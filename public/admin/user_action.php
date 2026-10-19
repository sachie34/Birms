<?php
/** POST-only security actions on a user: reset password, unlock, deactivate, reactivate. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
require_csrf();
require_permission('users.manage');

$id = post_int('id'); $action = post_str('action');
if ($id === null || !in_array($action, ['reset_password', 'unlock', 'deactivate', 'activate'], true)) { abort(404, 'Unknown action.'); }
$t = db_one('SELECT user_id, username, role, status FROM users WHERE user_id = ?', [$id]);
if ($t === null) { abort(404, 'That user does not exist.'); }
if ((int) $t['user_id'] === (int) $me['user_id']) {
    flash_add('warning', 'Use Change password for your own account. You cannot deactivate yourself.');
    redirect('admin/user_edit.php', ['id' => $id]);
}

$msg = '';
try {
    db_transaction(function () use ($action, $t, &$msg): void {
        $uid = (int) $t['user_id'];
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
                if ($t['role'] === 'admin' && $t['status'] === 'active' && (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND user_id <> ?", [$uid]) < 1) {
                    throw new RuntimeException('last-admin');
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
    flash_add('success', $msg);
} catch (RuntimeException $e) {
    flash_add('danger', $e->getMessage() === 'last-admin' ? 'At least one active Administrator must remain.' : 'The action could not be completed.');
}
redirect('admin/user_edit.php', ['id' => $id]);
