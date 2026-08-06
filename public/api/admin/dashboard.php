<?php

declare(strict_types=1);

/** GET /api/admin/dashboard.php */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Response;
use Lumera\Services\DashboardService;

AdminEndpoint::read(dirname(__DIR__, 3));

$overview = (new DashboardService())->overview();
$overview['activity'] = AuditLog::recent(8);

Response::success($overview);
