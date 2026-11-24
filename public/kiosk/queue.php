<?php
/** Resident kiosk queue ticket issue for the three independent services. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
$resident = kiosk_require_resident();

if (is_post()) {
    require_csrf();
    rate_limit_require('kiosk_queue_resident', (string) $resident['resident_id'], 8, 120,
        'You have requested several queue tickets. Please wait before trying again.');
    $service = post_str('service');
    $purpose = post_str('purpose');
    $legacyPreference = post_str('notification_preference');
    if ($legacyPreference !== '' && !queue_valid_notification_preference($legacyPreference)) {
        kiosk_flash('Notification channels are no longer selectable. BIRMS uses email only.', 'error');
        redirect('kiosk/queue.php');
    }
    if (!queue_valid_service($service)) {
        kiosk_flash('Choose Blotter, Documents or Venue.', 'error');
        redirect('kiosk/queue.php');
    }
    try {
        $ticket = queue_issue_number($service, (int) $resident['resident_id'], null, 'kiosk', $purpose,
            null);
        $_SESSION['kiosk_last_queue_ticket'] = $ticket;
        kiosk_flash('Your ' . $service . ' queue number is ' . (int) $ticket['queue_number'] . '. Please watch the public queue display.', 'success');
        redirect('kiosk/ticket.php');
    } catch (Throwable $e) {
        error_log('BIRMS kiosk queue issue failed: ' . get_class($e));
        kiosk_flash('The queue ticket could not be issued. Please ask Staff for help.', 'error');
        redirect('kiosk/queue.php');
    }
}
kiosk_page_start('Get a queue number', 'Use this for a walk-in service visit when you are not submitting a request online. Document, blotter and venue request forms issue their matching queue ticket automatically.');
echo '<section class="kiosk-card"><form method="post" action="' . e(base_url('kiosk/queue.php')) . '" class="kiosk-form">' . csrf_field()
    . '<div class="kiosk-field"><label for="service">Service</label><select id="service" name="service" required><option value="">Choose a service</option>';
foreach (QUEUE_SERVICES as $service) {
    echo '<option value="' . e($service) . '">' . e($service) . '</option>';
}
echo '</select></div>'
    . '<div class="kiosk-field"><label for="purpose">Purpose of visit</label><input type="text" id="purpose" name="purpose" maxlength="255" required></div>'
    . '<button class="kiosk-primary" type="submit">Get queue number</button></form>'
    . '<a class="kiosk-secondary-link" href="' . e(base_url('kiosk/index.php')) . '">Back to home</a></section>';
kiosk_page_end();
