<?php
/** B12 regression checks: SMS delivery has been removed from this release. */
declare(strict_types=1);

require_once __DIR__ . '/../app/notifications_lib.php';

function run_b12(array $S, string $base): void
{
    $providersAbsent = !function_exists('sms_send_outbox')
        && !function_exists('sms_refresh_outbox_status')
        && !function_exists('sms_provider_is_enabled')
        && !function_exists('smsapiph_sms_config')
        && !function_exists('infobip_sms_config')
        && !is_file(APP_ROOT . '/app/sms_provider.php')
        && !is_file(APP_ROOT . '/app/smsapiph_sms_provider.php')
        && !is_file(APP_ROOT . '/app/infobip_sms_provider.php')
        && !is_file(APP_ROOT . '/tools/test_smsapiph_sms.php')
        && !is_file(APP_ROOT . '/tools/test_infobip_sms.php');
    record(
        'B12-01',
        'No SMS provider adapter, send helper or live-send test utility is installed',
        $providersAbsent
    );

    $adminSettings = $S['admin']->get('/admin/settings.php?tab=notifications');
    $adminHome = $S['admin']->get('/dashboard.php');
    $noSmsScreen = !is_file(APP_ROOT . '/public/admin/sms.php')
        && $adminSettings['status'] === 200
        && stripos($adminSettings['body'], 'SMS') === false
        && !str_contains($adminHome['body'], 'SMS Delivery');
    record(
        'B12-02',
        'The administration interface contains email notification settings only',
        $noSmsScreen,
        'settings=' . $adminSettings['status'] . '; SMS page exists=' . (is_file(APP_ROOT . '/public/admin/sms.php') ? 'yes' : 'no')
    );

    // Keep the legacy outbox table for old audit records, but prove ordinary requests
    // no longer append new SMS records even when a resident has a mobile number.
    $legacyRowsBefore = (int) db_val('SELECT COUNT(*) FROM sms_outbox');
    $pref = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $status = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $type = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $residentNo = 'RES-B12-EMAIL-ONLY';
    db_exec('DELETE FROM residents WHERE resident_no = ?', [$residentNo]);
    try {
        db_exec(
            "INSERT INTO residents
             (resident_no,address,purok_id,resident_status_id,marital_status,resident_type_id,
              last_name,first_name,birthdate,sex,civil_status,contact_number,email_address,
              notification_preference,residency_status,household_id,is_household_head,archived_at)
             VALUES (?,'12 Email Lane',?,?,'Single',?,'ZZB12EmailOnly','Resident','1990-01-01',
                     'Female','Single','09170001212','b12-email@example.test','email','Active',NULL,0,NULL)",
            [$residentNo, $pref ?: null, $status ?: null, $type ?: null]
        );
        $residentId = (int) db()->lastInsertId();
        $notice = resident_notify_status_change(
            $residentId,
            'Documents',
            'B12-EMAIL-ONLY',
            'Approved',
            'b12_email_only',
            'Your document request was approved.'
        );
        $emailRow = db_one(
            "SELECT channel, recipient, event_type, delivery_status FROM notification_outbox
             WHERE resident_id = ? AND reference_no = 'B12-EMAIL-ONLY' ORDER BY notification_id DESC LIMIT 1",
            [$residentId]
        );
        $legacyRowsAfter = (int) db_val('SELECT COUNT(*) FROM sms_outbox');
        record(
            'B12-03',
            'A resident with both email and mobile contact receives an email outbox entry only',
            $emailRow !== null
            && $emailRow['channel'] === 'email'
            && $emailRow['recipient'] === 'b12-email@example.test'
            && $emailRow['event_type'] === 'b12_email_only'
            && $emailRow['delivery_status'] === 'queued'
            && $notice === ['email' => 'queued']
            && $legacyRowsAfter === $legacyRowsBefore,
            'email=' . (string) ($emailRow['delivery_status'] ?? 'missing')
            . '; legacy SMS rows added=' . ($legacyRowsAfter - $legacyRowsBefore)
        );
    } finally {
        db_exec("DELETE FROM notification_outbox WHERE reference_no = 'B12-EMAIL-ONLY'");
        db_exec('DELETE FROM residents WHERE resident_no = ?', [$residentNo]);
    }
}
