<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\LeadRepository;
use Lumera\Repositories\StepRepository;

/**
 * Summary figures for the dashboard overview. Deliberately simple aggregates —
 * no charting, no analytics engine.
 */
final class DashboardService
{
    public function __construct(
        private LeadRepository $leads = new LeadRepository(),
        private FunnelRepository $funnels = new FunnelRepository(),
        private StepRepository $steps = new StepRepository(),
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
        ];
    }
}
