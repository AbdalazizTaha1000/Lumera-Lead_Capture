<?php

declare(strict_types=1);

namespace Lumera\Core;

/**
 * Minimal path resolver for the public entry point.
 * The admin surface and the API are plain file endpoints and do not use this.
 */
final class Router
{
    /** Returns the normalised request path, e.g. "/f/property-finder". */
    public static function path(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        return '/' . trim($path, '/');
    }

    /**
     * Resolves the funnel slug from the public URL.
     * "/"                  -> null   (default funnel)
     * "/f/property-finder" -> "property-finder"
     * "/property-finder"   -> "property-finder"
     */
    public static function funnelSlug(): ?string
    {
        $segments = array_values(array_filter(explode('/', self::path()), static fn ($s) => $s !== ''));

        if ($segments === []) {
            return null;
        }

        if ($segments[0] === 'f' && isset($segments[1])) {
            return self::sanitizeSlug($segments[1]);
        }

        // Ignore known non-funnel prefixes.
        if (in_array($segments[0], ['admin', 'api', 'assets'], true)) {
            return null;
        }

        return self::sanitizeSlug($segments[0]);
    }

    private static function sanitizeSlug(string $slug): ?string
    {
        $slug = strtolower($slug);

        return preg_match('/^[a-z0-9][a-z0-9\-]{0,119}$/', $slug) === 1 ? $slug : null;
    }
}
