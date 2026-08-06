<?php

declare(strict_types=1);

/**
 * Admin draft preview.
 *
 * Renders the exact same template and JavaScript as the public funnel, with
 * preview mode enabled: the configuration comes from the DRAFT, submissions are
 * short-circuited in the client and no lead is ever created.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Auth;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Core\SubmissionToken;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\SettingsRepository;
use Lumera\Services\FunnelService;

App::boot(dirname(__DIR__, 2));
Response::securityHeaders();

Auth::requirePage('/admin/login.php');

$funnels = new FunnelRepository();
$slug    = isset($_GET['slug']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['slug'])) : '';
$funnel  = $slug !== '' ? $funnels->findBySlug($slug) : $funnels->primary();

if ($funnel === null) {
    http_response_code(404);
    exit('Funnel not found.');
}

$service  = new FunnelService();
$settings = new SettingsRepository();
$public   = $settings->publicSettings();

$view = [
    'funnel'          => $funnel,
    'slug'            => (string) $funnel['slug'],
    'name'            => (string) $funnel['name'],
    'companyName'     => $service->companyName($funnel),
    'logo'            => ($funnel['logo_path'] ?? '') ?: ($public['company_logo'] ?? ''),
    'favicon'         => (string) ($funnel['favicon_path'] ?? ''),
    'backgroundImage' => (string) ($funnel['background_image_path'] ?? ''),
    'theme'           => [
        'primary'    => (string) $funnel['primary_color'],
        'accent'     => (string) $funnel['accent_color'],
        'background' => (string) $funnel['background_color'],
    ],
    'languages'       => $service->languages($funnel),
    'defaultLanguage' => (string) $funnel['default_language'],
    'csrfToken'       => Csrf::token('public'),
    'submissionToken' => SubmissionToken::issue(),
    'preview'         => true,
];

// EXTR_OVERWRITE so the resolved funnel's slug wins over the requested one,
// which is empty when no ?slug= is supplied.
extract($view, EXTR_OVERWRITE);

require dirname(__DIR__, 2) . '/templates/public/funnel.php';
