<?php

declare(strict_types=1);

/**
 * POST /api/admin/publish.php
 *
 * Serialises the active draft into a new immutable version and points the
 * funnel at it — all inside one transaction, so the public funnel switches
 * atomically from the old version to the new one.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\Logger;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Services\PublishService;
use Lumera\Support\Str;

$basePath = dirname(__DIR__, 3);

[$admin, $body] = AdminEndpoint::write($basePath);

$funnels  = new FunnelRepository();
$funnelId = AdminEndpoint::funnelId($body);
$funnel   = $funnels->find($funnelId);

if ($funnel === null) {
    Response::error('Funnel not found.', 404);
}

$service  = new PublishService();
$blockers = $service->validateForPublish($funnelId);

if ($blockers !== []) {
    Response::json([
        'ok'       => false,
        'error'    => 'The funnel cannot be published yet.',
        'blockers' => $blockers,
    ], 422);
}

try {
    $result = $service->publish(
        $funnelId,
        (int) $admin['id'],
        Str::clean($body['notes'] ?? '', 255) ?: null
    );
} catch (Throwable $e) {
    Logger::error('publish.failed', ['funnel_id' => $funnelId, 'message' => $e->getMessage()]);
    Response::error('Publishing failed. No changes were made.', 500);
}

Response::success([
    'published' => $result,
    'status'    => $service->status($funnelId),
]);
