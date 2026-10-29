<?php
/** Create or edit a user (Administrator only). */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
require_permission('users.manage');

$id = get_int('id');
$target = null;
if ($id !== null) {
    $target = db_one('SELECT user_id, username, full_name, role, status, email, contact_number, last_login_at FROM users WHERE user_id = ?', [$id]);
    if ($target === null) { abort(404, 'That user does not exist.'); }
}
$isSelf = $target !== null && (int) $target['user_id'] === (int) $me['user_id'];
$errors = [];
$v = [
    'username' => $target['username'] ?? '', 'full_name' => $target['full_name'] ?? '', 'role' => $target['role'] ?? 'clerk',
    'email' => $target['email'] ?? '', 'contact_number' => $target['contact_number'] ?? '', 'status' => $target['status'] ?? 'active', 'temp_password' => '',
];

function other_active_admins(int $exceptId): int
{
    return (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND user_id <> ?", [$exceptId]);
}

if (is_post()) {
    require_csrf();
    $v['full_name'] = post_str('full_name');
    $v['email'] = post_str('email');
    $v['contact_number'] = post_str('contact_number');
    $roleIn = post_str('role'); $statusIn = post_str('status');
    $v['role'] = in_array($roleIn, ROLES, true) ? $roleIn : '';
    $v['status'] = in_array($statusIn, ['active', 'inactive'], true) ? $statusIn : 'active';
    if ($target === null) { $v['username'] = strtolower(post_str('username')); $v['temp_password'] = post_raw('temp_password'); }
    if ($isSelf) { $v['role'] = (string) $target['role']; $v['status'] = 'active'; } // you cannot demote or deactivate yourself

    if (!v_person_name($v['full_name']))                         { $errors[] = 'Enter the full name (letters, spaces, . \' - only).'; }
    if ($v['role'] === '')                                       { $errors[] = 'Choose a role.'; }
    if ($v['email'] !== '' && !v_email($v['email']))             { $errors[] = 'The email address is not valid.'; }
    if ($v['contact_number'] !== '' && !v_phone($v['contact_number'])) { $errors[] = 'The contact number is not valid (example: 09171234567).'; }
    if ($target === null) {
        if (!v_username($v['username']))                         { $errors[] = 'Username: 3-30 characters, lowercase letters, numbers, dot, dash or underscore.'; }
        elseif (db_val('SELECT COUNT(*) FROM users WHERE username = ?', [$v['username']]))   { $errors[] = 'That username is already taken.'; }
        if ($v['temp_password'] !== '') { $errors = array_merge($errors, password_policy_errors($v['temp_password'], $v['username'])); }
    } elseif ($target['role'] === 'admin' && $target['status'] === 'active' && ($v['role'] !== 'admin' || $v['status'] !== 'active') && other_active_admins((int) $target['user_id']) < 1) {
        $errors[] = 'At least one active Administrator must remain.';
    }

    if (!$errors) {
        $email = $v['email'] !== '' ? $v['email'] : null; $phone = $v['contact_number'] !== '' ? $v['contact_number'] : null;
        if ($target === null) {
            $plain = $v['temp_password'] !== '' ? $v['temp_password'] : random_password();
            $newId = db_transaction(function () use ($v, $email, $phone, $plain): int {
                db_exec('INSERT INTO users (username, password_hash, full_name, role, status, must_change_password, email, contact_number) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
                    [$v['username'], password_hash($plain, PASSWORD_DEFAULT), $v['full_name'], $v['role'], 'active', $email, $phone]);
                $nid = (int) db()->lastInsertId();
                audit('user_created', 'Users', 'Success', 'Created ' . $v['username'] . ' as ' . $v['role'], 'user', $nid);
                return $nid;
            });
            flash_add('success', 'User created. Temporary password (shown once, give it to the user): ' . $plain);
            redirect('admin/user_edit.php', ['id' => $newId]);
        }
        try {
            with_named_lock('birms_active_admin_guard', function () use ($v, $email, $phone, $target): void {
                db_transaction(function () use ($v, $email, $phone, $target): void {
                    $current = db_one('SELECT user_id, username, full_name, role, status, email, contact_number FROM users WHERE user_id = ? FOR UPDATE', [(int) $target['user_id']]);
                    if ($current === null) { throw new RuntimeException('missing-user'); }
                    if ((string) $current['role'] !== (string) $target['role'] || (string) $current['status'] !== (string) $target['status']) {
                        throw new RuntimeException('stale-user');
                    }
                    // The named lock serializes this check with every other role/status mutation.
                    if ($current['role'] === 'admin' && $current['status'] === 'active'
                        && ($v['role'] !== 'admin' || $v['status'] !== 'active')) {
                        $activeAdmins = db_all("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY user_id FOR UPDATE");
                        $remaining = 0;
                        foreach ($activeAdmins as $adminRow) {
                            if ((int) $adminRow['user_id'] !== (int) $current['user_id']) { $remaining++; }
                        }
                        if ($remaining < 1) { throw new RuntimeException('last-admin'); }
                    }
                    $changes = [];
                    foreach (['full_name', 'role', 'status'] as $f) { if ((string) $current[$f] !== (string) $v[$f]) { $changes[] = $f . ': ' . $current[$f] . ' -> ' . $v[$f]; } }
                    if ((string) $current['email'] !== (string) ($email ?? '')) { $changes[] = 'email changed'; }
                    if ((string) $current['contact_number'] !== (string) ($phone ?? '')) { $changes[] = 'contact number changed'; }
                    db_exec('UPDATE users SET full_name = ?, role = ?, status = ?, email = ?, contact_number = ? WHERE user_id = ?',
                        [$v['full_name'], $v['role'], $v['status'], $email, $phone, $current['user_id']]);
                    audit('user_updated', 'Users', 'Success', ($changes ? implode('; ', $changes) : 'No field changed') . ' [' . $current['username'] . ']', 'user', (int) $current['user_id']);
                });
            });
        } catch (RuntimeException $e) {
            $errors[] = match ($e->getMessage()) {
                'last-admin' => 'At least one active Administrator must remain.',
                'stale-user' => 'This account changed while you were editing it. Reload the page and try again.',
                'missing-user' => 'That user no longer exists. Reload the page.',
                default => 'The account could not be updated right now. Please try again.',
            };
        }
        if (!$errors) {
            flash_add('success', 'User updated.');
            redirect('admin/user_edit.php', ['id' => (int) $target['user_id']]);
        }
    }
}

$title = $target ? 'Manage user' : 'New user';
render_header($title, [['User Accounts', 'admin/users.php'], [$title, null]], 'users');
?>
<div class="page-head"><div><h1><?= e($title) ?></h1><p><?= $target ? e((string) $target['username']) . ' · last sign-in ' . e(fmt_dt($target['last_login_at'])) : 'The user must change the temporary password at first sign-in.' ?></p></div></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card-b"><div class="card-body-b pt-4">
    <form method="post" action="<?= e(base_url('admin/user_edit.php' . ($target ? '?id=' . (int) $target['user_id'] : ''))) ?>" class="needs-validation" novalidate autocomplete="off">
      <?= csrf_field() ?>
      <?php if (!$target): ?>
      <div class="mb-3"><label class="form-label req" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e($v['username']) ?>" required pattern="[a-z0-9._\-]{3,30}" maxlength="30" autocapitalize="none"></div>
      <?php endif; ?>
      <div class="mb-3"><label class="form-label req" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?= e($v['full_name']) ?>" required maxlength="100"></div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label req" for="role">Role</label>
          <select class="form-select" id="role" name="role" required<?= $isSelf ? ' disabled' : '' ?>><?php foreach (ROLES as $r): ?><option value="<?= e($r) ?>"<?= $v['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select>
          <?php if ($isSelf): ?><div class="form-text">You cannot change your own role.</div><?php endif; ?></div>
        <?php if ($target): ?><div class="col-md-6 mb-3"><label class="form-label" for="status">Status</label>
          <select class="form-select" id="status" name="status"<?= $isSelf ? ' disabled' : '' ?>><option value="active"<?= $v['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $v['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div><?php endif; ?>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label" for="email">Email (optional)</label><input class="form-control" id="email" name="email" type="email" value="<?= e($v['email']) ?>" maxlength="100"></div>
        <div class="col-md-6 mb-3"><label class="form-label" for="contact_number">Contact number (optional)</label><input class="form-control" id="contact_number" name="contact_number" value="<?= e($v['contact_number']) ?>" maxlength="20" placeholder="09171234567"></div>
      </div>
      <?php if (!$target): ?>
      <div class="mb-3"><label class="form-label" for="temp_password">Temporary password (optional)</label><input class="form-control" id="temp_password" name="temp_password" type="text" maxlength="72" autocomplete="off"><div class="form-text">Leave blank and the system generates one and shows it once.</div></div>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit"><?= $target ? 'Save changes' : 'Create user' ?></button>
      <a class="btn btn-light border" href="<?= e(base_url('admin/users.php')) ?>">Cancel</a>
    </form>
  </div></div></div>

  <?php if ($target && !$isSelf): ?>
  <div class="col-lg-5"><div class="card-b"><div class="card-h"><h2>Security actions</h2><p>Each action is recorded in the audit trail.</p></div><div class="card-body-b">
    <?php $act = function (string $action, string $label, string $icon, string $confirm, string $cls = 'btn-light border') use ($target): void { ?>
      <form method="post" action="<?= e(base_url('admin/user_action.php')) ?>" class="mb-2" data-confirm="<?= e($confirm) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $target['user_id'] ?>"><input type="hidden" name="action" value="<?= e($action) ?>">
        <button class="btn <?= e($cls) ?> w-100 text-start" type="submit"><i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?></button>
      </form>
    <?php }; ?>
    <?php $act('reset_password', 'Set a new temporary password', 'bi-key', 'Generate a new temporary password for this user? Their current password stops working.'); ?>
    <?php $act('unlock', 'Unlock sign-in', 'bi-unlock', 'Clear the failed sign-in attempts for this user?'); ?>
    <?php if ($target['status'] === 'active') { $act('deactivate', 'Deactivate account', 'bi-person-dash', 'Deactivate this account? The user is signed out immediately.', 'btn-outline-danger'); } else { $act('activate', 'Reactivate account', 'bi-person-check', 'Reactivate this account?'); } ?>
  </div></div></div>
  <?php endif; ?>
</div>
<?php render_footer();
