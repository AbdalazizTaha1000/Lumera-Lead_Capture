<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

/**
 * Data access for the analytics subsystem.
 *
 * Reporting reads `analytics_sessions` wherever possible: it holds every
 * dimension and is never pruned, so summaries stay exact for all time. Only the
 * step-level timeline needs `analytics_events`, which is the one table with a
 * retention window.
 */
final class AnalyticsRepository
{
    /* ========================================================== sessions === */

    /** @return array<string,mixed>|null */
    public function findSession(string $uuid): ?array
    {
        return Database::selectOne(
            'SELECT * FROM `analytics_sessions` WHERE `session_uuid` = :u LIMIT 1',
            ['u' => $uuid]
        );
    }

    /** @param array<string,mixed> $data */
    public function createSession(array $data): int
    {
        $columns = array_keys($data);
        $holders = array_map(static fn ($c) => ':' . $c, $columns);

        Database::execute(
            'INSERT INTO `analytics_sessions` (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', $holders) . ')',
            $data
        );

        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function updateSession(int $sessionId, array $data): void
    {
        if ($data === []) {
            return;
        }

        $sets = [];
        $params = ['id' => $sessionId];

        foreach ($data as $column => $value) {
            $sets[] = "`{$column}` = :{$column}";
            $params[$column] = $value;
        }

        Database::execute(
            'UPDATE `analytics_sessions` SET ' . implode(', ', $sets) . ' WHERE `id` = :id',
            $params
        );
    }

    public function touchSession(int $sessionId): void
    {
        Database::execute(
            'UPDATE `analytics_sessions` SET `last_seen_at` = NOW() WHERE `id` = :id',
            ['id' => $sessionId]
        );
    }

    /* ============================================================ events === */

    /** @param array<string,mixed> $data */
    public function recordEvent(array $data): int
    {
        $columns = array_keys($data);
        $holders = array_map(static fn ($c) => ':' . $c, $columns);

        Database::execute(
            'INSERT INTO `analytics_events` (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', $holders) . ')',
            $data
        );

