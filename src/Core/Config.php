<?php

declare(strict_types=1);

namespace Lumera\Core;

use Dotenv\Dotenv;

/**
 * Environment + path configuration.
 *
 * Every secret (DB, SMTP, APP_SECRET) is read from here and from here only.
 * Nothing in this class is ever serialised to the browser.
 */
final class Config
{
    private static string $basePath = '';
    private static bool $booted = false;

    public static function boot(string $basePath): void
    {
        if (self::$booted) {
            return;
        }

        self::$basePath = rtrim(str_replace('\\', '/', $basePath), '/');

        if (is_file(self::$basePath . '/.env')) {
            Dotenv::createImmutable(self::$basePath)->safeLoad();
        }

        date_default_timezone_set(self::get('APP_TIMEZONE', 'Asia/Dubai'));

        self::$booted = true;
    }

    public static function basePath(string $append = ''): string
    {
        return self::$basePath . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }

    public static function isProduction(): bool
    {
        return self::string('APP_ENV', 'production') === 'production';
    }

    public static function appUrl(): string
    {
        return rtrim(self::string('APP_URL', ''), '/');
    }

    /**
     * Secret used for keyed hashing (IP pseudonymisation, tokens).
     * Falls back to a derived value so the app degrades safely rather than
     * hashing with an empty key, but installation should always set it.
     */
    public static function secret(): string
    {
        $secret = self::string('APP_SECRET', '');

        if ($secret === '') {
            $secret = 'insecure-fallback:' . md5(self::$basePath);
        }

        return $secret;
    }

    public static function storagePath(string $append = ''): string
    {
        return self::basePath('storage' . ($append !== '' ? '/' . ltrim($append, '/') : ''));
    }

    public static function logPath(): string
    {
        $configured = self::string('LOG_PATH', '');

        return $configured !== '' ? rtrim($configured, '/\\') : self::storagePath('logs');
    }
}
