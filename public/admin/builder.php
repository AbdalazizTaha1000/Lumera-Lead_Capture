<?php

declare(strict_types=1);

/**
 * Funnel Builder — the single editing surface for a funnel.
 *
 * Reached from Funnels → Edit. There is no separate "Builder" navigation item
 * and no funnel picker here: this page always edits one funnel, named in the
 * URL, which is what makes the workflow short.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Auth;
use Lumera\Core\Config;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\SettingsRepository;
use Lumera\Support\StepType;

App::boot(dirname(__DIR__, 2));
Response::securityHeaders();

$admin = Auth::requirePage('/admin/login.php');

$funnels = new FunnelRepository();

$requested = isset($_GET['funnel']) ? (int) $_GET['funnel'] : 0;
$funnel = $requested > 0 ? $funnels->find($requested) : $funnels->primary();

if ($funnel === null) {
    Response::redirect('/admin/#/funnels');
}

$settings = new SettingsRepository();

$view = [
    'funnel'  => $funnel,
    'admin'   => $admin,
    'csrf'    => Csrf::token('admin'),
    'company' => (string) $settings->get('company_name', 'Lead Capture'),
    'appUrl'  => Config::appUrl(),
    // The picker only ever offers types the backend can store and validate.
    'stepTypes' => StepType::LABELS,
];

extract($view, EXTR_OVERWRITE);

require dirname(__DIR__, 2) . '/templates/admin/builder.php';
