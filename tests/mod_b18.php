<?php
/** B18 tests: resident notifications are email-only regardless of stored mobile contact data. */
declare(strict_types=1);
require_once __DIR__ . '/../app/email_provider.php';
require_once __DIR__ . '/../app/notifications_lib.php';

function run_b18(array $S, string $base): void
{
    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $fixtures = [
        ['resident_no' => 'RES-B18-EMAIL', 'first' => 'Email', 'email' => 'b18-email@example.test', 'phone' => null],
        ['resident_no' => 'RES-B18-MOBILE', 'first' => 'Mobile', 'email' => 'b18-mobile@example.test', 'phone' => '09170008881'],
        ['resident_no' => 'RES-B18-NOEMAIL', 'first' => 'NoEmail', 'email' => null, 'phone' => '09170008883'],
    ];
    $ids = [];
    $legacySmsBefore = (int) db_val('SELECT COUNT(*) FROM sms_outbox');

    try {
        foreach ($fixtures as $fixture) {
            db_exec(
                "INSERT INTO residents
                 (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
                  last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
                  notification_preference, residency_status, household_id, is_household_head, archived_at)
                 VALUES (?, '18 Notification Street', ?, ?, 'Single', ?, 'ZZB18Notify', ?, '1990-01-01',
                         'Male', 'Single', ?, ?, 'email', 'Active', NULL, 0, NULL)",
                [
                    $fixture['resident_no'], $purok['lookup_id'] ?? null,
                    $statusId ?: null, $typeId ?: null, $fixture['first'],
                    $fixture['phone'], $fixture['email'],
                ]
            );
            $ids[$fixture['resident_no']] = (int) db()->lastInsertId();
        }

        $emailId = $ids['RES-B18-EMAIL'];
        $mobileId = $ids['RES-B18-MOBILE'];
        $noEmailId = $ids['RES-B18-NOEMAIL'];

        $emailResult = resident_notify_status_change(
            $emailId, 'Documents', 'REQ-B18-EMAIL', 'Approved', 'notification_b18', 'Your document is approved.'
        );
        $emailRow = db_one(
            "SELECT recipient, channel, event_type, delivery_status FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'REQ-B18-EMAIL' ORDER BY notification_id DESC LIMIT 1",
            [$emailId]
        );
        record(
            'B18-01',
            'A resident with an email address receives a queued email status notice',
            $emailRow !== null
            && $emailRow['recipient'] === 'b18-email@example.test'
            && $emailRow['channel'] === 'email'
            && $emailRow['event_type'] === 'notification_b18'
            && $emailRow['delivery_status'] === 'queued'
            && $emailResult === ['email' => 'queued']
        );

        $mobileResult = resident_notify_status_change(
            $mobileId, 'Venue', 'VEN-B18-MOBILE', 'Approved', 'notification_b18', 'The venue reservation is approved.'
        );
        $mobileRow = db_one(
            "SELECT recipient, channel, event_type, delivery_status FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'VEN-B18-MOBILE' ORDER BY notification_id DESC LIMIT 1",
            [$mobileId]
        );
        record(
            'B18-02',
            'A saved mobile number does not create an SMS attempt; the resident receives email only',
            $mobileRow !== null
            && $mobileRow['recipient'] === 'b18-mobile@example.test'
            && $mobileRow['channel'] === 'email'
            && $mobileRow['event_type'] === 'notification_b18'
            && $mobileResult === ['email' => 'queued']
        );

        $noEmailResult = resident_notify_status_change(
            $noEmailId, 'Blotter', 'BLT-B18-NOEMAIL', 'Dismissed', 'notification_b18', 'The request was dismissed.'
        );
        $noEmailRow = db_one(
            "SELECT notification_id FROM notification_outbox WHERE resident_id = ? AND reference_no = 'BLT-B18-NOEMAIL'",
            [$noEmailId]
        );
        record(
            'B18-03',
            'When no email is registered, status changes still commit and no fake delivery is queued',
            $noEmailRow === null
            && $noEmailResult === ['email' => 'no_email']
        );

        $legacySmsAfter = (int) db_val('SELECT COUNT(*) FROM sms_outbox');
        record(
            'B18-04',
            'Email and mobile contact combinations never add rows to the retained legacy SMS outbox',
            $legacySmsAfter === $legacySmsBefore,
            'legacy SMS rows before=' . $legacySmsBefore . '; after=' . $legacySmsAfter
        );
    } finally {
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db_exec('DELETE FROM notification_outbox WHERE resident_id IN (' . $marks . ')', array_values($ids));
            db_exec('DELETE FROM residents WHERE resident_id IN (' . $marks . ')', array_values($ids));
        }
    }
}
