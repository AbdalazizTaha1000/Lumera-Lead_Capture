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
 *   4. persist the lead + answers in ONE transaction
 *   5. re-read the committed row and verify it before responding
 *   6. attempt the SMTP notification and webhook — failures there never affect
 *      the outcome reported to the visitor, and never lose the lead
 *
 * Success is reported only after the transaction has committed AND the stored
 * lead has been read back with the expected number of answers. No other branch
 * in this file may answer with success.
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
use Lumera\Services\AnalyticsService;
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
    // This branch used to answer 200 with `lead_id: null` so a bot would not
    // learn it had been detected. That silently discarded genuine submissions
    // whenever the field was filled by browser autofill or a password manager,
    // and the visitor still saw the Thank You screen.
    //
    // Nothing may report success without a stored lead. A rejection here is a
    // recoverable error, so a real person can correct it and resubmit.
    Logger::warning('submit.honeypot_triggered', [
        'request_id' => Logger::requestId(),
        'ip_hash'    => Request::ipHash(),
    ]);

    Response::error('We could not submit your request. Please try again.', 422);
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
// Structured diagnostic trail. Carries no secrets: the IP is a keyed hash and
// no credential, phone number or raw address is ever placed in it.
$trail = [
    'request_id'        => Logger::requestId(),
    'funnel_id'         => (int) $funnel['id'],
    'funnel_slug'       => (string) $funnel['slug'],
    'published_version' => (int) ($snapshot['version'] ?? 0),
    // Not named "token": the log redactor masks any key containing that word,
    // and this records only the verification RESULT, never the token itself.
    'submission_verified' => true,
    'ip_hash'           => Request::ipHash(),
];

$minSeconds = (int) ($snapshot['funnel']['min_completion_seconds'] ?? 0);
$elapsed    = SubmissionToken::elapsed((int) ($token['issued_at'] ?? 0));

// A null elapsed means the issue time is unknown; 0 means the form came back
// instantly, which is the case this guard exists for.
if ($minSeconds > 0 && $elapsed !== null && $elapsed < $minSeconds) {
    Logger::warning('submit.too_fast', $trail + ['elapsed' => $elapsed, 'required' => $minSeconds]);
    Response::error('Please take a moment to review your answers before submitting.', 422);
}

// --------------------------------------------------------------- validate --
$language = in_array($payload['language'] ?? 'en', ['en', 'ar'], true) ? (string) $payload['language'] : 'en';
$answers  = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];

$validator = new SubmissionValidator();

if (!$validator->validate($snapshot, $answers, $language)) {
    Logger::info('submit.validation_failed', $trail + ['fields' => array_keys($validator->errors())]);
    Response::validationError($validator->errors());
}

$trail['validation'] = 'passed';

// -------------------------------------------------- duplicate protection --
$leadService = new LeadService();
$contact     = $validator->contact();

$duplicate = $leadService->findRecentDuplicate(
    (int) $funnel['id'],
    $contact['phone_normalized'] ?? null,
    $contact['email'] ?? null
);

if ($duplicate !== null) {
    // Only reported as success because the row genuinely exists and is visible
    // to the admin — findRecentDuplicate() excludes soft-deleted leads.
    $trail['duplicate_of'] = (int) $duplicate['id'];
    $trail['lead_id']      = (int) $duplicate['id'];
    $trail['outcome']      = 'duplicate_suppressed';

    Logger::info('submit.duplicate_suppressed', $trail);

    Response::success([
        'lead_id'   => (int) $duplicate['id'],
        'duplicate' => true,
        'message'   => 'Lead submitted successfully.',
        'screen'    => publicSuccessBlock($snapshot, $language, $funnelService),
    ]);
}

// ------------------------------------------------------------------ store --
// The payload is fully validated at this point, so the token is burned here:
// a retry of *this* exact request can no longer create a second lead.
$burn = SubmissionToken::consume($submissionToken);

if (!$burn['ok']) {
    $trail['outcome'] = 'token_already_used';
    Logger::warning('submit.token_replay', $trail);

    Response::error('This form has already been submitted.', 409);
}

