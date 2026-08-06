<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Config;
use Lumera\Repositories\LeadRepository;

/**
 * CSV export of the currently filtered lead set.
 * Dynamic answers become columns derived from the data itself, so a funnel
 * change is reflected in the export without code changes.
 */
final class ExportService
{
    public function __construct(
        private LeadRepository $leads = new LeadRepository(),
    ) {
    }

    /**
     * Streams the CSV straight to the client.
     *
     * @param array<string,mixed> $filters
     */
    public function stream(array $filters, bool $includeRawIp = false): int
    {
        $rows      = $this->leads->forExport($filters);
        $leadIds   = array_map(static fn ($r) => (int) $r['id'], $rows);
        $answers   = $this->leads->answersForLeads($leadIds);
        $answerKeys = $this->answerColumns($answers);

        $filename = 'lumera-leads-' . date('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return 0;
        }

        // BOM so Excel reads UTF-8 (Arabic labels) correctly.
        fwrite($out, "\xEF\xBB\xBF");

        $header = [
            'Lead ID', 'Submitted At', 'Status', 'Score', 'Funnel Version',
            'Full Name', 'Country Code', 'Phone', 'Phone Normalized', 'Email',
            'Preferred Language', 'Interface Language', 'Consent', 'Consent At',
        ];

        foreach ($answerKeys as $key => $title) {
            $header[] = $title;
        }

        array_push(
            $header,
            'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Content', 'UTM Term',
            'GCLID', 'FBCLID', 'Referrer', 'Landing Page',
            'Device', 'Screen Size', 'Email Status'
        );

        if ($includeRawIp && Config::bool('STORE_RAW_IP', false)) {
            $header[] = 'IP Address';
        }

        fputcsv($out, $header);

        foreach ($rows as $lead) {
            $leadAnswers = [];

            foreach ($answers[(int) $lead['id']] ?? [] as $answer) {
                $leadAnswers[(string) $answer['step_key']] =
                    (string) ($answer['answer_label'] ?: ($answer['answer_value'] ?? ''));
            }

            $line = [
                $lead['id'],
                $lead['submitted_at'],
                $lead['status'],
                $lead['lead_score'],
                $lead['funnel_version'],
                $this->safeCell((string) $lead['full_name']),
                $this->safeCell((string) ($lead['country_code'] ?? '')),
                $this->safeCell((string) ($lead['phone'] ?? '')),
                $this->safeCell((string) ($lead['phone_normalized'] ?? '')),
                $this->safeCell((string) ($lead['email'] ?? '')),
                $lead['preferred_language'],
                $lead['interface_language'],
                $lead['consent_given'] ? 'yes' : 'no',
                $lead['consent_at'],
            ];

            foreach (array_keys($answerKeys) as $key) {
                $line[] = $this->safeCell($leadAnswers[$key] ?? '');
            }

            array_push(
                $line,
                $this->safeCell((string) ($lead['utm_source'] ?? '')),
                $this->safeCell((string) ($lead['utm_medium'] ?? '')),
                $this->safeCell((string) ($lead['utm_campaign'] ?? '')),
                $this->safeCell((string) ($lead['utm_content'] ?? '')),
                $this->safeCell((string) ($lead['utm_term'] ?? '')),
                $this->safeCell((string) ($lead['gclid'] ?? '')),
                $this->safeCell((string) ($lead['fbclid'] ?? '')),
                $this->safeCell((string) ($lead['referrer'] ?? '')),
                $this->safeCell((string) ($lead['landing_page'] ?? '')),
                $lead['device_type'],
                $lead['screen_size'],
                $lead['email_status']
            );

            if ($includeRawIp && Config::bool('STORE_RAW_IP', false)) {
                $line[] = $lead['ip_address'];
            }

            fputcsv($out, $line);
        }

        fclose($out);

        return count($rows);
    }

    /**
     * Builds the dynamic answer column set from the exported leads.
     *
     * @param array<int, list<array<string,mixed>>> $answersByLead
     * @return array<string,string> step_key => column title
     */
    private function answerColumns(array $answersByLead): array
    {
        $columns = [];

        foreach ($answersByLead as $answers) {
            foreach ($answers as $answer) {
                $key = (string) $answer['step_key'];

                if ($key === 'contact_information') {
                    continue; // already expanded into dedicated columns
                }

                if (!isset($columns[$key])) {
                    $columns[$key] = (string) ($answer['step_title'] ?: $key);
                }
            }
        }

        return $columns;
    }

    /**
     * Neutralises spreadsheet formula injection (=, +, -, @, tab, CR).
     */
    private function safeCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
