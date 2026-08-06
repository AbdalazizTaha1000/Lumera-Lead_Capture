<?php

declare(strict_types=1);

namespace Lumera\Mail;

use Lumera\Core\Config;
use Lumera\Repositories\SettingsRepository;
use Lumera\Support\Phone;
use Lumera\Support\Str;

/**
 * Builds and sends the internal "new lead" notification.
 */
final class LeadNotification
{
    public function __construct(
        private Mailer $mailer = new Mailer(),
        private SettingsRepository $settings = new SettingsRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $lead
     * @param list<array<string,mixed>> $answers
     * @param array<string,mixed>|null $funnel the funnel row, for per-funnel routing and branding
     * @return array{ok: bool, error?: string, skipped?: bool}
     */
    public function send(array $lead, array $answers, ?array $funnel = null): array
    {
        // A funnel may route its leads to its own inbox; the .env value is the
        // fallback for funnels that do not set one.
        $recipient = trim((string) ($funnel['recipient_email'] ?? ''));

        if ($recipient === '') {
            $recipient = Config::string('LEAD_RECIPIENT_EMAIL', '');
        }

        if ($recipient === '') {
            return ['ok' => false, 'skipped' => true, 'error' => 'No lead recipient is configured.'];
        }

        if (!$this->mailer->isConfigured()) {
            return ['ok' => false, 'skipped' => true, 'error' => 'SMTP is not configured.'];
        }

        $recipients = array_map('trim', explode(',', $recipient));

        $view = $this->viewData($lead, $answers, $funnel);

        return $this->mailer->send(
            $recipients,
            $this->subject($lead, $answers, $funnel),
            $this->render('lead-notification.php', $view),
            $this->renderText($view),
            is_string($lead['email'] ?? null) ? $lead['email'] : null,
            (string) ($lead['full_name'] ?? '')
        );
    }

    /**
     * @param array<string,mixed> $lead
     * @param list<array<string,mixed>> $answers
     * @param array<string,mixed>|null $funnel
     */
    public function subject(array $lead, array $answers, ?array $funnel = null): string
    {
        $template = (string) $this->settings->get(
            'notification_subject_template',
            'New Lead #{lead_id} — {full_name} ({purpose})'
        );

        $purpose = '—';
        $budget  = '—';

        foreach ($answers as $answer) {
            if (($answer['step_key'] ?? '') === 'property_purpose') {
                $purpose = (string) ($answer['answer_label'] ?? $answer['answer_value'] ?? '—');
            }
            if (($answer['step_key'] ?? '') === 'budget') {
                $budget = (string) ($answer['answer_label'] ?? $answer['answer_value'] ?? '—');
            }
        }

        $subject = strtr($template, [
            '{lead_id}'   => (string) ($lead['id'] ?? ''),
            '{full_name}' => (string) ($lead['full_name'] ?? 'Unknown'),
            '{purpose}'   => $purpose,
            '{budget}'    => $budget,
            '{score}'     => (string) ($lead['lead_score'] ?? 0),
            '{funnel}'    => (string) ($funnel['name'] ?? $lead['funnel_name'] ?? ''),
            '{company}'   => $this->companyName($funnel),
        ]);

        return Str::clean($subject, 190);
    }

    /**
     * @param array<string,mixed> $lead
     * @param list<array<string,mixed>> $answers
     * @param array<string,mixed>|null $funnel
     * @return array<string,mixed>
     */
    private function viewData(array $lead, array $answers, ?array $funnel = null): array
    {
        $normalized = (string) ($lead['phone_normalized'] ?? '');
        $digits     = Phone::whatsappDigits($normalized);
        $company    = $this->companyName($funnel);

        $whatsappMessage = sprintf(
            'Hello %s, this is %s following up on your enquiry.',
            (string) ($lead['full_name'] ?? ''),
            $company
        );

        return [
            'lead'      => $lead,
            'answers'   => $answers,
            'company'   => $company,
            'brand'     => [
                'primary' => (string) ($funnel['primary_color'] ?? '#0F2E4C'),
                'accent'  => (string) ($funnel['accent_color'] ?? '#C9A227'),
                'logo'    => $this->absoluteUrl($funnel['logo_path'] ?? null),
            ],
            'adminUrl'  => Config::appUrl() . '/admin/#/leads/' . (int) ($lead['id'] ?? 0),
            'whatsappUrl' => $digits !== ''
                ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($whatsappMessage)
                : null,
            'attribution' => [
                'Source'      => $lead['utm_source'] ?? null,
                'Medium'      => $lead['utm_medium'] ?? null,
                'Campaign'    => $lead['utm_campaign'] ?? null,
                'Content'     => $lead['utm_content'] ?? null,
                'Term'        => $lead['utm_term'] ?? null,
                'GCLID'       => $lead['gclid'] ?? null,
                'FBCLID'      => $lead['fbclid'] ?? null,
                'Referrer'    => $lead['referrer'] ?? null,
                'Landing page' => $lead['landing_page'] ?? null,
            ],
        ];
    }

    /**
     * The company the notification is branded as: the funnel's own name first,
     * then the global setting, then the mail-from name.
     *
     * @param array<string,mixed>|null $funnel
     */
    private function companyName(?array $funnel): string
    {
        $company = trim((string) ($funnel['company_name'] ?? ''));

        if ($company !== '') {
            return $company;
        }

        $global = trim((string) $this->settings->get('company_name', ''));

        return $global !== '' ? $global : Config::string('MAIL_FROM_NAME', 'Lead Capture');
    }

    /** Turns a stored upload path into an absolute URL for the email client. */
    private function absoluteUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '' || !str_starts_with($path, '/')) {
            return null;
        }

