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
    $secondResidentId = null;
    $secondResidentNo = $residentNo . '-NEXT';
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

        $emailRowsBeforeDispatchGuard = (int) db_val('SELECT COUNT(*) FROM notification_outbox WHERE resident_id = ?', [$residentId]);
        $smsRowsBeforeDispatchGuard = (int) db_val('SELECT COUNT(*) FROM sms_outbox WHERE recipient_number = ?', [$phone]);
        $dispatchGuardBlocked = false;
        db_transaction(function () use (&$dispatchGuardBlocked, $residentId): void {
            try {
                queue_enqueue_notifications(
                    $residentId,
                    'Venue',
                    999,
                    'queue_guard_probe',
                    'This notification must not be enqueued while a service transaction is open.',
                    'email'
                );
            } catch (LogicException $e) {
                $dispatchGuardBlocked = str_contains($e->getMessage(), 'after the service transaction commits');
            }
        });
        $emailRowsAfterDispatchGuard = (int) db_val('SELECT COUNT(*) FROM notification_outbox WHERE resident_id = ?', [$residentId]);
        $smsRowsAfterDispatchGuard = (int) db_val('SELECT COUNT(*) FROM sms_outbox WHERE recipient_number = ?', [$phone]);
        record(
            'B22-05',
            'Queue notification dispatch refuses to run inside an open database transaction and creates no outbox rows',
            $dispatchGuardBlocked
            && $emailRowsAfterDispatchGuard === $emailRowsBeforeDispatchGuard
            && $smsRowsAfterDispatchGuard === $smsRowsBeforeDispatchGuard,
            'blocked=' . ($dispatchGuardBlocked ? 'yes' : 'no')
            . '; email rows changed=' . ($emailRowsAfterDispatchGuard - $emailRowsBeforeDispatchGuard)
            . '; SMS rows changed=' . ($smsRowsAfterDispatchGuard - $smsRowsBeforeDispatchGuard)
        );

        $firstPost = post_as($kiosk, '/kiosk/queue.php', '/kiosk/queue.php', [
            'service' => 'Venue',
            'purpose' => 'Test SMS preference for queue ticket',
            'notification_preference' => 'sms',
        ]);
        $secondPost = post_as($kiosk, '/kiosk/queue.php', '/kiosk/queue.php', [
            'service' => 'Venue',
            'purpose' => 'Test Both preference for queue ticket',
            'notification_preference' => 'both',
        ]);
        $rows = db_all(
            'SELECT queue_id, queue_number, notification_preference, status
             FROM queue_entries WHERE resident_id = ? AND queue_id > ? AND service = \'Venue\'
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
            'service' => 'Venue',
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
             WHERE resident_id = ? AND queue_id > ? AND service = \'Venue\' ORDER BY queue_number',
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

        $baselineWait = db_one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, issued_at, called_at)), 0) AS wait_seconds,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, issued_at, served_at)), 0) AS elapsed_seconds
             FROM queue_entries
             WHERE service = 'Venue' AND queue_date = ? AND status = 'Served'
               AND called_at IS NOT NULL AND served_at IS NOT NULL",
            [date('Y-m-d')]
        );
        $issuedAt = date('Y-m-d H:i:s', time() - 300);
        $calledAt = date('Y-m-d H:i:s', time() - 120);
        $servedAt = date('Y-m-d H:i:s');
        db_exec(
            "UPDATE queue_entries
             SET status = 'Served', issued_at = ?, called_at = ?, served_at = ?
             WHERE queue_id = ?",
            [$issuedAt, $calledAt, $servedAt, (int) $rows[0]['queue_id']]
        );
        $snapshot = queue_service_snapshot('Venue');
        $baselineCount = (int) ($baselineWait['total'] ?? 0);
        $expectedWait = (int) round(((float) ($baselineWait['wait_seconds'] ?? 0) + 180) / ($baselineCount + 1));
        $expectedElapsed = (int) round(((float) ($baselineWait['elapsed_seconds'] ?? 0) + 300) / ($baselineCount + 1));
        record(
            'B22-06',
            'Average completed queue wait ends at the call time and excludes the time spent being served',
            $expectedWait !== $expectedElapsed && $snapshot['average_wait_seconds'] === $expectedWait,
            'baseline served=' . $baselineCount . '; expected wait average=' . $expectedWait
            . '; expected elapsed average=' . $expectedElapsed
            . '; actual=' . var_export($snapshot['average_wait_seconds'], true)
        );

        // Reuse the same kiosk browser session for another resident, as happens at a public counter.
        db_exec(
            "INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status,
              contact_number, email_address, notification_preference, household_id, is_household_head, archived_at)
             VALUES (?, 'B22 Next Resident Street', ?, ?, 'Single', ?, 'NextVisitor', 'Taylor', '1992-06-11',
                     'Female', 'Single', 'Active', 'unverified', NULL, 'b22-next@example.test', 'email', NULL, 0, NULL)",
            [$secondResidentNo, $purokId, $statusId ?: null, $typeId ?: null]
        );
        $secondResidentId = (int) db()->lastInsertId();
        $kioskHomeForTimer = $kiosk->get('/kiosk/index.php');
        $kioskJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/kiosk.js');
        $timeoutFormReady = $kioskHomeForTimer['status'] === 200
            && preg_match('/<form id="kiosk-timeout-form" method="post" action="[^"]*kiosk\\/end\\.php" hidden>.*?name="_csrf" value="[a-f0-9]{64}"/s', $kioskHomeForTimer['body']) === 1
            && str_contains($kioskJs, "form.submit();")
            && !str_contains($kioskJs, 'end.php?timeout=1');
        record(
            'B22-09',
            'Kiosk inactivity timer submits the CSRF-protected POST logout form instead of a rejected GET request',
            $timeoutFormReady,
            'page=' . $kioskHomeForTimer['status']
            . '; protected form=' . (str_contains($kioskHomeForTimer['body'], 'kiosk-timeout-form') ? 'yes' : 'no')
            . '; POST submit=' . (str_contains($kioskJs, 'form.submit();') ? 'yes' : 'no')
            . '; stale GET timer=' . (str_contains($kioskJs, 'end.php?timeout=1') ? 'yes' : 'no')
        );

        $savedTestSession = $_SESSION;
        $kioskArtifactKeys = [
            'kiosk_resident_id', 'kiosk_last_activity', 'kiosk_expires_at',
            'kiosk_challenge_token', 'kiosk_after_verify', 'kiosk_flash',
            'kiosk_last_queue_ticket', 'kiosk_last_request',
            'kiosk_last_venue', 'kiosk_last_blotter_request',
        ];
        foreach ($kioskArtifactKeys as $artifactKey) {
            $_SESSION[$artifactKey] = ['previous-resident' => 'sensitive-test-payload'];
        }
        kiosk_clear_session();
        $remainingKioskKeys = array_values(array_filter(
            $kioskArtifactKeys,
            static fn(string $key): bool => array_key_exists($key, $_SESSION)
        ));
        $_SESSION = $savedTestSession;
        record(
            'B22-10',
            'Kiosk reset clears resident identity, OTP, flash messages and all four previous-visitor receipt/ticket payloads',
            $remainingKioskKeys === [],
            'remaining session keys=' . implode(', ', $remainingKioskKeys)
        );
        $getLogout = $kiosk->get('/kiosk/end.php');
        $stillFirstResident = $kiosk->get('/kiosk/index.php');
        record(
            'B22-08',
            'Kiosk session termination rejects cross-site GET requests and leaves the current resident session unchanged',
            $getLogout['status'] === 405
            && $stillFirstResident['status'] === 200
            && str_contains($stillFirstResident['body'], 'IDENTITY VERIFIED'),
            'GET logout status=' . $getLogout['status']
            . '; resident session retained=' . (str_contains($stillFirstResident['body'], 'IDENTITY VERIFIED') ? 'yes' : 'no')
        );
        $endSession = $kiosk->post('/kiosk/end.php', ['_csrf' => $kiosk->token('/kiosk/index.php')]);
        $secondIdentity = $kiosk->post('/kiosk/identify.php', [
            '_csrf' => $kiosk->token('/kiosk/index.php'),
            'resident_no' => $secondResidentNo,
            'birthdate' => '1992-06-11',
        ]);
        $oldTicket = $kiosk->get('/kiosk/ticket.php');
        record(
            'B22-07',
            'Ending one resident kiosk session and verifying the next resident clears the previous visitor ticket',
            $endSession['status'] === 302
            && $secondIdentity['status'] === 302
            && $oldTicket['status'] === 302
            && str_contains(loc($oldTicket), 'kiosk/queue.php')
            && !str_contains($oldTicket['body'], 'Your queue number'),
            'end=' . $endSession['status'] . '; next identity=' . $secondIdentity['status']
            . '; old ticket=' . $oldTicket['status'] . '; redirect=' . (string) loc($oldTicket)
        );
    } finally {
        $ids = db_all('SELECT queue_id FROM queue_entries WHERE resident_id = ? AND queue_id > ?', [$residentId, $queueStartId]);
        if ($ids) {
            $queueIds = array_map(static fn(array $row): int => (int) $row['queue_id'], $ids);
            $marks = implode(',', array_fill(0, count($queueIds), '?'));
            db_exec('DELETE FROM queue_entries WHERE queue_id IN (' . $marks . ')', $queueIds);
        }
        if ($residentId !== null || $secondResidentId !== null) {
            $cleanupResidentIds = array_values(array_filter([$residentId, $secondResidentId], static fn($id) => $id !== null));
            $marks = implode(',', array_fill(0, count($cleanupResidentIds), '?'));
            db_exec('DELETE FROM notification_outbox WHERE resident_id IN (' . $marks . ')', $cleanupResidentIds);
        }
        db_exec(
            "DELETE FROM sms_outbox WHERE recipient_number = ? AND trigger_event IN ('queue_issued','queue_called','queue_near')",
            [$phone]
        );
        if ($venueId !== null) {
            db_exec('DELETE FROM venue_reservations WHERE venue_id = ?', [$venueId]);
            db_exec('DELETE FROM venues WHERE venue_id = ?', [$venueId]);
        }
        foreach ([$residentId, $secondResidentId] as $cleanupResidentId) {
            if ($cleanupResidentId !== null) {
                db_exec('DELETE FROM resident_verification_challenges WHERE resident_id = ?', [$cleanupResidentId]);
                db_exec('DELETE FROM residents WHERE resident_id = ?', [$cleanupResidentId]);
            }
        }
        db_exec("DELETE FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
    }
}
