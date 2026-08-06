<?php

declare(strict_types=1);

namespace Lumera\Core;

/**
 * Append-only JSON-lines logger.
 *
 * Secrets are structurally excluded: callers pass context arrays and any key
 * matching the redaction list is replaced before the line is written.
 */
final class Logger
{
    private const REDACT = [
        'password', 'password_hash', 'smtp_password', 'app_secret', 'secret',
        'db_password', 'authorization', 'cookie', 'token', 'csrf', 'api_key',
    ];

    private static ?string $requestId = null;

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }

    /** @param array<string,mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $event, array $context): void
    {
        $dir = Config::logPath();

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return; // logging must never break the request
        }

        $payload = [
            'ts'         => date('c'),
            'level'      => $level,
            'event'      => $event,
            'request_id' => self::requestId(),
            'context'    => self::redact($context),
        ];

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        @file_put_contents(
            $dir . '/app-' . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function redact(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            $isSecret = false;

            foreach (self::REDACT as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) && mb_strlen($value) > 500
                    ? mb_substr($value, 0, 500) . '…'
                    : $value;
                continue;
            }

            $clean[$key] = '[object]';
        }

        return $clean;
    }
}
