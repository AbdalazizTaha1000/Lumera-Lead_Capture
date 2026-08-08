<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Logger;
use Lumera\Repositories\AnalyticsRepository;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\LeadRepository;
use Lumera\Repositories\StepRepository;

/**
 * Summary figures for the dashboard overview.
 *
 * Lead counts come straight from the leads table and are always present. The
 * traffic block is additive: it reads the analytics tables when they exist and
 * reports itself unavailable otherwise, so a dashboard on an installation that
 * has not run the analytics migration still renders every lead figure.
 */
final class DashboardService
{
    /** Rolling window for the traffic figures on the dashboard. */
    private const TRAFFIC_DAYS = 30;

    public function __construct(
        private LeadRepository $leads = new LeadRepository(),
        private FunnelRepository $funnels = new FunnelRepository(),
        private StepRepository $steps = new StepRepository(),
        private AnalyticsRepository $analytics = new AnalyticsRepository(),
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $funnel = $this->funnels->primary();
        $stats  = $this->leads->stats();

        $activeSteps = 0;
        $totalSteps  = 0;
        $funnelBlock = null;

        if ($funnel !== null) {
            $steps       = $this->steps->allForFunnel((int) $funnel['id']);
            $totalSteps  = count($steps);
            $activeSteps = count(array_filter($steps, static fn ($s) => (int) $s['is_active'] === 1));

            $funnelBlock = [
                'id'                => (int) $funnel['id'],
                'name'              => $funnel['name'],
                'slug'              => $funnel['slug'],
                'status'            => $funnel['status'],
                'published_version' => (int) $funnel['published_version'],
                'published_at'      => $funnel['published_at'],
                'has_unpublished'   => $this->funnels->hasUnpublishedChanges($funnel),
                'active_steps'      => $activeSteps,
                'total_steps'       => $totalSteps,
            ];
        }

        return [
            'stats'  => $stats,
            'funnel' => $funnelBlock,
            'latest' => $this->leads->latest(8),
            'breakdowns' => [
                'source'  => $this->leads->breakdownBySource(6),
                'budget'  => $this->leads->breakdownByAnswer('budget', 6),
                'purpose' => $this->leads->breakdownByAnswer('property_purpose', 6),
            ],
            'traffic' => $this->traffic(),
        ];
    }

    /**
     * Site-wide traffic for the last 30 days.
     *
     * Every rate is null rather than zero when its denominator is empty: a rate
     * nobody could have measured is not 0%, it is unknown, and the dashboard
     * says so instead of inventing a number.
     *
     * @return array<string,mixed>
     */
    private function traffic(): array
    {
        $blank = [
            'available'           => false,
            'days'                => self::TRAFFIC_DAYS,
            'visitors'            => null,
            'sessions'            => null,
            'views'               => null,
            'completions'         => null,
            'conversion_rate'     => null,
            'completion_rate'     => null,
            'tracking_started_on' => null,
        ];

        if (!AnalyticsService::enabled()) {
            return $blank;
        }

        try {
            $from = date('Y-m-d 00:00:00', strtotime('-' . (self::TRAFFIC_DAYS - 1) . ' days'));
            $to   = date('Y-m-d 23:59:59');

            $summary = $this->analytics->summary(null, $from, $to);
            $leads   = $this->analytics->leadCount(null, $from, $to);

            $rate = static fn (int $part, int $whole): ?float =>
                $whole > 0 ? round(($part / $whole) * 100, 1) : null;

            return [
                'available'           => true,
                'days'                => self::TRAFFIC_DAYS,
                'visitors'            => $summary['unique_visitors'],
                'sessions'            => $summary['sessions'],
                'views'               => $this->analytics->viewCount(null, $from, $to),
                'completions'         => $summary['completions'],
                'leads'               => $leads,
                'attributed_leads'    => $summary['attributed_leads'],
                // Measured on both sides — see the note in the analytics API.
                'conversion_rate'     => $rate($summary['attributed_leads'], $summary['sessions']),
                'completion_rate'     => $rate($summary['completions'], $summary['engaged_sessions']),
                'tracking_started_on' => $this->analytics->firstSessionDate(),
            ];
        } catch (\Throwable $e) {
            // Most often: the analytics migration has not been run here. The
            // dashboard is a lead tool first, so it keeps working regardless.
            Logger::warning('Dashboard traffic unavailable.', ['error' => $e->getMessage()]);

            return $blank;
        }
    }
}
