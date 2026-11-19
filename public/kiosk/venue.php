<?php
/** Public resident venue reservation request and Venue queue ticket. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
$resident = kiosk_require_resident();
$venues = venue_list(true);
$errors = [];
$selectedNotificationPreference = queue_resolve_notification_preference(null, (string) ($resident['notification_preference'] ?? 'email'));

if (is_post()) {
    require_csrf();
    rate_limit_require('kiosk_venue_request', (string) $resident['resident_id'], 4, 3600,
        'Too many venue requests were submitted. Please ask Staff for help.');
    $venueId = post_int('venue_id');
    $purpose = post_str('purpose');
    $requestedPreference = post_str('notification_preference');
    if ($requestedPreference !== '' && queue_valid_notification_preference($requestedPreference)) {
        $selectedNotificationPreference = $requestedPreference;
    }
    if ($venueId === null) { $errors[] = 'Choose a venue.'; }
    if (!$errors) {
        try {
            $result = db_transaction(function () use ($resident, $venueId, $purpose, $requestedPreference): array {
                $reservation = venue_create_reservation(
                    (int) $venueId, (int) $resident['resident_id'], $purpose,
                    post_str('reservation_date'), post_str('start_time'), post_str('end_time'), null
                );
                $ticket = queue_issue_number('Venue', (int) $resident['resident_id'], $reservation['reference_no'], 'kiosk', $purpose,
                    $requestedPreference !== '' ? $requestedPreference : null, true);
                return ['reservation' => $reservation, 'ticket' => $ticket];
            });
            queue_notify_ticket_issued((int) $result['ticket']['queue_id']);
            $_SESSION['kiosk_last_venue'] = $result;
            kiosk_flash('Your venue reservation request is Pending approval.', 'success');
            redirect('kiosk/venue_receipt.php');
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('BIRMS kiosk venue request failed: ' . get_class($e));
            $errors[] = 'The reservation could not be submitted. Please ask Staff for help.';
        }
    }
}
kiosk_page_start('Reserve a venue', 'Choose a venue, date and time. Your reservation will be Pending until Staff approves it, and BIRMS will issue a Venue queue ticket automatically.');
if ($errors) { echo '<div class="kiosk-alert kiosk-alert-error">' . e(implode(' ', $errors)) . '</div>'; }
echo '<section class="kiosk-card">';
if (!$venues) { echo '<p>No venues are configured yet. Please ask Staff for assistance.</p>'; }
else {
    echo '<div class="kiosk-venue-grid">';
    foreach ($venues as $venue) {
        $state = $venue['current_state'];
        echo '<div class="kiosk-venue ' . ($state === 'in_use' ? 'is-busy' : 'is-free') . '"><strong>' . e((string) $venue['name']) . '</strong>'
            . '<span>' . ($state === 'in_use' ? 'Red · In use now' : 'Green · Available now') . '</span><small>' . e((string) ($venue['description'] ?? '')) . '</small></div>';
    }
    echo '</div>';
    echo '<form method="post" action="' . e(base_url('kiosk/venue.php')) . '" class="kiosk-form">' . csrf_field()
        . '<div class="kiosk-field"><label for="venue_id">Venue</label><select name="venue_id" id="venue_id" required><option value="">Choose a venue</option>';
    foreach ($venues as $venue) { echo '<option value="' . (int) $venue['venue_id'] . '">' . e((string) $venue['name']) . '</option>'; }
    echo '</select></div><div class="kiosk-field"><label for="purpose">Purpose</label><input type="text" name="purpose" id="purpose" maxlength="255" required></div>'
        . '<div class="kiosk-grid kiosk-time-grid"><div class="kiosk-field"><label for="reservation_date">Date</label><input type="date" name="reservation_date" id="reservation_date" min="' . e(date('Y-m-d')) . '" required></div>'
        . '<div class="kiosk-field"><label for="start_time">Start time</label><input type="time" name="start_time" id="start_time" required></div>'
        . '<div class="kiosk-field"><label for="end_time">End time</label><input type="time" name="end_time" id="end_time" required></div></div>'
        . '<div class="kiosk-field"><label for="notification_preference">How should we notify you?</label><select id="notification_preference" name="notification_preference" required>'
        . queue_notification_preference_options($selectedNotificationPreference)
        . '</select><p class="kiosk-helper">Email is the default. If SMS is unavailable, BIRMS will use your registered email when available. Watch the queue display too.</p></div>'
        . '<button class="kiosk-primary" type="submit">Submit reservation request</button></form>';
}
echo '<a class="kiosk-secondary-link" href="' . e(base_url('kiosk/index.php')) . '">Back to home</a></section>';
kiosk_page_end();
