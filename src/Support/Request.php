<?php

declare(strict_types=1);

namespace Lumera\Support;

use Lumera\Core\Config;

/**
 * Request helpers: client IP, privacy-preserving hashing, JSON body parsing.
 */
final class Request
{
    public const MAX_JSON_BYTES = 65536; // 64 KB payload ceiling

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function ip(): string
    {
        // Only trust proxy headers when the app sits behind a known proxy.
        $candidates = [$_SERVER['REMOTE_ADDR'] ?? ''];

        foreach ($candidates as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /** Keyed hash — reversible only with APP_SECRET, which never leaves the server. */
    public static function ipHash(?string $ip = null): string
    {
        return hash_hmac('sha256', $ip ?? self::ip(), Config::secret());
    }

    public static function rawIpIfEnabled(?string $ip = null): ?string
    {
        return Config::bool('STORE_RAW_IP', false) ? ($ip ?? self::ip()) : null;
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public static function deviceType(?string $userAgent = null): string
    {
        $ua = strtolower($userAgent ?? self::userAgent());

        if ($ua === '') {
            return 'unknown';
        }
        if (preg_match('/ipad|tablet|playbook|silk|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    public static function isJson(): bool
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        return str_contains($type, 'application/json');
    }

    /**
     * Decodes the JSON body.
     *
     * @return array{0: array<string,mixed>|null, 1: string|null} [payload, errorMessage]
     */
    public static function jsonBody(): array
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($length > self::MAX_JSON_BYTES) {
            return [null, 'Payload too large.'];
        }

        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return [[], null];
        }

        if (strlen($raw) > self::MAX_JSON_BYTES) {
            return [null, 'Payload too large.'];
        }

        $decoded = json_decode($raw, true, 16);

        if (!is_array($decoded)) {
            return [null, 'Malformed request body.'];
        }

        return [$decoded, null];
    }

    public static function currentUrl(): string
    {
        $scheme = \Lumera\Core\Session::isHttps() ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return $scheme . '://' . $host . $uri;
    }
}
