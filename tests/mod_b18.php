<?php
/** B18 regression checks: legacy SMS preferences are ignored; status and queue notices are email-only. */
declare(strict_types=1);
require_once __DIR__ . '/../app/email_provider.php';
require_once __DIR__ . '/../app/notifications_lib.php';
require_once __DIR__ . '/../app/queue_lib.php';

function run_b18(array $S, string $base): void
{
    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $fixtures = [
        ['resident_no' => 'RES-B18-LEGACY-SMS', 'first' => 'LegacySms', 'email' => 'b18-legacy-sms@example.test', 'phone' => '09170008881', 'preference' => 'sms'],
        ['resident_no' => 'RES-B18-LEGACY-BOTH', 'first' => 'LegacyBoth', 'email' => 'b18-legacy-both@example.test', 'phone' => '09170008882', 'preference' => 'both'],
    ];
    $ids = [];
    $phones = array_values(array_filter(array_column($fixtures, 'phone')));
    $queueIds = [];

    try {
        foreach ($fixtures as $fixture) {
            db_exec(
                "INSERT INTO residents
                 (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
                  last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
                  notification_preference, residency_status, household_id, is_household_head, archived_at)
                 VALUES (?, '18 Email Only Street', ?, ?, 'Single', ?, 'ZZB18Notify', ?, '1990-01-01',
                         'Male', 'Single', ?, ?, ?, 'Active', NULL, 0, NULL)",
                [
                    $fixture['resident_no'], $purok['lookup_id'] ?? null,
                    $statusId ?: null, $typeId ?: null, $fixture['first'],
                    $fixture['phone'], $fixture['email'], $fixture['preference'],
                ]
            );
            $ids[$fixture['resident_no']] = (int) db()->lastInsertId();
        }

        $legacySmsId = $ids['RES-B18-LEGACY-SMS'];
        $legacyBothId = $ids['RES-B18-LEGACY-BOTH'];
        $smsBefore = (int) db_val("SELECT COUNT(*) FROM sms_outbox WHERE trigger_event LIKE 'B18-%'");

        $statusResult = resident_notify_status_change(
            $legacySmsId, 'Documents', 'REQ-B18-EMAIL-ONLY', 'Approved',
            'notification_b18_email_only', 'Your document is approved.'
        );
        $statusRow = db_one(
            "SELECT recipient, channel, event_type, delivery_status
             FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'REQ-B18-EMAIL-ONLY'
             ORDER BY notification_id DESC LIMIT 1",
            [$legacySmsId]
        );
        $smsAfterStatus = (int) db_val("SELECT COUNT(*) FROM sms_outbox WHERE trigger_event LIKE 'B18-%'");

        record('B18-01', 'A legacy SMS-only resident preference still produces an email status notice, never an SMS',
            $statusRow !== null
            && $statusRow['recipient'] === 'b18-legacy-sms@example.test'
            && $statusRow['channel'] === 'email'
            && $statusRow['event_type'] === 'notification_b18_email_only'
            && $statusResult === ['email' => 'queued']
            && $smsAfterStatus === $smsBefore,
            'email=' . ($statusRow['channel'] ?? 'missing') . '; SMS rows added=' . ($smsAfterStatus - $smsBefore));

        $ticket = queue_issue_number(
            'Documents', $legacyBothId, 'REQ-B18-QUEUE', 'kiosk', 'B18 email-only queue test'
        );
        $queueIds[] = (int) $ticket['queue_id'];
        $queueRow = db_one('SELECT notification_preference FROM queue_entries WHERE queue_id = ?', [$ticket['queue_id']]);
        $queueNotice = db_one(
            "SELECT recipient, channel, event_type FROM notification_outbox
             WHERE resident_id = ? AND reference_no = ? ORDER BY notification_id DESC LIMIT 1",
            [$legacyBothId, 'Documents-' . date('Ymd') . '-' . (int) $ticket['queue_number']]
        );
        $smsAfterQueue = (int) db_val("SELECT COUNT(*) FROM sms_outbox WHERE trigger_event LIKE 'B18-%'");

        record('B18-02', 'Queue issuance still creates a queue ticket and email notice for a legacy Both preference without an SMS attempt',
            $ticket['queue_number'] > 0
            && ($queueRow['notification_preference'] ?? '') === 'email'
            && $queueNotice !== null
            && $queueNotice['recipient'] === 'b18-legacy-both@example.test'
            && $queueNotice['channel'] === 'email'
            && $queueNotice['event_type'] === 'queue_issued'
            && $smsAfterQueue === $smsBefore,
            'queue=' . (int) $ticket['queue_number'] . '; channel=' . ($queueNotice['channel'] ?? 'missing')
            . '; SMS rows added=' . ($smsAfterQueue - $smsBefore));
    } finally {
        if ($queueIds) {
            $marks = implode(',', array_fill(0, count($queueIds), '?'));
            db_exec('DELETE FROM queue_entries WHERE queue_id IN (' . $marks . ')', $queueIds);
        }
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db_exec('DELETE FROM notification_outbox WHERE resident_id IN (' . $marks . ')', array_values($ids));
            db_exec('DELETE FROM residents WHERE resident_id IN (' . $marks . ')', array_values($ids));
        }
    }
}
