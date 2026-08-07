<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

/**
 * Safe, non-secret application settings.
 *
 * The write path is restricted to an explicit allow-list, so no request can
 * introduce a key that shadows an environment secret.
 */
final class SettingsRepository
{
    /**
     * key => [type, is_public]
     *
     * `is_public` marks the values that may be served through the public API
     * and rendered into public pages. Everything else stays admin-only, and no
     * secret is ever stored here — those live in .env.
     */
    public const EDITABLE = [
        'company_name'                 => ['string', 1],
        'company_logo'                 => ['string', 1],
        // Rendered into the public <title> and <meta name="description">.
        'site_tagline'                 => ['string', 1],
        'admin_interface_language'     => ['string', 0],
        'timezone'                     => ['string', 0],
        'privacy_policy_url'           => ['string', 1],
        'notification_subject_template' => ['string', 0],
    ];

    /** @return array<string,mixed> */
    public function all(): array
    {
        $rows = Database::select('SELECT * FROM `app_settings`');
        $out  = [];

        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = $this->cast($row['setting_value'], (string) $row['value_type']);
        }

        return $out;
    }

    /** @return array<string,mixed> settings marked as safe for the public API */
    public function publicSettings(): array
    {
        $rows = Database::select('SELECT * FROM `app_settings` WHERE `is_public` = 1');
        $out  = [];

        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = $this->cast($row['setting_value'], (string) $row['value_type']);
        }

        return $out;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = Database::selectOne(
            'SELECT `setting_value`, `value_type` FROM `app_settings` WHERE `setting_key` = :k LIMIT 1',
            ['k' => $key]
        );

        return $row === null ? $default : $this->cast($row['setting_value'], (string) $row['value_type']);
    }

    public function set(string $key, mixed $value): bool
    {
        if (!isset(self::EDITABLE[$key])) {
            return false;
        }

        [$type, $isPublic] = self::EDITABLE[$key];

        Database::execute(
            'INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`)
             VALUES (:k, :v, :t, :p)
             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`),
                                     `value_type` = VALUES(`value_type`),
                                     `is_public` = VALUES(`is_public`)',
            [
                'k' => $key,
                'v' => $this->serialize($value, $type),
                't' => $type,
                'p' => $isPublic,
            ]
        );

        return true;
    }

    private function cast(mixed $raw, string $type): mixed
    {
        return match ($type) {
            'bool' => in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true),
            'int'  => (int) $raw,
            'json' => json_decode((string) $raw, true) ?? null,
            default => (string) $raw,
        };
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int'  => (string) (int) $value,
            'json' => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
