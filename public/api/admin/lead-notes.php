<?php

declare(strict_types=1);

/**
 * POST /api/admin/lead-notes.php
 * Body: { lead_id, note }
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Response;
use Lumera\Repositories\LeadRepository;
use Lumera\Support\Str;

[$admin, $body] = AdminEndpoint::write(dirname(__DIR__, 3));

$repo   = new LeadRepository();
$leadId = AdminEndpoint::intParam($body, 'lead_id');
$lead   = $repo->find($leadId, true);

if ($lead === null) {
    Response::error('Lead not found.', 404);
}

$note = Str::cleanMultiline($body['note'] ?? '', 5000);

if ($note === '') {
    Response::validationError(['note' => 'The note cannot be empty.']);
}

$noteId = $repo->addNote($leadId, (int) $admin['id'], $note);

AuditLog::record(AuditLog::LEAD_NOTE_ADDED, 'lead', $leadId, ['note_id' => $noteId]);

Response::success(['notes' => $repo->notes($leadId)], 201);
