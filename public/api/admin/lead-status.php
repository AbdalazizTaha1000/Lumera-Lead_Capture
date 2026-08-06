<?php

declare(strict_types=1);

/**
 * POST /api/admin/lead-status.php
 * Body: { lead_id, status }            change status
 *       { lead_id, action: archive }   soft delete
 *       { lead_id, action: restore }   undo soft delete
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Response;
use Lumera\Repositories\LeadRepository;

[$admin, $body] = AdminEndpoint::write(dirname(__DIR__, 3));

$repo   = new LeadRepository();
$leadId = AdminEndpoint::intParam($body, 'lead_id');
$lead   = $repo->find($leadId, true);

if ($lead === null) {
    Response::error('Lead not found.', 404);
}

$action = AdminEndpoint::stringParam($body, 'action');

if ($action === 'archive') {
    $repo->softDelete($leadId);
    $repo->updateStatus($leadId, 'archived');
    AuditLog::record(AuditLog::LEAD_ARCHIVED, 'lead', $leadId);

    Response::success(['lead' => $repo->find($leadId, true)]);
}

if ($action === 'restore') {
    $repo->restore($leadId);
    $repo->updateStatus($leadId, 'new');
    AuditLog::record(AuditLog::LEAD_STATUS_CHANGED, 'lead', $leadId, ['restored' => true]);

    Response::success(['lead' => $repo->find($leadId, true)]);
}

$status = AdminEndpoint::stringParam($body, 'status');

if (!in_array($status, LeadRepository::STATUSES, true)) {
    Response::validationError(['status' => 'Unsupported lead status.']);
}

$repo->updateStatus($leadId, $status);

if ($status === 'archived') {
    $repo->softDelete($leadId);
} elseif (!empty($lead['deleted_at'])) {
    $repo->restore($leadId);
}

AuditLog::record(AuditLog::LEAD_STATUS_CHANGED, 'lead', $leadId, [
    'from' => $lead['status'],
    'to'   => $status,
]);

Response::success(['lead' => $repo->find($leadId, true)]);
