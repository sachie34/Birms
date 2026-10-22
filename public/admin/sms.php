<?php
/** Protected Semaphore delivery log, status refresh and explicit retry for confirmed failures. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('settings.edit');

if (is_post()) {
    require_csrf();
    $action = post_str('do');
    $id = post_int('sms_id');
    if ($id === null || $id < 1) {
        flash_add('danger', 'Choose a valid SMS record.');
        redirect('admin/sms.php');
    }

    if ($action === 'retry') {
        $result = sms_send_outbox($id, true);
    } elseif ($action === 'refresh') {
        $result = sms_refresh_outbox_status($id);
    } else {
        abort(400, 'Unknown SMS action.');
    }

    $status = (string) ($result['status'] ?? 'unknown');
    $tone = !empty($result['ok']) ? 'success'
        : (in_array($status, ['not_configured', 'not_found'], true) ? 'info' : 'warning');
    flash_add($tone, (string) ($result['message'] ?? 'SMS action completed.'));
    redirect('admin/sms.php');
}

$summary = sms_provider_status_summary();
$counts = [];
foreach (db_all('SELECT provider_status, COUNT(*) AS total FROM sms_outbox GROUP BY provider_status') as $c) {
    $counts[(string) $c['provider_status']] = (int) $c['total'];
}
$rows = db_all(
    'SELECT s.sms_id, s.created_at, s.recipient_number, s.message, s.trigger_event, s.request_id,
            s.provider_name, s.provider_message_id, s.provider_status, s.provider_error,
            s.attempt_count, s.last_attempt_at, r.reference_no
     FROM sms_outbox s
     LEFT JOIN document_requests r ON r.request_id = s.request_id
     ORDER BY s.sms_id DESC
     LIMIT 100'
);

render_header('SMS Delivery', [['Settings', 'admin/settings.php?tab=sms'], ['SMS Delivery', null]], 'sms');
page_head('SMS Delivery', 'Semaphore delivery history, provider statuses and controlled retry for confirmed failures.');
if ($summary['enabled']) {
    echo '<div class="alert alert-success"><strong>Semaphore enabled.</strong> ' . e($summary['reason']) . ' Requests marked Ready with a valid mobile number may incur SMS charges.</div>';
} else {
    echo '<div class="alert alert-secondary"><strong>Semaphore is not sending messages.</strong> ' . e($summary['reason']) . '. BIRMS will keep readiness notifications in the outbox until the provider is explicitly enabled and configured.</div>';
}
echo '<div class="alert alert-warning">Queued or pending means the provider has accepted the message for processing, not that the resident has read it. If a result is <strong>Unknown</strong>, check the provider portal before any retry because the SMS may already have been accepted. Only confirmed Failed/Refunded messages can be retried here.</div>';

card_start('Outbox overview', 'Status totals are calculated from saved SMS outbox records.');
echo '<div class="row g-3">';
foreach ([
    'not_configured' => 'Log only',
    'no_number' => 'No number',
    'queued' => 'Queued',
    'pending' => 'Pending',
    'sent' => 'Sent',
    'failed' => 'Failed',
    'refunded' => 'Refunded',
    'unknown' => 'Unknown',
] as $key => $label) {
    echo '<div class="col-6 col-md-3 col-xl-2"><div class="metric"><div class="metric-label">' . e($label) . '</div><div class="metric-value">' . (int) ($counts[$key] ?? 0) . '</div></div></div>';
}
echo '</div>';
card_end();

card_start('Recent SMS records', 'The latest 100 records. Personal recipient numbers and message text are visible only to users with Settings edit permission.');
if (!$rows) {
    echo empty_state('bi-chat-dots', 'No SMS outbox records have been created yet.');
} else {
    echo '<div class="table-responsive"><table class="table table-b align-middle"><thead><tr><th>Created</th><th>Request</th><th>Recipient</th><th>Message</th><th>Provider status</th><th>Attempts</th><th>Last attempt</th><th>Action</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $status = strtolower((string) $r['provider_status']);
        $label = sms_provider_status_label($status);
        $tone = sms_provider_status_tone($status);
        $msg = mb_substr_safe((string) $r['message'], 105);
        if (str_len((string) $r['message']) > 105) { $msg .= '…'; }
        echo '<tr>'
            . '<td class="text-nowrap">' . e(fmt_dt((string) $r['created_at'])) . '</td>'
            . '<td>' . e((string) ($r['reference_no'] ?? ($r['request_id'] !== null ? 'Request #' . $r['request_id'] : '—'))) . '</td>'
            . '<td class="text-nowrap">' . e((string) ($r['recipient_number'] ?? '—')) . '</td>'
            . '<td><span title="' . e((string) $r['message']) . '">' . e($msg) . '</span>'
            . ($r['provider_error'] !== null && $r['provider_error'] !== '' ? '<div class="small text-danger mt-1">' . e((string) $r['provider_error']) . '</div>' : '') . '</td>'
            . '<td>' . pill($label, $tone) . ($r['provider_message_id'] !== null ? '<div class="small text-muted">Provider ID: ' . e((string) $r['provider_message_id']) . '</div>' : '') . '</td>'
            . '<td>' . (int) $r['attempt_count'] . '</td>'
            . '<td class="text-nowrap">' . e($r['last_attempt_at'] !== null ? fmt_dt((string) $r['last_attempt_at']) : '—') . '</td>'
            . '<td class="text-nowrap">';
        if ($summary['enabled'] && in_array($status, ['queued', 'pending', 'sent', 'failed', 'refunded'], true) && (string) ($r['provider_message_id'] ?? '') !== '') {
            echo '<form method="post" action="' . e(base_url('admin/sms.php')) . '" class="d-inline">'
                . csrf_field() . '<input type="hidden" name="do" value="refresh"><input type="hidden" name="sms_id" value="' . (int) $r['sms_id'] . '">'
                . '<button class="btn btn-sm btn-light border mb-1" type="submit">Refresh</button></form> ';
        }
        if ($summary['enabled'] && in_array($status, ['failed', 'refunded'], true)) {
            echo '<form method="post" action="' . e(base_url('admin/sms.php')) . '" class="d-inline" data-confirm="Retry this SMS? This may incur another SMS charge.">'
                . csrf_field() . '<input type="hidden" name="do" value="retry"><input type="hidden" name="sms_id" value="' . (int) $r['sms_id'] . '">'
                . '<button class="btn btn-sm btn-outline-danger mb-1" type="submit">Retry</button></form>';
        } elseif ($status === 'unknown') {
            echo '<span class="small text-muted">Verify first</span>';
        } elseif (in_array($status, ['not_configured', 'no_number'], true)) {
            echo '<span class="small text-muted">Not sent</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
card_end();
echo '<p class="small text-muted">Status refresh calls Semaphore only for records with a provider message ID. Retry is manual and restricted to provider-confirmed Failed/Refunded results. BIRMS never auto-retries an Unknown result.</p>';
render_footer();
