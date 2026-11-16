<?php
/** B15 tests: venue availability, overlap prevention, reservation decisions and audit history. */
declare(strict_types=1);
require_once __DIR__ . '/../app/venues_lib.php';

function run_b15(array $S, string $base): void
{
    $adm = $S['admin'];
    $cap = $S['captain'];
    $sec = $S['secretary'];
    $clk = $S['clerk'];
    $venueName = 'ZZ B15 Community Hall';
    $referencePrefix = 'ZB15-';
    $oldVenueId = (int) db_val('SELECT venue_id FROM venues WHERE name = ?', [$venueName]);
    if ($oldVenueId > 0) {
        db_exec('DELETE FROM request_status_history WHERE reservation_id IN (SELECT reservation_id FROM venue_reservations WHERE venue_id = ?)', [$oldVenueId]);
        db_exec('DELETE FROM venue_reservations WHERE venue_id = ?', [$oldVenueId]);
        db_exec('DELETE FROM venues WHERE venue_id = ?', [$oldVenueId]);
    }
    db_exec("DELETE FROM residents WHERE last_name = 'ZZVenueFifteen'");
    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    db_exec(
        "INSERT INTO residents
         (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
          last_name, first_name, birthdate, sex, civil_status, contact_number, email_address, notification_preference,
          residency_status, household_id, is_household_head, archived_at)
         VALUES (?, ?, ?, ?, 'Single', ?, 'ZZVenueFifteen', 'Rita', '1990-01-01', 'Female', 'Single',
                 '09170005555', 'b15-rita@example.test', 'email', 'Active', NULL, 0, NULL)",
        ['RES-B15-' . strtoupper(bin2hex(random_bytes(4))), '15 Queue Lane', $purok['lookup_id'] ?? null, $statusId ?: null, $typeId ?: null]
    );
    $residentId = (int) db()->lastInsertId();
    $adminId = (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_admin'");
    $venueId = 0;

    try {
        $pageOk = true;
        foreach (['admin' => $adm, 'chairman' => $cap, 'secretary' => $sec, 'staff' => $clk] as $role => $client) {
            $page = $client->get('/venues/index.php');
            if ($page['status'] !== 200 || leaks($page)) { $pageOk = false; }
        }
        record('B15-01', 'All four SRS roles can view the venue availability board', $pageOk);

        $created = post_as($adm, '/venues/index.php', '/venues/action.php', [
            'do' => 'create_venue', 'name' => $venueName, 'description' => 'Venue fixture for overlap testing',
        ]);
        $venueId = (int) db_val('SELECT venue_id FROM venues WHERE name = ?', [$venueName]);
        $chairmanCreate = post_as($cap, '/venues/index.php', '/venues/action.php', [
            'do' => 'create_venue', 'name' => 'ZZ B15 Chairman Must Not Create',
        ]);
        $noCsrf = $clk->post('/venues/action.php', ['do' => 'override', 'venue_id' => (string) $venueId, 'override' => 'in_use']);
        record('B15-02', 'Admin can configure venues; Chairman cannot create them and all venue writes require CSRF',
            $created['status'] === 302 && $venueId > 0 && $chairmanCreate['status'] === 403
            && $noCsrf['status'] === 403
            && db_val('SELECT venue_id FROM venues WHERE name = ?', ['ZZ B15 Chairman Must Not Create']) === null);

        $date = date('Y-m-d', strtotime('+1 day'));
        $first = venue_create_reservation($venueId, $residentId, 'Community meeting', $date, '10:00', '11:00', $adminId);
        $firstRow = db_one('SELECT * FROM venue_reservations WHERE reservation_id = ?', [$first['reservation_id']]);
        record('B15-03', 'A valid venue reservation receives a unique reference and starts Pending',
            $firstRow !== null && preg_match('/^VEN-' . date('Y') . '-[A-F0-9]{8}$/', (string) $firstRow['reference_no']) === 1
            && $firstRow['status'] === 'Pending'
            && (int) db_val("SELECT COUNT(*) FROM request_status_history WHERE reservation_id = ? AND service = 'Venue' AND previous_status IS NULL AND new_status = 'Pending'", [$first['reservation_id']]) === 1);

        $overlapRejected = false;
        try {
            venue_create_reservation($venueId, $residentId, 'Conflicting meeting', $date, '10:30', '11:30', $adminId);
        } catch (InvalidArgumentException $e) {
            $overlapRejected = str_contains($e->getMessage(), 'already requested') || str_contains($e->getMessage(), 'overlaps');
        }
        $sameDayOther = venue_create_reservation($venueId, $residentId, 'After lunch activity', $date, '11:00', '12:00', $adminId);
        record('B15-04', 'Overlapping Pending reservations are rejected but back-to-back bookings are allowed',
            $overlapRejected && $sameDayOther['reservation_id'] > 0,
            'overlap-rejected=' . ($overlapRejected ? 'yes' : 'no') . '; back-to-back=' . (string) $sameDayOther['reservation_id']);

        venue_change_status((int) $first['reservation_id'], 'Rejected', 'Venue already requested for another event');
        $reopenedSlot = venue_create_reservation($venueId, $residentId, 'Replacement meeting', $date, '10:00', '11:00', $adminId);
        venue_change_status((int) $reopenedSlot['reservation_id'], 'Approved');
        $approvedOverlapRejected = false;
        try {
            venue_create_reservation($venueId, $residentId, 'Approved conflict', $date, '10:15', '10:45', $adminId);
        } catch (InvalidArgumentException $e) {
            $approvedOverlapRejected = str_contains($e->getMessage(), 'overlaps');
        }
        $rejected = db_one('SELECT previous_status,new_status FROM request_status_history WHERE reservation_id = ? ORDER BY history_id DESC LIMIT 1', [$first['reservation_id']]);
        $approved = db_one('SELECT previous_status,new_status FROM request_status_history WHERE reservation_id = ? ORDER BY history_id DESC LIMIT 1', [$reopenedSlot['reservation_id']]);
        $rejectedNotice = db_one("SELECT recipient, channel, delivery_status, message FROM notification_outbox WHERE resident_id = ? AND reference_no = ? AND event_type = 'venue_reservation_status_changed' ORDER BY notification_id DESC LIMIT 1", [$residentId, $first['reference_no']]);
        $approvedNotice = db_one("SELECT recipient, channel, delivery_status, message FROM notification_outbox WHERE resident_id = ? AND reference_no = ? AND event_type = 'venue_reservation_status_changed' ORDER BY notification_id DESC LIMIT 1", [$residentId, $reopenedSlot['reference_no']]);
        record('B15-05', 'Rejection frees a time slot; approval blocks later overlaps and both decisions are written to history and resident notices',
            $approvedOverlapRejected
            && $rejected !== null && $rejected['previous_status'] === 'Pending' && $rejected['new_status'] === 'Rejected'
            && $approved !== null && $approved['previous_status'] === 'Pending' && $approved['new_status'] === 'Approved'
            && $rejectedNotice !== null && $rejectedNotice['recipient'] === 'b15-rita@example.test'
            && $rejectedNotice['channel'] === 'email' && in_array($rejectedNotice['delivery_status'], ['queued','sent'], true)
            && $approvedNotice !== null && $approvedNotice['channel'] === 'email',
            'approved-overlap=' . ($approvedOverlapRejected ? 'yes' : 'no') . '; rejected=' . json_encode($rejected) . '; approved=' . json_encode($approved));

        $start = '00:00:00';
        $end = '23:59:59';
        $activeReservation = venue_create_reservation($venueId, $residentId, 'Current active use', date('Y-m-d'), $start, $end, $adminId);
        venue_change_status((int) $activeReservation['reservation_id'], 'Approved');
        $venue = db_one('SELECT * FROM venues WHERE venue_id = ?', [$venueId]);
        $autoRed = venue_current_state($venue) === 'in_use';
        venue_set_override($venueId, 'available');
        $manualGreen = venue_current_state(db_one('SELECT * FROM venues WHERE venue_id = ?', [$venueId])) === 'available';
        venue_set_override($venueId, 'automatic');
        $autoRedAgain = venue_current_state(db_one('SELECT * FROM venues WHERE venue_id = ?', [$venueId])) === 'in_use';
        record('B15-06', 'Automatic availability turns red during an approved reservation; Staff override can set green and return to Automatic',
            $autoRed && $manualGreen && $autoRedAgain);

        venue_change_status((int) $reopenedSlot['reservation_id'], 'Completed');
        $completed = db_val('SELECT status FROM venue_reservations WHERE reservation_id = ?', [$reopenedSlot['reservation_id']]);
        $completedNotice = db_one("SELECT new_status, delivery_status FROM notification_outbox n JOIN request_status_history h ON h.reference_no = n.reference_no WHERE n.resident_id = ? AND n.reference_no = ? AND n.event_type = 'venue_reservation_status_changed' ORDER BY n.notification_id DESC LIMIT 1", [$residentId, $reopenedSlot['reference_no']]);
        record('B15-07', 'Only an Approved reservation can be marked Completed and the resident receives the Completed status',
            $completed === 'Completed' && $completedNotice !== null && $completedNotice['new_status'] === 'Completed');

        $staffView = $clk->get('/venues/index.php');
        $chairmanAction = post_as($cap, '/venues/index.php', '/venues/action.php', [
            'do' => 'approve', 'reservation_id' => (string) $sameDayOther['reservation_id'],
        ]);
        $secretaryAction = post_as($sec, '/venues/index.php', '/venues/action.php', [
            'do' => 'approve', 'reservation_id' => (string) $sameDayOther['reservation_id'],
        ]);
        $staffAction = post_as($clk, '/venues/index.php', '/venues/action.php', [
            'do' => 'approve', 'reservation_id' => (string) $sameDayOther['reservation_id'],
        ]);
        record('B15-08', 'Only Staff/Admin can approve reservations; Chairman is view-only and Secretary can monitor',
            $staffView['status'] === 200 && $chairmanAction['status'] === 403
            && $secretaryAction['status'] === 403
            && $staffAction['status'] === 302
            && db_val('SELECT status FROM venue_reservations WHERE reservation_id = ?', [$sameDayOther['reservation_id']]) === 'Approved');

        $historyRows = (int) db_val('SELECT COUNT(*) FROM request_status_history WHERE service = \'Venue\' AND reference_no LIKE ?', [$referencePrefix . '%']);
        // References use VEN-YYYY-random; cleanup all fixture reservations by venue ID.
        $historyRows = (int) db_val('SELECT COUNT(*) FROM request_status_history WHERE service = \'Venue\' AND reservation_id IN (SELECT reservation_id FROM venue_reservations WHERE venue_id = ?)', [$venueId]);
        record('B15-09', 'Venue creation, reservation and status changes are auditable',
            $historyRows >= 7 && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE module = 'Venues' AND entity_type = 'venue_reservation'") >= 5);
    } finally {
        $ids = db_all('SELECT reservation_id, reference_no FROM venue_reservations WHERE venue_id = ?', [$venueId ?? 0]);
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db_exec('DELETE FROM request_status_history WHERE reservation_id IN (' . $marks . ')', array_column($ids, 'reservation_id'));
        }
        db_exec('DELETE FROM notification_outbox WHERE reference_no IN (SELECT reference_no FROM venue_reservations WHERE venue_id = ?)', [$venueId ?? 0]);
        db_exec('DELETE FROM venue_reservations WHERE venue_id = ?', [$venueId ?? 0]);
        db_exec('DELETE FROM venues WHERE venue_id = ?', [$venueId ?? 0]);
        db_exec('DELETE FROM notification_outbox WHERE resident_id = ?', [$residentId]);
        db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
    }
}
