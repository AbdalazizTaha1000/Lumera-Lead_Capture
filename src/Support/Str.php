<?php

declare(strict_types=1);

namespace Lumera\Support;

final class Str
{
    /** HTML-escape for template output. */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Normalises a user-supplied identifier into a stable internal key.
     * Language-independent: only [a-z0-9_].
     */
    public static function key(string $value, int $maxLength = 64): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return substr($value, 0, $maxLength);
    }

    public static function slug(string $value, int $maxLength = 120): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return substr($value, 0, $maxLength);
    }

    public static function limit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    /** Trim + collapse whitespace + strip control characters. */
    public static function clean(?string $value, int $maxLength = 500): string
    {
        $value = (string) $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $maxLength);
    }

    /** Multi-line safe clean (keeps newlines). */
    public static function cleanMultiline(?string $value, int $maxLength = 5000): string
    {
        $value = (string) $value;
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr(trim($value), 0, $maxLength);
    }

    public static function isHexColor(string $value): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value);
    }

    /** Only allow http(s) absolute URLs or site-relative paths. */
    public static function safeUrl(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return mb_substr($value, 0, 255);
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? mb_substr($value, 0, 255) : null;
    }
}
