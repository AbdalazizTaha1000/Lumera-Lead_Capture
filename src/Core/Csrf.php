<?php

declare(strict_types=1);

namespace Lumera\Core;

/**
 * Per-session CSRF token, compared in constant time.
 * Admin and public surfaces use separate token slots.
 */
final class Csrf
{
    public const HEADER = 'X-CSRF-Token';

    public static function token(string $scope = 'admin'): string
    {
        Session::start();
        $key = '_csrf_' . $scope;

        if (!is_string(Session::get($key)) || Session::get($key) === '') {
            Session::set($key, bin2hex(random_bytes(32)));
        }

        return (string) Session::get($key);
    }

    public static function rotate(string $scope = 'admin'): string
    {
        Session::start();
        Session::set('_csrf_' . $scope, bin2hex(random_bytes(32)));

        return (string) Session::get('_csrf_' . $scope);
    }

    public static function validate(?string $candidate, string $scope = 'admin'): bool
    {
        Session::start();
        $expected = Session::get('_csrf_' . $scope);

        if (!is_string($expected) || $expected === '' || !is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    /**
     * Reads the token from the request header first, then from a JSON/form body.
     *
     * @param array<string,mixed> $body
     */
    public static function fromRequest(array $body = []): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (is_string($header) && $header !== '') {
            return $header;
        }

        foreach (['csrf_token', '_csrf', 'csrf'] as $field) {
            if (isset($body[$field]) && is_string($body[$field])) {
                return $body[$field];
            }
            if (isset($_POST[$field]) && is_string($_POST[$field])) {
                return $_POST[$field];
            }
        }

        return null;
    }
}
