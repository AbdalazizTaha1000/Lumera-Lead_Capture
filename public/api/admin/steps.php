<?php

declare(strict_types=1);

/**
 * POST /api/admin/steps.php
 *
 * Body: { action: create | update | delete | duplicate | toggle | reorder | move, … }
 * Every branch is authenticated, CSRF-checked, validated server side and
 * audit-logged. Multi-row operations run inside a transaction.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Database;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\OptionRepository;
use Lumera\Repositories\StepRepository;
use Lumera\Support\Str;
use Lumera\Validators\StepValidator;

$basePath = dirname(__DIR__, 3);

[$admin, $body] = AdminEndpoint::write($basePath);

$funnels   = new FunnelRepository();
$steps     = new StepRepository();
$options   = new OptionRepository();
$validator = new StepValidator();

$funnelId = AdminEndpoint::funnelId($body);
$funnel   = $funnels->find($funnelId);

if ($funnel === null) {
    Response::error('Funnel not found.', 404);
}

$action = AdminEndpoint::stringParam($body, 'action');

/** Loads a step and asserts it belongs to this funnel. */
$requireStep = static function (int $stepId) use ($steps, $funnelId): array {
    $step = $steps->find($stepId);

    if ($step === null || (int) $step['funnel_id'] !== $funnelId) {
        Response::error('Step not found.', 404);
    }

    return $step;
};

