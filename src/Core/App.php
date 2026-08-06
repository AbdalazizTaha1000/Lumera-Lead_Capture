<?php

declare(strict_types=1);

namespace Lumera\Core;

/**
 * Single bootstrap entry point used by every public and admin script.
 */
final class App
{
    private static bool $booted = false;

    public static function boot(?string $basePath = null): void
    {
        if (self::$booted) {
            return;
        }

        $basePath ??= dirname(__DIR__, 2);

        Config::boot($basePath);

        if (Config::isDebug()) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
        }

        mb_internal_encoding('UTF-8');

        self::registerHandlers();

        self::$booted = true;
    }

    /** Boots and prepares a JSON API request (headers + fatal handling). */
    public static function bootApi(?string $basePath = null): void
    {
        self::boot($basePath);
        Response::securityHeaders();
        Session::start();
    }

    private static function registerHandlers(): void
    {
        set_exception_handler(static function (\Throwable $e): void {
            Logger::error('uncaught_exception', [
                'message' => $e->getMessage(),
                'class'   => $e::class,
                'file'    => basename($e->getFile()),
                'line'    => $e->getLine(),
            ]);

            if (headers_sent()) {
                return;
            }

            http_response_code(500);

            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                || str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');

            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                $payload = ['ok' => false, 'error' => 'An unexpected error occurred.'];

                if (Config::isDebug()) {
                    $payload['debug'] = $e->getMessage();
                }

                echo json_encode($payload, JSON_UNESCAPED_UNICODE);
                return;
            }

            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
                . '<p style="font:16px system-ui;padding:40px">Something went wrong. Please try again later.</p>';

            if (Config::isDebug()) {
                echo '<pre style="font:13px monospace;padding:0 40px;color:#b00">'
                    . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
            }
        });

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }
}
