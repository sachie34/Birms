<?php
/** B12 regression checks: SMS is removed from the active application; historical outbox data is retained. */
declare(strict_types=1);

function run_b12(array $S, string $base): void
{
    $smsPage = $S['admin']->get('/admin/sms.php');
    $notifications = $S['admin']->get('/admin/settings.php?tab=notifications');
    $legacyTab = $S['admin']->get('/admin/settings.php?tab=sms');
    $schemaRows = (int) db_val(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_outbox'"
    );

    record('B12-01', 'The former SMS delivery route is no longer available',
        $smsPage['status'] === 404, 'HTTP=' . $smsPage['status']);

    record('B12-02', 'Settings exposes email notifications only and no SMS tab or provider controls',
        $notifications['status'] === 200
        && !str_contains($notifications['body'], 'SMS')
        && $legacyTab['status'] === 200
        && !str_contains($legacyTab['body'], 'SMS'),
        'notifications HTTP=' . $notifications['status'] . '; legacy tab HTTP=' . $legacyTab['status']);

    record('B12-03', 'SMS provider functions are not loaded into the application runtime',
        !function_exists('sms_provider_is_enabled')
        && !function_exists('sms_send_outbox'));

    record('B12-04', 'Legacy SMS outbox table remains available for historical record retention',
        $schemaRows === 1, 'sms_outbox table count=' . $schemaRows);
}
