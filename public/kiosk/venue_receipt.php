<?php
/** Receipt for kiosk venue reservation and Venue queue ticket. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
kiosk_require_resident();
$result = $_SESSION['kiosk_last_venue'] ?? null;
if (!is_array($result) || empty($result['reservation']['reference_no']) || empty($result['ticket']['queue_number'])) { redirect('kiosk/venue.php'); }
kiosk_page_start('Venue request submitted', 'Your reservation request is saved as Pending approval.');
echo '<section class="kiosk-card kiosk-ticket"><p class="kiosk-eyebrow">VENUE RESERVATION · PENDING</p>'
    . '<div class="kiosk-reference">' . e((string) $result['reservation']['reference_no']) . '</div>'
    . '<p>Your Venue queue ticket is:</p><div class="kiosk-ticket-number">#' . (int) $result['ticket']['queue_number'] . '</div>'
    . '<p class="kiosk-ticket-caption">Staff will review availability and approve or reject the requested time.</p>'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/status.php?ref=' . rawurlencode((string) $result['reservation']['reference_no']))) . '"><span class="kiosk-icon">✓</span><strong>Check reservation status</strong></a></section>';
kiosk_page_end();
