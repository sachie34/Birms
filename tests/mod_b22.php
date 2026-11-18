<?php
/** B22: resident-facing channel choice is saved and honored across queue notifications. */
declare(strict_types=1);
require_once __DIR__ . '/../app/queue_lib.php';

function run_b22(array $S, string $base): void
{
    $residentNo = 'RES-B22-CHOICE';
    $purokLabel = 'ZZ B22 Notification Purok';
    $venueName = 'ZZ B22 Notification Venue';
    $email = 'b22-queue-choice@example.test';
    $phone = '09179992222';
    $residentId = null;
    $venueId = null;
    $queueStartId = (int) (db_val('SELECT COALESCE(MAX(queue_id), 0) FROM queue_entries') ?? 0);

    db_exec("DELETE FROM residents WHERE resident_no = ?", [$residentNo]);
    db_exec("DELETE FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
    db_exec("DELETE FROM venues WHERE name = ?", [$venueName]);

    try {
        db_exec("INSERT INTO lookups (list_key, label, sort_order, is_active) VALUES ('purok', ?, 9995, 1)", [$purokLabel]);
        $purokId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
        $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
        $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");

        db_exec(
            "INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              contact_number, email_address, notification_preference, last_name, first_name,
              birthdate, sex, civil_status, residency_status, household_id, is_household_head, archived_at)
             VALUES (?, '22 Queue Choice Street', ?, ?, 'Single', ?, ?, ?, 'email',
                     'QueueChoice', 'Resident', '1991-04-18', 'Female', 'Single', 'Active', NULL, 0, NULL)",
            [$residentNo, $purokId, $statusId, $typeId, $phone, $email]
        );
        $residentId = (int) db()->lastInsertId();

        db_exec(
            "INSERT INTO venues (name, description, availability_override, is_active)
             VALUES (?, 'B22 temporary venue for kiosk form testing', 'automatic', 1)",
            [$venueName]
        );
        $venueId = (int) db()->lastInsertId();

        // Verify through the same public Resident ID + birthdate flow used at the kiosk.
        $kiosk = new Http($base);
        $identify = $kiosk->post('/kiosk/identify.php', [
            '_csrf' => $kiosk->token('/kiosk/index.php'),
            'resident_no' => $residentNo,
            'birthdate' => '1991-04-18',
        ]);
        $home = $kiosk->get('/kiosk/index.php');
        $verified = $identify['status'] === 302
            && $home['status'] === 200
            && str_contains($home['body'], 'IDENTITY VERIFIED');

        $forms = [
            '/kiosk/queue.php' => $kiosk->get('/kiosk/queue.php'),
            '/kiosk/request.php' => $kiosk->get('/kiosk/request.php'),
            '/kiosk/blotter.php' => $kiosk->get('/kiosk/blotter.php'),
            '/kiosk/venue.php' => $kiosk->get('/kiosk/venue.php'),
        ];
        $formsOfferChoice = $verified;
        foreach ($forms as $page) {
            $formsOfferChoice = $formsOfferChoice
                && $page['status'] === 200
                && str_contains($page['body'], 'name="notification_preference"')
                && str_contains($page['body'], 'option value="email"')
                && str_contains($page['body'], 'option value="sms"')
                && str_contains($page['body'], 'option value="both"');
        }
        record(
            'B22-01',
            'Resident kiosk queue, document, blotter and venue forms let the resident choose Email, SMS or Both',
            $formsOfferChoice,
            $formsOfferChoice ? 'all four resident forms checked' : 'identity or one or more kiosk forms did not expose all three choices'
        );

        $firstPost = post_as($kiosk, '/kiosk/queue.php', '/kiosk/queue.php', [
            'service' => 'Blotter',
            'purpose' => 'Test SMS preference for queue ticket',
            'notification_preference' => 'sms',
        ]);
        $secondPost = post_as($kiosk, '/kiosk/queue.php', '/kiosk/queue.php', [
            'service' => 'Blotter',
            'purpose' => 'Test Both preference for queue ticket',
            'notification_preference' => 'both',
        ]);
        $rows = db_all(
            'SELECT queue_id, queue_number, notification_preference, status
             FROM queue_entries WHERE resident_id = ? AND queue_id > ? AND service = \'Blotter\'
             ORDER BY queue_id',
            [$residentId, $queueStartId]
        );
        $savedBoth = (string) db_val('SELECT notification_preference FROM residents WHERE resident_id = ?', [$residentId]);
        $smsIssuedCount = (int) db_val(
            "SELECT COUNT(*) FROM sms_outbox WHERE recipient_number = ? AND trigger_event = 'queue_issued'
             AND provider_status = 'not_configured'",
            [$phone]
        );
        $emailIssuedCount = (int) db_val(
            "SELECT COUNT(*) FROM notification_outbox WHERE resident_id = ?
             AND event_type IN ('queue_issued','queue_issued_email_fallback')
             AND channel = 'email' AND delivery_status = 'queued'",
            [$residentId]
        );
        $choiceSaved = $firstPost['status'] === 302 && $secondPost['status'] === 302
            && count($rows) === 2
            && $rows[0]['notification_preference'] === 'sms'
            && $rows[1]['notification_preference'] === 'both'
            && $savedBoth === 'both'
            && $smsIssuedCount === 2
            && $emailIssuedCount === 2;
        record(
            'B22-02',
            'Kiosk selections are saved on the resident and each ticket; unavailable SMS falls back to email',
            $choiceSaved,
            'tickets=' . count($rows) . '; saved=' . $savedBoth . '; SMS outbox=' . $smsIssuedCount . '; email outbox=' . $emailIssuedCount
        );

        // Change the profile preference after issuance. Notifications for existing tickets
        // must still use the channel each resident selected for that specific queue visit.
        db_exec("UPDATE residents SET notification_preference = 'email' WHERE resident_id = ?", [$residentId]);
        $called = post_as($S['clerk'], '/queue/index.php', '/queue/action.php', [
            'do' => 'call_next',
            'service' => 'Blotter',
        ]);
        $calledSms = (int) db_val(
            "SELECT COUNT(*) FROM sms_outbox WHERE recipient_number = ? AND trigger_event = 'queue_called'
             AND provider_status = 'not_configured'",
            [$phone]
        );
        $nearSms = (int) db_val(
            "SELECT COUNT(*) FROM sms_outbox WHERE recipient_number = ? AND trigger_event = 'queue_near'
             AND provider_status = 'not_configured'",
            [$phone]
        );
        $calledFallbackEmail = (int) db_val(
            "SELECT COUNT(*) FROM notification_outbox WHERE resident_id = ? AND event_type = 'queue_called_email_fallback'",
            [$residentId]
        );
        $nearEmail = (int) db_val(
            "SELECT COUNT(*) FROM notification_outbox WHERE resident_id = ? AND event_type = 'queue_near' AND channel = 'email'",
            [$residentId]
        );
        $recalledRows = db_all(
            'SELECT queue_number, notification_preference, status FROM queue_entries
             WHERE resident_id = ? AND queue_id > ? AND service = \'Blotter\' ORDER BY queue_number',
            [$residentId, $queueStartId]
        );
        $perTicketApplied = $called['status'] === 302
            && count($recalledRows) === 2
            && $recalledRows[0]['status'] === 'Called'
            && $recalledRows[1]['status'] === 'Waiting'
            && $calledSms === 1
            && $nearSms === 1
            && $calledFallbackEmail === 1
            && $nearEmail === 1;
        record(
            'B22-03',
            'Called and next-in-line alerts honor each ticket choice even after the resident profile preference changes',
            $perTicketApplied,
            'called SMS=' . $calledSms . '; next SMS=' . $nearSms . '; called email fallback=' . $calledFallbackEmail . '; next email=' . $nearEmail
        );

        $invalidPost = post_as($kiosk, '/kiosk/queue.php', '/kiosk/queue.php', [
            'service' => 'Venue',
            'purpose' => 'Invalid preference must not issue ticket',
            'notification_preference' => 'push',
        ]);
        $afterInvalidCount = (int) db_val(
            'SELECT COUNT(*) FROM queue_entries WHERE resident_id = ? AND queue_id > ?',
            [$residentId, $queueStartId]
        );
        $profileAfterInvalid = (string) db_val(
            'SELECT notification_preference FROM residents WHERE resident_id = ?',
            [$residentId]
        );
        record(
            'B22-04',
            'An invalid notification choice is rejected without issuing a ticket or changing the saved preference',
            $invalidPost['status'] === 302 && $afterInvalidCount === 2 && $profileAfterInvalid === 'email'
        );
    } finally {
        $ids = db_all('SELECT queue_id FROM queue_entries WHERE resident_id = ? AND queue_id > ?', [$residentId, $queueStartId]);
        if ($ids) {
            $queueIds = array_map(static fn(array $row): int => (int) $row['queue_id'], $ids);
            $marks = implode(',', array_fill(0, count($queueIds), '?'));
            db_exec('DELETE FROM queue_entries WHERE queue_id IN (' . $marks . ')', $queueIds);
        }
        if ($residentId !== null) {
            db_exec('DELETE FROM notification_outbox WHERE resident_id = ?', [$residentId]);
        }
        db_exec(
            "DELETE FROM sms_outbox WHERE recipient_number = ? AND trigger_event IN ('queue_issued','queue_called','queue_near')",
            [$phone]
        );
        if ($venueId !== null) {
            db_exec('DELETE FROM venue_reservations WHERE venue_id = ?', [$venueId]);
            db_exec('DELETE FROM venues WHERE venue_id = ?', [$venueId]);
        }
        if ($residentId !== null) {
            db_exec('DELETE FROM resident_verification_challenges WHERE resident_id = ?', [$residentId]);
            db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
        }
        db_exec("DELETE FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
    }
}
