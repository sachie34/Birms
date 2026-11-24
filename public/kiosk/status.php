<?php
/** Verified resident status lookup. A reference is visible only to its owning resident session. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

rate_limit_require('kiosk_status_ip', client_ip(), 20, 600,
    'Too many status lookups from this device. Please wait 10 minutes.');
$resident = kiosk_require_resident();
$reference = strtoupper(get_str('ref'));
$result = null;
$history = [];
if ($reference !== '') {
    if (preg_match('/^[A-Z0-9-]{4,32}$/', $reference) !== 1) {
        $reference = '';
    } else {
        $doc = db_one('SELECT reference_no, msit_status AS status, requested_at AS created_at FROM document_requests WHERE reference_no = ? AND resident_id = ?', [$reference, (int) $resident['resident_id']]);
        if ($doc !== null) {
            $result = ['service' => 'Documents', 'reference_no' => $doc['reference_no'], 'status' => $doc['status'], 'created_at' => $doc['created_at']];
        } else {
            $venue = db_one('SELECT reference_no, status, created_at FROM venue_reservations WHERE reference_no = ? AND resident_id = ?', [$reference, (int) $resident['resident_id']]);
            if ($venue !== null) {
                $result = ['service' => 'Venue', 'reference_no' => $venue['reference_no'], 'status' => $venue['status'], 'created_at' => $venue['created_at']];
            } else {
                $blotterRequest = db_one(
                    'SELECT reference_no, msit_status AS status, submitted_at AS created_at
                     FROM resident_blotter_requests WHERE reference_no = ? AND resident_id = ?',
                    [$reference, (int) $resident['resident_id']]
                );
                if ($blotterRequest !== null) {
                    $result = ['service' => 'Blotter', 'reference_no' => $blotterRequest['reference_no'], 'status' => $blotterRequest['status'], 'created_at' => $blotterRequest['created_at']];
                }
            }
        }
        if ($result !== null) {
            $history = db_all(
                'SELECT previous_status, new_status, changed_at FROM request_status_history
                 WHERE reference_no = ? AND (previous_status IS NULL OR previous_status <> new_status)
                 ORDER BY history_id ASC LIMIT 30',
                [$reference]
            );
        }
        if ($result === null) {
            audit_best_effort('kiosk_status_not_found', 'Kiosk', 'Failed', 'A public request reference lookup did not match a record.');
        }
    }
}
kiosk_page_start('Check request status', 'Verify your identity first, then enter the reference number printed on your receipt. Only your own service status is shown.');
echo '<section class="kiosk-card"><form method="get" action="' . e(base_url('kiosk/status.php')) . '" class="kiosk-form">'
    . '<div class="kiosk-field"><label for="ref">Reference number</label><input id="ref" name="ref" value="' . e($reference) . '" maxlength="32" required></div>'
    . '<button class="kiosk-primary" type="submit">Check status</button></form>';
if ($reference !== '' && $result === null) {
    echo '<div class="kiosk-alert kiosk-alert-warning">No request was found with that reference. Check the number or ask Staff for help.</div>';
} elseif ($result !== null) {
    echo '<div class="kiosk-status-card"><p class="kiosk-eyebrow">' . e((string) $result['service']) . '</p>'
        . '<div class="kiosk-reference">' . e((string) $result['reference_no']) . '</div>'
        . '<div class="kiosk-status-value">' . e((string) $result['status']) . '</div>'
        . '<small>Submitted: ' . e(date('d M Y h:i A', strtotime((string) $result['created_at']))) . '</small>';
    if ($history) {
        echo '<h2 class="kiosk-history-title">Status history</h2><ol class="kiosk-history">';
        foreach ($history as $item) {
            echo '<li><strong>' . e((string) $item['new_status']) . '</strong><small>' . e(date('d M Y h:i A', strtotime((string) $item['changed_at']))) . '</small></li>';
        }
        echo '</ol>';
    }
    echo '</div>';
}
echo '<a class="kiosk-secondary-link" href="' . e(base_url('kiosk/index.php')) . '">Back to home</a></section>';
kiosk_page_end();
