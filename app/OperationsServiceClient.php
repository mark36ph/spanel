<?php
declare(strict_types=1);

require_once __DIR__ . '/OperationsServiceProtocol.php';

/**
 * Fail-closed client for the future local restricted operations service.
 *
 * Fixed Unix socket only. No shell, no configurable endpoint, no TCP fallback.
 * This client does not grant privileges: it is useful only when a separately
 * reviewed service owns the socket and enforces its own operation allowlist.
 */
final class OperationsServiceClient
{
    public const SOCKET_URI = 'unix:///run/spanel-ops/operations.sock';
    private const CONNECT_TIMEOUT_SECONDS = 2.0;
    private const IO_TIMEOUT_SECONDS = 5;

    public function preflight(array $request): array
    {
        $frame = OperationsServiceProtocol::encodePreflightRequest($request);
        $responseFrame = $this->exchange($frame);
        return OperationsServiceProtocol::decodePreflightResponse($responseFrame, $request);
    }

    public function provision(array $request): array
    {
        $frame = OperationsServiceProtocol::encodeProvisionRequest($request);
        $responseFrame = $this->exchange($frame);
        return OperationsServiceProtocol::decodeProvisionResponse($responseFrame, $request);
    }

    private function exchange(string $frame): string
    {
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            self::SOCKET_URI,
            $errno,
            $error,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($stream)) {
            throw new RuntimeException(
                'Restricted operations service is unavailable; no operation was sent.'
            );
        }

        try {
            stream_set_timeout($stream, self::IO_TIMEOUT_SECONDS);
            $written = 0;
            $length = strlen($frame);
            while ($written < $length) {
                $count = @fwrite($stream, substr($frame, $written));
                if (!is_int($count) || $count < 1) {
                    throw new RuntimeException('Operations service request could not be delivered.');
                }
                $written += $count;
            }

            $response = '';
            while (!str_ends_with($response, "\n")) {
                $remaining = OperationsServiceProtocol::MAX_FRAME_BYTES + 1 - strlen($response);
                if ($remaining <= 0) {
                    throw new RuntimeException('Operations service response exceeded the frame limit.');
                }
                $chunk = @fread($stream, min(4096, $remaining));
                $meta = stream_get_meta_data($stream);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new RuntimeException('Operations service response timed out.');
                }
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    throw new RuntimeException('Operations service closed without a complete response.');
                }
                if ($chunk === '') {
                    continue;
                }
                $response .= $chunk;
                if (strlen($response) > OperationsServiceProtocol::MAX_FRAME_BYTES) {
                    throw new RuntimeException('Operations service response exceeded the frame limit.');
                }
            }

            return $response;
        } finally {
            fclose($stream);
        }
    }
}
