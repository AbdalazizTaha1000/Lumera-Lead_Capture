<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Config;
use Lumera\Core\Logger;
use Lumera\Core\Session;
use Lumera\Repositories\AnalyticsRepository;
use Lumera\Support\Geo;
use Lumera\Support\Request;
use Lumera\Support\Str;
use Lumera\Support\TrafficSource;
use Lumera\Support\UserAgent;

/**
 * The analytics engine.
 *
 * Two rules shape everything here:
 *
 *  1. Analytics is never load-bearing. Every public entry point is wrapped so a
 *     failure — a missing table, a locked row, a bad payload — degrades to a
 *     logged warning and never affects funnel rendering or lead capture.
 *
 *  2. The browser is not trusted. It names an event and a step key; the server
 *     resolves the funnel, the published version, the step and every dimension
 *     from its own state. `lead_created` cannot be sent by a client at all.
 */
final class AnalyticsService
{
    /** Events a browser may ask for. Everything else is rejected. */
    public const CLIENT_EVENTS = [
        'funnel_view', 'session_start', 'step_view', 'step_start',
        'step_complete', 'step_back', 'validation_error', 'funnel_complete',
    ];

    /** Events only the server may emit. */
    public const SERVER_EVENTS = ['lead_created', 'funnel_abandon'];

    /** Once-per-session events; repeats are accepted but not duplicated. */
    private const ONCE_PER_SESSION = ['session_start', 'funnel_view', 'funnel_complete'];

    /** The events a fresh page load sends. Only these can begin a new visit. */
    private const VISIT_START_EVENTS = ['session_start', 'funnel_view'];

    /** Metadata keys a client may attach. Anything else is dropped. */
    private const ALLOWED_METADATA = ['reason', 'category', 'direction', 'language', 'variant', 'position'];

    private const SESSION_KEY = '_analytics_session';

    public function __construct(
        private AnalyticsRepository $repo = new AnalyticsRepository(),
    ) {
    }

    public static function enabled(): bool
    {
        return Config::bool('ANALYTICS_ENABLED', true);
    }

    public static function timeoutMinutes(): int
    {
        return max(5, min(1440, Config::int('ANALYTICS_SESSION_TIMEOUT_MINUTES', 30)));
    }

    public static function retentionDays(): int
    {
        return max(7, min(3650, Config::int('ANALYTICS_RAW_RETENTION_DAYS', 90)));
    }

    /**
     * One-way visitor identity.
     *
     * HMAC of the client IP and user agent under a server-only salt. The raw IP
     * never reaches the database, and rotating ANALYTICS_HASH_SALT retires every
     * existing identity.
     */
    public static function visitorHash(?string $ip = null, ?string $userAgent = null): string
    {
        $salt = Config::string('ANALYTICS_HASH_SALT', '');

        if ($salt === '') {
            // Falls back to APP_SECRET so a missing salt degrades to "still
            // hashed" rather than "hashed with an empty key".
            $salt = Config::secret();
        }

        return hash_hmac(
            'sha256',
            ($ip ?? Request::ip()) . '|' . ($userAgent ?? Request::userAgent()),
            $salt
        );
    }

    /* ========================================================== sessions === */

    /**
     * Returns the live session for this funnel, creating one when needed.
     *
     * A session belongs to exactly one funnel and one published version. It is
     * replaced when the funnel changes, the version changes, or the visitor has
     * been idle beyond the configured timeout.
     *
     * A finished session is replaced only by a fresh page load. Beacons are
     * fire-and-forget, so a completed visit trails a few late events behind it;
     * rotating on those would invent a second session for one real visit and
     * quietly depress the conversion rate.
     *
     * @param array<string,mixed> $funnel
     * @param array<string,mixed> $context client-supplied, untrusted context
     * @param string $event the event being recorded, which decides whether a
     *                      finished session may be replaced
     * @return array<string,mixed>|null the session row, or null when disabled
     */
    public function resolveSession(array $funnel, array $context = [], string $event = ''): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        Session::start();

        $funnelId = (int) $funnel['id'];
        $version  = (int) ($funnel['published_version'] ?? 0);
        $stored   = Session::get(self::SESSION_KEY);

