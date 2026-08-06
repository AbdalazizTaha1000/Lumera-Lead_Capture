<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Config;
use Lumera\Core\Database;
use Lumera\Core\Logger;
use Lumera\Repositories\LeadRepository;
use Lumera\Support\Request;
use Lumera\Support\Str;

/**
 * Persists a validated submission.
 *
 * Storage always happens first and in a single transaction; email delivery is a
 * separate, non-fatal step so a mail failure can never lose a lead.
 */
final class LeadService
{
    public function __construct(
        private LeadRepository $leads = new LeadRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $snapshot   published funnel snapshot
     * @param list<array<string,mixed>> $answers validated answers
     * @param array<string,mixed> $contact    validated contact values
     * @param array<string,mixed> $context    attribution + technical metadata
     * @return int lead id
     */
    public function store(
        array $snapshot,
        array $answers,
        array $contact,
        array $context,
        int $score,
        bool $consentGiven
    ): int {
        $funnelId = (int) ($snapshot['funnel']['id'] ?? 0);
        $version  = (int) ($snapshot['version'] ?? 0);

        $phoneNormalized = $contact['phone_normalized'] ?? null;

        $lead = [
            'funnel_id'      => $funnelId ?: null,
            'funnel_version' => $version,

            'full_name'          => Str::limit($contact['full_name'] ?? '', 190) ?? '',
            'country_code'       => Str::limit($contact['country_code'] ?? null, 8),
            'phone'              => Str::limit($contact['phone'] ?? null, 40),
            'phone_normalized'   => Str::limit($phoneNormalized, 40),
            'email'              => Str::limit($contact['email'] ?? null, 190),
            'preferred_language' => Str::limit($contact['preferred_language'] ?? null, 20),
            'interface_language' => Str::limit($context['interface_language'] ?? 'en', 5),

            'consent_given' => $consentGiven ? 1 : 0,
            'consent_at'    => $consentGiven ? date('Y-m-d H:i:s') : null,

            'lead_score' => $score,
            'status'     => 'new',

            'utm_source'   => Str::limit($context['utm_source'] ?? null, 190),
            'utm_medium'   => Str::limit($context['utm_medium'] ?? null, 190),
            'utm_campaign' => Str::limit($context['utm_campaign'] ?? null, 190),
            'utm_content'  => Str::limit($context['utm_content'] ?? null, 190),
            'utm_term'     => Str::limit($context['utm_term'] ?? null, 190),
            'gclid'        => Str::limit($context['gclid'] ?? null, 255),
            'fbclid'       => Str::limit($context['fbclid'] ?? null, 255),
            'referrer'     => Str::limit($context['referrer'] ?? null, 500),
            'landing_page' => Str::limit($context['landing_page'] ?? null, 500),

            'device_type' => Str::limit($context['device_type'] ?? Request::deviceType(), 20),
            'user_agent'  => Str::limit($context['user_agent'] ?? Request::userAgent(), 500),
            'screen_size' => Str::limit($context['screen_size'] ?? null, 20),
            'ip_hash'     => Request::ipHash(),
            'ip_address'  => Request::rawIpIfEnabled(),

            'submission_hash' => $this->submissionHash($funnelId, $phoneNormalized, $contact['email'] ?? null),
            'email_status'    => 'pending',
            'submitted_at'    => date('Y-m-d H:i:s'),
        ];

        return Database::transaction(function () use ($lead, $answers) {
            $leadId = $this->leads->create($lead);
            $this->leads->insertAnswers($leadId, $answers);

            return $leadId;
        });
    }

    /**
     * Stable fingerprint used to spot an accidental repeat submission from a
     * different session (double tap, back-button re-post).
     */
    public function submissionHash(int $funnelId, ?string $phoneNormalized, ?string $email): ?string
    {
        $identity = $phoneNormalized ?: ($email !== null ? mb_strtolower($email) : null);

        if ($identity === null || $identity === '') {
            return null;
        }

        return hash_hmac('sha256', $funnelId . '|' . $identity, Config::secret());
    }

    /** @return array<string,mixed>|null the duplicate lead, when one exists */
    public function findRecentDuplicate(int $funnelId, ?string $phoneNormalized, ?string $email): ?array
    {
        return $this->leads->recentDuplicate(
            $this->submissionHash($funnelId, $phoneNormalized, $email),
            300
        );
    }

    /**
     * Extracts attribution + technical metadata from the client payload,
     * clamping everything to safe lengths.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function contextFromPayload(array $payload): array
    {
        $attribution = is_array($payload['attribution'] ?? null) ? $payload['attribution'] : [];
        $meta        = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        $pick = static function (array $source, string $key, int $max): ?string {
            $value = $source[$key] ?? null;

            if (!is_scalar($value)) {
                return null;
            }

            $value = Str::clean((string) $value, $max);

            return $value === '' ? null : $value;
        };

        return [
            'utm_source'   => $pick($attribution, 'utm_source', 190),
            'utm_medium'   => $pick($attribution, 'utm_medium', 190),
            'utm_campaign' => $pick($attribution, 'utm_campaign', 190),
            'utm_content'  => $pick($attribution, 'utm_content', 190),
            'utm_term'     => $pick($attribution, 'utm_term', 190),
            'gclid'        => $pick($attribution, 'gclid', 255),
            'fbclid'       => $pick($attribution, 'fbclid', 255),
            'referrer'     => $pick($attribution, 'referrer', 500),
            'landing_page' => $pick($attribution, 'landing_page', 500),
            'screen_size'  => $pick($meta, 'screen_size', 20),
            'interface_language' => in_array($payload['language'] ?? 'en', ['en', 'ar'], true)
                ? (string) $payload['language'] : 'en',
            'device_type' => Request::deviceType(),
            'user_agent'  => Request::userAgent(),
        ];
    }

    public function markEmailSent(int $leadId): void
    {
        $this->leads->markEmail($leadId, 'sent');
    }

    public function markEmailFailed(int $leadId, string $reason): void
    {
        $this->leads->markEmail($leadId, 'failed', $reason);
        Logger::error('lead.email_failed', ['lead_id' => $leadId, 'reason' => $reason]);
    }

    public function markEmailSkipped(int $leadId, string $reason): void
    {
        $this->leads->markEmail($leadId, 'skipped', $reason);
        Logger::warning('lead.email_skipped', ['lead_id' => $leadId, 'reason' => $reason]);
    }
}
