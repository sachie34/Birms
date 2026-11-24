<?php
/** Verify Resident ID + birthdate, or initiate OTP for name + birthdate fallback. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

if (!is_post()) { redirect('kiosk/index.php'); }
require_csrf();
rate_limit_require('kiosk_identity_ip', client_ip(), 5, 600,
    'Too many identity attempts from this device. Please wait 10 minutes or visit Staff.');

// This browser may have been used by another resident. Clear the previous identity,
// challenge, flash and receipt data before evaluating the next visitor's credentials.
kiosk_clear_session();
session_regenerate_id(true);

$residentNo = post_str('resident_no');
$lastName = post_str('last_name');
$firstName = post_str('first_name');
$birthdate = post_str('birthdate');
$allowedDestinations = [
    'request' => 'kiosk/request.php',
    'blotter' => 'kiosk/blotter.php',
    'venue' => 'kiosk/venue.php',
    'queue' => 'kiosk/queue.php',
    'status' => 'kiosk/status.php',
];
$next = post_str('next');
if (!isset($allowedDestinations[$next])) { $next = ''; }
$_SESSION['kiosk_after_verify'] = $next !== '' ? $next : null;

if (!v_date($birthdate) || $birthdate > date('Y-m-d')) {
    kiosk_flash('Enter your correct birthdate.', 'error');
    redirect($next !== '' ? 'kiosk/index.php?next=' . rawurlencode($next) : 'kiosk/index.php');
}

if ($residentNo !== '') {
    if (str_len($residentNo) > 30 || preg_match('/^[A-Za-z0-9-]+$/', $residentNo) !== 1) {
        kiosk_flash('The details could not be verified. Check your Resident ID and birthdate or ask Staff for help.', 'error');
        redirect($next !== '' ? 'kiosk/index.php?next=' . rawurlencode($next) : 'kiosk/index.php');
    }
    $matches = db_all(
        'SELECT resident_id FROM residents WHERE resident_no = ? AND birthdate = ? AND archived_at IS NULL LIMIT 2',
        [$residentNo, $birthdate]
    );
    if (count($matches) === 1) {
        kiosk_set_resident((int) $matches[0]['resident_id']);
        audit_best_effort('kiosk_identity_verified', 'Kiosk', 'Success', 'Resident verified using Resident ID and birthdate.');
        kiosk_flash('Identity verified. Continuing to your selected service.', 'success');
        $destination = (string) ($_SESSION['kiosk_after_verify'] ?? '');
        unset($_SESSION['kiosk_after_verify']);
        redirect($allowedDestinations[$destination] ?? 'kiosk/index.php');
    }
    audit_best_effort('kiosk_identity_denied', 'Kiosk', 'Failed', 'Resident ID and birthdate did not match an active resident record.');
    kiosk_flash('The details could not be verified. Check your Resident ID and birthdate or ask Staff for help.', 'error');
    redirect($next !== '' ? 'kiosk/index.php?next=' . rawurlencode($next) : 'kiosk/index.php');
}

if (!v_person_name($lastName) || !v_person_name($firstName)) {
    kiosk_flash('Enter a valid first and last name, or use your Resident ID.', 'error');
    redirect($next !== '' ? 'kiosk/index.php?next=' . rawurlencode($next) : 'kiosk/index.php');
}
$matches = db_all(
    'SELECT resident_id, email_address
     FROM residents WHERE LOWER(last_name) = LOWER(?) AND LOWER(first_name) = LOWER(?)
       AND birthdate = ? AND archived_at IS NULL LIMIT 3',
    [$lastName, $firstName, $birthdate]
);
if (count($matches) !== 1) {
    audit_best_effort('kiosk_identity_denied', 'Kiosk', 'Failed', 'Name and birthdate did not identify exactly one active resident record.');
    kiosk_flash(count($matches) > 1
        ? 'More than one record matches those details. Please ask Staff to verify your identity at the counter.'
        : 'The details could not be verified. Check your name and birthdate or ask Staff for help.', 'error');
    redirect($next !== '' ? 'kiosk/index.php?next=' . rawurlencode($next) : 'kiosk/index.php');
}
$sent = kiosk_send_verification_code($matches[0]);
if (!$sent['ok']) {
    kiosk_flash((string) $sent['message'], 'warning');
    redirect($next !== '' ? 'kiosk/index.php?next=' . rawurlencode($next) : 'kiosk/index.php');
}
kiosk_flash('A one-time code was sent to your registered ' . $sent['channel'] . ' (' . $sent['destination'] . '). It expires in 5 minutes.', 'success');
redirect('kiosk/verify.php');
