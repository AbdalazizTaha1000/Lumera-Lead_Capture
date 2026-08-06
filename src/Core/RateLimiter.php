<?php

declare(strict_types=1);

namespace Lumera\Core;

use Lumera\Support\Request;

/**
 * Fixed-window rate limiter backed by `rate_limit_entries`.
 * Bucket keys are hashed so no raw IP or email is stored.
 */
final class RateLimiter
{
    /**
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public static function hit(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): array
    {
        $bucket = hash_hmac('sha256', $scope . '|' . $identifier, Config::secret());
        $now    = time();

        try {
            return Database::transaction(static function () use ($bucket, $now, $maxAttempts, $windowSeconds) {
                $row = Database::selectOne(
                    'SELECT `hits`, UNIX_TIMESTAMP(`window_start`) AS ws, UNIX_TIMESTAMP(`expires_at`) AS ex
                     FROM `rate_limit_entries` WHERE `bucket_key` = :k FOR UPDATE',
                    ['k' => $bucket]
                );

                if ($row === null || (int) $row['ex'] <= $now) {
                    Database::execute(
                        'INSERT INTO `rate_limit_entries` (`bucket_key`, `hits`, `window_start`, `expires_at`)
                         VALUES (:k, 1, FROM_UNIXTIME(:ws), FROM_UNIXTIME(:ex))
                         ON DUPLICATE KEY UPDATE `hits` = 1,
                            `window_start` = FROM_UNIXTIME(:ws2), `expires_at` = FROM_UNIXTIME(:ex2)',
                        [
                            'k'   => $bucket,
                            'ws'  => $now,
                            'ex'  => $now + $windowSeconds,
                            'ws2' => $now,
                            'ex2' => $now + $windowSeconds,
                        ]
                    );

                    return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
                }

                $hits = (int) $row['hits'];

                if ($hits >= $maxAttempts) {
                    return [
                        'allowed'     => false,
                        'remaining'   => 0,
                        'retry_after' => max(1, (int) $row['ex'] - $now),
                    ];
                }

                Database::execute(
                    'UPDATE `rate_limit_entries` SET `hits` = `hits` + 1 WHERE `bucket_key` = :k',
                    ['k' => $bucket]
                );

                return [
                    'allowed'     => true,
                    'remaining'   => max(0, $maxAttempts - $hits - 1),
                    'retry_after' => 0,
                ];
            });
        } catch (\Throwable $e) {
            Logger::error('rate_limit.failure', ['scope' => $scope, 'message' => $e->getMessage()]);

            // Fail open rather than locking every visitor out of the funnel.
            return ['allowed' => true, 'remaining' => 0, 'retry_after' => 0];
        }
    }

    public static function clear(string $scope, string $identifier): void
    {
        $bucket = hash_hmac('sha256', $scope . '|' . $identifier, Config::secret());

        try {
            Database::execute('DELETE FROM `rate_limit_entries` WHERE `bucket_key` = :k', ['k' => $bucket]);
        } catch (\Throwable) {
            // non-critical
        }
    }

    public static function prune(): void
    {
        try {
            Database::execute('DELETE FROM `rate_limit_entries` WHERE `expires_at` < NOW()');
        } catch (\Throwable) {
            // non-critical
        }
    }

    /** Convenience wrapper for public submissions. */
    public static function publicSubmission(): array
    {
        return self::hit(
            'public_submit',
            Request::ip(),
            Config::int('RATE_LIMIT_MAX_ATTEMPTS', 5),
            Config::int('RATE_LIMIT_WINDOW_SECONDS', 900)
        );
    }
}
