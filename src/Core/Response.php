<?php

declare(strict_types=1);

namespace Lumera\Core;

/**
 * JSON response helper + security headers.
 *
 * Public error messages are deliberately generic; details go to the log.
 */
final class Response
{
    public static function securityHeaders(bool $noStore = true): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
        header_remove('X-Powered-By');

        if ($noStore) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        self::securityHeaders();

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Every response carries an explicit outcome flag.
     *
     * `success` is the contract clients must check; `ok` is kept as its alias so
     * existing callers keep working. A client must never infer success from the
     * mere presence of JSON — see public-funnel.js, which requires the HTTP
     * status, `success === true` and a usable payload before it shows anything.
     *
     * @param array<string,mixed> $data
     */
    public static function success(array $data = [], int $status = 200): never
    {
        self::json(['ok' => true, 'success' => true] + $data, $status);
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'success' => false, 'error' => $message, 'message' => $message] + $extra, $status);
    }

    /** @param array<string,string> $errors field => message */
    public static function validationError(array $errors, string $message = 'Please correct the highlighted fields.'): never
    {
        self::json([
            'ok'      => false,
            'success' => false,
            'error'   => $message,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    public static function redirect(string $url, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }

        exit;
    }
}
