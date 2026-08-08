<?php

declare(strict_types=1);

/**
 * GET /api/admin/analytics.php
 *
 * Query: funnel_id, date_from, date_to (or days), compare=1
 *
 * Reads sessions for every summary and dimension — that table is never pruned,
 * so figures stay exact for all time. Only the per-step timeline comes from
 * `analytics_events`, which has a retention window; the response says so.
 *
 * Metrics that were never measured are reported as unavailable rather than as
 * zero. Days before analytics existed are flagged lead-only.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\Response;
use Lumera\Repositories\AnalyticsRepository;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\StepRepository;
use Lumera\Services\AnalyticsService;
use Lumera\Support\Geo;
use Lumera\Support\StepType;

AdminEndpoint::read(dirname(__DIR__, 3));

$funnels = new FunnelRepository();
$repo    = new AnalyticsRepository();

$funnelId = AdminEndpoint::funnelId($_GET);
$funnel   = $funnels->find($funnelId);

if ($funnel === null) {
    Response::error('Funnel not found.', 404);
}

/* ------------------------------------------------------------------ range -- */
$isDate = static fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;

if ($isDate($_GET['date_from'] ?? null) && $isDate($_GET['date_to'] ?? null)) {
    $fromDate = (string) $_GET['date_from'];
    $toDate   = (string) $_GET['date_to'];

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
} else {
    $days = max(1, min(365, AdminEndpoint::intParam($_GET, 'days', 30)));
    $toDate = date('Y-m-d');
    $fromDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
}

