<?php

declare(strict_types=1);

/**
 * GET  /api/admin/funnel.php[?funnel_id=1]   full draft configuration
 * POST /api/admin/funnel.php                 update funnel settings
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Database;
use Lumera\Core\Response;
use Lumera\Repositories\ContactFieldRepository;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\OptionRepository;
use Lumera\Repositories\StepRepository;
use Lumera\Services\PublishService;
use Lumera\Services\UploadService;
use Lumera\Services\WebhookService;
use Lumera\Support\Request;
use Lumera\Support\StepType;
use Lumera\Support\Str;

$basePath = dirname(__DIR__, 3);

$funnels = new FunnelRepository();
$steps   = new StepRepository();
$options = new OptionRepository();
$fields  = new ContactFieldRepository();
$publish = new PublishService();

if (Request::method() === 'GET') {
    AdminEndpoint::read($basePath);

    $funnelId = AdminEndpoint::funnelId($_GET);
    $funnel   = $funnels->find($funnelId);

    if ($funnel === null) {
        Response::error('Funnel not found.', 404);
    }

    $draftSteps = $steps->allForFunnel($funnelId, false);
    $stepIds    = array_map(static fn ($s) => (int) $s['id'], $draftSteps);
    $optionMap  = $options->forSteps($stepIds, false);

    foreach ($draftSteps as &$step) {
        $step['options']    = $optionMap[(int) $step['id']] ?? [];
        $step['type_label'] = StepType::label((string) $step['step_type']);
        $step['uses_options'] = StepType::usesOptions((string) $step['step_type']);
    }
    unset($step);

    // The builder needs the funnel list so the administrator can switch between
    // funnels without leaving the screen.
    $switcher = array_map(
        static fn ($f) => [
            'id'          => (int) $f['id'],
            'name'        => $f['name'],
            'slug'        => $f['slug'],
            'status'      => $f['status'],
            'is_archived' => $f['archived_at'] !== null,
        ],
        $funnels->all(true)
    );

    $uploads = new UploadService();

    Response::success([
        'funnel'         => $funnel,
        'steps'          => $draftSteps,
        'contact_fields' => $fields->forFunnel($funnelId, false),
        'status'         => $publish->status($funnelId),
        'funnels'        => $switcher,
        'meta'           => [
            'public_url'          => rtrim(\Lumera\Core\Config::appUrl(), '/') . '/' . $funnel['slug'],
            'upload_max_mb'       => (int) ($uploads->maxBytes() / 1024 / 1024),
            'logo_formats'        => $uploads->allowedExtensions('logo'),
            'favicon_formats'     => $uploads->allowedExtensions('favicon'),
            'background_formats'  => $uploads->allowedExtensions('background'),
            'step_types'          => StepType::LABELS,
            'types_with_options'  => StepType::WITH_OPTIONS,
            'auto_advance_types'  => array_values(array_filter(
                StepType::all(),
                static fn ($t) => StepType::supportsAutoAdvance($t)
            )),
            'system_contact_keys' => ContactFieldRepository::SYSTEM_KEYS,
            'condition_operators' => ['equals', 'not_equals', 'contains'],
        ],
    ]);
}

// ------------------------------------------------------------------ update --
[$admin, $body] = AdminEndpoint::write($basePath);

$funnelId = AdminEndpoint::funnelId($body);
$funnel   = $funnels->find($funnelId);

if ($funnel === null) {
    Response::error('Funnel not found.', 404);
}

$errors = [];
$data   = [];

if (isset($body['name'])) {
    $name = Str::clean($body['name'], 190);

    if ($name === '') {
        $errors['name'] = 'A funnel name is required.';
    } else {
        $data['name'] = $name;
    }
}

if (isset($body['company_name'])) {
    $company = Str::clean($body['company_name'], 190);

    if ($company === '') {
        $errors['company_name'] = 'A company name is required — it is shown on the public funnel.';
    } else {
        $data['company_name'] = $company;
    }
}

// The slug is the public URL, so uniqueness and reserved paths are enforced.
if (isset($body['slug'])) {
    $slug = Str::slug((string) $body['slug']);

    if ($slug === '') {
        $errors['slug'] = 'A URL slug is required.';
    } elseif (in_array($slug, FunnelRepository::RESERVED_SLUGS, true)) {
        $errors['slug'] = 'That slug is reserved by the application.';
    } elseif ($funnels->slugExists($slug, $funnelId)) {
        $errors['slug'] = 'Another funnel already uses that slug.';
    } else {
        $data['slug'] = $slug;
    }
}

if (isset($body['status'])) {
    $status = (string) $body['status'];

    if (!in_array($status, ['active', 'paused', 'draft'], true)) {
        $errors['status'] = 'Unsupported funnel status.';
    } else {
        $data['status'] = $status;
    }
}

if (isset($body['enabled_languages'])) {
    $raw  = is_array($body['enabled_languages']) ? $body['enabled_languages'] : explode(',', (string) $body['enabled_languages']);
    $list = array_values(array_filter(array_map(
        static fn ($l) => preg_replace('/[^a-z]/', '', strtolower(trim((string) $l))),
        $raw
    ), static fn ($l) => in_array($l, ['en', 'ar'], true)));

    if ($list === []) {
        $errors['enabled_languages'] = 'At least one language must be enabled.';
    } else {
        $data['enabled_languages'] = implode(',', array_unique($list));
    }
}

if (isset($body['default_language'])) {
    $default = preg_replace('/[^a-z]/', '', strtolower((string) $body['default_language'])) ?? 'en';
    $enabled = explode(',', $data['enabled_languages'] ?? (string) $funnel['enabled_languages']);

    if (!in_array($default, $enabled, true)) {
        $errors['default_language'] = 'The default language must also be enabled.';
    } else {
        $data['default_language'] = $default;
    }
}

foreach (['primary_color', 'accent_color', 'background_color'] as $colorField) {
    if (!isset($body[$colorField])) {
        continue;
    }

    $color = trim((string) $body[$colorField]);

    if (!Str::isHexColor($color)) {
        $errors[$colorField] = 'Use a hex colour such as #0F2E4C.';
    } else {
        $data[$colorField] = $color;
    }
}

foreach ([
    'submit_label_en' => 120, 'submit_label_ar' => 120,
    'success_title_en' => 190, 'success_title_ar' => 190,
    'success_button_en' => 120, 'success_button_ar' => 120,
    'whatsapp_label_en' => 120, 'whatsapp_label_ar' => 120,
] as $field => $max) {
    if (isset($body[$field])) {
        $data[$field] = Str::clean($body[$field], $max);
    }
}

// ---------------------------------------------------- per-funnel delivery --
if (array_key_exists('recipient_email', $body)) {
    $raw = Str::clean($body['recipient_email'], 500);

    if ($raw === '') {
        // Empty means "fall back to LEAD_RECIPIENT_EMAIL from .env".
        $data['recipient_email'] = null;
    } else {
        $addresses = array_filter(array_map('trim', explode(',', $raw)));
        $invalid   = array_filter(
            $addresses,
            static fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL) === false
        );

        if ($addresses === [] || $invalid !== []) {
            $errors['recipient_email'] = 'Enter one or more valid email addresses, separated by commas.';
        } else {
            $data['recipient_email'] = implode(',', $addresses);
        }
    }
}

if (array_key_exists('redirect_url', $body)) {
    $raw = trim((string) $body['redirect_url']);

    if ($raw === '') {
        $data['redirect_url'] = null;
    } else {
        $url = Str::safeUrl($raw);

        if ($url === null) {
            $errors['redirect_url'] = 'Enter a valid http(s) URL, or leave it empty.';
        } else {
            $data['redirect_url'] = $url;
        }
    }
}

if (isset($body['redirect_delay'])) {
    $data['redirect_delay'] = max(0, min(60, (int) $body['redirect_delay']));
}

$webhookEnabled = array_key_exists('webhook_enabled', $body)
    ? AdminEndpoint::boolParam($body, 'webhook_enabled')
    : (int) $funnel['webhook_enabled'] === 1;

if (array_key_exists('webhook_url', $body)) {
    $raw = trim((string) $body['webhook_url']);

    if ($raw === '') {
        $data['webhook_url'] = null;

        if ($webhookEnabled) {
            $errors['webhook_url'] = 'A webhook URL is required when the webhook is enabled.';
        }
    } else {
        $reason = (new WebhookService())->rejectionReason($raw);

        if ($reason !== null) {
            $errors['webhook_url'] = $reason;
        } else {
            $data['webhook_url'] = mb_substr($raw, 0, 500);
        }
    }
} elseif ($webhookEnabled && (string) ($funnel['webhook_url'] ?? '') === '') {
    $errors['webhook_enabled'] = 'Add a webhook URL before enabling the webhook.';
}

if (array_key_exists('webhook_enabled', $body)) {
    $data['webhook_enabled'] = $webhookEnabled ? 1 : 0;
}

foreach (['success_message_en', 'success_message_ar'] as $field) {
    if (isset($body[$field])) {
        $data[$field] = Str::cleanMultiline($body[$field], 2000);
    }
}

if (array_key_exists('privacy_policy_url', $body)) {
    $url = Str::safeUrl($body['privacy_policy_url']);

    if ($body['privacy_policy_url'] !== '' && $url === null) {
        $errors['privacy_policy_url'] = 'Enter a valid http(s) URL.';
    } else {
        $data['privacy_policy_url'] = $url;
    }
}

foreach ([
    'whatsapp_enabled', 'progress_bar_enabled', 'step_counter_enabled',
    'back_button_enabled', 'save_progress_enabled',
] as $flag) {
    if (array_key_exists($flag, $body)) {
        $data[$flag] = AdminEndpoint::boolParam($body, $flag) ? 1 : 0;
    }
}

if (isset($body['min_completion_seconds'])) {
    $data['min_completion_seconds'] = max(0, min(600, (int) $body['min_completion_seconds']));
}

foreach (['logo_path', 'favicon_path', 'background_image_path'] as $assetField) {
    if (!array_key_exists($assetField, $body)) {
        continue;
    }

    $path = trim((string) $body[$assetField]);

    if ($path === '') {
        $data[$assetField] = null;
    } elseif (preg_match('#^/assets/uploads/[A-Za-z0-9._-]+$#', $path) === 1) {
        $data[$assetField] = $path;
    } else {
        $errors[$assetField] = 'Upload the image first, then save.';
    }
}

if ($errors !== []) {
    Response::validationError($errors);
}

if ($data === []) {
    Response::error('Nothing to update.', 400);
}

Database::transaction(static function () use ($funnels, $funnelId, $data) {
    $funnels->update($funnelId, $data);
});

AuditLog::record(AuditLog::FUNNEL_UPDATED, 'funnel', $funnelId, ['fields' => array_keys($data)]);

Response::success([
    'funnel' => $funnels->find($funnelId),
    'status' => $publish->status($funnelId),
]);
