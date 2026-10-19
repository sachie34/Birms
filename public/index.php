<?php
/** Sign-in page. Messages come from fixed codes (?m=), never from free text in the URL. */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (auth_user() !== null) { redirect('dashboard.php'); }

if (is_post()) {
    require_csrf();
    $code = attempt_login(post_str('username'), post_raw('password'));
    redirect($code === 'ok' ? 'dashboard.php' : 'index.php', $code === 'ok' ? [] : ['m' => $code]);
}

$messages = [
    'invalid'  => ['danger', 'Invalid username or password.'],
    'required' => ['danger', 'Enter your username and password.'],
    'locked'   => ['danger', 'Too many failed attempts. Please wait about 15 minutes, or ask the administrator to unlock the account.'],
    'inactive' => ['danger', 'This account is inactive. Contact the administrator.'],
    'timeout'  => ['info', 'Your session expired. Please sign in again.'],
    'login'    => ['info', 'Please sign in to continue.'],
    'bye'      => ['success', 'You have been signed out.'],
];
$m = $messages[get_str('m')] ?? null;

render_header('Sign in');
?>
<div class="login-card">
  <div class="login-head">
    <div class="brand-mark">B</div>
    <h1>Barangay Wawa</h1>
    <p>Information and Records Management System</p>
  </div>
  <div class="login-body">
    <?php if ($m): ?><div class="alert alert-<?= e($m[0]) ?> py-2" role="alert"><?= e($m[1]) ?></div><?php endif; ?>
    <form method="post" action="<?= e(base_url('index.php')) ?>" class="needs-validation" novalidate autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label req" for="username">Username</label>
        <input class="form-control" id="username" name="username" type="text" maxlength="30" required autofocus autocapitalize="none">
      </div>
      <div class="mb-3">
        <label class="form-label req" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" maxlength="72" required autocomplete="current-password">
      </div>
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button>
    </form>
    <p class="text-center text-muted small mt-3 mb-0">Forgot your password? Ask the administrator to reset it.</p>
  </div>
</div>
<?php render_footer();