        $base = Config::appUrl();

        return $base !== '' ? $base . $path : null;
    }

    /** @param array<string,mixed> $data */
    private function render(string $template, array $data): string
    {
        $path = Config::basePath('templates/email/' . $template);

        if (!is_file($path)) {
            return $this->renderText($data);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;

        return (string) ob_get_clean();
    }

    /** Plain-text alternative. @param array<string,mixed> $data */
    private function renderText(array $data): string
    {
        $lead  = $data['lead'] ?? [];
        $lines = [];

        $lines[] = 'NEW LEAD #' . ($lead['id'] ?? '');
        $lines[] = str_repeat('=', 40);
        $lines[] = 'Name:      ' . ($lead['full_name'] ?? '');
        $lines[] = 'Phone:     ' . trim((string) ($lead['country_code'] ?? '') . ' ' . (string) ($lead['phone'] ?? ''));
        $lines[] = 'Normalised:' . ' ' . ($lead['phone_normalized'] ?? '—');
        $lines[] = 'Email:     ' . ($lead['email'] ?: '—');
        $lines[] = 'Language:  ' . ($lead['preferred_language'] ?: '—');
        $lines[] = 'Score:     ' . ($lead['lead_score'] ?? 0);
        $lines[] = 'Consent:   ' . (!empty($lead['consent_given']) ? 'Yes' : 'No');
        $lines[] = 'Funnel v:  ' . ($lead['funnel_version'] ?? 0);
        $lines[] = 'Submitted: ' . ($lead['submitted_at'] ?? '');
        $lines[] = '';
        $lines[] = 'ANSWERS';
        $lines[] = str_repeat('-', 40);

        foreach ($data['answers'] ?? [] as $answer) {
            $lines[] = ($answer['step_title'] ?? $answer['step_key']) . ': '
                . ($answer['answer_label'] ?? $answer['answer_value'] ?? '—');
        }

        $lines[] = '';
        $lines[] = 'ATTRIBUTION';
        $lines[] = str_repeat('-', 40);

        foreach ($data['attribution'] ?? [] as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }

        if (!empty($data['whatsappUrl'])) {
            $lines[] = '';
            $lines[] = 'WhatsApp: ' . $data['whatsappUrl'];
        }

        $lines[] = '';
        $lines[] = 'Open in dashboard: ' . ($data['adminUrl'] ?? '');

        return implode("\n", $lines);
    }
}
