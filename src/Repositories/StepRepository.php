<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

final class StepRepository
{
    /** Columns an administrator may write. */
    private const WRITABLE = [
        'step_key', 'step_type',
        'title_en', 'title_ar', 'description_en', 'description_ar',
        'placeholder_en', 'placeholder_ar',
        'is_required', 'is_active', 'auto_advance',
        'min_length', 'max_length', 'min_value', 'max_value',
        'validation_pattern', 'validation_message_en', 'validation_message_ar',
        'condition_parent_key', 'condition_operator', 'condition_value',
    ];

    /** @return list<array<string,mixed>> */
    public function allForFunnel(int $funnelId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM `funnel_steps` WHERE `funnel_id` = :fid';

        if ($activeOnly) {
            $sql .= ' AND `is_active` = 1';
        }

        $sql .= ' ORDER BY `sort_order` ASC, `id` ASC';

        return Database::select($sql, ['fid' => $funnelId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $stepId): ?array
    {
        return Database::selectOne('SELECT * FROM `funnel_steps` WHERE `id` = :id LIMIT 1', ['id' => $stepId]);
    }

    /** @return array<string,mixed>|null */
    public function findByKey(int $funnelId, string $key): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `funnel_steps` WHERE `funnel_id` = :fid AND `step_key` = :k LIMIT 1',
            ['fid' => $funnelId, 'k' => $key]
        );
    }

    public function keyExists(int $funnelId, string $key, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM `funnel_steps` WHERE `funnel_id` = :fid AND `step_key` = :k';
        $params = ['fid' => $funnelId, 'k' => $key];

        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :ex';
            $params['ex'] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** @param array<string,mixed> $data */
    public function create(int $funnelId, array $data): int
    {
        $columns = ['funnel_id', 'sort_order'];
        $values  = [':funnel_id', ':sort_order'];
        $params  = [
            'funnel_id'  => $funnelId,
            'sort_order' => $data['sort_order'] ?? ($this->maxOrder($funnelId) + 1),
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
            'INSERT INTO `funnel_steps` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ')',
            $params
        );

        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $stepId, array $data): void
    {
        $sets   = [];
        $params = ['id' => $stepId];

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
            'UPDATE `funnel_steps` SET ' . implode(', ', $sets) . ' WHERE `id` = :id',
            $params
        );
    }

    public function delete(int $stepId): void
    {
        // Options cascade; lead_answers.step_id is SET NULL and keeps its snapshot.
        Database::execute('DELETE FROM `funnel_steps` WHERE `id` = :id', ['id' => $stepId]);
    }

    public function maxOrder(int $funnelId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(MAX(`sort_order`), 0) FROM `funnel_steps` WHERE `funnel_id` = :fid',
            ['fid' => $funnelId]
        );
    }

    /**
     * Applies a new ordering. Caller wraps this in a transaction.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(int $funnelId, array $orderedIds): int
    {
        $position = 0;
        $applied  = 0;

        foreach ($orderedIds as $stepId) {
            $position++;
            $applied += Database::execute(
                'UPDATE `funnel_steps` SET `sort_order` = :pos WHERE `id` = :id AND `funnel_id` = :fid',
                ['pos' => $position, 'id' => (int) $stepId, 'fid' => $funnelId]
            );
        }

        return $applied;
    }

    /** Normalises sort_order to a dense 1..n sequence. */
    public function resequence(int $funnelId): void
    {
        $steps = Database::select(
            'SELECT `id` FROM `funnel_steps` WHERE `funnel_id` = :fid ORDER BY `sort_order` ASC, `id` ASC',
            ['fid' => $funnelId]
        );

        $position = 0;

        foreach ($steps as $step) {
            $position++;
            Database::execute(
                'UPDATE `funnel_steps` SET `sort_order` = :p WHERE `id` = :id',
                ['p' => $position, 'id' => (int) $step['id']]
            );
        }
    }

    /** Shifts a step one place up (-1) or down (+1). */
    public function move(int $funnelId, int $stepId, int $direction): bool
    {
        $steps = Database::select(
            'SELECT `id` FROM `funnel_steps` WHERE `funnel_id` = :fid ORDER BY `sort_order` ASC, `id` ASC',
            ['fid' => $funnelId]
        );

        $ids   = array_map(static fn ($s) => (int) $s['id'], $steps);
        $index = array_search($stepId, $ids, true);

        if ($index === false) {
            return false;
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($ids)) {
            return false;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        $this->reorder($funnelId, $ids);

        return true;
    }
}
