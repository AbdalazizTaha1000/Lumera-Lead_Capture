<?php

declare(strict_types=1);

/** GET /api/admin/lead-details.php?id=123 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\Config;
use Lumera\Core\Response;
use Lumera\Repositories\LeadRepository;
use Lumera\Support\Phone;

AdminEndpoint::read(dirname(__DIR__, 3));

$leadId = AdminEndpoint::intParam($_GET, 'id');

if ($leadId <= 0) {
    Response::error('A lead id is required.', 400);
}

$repo = new LeadRepository();
$lead = $repo->find($leadId, true);

if ($lead === null) {
    Response::error('Lead not found.', 404);
}

// The raw IP is only ever returned when the operator explicitly enabled storing it.
if (!Config::bool('STORE_RAW_IP', false)) {
    unset($lead['ip_address']);
}

unset($lead['submission_hash']);

// The hash is a pseudonymous identifier; expose only a short prefix.
if (isset($lead['ip_hash'])) {
    $lead['ip_hash'] = substr((string) $lead['ip_hash'], 0, 12) . '…';
}

$answers = $repo->answers($leadId);
$notes   = $repo->notes($leadId);

$contactDetail = [];

foreach ($answers as $answer) {
    if (($answer['step_type'] ?? '') === 'contact_information' && !empty($answer['answer_json'])) {
        $decoded = json_decode((string) $answer['answer_json'], true);

        if (is_array($decoded)) {
            $contactDetail = $decoded;
        }
    }
}

$digits = Phone::whatsappDigits((string) ($lead['phone_normalized'] ?? ''));

$timeline = [
    ['label' => 'Submitted', 'at' => $lead['submitted_at']],
];

if (!empty($lead['consent_at'])) {
    $timeline[] = ['label' => 'Consent recorded', 'at' => $lead['consent_at']];
}

if (!empty($lead['email_sent_at'])) {
    $timeline[] = ['label' => 'Notification sent', 'at' => $lead['email_sent_at']];
}

if (($lead['email_status'] ?? '') === 'failed') {
    $timeline[] = ['label' => 'Notification failed', 'at' => $lead['updated_at']];
}

foreach ($notes as $note) {
    $timeline[] = ['label' => 'Note added', 'at' => $note['created_at']];
}

if (!empty($lead['deleted_at'])) {
    $timeline[] = ['label' => 'Archived', 'at' => $lead['deleted_at']];
}

usort($timeline, static fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

Response::success([
    'lead'           => $lead,
    'answers'        => $answers,
    'notes'          => $notes,
    'contact_detail' => $contactDetail,
    'timeline'       => $timeline,
    'whatsapp_url'   => $digits !== '' ? 'https://wa.me/' . $digits : null,
    'statuses'       => LeadRepository::STATUSES,
]);
