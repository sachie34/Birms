<?php
/** Complete fallback identity verification using the one-time code. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

if (kiosk_current_resident(false) !== null) { redirect('kiosk/index.php'); }
$token = (string) ($_SESSION['kiosk_challenge_token'] ?? '');
if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
    kiosk_flash('There is no active verification code. Please start again.', 'warning');
    redirect('kiosk/index.php');
}
if (is_post()) {
    require_csrf();
    $code = post_str('code');
    if (kiosk_verify_code($token, $code)) {
        kiosk_flash('Identity verified. Your kiosk session is now active.', 'success');
        $destination = $_SESSION['kiosk_after_verify'] ?? null;
        unset($_SESSION['kiosk_after_verify']);
        redirect($destination === 'request' ? 'kiosk/request.php' : 'kiosk/index.php');
    }
    kiosk_flash('The code is incorrect, expired or already used. Check the message and try again, or ask Staff for help.', 'error');
    redirect('kiosk/verify.php');
}
kiosk_page_start('Enter your verification code', 'Enter the six-digit code sent to your registered contact. The code can be used once and expires in five minutes.');
echo '<section class="kiosk-card"><form method="post" action="' . e(base_url('kiosk/verify.php')) . '" class="kiosk-form">'
    . csrf_field()
    . '<div class="kiosk-field"><label for="code">Six-digit verification code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus></div>'
    . '<button class="kiosk-primary" type="submit">Verify identity</button></form>'
    . '<a class="kiosk-secondary-link" href="' . e(base_url('kiosk/index.php')) . '">Start again</a></section>';
kiosk_page_end();
