<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

final class AdminUserRepository
{
    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `admin_users` WHERE `email` = :e LIMIT 1',
            ['e' => mb_strtolower(trim($email))]
        );
    }

    public function count(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM `admin_users`');
    }

    /**
     * Creates an admin. The plaintext password is hashed here and is never
     * stored, echoed, or logged.
     */
    public function create(string $email, string $plainPassword, string $name = 'Administrator'): int
    {
        Database::execute(
            'INSERT INTO `admin_users` (`email`, `password_hash`, `name`) VALUES (:e, :h, :n)',
            [
                'e' => mb_strtolower(trim($email)),
                'h' => password_hash($plainPassword, PASSWORD_DEFAULT),
                'n' => mb_substr(trim($name), 0, 120),
            ]
        );

        return Database::lastInsertId();
    }

    public function setPassword(int $id, string $plainPassword): void
    {
        Database::execute(
            'UPDATE `admin_users` SET `password_hash` = :h WHERE `id` = :id',
            ['h' => password_hash($plainPassword, PASSWORD_DEFAULT), 'id' => $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Database::select(
            'SELECT `id`, `email`, `name`, `is_active`, `last_login_at`, `created_at` FROM `admin_users` ORDER BY `id`'
        );
    }
}
