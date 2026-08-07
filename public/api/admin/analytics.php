<?php

declare(strict_types=1);

/**
 * GET /api/admin/analytics.php?funnel_id=1&days=30
 *
 * Read-only funnel analytics derived entirely from stored leads. Adds no table
 * and writes nothing.
 *
 * Visitor counts, conversion rate, completion rate and per-step drop-off are
 * NOT reported here: the platform stores submitted leads, not page or step
 * views, so those figures cannot be computed without view tracking. The
 * response says so explicitly rather than inventing numbers.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\Database;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\StepRepository;

AdminEndpoint::read(dirname(__DIR__, 3));

$funnels  = new FunnelRepository();
$funnelId = AdminEndpoint::funnelId($_GET);
$funnel   = $funnels->find($funnelId);

if ($funnel === null) {
    Response::error('Funnel not found.', 404);
}

$days = max(7, min(365, AdminEndpoint::intParam($_GET, 'days', 30)));

$scope  = ['fid' => $funnelId];
$window = ['fid' => $funnelId, 'days' => $days];

// ------------------------------------------------------------------ totals --
$totals = Database::selectOne(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN DATE(`submitted_at`) = CURDATE() THEN 1 ELSE 0 END) AS today,
        SUM(CASE WHEN `submitted_at` >= (NOW() - INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
        SUM(CASE WHEN `submitted_at` >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS month,
        AVG(`lead_score`) AS avg_score,
        SUM(CASE WHEN `consent_given` = 1 THEN 1 ELSE 0 END) AS consented
     FROM `leads`
     WHERE `funnel_id` = :fid AND `deleted_at` IS NULL',
    $scope
) ?? [];

// ------------------------------------------------------------- daily trend --
$rows = Database::select(
    'SELECT DATE(`submitted_at`) AS d, COUNT(*) AS total
     FROM `leads`
     WHERE `funnel_id` = :fid AND `deleted_at` IS NULL
       AND `submitted_at` >= (NOW() - INTERVAL :days DAY)
     GROUP BY d ORDER BY d ASC',
    $window
);

$byDate = [];
foreach ($rows as $row) {
    $byDate[(string) $row['d']] = (int) $row['total'];
}

// Dense series: a day with no leads must still appear, as a zero.
$trend = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $trend[] = ['date' => $date, 'leads' => $byDate[$date] ?? 0];
}

/** @return list<array{label: string, total: int}> */
$breakdown = static function (string $expression) use ($funnelId): array {
    $rows = Database::select(
        "SELECT {$expression} AS label, COUNT(*) AS total
         FROM `leads`
         WHERE `funnel_id` = :fid AND `deleted_at` IS NULL
         GROUP BY label ORDER BY total DESC LIMIT 8",
        ['fid' => $funnelId]
    );

    return array_map(
        static fn ($r) => ['label' => (string) $r['label'], 'total' => (int) $r['total']],
        $rows
    );
};

// -------------------------------------------------------- answer coverage --
// How many leads answered each step. This is the closest honest proxy for
// engagement per step that stored leads can give: it only covers people who
// reached the end, so it is labelled as such in the UI and never called
// "drop-off".
$steps = (new StepRepository())->allForFunnel($funnelId, true);
$total = (int) ($totals['total'] ?? 0);

$coverage = [];

foreach ($steps as $step) {
    $answered = (int) Database::scalar(
        'SELECT COUNT(DISTINCT a.`lead_id`)
         FROM `lead_answers` a
         INNER JOIN `leads` l ON l.`id` = a.`lead_id`
         WHERE l.`funnel_id` = :fid AND l.`deleted_at` IS NULL
           AND a.`step_key` = :k
           AND (a.`answer_value` IS NOT NULL AND a.`answer_value` <> \'\')',
        ['fid' => $funnelId, 'k' => (string) $step['step_key']]
    );

    $coverage[] = [
        'key'      => (string) $step['step_key'],
        'title'    => (string) ($step['title_en'] ?: $step['step_key']),
        'type'     => (string) $step['step_type'],
        'answered' => $answered,
        'percent'  => $total > 0 ? (int) round(($answered / $total) * 100) : 0,
    ];
}

Response::success([
    'funnel' => [
        'id'   => (int) $funnel['id'],
        'name' => $funnel['name'],
        'slug' => $funnel['slug'],
    ],
    'range_days' => $days,
    'totals' => [
        'leads'     => $total,
        'today'     => (int) ($totals['today'] ?? 0),
        'week'      => (int) ($totals['week'] ?? 0),
        'month'     => (int) ($totals['month'] ?? 0),
        'avg_score' => round((float) ($totals['avg_score'] ?? 0), 1),
        'consented' => (int) ($totals['consented'] ?? 0),
    ],
    'trend'    => $trend,
    'sources'  => $breakdown("COALESCE(NULLIF(`utm_source`, ''), 'direct')"),
    'devices'  => $breakdown("COALESCE(NULLIF(`device_type`, ''), 'unknown')"),
    'campaigns' => $breakdown("COALESCE(NULLIF(`utm_campaign`, ''), 'none')"),
    'languages' => $breakdown("COALESCE(NULLIF(`interface_language`, ''), 'en')"),
    'step_coverage' => $coverage,

    // Declared, not silently omitted: the UI shows these as unavailable rather
    // than rendering a plausible-looking zero.
    'unavailable' => [
        'visitors'        => 'Requires page-view tracking, which this platform does not collect.',
        'conversion_rate' => 'Needs visitor counts.',
        'completion_rate' => 'Needs funnel-start events.',
        'step_dropoff'    => 'Needs per-step view events.',
    ],
]);
