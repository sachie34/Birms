<?php
/** Receipt for a kiosk-submitted document request and its Documents queue ticket. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
kiosk_require_resident();
$result = $_SESSION['kiosk_last_request'] ?? null;
if (!is_array($result) || empty($result['reference_no']) || empty($result['ticket']['queue_number'])) { redirect('kiosk/request.php'); }
kiosk_page_start('Request submitted', 'Your request is saved. Keep this reference number to check its status.');
echo '<section class="kiosk-card kiosk-ticket"><p class="kiosk-eyebrow">DOCUMENT REQUEST · PENDING</p>'
    . '<div class="kiosk-reference">' . e((string) $result['reference_no']) . '</div>'
    . '<p>Your Documents queue ticket is:</p><div class="kiosk-ticket-number">#' . (int) $result['ticket']['queue_number'] . '</div>'
    . '<p class="kiosk-ticket-caption">The Secretary will review your requirements. Staff will make the final decision after review.</p>'
    . '<div class="kiosk-grid"><a class="kiosk-action" href="' . e(base_url('kiosk/status.php?ref=' . rawurlencode((string) $result['reference_no']))) . '"><span class="kiosk-icon">✓</span><strong>Check status</strong><small>Track this request</small></a>'
    . '<a class="kiosk-action" href="' . e(base_url('queue/display.php')) . '" target="_blank" rel="noopener"><span class="kiosk-icon">▣</span><strong>Queue display</strong><small>Wait for your number</small></a></div></section>';
kiosk_page_end();
