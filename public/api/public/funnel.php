<?php

declare(strict_types=1);

/**
 * GET /api/public/funnel.php?slug=property-finder
 * GET /api/public/funnel.php?slug=…&preview=1   (authenticated admin only)
 *
 * Returns the PUBLISHED configuration only. Draft steps, inactive steps,
 * inactive options, option scores and internal metadata are never included.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Auth;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\SettingsRepository;
use Lumera\Services\FunnelService;
use Lumera\Support\Request;

App::bootApi(dirname(__DIR__, 3));

if (Request::method() !== 'GET') {
    Response::error('Method not allowed.', 405);
}

$slug    = isset($_GET['slug']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['slug'])) : '';
$preview = ($_GET['preview'] ?? '') === '1';

$funnels = new FunnelRepository();
$funnel  = $slug !== '' ? $funnels->findBySlug($slug) : $funnels->primary();

if ($funnel === null) {
    Response::error('This form is not available.', 404);
}

$service = new FunnelService();

if ($preview) {
    // Preview renders the DRAFT — so it must be an authenticated admin.
    if (!Auth::check()) {
        Response::error('Authentication required.', 401);
    }

    $snapshot = $service->buildSnapshot((int) $funnel['id'], true);

    if ($snapshot === []) {
        Response::error('This form is not available.', 404);
    }

    $config = $service->toPublicConfig($snapshot, true);

    // Marked as "draft" rather than a number so a preview can never be mistaken
    // for a published version, and so preview answers saved in sessionStorage
    // never collide with the live funnel's.
    $config['version'] = 'draft';
    $config['funnel']['version'] = 'draft';
} else {
    if ((string) $funnel['status'] !== 'active') {
        Response::error('This form is currently unavailable.', 503);
    }

    $snapshot = $service->publishedSnapshot((int) $funnel['id']);

    if ($snapshot === null || ($snapshot['steps'] ?? []) === []) {
        Response::error('This form is not available yet.', 503);
    }

    $config = $service->toPublicConfig($snapshot, false);
}

$settings = new SettingsRepository();
$public   = $settings->publicSettings();

$config['branding'] = [
    'company_name'       => $public['company_name'] ?? 'Lumera Dubai Real Estate',
    'company_logo'       => $public['company_logo'] ?? ($config['funnel']['theme']['logo'] ?? null),
    'privacy_policy_url' => $config['funnel']['privacy_policy_url'] ?? ($public['privacy_policy_url'] ?? null),
];

Response::success(['config' => $config]);
