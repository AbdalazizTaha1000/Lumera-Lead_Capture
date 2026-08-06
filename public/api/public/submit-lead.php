<?php

declare(strict_types=1);

/**
 * POST /api/public/submit-lead.php
 *
 * Order of operations:
 *   1. transport + anti-abuse checks (method, content-type, size, rate limit,
 *      honeypot, minimum completion time, single-use submission token)
 *   2. CSRF
 *   3. authoritative validation against the PUBLISHED snapshot
 *   4. persist the lead + answers in one transaction
 *   5. attempt the SMTP notification — a failure here never loses the lead
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Csrf;
use Lumera\Core\Logger;
use Lumera\Core\RateLimiter;
use Lumera\Core\Response;
use Lumera\Core\SubmissionToken;
use Lumera\Mail\LeadNotification;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\LeadRepository;
use Lumera\Services\FunnelService;
use Lumera\Services\LeadService;
use Lumera\Services\WebhookService;
use Lumera\Support\Request;
use Lumera\Validators\SubmissionValidator;

App::bootApi(dirname(__DIR__, 3));

if (Request::method() !== 'POST') {
    Response::error('Method not allowed.', 405);
}

if (!Request::isJson()) {
    Response::error('Unsupported content type.', 415);
}

// ---------------------------------------------------------------- payload --
[$payload, $payloadError] = Request::jsonBody();

if ($payload === null) {
    Response::error($payloadError ?? 'Malformed request.', 400);
}

// ------------------------------------------------------------- rate limit --
$limit = RateLimiter::publicSubmission();

if (!$limit['allowed']) {
    Logger::warning('submit.rate_limited', ['ip_hash' => Request::ipHash()]);
    Response::error('Too many submissions. Please try again later.', 429, ['retry_after' => $limit['retry_after']]);
}

// ------------------------------------------------------------------- CSRF --
if (!Csrf::validate(Csrf::fromRequest($payload), 'public')) {
    Logger::warning('submit.csrf_rejected', ['ip_hash' => Request::ipHash()]);
    Response::error('Your session has expired. Please refresh the page and try again.', 419);
}

// --------------------------------------------------------------- honeypot --
$honeypot = $payload['company_website'] ?? '';

if (is_string($honeypot) && trim($honeypot) !== '') {
    // Silently accept so the bot does not learn it was detected.
    Logger::warning('submit.honeypot_triggered', ['ip_hash' => Request::ipHash()]);
    Response::success(['lead_id' => null, 'duplicate' => false]);
}

// ------------------------------------------------- single-use submit token --
// Verified now, burned later (just before the insert) so that a validation
// error does not lock the visitor out of correcting and resubmitting.
$submissionToken = is_string($payload['submission_token'] ?? null) ? $payload['submission_token'] : null;
$token = SubmissionToken::verify($submissionToken);

if (!$token['ok']) {
    $message = $token['reason'] === 'already_used'
        ? 'This form has already been submitted.'
        : 'Your session has expired. Please refresh the page and try again.';

    Logger::warning('submit.token_rejected', ['reason' => $token['reason']]);
    Response::error($message, 409);
}

// ------------------------------------------------------------ funnel load --
$slugInput = $payload['funnel_slug'] ?? '';
$slug      = is_string($slugInput) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($slugInput)) : '';

$funnels = new FunnelRepository();
// findPublicBySlug excludes archived funnels, so an archived funnel stops
// accepting submissions the moment it is archived.
$funnel  = $slug !== '' ? $funnels->findPublicBySlug($slug) : $funnels->primaryPublic();

if ($funnel === null || (string) $funnel['status'] !== 'active') {
    Response::error('This form is not available.', 404);
}

$funnelService = new FunnelService();
$snapshot      = $funnelService->publishedSnapshot((int) $funnel['id']);

if ($snapshot === null || ($snapshot['steps'] ?? []) === []) {
    Response::error('This form is not available.', 503);
}

// ------------------------------------------------- minimum completion time --
$minSeconds = (int) ($snapshot['funnel']['min_completion_seconds'] ?? 0);
$elapsed    = SubmissionToken::elapsed((int) ($token['issued_at'] ?? 0));

// A null elapsed means the issue time is unknown; 0 means the form came back
// instantly, which is the case this guard exists for.
if ($minSeconds > 0 && $elapsed !== null && $elapsed < $minSeconds) {
    Logger::warning('submit.too_fast', ['elapsed' => $elapsed, 'required' => $minSeconds]);
    Response::error('Please take a moment to review your answers before submitting.', 422);
}

// --------------------------------------------------------------- validate --
$language = in_array($payload['language'] ?? 'en', ['en', 'ar'], true) ? (string) $payload['language'] : 'en';
$answers  = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

$validator = new SubmissionValidator();

if (!$validator->validate($snapshot, $answers, $language)) {
    Response::validationError($validator->errors());
}

// -------------------------------------------------- duplicate protection --
$leadService = new LeadService();
$contact     = $validator->contact();

$duplicate = $leadService->findRecentDuplicate(
    (int) $funnel['id'],
    $contact['phone_normalized'] ?? null,
    $contact['email'] ?? null
);

if ($duplicate !== null) {
    Logger::info('submit.duplicate_suppressed', ['existing_lead_id' => (int) $duplicate['id']]);

    // Treat as success: the visitor's data is already safely stored.
    Response::success([
        'lead_id'   => (int) $duplicate['id'],
        'duplicate' => true,
        'success'   => publicSuccessBlock($snapshot, $language, $funnelService),
    ]);
}

// ------------------------------------------------------------------ store --
// The payload is fully validated at this point, so the token is burned here:
// a retry of *this* exact request can no longer create a second lead.
$burn = SubmissionToken::consume($submissionToken);

if (!$burn['ok']) {
    Response::error('This form has already been submitted.', 409);
}

try {
    $context = $leadService->contextFromPayload($payload);
    $leadId  = $leadService->store(
        $snapshot,
        $validator->answers(),
        $contact,
        $context,
        $validator->score(),
        $validator->consentGiven()
    );
} catch (Throwable $e) {
    Logger::error('submit.store_failed', ['message' => $e->getMessage()]);
    Response::error('We could not save your details. Please try again.', 500);
}

Logger::info('lead.created', [
    'lead_id'        => $leadId,
    'funnel_id'      => (int) $funnel['id'],
    'funnel_version' => (int) ($snapshot['version'] ?? 0),
    'score'          => $validator->score(),
]);

// ------------------------------------------------------------ notification --
// Delivery is best-effort. The lead is already committed; a mail failure is
// recorded on the lead for admin review and never surfaces to the visitor.
$leadRepo = new LeadRepository();
$lead     = $leadRepo->find($leadId) ?? [];
$answers  = $leadRepo->answers($leadId);

$lead['funnel_name'] = (string) $funnel['name'];

try {
    $result = (new LeadNotification())->send($lead, $answers, $funnel);

    if ($result['ok']) {
        $leadService->markEmailSent($leadId);
    } elseif (!empty($result['skipped'])) {
        $leadService->markEmailSkipped($leadId, (string) ($result['error'] ?? 'skipped'));
    } else {
        $leadService->markEmailFailed($leadId, (string) ($result['error'] ?? 'unknown error'));
    }
} catch (Throwable $e) {
    $leadService->markEmailFailed($leadId, 'Notification exception.');
    Logger::error('submit.notification_exception', ['lead_id' => $leadId, 'message' => $e->getMessage()]);
}

// ---------------------------------------------------------------- webhook --
// Same contract as email: the lead is already stored, so a webhook failure is
// logged for the administrator and never reaches the visitor.
if ((int) ($funnel['webhook_enabled'] ?? 0) === 1 && (string) ($funnel['webhook_url'] ?? '') !== '') {
    try {
        $webhook = new WebhookService();
        $webhook->send(
            (string) $funnel['webhook_url'],
            $webhook->buildPayload($funnel, $lead, $answers)
        );
    } catch (Throwable $e) {
        Logger::error('submit.webhook_exception', ['lead_id' => $leadId, 'message' => $e->getMessage()]);
    }
}

Response::success([
    'lead_id'   => $leadId,
    'duplicate' => false,
    'success'   => publicSuccessBlock($snapshot, $language, $funnelService),
]);

/**
 * Success-screen content, resolved server side from the published funnel.
 *
 * @param array<string,mixed> $snapshot
 * @return array<string,mixed>
 */
function publicSuccessBlock(array $snapshot, string $language, FunnelService $service): array
{
    $labels   = $snapshot['funnel']['labels'] ?? [];
    $redirect = $snapshot['funnel']['redirect'] ?? [];

    return [
        'title'    => $labels['success_title'][$language] ?? $labels['success_title']['en'] ?? 'Thank you',
        'message'  => $labels['success_message'][$language] ?? $labels['success_message']['en'] ?? '',
        'button'   => $labels['success_button'][$language] ?? $labels['success_button']['en'] ?? '',
        'whatsapp' => $service->whatsappConfig($snapshot),
        'redirect' => ($redirect['url'] ?? null) !== null
            ? ['url' => $redirect['url'], 'delay' => (int) ($redirect['delay'] ?? 5)]
            : null,
    ];
}
