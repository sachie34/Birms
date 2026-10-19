<?php
/** Change your own password. Also the only page a user with a temporary password may use. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$user = require_login(true);
$errors = [];

if (is_post()) {
    require_csrf();
    $cur = post_raw('current_password'); $new = post_raw('new_password'); $conf = post_raw('confirm_password');
    $hash = (string) db_val('SELECT password_hash FROM users WHERE user_id = ?', [$user['user_id']]);
    if (!password_verify($cur, $hash)) { $errors[] = 'Your current password is not correct.'; }
    if ($new !== $conf)                { $errors[] = 'The new password and its confirmation do not match.'; }
    if ($cur !== '' && $new === $cur)  { $errors[] = 'Choose a password different from the current one.'; }
    $errors = array_merge($errors, password_policy_errors($new, (string) $user['username']));
    if (!$errors) {
        db_transaction(function () use ($user, $new): void {
            db_exec('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);
            audit('password_changed', 'Auth', 'Success', 'Own password changed', 'user', (int) $user['user_id']);
        });
        session_regenerate_id(true);
        flash_add('success', 'Your password has been changed.');
        redirect('dashboard.php');
    }
    audit_best_effort('password_change_failed', 'Auth', 'Failed', 'Validation failed');
}
$forced = get_str('m') === 'forced' || (int) $user['must_change_password'] === 1;

render_header('Change password', [['Change password', null]]);
?>
<div class="page-head"><div><h1>Change password</h1><p>Use a password only you know.</p></div></div>
<div class="card-b col-lg-6">
  <div class="card-body-b pt-4">
    <?php if ($forced): ?><div class="alert alert-warning">You are using a temporary password. Choose a new one to continue.</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="<?= e(base_url('account/password.php')) ?>" class="needs-validation" novalidate autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label req" for="cp">Current password</label><input class="form-control" id="cp" name="current_password" type="password" maxlength="72" required autocomplete="current-password"></div>
      <div class="mb-3"><label class="form-label req" for="np">New password</label><input class="form-control" id="np" name="new_password" type="password" minlength="<?= (int) config('security.password_min_length', 10) ?>" maxlength="72" required autocomplete="new-password" data-match-source><div class="form-text">At least <?= (int) config('security.password_min_length', 10) ?> characters, with a letter and a number.</div></div>
      <div class="mb-3"><label class="form-label req" for="cf">Confirm new password</label><input class="form-control" id="cf" name="confirm_password" type="password" maxlength="72" required autocomplete="new-password" data-match-target><div class="invalid-feedback">The passwords do not match.</div></div>
      <button class="btn btn-primary" type="submit">Save new password</button>
    </form>
  </div>
</div>
<?php render_footer();
