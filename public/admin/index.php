<?php

declare(strict_types=1);

/** Admin dashboard shell. All data is loaded through the authenticated API. */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Auth;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\SettingsRepository;

App::boot(dirname(__DIR__, 2));
Response::securityHeaders();

$admin = Auth::requirePage('/admin/login.php');

$settings = new SettingsRepository();
$funnel   = (new FunnelRepository())->primary();

$view = [
    'admin'    => $admin,
    'csrf'     => Csrf::token('admin'),
    'company'  => (string) $settings->get('company_name', 'Lead Capture'),
    'logo'     => (string) $settings->get('company_logo', ''),
    'tagline'  => (string) $settings->get('site_tagline', ''),
    'funnelId' => $funnel !== null ? (int) $funnel['id'] : 0,
    'funnelSlug' => $funnel !== null ? (string) $funnel['slug'] : '',
    'timezones' => timezone_identifiers_list(),
];

extract($view, EXTR_SKIP);

require dirname(__DIR__, 2) . '/templates/admin/app.php';
