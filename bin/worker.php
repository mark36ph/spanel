<?php
declare(strict_types=1);

/**
 * Single-job CLI worker. It never executes commands from a queue payload:
 * the only privileged entry point is the fixed, root-owned typed helper.
 *
 * Run manually as the panel service account after installation:
 *   php bin/worker.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/DomainName.php';
require_once dirname(__DIR__) . '/app/ProvisioningContract.php';

const WORKER_LOCK = 'spanel_operations_worker';
const OPS_HELPER = '/usr/local/sbin/spanel-ops';

function finishJob(PDO $pdo, int $jobId, string $status, ?string $errorCode = null, ?string $summary = null, ?array $result = null): void
{
    $stmt = $pdo->prepare(
        'UPDATE operation_jobs SET status = :status, error_code = :error_code, error_summary = :summary,
         result_json = :result, finished_at = NOW(), updated_at = NOW() WHERE id = :id AND status = \'running\''
    );
    $stmt->execute([
        'status' => $status,
        'error_code' => $errorCode,
        'summary' => $summary === null ? null : mb_substr($summary, 0, 1000),
        'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR),
        'id' => $jobId,
    ]);
}

function invokeOperationsHelper(array $request): array
{
    // Refuse symlinks and helpers writable by anyone other than root. sudo must
    // never execute a helper that the panel account or another unprivileged
    // account can replace or modify.
    clearstatcache(true, OPS_HELPER);
    $helperStat = is_link(OPS_HELPER) ? false : @lstat(OPS_HELPER);
    if (!is_array($helperStat)
        || !is_file(OPS_HELPER)
        || !is_executable(OPS_HELPER)
        || (int)($helperStat['uid'] ?? -1) !== 0
        || (((int)($helperStat['mode'] ?? 0)) & 0022) !== 0) {
        throw new RuntimeException('Restricted operations helper is missing or fails ownership/permission checks; provisioning remains disabled.');
    }

    $pipes = [];
    $process = proc_open(
        ['/usr/bin/sudo', '-n', OPS_HELPER],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
        null,
        ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the restricted operations helper.');
    }

    $input = json_encode($request, JSON_THROW_ON_ERROR);
    $written = 0;
    while ($written < strlen($input)) {
        $count = fwrite($pipes[0], substr($input, $written));
        if ($count === false || $count === 0) {
            proc_terminate($process, 9);
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($process);
            throw new RuntimeException('Could not send the request to the restricted operations helper.');
        }
        $written += $count;
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 60);
    $stdout = stream_get_contents($pipes[1], 16385);
    $streamMeta = stream_get_meta_data($pipes[1]);
    if (($streamMeta['timed_out'] ?? false) === true
        || !is_string($stdout)
        || strlen($stdout) > 16384) {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        proc_close($process);
        throw new RuntimeException('Restricted operations helper timed out or returned an oversized response.');
    }
    fclose($pipes[1]);
    $exitCode = proc_close($process);

    if (!is_string($stdout)) {
        throw new RuntimeException('Restricted operations helper returned an invalid response.');
    }
    try {
        $response = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Restricted operations helper returned an invalid response.');
    }
    if (!is_array($response) || ($response['ok'] ?? false) !== true || $exitCode !== 0) {
        // Never trust privileged-process output as a user-facing error. It may
        // contain paths, SQL details or other operational data.
        throw new RuntimeException('Restricted operations helper rejected the request or failed; inspect protected server logs.');
    }
    return $response;
}

$pdo = null;
$locked = false;
$jobId = 0;
try {
    $pdo = Database::connection();
    $lock = $pdo->query("SELECT GET_LOCK('" . WORKER_LOCK . "', 0)")->fetchColumn();
    if ((int)$lock !== 1) {
        fwrite(STDOUT, "Another SPanel worker is active; exiting.\n");
        exit(0);
    }
    $locked = true;
    $pdo->beginTransaction();
    $job = $pdo->query(
        "SELECT j.id, j.operation_type, j.payload_json, j.website_id, j.server_id,
                w.domain, w.server_id AS website_server_id, s.is_local
         FROM operation_jobs j
         LEFT JOIN websites w ON w.id = j.website_id
         LEFT JOIN servers s ON s.id = j.server_id
         WHERE j.status = 'queued'
         ORDER BY j.id ASC
         LIMIT 1
         FOR UPDATE"
    )->fetch();

    if (!$job) {
        $pdo->commit();
        fwrite(STDOUT, "No queued operations.\n");
        exit(0);
    }

    $jobId = (int)$job['id'];
    $claim = $pdo->prepare(
        "UPDATE operation_jobs SET status = 'running', attempt_count = attempt_count + 1,
         started_at = NOW(), error_code = NULL, error_summary = NULL, updated_at = NOW()
         WHERE id = :id AND status = 'queued'"
    );
    $claim->execute(['id' => $jobId]);
    if ($claim->rowCount() !== 1) {
        $pdo->rollBack();
        fwrite(STDOUT, "Job was claimed by another worker; exiting.\n");
        exit(0);
    }
    $pdo->commit();

    $provisioningMayHaveChangedSystem = false;
    try {
        if ($job['operation_type'] !== 'website.provision') {
            throw new RuntimeException('Operation type is not supported by this worker.');
        }
        if (!$job['website_id'] || !$job['server_id'] || (int)$job['website_server_id'] !== (int)$job['server_id']) {
            throw new RuntimeException('Job ownership does not match the website/server assignment.');
        }
        if ((int)$job['is_local'] !== 1) {
            throw new RuntimeException('Remote server agents are not implemented; this job must target the local server.');
        }
        $payload = json_decode((string)$job['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_diff(array_keys($payload), ['create_database_requested', 'php_version_requested'])
            || !array_key_exists('create_database_requested', $payload)
            || !array_key_exists('php_version_requested', $payload)
            || !is_bool($payload['create_database_requested'])
            || ($payload['php_version_requested'] !== null && !in_array($payload['php_version_requested'], ['8.2', '8.3'], true))) {
            throw new RuntimeException('Queued operation payload failed validation.');
        }

        $websiteId = (int)$job['website_id'];
        $domain = (string)$job['domain'];
        if (DomainName::normalize($domain) !== $domain) {
            throw new RuntimeException('Website domain failed provisioning validation.');
        }
        $request = ProvisioningContract::validateRequest([
            'operation' => 'website.provision',
            'website_id' => $websiteId,
            'domain' => $domain,
            'php_version' => $payload['php_version_requested'],
            'create_database' => $payload['create_database_requested'],
        ]);

        // A failed or mismatched helper response can follow partial system changes.
        $provisioningMayHaveChangedSystem = is_file(OPS_HELPER) && is_executable(OPS_HELPER);
        $response = invokeOperationsHelper($request);
        try {
            $response = ProvisioningContract::validateResponse($response, $request);
        } catch (InvalidArgumentException $contractError) {
            throw new RuntimeException('Restricted operations helper returned a mismatched result.', 0, $contractError);
        }
        $actualPhpVersion = $response['php_version'];

        $pdo->beginTransaction();
        $updateWebsite = $pdo->prepare(
            "UPDATE websites SET status = 'active', document_root = :document_root,
             php_version = :php_version, updated_at = NOW() WHERE id = :id AND server_id = :server_id"
        );
        $updateWebsite->execute([
            'document_root' => '/var/www/spanel/sites/' . (int)$job['website_id'] . '/public',
            'php_version' => $actualPhpVersion,
            'id' => (int)$job['website_id'],
            'server_id' => (int)$job['server_id'],
        ]);
        if ($updateWebsite->rowCount() !== 1) {
            throw new RuntimeException('Website metadata did not match the completed provisioning operation.');
        }
        $domainUpdate = $pdo->prepare(
            "UPDATE website_domains SET status = 'active' WHERE website_id = :website_id AND is_primary = 1"
        );
        $domainUpdate->execute(['website_id' => $websiteId]);
        if ($domainUpdate->rowCount() !== 1) {
            throw new RuntimeException('Primary domain metadata did not match the completed provisioning operation.');
        }

        if ($response['database_created'] === true) {
            // Resource names are derived from the numeric website ID, never from
            // browser input or helper-provided names.
            $databaseName = 'spw_' . $websiteId;
            $databaseUser = 'spu_' . $websiteId;
            $existingDatabase = $pdo->prepare(
                'SELECT website_id, server_id, database_name, database_user
                 FROM website_databases WHERE website_id = :website_id OR database_name = :database_name OR database_user = :database_user'
            );
            $existingDatabase->execute([
                'website_id' => $websiteId,
                'database_name' => $databaseName,
                'database_user' => $databaseUser,
            ]);
            $existingRows = $existingDatabase->fetchAll();
            if ($existingRows) {
                if (count($existingRows) !== 1
                    || (int)$existingRows[0]['website_id'] !== $websiteId
                    || (int)$existingRows[0]['server_id'] !== (int)$job['server_id']
                    || $existingRows[0]['database_name'] !== $databaseName
                    || $existingRows[0]['database_user'] !== $databaseUser) {
                    throw new RuntimeException('Database metadata conflicts with an existing website resource.');
                }
            } else {
                $insertDatabase = $pdo->prepare(
                    'INSERT INTO website_databases (website_id, server_id, database_name, database_user)
                     VALUES (:website_id, :server_id, :database_name, :database_user)'
                );
                $insertDatabase->execute([
                    'website_id' => $websiteId,
                    'server_id' => (int)$job['server_id'],
                    'database_name' => $databaseName,
                    'database_user' => $databaseUser,
                ]);
            }
        }

        finishJob($pdo, $jobId, 'succeeded', null, null, [
            'website_id' => $websiteId,
            'domain' => $domain,
            'php_version' => $actualPhpVersion,
            'database_created' => $response['database_created'],
        ]);
        $pdo->commit();
        fwrite(STDOUT, "Completed operation job #{$jobId}.\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Error summaries are intentionally short; no raw subprocess output or secrets.
        $summary = mb_substr($e->getMessage(), 0, 300);
        $failureStatus = $provisioningMayHaveChangedSystem ? 'rollback_required' : 'failed';
        $failureCode = $provisioningMayHaveChangedSystem ? 'provisioning_state_uncertain' : 'provisioning_failed';
        if ($provisioningMayHaveChangedSystem) {
            $summary = 'The helper may have changed system resources, but the panel could not verify/commit the result. Inspect the server before retrying. Details: ' . $summary;
        }
        finishJob($pdo, $jobId, $failureStatus, $failureCode, $summary);
        fwrite(STDERR, "Operation job #{$jobId} {$failureStatus}: {$summary}\n");
        exit(1);
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'SPanel worker error: ' . mb_substr($e->getMessage(), 0, 300) . "\n");
    exit(1);
} finally {
    if ($locked && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query("SELECT RELEASE_LOCK('" . WORKER_LOCK . "')");
            $stmt->fetchColumn();
        } catch (Throwable) {
            // Connection close releases the advisory lock.
        }
    }
}
