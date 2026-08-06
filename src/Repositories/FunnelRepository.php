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

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnels` WHERE `slug` = :slug AND `deleted_at` IS NULL LIMIT 1',
            ['slug' => $slug]
        );
    }

    /** The funnel served at the site root. */
    public function primary(): ?array
    {
        return $this->findBySlug(self::DEFAULT_SLUG)
            ?? Database::selectOne(
                'SELECT * FROM `funnels` WHERE `deleted_at` IS NULL ORDER BY `id` ASC LIMIT 1'
            );
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Database::select('SELECT * FROM `funnels` WHERE `deleted_at` IS NULL ORDER BY `id` ASC');
    }

    /**
     * Updates whitelisted funnel columns only.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $funnelId, array $data): void
    {
        $allowed = [
            'name', 'status', 'default_language', 'enabled_languages',
            'logo_path', 'background_image_path',
            'primary_color', 'accent_color', 'background_color',
            'submit_label_en', 'submit_label_ar',
            'success_title_en', 'success_title_ar',
            'success_message_en', 'success_message_ar',
            'privacy_policy_url',
            'whatsapp_enabled', 'whatsapp_label_en', 'whatsapp_label_ar',
            'progress_bar_enabled', 'step_counter_enabled', 'back_button_enabled',
            'save_progress_enabled', 'min_completion_seconds',
        ];

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
