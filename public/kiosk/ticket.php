<?php
/** Show the last queue ticket issued in this resident kiosk session. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
$resident = kiosk_require_resident();
$ticket = $_SESSION['kiosk_last_queue_ticket'] ?? null;
if (!is_array($ticket) || !isset($ticket['queue_number'], $ticket['service'])) { redirect('kiosk/queue.php'); }
kiosk_page_start('Your queue number', 'Your ticket has been recorded. Watch the public queue display for your number.');
echo '<section class="kiosk-card kiosk-ticket"><p class="kiosk-eyebrow">' . e((string) $ticket['service']) . '</p>'
    . '<div class="kiosk-ticket-number">#' . (int) $ticket['queue_number'] . '</div>'
    . '<p class="kiosk-ticket-caption">Please wait until this number is called.</p>'
    . '<p><strong>Resident:</strong> ' . e((string) $resident['first_name'] . ' ' . (string) $resident['last_name']) . '</p>'
    . '<p><strong>Issued:</strong> ' . e((string) ($ticket['queue_date'] ?? date('Y-m-d'))) . '</p>'
    . '<div class="kiosk-grid"><a class="kiosk-action" href="' . e(base_url('queue/display.php')) . '" target="_blank" rel="noopener"><span class="kiosk-icon">▣</span><strong>Queue display</strong><small>See the number being called</small></a>'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/index.php')) . '"><span class="kiosk-icon">⌂</span><strong>Home</strong><small>Choose another service</small></a></div></section>';
kiosk_page_end();