switch ($action) {
    // ---------------------------------------------------------------- create --
    case 'create':
        $data = $validator->validateStep($funnelId, $body);

        if ($data === null) {
            Response::validationError($validator->errors());
        }

        $stepId = Database::transaction(static function () use ($steps, $funnels, $funnelId, $data) {
            $id = $steps->create($funnelId, $data);
            $funnels->touchDraft($funnelId);

            return $id;
        });

        AuditLog::record(AuditLog::STEP_CREATED, 'funnel_step', $stepId, [
            'step_key' => $data['step_key'],
            'type'     => $data['step_type'],
        ]);

        Response::success(['step' => $steps->find($stepId)], 201);
        // no break — Response exits

    // ---------------------------------------------------------------- update --
    case 'update':
        $step = $requireStep(AdminEndpoint::intParam($body, 'step_id'));
        $data = $validator->validateStep($funnelId, $body, (int) $step['id']);

        if ($data === null) {
            Response::validationError($validator->errors());
        }

        // Switching away from an option-based type leaves the options intact
        // but unused; switching to one keeps whatever already exists.
        Database::transaction(static function () use ($steps, $funnels, $funnelId, $step, $data) {
            $steps->update((int) $step['id'], $data);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::STEP_UPDATED, 'funnel_step', (int) $step['id'], [
            'step_key' => $data['step_key'],
        ]);

        Response::success(['step' => $steps->find((int) $step['id'])]);

    // ---------------------------------------------------------------- toggle --
    case 'toggle':
        $step   = $requireStep(AdminEndpoint::intParam($body, 'step_id'));
        $active = AdminEndpoint::boolParam($body, 'is_active', !((bool) $step['is_active']));

        Database::transaction(static function () use ($steps, $funnels, $funnelId, $step, $active) {
            $steps->update((int) $step['id'], ['is_active' => $active ? 1 : 0]);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::STEP_UPDATED, 'funnel_step', (int) $step['id'], [
            'is_active' => $active,
        ]);

        Response::success(['step' => $steps->find((int) $step['id'])]);

    // ------------------------------------------------------------- duplicate --
    case 'duplicate':
        $step = $requireStep(AdminEndpoint::intParam($body, 'step_id'));

        $newId = Database::transaction(static function () use ($steps, $options, $funnels, $funnelId, $step) {
            // Find a free internal key: <key>_copy, _copy_2, …
            $base = Str::key((string) $step['step_key'] . '_copy');
            $key  = $base;
            $n    = 1;

            while ($steps->keyExists($funnelId, $key)) {
                $n++;
                $key = Str::key($base . '_' . $n);
            }

            $data = [
                'step_key'   => $key,
                'step_type'  => $step['step_type'],
                'title_en'   => $step['title_en'],
                'title_ar'   => $step['title_ar'],
                'description_en' => $step['description_en'],
                'description_ar' => $step['description_ar'],
                'placeholder_en' => $step['placeholder_en'],
                'placeholder_ar' => $step['placeholder_ar'],
                'is_required'    => $step['is_required'],
                'is_active'      => 0, // a copy starts inactive so it cannot go live by accident
                'auto_advance'   => $step['auto_advance'],
                'min_length'     => $step['min_length'],
                'max_length'     => $step['max_length'],
                'min_value'      => $step['min_value'],
                'max_value'      => $step['max_value'],
                'validation_pattern'    => $step['validation_pattern'],
                'validation_message_en' => $step['validation_message_en'],
                'validation_message_ar' => $step['validation_message_ar'],
                'condition_parent_key'  => $step['condition_parent_key'],
                'condition_operator'    => $step['condition_operator'],
                'condition_value'       => $step['condition_value'],
                'sort_order'            => (int) $step['sort_order'] + 1,
            ];

            $newId = $steps->create($funnelId, $data);

            foreach ($options->forStep((int) $step['id'], false) as $option) {
                $options->create($newId, [
                    'option_value' => $option['option_value'],
                    'label_en'     => $option['label_en'],
                    'label_ar'     => $option['label_ar'],
                    'icon'         => $option['icon'],
                    'score'        => $option['score'],
                    'is_active'    => $option['is_active'],
                    'metadata'     => $option['metadata'],
                    'sort_order'   => $option['sort_order'],
                ]);
            }

            $steps->resequence($funnelId);
            $funnels->touchDraft($funnelId);

            return $newId;
        });

        AuditLog::record(AuditLog::STEP_DUPLICATED, 'funnel_step', $newId, [
            'source_step_id' => (int) $step['id'],
        ]);

        Response::success(['step' => $steps->find($newId)], 201);

    // ---------------------------------------------------------------- delete --
    case 'delete':
        $step = $requireStep(AdminEndpoint::intParam($body, 'step_id'));

        Database::transaction(static function () use ($steps, $funnels, $funnelId, $step) {
            // Options cascade; lead_answers keep their label snapshots and
            // have step_id set to NULL, so historic leads stay readable.
            $steps->delete((int) $step['id']);
            $steps->resequence($funnelId);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::STEP_DELETED, 'funnel_step', (int) $step['id'], [
            'step_key' => $step['step_key'],
        ]);

        Response::success(['deleted' => (int) $step['id']]);

    // --------------------------------------------------------------- reorder --
    case 'reorder':
        $ids = AdminEndpoint::idList($body['order'] ?? null);

        if ($ids === null) {
            Response::error('A valid ordered list of step ids is required.', 422);
        }

        $applied = Database::transaction(static function () use ($steps, $funnels, $funnelId, $ids) {
            $applied = $steps->reorder($funnelId, $ids);
            $steps->resequence($funnelId);
            $funnels->touchDraft($funnelId);

            return $applied;
        });

        AuditLog::record(AuditLog::STEP_REORDERED, 'funnel', $funnelId, ['count' => $applied]);

        Response::success(['steps' => $steps->allForFunnel($funnelId, false)]);

    // ------------------------------------------------------------------ move --
    case 'move':
        $step      = $requireStep(AdminEndpoint::intParam($body, 'step_id'));
        $direction = AdminEndpoint::stringParam($body, 'direction') === 'up' ? -1 : 1;

        $moved = Database::transaction(static function () use ($steps, $funnels, $funnelId, $step, $direction) {
            $moved = $steps->move($funnelId, (int) $step['id'], $direction);

            if ($moved) {
                $funnels->touchDraft($funnelId);
            }

            return $moved;
        });

        if (!$moved) {
            Response::error('The step is already at the end of the list.', 409);
        }

        AuditLog::record(AuditLog::STEP_REORDERED, 'funnel_step', (int) $step['id'], [
            'direction' => $direction < 0 ? 'up' : 'down',
        ]);

        Response::success(['steps' => $steps->allForFunnel($funnelId, false)]);

    default:
        Response::error('Unknown action.', 400);
}
