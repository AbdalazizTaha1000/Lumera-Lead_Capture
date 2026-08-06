<?php

declare(strict_types=1);

/**
 * GET  /api/admin/settings.php    safe application settings
 * POST /api/admin/settings.php    update safe application settings
 *
 * SMTP credentials, APP_SECRET, database credentials and server paths are NOT
 * managed here — they live in .env and are never returned by this endpoint.
 * The SMTP block below reports only whether delivery is configured.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Config;
use Lumera\Core\Response;
use Lumera\Mail\Mailer;
use Lumera\Repositories\SettingsRepository;
use Lumera\Support\Request;
use Lumera\Support\Str;

$basePath = dirname(__DIR__, 3);
$settings = new SettingsRepository();

if (Request::method() === 'GET') {
    AdminEndpoint::read($basePath);

    Response::success([
        'settings' => $settings->all(),
        'editable' => array_keys(SettingsRepository::EDITABLE),
        'environment' => [
            // Booleans and non-secret descriptors only.
            'app_env'          => Config::string('APP_ENV', 'production'),
            'app_url'          => Config::appUrl(),
            'timezone'         => Config::string('APP_TIMEZONE', 'Asia/Dubai'),
            'smtp_configured'  => (new Mailer())->isConfigured(),
            'lead_recipient_set' => Config::string('LEAD_RECIPIENT_EMAIL', '') !== '',
            'whatsapp_configured' => Config::string('WHATSAPP_NUMBER', '') !== '',
            'store_raw_ip'     => Config::bool('STORE_RAW_IP', false),
        ],
    ]);
}

[$admin, $body] = AdminEndpoint::write($basePath);

$input  = is_array($body['settings'] ?? null) ? $body['settings'] : $body;
$errors = [];
$saved  = [];

foreach ($input as $key => $value) {
    if (!isset(SettingsRepository::EDITABLE[(string) $key])) {
        continue; // silently ignore anything not on the allow-list
    }

    if (!is_scalar($value) && $value !== null) {
        $errors[(string) $key] = 'Unsupported value.';
        continue;
    }

    $value = (string) $value;

    switch ($key) {
        case 'company_name':
            $value = Str::clean($value, 190);

            if ($value === '') {
                $errors['company_name'] = 'A company name is required.';
                continue 2;
            }
            break;

        case 'privacy_policy_url':
            if ($value !== '') {
                $url = Str::safeUrl($value);

                if ($url === null) {
                    $errors['privacy_policy_url'] = 'Enter a valid http(s) URL.';
                    continue 2;
                }

                $value = $url;
            }
            break;

        case 'company_logo':
            if ($value !== '' && preg_match('#^/assets/uploads/[A-Za-z0-9._-]+$#', $value) !== 1) {
                $errors['company_logo'] = 'Upload the logo first, then save.';
                continue 2;
            }
            break;

        case 'admin_interface_language':
            if (!in_array($value, ['en', 'ar'], true)) {
                $errors['admin_interface_language'] = 'Choose English or Arabic.';
                continue 2;
            }
            break;

        case 'timezone':
            if (!in_array($value, timezone_identifiers_list(), true)) {
                $errors['timezone'] = 'Choose a valid timezone.';
                continue 2;
            }
            break;

        case 'notification_subject_template':
            $value = Str::clean($value, 190);

            if ($value === '') {
                $errors['notification_subject_template'] = 'A subject template is required.';
                continue 2;
            }
            break;
    }

    if ($settings->set((string) $key, $value)) {
        $saved[] = (string) $key;
    }
}

if ($errors !== []) {
    Response::validationError($errors);
}

if ($saved === []) {
    Response::error('Nothing to update.', 400);
}

AuditLog::record(AuditLog::SETTINGS_UPDATED, 'app_settings', null, ['keys' => $saved]);

Response::success(['settings' => $settings->all(), 'saved' => $saved]);
