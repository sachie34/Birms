<?php
/** B18 tests: resident status notifications are email-only; legacy SMS rows remain unchanged. */
declare(strict_types=1);
require_once __DIR__ . '/../app/email_provider.php';
require_once __DIR__ . '/../app/notifications_lib.php';

function run_b18(array $S, string $base): void
{
    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $fixtures = [
        ['resident_no' => 'RES-B18-EMAIL', 'first' => 'Email', 'email' => 'b18-email@example.test'],
        ['resident_no' => 'RES-B18-NOEMAIL', 'first' => 'NoEmail', 'email' => null],
    ];
    $ids = [];
    $smsBefore = (int) db_val('SELECT COUNT(*) FROM sms_outbox');

    try {
        foreach ($fixtures as $fixture) {
            db_exec(
                "INSERT INTO residents
                 (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
                  last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
                  notification_preference, residency_status, household_id, is_household_head, archived_at)
                 VALUES (?, '18 Notification Street', ?, ?, 'Single', ?, 'ZZB18Notify', ?, '1990-01-01',
                         'Male', 'Single', NULL, ?, 'email', 'Active', NULL, 0, NULL)",
                [
                    $fixture['resident_no'], $purok['lookup_id'] ?? null,
                    $statusId ?: null, $typeId ?: null, $fixture['first'], $fixture['email'],
                ]
            );
            $ids[$fixture['resident_no']] = (int) db()->lastInsertId();
        }

        $emailId = $ids['RES-B18-EMAIL'];
        $emailResult = resident_notify_status_change(
            $emailId, 'Documents', 'REQ-B18-EMAIL', 'Approved', 'notification_b18', 'Your document is approved.'
        );
        $emailRow = db_one(
            "SELECT recipient, channel, event_type, delivery_status, message FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'REQ-B18-EMAIL' ORDER BY notification_id DESC LIMIT 1",
            [$emailId]
        );
        record(
            'B18-01',
            'A resident with a valid email receives a durable email-only status notice',
            $emailRow !== null
            && $emailRow['recipient'] === 'b18-email@example.test'
            && $emailRow['channel'] === 'email'
            && $emailRow['event_type'] === 'notification_b18'
            && str_contains((string) $emailRow['message'], 'Approved')
            && in_array($emailRow['delivery_status'], ['queued', 'sent', 'failed'], true)
            && in_array($emailResult['email'], ['queued', 'sent', 'failed'], true)
        );

        $noEmailId = $ids['RES-B18-NOEMAIL'];
        $noEmailResult = resident_notify_status_change(
            $noEmailId, 'Blotter', 'BLT-B18-NOEMAIL', 'Dismissed', 'notification_b18', 'The request was dismissed.'
        );
        $noEmailRow = db_one(
            "SELECT notification_id FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'BLT-B18-NOEMAIL'",
            [$noEmailId]
        );
        record(
            'B18-02',
            'A resident without an email address does not receive a fabricated email or a new SMS',
            $noEmailRow === null && $noEmailResult['email'] === 'no_email'
        );

        $smsAfter = (int) db_val('SELECT COUNT(*) FROM sms_outbox');
        record(
            'B18-03',
            'Email-only status notifications do not create new SMS outbox rows',
            $smsAfter === $smsBefore,
            'SMS rows before=' . $smsBefore . '; after=' . $smsAfter
        );
    } finally {
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db_exec('DELETE FROM notification_outbox WHERE resident_id IN (' . $marks . ')', array_values($ids));
            db_exec('DELETE FROM residents WHERE resident_id IN (' . $marks . ')', array_values($ids));
        }
    }
}
