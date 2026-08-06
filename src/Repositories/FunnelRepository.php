<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

final class FunnelRepository
{
    public const DEFAULT_SLUG = 'property-finder';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnels` WHERE `id` = :id AND `deleted_at` IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    /** Slugs that would collide with a real path and can never be used. */
    public const RESERVED_SLUGS = ['admin', 'api', 'assets', 'storage', 'vendor', 'src', 'bin', 'database', 'templates', 'f'];

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnels` WHERE `slug` = :slug AND `deleted_at` IS NULL LIMIT 1',
            ['slug' => $slug]
        );
    }

    /**
     * Resolves a funnel for the PUBLIC router.
     * Archived and soft-deleted funnels are invisible here by construction.
     *
     * @return array<string,mixed>|null
     */
    public function findPublicBySlug(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnels`
             WHERE `slug` = :slug AND `deleted_at` IS NULL AND `archived_at` IS NULL
             LIMIT 1',
            ['slug' => $slug]
        );
    }

    /** The funnel served at the site root: the seeded one, else the oldest live funnel. */
    public function primary(): ?array
    {
        $default = $this->findBySlug(self::DEFAULT_SLUG);

        if ($default !== null && $default['archived_at'] === null) {
            return $default;
        }

        return Database::selectOne(
            'SELECT * FROM `funnels`
             WHERE `deleted_at` IS NULL AND `archived_at` IS NULL
             ORDER BY `id` ASC LIMIT 1'
        ) ?? $default;
    }

    /** The funnel served at the site root, for public visitors only. */
    public function primaryPublic(): ?array
    {
        $default = $this->findPublicBySlug(self::DEFAULT_SLUG);

        if ($default !== null) {
            return $default;
        }

        return Database::selectOne(
            "SELECT * FROM `funnels`
             WHERE `deleted_at` IS NULL AND `archived_at` IS NULL AND `status` = 'active'
             ORDER BY `id` ASC LIMIT 1"
        );
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM `funnels` WHERE `deleted_at` IS NULL';

        if (!$includeArchived) {
            $sql .= ' AND `archived_at` IS NULL';
        }

        return Database::select($sql . ' ORDER BY `id` ASC');
    }

    /**
     * Listing for the admin Funnels screen: adds the lead count and the
     * published-version metadata each row displays.
     *
     * @return list<array<string,mixed>>
     */
    public function listWithStats(bool $includeArchived = false): array
    {
        $sql = 'SELECT f.*,
                       (SELECT COUNT(*) FROM `leads` l
                         WHERE l.`funnel_id` = f.`id` AND l.`deleted_at` IS NULL) AS leads_count,
                       (SELECT COUNT(*) FROM `funnel_steps` s
                         WHERE s.`funnel_id` = f.`id` AND s.`is_active` = 1) AS active_steps
                FROM `funnels` f
                WHERE f.`deleted_at` IS NULL';

        if (!$includeArchived) {
            $sql .= ' AND f.`archived_at` IS NULL';
        }

        return Database::select($sql . ' ORDER BY f.`archived_at` IS NOT NULL ASC, f.`id` ASC');
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM `funnels` WHERE `slug` = :slug';
        $params = ['slug' => $slug];

        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :ex';
            $params['ex'] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** Finds the next free slug in the form base, base-2, base-3… */
    public function availableSlug(string $base, ?int $exceptId = null): string
    {
        $base = $base !== '' ? $base : 'funnel';
        $slug = $base;
        $n    = 1;

        while ($this->slugExists($slug, $exceptId) || in_array($slug, self::RESERVED_SLUGS, true)) {
            $n++;
            $slug = substr($base, 0, 110) . '-' . $n;
        }

        return $slug;
    }

    /**
     * Creates a funnel row. Steps, options and contact fields are added by the
     * caller (FunnelDuplicationService for a copy, or the blank scaffold).
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $columns = ['slug', 'name'];
        $values  = [':slug', ':name'];
        $params  = ['slug' => $data['slug'], 'name' => $data['name']];

        foreach (self::WRITABLE as $column) {
            if (!array_key_exists($column, $data) || in_array($column, ['slug', 'name'], true)) {
                continue;
            }

            $columns[] = $column;
            $values[]  = ':' . $column;
            $params[$column] = $data[$column];
        }

        $columns[] = 'draft_updated_at';
        $values[]  = 'NOW()';

        Database::execute(
            'INSERT INTO `funnels` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ')',
            $params
        );

        return Database::lastInsertId();
    }

    public function archive(int $funnelId): void
    {
        Database::execute(
            'UPDATE `funnels` SET `archived_at` = NOW() WHERE `id` = :id AND `archived_at` IS NULL',
            ['id' => $funnelId]
        );
    }

    public function restore(int $funnelId): void
    {
        Database::execute(
            'UPDATE `funnels` SET `archived_at` = NULL WHERE `id` = :id',
            ['id' => $funnelId]
        );
    }

    /**
     * Permanently removes a funnel and its configuration.
     *
     * Steps, options, contact fields and versions cascade. Leads do NOT:
     * `leads.funnel_id` is ON DELETE SET NULL and `lead_answers` carries its own
     * label snapshots, so every submission survives intact and readable.
     */
    public function delete(int $funnelId): void
    {
        Database::execute('DELETE FROM `funnels` WHERE `id` = :id', ['id' => $funnelId]);
    }

    public function leadCount(int $funnelId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `leads` WHERE `funnel_id` = :id',
            ['id' => $funnelId]
        );
    }

    /**
     * Every column an administrator may write, through any code path.
     * Anything not on this list can never reach SQL.
     */
    public const WRITABLE = [
        'slug', 'name', 'company_name', 'status', 'default_language', 'enabled_languages',
        'logo_path', 'favicon_path', 'background_image_path',
        'primary_color', 'accent_color', 'background_color',
        'submit_label_en', 'submit_label_ar',
        'success_title_en', 'success_title_ar',
        'success_message_en', 'success_message_ar',
        'success_button_en', 'success_button_ar',
        'recipient_email', 'redirect_url', 'redirect_delay',
        'webhook_url', 'webhook_enabled',
        'privacy_policy_url',
        'whatsapp_enabled', 'whatsapp_label_en', 'whatsapp_label_ar',
        'progress_bar_enabled', 'step_counter_enabled', 'back_button_enabled',
        'save_progress_enabled', 'min_completion_seconds',
    ];

    /**
     * Updates whitelisted funnel columns only.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $funnelId, array $data): void
    {
        $allowed = self::WRITABLE;

        $sets   = [];
        $params = ['id' => $funnelId];

        foreach ($data as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            $sets[] = "`{$column}` = :{$column}";
            $params[$column] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sets[] = '`draft_updated_at` = NOW()';

        Database::execute(
            'UPDATE `funnels` SET ' . implode(', ', $sets) . ' WHERE `id` = :id',
            $params
        );
    }

    /** Flags the draft as changed since the last publish. */
    public function touchDraft(int $funnelId): void
    {
        Database::execute(
            'UPDATE `funnels` SET `draft_updated_at` = NOW() WHERE `id` = :id',
            ['id' => $funnelId]
        );
    }

    public function hasUnpublishedChanges(array $funnel): bool
    {
        if ((int) $funnel['published_version'] === 0) {
            return true;
        }

        if (empty($funnel['draft_updated_at']) || empty($funnel['published_at'])) {
            return false;
        }

        return strtotime((string) $funnel['draft_updated_at']) > strtotime((string) $funnel['published_at']);
    }
}
