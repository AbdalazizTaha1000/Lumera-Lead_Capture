<?php

declare(strict_types=1);

/**
 * GET  /api/admin/funnels.php[?include_archived=1]   list every funnel
 * POST /api/admin/funnels.php                        create | duplicate |
 *                                                    archive | restore | delete
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\AuditLog;
use Lumera\Core\Config;
use Lumera\Core\Logger;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Services\FunnelManager;
use Lumera\Support\Request;
use Lumera\Support\Str;

$basePath = dirname(__DIR__, 3);

$funnels = new FunnelRepository();
$manager = new FunnelManager();

// ------------------------------------------------------------------- list --
if (Request::method() === 'GET') {
    AdminEndpoint::read($basePath);

    $includeArchived = ($_GET['include_archived'] ?? '') === '1';
    $rows = $funnels->listWithStats($includeArchived);

    $appUrl = Config::appUrl();
    $list   = [];

    foreach ($rows as $funnel) {
        $list[] = [
            'id'                => (int) $funnel['id'],
            'name'              => $funnel['name'],
            'company_name'      => $funnel['company_name'],
            'slug'              => $funnel['slug'],
            'status'            => $funnel['status'],
            'logo_path'         => $funnel['logo_path'],
            'favicon_path'      => $funnel['favicon_path'],
            'primary_color'     => $funnel['primary_color'],
            'accent_color'      => $funnel['accent_color'],
            'leads_count'       => (int) $funnel['leads_count'],
            'active_steps'      => (int) $funnel['active_steps'],
            'published_version' => (int) $funnel['published_version'],
            'published_at'      => $funnel['published_at'],
            'archived_at'       => $funnel['archived_at'],
            'is_archived'       => $funnel['archived_at'] !== null,
            'webhook_enabled'   => (int) $funnel['webhook_enabled'] === 1,
            'public_url'        => ($appUrl !== '' ? $appUrl : '') . '/' . $funnel['slug'],
            'created_at'        => $funnel['created_at'],
        ];
    }

    Response::success([
        'funnels'  => $list,
        'archived' => count(array_filter($list, static fn ($f) => $f['is_archived'])),
        'reserved_slugs' => FunnelRepository::RESERVED_SLUGS,
    ]);
}

// ---------------------------------------------------------------- actions --
[$admin, $body] = AdminEndpoint::write($basePath);

$action = AdminEndpoint::stringParam($body, 'action');

/** Loads a funnel by id or fails the request. */
$requireFunnel = static function (int $id) use ($funnels): array {
    $funnel = $funnels->find($id);

    if ($funnel === null) {
        Response::error('Funnel not found.', 404);
    }

    return $funnel;
};

switch ($action) {
    // ---------------------------------------------------------------- create --
    case 'create':
        $name = Str::clean($body['name'] ?? '', 190);

        if ($name === '') {
            Response::validationError(['name' => 'A funnel name is required.']);
        }

        $requestedSlug = Str::slug((string) ($body['slug'] ?? '')) ?: Str::slug($name);

        if ($requestedSlug === '') {
            Response::validationError(['slug' => 'A URL slug is required.']);
        }

        if (in_array($requestedSlug, FunnelRepository::RESERVED_SLUGS, true)) {
            Response::validationError(['slug' => 'That slug is reserved by the application.']);
        }

        if ($funnels->slugExists($requestedSlug)) {
            Response::validationError(['slug' => 'Another funnel already uses that slug.']);
        }

        $company = Str::clean($body['company_name'] ?? '', 190);

        $data = [
            'slug'         => $requestedSlug,
            'name'         => $name,
            'company_name' => $company !== '' ? $company : $name,
            'status'       => 'draft',
        ];

        foreach (['primary_color', 'accent_color', 'background_color'] as $colorField) {
            if (!isset($body[$colorField])) {
                continue;
            }

            $color = trim((string) $body[$colorField]);

            if (!Str::isHexColor($color)) {
                Response::validationError([$colorField => 'Use a hex colour such as #0F2E4C.']);
            }

            $data[$colorField] = $color;
        }

        $funnelId = $manager->create($data);

        AuditLog::record(AuditLog::FUNNEL_CREATED, 'funnel', $funnelId, [
            'slug' => $requestedSlug,
            'name' => $name,
        ]);

        Response::success(['funnel' => $funnels->find($funnelId)], 201);

    // ------------------------------------------------------------- duplicate --
    case 'duplicate':
        $source = $requireFunnel(AdminEndpoint::intParam($body, 'funnel_id'));

        try {
            $copy = $manager->duplicate(
                (int) $source['id'],
                Str::clean($body['name'] ?? '', 190) ?: null,
                Str::slug((string) ($body['slug'] ?? '')) ?: null
            );
        } catch (Throwable $e) {
            Logger::error('funnel.duplicate_failed', [
                'funnel_id' => (int) $source['id'],
                'message'   => $e->getMessage(),
            ]);
            Response::error('The funnel could not be duplicated. No changes were made.', 500);
        }

        AuditLog::record(AuditLog::FUNNEL_DUPLICATED, 'funnel', $copy['id'], [
            'source_funnel_id' => (int) $source['id'],
            'slug'             => $copy['slug'],
        ]);

        Response::success(['funnel' => $funnels->find($copy['id'])], 201);

    // --------------------------------------------------------------- archive --
    case 'archive':
        $funnel = $requireFunnel(AdminEndpoint::intParam($body, 'funnel_id'));

        $funnels->archive((int) $funnel['id']);

        AuditLog::record(AuditLog::FUNNEL_ARCHIVED, 'funnel', (int) $funnel['id'], [
            'slug' => $funnel['slug'],
        ]);

        Response::success(['funnel' => $funnels->find((int) $funnel['id'])]);

    // --------------------------------------------------------------- restore --
    case 'restore':
        $funnel = $requireFunnel(AdminEndpoint::intParam($body, 'funnel_id'));

        $funnels->restore((int) $funnel['id']);

        AuditLog::record(AuditLog::FUNNEL_RESTORED, 'funnel', (int) $funnel['id'], [
            'slug' => $funnel['slug'],
        ]);

        Response::success(['funnel' => $funnels->find((int) $funnel['id'])]);

    // ---------------------------------------------------------------- delete --
    case 'delete':
        $funnel = $requireFunnel(AdminEndpoint::intParam($body, 'funnel_id'));

        // Never leave the installation with nothing to serve.
        if (count($funnels->all(true)) <= 1) {
            Response::error('This is the only funnel. Create another one before deleting it.', 409);
        }

        $guard = $manager->deletionGuard($funnel, AdminEndpoint::boolParam($body, 'confirm_permanent'));

        if (!$guard['allowed']) {
            Response::json([
                'ok'                    => false,
                'error'                 => $guard['reason'],
                'requires_confirmation' => true,
                'leads'                 => $guard['leads'],
            ], 409);
        }

        $funnels->delete((int) $funnel['id']);

        AuditLog::record(AuditLog::FUNNEL_DELETED, 'funnel', (int) $funnel['id'], [
            'slug'           => $funnel['slug'],
            'name'           => $funnel['name'],
            'leads_retained' => $guard['leads'],
        ]);

        Logger::warning('funnel.deleted', [
            'funnel_id'      => (int) $funnel['id'],
            'slug'           => $funnel['slug'],
            'leads_retained' => $guard['leads'],
        ]);

        Response::success([
            'deleted'        => (int) $funnel['id'],
            'leads_retained' => $guard['leads'],
        ]);

    default:
        Response::error('Unknown action.', 400);
}
