<?php
/** B19 tests: Admin-managed email notification settings and authorization. */
declare(strict_types=1);

function run_b19(array $S, string $base): void
{
    $admin = $S['admin'];
    $chairman = $S['captain'];
    $secretary = $S['secretary'];
    $staff = $S['clerk'];
    $keys = ['email_enabled', 'email_from_email', 'email_from_name'];
    $before = [];
    foreach ($keys as $key) {
        $before[$key] = db_val('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    }

    try {
        $adminPage = $admin->get('/admin/settings.php?tab=notifications');
        $chairmanPage = $chairman->get('/admin/settings.php?tab=notifications');
        $secretaryPage = $secretary->get('/admin/settings.php?tab=notifications');
        $staffPage = $staff->get('/admin/settings.php?tab=notifications');
        record('B19-01', 'Notification settings are visible only to the Admin role',
            $adminPage['status'] === 200
            && str_contains($adminPage['body'], 'Default email notifications')
            && stripos($adminPage['body'], 'SMS') === false
            && $chairmanPage['status'] === 403
            && $secretaryPage['status'] === 403
            && $staffPage['status'] === 403,
            'admin=' . $adminPage['status'] . '; chairman=' . $chairmanPage['status']
            . '; secretary=' . $secretaryPage['status'] . '; staff=' . $staffPage['status']);

        $saved = post_as($admin, '/admin/settings.php?tab=notifications', '/admin/settings.php', [
            'do' => 'email_settings_save',
            'tab' => 'notifications',
            'email_enabled' => '0',
            'email_from_email' => 'b19-sender@example.test',
            'email_from_name' => 'BIRMS Test Office',
        ]);
        $savedValues = [
            'enabled' => db_val("SELECT setting_value FROM settings WHERE setting_key = 'email_enabled'"),
            'from' => db_val("SELECT setting_value FROM settings WHERE setting_key = 'email_from_email'"),
            'name' => db_val("SELECT setting_value FROM settings WHERE setting_key = 'email_from_name'"),
        ];
        $afterSavePage = $admin->get('/admin/settings.php?tab=notifications');
        record('B19-02', 'Admin can save the default email sender without enabling live delivery',
            $saved['status'] === 302
            && $savedValues['enabled'] === '0'
            && $savedValues['from'] === 'b19-sender@example.test'
            && $savedValues['name'] === 'BIRMS Test Office'
            && str_contains($afterSavePage['body'], 'b19-sender@example.test')
            && str_contains($afterSavePage['body'], 'BIRMS Test Office'));

        $invalid = post_as($admin, '/admin/settings.php?tab=notifications', '/admin/settings.php', [
            'do' => 'email_settings_save',
            'tab' => 'notifications',
            'email_enabled' => '1',
            'email_from_email' => 'not-an-email',
            'email_from_name' => 'BIRMS Test Office',
        ]);
        $stillSafe = db_val("SELECT setting_value FROM settings WHERE setting_key = 'email_enabled'") === '0'
            && db_val("SELECT setting_value FROM settings WHERE setting_key = 'email_from_email'") === 'b19-sender@example.test';
        record('B19-03', 'Email cannot be enabled with an invalid sender address and invalid input does not change settings',
            $invalid['status'] === 200
            && str_contains($invalid['body'], 'valid sender email address')
            && $stillSafe);

        $beforeUnauthorized = db_val("SELECT setting_value FROM settings WHERE setting_key = 'email_from_email'");
        $noCsrf = $admin->post('/admin/settings.php', [
            'do' => 'email_settings_save', 'tab' => 'notifications',
            'email_enabled' => '0', 'email_from_email' => 'forged@example.test',
            'email_from_name' => 'Forged Sender',
        ]);
        $forbidden = post_as($secretary, '/dashboard.php', '/admin/settings.php', [
            'do' => 'email_settings_save', 'tab' => 'notifications',
            'email_enabled' => '0', 'email_from_email' => 'forged@example.test',
            'email_from_name' => 'Forged Sender',
        ]);
        record('B19-04', 'Notification settings require CSRF and Admin permission; unauthorized POST cannot change the sender',
            $noCsrf['status'] === 403
            && $forbidden['status'] === 403
            && db_val("SELECT setting_value FROM settings WHERE setting_key = 'email_from_email'") === $beforeUnauthorized);
    } finally {
        foreach ($keys as $key) {
            if ($before[$key] === null) {
                db_exec('DELETE FROM settings WHERE setting_key = ?', [$key]);
            } else {
                setting_set($key, (string) $before[$key]);
            }
        }
    }
}
