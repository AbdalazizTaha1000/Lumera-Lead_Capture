<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Logger;

/**
 * Outbound lead webhook (Zapier, Make, or any HTTPS endpoint).
 *
 * Delivery is best-effort and strictly non-fatal: the lead is already committed
 * before this runs, exactly like the SMTP notification. A webhook failure is
 * logged for the administrator and never surfaces to the visitor.
 */
final class WebhookService
{
    private const TIMEOUT_SECONDS = 8;
    private const MAX_RESPONSE    = 2048;

    /**
     * Validates an administrator-supplied webhook URL.
     *
     * Only absolute http(s) URLs to a routable public host are accepted, so the
     * dashboard cannot be turned into a probe against the server's own network.
     */
    public function isDeliverable(?string $url): bool
    {
        return $this->rejectionReason($url) === null;
    }

    /** @return string|null the reason the URL is unusable, or null when it is fine */
    public function rejectionReason(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return 'A webhook URL is required when the webhook is enabled.';
        }

        if (mb_strlen($url) > 500) {
            return 'The webhook URL is too long.';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Enter a valid absolute URL.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'The webhook URL must use http or https.';
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            return 'The webhook URL must include a host name.';
        }

        if ($this->isPrivateHost($host)) {
            return 'The webhook URL must point at a public host.';
        }

        return null;
    }

    /**
     * Posts the lead payload.
     *
     * @param array<string,mixed> $payload
     * @return array{ok: bool, status?: int, error?: string}
     */
    public function send(string $url, array $payload): array
    {
        if (!$this->isDeliverable($url)) {
            return ['ok' => false, 'error' => 'Webhook URL rejected.'];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL is not available.'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            return ['ok' => false, 'error' => 'Payload could not be encoded.'];
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                'User-Agent: LeadCapture-Webhook/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('webhook.transport_failed', ['status' => $status, 'error' => $error]);

            return ['ok' => false, 'status' => $status, 'error' => mb_substr($error, 0, 200)];
        }

        if ($status < 200 || $status >= 300) {
            Logger::warning('webhook.rejected', [
                'status'   => $status,
                'response' => mb_substr((string) $response, 0, 200),
            ]);

            return ['ok' => false, 'status' => $status, 'error' => 'Endpoint returned HTTP ' . $status];
        }

        Logger::info('webhook.delivered', ['status' => $status]);

        return ['ok' => true, 'status' => $status];
    }

    /**
     * Builds the outbound payload from the stored lead.
     *
     * @param array<string,mixed> $funnel
     * @param array<string,mixed> $lead
     * @param list<array<string,mixed>> $answers
     * @return array<string,mixed>
     */
    public function buildPayload(array $funnel, array $lead, array $answers): array
    {
        $flat = [];

        foreach ($answers as $answer) {
            $key = (string) $answer['step_key'];

            $flat[$key] = [
                'question' => $answer['step_title'],
                'type'     => $answer['step_type'],
                'value'    => $answer['answer_value'],
                'label'    => $answer['answer_label'],
            ];
        }

        return [
            'event' => 'lead.created',
            'sent_at' => date('c'),
            'funnel' => [
                'id'           => (int) ($funnel['id'] ?? 0),
                'slug'         => $funnel['slug'] ?? '',
                'name'         => $funnel['name'] ?? '',
                'company_name' => $funnel['company_name'] ?? '',
                'version'      => (int) ($lead['funnel_version'] ?? 0),
            ],
            'lead' => [
                'id'                 => (int) ($lead['id'] ?? 0),
                'full_name'          => $lead['full_name'] ?? '',
                'country_code'       => $lead['country_code'] ?? null,
                'phone'              => $lead['phone'] ?? null,
                'phone_normalized'   => $lead['phone_normalized'] ?? null,
                'email'              => $lead['email'] ?? null,
                'preferred_language' => $lead['preferred_language'] ?? null,
                'interface_language' => $lead['interface_language'] ?? 'en',
                'consent_given'      => (bool) ($lead['consent_given'] ?? false),
                'lead_score'         => (int) ($lead['lead_score'] ?? 0),
                'status'             => $lead['status'] ?? 'new',
                'submitted_at'       => $lead['submitted_at'] ?? null,
            ],
            'answers' => $flat,
            'attribution' => [
                'utm_source'   => $lead['utm_source'] ?? null,
                'utm_medium'   => $lead['utm_medium'] ?? null,
                'utm_campaign' => $lead['utm_campaign'] ?? null,
                'utm_content'  => $lead['utm_content'] ?? null,
                'utm_term'     => $lead['utm_term'] ?? null,
                'gclid'        => $lead['gclid'] ?? null,
                'fbclid'       => $lead['fbclid'] ?? null,
                'referrer'     => $lead['referrer'] ?? null,
                'landing_page' => $lead['landing_page'] ?? null,
            ],
        ];
    }

    /** Blocks loopback, link-local, private and reserved destinations. */
    private function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost'], true)) {
            return true;
        }

        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        $addresses = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }

            // An unresolvable host is not proof of safety, but it is also not a
            // private-range hit; let cURL fail on it instead.
            if ($addresses === []) {
                return false;
            }
        }

        foreach ($addresses as $address) {
            $public = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($public === false) {
                return true;
            }
        }

        return false;
    }
}
