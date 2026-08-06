<?php

declare(strict_types=1);

/**
 * POST /api/admin/options.php
 *
 * Body: { action: create | update | delete | duplicate | toggle | reorder | move, … }
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

$requireStep = static function (int $stepId) use ($steps, $funnelId): array {
    $step = $steps->find($stepId);

    if ($step === null || (int) $step['funnel_id'] !== $funnelId) {
        Response::error('Step not found.', 404);
    }

    return $step;
};

$requireOption = static function (int $optionId) use ($options, $requireStep): array {
    $option = $options->find($optionId);

    if ($option === null) {
        Response::error('Option not found.', 404);
    }

    $requireStep((int) $option['step_id']); // asserts funnel ownership

    return $option;
};

$action = AdminEndpoint::stringParam($body, 'action');

switch ($action) {
    // ---------------------------------------------------------------- create --
    case 'create':
        $step = $requireStep(AdminEndpoint::intParam($body, 'step_id'));
        $data = $validator->validateOption((int) $step['id'], $body);

        if ($data === null) {
            Response::validationError($validator->errors());
        }

        $optionId = Database::transaction(static function () use ($options, $funnels, $funnelId, $step, $data) {
            $id = $options->create((int) $step['id'], $data);
            $funnels->touchDraft($funnelId);

            return $id;
        });

        AuditLog::record(AuditLog::OPTION_CREATED, 'funnel_step_option', $optionId, [
            'step_id' => (int) $step['id'],
            'value'   => $data['option_value'],
        ]);

        Response::success(['option' => $options->find($optionId)], 201);

    // ---------------------------------------------------------------- update --
    case 'update':
        $option = $requireOption(AdminEndpoint::intParam($body, 'option_id'));
        $data   = $validator->validateOption((int) $option['step_id'], $body, (int) $option['id']);

        if ($data === null) {
            Response::validationError($validator->errors());
        }

        Database::transaction(static function () use ($options, $funnels, $funnelId, $option, $data) {
            $options->update((int) $option['id'], $data);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::OPTION_UPDATED, 'funnel_step_option', (int) $option['id'], [
            'value' => $data['option_value'],
        ]);

        Response::success(['option' => $options->find((int) $option['id'])]);

    // ---------------------------------------------------------------- toggle --
    case 'toggle':
        $option = $requireOption(AdminEndpoint::intParam($body, 'option_id'));
        $active = AdminEndpoint::boolParam($body, 'is_active', !((bool) $option['is_active']));

        Database::transaction(static function () use ($options, $funnels, $funnelId, $option, $active) {
            $options->update((int) $option['id'], ['is_active' => $active ? 1 : 0]);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::OPTION_UPDATED, 'funnel_step_option', (int) $option['id'], [
            'is_active' => $active,
        ]);

        Response::success(['option' => $options->find((int) $option['id'])]);

    // ------------------------------------------------------------- duplicate --
    case 'duplicate':
        $option = $requireOption(AdminEndpoint::intParam($body, 'option_id'));

        $newId = Database::transaction(static function () use ($options, $funnels, $funnelId, $option) {
            $stepId = (int) $option['step_id'];
            $base   = Str::key((string) $option['option_value'] . '_copy');
            $value  = $base;
            $n      = 1;

            while ($options->valueExists($stepId, $value)) {
                $n++;
                $value = Str::key($base . '_' . $n);
            }

            $id = $options->create($stepId, [
                'option_value' => $value,
                'label_en'     => $option['label_en'],
                'label_ar'     => $option['label_ar'],
                'icon'         => $option['icon'],
                'score'        => $option['score'],
                'is_active'    => $option['is_active'],
                'metadata'     => $option['metadata'],
            ]);

            $funnels->touchDraft($funnelId);

            return $id;
        });

        AuditLog::record(AuditLog::OPTION_CREATED, 'funnel_step_option', $newId, [
            'duplicated_from' => (int) $option['id'],
        ]);

        Response::success(['option' => $options->find($newId)], 201);

    // ---------------------------------------------------------------- delete --
    case 'delete':
        $option = $requireOption(AdminEndpoint::intParam($body, 'option_id'));

        Database::transaction(static function () use ($options, $funnels, $funnelId, $option) {
            $options->delete((int) $option['id']);
            $options->reorder(
                (int) $option['step_id'],
                array_map(
                    static fn ($o) => (int) $o['id'],
                    $options->forStep((int) $option['step_id'], false)
                )
            );
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::OPTION_DELETED, 'funnel_step_option', (int) $option['id'], [
            'value' => $option['option_value'],
        ]);

        Response::success(['deleted' => (int) $option['id']]);

    // --------------------------------------------------------------- reorder --
    case 'reorder':
        $step = $requireStep(AdminEndpoint::intParam($body, 'step_id'));
        $ids  = AdminEndpoint::idList($body['order'] ?? null);

        if ($ids === null) {
            Response::error('A valid ordered list of option ids is required.', 422);
        }

        Database::transaction(static function () use ($options, $funnels, $funnelId, $step, $ids) {
            $options->reorder((int) $step['id'], $ids);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::OPTION_REORDERED, 'funnel_step', (int) $step['id'], [
            'count' => count($ids),
        ]);

        Response::success(['options' => $options->forStep((int) $step['id'], false)]);

    // ------------------------------------------------------------------ move --
    case 'move':
        $option    = $requireOption(AdminEndpoint::intParam($body, 'option_id'));
        $direction = AdminEndpoint::stringParam($body, 'direction') === 'up' ? -1 : 1;

        $moved = Database::transaction(static function () use ($options, $funnels, $funnelId, $option, $direction) {
            $moved = $options->move((int) $option['step_id'], (int) $option['id'], $direction);

            if ($moved) {
                $funnels->touchDraft($funnelId);
            }

            return $moved;
        });

        if (!$moved) {
            Response::error('The option is already at the end of the list.', 409);
        }

        AuditLog::record(AuditLog::OPTION_REORDERED, 'funnel_step_option', (int) $option['id'], [
            'direction' => $direction < 0 ? 'up' : 'down',
        ]);

        Response::success(['options' => $options->forStep((int) $option['step_id'], false)]);

    default:
        Response::error('Unknown action.', 400);
}
