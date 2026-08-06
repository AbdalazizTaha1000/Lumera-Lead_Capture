<?php

declare(strict_types=1);

/**
 * Public funnel entry point.
 *
 * This file renders a shell only. Every question, label, option, validation
 * rule and language comes from the published configuration fetched at runtime
 * from /api/public/funnel.php — there are no hardcoded steps here.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Core\Router;
use Lumera\Core\SubmissionToken;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\SettingsRepository;
use Lumera\Services\FunnelService;

App::boot(dirname(__DIR__));
Response::securityHeaders(false);

$funnels = new FunnelRepository();
$slug    = Router::funnelSlug();

// Archived and soft-deleted funnels are invisible to the public router.
$funnel = $slug !== null ? $funnels->findPublicBySlug($slug) : $funnels->primaryPublic();

$service  = new FunnelService();
$settings = new SettingsRepository();
$public   = $settings->publicSettings();

if ($funnel === null) {
    http_response_code(404);

    require dirname(__DIR__) . '/templates/public/not-found.php';
    exit;
}

$languages       = $service->languages($funnel);
$defaultLanguage = (string) $funnel['default_language'];

$theme = [
    'primary'    => (string) $funnel['primary_color'],
    'accent'     => (string) $funnel['accent_color'],
    'background' => (string) $funnel['background_color'],
];

// Branding is per funnel; the global setting is only a fallback, so a single
// installation can serve any number of companies.
$logo = ($funnel['logo_path'] ?? '') ?: ($public['company_logo'] ?? '');
$backgroundImage = $funnel['background_image_path'] ?? '';

$view = [
    'funnel'          => $funnel,
    'slug'            => (string) $funnel['slug'],
    'name'            => (string) $funnel['name'],
    'companyName'     => $service->companyName($funnel),
    'logo'            => $logo,
    'favicon'         => ($funnel['favicon_path'] ?? '') ?: '',
    'backgroundImage' => $backgroundImage,
    'theme'           => $theme,
    'languages'       => $languages,
    'defaultLanguage' => $defaultLanguage,
    'csrfToken'       => Csrf::token('public'),
    'submissionToken' => SubmissionToken::issue(),
    'preview'         => false,
];

// EXTR_OVERWRITE, not EXTR_SKIP: $slug already holds the *requested* slug,
// which is null on the root URL. The template must receive the resolved
// funnel's slug so the page always names the funnel it is rendering.
extract($view, EXTR_OVERWRITE);

require dirname(__DIR__) . '/templates/public/funnel.php';