$from = $fromDate . ' 00:00:00';
$to   = $toDate . ' 23:59:59';
$dayCount = max(1, (int) ((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1);

/* ---------------------------------------------------------------- summary -- */
$summary = $repo->summary($funnelId, $from, $to);
$views   = $repo->viewCount($funnelId, $from, $to);
$leads   = $repo->leadCount($funnelId, $from, $to);

$sessions = $summary['sessions'];
$engaged  = $summary['engaged_sessions'];

$rate = static fn (int $part, int $whole): ?float =>
    $whole > 0 ? round(($part / $whole) * 100, 1) : null;

$summaryOut = [
    'views'            => $views,
    'sessions'         => $sessions,
    'unique_visitors'  => $summary['unique_visitors'],
    'leads'            => $leads,
    'completions'      => $summary['completions'],
    'abandonments'     => $summary['abandonments'],
    'engaged_sessions' => $engaged,
    'attributed_leads' => $summary['attributed_leads'],
    // Leads per session, measured on both sides: only leads that were actually
    // matched to a tracked session count towards it. Using the raw lead total
    // would divide a number that includes untracked leads — leads from before
    // analytics existed, or from a visitor whose beacons never arrived — by a
    // session count that does not, and can report well over 100%.
    'conversion_rate'  => $rate($summary['attributed_leads'], $sessions),
    // Completions per engaged session: how well the funnel itself performs
    // once someone actually starts answering.
    'completion_rate'  => $rate($summary['completions'], $engaged),
    'abandonment_rate' => $rate($summary['abandonments'], $sessions),
    'avg_completion_seconds' => $summary['avg_completion_seconds'],
];

/* ------------------------------------------------------------------ trend -- */
$series = $repo->dailySeries($funnelId, $from, $to);

// Rollups carry the days whose raw events have been pruned, and flag the days
// that predate analytics entirely.
$rollupIndex = [];
foreach ($repo->rollups($funnelId, $fromDate, $toDate) as $r) {
    $rollupIndex[(string) $r['date']] = $r;
}

$firstSession = $repo->firstSessionDate();

$trend = [];
for ($i = 0; $i < $dayCount; $i++) {
    $date = date('Y-m-d', strtotime($fromDate . ' +' . $i . ' days'));
    $live = $series[$date] ?? null;
    $roll = $rollupIndex[$date] ?? null;

    // Views live in the event table, so a pruned day falls back to its rollup.
    $dayViews = $live['views'] ?? 0;
    if ($dayViews === 0 && $roll !== null) {
        $dayViews = (int) $roll['views'];
    }

    $leadOnly = $firstSession === null || $date < $firstSession;

    $trend[] = [
        'date'        => $date,
        'views'       => $dayViews,
        'sessions'    => $live['sessions'] ?? 0,
        'visitors'    => $live['visitors'] ?? 0,
        'leads'       => $live['leads'] ?? (int) ($roll['leads'] ?? 0),
        'completions' => $live['completions'] ?? 0,
        // True for days before the engine existed: the lead count is real, the
        // traffic figures were simply never measured.
        'lead_only'   => $leadOnly,
    ];
}

/* ------------------------------------------------------------------ steps -- */
$stepRows = (new StepRepository())->allForFunnel($funnelId, true);
$counts   = $repo->stepCounts($funnelId, $from, $to);
$times    = $repo->stepDurations($funnelId, $from, $to);

$steps = [];
$previousCompletions = null;

foreach ($stepRows as $index => $row) {
    $key = (string) $row['step_key'];
    $c = $counts[$key] ?? ['views' => 0, 'starts' => 0, 'completions' => 0, 'backs' => 0, 'errors' => 0];

    // Drop-off is measured against the people who reached this step.
    $dropped = max(0, $c['views'] - $c['completions']);

    $steps[] = [
        'position'      => $index + 1,
        'key'           => $key,
        'title'         => (string) ($row['title_en'] ?: $key),
        'type'          => (string) $row['step_type'],
        'type_label'    => StepType::label((string) $row['step_type']),
        'views'         => $c['views'],
        'starts'        => $c['starts'],
        'completions'   => $c['completions'],
        'backs'         => $c['backs'],
        'errors'        => $c['errors'],
        'dropped'       => $dropped,
        'drop_off_rate' => $c['views'] > 0 ? round(($dropped / $c['views']) * 100, 1) : null,
        // Share of the people who saw step 1 that also reached this one.
        'reach_rate'    => $previousCompletions === null
            ? ($c['views'] > 0 ? 100.0 : null)
            : ($previousCompletions > 0 ? round(($c['views'] / $previousCompletions) * 100, 1) : null),
        'avg_seconds'   => $times[$key] ?? null,
    ];

    if ($index === 0) {
        $previousCompletions = $c['views'];
    }
}

/* ------------------------------------------------------------- dimensions -- */
$countries = array_map(
    static function ($row) {
        $row['label'] = $row['label'] === '(none)' ? 'Unknown' : (Geo::countryName($row['label']) ?? $row['label']);

        return $row;
    },
    $repo->dimension($funnelId, 'country_code', $from, $to)
);

/* -------------------------------------------------------------- comparison -- */
$comparison = [];

if (($_GET['compare'] ?? '') === '1') {
    foreach ($funnels->listWithStats(false) as $row) {
        $id = (int) $row['id'];
        $s = $repo->summary($id, $from, $to);
        $l = $repo->leadCount($id, $from, $to);

        $comparison[] = [
            'funnel_id'       => $id,
            'name'            => $row['name'],
            'slug'            => $row['slug'],
            'sessions'        => $s['sessions'],
            'unique_visitors' => $s['unique_visitors'],
            'leads'           => $l,
            'completions'     => $s['completions'],
            'conversion_rate' => $rate($s['attributed_leads'], $s['sessions']),
            'completion_rate' => $rate($s['completions'], $s['engaged_sessions']),
        ];
    }

    usort($comparison, static fn ($a, $b) => $b['leads'] <=> $a['leads']);
}

Response::success([
    'funnel' => [
        'id'   => (int) $funnel['id'],
        'name' => $funnel['name'],
        'slug' => $funnel['slug'],
    ],
    'range' => [
        'from' => $fromDate,
        'to'   => $toDate,
        'days' => $dayCount,
    ],
    'summary'    => $summaryOut,
    'trend'      => $trend,
    'steps'      => $steps,
    'sources'    => $repo->dimension($funnelId, 'source_group', $from, $to),
    'utm_sources'   => $repo->dimension($funnelId, 'utm_source', $from, $to),
    'utm_mediums'   => $repo->dimension($funnelId, 'utm_medium', $from, $to),
    'campaigns'  => $repo->dimension($funnelId, 'utm_campaign', $from, $to),
    'referrers'  => $repo->dimension($funnelId, 'referrer_domain', $from, $to),
    'devices'    => $repo->dimension($funnelId, 'device_type', $from, $to),
    'browsers'   => $repo->dimension($funnelId, 'browser', $from, $to),
    'os'         => $repo->dimension($funnelId, 'os', $from, $to),
    'countries'  => $countries,
    'cities'     => $repo->dimension($funnelId, 'city', $from, $to),
    'comparison' => $comparison,
    'meta' => [
        'analytics_enabled'   => AnalyticsService::enabled(),
        'tracking_started_on' => $firstSession,
        'event_retention_days' => AnalyticsService::retentionDays(),
        'session_timeout_minutes' => AnalyticsService::timeoutMinutes(),
        // Step figures come from the event timeline, so they only cover the
        // retention window. Everything else is exact for any range.
        'step_data_from' => date('Y-m-d', strtotime('-' . AnalyticsService::retentionDays() . ' days')),
    ],
]);
