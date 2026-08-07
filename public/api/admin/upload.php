<?php

declare(strict_types=1);

/**
 * POST /api/admin/upload.php  (multipart/form-data)
 * Fields: file, purpose = logo | background
 *
 * Uses the form-encoded CSRF field rather than JSON, because the body is a
 * multipart upload.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Response;
use Lumera\Services\UploadService;

[$admin, $body] = AdminEndpoint::write(dirname(__DIR__, 3), false);

$purpose = AdminEndpoint::stringParam($body, 'purpose', 'logo');

if (!in_array($purpose, ['logo', 'favicon', 'background', 'step'], true)) {
    Response::error('Unsupported upload purpose.', 400);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    Response::error('No file was uploaded.', 400);
}

$result = (new UploadService())->store($_FILES['file'], $purpose);

if (!$result['ok']) {
    Response::validationError(['file' => $result['error'] ?? 'Upload failed.'], $result['error'] ?? 'Upload failed.');
}

AuditLog::record(AuditLog::FILE_UPLOADED, 'upload', null, [
    'purpose' => $purpose,
    'path'    => $result['path'],
]);

Response::success(['path' => $result['path'], 'url' => $result['url']], 201);
