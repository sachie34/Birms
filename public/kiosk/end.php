<?php
/** Clear the resident-only kiosk session. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
if (!is_post()) { abort(405, 'Kiosk session end must use POST.'); }
require_csrf();
kiosk_clear_session();
kiosk_flash('The kiosk session has ended. Your resident information is no longer active on this screen.', 'success');
redirect('kiosk/index.php');
