<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\AuditLog;
use Lumera\Core\Database;
use Lumera\Core\Logger;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\VersionRepository;
use Lumera\Support\StepType;
use RuntimeException;

/**
 * Draft -> published transition.
 *
 * The public funnel never reads the draft tables. Publishing serialises the
 * whole active draft into an immutable version row and flips
 * `funnels.published_version` inside one transaction, so a visitor either sees
 * the previous version in full or the new version in full — never a half-saved
 * or half-reordered state.
 */
final class PublishService
{
    public function __construct(
        private FunnelService $funnelService = new FunnelService(),
        private FunnelRepository $funnels = new FunnelRepository(),
        private VersionRepository $versions = new VersionRepository(),
    ) {
    }

    /**
     * @return array{version: int, published_at: string, steps: int}
     */
    public function publish(int $funnelId, ?int $adminUserId, ?string $notes = null): array
    {
        $problems = $this->validateForPublish($funnelId);

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        return Database::transaction(function () use ($funnelId, $adminUserId, $notes) {
            $snapshot = $this->funnelService->buildSnapshot($funnelId, true);

            if ($snapshot === []) {
                throw new RuntimeException('Funnel not found.');
            }

            $version = $this->versions->nextVersion($funnelId);
            $snapshot['version'] = $version;
            $snapshot['funnel']['version'] = $version;

            $this->versions->create($funnelId, $version, $snapshot, $adminUserId, $notes);

            Database::execute(
                'UPDATE `funnels` SET `published_version` = :v, `published_at` = NOW() WHERE `id` = :id',
                ['v' => $version, 'id' => $funnelId]
            );

            $publishedAt = (string) Database::scalar(
                'SELECT `published_at` FROM `funnels` WHERE `id` = :id',
                ['id' => $funnelId]
            );

            AuditLog::record(AuditLog::FUNNEL_PUBLISHED, 'funnel', $funnelId, [
                'version' => $version,
                'steps'   => count($snapshot['steps']),
            ], $adminUserId);

            Logger::info('funnel.published', ['funnel_id' => $funnelId, 'version' => $version]);

            return [
                'version'      => $version,
                'published_at' => $publishedAt,
                'steps'        => count($snapshot['steps']),
            ];
        });
    }

    /**
     * Pre-publish sanity checks — cheap guards against publishing a funnel
     * that cannot be completed by a visitor.
     *
     * @return list<string>
     */
    public function validateForPublish(int $funnelId): array
    {
        $problems = [];
        $draft    = $this->funnelService->buildSnapshot($funnelId, true);

        if ($draft === []) {
            return ['Funnel not found.'];
        }

        $steps = $draft['steps'] ?? [];

        if ($steps === []) {
            $problems[] = 'The funnel has no active steps.';
        }

        $answering = array_filter(
            $steps,
            static fn ($s) => !in_array($s['type'], StepType::NON_ANSWERING, true)
        );

        if ($answering === []) {
            $problems[] = 'The funnel needs at least one step that collects an answer.';
        }

        foreach ($steps as $step) {
            $label = $step['key'];

            if (trim((string) ($step['title']['en'] ?? '')) === '') {
                $problems[] = "Step \"{$label}\" is missing an English title.";
            }

            if (StepType::usesOptions($step['type']) && count($step['options'] ?? []) === 0) {
                $problems[] = "Step \"{$label}\" is a selection step with no active options.";
            }

            if ($step['type'] === StepType::CONTACT_INFORMATION && count($step['fields'] ?? []) === 0) {
                $problems[] = "Step \"{$label}\" has no active contact fields.";
            }
        }

        return $problems;
    }

    /**
     * Status block for the funnel builder header.
     *
     * @return array<string,mixed>
     */
    public function status(int $funnelId): array
    {
        $funnel = $this->funnels->find($funnelId);

        if ($funnel === null) {
            return [];
        }

        return [
            'published_version'  => (int) $funnel['published_version'],
            'published_at'       => $funnel['published_at'],
            'draft_updated_at'   => $funnel['draft_updated_at'],
            'has_unpublished'    => $this->funnels->hasUnpublishedChanges($funnel),
            'next_version'       => $this->versions->nextVersion($funnelId),
            'publish_blockers'   => $this->validateForPublish($funnelId),
            'history'            => $this->versions->history($funnelId, 5),
        ];
    }
}
