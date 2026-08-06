<?php

declare(strict_types=1);

namespace Lumera\Core;

use Lumera\Support\Request;

/**
 * Shared entry sequence for every authenticated admin JSON endpoint.
 *
 * read()  — GET endpoints: boot, authenticate.
 * write() — state-changing endpoints: boot, authenticate, enforce method,
 *           content type and CSRF.
 */
final class AdminEndpoint
{
    /** @return array<string,mixed> the authenticated admin */
    public static function read(string $basePath): array
    {
        App::bootApi($basePath);

        if (Request::method() !== 'GET') {
            Response::error('Method not allowed.', 405);
        }

        return Auth::requireApi();
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [admin, body]
     */
    public static function write(string $basePath, bool $requireJson = true): array
    {
        App::bootApi($basePath);

        if (!in_array(Request::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            Response::error('Method not allowed.', 405);
        }

        $body = [];

        if ($requireJson) {
            if (!Request::isJson()) {
                Response::error('Unsupported content type.', 415);
            }

            [$decoded, $error] = Request::jsonBody();

            if ($decoded === null) {
                Response::error($error ?? 'Malformed request.', 400);
            }

            $body = $decoded;
        } else {
            $body = $_POST;
        }

        $admin = Auth::requireApiWrite($body);

        return [$admin, $body];
    }

    /** Resolves the target funnel id from a request, defaulting to the primary funnel. */
    public static function funnelId(array $source): int
    {
        $id = (int) ($source['funnel_id'] ?? 0);

        if ($id > 0) {
            return $id;
        }

        $funnel = (new \Lumera\Repositories\FunnelRepository())->primary();

        return $funnel !== null ? (int) $funnel['id'] : 0;
    }

    public static function intParam(array $source, string $key, int $default = 0): int
    {
        $value = $source[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function stringParam(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function boolParam(array $source, string $key, bool $default = false): bool
    {
        if (!isset($source[$key])) {
            return $default;
        }

        $value = $source[$key];

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Validates a list of integer ids sent for a reorder operation.
     *
     * @return list<int>|null
     */
    public static function idList(mixed $raw, int $max = 200): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        if (count($raw) > $max) {
            return null;
        }

        $ids = [];

        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                return null;
            }

            $id = (int) $value;

            if ($id <= 0) {
                return null;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids)) === $ids ? $ids : null;
    }
}
