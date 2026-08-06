<?php

declare(strict_types=1);

/**
 * POST /api/admin/contact-fields.php
 *
 * Body: { action: update | toggle | reorder, … }
 *
 * Contact fields are never deleted. System keys (the ones the leads table and
 * the notification depend on) can be edited and, where optional, deactivated —
 * but the key itself is immutable, so existing leads never become unreadable.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Database;
use Lumera\Core\Response;
use Lumera\Repositories\ContactFieldRepository;
use Lumera\Repositories\FunnelRepository;
use Lumera\Support\Str;

$basePath = dirname(__DIR__, 3);

[$admin, $body] = AdminEndpoint::write($basePath);

$funnels = new FunnelRepository();
$fields  = new ContactFieldRepository();

$funnelId = AdminEndpoint::funnelId($body);
$funnel   = $funnels->find($funnelId);

if ($funnel === null) {
    Response::error('Funnel not found.', 404);
}

$requireField = static function (int $id) use ($fields, $funnelId): array {
    $field = $fields->find($id);

    if ($field === null || (int) $field['funnel_id'] !== $funnelId) {
        Response::error('Contact field not found.', 404);
    }

    return $field;
};

/** These two keys carry the phone number; the funnel cannot function without them. */
$undeactivatable = ['full_name', 'country_code', 'phone'];

switch (AdminEndpoint::stringParam($body, 'action')) {
    // ---------------------------------------------------------------- update --
    case 'update':
        $field = $requireField(AdminEndpoint::intParam($body, 'field_id'));
        $key   = (string) $field['field_key'];

        $errors = [];
        $data   = [];

        if (isset($body['label_en'])) {
            $label = Str::clean($body['label_en'], 190);

            if ($label === '') {
                $errors['label_en'] = 'An English label is required.';
            } else {
                $data['label_en'] = $label;
            }
        }

        foreach (['label_ar' => 190, 'placeholder_en' => 190, 'placeholder_ar' => 190] as $name => $max) {
            if (isset($body[$name])) {
                $data[$name] = Str::clean($body[$name], $max);
            }
        }

        if (array_key_exists('is_required', $body)) {
            $data['is_required'] = AdminEndpoint::boolParam($body, 'is_required') ? 1 : 0;
        }

        if (array_key_exists('is_active', $body)) {
            $active = AdminEndpoint::boolParam($body, 'is_active');

            if (!$active && in_array($key, $undeactivatable, true)) {
                $errors['is_active'] = 'This field is required by the funnel and cannot be hidden.';
            } else {
                $data['is_active'] = $active ? 1 : 0;
            }
        }

        foreach (['min_length', 'max_length'] as $name) {
            if (!array_key_exists($name, $body)) {
                continue;
            }

            $value = $body[$name];
            $data[$name] = ($value === '' || $value === null || !is_numeric($value))
                ? null
                : max(0, min(5000, (int) $value));
        }

        if (array_key_exists('validation_pattern', $body)) {
            $pattern = Str::clean($body['validation_pattern'], 255);

            if ($pattern !== '' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/u', '') === false) {
                $errors['validation_pattern'] = 'The validation pattern is not a valid regular expression.';
            } else {
                $data['validation_pattern'] = $pattern ?: null;
            }
        }

        // Choices are only meaningful for select fields.
        if (array_key_exists('choices', $body) && (string) $field['field_type'] === 'select') {
            $raw = is_array($body['choices']) ? $body['choices'] : json_decode((string) $body['choices'], true);

            if (!is_array($raw)) {
                $errors['choices'] = 'Choices must be a list.';
            } else {
                $clean = [];

                foreach (array_slice($raw, 0, 50) as $choice) {
                    if (!is_array($choice)) {
                        continue;
                    }

                    $value = Str::key((string) ($choice['value'] ?? ''));
                    $labelEn = Str::clean($choice['label_en'] ?? '', 190);

                    if ($value === '' || $labelEn === '') {
                        continue;
                    }

                    $clean[] = [
                        'value'    => $value,
                        'label_en' => $labelEn,
                        'label_ar' => Str::clean($choice['label_ar'] ?? '', 190),
                    ];
                }

                if ($clean === []) {
                    $errors['choices'] = 'Add at least one choice with an internal value and an English label.';
                } else {
                    $data['choices'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        if ($errors !== []) {
            Response::validationError($errors);
        }

        if ($data === []) {
            Response::error('Nothing to update.', 400);
        }

        Database::transaction(static function () use ($fields, $funnels, $funnelId, $field, $data) {
            $fields->update((int) $field['id'], $data);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::CONTACT_FIELD_UPDATED, 'funnel_contact_field', (int) $field['id'], [
            'field_key' => $key,
            'fields'    => array_keys($data),
        ]);

        Response::success(['field' => $fields->find((int) $field['id'])]);

    // ---------------------------------------------------------------- toggle --
    case 'toggle':
        $field  = $requireField(AdminEndpoint::intParam($body, 'field_id'));
        $active = AdminEndpoint::boolParam($body, 'is_active', !((bool) $field['is_active']));

        if (!$active && in_array((string) $field['field_key'], $undeactivatable, true)) {
            Response::validationError(['is_active' => 'This field is required by the funnel and cannot be hidden.']);
        }

        Database::transaction(static function () use ($fields, $funnels, $funnelId, $field, $active) {
            $fields->update((int) $field['id'], ['is_active' => $active ? 1 : 0]);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::CONTACT_FIELD_UPDATED, 'funnel_contact_field', (int) $field['id'], [
            'is_active' => $active,
        ]);

        Response::success(['field' => $fields->find((int) $field['id'])]);

    // --------------------------------------------------------------- reorder --
    case 'reorder':
        $ids = AdminEndpoint::idList($body['order'] ?? null, 50);

        if ($ids === null) {
            Response::error('A valid ordered list of field ids is required.', 422);
        }

        Database::transaction(static function () use ($fields, $funnels, $funnelId, $ids) {
            $fields->reorder($funnelId, $ids);
            $funnels->touchDraft($funnelId);
        });

        AuditLog::record(AuditLog::CONTACT_FIELD_UPDATED, 'funnel', $funnelId, ['reordered' => count($ids)]);

        Response::success(['fields' => $fields->forFunnel($funnelId, false)]);

    default:
        Response::error('Unknown action.', 400);
}
