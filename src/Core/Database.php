<?php

declare(strict_types=1);

namespace Lumera\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin PDO singleton. Emulated prepares are disabled so every statement is a
 * genuine server-side prepared statement.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = Config::string('DB_HOST', '127.0.0.1');
        $port    = Config::int('DB_PORT', 3306);
        $name    = Config::string('DB_DATABASE', '');
        $user    = Config::string('DB_USERNAME', '');
        $pass    = Config::string('DB_PASSWORD', '');
        $charset = Config::string('DB_CHARSET', 'utf8mb4');

        if ($name === '') {
            throw new RuntimeException('Database is not configured (DB_DATABASE is empty).');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Never leak DSN / credentials to the caller.
            Logger::error('database.connect_failed', ['message' => $e->getMessage()]);
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return self::$pdo;
    }

    /** @param array<string,mixed> $params */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run a callback inside a transaction, rolling back on any exception.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
