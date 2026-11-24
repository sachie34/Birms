<?php
/** Resident receipt for a submitted blotter report and its queue ticket. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

kiosk_require_resident();
$result = $_SESSION['kiosk_last_blotter_request'] ?? null;
if (!is_array($result) || empty($result['reference_no']) || empty($result['ticket']['queue_number'])) {
    redirect('kiosk/blotter.php');
}
kiosk_page_start('Blotter report submitted', 'Your report is recorded. Keep the reference number to check the case status.');
echo '<section class="kiosk-card kiosk-ticket"><p class="kiosk-eyebrow">BLOTTER REPORT · ONGOING</p>'
    . '<div class="kiosk-reference">' . e((string) $result['reference_no']) . '</div>'
    . '<p>Your Blotter queue ticket is:</p><div class="kiosk-ticket-number">#' . (int) $result['ticket']['queue_number'] . '</div>'
    . '<p class="kiosk-ticket-caption">Your report is waiting for Staff review. Keep this reference number for follow-up.</p>'
    . '<div class="kiosk-grid">'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/status.php?ref=' . rawurlencode((string) $result['reference_no']))) . '"><span class="kiosk-icon">✓</span><strong>Check status</strong><small>Track the blotter report</small></a>'
    . '<a class="kiosk-action" href="' . e(base_url('queue/display.php')) . '" target="_blank" rel="noopener"><span class="kiosk-icon">▣</span><strong>Queue display</strong><small>Wait for your number</small></a>'
    . '</div></section>';
kiosk_page_end();
