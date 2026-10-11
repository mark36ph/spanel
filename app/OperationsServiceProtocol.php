<?php
declare(strict_types=1);

require_once __DIR__ . '/ProvisioningContract.php';
require_once __DIR__ . '/ProvisioningOperationsContract.php';

/**
 * Data-only JSON-line protocol for a future restricted local operations service.
 *
 * This class performs no I/O and starts no process. Transport and the privileged
 * service remain deliberately unimplemented until their security design is reviewed.
 */
final class OperationsServiceProtocol
{
    public const MAX_FRAME_BYTES = 16384;

    public static function encodeProvisionRequest(array $request): string
    {
        return self::encode(ProvisioningContract::validateRequest($request));
    }

    public static function encodePreflightRequest(array $request): string
    {
        return self::encode(ProvisioningOperationsContract::validatePreflightRequest($request));
    }

    public static function decodeProvisionResponse(string $frame, array $request): array
    {
        $response = self::decode($frame);
        return ProvisioningContract::validateResponse($response, $request);
    }

    public static function decodePreflightResponse(string $frame, array $request): array
    {
        $response = self::decode($frame);
        return ProvisioningOperationsContract::validatePreflightResponse($response, $request);
    }

    private static function encode(array $message): string
    {
        $json = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $frame = $json . "\n";
        if (strlen($frame) > self::MAX_FRAME_BYTES) {
            throw new InvalidArgumentException('Operations protocol request exceeds the frame limit.');
        }
        return $frame;
    }

    private static function decode(string $frame): array
    {
        if ($frame === ''
            || strlen($frame) > self::MAX_FRAME_BYTES
            || !str_ends_with($frame, "\n")
            || substr_count($frame, "\n") !== 1
            || str_contains($frame, "\r")) {
            throw new InvalidArgumentException('Operations protocol response has invalid framing or size.');
        }

        try {
            $decoded = json_decode(substr($frame, 0, -1), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Operations protocol response is not valid JSON.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Operations protocol response must be a JSON object.');
        }

        return $decoded;
    }
}