        if (is_array($stored) && isset($stored['uuid'])) {
            $existing = $this->repo->findSession((string) $stored['uuid']);

            if ($existing !== null
                && (int) $existing['funnel_id'] === $funnelId
                && (int) $existing['published_version'] === $version
                && (time() - strtotime((string) $existing['last_seen_at'])) < self::timeoutMinutes() * 60
            ) {
                $finished = $existing['completed_at'] !== null || $existing['abandoned_at'] !== null;

                // Only a new page load starts a new visit.
                if (!$finished || !in_array($event, self::VISIT_START_EVENTS, true)) {
                    return $existing;
                }
            }
        }

        return $this->startSession($funnel, $context);
    }

    /**
     * @param array<string,mixed> $funnel
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function startSession(array $funnel, array $context): ?array
    {
        $ua = Request::userAgent();
        $agent = UserAgent::parse($ua);
        $geo = Geo::resolve();

        $utm = [
            'source'   => $this->clean($context['utm_source'] ?? null, 190),
            'medium'   => $this->clean($context['utm_medium'] ?? null, 190),
            'campaign' => $this->clean($context['utm_campaign'] ?? null, 190),
            'content'  => $this->clean($context['utm_content'] ?? null, 190),
            'term'     => $this->clean($context['utm_term'] ?? null, 190),
        ];

        $referrer = TrafficSource::sanitize(is_string($context['referrer'] ?? null) ? $context['referrer'] : null);

        $uuid = $this->uuid();

        $id = $this->repo->createSession([
            'session_uuid'      => $uuid,
            'funnel_id'         => (int) $funnel['id'],
            'published_version' => (int) ($funnel['published_version'] ?? 0),
            'visitor_hash'      => self::visitorHash(null, $ua),
            'is_bot'            => $agent['is_bot'] ? 1 : 0,
            'landing_path'      => $this->clean($context['landing_path'] ?? null, 500),
            'referrer'          => $referrer,
            'referrer_domain'   => TrafficSource::domain($referrer),
            'source_group'      => TrafficSource::classify($referrer, $utm),
            'utm_source'        => $utm['source'],
            'utm_medium'        => $utm['medium'],
            'utm_campaign'      => $utm['campaign'],
            'utm_content'       => $utm['content'],
            'utm_term'          => $utm['term'],
            'device_type'       => $agent['device_type'],
            'browser'           => $agent['browser'],
            'browser_version'   => $agent['browser_version'],
            'os'                => $agent['os'],
            'os_version'        => $agent['os_version'],
            'country_code'      => $geo['country_code'],
            'country_name'      => $geo['country_name'],
            'city'              => $geo['city'],
            'language'          => $this->clean($context['language'] ?? null, 12),
            'timezone'          => $this->clean($context['timezone'] ?? null, 64),
        ]);

        Session::set(self::SESSION_KEY, ['uuid' => $uuid, 'id' => $id]);

        return $this->repo->findSession($uuid);
    }

    /* ============================================================ events === */

    /**
     * Records a client-requested event against the resolved session.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $snapshot published funnel snapshot
     * @param array<string,mixed> $payload
     * @return array{ok: bool, error?: string, recorded?: bool}
     */
    public function recordClientEvent(array $session, array $snapshot, array $payload): array
    {
        $type = is_string($payload['event'] ?? null) ? $payload['event'] : '';

        if (!in_array($type, self::CLIENT_EVENTS, true)) {
            return ['ok' => false, 'error' => 'Unsupported event.'];
        }

        $sessionId = (int) $session['id'];
        $funnelId  = (int) $session['funnel_id'];

        // Step events must name a step that exists in the PUBLISHED snapshot.
        $stepKey = null;
        $stepId = null;
        $position = null;

        if (in_array($type, ['step_view', 'step_start', 'step_complete', 'step_back', 'validation_error'], true)) {
            $requested = is_string($payload['step_key'] ?? null) ? $payload['step_key'] : '';
            $step = $this->findStep($snapshot, $requested);

            if ($step === null) {
                return ['ok' => false, 'error' => 'Unknown step.'];
            }

            $stepKey = (string) $step['key'];
            $stepId = isset($step['id']) ? (int) $step['id'] : null;
            // Position is resolved server-side; the client's value is ignored.
            $position = (int) $step['_position'] + 1;
        }

        // Repeats of a once-per-session event are a no-op, not an error.
        if (in_array($type, self::ONCE_PER_SESSION, true)
            && $this->repo->hasEvent($sessionId, $type)) {
            $this->repo->touchSession($sessionId);

            return ['ok' => true, 'recorded' => false];
        }

        $this->repo->recordEvent([
            'session_id'    => $sessionId,
            'funnel_id'     => $funnelId,
            'event_type'    => $type,
            'step_key'      => $stepKey,
            'step_id'       => $stepId,
            'step_position' => $position,
            'event_value'   => $this->eventValue($type, $payload),
            'metadata_json' => $this->metadata($payload['metadata'] ?? null),
        ]);

        $this->applySideEffects($session, $type, $stepKey);

        return ['ok' => true, 'recorded' => true];
    }

    /**
     * Keeps the denormalised session counters in step with the timeline, so
     * summaries never have to scan events.
     *
     * @param array<string,mixed> $session
     */
    private function applySideEffects(array $session, string $type, ?string $stepKey): void
    {
        $id = (int) $session['id'];
        $update = ['last_seen_at' => date('Y-m-d H:i:s')];

        if ($stepKey !== null) {
            $update['last_step_key'] = $stepKey;

            if (($session['first_step_key'] ?? null) === null) {
                $update['first_step_key'] = $stepKey;
            }
        }

        if ($type === 'step_start') {
            $update['steps_started'] = (int) $session['steps_started'] + 1;
        }

        if ($type === 'step_complete') {
            $update['steps_completed'] = (int) $session['steps_completed'] + 1;
        }

        // funnel_complete from a browser is recorded but never marks the session
        // complete: completion is set server-side once a lead is persisted, so
        // the completion rate cannot be inflated from the client.
        $this->repo->updateSession($id, $update);
    }

    /**
     * Links a persisted lead to the visitor's session and closes it out.
     *
     * Called only after the lead is committed and verified. Never accepts a
     * lead id from the browser.
     */
    public function linkLead(array $funnel, int $leadId): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            Session::start();
            $stored = Session::get(self::SESSION_KEY);

            if (!is_array($stored) || !isset($stored['uuid'])) {
                return;
            }

            $session = $this->repo->findSession((string) $stored['uuid']);

            if ($session === null || (int) $session['funnel_id'] !== (int) $funnel['id']) {
                return;
            }

            $id = (int) $session['id'];
            $duration = max(0, time() - strtotime((string) $session['started_at']));

            $this->repo->updateSession($id, [
                'lead_id'          => $leadId,
                'completed_at'     => date('Y-m-d H:i:s'),
                'abandoned_at'     => null,
                'duration_seconds' => $duration,
                'last_seen_at'     => date('Y-m-d H:i:s'),
            ]);

            if (!$this->repo->hasEvent($id, 'funnel_complete')) {
                $this->repo->recordEvent([
                    'session_id' => $id,
                    'funnel_id'  => (int) $funnel['id'],
                    'event_type' => 'funnel_complete',
                    'event_value' => (string) $duration,
                ]);
            }

            $this->repo->recordEvent([
                'session_id'  => $id,
                'funnel_id'   => (int) $funnel['id'],
                'event_type'  => 'lead_created',
                'event_value' => (string) $leadId,
            ]);
        } catch (\Throwable $e) {
            // A lead is already stored; analytics must not disturb that.
            Logger::warning('analytics.link_lead_failed', ['lead_id' => $leadId, 'message' => $e->getMessage()]);
        }
    }

    /* ======================================================= maintenance === */

    /**
     * Closes sessions that went quiet, then rebuilds the daily rollups.
     *
     * Idempotent: re-running produces the same rows.
     *
     * @return array{abandoned: int, days: int, funnels: int}
     */
    public function rollup(int $days = 7): array
    {
        $abandoned = 0;

        foreach ($this->repo->staleSessions(self::timeoutMinutes()) as $row) {
            $id = (int) $row['id'];
            $duration = max(0, strtotime((string) $row['last_seen_at']) - strtotime((string) $row['started_at']));

            $this->repo->updateSession($id, [
                'abandoned_at'     => (string) $row['last_seen_at'],
                'duration_seconds' => $duration,
            ]);

            $this->repo->recordEvent([
                'session_id'  => $id,
                'funnel_id'   => null,
                'event_type'  => 'funnel_abandon',
                'event_value' => (string) $duration,
                'occurred_at' => (string) $row['last_seen_at'],
            ]);

            $abandoned++;
        }

        // The first day analytics ever recorded. Everything before it has lead
        // data only and is flagged so the UI never implies measured traffic.
        $firstSession = $this->repo->firstSessionDate();

        $funnels = 0;
        $days = max(1, min(400, $days));

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));

            foreach ($this->repo->funnelsWithActivity($date) as $funnelId) {
                $this->rollupDay($funnelId, $date, $firstSession);
                $funnels++;
            }
        }

        return ['abandoned' => $abandoned, 'days' => $days, 'funnels' => $funnels];
    }

    private function rollupDay(int $funnelId, string $date, ?string $firstSession): void
    {
        $from = $date . ' 00:00:00';
        $to   = $date . ' 23:59:59';

        $summary = $this->repo->summary($funnelId, $from, $to);
        $series  = $this->repo->dailySeries($funnelId, $from, $to);
        $day     = $series[$date] ?? null;

        $sessions = $summary['sessions'];
        $engaged  = $summary['engaged_sessions'];
        $leads    = $this->repo->leadCount($funnelId, $from, $to);

        // Pre-analytics days carry real lead counts and nothing else.
        $leadOnly = $firstSession === null || $date < $firstSession;

        $this->repo->upsertRollup([
            'funnel_id'        => $funnelId,
            'date'             => $date,
            'views'            => $this->repo->viewCount($funnelId, $from, $to),
            'sessions'         => $sessions,
            'unique_visitors'  => $summary['unique_visitors'],
            'engaged_sessions' => $engaged,
            'completions'      => $summary['completions'],
            'leads'            => $leads,
            'abandonments'     => $summary['abandonments'],
            'completion_rate'  => $engaged > 0 ? round(($summary['completions'] / $engaged) * 100, 2) : 0,
            'avg_completion_seconds' => $summary['avg_completion_seconds'],
            'desktop_sessions' => $day['desktop'] ?? 0,
            'mobile_sessions'  => $day['mobile'] ?? 0,
            'tablet_sessions'  => $day['tablet'] ?? 0,
            'lead_only'        => $leadOnly ? 1 : 0,
        ]);
    }

    /**
     * Deletes raw events past the retention window. Sessions, rollups and leads
     * are never touched.
     */
    public function prune(): int
    {
        $total = 0;

        // Batched so a large backlog cannot hold a long lock.
        for ($i = 0; $i < 50; $i++) {
            $deleted = $this->repo->pruneEvents(self::retentionDays());
            $total += $deleted;

            if ($deleted === 0) {
                break;
            }
        }

        return $total;
    }

    /* =========================================================== helpers === */

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>|null
     */
    private function findStep(array $snapshot, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        foreach ($snapshot['steps'] ?? [] as $position => $step) {
            if ((string) ($step['key'] ?? '') === $key) {
                $step['_position'] = $position;

                return $step;
            }
        }

        return null;
    }

    /**
     * Only a bounded, non-identifying value is kept. For validation errors that
     * is the error CATEGORY — never the field value the visitor typed.
     *
     * @param array<string,mixed> $payload
     */
    private function eventValue(string $type, array $payload): ?string
    {
        if ($type !== 'validation_error') {
            return null;
        }

        $reason = $payload['reason'] ?? ($payload['metadata']['reason'] ?? null);

        if (!is_string($reason)) {
            return 'invalid';
        }

        // Collapse to a known category so no free text can reach the table.
        $reason = strtolower(preg_replace('/[^a-z_]/i', '', $reason) ?? '');
        $known = ['required', 'invalid_email', 'invalid_phone', 'invalid_number',
                  'min_length', 'max_length', 'pattern', 'consent_required',
                  'select_required', 'server', 'invalid'];

        return in_array($reason, $known, true) ? $reason : 'invalid';
    }

    /**
     * Allow-listed, scalar-only, length-capped. Answers can never land here.
     *
     * @param mixed $metadata
     */
    private function metadata(mixed $metadata): ?string
    {
        if (!is_array($metadata) || $metadata === []) {
            return null;
        }

        $clean = [];

        foreach (self::ALLOWED_METADATA as $key) {
            if (!array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];

            if (is_bool($value) || is_int($value)) {
                $clean[$key] = $value;
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = Str::clean($value, 60);
            }
        }

        if ($clean === []) {
            return null;
        }

        return json_encode($clean, JSON_UNESCAPED_UNICODE) ?: null;
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $clean = Str::clean((string) $value, $max);

        return $clean === '' ? null : $clean;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
