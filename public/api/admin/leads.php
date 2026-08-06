<?php

declare(strict_types=1);

/**
 * GET /api/admin/leads.php
 *
 * Query: search, status, date_from, date_to, source, campaign, budget,
 *        purpose, page, per_page
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\Config;
use Lumera\Core\Response;
use Lumera\Repositories\LeadRepository;
use Lumera\Support\Str;

AdminEndpoint::read(dirname(__DIR__, 3));

$leads = new LeadRepository();

$filters = [
    'search'    => Str::clean($_GET['search'] ?? '', 100),
    'status'    => Str::clean($_GET['status'] ?? '', 20),
    'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? '')) === 1 ? (string) $_GET['date_from'] : '',
    'date_to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? '')) === 1 ? (string) $_GET['date_to'] : '',
    'source'    => Str::clean($_GET['source'] ?? '', 190),
    'campaign'  => Str::clean($_GET['campaign'] ?? '', 190),
    'budget'    => Str::clean($_GET['budget'] ?? '', 64),
    'purpose'   => Str::clean($_GET['purpose'] ?? '', 64),
    'include_deleted' => ($_GET['include_archived'] ?? '') === '1',
];

$page    = max(1, AdminEndpoint::intParam($_GET, 'page', 1));
$perPage = min(100, max(5, AdminEndpoint::intParam($_GET, 'per_page', 25)));

$result   = $leads->paginate($filters, $page, $perPage);
$leadIds  = array_map(static fn ($r) => (int) $r['id'], $result['rows']);
$answers  = $leads->answersForLeads($leadIds);
$storeRaw = Config::bool('STORE_RAW_IP', false);

$rows = [];

foreach ($result['rows'] as $lead) {
    $summary = [];

    foreach ($answers[(int) $lead['id']] ?? [] as $answer) {
        if (($answer['step_type'] ?? '') === 'contact_information') {
            continue;
        }

        $summary[(string) $answer['step_key']] = (string) ($answer['answer_label'] ?: ($answer['answer_value'] ?? ''));
    }

    if (!$storeRaw) {
        unset($lead['ip_address']);
    }

    unset($lead['ip_hash'], $lead['submission_hash']);

    $lead['answers_summary'] = $summary;
    $rows[] = $lead;
}

Response::success([
    'leads' => $rows,
    'pagination' => [
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $result['total'],
        'pages'    => (int) ceil($result['total'] / $perPage),
    ],
    'filters' => [
        'statuses'  => LeadRepository::STATUSES,
        'sources'   => $leads->distinctValues('utm_source'),
        'campaigns' => $leads->distinctValues('utm_campaign'),
    ],
]);
