<?php

declare(strict_types=1);

/**
 * GET /api/admin/export.php?token=<csrf>&<same filters as leads.php>
 *
 * Streams a CSV of the currently filtered lead set. The CSRF token is required
 * even though this is a GET, because the response is a data export.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Services\ExportService;
use Lumera\Support\Str;

$admin = AdminEndpoint::read(dirname(__DIR__, 3));

if (!Csrf::validate(isset($_GET['token']) ? (string) $_GET['token'] : null, 'admin')) {
    Response::error('Your session has expired. Please refresh the page.', 419);
}

$filters = [
    'search'    => Str::clean($_GET['search'] ?? '', 100),
    'status'    => Str::clean($_GET['status'] ?? '', 20),
    'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) === 1 ? (string) $_GET['date_from'] : '',
    'date_to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) === 1 ? (string) $_GET['date_to'] : '',
    'source'    => Str::clean($_GET['source'] ?? '', 190),
    'campaign'  => Str::clean($_GET['campaign'] ?? '', 190),
    'budget'    => Str::clean($_GET['budget'] ?? '', 64),
    'purpose'   => Str::clean($_GET['purpose'] ?? '', 64),
    'include_deleted' => ($_GET['include_archived'] ?? '') === '1',
];

$exported = (new ExportService())->stream($filters, true);

AuditLog::record(AuditLog::CSV_EXPORTED, 'lead', null, [
    'rows'    => $exported,
    'filters' => array_filter($filters, static fn ($v) => $v !== '' && $v !== false),
]);

exit;
