<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';

Auth::requireLogin();
$pdo = Database::connection();
$websiteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$websiteId || $websiteId < 1) {
    http_response_code(400);
    exit('A valid website ID is required.');
}

$stmt = $pdo->prepare(
    'SELECT w.id, w.name, w.domain, w.document_root, w.status, w.server_id,
            s.name AS server_name, s.is_local, s.status AS server_status
     FROM websites w
     LEFT JOIN servers s ON s.id = w.server_id
     WHERE w.id = :id LIMIT 1'
);
$stmt->execute(['id' => $websiteId]);
$website = $stmt->fetch();
if (!$website) {
    http_response_code(404);
    exit('Website not found.');
}

$error = '';
$root = rtrim((string)$website['document_root'], '/');
$expectedRoot = '/var/www/spanel/sites/' . $websiteId . '/public';
if ((int)($website['is_local'] ?? 0) !== 1
    || $website['server_status'] !== 'online'
    || $root !== $expectedRoot) {
    http_response_code(409);
    exit('The file manager is available only for websites on this local server with the expected document root.');
}

$relativePath = trim((string)($_GET['path'] ?? ''), '/');
$segments = $relativePath === '' ? [] : explode('/', $relativePath);
foreach ($segments as $segment) {
    if ($segment === '' || $segment === '.' || $segment === '..'
        || str_starts_with($segment, '.') || str_contains($segment, "\0")
        || preg_match('/[\\\\]/', $segment)) {
        http_response_code(400);
        exit('Invalid file path.');
    }
}
$target = $root . ($relativePath === '' ? '' : '/' . $relativePath);
$realRoot = realpath($root);
$realTarget = realpath($target);
if ($realRoot === false || $realTarget === false
    || ($realTarget !== $realRoot && !str_starts_with($realTarget, $realRoot . DIRECTORY_SEPARATOR))
    || is_link($target)) {
    http_response_code(404);
    exit('File or directory not found.');
}

$entries = [];
$fileContent = null;
$fileName = basename($realTarget);
$isDirectory = is_dir($realTarget);
if ($isDirectory) {
    $items = scandir($realTarget);
    if ($items === false) {
        $error = 'This directory could not be read.';
    } else {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
                continue;
            }
            $fullPath = $realTarget . DIRECTORY_SEPARATOR . $item;
            if (is_link($fullPath) || (!is_dir($fullPath) && !is_file($fullPath))) {
                continue;
            }
            $entries[] = [
                'name' => $item,
                'directory' => is_dir($fullPath),
                'size' => is_file($fullPath) ? (int)filesize($fullPath) : null,
                'modified' => date('Y-m-d H:i', (int)filemtime($fullPath)),
            ];
        }
        usort($entries, static fn(array $a, array $b): int =>
            ($a['directory'] === $b['directory'] ? 0 : ($a['directory'] ? -1 : 1))
            ?: strcasecmp($a['name'], $b['name'])
        );
    }
} elseif (is_file($realTarget)) {
    $extension = strtolower((string)pathinfo($realTarget, PATHINFO_EXTENSION));
    $textExtensions = ['txt', 'md', 'html', 'htm', 'css', 'js', 'mjs', 'json', 'xml', 'svg', 'yml', 'yaml', 'ini', 'conf', 'htaccess', 'php', 'sql', 'sh', 'log', 'csv'];
    if (!in_array($extension, $textExtensions, true)) {
        $error = 'Preview is limited to recognised text files. This file can be listed but not previewed.';
    } elseif ((int)filesize($realTarget) > 524288) {
        $error = 'Files larger than 512 KiB cannot be previewed.';
    } else {
        $contents = file_get_contents($realTarget);
        if (!is_string($contents) || str_contains($contents, "\0")) {
            $error = 'This file could not be safely previewed as text.';
        } else {
            $fileContent = $contents;
        }
    }
} else {
    http_response_code(404);
    exit('File or directory not found.');
}

$breadcrumbs = [];
$crumbPath = '';
foreach ($segments as $segment) {
    $crumbPath = $crumbPath === '' ? $segment : $crumbPath . '/' . $segment;
    $breadcrumbs[] = ['name' => $segment, 'path' => $crumbPath];
}
renderHeader('File manager', 'sites');
?>
<div class="welcome-row"><div><p class="muted">WEBSITE FILES · READ ONLY</p><h2><?= Security::escape($website['name']) ?></h2><p class="muted"><?= Security::escape($website['domain']) ?> · <?= Security::escape($website['document_root']) ?></p></div><a class="secondary-button link-button" href="/site.php?id=<?= (int)$websiteId ?>">← Website overview</a></div>
<?php if ($error !== ''): ?><div class="alert"><?= Security::escape($error) ?></div><?php endif; ?>
<div class="notice"><strong>Safe preview mode</strong><p>You can browse folders and preview text files up to 512 KiB. Hidden files and symbolic links are excluded. Upload, edit and delete are not enabled yet; those actions will require a restricted, website-scoped helper.</p></div>
<div class="file-breadcrumbs"><a href="/files.php?id=<?= (int)$websiteId ?>">Root</a><?php foreach ($breadcrumbs as $crumb): ?> <span> / </span><a href="/files.php?id=<?= (int)$websiteId ?>&amp;path=<?= rawurlencode($crumb['path']) ?>"><?= Security::escape($crumb['name']) ?></a><?php endforeach; ?></div>
<?php if ($isDirectory): ?>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Modified</th></tr></thead><tbody>
<?php if ($relativePath !== ''): $parentPath = implode('/', array_slice($segments, 0, -1)); ?><tr><td><a class="text-link" href="/files.php?id=<?= (int)$websiteId ?>&amp;path=<?= rawurlencode($parentPath) ?>">↑ Parent directory</a></td><td>Directory</td><td>—</td><td>—</td></tr><?php endif; ?>
<?php foreach ($entries as $entry): $entryPath = $relativePath === '' ? $entry['name'] : $relativePath . '/' . $entry['name']; ?><tr><td><strong><a class="text-link" href="/files.php?id=<?= (int)$websiteId ?>&amp;path=<?= rawurlencode($entryPath) ?>"><?= $entry['directory'] ? '📁 ' : '📄 ' ?><?= Security::escape($entry['name']) ?></a></strong></td><td><?= $entry['directory'] ? 'Directory' : 'File' ?></td><td><?= $entry['size'] === null ? '—' : number_format($entry['size']) . ' bytes' ?></td><td><?= Security::escape($entry['modified']) ?></td></tr><?php endforeach; ?>
<?php if (!$entries): ?><tr><td colspan="4">This directory is empty.</td></tr><?php endif; ?>
</tbody></table></div>
<?php elseif ($fileContent !== null): ?>
<div class="section-heading"><div><h3><?= Security::escape($fileName) ?></h3><p class="muted">Read-only preview · <?= number_format(strlen($fileContent)) ?> bytes</p></div><a class="secondary-button link-button" href="/files.php?id=<?= (int)$websiteId ?>&amp;path=<?= rawurlencode(implode('/', array_slice($segments, 0, -1))) ?>">← Back to folder</a></div>
<pre class="file-preview"><?= Security::escape($fileContent) ?></pre>
<?php endif; ?>
<?php renderFooter(); ?>
