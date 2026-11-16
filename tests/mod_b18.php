<?php
/** B18 tests: resident email/SMS/both preferences and email fallback when SMS is unavailable. */
declare(strict_types=1);
require_once __DIR__ . '/../app/email_provider.php';
require_once __DIR__ . '/../app/sms_provider.php';
require_once __DIR__ . '/../app/notifications_lib.php';

function run_b18(array $S, string $base): void
{
    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $fixtures = [
        ['resident_no' => 'RES-B18-EMAIL', 'first' => 'Email', 'email' => 'b18-email@example.test', 'phone' => null, 'preference' => 'email'],
        ['resident_no' => 'RES-B18-SMS', 'first' => 'Sms', 'email' => 'b18-sms@example.test', 'phone' => '09170008881', 'preference' => 'sms'],
        ['resident_no' => 'RES-B18-BOTH', 'first' => 'Both', 'email' => 'b18-both@example.test', 'phone' => '09170008882', 'preference' => 'both'],
        ['resident_no' => 'RES-B18-NOEMAIL', 'first' => 'NoEmail', 'email' => null, 'phone' => '09170008883', 'preference' => 'sms'],
    ];
    $ids = [];
    $phones = array_values(array_filter(array_column($fixtures, 'phone')));

    try {
        foreach ($fixtures as $fixture) {
            db_exec(
                "INSERT INTO residents
                 (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
                  last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
                  notification_preference, residency_status, household_id, is_household_head, archived_at)
                 VALUES (?, '18 Notification Street', ?, ?, 'Single', ?, 'ZZB18Notify', ?, '1990-01-01',
                         'Male', 'Single', ?, ?, ?, 'Active', NULL, 0, NULL)",
                [
                    $fixture['resident_no'], $purok['lookup_id'] ?? null,
                    $statusId ?: null, $typeId ?: null, $fixture['first'],
                    $fixture['phone'], $fixture['email'], $fixture['preference'],
                ]
            );
            $ids[$fixture['resident_no']] = (int) db()->lastInsertId();
        }

        $emailId = $ids['RES-B18-EMAIL'];
        $smsId = $ids['RES-B18-SMS'];
        $bothId = $ids['RES-B18-BOTH'];
        $noEmailId = $ids['RES-B18-NOEMAIL'];

        $emailResult = resident_notify_status_change(
            $emailId, 'Documents', 'REQ-B18-EMAIL', 'Approved', 'notification_b18', 'Your document is approved.'
        );
        $emailRow = db_one(
            "SELECT recipient, channel, event_type, delivery_status FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'REQ-B18-EMAIL' ORDER BY notification_id DESC LIMIT 1",
            [$emailId]
        );
        $emailSmsCount = (int) db_val('SELECT COUNT(*) FROM sms_outbox WHERE recipient_number = ?', ['']);

        record('B18-01', 'Email preference queues a status email without requiring an SMS provider',
            $emailRow !== null
            && $emailRow['recipient'] === 'b18-email@example.test'
            && $emailRow['channel'] === 'email'
            && $emailRow['event_type'] === 'notification_b18'
            && in_array($emailRow['delivery_status'], ['queued', 'sent', 'failed'], true)
            && $emailResult['sms'] === 'not_requested'
            && $emailSmsCount === 0);

        $smsResult = resident_notify_status_change(
            $smsId, 'Venue', 'VEN-B18-SMS', 'Approved', 'notification_b18', 'The venue reservation is approved.'
        );
        $smsEmailRow = db_one(
            "SELECT recipient, channel, event_type, delivery_status, message FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'VEN-B18-SMS' ORDER BY notification_id DESC LIMIT 1",
            [$smsId]
        );
        $smsLog = db_one(
            "SELECT recipient_number, delivery, provider_status, trigger_event
             FROM sms_outbox WHERE recipient_number = ? AND trigger_event = 'notification_b18'
             ORDER BY sms_id DESC LIMIT 1",
            ['09170008881']
        );
        record('B18-02', 'SMS-only preference falls back to email when the SMS gateway is disabled',
            !sms_provider_is_enabled()
            && $smsEmailRow !== null
            && $smsEmailRow['recipient'] === 'b18-sms@example.test'
            && $smsEmailRow['channel'] === 'email'
            && $smsEmailRow['event_type'] === 'notification_b18'
            && str_contains((string) $smsEmailRow['message'], 'Approved')
            && $smsLog !== null
            && $smsLog['recipient_number'] === '09170008881'
            && $smsLog['delivery'] === 'Logged only'
            && $smsLog['provider_status'] === 'not_configured'
            && $smsResult['sms'] === 'not_configured'
            && in_array($smsResult['email'], ['queued', 'sent', 'failed'], true));

        $bothResult = resident_notify_status_change(
            $bothId, 'Blotter', 'BLT-B18-BOTH', 'Unresolved', 'notification_b18', 'The case remains unresolved.'
        );
        $bothEmailRow = db_one(
            "SELECT recipient, channel, event_type FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'BLT-B18-BOTH' ORDER BY notification_id DESC LIMIT 1",
            [$bothId]
        );
        $bothSmsRow = db_one(
            "SELECT recipient_number, provider_status FROM sms_outbox
             WHERE recipient_number = ? AND trigger_event = 'notification_b18' ORDER BY sms_id DESC LIMIT 1",
            ['09170008882']
        );
        record('B18-03', 'Both preference preserves the email notice and records the optional SMS attempt separately',
            $bothEmailRow !== null && $bothEmailRow['recipient'] === 'b18-both@example.test'
            && $bothEmailRow['channel'] === 'email'
            && $bothSmsRow !== null && $bothSmsRow['recipient_number'] === '09170008882'
            && $bothSmsRow['provider_status'] === 'not_configured'
            && $bothResult['sms'] === 'not_configured');

        $noEmailResult = resident_notify_status_change(
            $noEmailId, 'Blotter', 'BLT-B18-NOEMAIL', 'Dismissed', 'notification_b18', 'The request was dismissed.'
        );
        $noEmailRow = db_one(
            "SELECT notification_id FROM notification_outbox WHERE resident_id = ? AND reference_no = 'BLT-B18-NOEMAIL'",
            [$noEmailId]
        );
        $noEmailSms = db_one(
            "SELECT recipient_number, delivery, provider_status FROM sms_outbox
             WHERE recipient_number = '09170008883' AND trigger_event = 'notification_b18'
             ORDER BY sms_id DESC LIMIT 1"
        );
        record('B18-04', 'SMS fallback does not invent an email address when none is on the resident record',
            $noEmailRow === null
            && $noEmailResult['email'] === 'no_email'
            && $noEmailResult['sms'] === 'not_configured'
            && $noEmailSms !== null
            && $noEmailSms['recipient_number'] === '09170008883'
            && $noEmailSms['provider_status'] === 'not_configured',
            'email=' . $noEmailResult['email'] . '; sms=' . $noEmailResult['sms']
            . '; fabricated-email=' . ($noEmailRow === null ? 'no' : 'yes')
            . '; sms-log=' . ($noEmailSms === null ? 'missing' : (string) $noEmailSms['provider_status']));
    } finally {
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db_exec('DELETE FROM notification_outbox WHERE resident_id IN (' . $marks . ')', array_values($ids));
            if ($phones) {
                $phoneMarks = implode(',', array_fill(0, count($phones), '?'));
                db_exec("DELETE FROM sms_outbox WHERE trigger_event = 'notification_b18' AND recipient_number IN (" . $phoneMarks . ')', $phones);
            }
            db_exec('DELETE FROM residents WHERE resident_id IN (' . $marks . ')', array_values($ids));
        }
    }
}
