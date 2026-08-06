<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

final class OptionRepository
{
    private const WRITABLE = [
        'option_value', 'label_en', 'label_ar', 'icon', 'score', 'is_active', 'metadata',
    ];

    /** @return list<array<string,mixed>> */
    public function forStep(int $stepId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM `funnel_step_options` WHERE `step_id` = :sid';

        if ($activeOnly) {
            $sql .= ' AND `is_active` = 1';
        }

        $sql .= ' ORDER BY `sort_order` ASC, `id` ASC';

        return Database::select($sql, ['sid' => $stepId]);
    }

    /**
     * Bulk load, keyed by step id — avoids N+1 when building a snapshot.
     *
     * @param list<int> $stepIds
     * @return array<int, list<array<string,mixed>>>
     */
    public function forSteps(array $stepIds, bool $activeOnly = false): array
    {
        if ($stepIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($stepIds), '?'));
        $sql = "SELECT * FROM `funnel_step_options` WHERE `step_id` IN ({$placeholders})";

        if ($activeOnly) {
            $sql .= ' AND `is_active` = 1';
        }

        $sql .= ' ORDER BY `step_id` ASC, `sort_order` ASC, `id` ASC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(array_map('intval', $stepIds));

        $grouped = [];

        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['step_id']][] = $row;
        }

        return $grouped;
    }

    /** @return array<string,mixed>|null */
    public function find(int $optionId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnel_step_options` WHERE `id` = :id LIMIT 1',
            ['id' => $optionId]
        );
    }

    public function valueExists(int $stepId, string $value, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM `funnel_step_options` WHERE `step_id` = :sid AND `option_value` = :v';
        $params = ['sid' => $stepId, 'v' => $value];

        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :ex';
            $params['ex'] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** @param array<string,mixed> $data */
    public function create(int $stepId, array $data): int
    {
        $columns = ['step_id', 'sort_order'];
        $values  = [':step_id', ':sort_order'];
        $params  = [
            'step_id'    => $stepId,
            'sort_order' => $data['sort_order'] ?? ($this->maxOrder($stepId) + 1),
        ];

        foreach (self::WRITABLE as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $columns[] = $column;
            $values[]  = ':' . $column;
            $params[$column] = $data[$column];
        }

        Database::execute(
            'INSERT INTO `funnel_step_options` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ')',
            $params
        );

        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $optionId, array $data): void
    {
        $sets   = [];
        $params = ['id' => $optionId];

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
            'UPDATE `funnel_step_options` SET ' . implode(', ', $sets) . ' WHERE `id` = :id',
            $params
        );
    }

    public function delete(int $optionId): void
    {
        Database::execute('DELETE FROM `funnel_step_options` WHERE `id` = :id', ['id' => $optionId]);
    }

    public function deleteForStep(int $stepId): void
    {
        Database::execute('DELETE FROM `funnel_step_options` WHERE `step_id` = :sid', ['sid' => $stepId]);
    }

    public function maxOrder(int $stepId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(MAX(`sort_order`), 0) FROM `funnel_step_options` WHERE `step_id` = :sid',
            ['sid' => $stepId]
        );
    }

    /** @param list<int> $orderedIds */
    public function reorder(int $stepId, array $orderedIds): int
    {
        $position = 0;
        $applied  = 0;

        foreach ($orderedIds as $optionId) {
            $position++;
            $applied += Database::execute(
                'UPDATE `funnel_step_options` SET `sort_order` = :pos WHERE `id` = :id AND `step_id` = :sid',
                ['pos' => $position, 'id' => (int) $optionId, 'sid' => $stepId]
            );
        }

        return $applied;
    }

    public function move(int $stepId, int $optionId, int $direction): bool
    {
        $options = Database::select(
            'SELECT `id` FROM `funnel_step_options` WHERE `step_id` = :sid ORDER BY `sort_order` ASC, `id` ASC',
            ['sid' => $stepId]
        );

        $ids   = array_map(static fn ($o) => (int) $o['id'], $options);
        $index = array_search($optionId, $ids, true);

        if ($index === false) {
            return false;
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($ids)) {
            return false;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        $this->reorder($stepId, $ids);

        return true;
    }
}
