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
$funnel  = $slug !== null ? $funnels->findBySlug($slug) : $funnels->primary();

if ($funnel === null) {
    http_response_code(404);
    $funnel = null;
}

$service  = new FunnelService();
$settings = new SettingsRepository();
$public   = $settings->publicSettings();

$languages       = $funnel !== null ? $service->languages($funnel) : ['en'];
$defaultLanguage = $funnel !== null ? (string) $funnel['default_language'] : 'en';

$theme = [
    'primary'    => $funnel['primary_color'] ?? '#0F2E4C',
    'accent'     => $funnel['accent_color'] ?? '#C9A227',
    'background' => $funnel['background_color'] ?? '#F7F8FA',
];

$logo = $public['company_logo'] ?? ($funnel['logo_path'] ?? '');
$backgroundImage = $funnel['background_image_path'] ?? '';

$view = [
    'funnel'          => $funnel,
    'slug'            => $funnel['slug'] ?? '',
    'name'            => $funnel['name'] ?? 'Lumera',
    'companyName'     => $public['company_name'] ?? 'Lumera Dubai Real Estate',
    'logo'            => $logo,
    'backgroundImage' => $backgroundImage,
    'theme'           => $theme,
    'languages'       => $languages,
    'defaultLanguage' => $defaultLanguage,
    'csrfToken'       => Csrf::token('public'),
    'submissionToken' => SubmissionToken::issue(),
    'preview'         => false,
];

extract($view, EXTR_SKIP);

require dirname(__DIR__) . '/templates/public/funnel.php';
