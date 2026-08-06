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
     * @return array{ok: bool, error?: string, skipped?: bool}
     */
    public function send(array $lead, array $answers): array
    {
        $recipient = Config::string('LEAD_RECIPIENT_EMAIL', '');

        if ($recipient === '') {
            return ['ok' => false, 'skipped' => true, 'error' => 'LEAD_RECIPIENT_EMAIL is not set.'];
        }

        if (!$this->mailer->isConfigured()) {
            return ['ok' => false, 'skipped' => true, 'error' => 'SMTP is not configured.'];
        }

        $recipients = array_map('trim', explode(',', $recipient));

        $view = $this->viewData($lead, $answers);

        return $this->mailer->send(
            $recipients,
            $this->subject($lead, $answers),
            $this->render('lead-notification.php', $view),
            $this->renderText($view),
            is_string($lead['email'] ?? null) ? $lead['email'] : null,
            (string) ($lead['full_name'] ?? '')
        );
    }

    /**
     * @param array<string,mixed> $lead
     * @param list<array<string,mixed>> $answers
     */
    public function subject(array $lead, array $answers): string
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
            '{funnel}'    => (string) ($lead['funnel_name'] ?? 'Lumera'),
        ]);

        return Str::clean($subject, 190);
    }

    /**
     * @param array<string,mixed> $lead
     * @param list<array<string,mixed>> $answers
     * @return array<string,mixed>
     */
    private function viewData(array $lead, array $answers): array
    {
        $normalized = (string) ($lead['phone_normalized'] ?? '');
        $digits     = Phone::whatsappDigits($normalized);

        $whatsappMessage = sprintf(
            'Hello %s, this is Lumera Dubai Real Estate following up on your property enquiry.',
            (string) ($lead['full_name'] ?? '')
        );

        return [
            'lead'      => $lead,
            'answers'   => $answers,
            'company'   => (string) $this->settings->get('company_name', 'Lumera Dubai Real Estate'),
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