        return Database::lastInsertId();
    }

    /** Used to keep once-per-session events idempotent. */
    public function hasEvent(int $sessionId, string $type, ?string $stepKey = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM `analytics_events` WHERE `session_id` = :s AND `event_type` = :t';
        $params = ['s' => $sessionId, 't' => $type];

        if ($stepKey !== null) {
            $sql .= ' AND `step_key` = :k';
            $params['k'] = $stepKey;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /** @return list<array<string,mixed>> */
    public function sessionTimeline(int $sessionId): array
    {
        return Database::select(
            'SELECT `event_type`, `step_key`, `step_position`, `event_value`, `occurred_at`
             FROM `analytics_events` WHERE `session_id` = :s ORDER BY `id` ASC',
            ['s' => $sessionId]
        );
    }

    /* =========================================================== reports === */

    /**
     * Session-derived summary. Exact for any range: sessions are never pruned.
     *
     * @return array<string,mixed>
     */
    public function summary(?int $funnelId, string $from, string $to): array
    {
        // A null funnel means "every funnel" — the dashboard's whole-site view.
        $scope  = $funnelId !== null ? 'AND `funnel_id` = :fid' : '';
        $params = ['from' => $from, 'to' => $to];

        if ($funnelId !== null) {
            $params['fid'] = $funnelId;
        }

        $row = Database::selectOne(
            "SELECT
                COUNT(*) AS sessions,
                COUNT(DISTINCT `visitor_hash`) AS unique_visitors,
                SUM(CASE WHEN `completed_at` IS NOT NULL THEN 1 ELSE 0 END) AS completions,
                SUM(CASE WHEN `abandoned_at` IS NOT NULL THEN 1 ELSE 0 END) AS abandonments,
                SUM(CASE WHEN `steps_started` > 0 THEN 1 ELSE 0 END) AS engaged,
                SUM(CASE WHEN `lead_id` IS NOT NULL THEN 1 ELSE 0 END) AS attributed_leads,
                AVG(CASE WHEN `completed_at` IS NOT NULL THEN `duration_seconds` END) AS avg_completion
             FROM `analytics_sessions`
             WHERE `is_bot` = 0 {$scope}
               AND `started_at` >= :from AND `started_at` <= :to",
            $params
        ) ?? [];

        return [
            'sessions'         => (int) ($row['sessions'] ?? 0),
            'unique_visitors'  => (int) ($row['unique_visitors'] ?? 0),
            'completions'      => (int) ($row['completions'] ?? 0),
            'abandonments'     => (int) ($row['abandonments'] ?? 0),
            'engaged_sessions' => (int) ($row['engaged'] ?? 0),
            'attributed_leads' => (int) ($row['attributed_leads'] ?? 0),
            'avg_completion_seconds' => $row['avg_completion'] !== null ? (int) round((float) $row['avg_completion']) : null,
        ];
    }

    /** Page views come from events, so they are bounded by the retention window. */
    public function viewCount(?int $funnelId, string $from, string $to): int
    {
        $scope  = $funnelId !== null ? 'AND e.`funnel_id` = :fid' : '';
        $params = ['from' => $from, 'to' => $to];

        if ($funnelId !== null) {
            $params['fid'] = $funnelId;
        }

        return (int) Database::scalar(
            "SELECT COUNT(*)
             FROM `analytics_events` e
             INNER JOIN `analytics_sessions` s ON s.`id` = e.`session_id` AND s.`is_bot` = 0
             WHERE e.`event_type` = 'funnel_view' {$scope}
               AND e.`occurred_at` >= :from AND e.`occurred_at` <= :to",
            $params
        );
    }

    public function leadCount(?int $funnelId, string $from, string $to): int
    {
        $scope  = $funnelId !== null ? 'AND `funnel_id` = :fid' : '';
        $params = ['from' => $from, 'to' => $to];

        if ($funnelId !== null) {
            $params['fid'] = $funnelId;
        }

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM `leads`
             WHERE `deleted_at` IS NULL {$scope}
               AND `submitted_at` >= :from AND `submitted_at` <= :to",
            $params
        );
    }

    /**
     * Daily series straight from sessions and leads.
     *
     * @return array<string, array<string,int>> keyed by Y-m-d
     */
    public function dailySeries(int $funnelId, string $from, string $to): array
    {
        $out = [];

        $sessions = Database::select(
            "SELECT DATE(`started_at`) AS d,
                    COUNT(*) AS sessions,
                    COUNT(DISTINCT `visitor_hash`) AS visitors,
                    SUM(CASE WHEN `completed_at` IS NOT NULL THEN 1 ELSE 0 END) AS completions,
                    SUM(CASE WHEN `abandoned_at` IS NOT NULL THEN 1 ELSE 0 END) AS abandonments,
                    SUM(CASE WHEN `steps_started` > 0 THEN 1 ELSE 0 END) AS engaged,
                    SUM(CASE WHEN `device_type` = 'desktop' THEN 1 ELSE 0 END) AS desktop,
                    SUM(CASE WHEN `device_type` = 'mobile' THEN 1 ELSE 0 END) AS mobile,
                    SUM(CASE WHEN `device_type` = 'tablet' THEN 1 ELSE 0 END) AS tablet,
                    AVG(CASE WHEN `completed_at` IS NOT NULL THEN `duration_seconds` END) AS avg_completion
             FROM `analytics_sessions`
             WHERE `funnel_id` = :fid AND `is_bot` = 0
               AND `started_at` >= :from AND `started_at` <= :to
             GROUP BY d",
            ['fid' => $funnelId, 'from' => $from, 'to' => $to]
        );

        foreach ($sessions as $r) {
            $out[(string) $r['d']] = [
                'sessions'     => (int) $r['sessions'],
                'visitors'     => (int) $r['visitors'],
                'completions'  => (int) $r['completions'],
                'abandonments' => (int) $r['abandonments'],
                'engaged'      => (int) $r['engaged'],
                'desktop'      => (int) $r['desktop'],
                'mobile'       => (int) $r['mobile'],
                'tablet'       => (int) $r['tablet'],
                'avg_completion' => $r['avg_completion'] !== null ? (int) round((float) $r['avg_completion']) : null,
                'views'        => 0,
                'leads'        => 0,
            ];
        }

        $views = Database::select(
            "SELECT DATE(e.`occurred_at`) AS d, COUNT(*) AS views
             FROM `analytics_events` e
             INNER JOIN `analytics_sessions` s ON s.`id` = e.`session_id` AND s.`is_bot` = 0
             WHERE e.`funnel_id` = :fid AND e.`event_type` = 'funnel_view'
               AND e.`occurred_at` >= :from AND e.`occurred_at` <= :to
             GROUP BY d",
            ['fid' => $funnelId, 'from' => $from, 'to' => $to]
        );

        foreach ($views as $r) {
            $out[(string) $r['d']]['views'] = (int) $r['views'];
        }

        $leads = Database::select(
            'SELECT DATE(`submitted_at`) AS d, COUNT(*) AS leads
             FROM `leads`
             WHERE `funnel_id` = :fid AND `deleted_at` IS NULL
               AND `submitted_at` >= :from AND `submitted_at` <= :to
             GROUP BY d',
            ['fid' => $funnelId, 'from' => $from, 'to' => $to]
        );

        foreach ($leads as $r) {
            $date = (string) $r['d'];
            if (!isset($out[$date])) {
                $out[$date] = [
                    'sessions' => 0, 'visitors' => 0, 'completions' => 0, 'abandonments' => 0,
                    'engaged' => 0, 'desktop' => 0, 'mobile' => 0, 'tablet' => 0,
                    'avg_completion' => null, 'views' => 0, 'leads' => 0,
                ];
            }
            $out[$date]['leads'] = (int) $r['leads'];
        }

        return $out;
    }

    /**
     * Per-step counts from the event timeline.
     *
     * Views, starts and completions count PEOPLE, not events: one session that
     * navigates back and sees a step twice reached it once. Counting raw events
     * there would inflate views without inflating completions, and report a
     * drop-off that never happened.
     *
     * Backs and validation errors are genuine volumes and stay raw counts —
     * "three people hit an error twice each" is the interesting number.
     *
     * @return array<string, array{views:int, starts:int, completions:int, backs:int, errors:int}>
     */
    public function stepCounts(int $funnelId, string $from, string $to): array
    {
        $rows = Database::select(
            "SELECT e.`step_key` AS k, e.`event_type` AS t,
                    COUNT(*) AS events,
                    COUNT(DISTINCT e.`session_id`) AS people
             FROM `analytics_events` e
             INNER JOIN `analytics_sessions` s ON s.`id` = e.`session_id` AND s.`is_bot` = 0
             WHERE e.`funnel_id` = :fid AND e.`step_key` IS NOT NULL
               AND e.`occurred_at` >= :from AND e.`occurred_at` <= :to
               AND e.`event_type` IN ('step_view','step_start','step_complete','step_back','validation_error')
             GROUP BY k, t",
            ['fid' => $funnelId, 'from' => $from, 'to' => $to]
        );

        $perPerson = ['step_view' => 'views', 'step_start' => 'starts', 'step_complete' => 'completions'];
        $perEvent  = ['step_back' => 'backs', 'validation_error' => 'errors'];

        $map = [];

        foreach ($rows as $r) {
            $key = (string) $r['k'];
            $type = (string) $r['t'];
            $map[$key] ??= ['views' => 0, 'starts' => 0, 'completions' => 0, 'backs' => 0, 'errors' => 0];

            if (isset($perPerson[$type])) {
                $map[$key][$perPerson[$type]] = (int) $r['people'];
            } elseif (isset($perEvent[$type])) {
                $map[$key][$perEvent[$type]] = (int) $r['events'];
            }
        }

        return $map;
    }

    /**
     * Average seconds a visitor spends on each step.
     *
     * Reduced per session first: a step seen twice pairs its view with several
     * completions, so the tightest view-to-complete gap is taken as that
     * person's time on the step, and those per-person figures are averaged.
     * Averaging the raw pairs would let a back-navigation stretch the number.
     *
     * @return array<string,int>
     */
    public function stepDurations(int $funnelId, string $from, string $to): array
    {
        $rows = Database::select(
            "SELECT k, AVG(secs) AS secs FROM (
                SELECT v.`step_key` AS k,
                       v.`session_id` AS sid,
                       MIN(TIMESTAMPDIFF(SECOND, v.`occurred_at`, c.`occurred_at`)) AS secs
                FROM `analytics_events` v
                INNER JOIN `analytics_events` c
                        ON c.`session_id` = v.`session_id`
                       AND c.`step_key` = v.`step_key`
                       AND c.`event_type` = 'step_complete'
                       AND c.`occurred_at` >= v.`occurred_at`
                INNER JOIN `analytics_sessions` s ON s.`id` = v.`session_id` AND s.`is_bot` = 0
                WHERE v.`funnel_id` = :fid AND v.`event_type` = 'step_view'
                  AND v.`occurred_at` >= :from AND v.`occurred_at` <= :to
                GROUP BY k, sid
            ) per_person
            GROUP BY k",
            ['fid' => $funnelId, 'from' => $from, 'to' => $to]
        );

        $out = [];

        foreach ($rows as $r) {
            if ($r['secs'] !== null) {
                $out[(string) $r['k']] = max(0, (int) round((float) $r['secs']));
            }
        }

        return $out;
    }

    /**
     * Grouped session dimension, e.g. device_type or country_code.
     *
     * @return list<array{label: string, total: int, leads: int}>
     */
    public function dimension(int $funnelId, string $column, string $from, string $to, int $limit = 12): array
    {
        $allowed = [
            'source_group', 'utm_source', 'utm_medium', 'utm_campaign',
            'device_type', 'browser', 'os', 'country_code', 'city',
            'referrer_domain', 'language',
        ];

        if (!in_array($column, $allowed, true)) {
            return [];
        }

        $limit = max(1, min(50, $limit));

        $rows = Database::select(
            "SELECT COALESCE(NULLIF(`{$column}`, ''), '(none)') AS label,
                    COUNT(*) AS total,
                    SUM(CASE WHEN `lead_id` IS NOT NULL THEN 1 ELSE 0 END) AS leads
             FROM `analytics_sessions`
             WHERE `funnel_id` = :fid AND `is_bot` = 0
               AND `started_at` >= :from AND `started_at` <= :to
             GROUP BY label
             ORDER BY total DESC
             LIMIT {$limit}",
            ['fid' => $funnelId, 'from' => $from, 'to' => $to]
        );

        return array_map(
            static fn ($r) => [
                'label' => (string) $r['label'],
                'total' => (int) $r['total'],
                'leads' => (int) $r['leads'],
            ],
            $rows
        );
    }

    /* =========================================================== rollups === */

    /** @return list<array<string,mixed>> */
    public function rollups(int $funnelId, string $fromDate, string $toDate): array
    {
        return Database::select(
            'SELECT * FROM `analytics_daily_rollups`
             WHERE `funnel_id` = :fid AND `date` >= :from AND `date` <= :to
             ORDER BY `date` ASC',
            ['fid' => $funnelId, 'from' => $fromDate, 'to' => $toDate]
        );
    }

    /** @param array<string,mixed> $row */
    public function upsertRollup(array $row): void
    {
        $columns = array_keys($row);
        $holders = array_map(static fn ($c) => ':' . $c, $columns);

        $updates = [];
        foreach ($columns as $c) {
            if ($c === 'funnel_id' || $c === 'date') { continue; }
            $updates[] = "`{$c}` = VALUES(`{$c}`)";
        }

        Database::execute(
            'INSERT INTO `analytics_daily_rollups` (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', $holders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            $row
        );
    }

    /* ======================================================= maintenance === */

    /** @return list<array<string,mixed>> sessions that went quiet without completing */
    public function staleSessions(int $timeoutMinutes, int $limit = 5000): array
    {
        $limit = max(1, min(50000, $limit));

        return Database::select(
            "SELECT `id`, `started_at`, `last_seen_at`
             FROM `analytics_sessions`
             WHERE `completed_at` IS NULL AND `abandoned_at` IS NULL
               AND `last_seen_at` < (NOW() - INTERVAL :mins MINUTE)
             ORDER BY `id` ASC
             LIMIT {$limit}",
            ['mins' => $timeoutMinutes]
        );
    }

    public function pruneEvents(int $retentionDays, int $batch = 20000): int
    {
        $batch = max(100, min(200000, $batch));

        return Database::execute(
            "DELETE FROM `analytics_events`
             WHERE `occurred_at` < (NOW() - INTERVAL :days DAY)
             LIMIT {$batch}",
            ['days' => $retentionDays]
        );
    }

    /** @return list<int> funnel ids that have analytics or leads on a date */
    public function funnelsWithActivity(string $date): array
    {
        $rows = Database::select(
            'SELECT DISTINCT `funnel_id` AS id FROM `analytics_sessions`
             WHERE `funnel_id` IS NOT NULL AND DATE(`started_at`) = :d
             UNION
             SELECT DISTINCT `funnel_id` AS id FROM `leads`
             WHERE `funnel_id` IS NOT NULL AND DATE(`submitted_at`) = :d2',
            ['d' => $date, 'd2' => $date]
        );

        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    public function earliestActivityDate(): ?string
    {
        $value = Database::scalar(
            'SELECT LEAST(
                COALESCE((SELECT MIN(DATE(`started_at`)) FROM `analytics_sessions`), CURDATE()),
                COALESCE((SELECT MIN(DATE(`submitted_at`)) FROM `leads`), CURDATE())
             )'
        );

        return $value !== false && $value !== null ? (string) $value : null;
    }

    /** True once any real session exists — used to mark pre-analytics history. */
    public function firstSessionDate(): ?string
    {
        $value = Database::scalar('SELECT MIN(DATE(`started_at`)) FROM `analytics_sessions`');

        return ($value === false || $value === null) ? null : (string) $value;
    }
}
