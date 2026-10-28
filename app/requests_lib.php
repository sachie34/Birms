<?php
/** Document request rules: who may move a request to which status, reference numbers, SMS log. */
declare(strict_types=1);

/** current status => [new status => permission needed]. Ready -> Claimed happens only when a certificate is issued (B5). */
const REQUEST_TRANSITIONS = [
    'Pending' => ['Review' => 'requests.review', 'Rejected' => 'requests.approve', 'Cancelled' => 'requests.edit'],
    'Review'  => ['Ready' => 'requests.approve', 'Rejected' => 'requests.approve', 'Cancelled' => 'requests.edit'],
];
const REQUEST_STATUSES = ['Pending', 'Review', 'Ready', 'Claimed', 'Rejected', 'Cancelled'];

function request_allowed_moves(string $status): array
{
    $out = [];
    foreach (REQUEST_TRANSITIONS[$status] ?? [] as $to => $perm) { if (can($perm)) { $out[] = $to; } }
    return $out;
}
function new_request_reference(): string
{
    $y = (int) date('Y');
    return sprintf('REQ-%d-%04d', $y, next_counter('request', $y));
}

/** Create the outbox record inside the request transaction. The network send happens after commit. */
function sms_log_ready(array $req): int
{
    $default = 'Your request {{reference_no}} ({{document_name}}) is ready for pickup at the barangay office.';
    $msg = render_template((string) setting('sms_ready_text', $default), ['reference_no' => $req['reference_no'], 'document_name' => $req['type_name']]);
    $number = trim((string) ($req['contact_number'] ?? ''));
    $delivery = $number === '' ? 'No number' : 'Logged only'; // preserve legacy display behavior
    $enabled = sms_provider_is_enabled();
    $providerStatus = $number === '' ? 'no_number' : ($enabled ? 'not_attempted' : 'not_configured');
    $providerName = ($number !== '' && $enabled) ? sms_provider_selected_name() : null;
    db_exec('INSERT INTO sms_outbox (recipient_number, message, trigger_event, request_id, delivery, provider_name, provider_status) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$number === '' ? null : $number, mb_substr_safe($msg, 480), 'request_ready', (int) $req['request_id'], $delivery, $providerName, $providerStatus]);
    return (int) db()->lastInsertId();
}
