<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

final class LeadRepository
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'unqualified', 'converted', 'archived'];

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);

        Database::execute(
            'INSERT INTO `leads` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')',
            $data
        );

        return Database::lastInsertId();
    }

    /** @param list<array<string,mixed>> $answers */
    public function insertAnswers(int $leadId, array $answers): void
    {
        if ($answers === []) {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO `lead_answers`
                (`lead_id`, `step_id`, `step_key`, `step_title`, `step_type`,
                 `answer_value`, `answer_label`, `answer_json`, `score`, `sort_order`)
             VALUES (:lead_id, :step_id, :step_key, :step_title, :step_type,
                 :answer_value, :answer_label, :answer_json, :score, :sort_order)'
        );

        foreach ($answers as $answer) {
            $stmt->execute([
                'lead_id'      => $leadId,
                'step_id'      => $answer['step_id'] ?? null,
                'step_key'     => $answer['step_key'],
                'step_title'   => $answer['step_title'] ?? '',
                'step_type'    => $answer['step_type'],
                'answer_value' => $answer['answer_value'] ?? null,
                'answer_label' => $answer['answer_label'] ?? null,
                'answer_json'  => $answer['answer_json'] ?? null,
                'score'        => (int) ($answer['score'] ?? 0),
                'sort_order'   => (int) ($answer['sort_order'] ?? 0),
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $leadId, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT * FROM `leads` WHERE `id` = :id';

        if (!$includeDeleted) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        return Database::selectOne($sql . ' LIMIT 1', ['id' => $leadId]);
    }

    /** @return list<array<string,mixed>> */
    public function answers(int $leadId): array
    {
        return Database::select(
            'SELECT * FROM `lead_answers` WHERE `lead_id` = :id ORDER BY `sort_order` ASC, `id` ASC',
            ['id' => $leadId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function notes(int $leadId): array
    {
        return Database::select(
            'SELECT n.*, u.`email` AS admin_email, u.`name` AS admin_name
             FROM `lead_notes` n
             LEFT JOIN `admin_users` u ON u.`id` = n.`admin_user_id`
             WHERE n.`lead_id` = :id ORDER BY n.`id` DESC',
            ['id' => $leadId]
        );
    }

    public function addNote(int $leadId, ?int $adminUserId, string $note): int
    {
        Database::execute(
            'INSERT INTO `lead_notes` (`lead_id`, `admin_user_id`, `note`) VALUES (:l, :a, :n)',
            ['l' => $leadId, 'a' => $adminUserId, 'n' => $note]
        );

        return Database::lastInsertId();
    }

    public function updateStatus(int $leadId, string $status): void
    {
        Database::execute(
            'UPDATE `leads` SET `status` = :s WHERE `id` = :id',
            ['s' => $status, 'id' => $leadId]
        );
    }

    public function softDelete(int $leadId): void
    {
        Database::execute('UPDATE `leads` SET `deleted_at` = NOW() WHERE `id` = :id', ['id' => $leadId]);
    }

    public function restore(int $leadId): void
    {
        Database::execute('UPDATE `leads` SET `deleted_at` = NULL WHERE `id` = :id', ['id' => $leadId]);
    }

    public function markEmail(int $leadId, string $status, ?string $error = null): void
    {
        Database::execute(
            'UPDATE `leads` SET `email_status` = :s, `email_error` = :e,
                    `email_sent_at` = CASE WHEN :s2 = \'sent\' THEN NOW() ELSE `email_sent_at` END
             WHERE `id` = :id',
            [
                's'  => $status,
                's2' => $status,
                'e'  => $error !== null ? mb_substr($error, 0, 500) : null,
                'id' => $leadId,
            ]
        );
    }

    /**
     * Recent duplicate check: same normalised phone on the same funnel within
     * the given window.
     */
    public function recentDuplicate(?string $submissionHash, int $windowSeconds = 300): ?array
    {
        if ($submissionHash === null || $submissionHash === '') {
            return null;
        }

        return Database::selectOne(
            'SELECT `id`, `submitted_at` FROM `leads`
             WHERE `submission_hash` = :h AND `submitted_at` >= (NOW() - INTERVAL :w SECOND)
             ORDER BY `id` DESC LIMIT 1',
            ['h' => $submissionHash, 'w' => $windowSeconds]
        );
    }

    /**
     * Filtered, paginated listing.
     *
     * @param array<string,mixed> $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM `leads` l WHERE ' . $where,
            $params
        );

        $perPage = max(1, min(200, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT l.* FROM `leads` l WHERE ' . $where .
            " ORDER BY l.`id` DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Unpaginated stream for CSV export (capped for safety).
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function forExport(array $filters, int $limit = 10000): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $limit = max(1, min(50000, $limit));

        return Database::select(
            'SELECT l.* FROM `leads` l WHERE ' . $where . " ORDER BY l.`id` DESC LIMIT {$limit}",
            $params
        );
    }

    /**
     * Answers for many leads at once, keyed by lead id.
     *
     * @param list<int> $leadIds
     * @return array<int, list<array<string,mixed>>>
     */
    public function answersForLeads(array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM `lead_answers` WHERE `lead_id` IN ({$placeholders}) ORDER BY `lead_id`, `sort_order`"
        );
        $stmt->execute(array_map('intval', $leadIds));

        $grouped = [];

        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['lead_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Builds the shared WHERE clause for listing and exporting.
     *
     * @param array<string,mixed> $filters
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        $clauses[] = empty($filters['include_deleted'])
            ? 'l.`deleted_at` IS NULL'
            : '1 = 1';

        if (!empty($filters['funnel_id'])) {
            $clauses[] = 'l.`funnel_id` = :funnel_id';
            $params['funnel_id'] = (int) $filters['funnel_id'];
        }

        if (!empty($filters['search'])) {
            // Each named placeholder is bound exactly once: PDO runs with
            // emulated prepares disabled, which forbids reusing a name.
            $clauses[] = '(l.`full_name` LIKE :search_name OR l.`email` LIKE :search_email
                           OR l.`phone` LIKE :search_phone OR l.`phone_normalized` LIKE :search_norm
                           OR CAST(l.`id` AS CHAR) = :search_exact)';

            $like = '%' . $filters['search'] . '%';
            $params['search_name']  = $like;
            $params['search_email'] = $like;
            $params['search_phone'] = $like;
            $params['search_norm']  = $like;
            $params['search_exact'] = (string) $filters['search'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $clauses[] = 'l.`status` = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $clauses[] = 'l.`submitted_at` >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'l.`submitted_at` <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['source'])) {
            $clauses[] = 'l.`utm_source` = :source';
            $params['source'] = $filters['source'];
        }

        if (!empty($filters['campaign'])) {
            $clauses[] = 'l.`utm_campaign` = :campaign';
            $params['campaign'] = $filters['campaign'];
        }

        // Answer-based filters resolve through lead_answers on the stable keys.
        if (!empty($filters['budget'])) {
            $clauses[] = 'EXISTS (SELECT 1 FROM `lead_answers` a WHERE a.`lead_id` = l.`id`
                          AND a.`step_key` = \'budget\' AND a.`answer_value` = :budget)';
            $params['budget'] = $filters['budget'];
        }

        if (!empty($filters['purpose'])) {
            $clauses[] = 'EXISTS (SELECT 1 FROM `lead_answers` a WHERE a.`lead_id` = l.`id`
                          AND a.`step_key` = \'property_purpose\' AND a.`answer_value` = :purpose)';
            $params['purpose'] = $filters['purpose'];
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** @return array<string,mixed> */
    public function stats(?int $funnelId = null): array
    {
        $scope  = $funnelId !== null ? 'AND `funnel_id` = :fid' : '';
        $params = $funnelId !== null ? ['fid' => $funnelId] : [];

        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE(`submitted_at`) = CURDATE() THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN `submitted_at` >= (NOW() - INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
                SUM(CASE WHEN `submitted_at` >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS month,
                SUM(CASE WHEN `status` = 'new' THEN 1 ELSE 0 END) AS new_leads,
                SUM(CASE WHEN `email_status` = 'failed' THEN 1 ELSE 0 END) AS email_failures
             FROM `leads` WHERE `deleted_at` IS NULL {$scope}",
            $params
        ) ?? [];

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'today'          => (int) ($row['today'] ?? 0),
            'week'           => (int) ($row['week'] ?? 0),
            'month'          => (int) ($row['month'] ?? 0),
            'new_leads'      => (int) ($row['new_leads'] ?? 0),
            'email_failures' => (int) ($row['email_failures'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function breakdownBySource(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));

        return Database::select(
            "SELECT COALESCE(NULLIF(`utm_source`, ''), 'direct') AS label, COUNT(*) AS total
             FROM `leads` WHERE `deleted_at` IS NULL
             GROUP BY label ORDER BY total DESC LIMIT {$limit}"
        );
    }

    /**
     * Breakdown over a dynamic answer key (e.g. budget, property_purpose).
     * @return list<array<string,mixed>>
     */
    public function breakdownByAnswer(string $stepKey, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return Database::select(
            "SELECT COALESCE(NULLIF(a.`answer_label`, ''), a.`answer_value`) AS label, COUNT(*) AS total
             FROM `lead_answers` a
             INNER JOIN `leads` l ON l.`id` = a.`lead_id` AND l.`deleted_at` IS NULL
             WHERE a.`step_key` = :k AND a.`answer_value` IS NOT NULL
             GROUP BY label ORDER BY total DESC LIMIT {$limit}",
            ['k' => $stepKey]
        );
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));

        return Database::select(
            "SELECT `id`, `full_name`, `email`, `country_code`, `phone`, `status`,
                    `lead_score`, `utm_source`, `submitted_at`, `email_status`
             FROM `leads` WHERE `deleted_at` IS NULL ORDER BY `id` DESC LIMIT {$limit}"
        );
    }

    /** @return list<string> distinct non-empty values for a filter dropdown */
    public function distinctValues(string $column): array
    {
        $allowed = ['utm_source', 'utm_campaign', 'utm_medium'];

        if (!in_array($column, $allowed, true)) {
            return [];
        }

        $rows = Database::select(
            "SELECT DISTINCT `{$column}` AS v FROM `leads`
             WHERE `deleted_at` IS NULL AND `{$column}` IS NOT NULL AND `{$column}` <> ''
             ORDER BY v ASC LIMIT 200"
        );

        return array_map(static fn ($r) => (string) $r['v'], $rows);
    }
}