$expectedAnswers = count($validator->answers());
$trail['expected_answers'] = $expectedAnswers;
$trail['transaction']      = 'started';

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
    // store() runs inside a transaction that rolls back on any exception, so
    // nothing partial survives. The visitor must see a failure, never success.
    $trail['transaction'] = 'rolled_back';
    $trail['outcome']     = 'store_failed';
    $trail['error']       = $e->getMessage();

    Logger::error('submit.store_failed', $trail);

    // Nothing was stored, so the visitor must be able to submit again.
    SubmissionToken::release($submissionToken);

    Response::error('We could not submit your request. Please try again.', 500);
}

$trail['transaction'] = 'committed';
$trail['lead_id']     = $leadId;

// --------------------------------------------- post-commit verification --
// The response contract requires proof, not optimism: re-read the committed
// row and its answers on a fresh query before anything is reported as success.
$leadRepo = new LeadRepository();
$lead     = $leadRepo->find($leadId);
$answers  = $lead !== null ? $leadRepo->answers($leadId) : [];

$trail['answers_inserted'] = count($answers);

if ($leadId <= 0 || $lead === null || count($answers) !== $expectedAnswers) {
    $trail['outcome'] = 'persistence_unverified';

    Logger::error('submit.persistence_unverified', $trail);

    SubmissionToken::release($submissionToken);

    Response::error('We could not submit your request. Please try again.', 500);
}

$trail['persistence'] = 'verified';

// From here the lead is durably stored. Everything below is a side effect and
// may fail freely without affecting the outcome reported to the visitor.
$lead['funnel_name'] = (string) $funnel['name'];

// ---------------------------------------------------------------- analytics --
// Attaches the visitor's session to the committed lead and emits the
// server-only lead_created event. Wrapped: tracking must never disturb a lead
// that is already stored.
try {
    (new AnalyticsService())->linkLead($funnel, $leadId);
    $trail['analytics'] = 'linked';
} catch (Throwable $e) {
    $trail['analytics'] = 'failed';
    Logger::warning('submit.analytics_link_failed', ['lead_id' => $leadId, 'message' => $e->getMessage()]);
}

// ------------------------------------------------------------ notification --
// Delivery is best-effort: a mail failure is recorded on the lead for admin
// review and never surfaces to the visitor.
try {
    $result = (new LeadNotification())->send($lead, $answers, $funnel);

    if ($result['ok']) {
        $leadService->markEmailSent($leadId);
        $trail['email'] = 'sent';
    } elseif (!empty($result['skipped'])) {
        $leadService->markEmailSkipped($leadId, (string) ($result['error'] ?? 'skipped'));
        $trail['email'] = 'skipped';
    } else {
        $leadService->markEmailFailed($leadId, (string) ($result['error'] ?? 'unknown error'));
        $trail['email'] = 'failed';
    }
} catch (Throwable $e) {
    $trail['email'] = 'exception';

    try {
        $leadService->markEmailFailed($leadId, 'Notification exception.');
    } catch (Throwable) {
        // Recording the failure must not itself break the response.
    }

    Logger::error('submit.notification_exception', $trail + ['error' => $e->getMessage()]);
}

// ---------------------------------------------------------------- webhook --
// Same contract as email: the lead is already stored, so a webhook failure is
// logged for the administrator and never reaches the visitor.
if ((int) ($funnel['webhook_enabled'] ?? 0) === 1 && (string) ($funnel['webhook_url'] ?? '') !== '') {
    try {
        $webhook = new WebhookService();
        $delivery = $webhook->send(
            (string) $funnel['webhook_url'],
            $webhook->buildPayload($funnel, $lead, $answers)
        );
        $trail['webhook'] = $delivery['ok'] ? 'delivered' : 'failed';
    } catch (Throwable $e) {
        $trail['webhook'] = 'exception';
        Logger::error('submit.webhook_exception', $trail + ['error' => $e->getMessage()]);
    }
} else {
    $trail['webhook'] = 'disabled';
}

$trail['outcome']     = 'persisted';
$trail['http_status'] = 200;

Logger::info('lead.created', $trail);

Response::success([
    'lead_id'   => $leadId,
    'duplicate' => false,
    'message'   => 'Lead submitted successfully.',
    'screen'    => publicSuccessBlock($snapshot, $language, $funnelService),
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
