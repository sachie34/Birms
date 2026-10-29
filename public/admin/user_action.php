<?php
/** POST-only security actions on a user: reset password, unlock, deactivate, reactivate. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
require_csrf();
require_permission('users.manage');

$id = post_int('id'); $action = post_str('action');
if ($id === null || !in_array($action, ['reset_password', 'unlock', 'deactivate', 'activate'], true)) { abort(404, 'Unknown action.'); }
rate_limit_enforce('admin_user_action', 30, 3600, null, 120);
if ($action === 'reset_password') {
    rate_limit_enforce('admin_password_reset_target', 3, 86400, (string) $id, 60);
}
$t = db_one('SELECT user_id, username, role, status FROM users WHERE user_id = ?', [$id]);
if ($t === null) { abort(404, 'That user does not exist.'); }
if ((int) $t['user_id'] === (int) $me['user_id']) {
    flash_add('warning', 'Use Change password for your own account. You cannot deactivate yourself.');
    redirect('admin/user_edit.php', ['id' => $id]);
}

$msg = '';
try {
    with_named_lock('birms_active_admin_guard', function () use ($action, $id, $me, &$msg): void {
        db_transaction(function () use ($action, $id, $me, &$msg): void {
            $t = db_one('SELECT user_id, username, role, status FROM users WHERE user_id = ? FOR UPDATE', [$id]);
            if ($t === null) { throw new RuntimeException('missing-user'); }
            $uid = (int) $t['user_id'];
            if ($uid === (int) $me['user_id']) { throw new RuntimeException('self-action'); }
            switch ($action) {
                case 'reset_password':
                    $plain = random_password();
                    db_exec('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?', [password_hash($plain, PASSWORD_DEFAULT), $uid]);
                    login_clear_failures((string) $t['username']);
                    audit('password_reset', 'Users', 'Success', 'Temporary password set for ' . $t['username'], 'user', $uid);
                    $msg = 'New temporary password (shown once, give it to the user): ' . $plain;
                    break;
                case 'unlock':
                    login_clear_failures((string) $t['username']);
                    audit('user_unlocked', 'Users', 'Success', 'Cleared failed sign-ins for ' . $t['username'], 'user', $uid);
                    $msg = 'Sign-in unlocked.';
                    break;
                case 'deactivate':
                    if ($t['role'] === 'admin' && $t['status'] === 'active') {
                        $activeAdmins = db_all("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY user_id FOR UPDATE");
                        $remaining = 0;
                        foreach ($activeAdmins as $adminRow) {
                            if ((int) $adminRow['user_id'] !== $uid) { $remaining++; }
                        }
                        if ($remaining < 1) { throw new RuntimeException('last-admin'); }
                    }
                    db_exec("UPDATE users SET status = 'inactive' WHERE user_id = ?", [$uid]);
                    audit('user_deactivated', 'Users', 'Success', 'Deactivated ' . $t['username'], 'user', $uid);
                    $msg = 'Account deactivated.';
                    break;
                case 'activate':
                    db_exec("UPDATE users SET status = 'active' WHERE user_id = ?", [$uid]);
                    audit('user_activated', 'Users', 'Success', 'Reactivated ' . $t['username'], 'user', $uid);
                    $msg = 'Account reactivated.';
                    break;
            }
        });
    });
    flash_add('success', $msg);
} catch (RuntimeException $e) {
    flash_add('danger', in_array($e->getMessage(), ['last-admin'], true)
        ? 'At least one active Administrator must remain.'
        : 'The action could not be completed. Reload the page and try again.');
}
redirect('admin/user_edit.php', ['id' => $id]);
