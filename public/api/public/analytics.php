<?php

declare(strict_types=1);

/**
 * POST /api/public/analytics.php
 *
 * The only public write surface for analytics. It is deliberately narrow:
 *
 *   - allow-listed event names only; `lead_created` is server-only
 *   - the funnel is resolved from its slug against the PUBLISHED snapshot
 *   - step keys are checked against that snapshot; step ids and positions sent
 *     by the browser are ignored and re-derived
 *   - metadata is filtered to a fixed key list and never carries answers
 *   - rate limited per visitor, with a small payload ceiling
 *
 * It always answers 204 for accepted-or-ignored traffic so a tracking failure
 * can never surface to a visitor or become a signal worth probing.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Config;
use Lumera\Core\Logger;
use Lumera\Core\RateLimiter;
use Lumera\Core\Response;
use Lumera\Repositories\FunnelRepository;
use Lumera\Services\AnalyticsService;
use Lumera\Services\FunnelService;
use Lumera\Support\Request;

App::bootApi(dirname(__DIR__, 3));

/** Ends the request without a body. Tracking never explains itself. */
function done(int $status = 204): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Length: 0');
    }

    exit;
}

if (Request::method() !== 'POST') {
    Response::error('Method not allowed.', 405);
}

if (!AnalyticsService::enabled()) {
    done();
}

// sendBeacon posts as text/plain by default, so both types are accepted.
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

if (!str_contains($contentType, 'application/json') && !str_contains($contentType, 'text/plain')) {
    Response::error('Unsupported content type.', 415);
}

// Tracking payloads are tiny; anything larger is not a real beacon.
$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($length > 4096) {
    done(413);
}

$raw = file_get_contents('php://input');

if ($raw === false || $raw === '' || strlen($raw) > 4096) {
    done(400);
}

$payload = json_decode($raw, true, 8);

if (!is_array($payload)) {
    done(400);
}

// Generous enough for a full funnel run, tight enough to stop flooding.
$limit = RateLimiter::hit(
    'analytics_ingest',
    Request::ip(),
    Config::int('ANALYTICS_RATE_LIMIT_MAX', 300),
    Config::int('ANALYTICS_RATE_LIMIT_WINDOW', 600)
);

if (!$limit['allowed']) {
    done(429);
}

$event = is_string($payload['event'] ?? null) ? $payload['event'] : '';

// Server-only events are rejected outright, never silently ignored.
if (in_array($event, AnalyticsService::SERVER_EVENTS, true)) {
    Logger::warning('analytics.server_event_from_client', ['event' => $event, 'ip_hash' => Request::ipHash()]);
    done(422);
}

if (!in_array($event, AnalyticsService::CLIENT_EVENTS, true)) {
    done(422);
}

$slugInput = $payload['funnel_slug'] ?? '';
$slug = is_string($slugInput) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($slugInput)) : '';

try {
    $funnels = new FunnelRepository();
    // findPublicBySlug excludes archived and soft-deleted funnels, so an
    // archived funnel stops accepting analytics the moment it is archived.
    $funnel = $slug !== '' ? $funnels->findPublicBySlug($slug) : $funnels->primaryPublic();

    if ($funnel === null) {
        done(404);
    }

    $service = new FunnelService();
    $snapshot = $service->publishedSnapshot((int) $funnel['id']);

    if ($snapshot === null || ($snapshot['steps'] ?? []) === []) {
        done(404);
    }

    $analytics = new AnalyticsService();
    $session = $analytics->resolveSession(
        $funnel,
        is_array($payload['context'] ?? null) ? $payload['context'] : [],
        $event
    );

    if ($session === null) {
        done();
    }

    $result = $analytics->recordClientEvent($session, $snapshot, $payload);

    if (!$result['ok']) {
        done(422);
    }
} catch (Throwable $e) {
    // Analytics is never load-bearing: swallow, log, and answer normally.
    Logger::error('analytics.ingest_failed', ['event' => $event, 'message' => $e->getMessage()]);
    done();
}

done();
