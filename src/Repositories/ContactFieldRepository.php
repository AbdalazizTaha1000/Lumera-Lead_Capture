<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

final class ContactFieldRepository
{
    /** Keys the funnel and the leads table depend on — never deletable. */
    public const SYSTEM_KEYS = ['full_name', 'country_code', 'phone', 'email', 'preferred_language'];

    /** Every contact field key the MVP supports. */
    public const SUPPORTED_KEYS = [
        'full_name', 'country_code', 'phone', 'email',
        'preferred_language', 'nationality', 'preferred_contact_method',
    ];

    private const WRITABLE = [
        'label_en', 'label_ar', 'placeholder_en', 'placeholder_ar',
        'is_required', 'is_active', 'min_length', 'max_length',
        'validation_pattern', 'choices', 'field_type',
    ];

    /** @return list<array<string,mixed>> */
    public function forFunnel(int $funnelId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM `funnel_contact_fields` WHERE `funnel_id` = :fid';

        if ($activeOnly) {
            $sql .= ' AND `is_active` = 1';
        }

        $sql .= ' ORDER BY `sort_order` ASC, `id` ASC';

        return Database::select($sql, ['fid' => $funnelId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnel_contact_fields` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByKey(int $funnelId, string $key): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnel_contact_fields` WHERE `funnel_id` = :fid AND `field_key` = :k LIMIT 1',
            ['fid' => $funnelId, 'k' => $key]
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        foreach (self::WRITABLE as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $sets[] = "`{$column}` = :{$column}";
            $params[$column] = $data[$column];
        }

        if ($sets === []) {
            return;
        }

        Database::execute(
            'UPDATE `funnel_contact_fields` SET ' . implode(', ', $sets) . ' WHERE `id` = :id',
            $params
        );
    }

    /** @param list<int> $orderedIds */
    public function reorder(int $funnelId, array $orderedIds): int
    {
        $position = 0;
        $applied  = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $applied += Database::execute(
                'UPDATE `funnel_contact_fields` SET `sort_order` = :p WHERE `id` = :id AND `funnel_id` = :fid',
                ['p' => $position, 'id' => (int) $id, 'fid' => $funnelId]
            );
        }

        return $applied;
    }

    public function isSystemKey(string $key): bool
    {
        return in_array($key, self::SYSTEM_KEYS, true);
    }
}
