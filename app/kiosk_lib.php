<?php
/** Public kiosk session and resident identity verification helpers. */
declare(strict_types=1);

const KIOSK_IDLE_SECONDS = 90;
const KIOSK_CODE_TTL_SECONDS = 300;
const KIOSK_CODE_MAX_ATTEMPTS = 5;

function kiosk_flash(string $message, string $tone = 'info'): void
{
    $_SESSION['kiosk_flash'] = ['message' => $message, 'tone' => $tone];
}

function kiosk_take_flash(): ?array
{
    $flash = $_SESSION['kiosk_flash'] ?? null;
    unset($_SESSION['kiosk_flash']);
    return is_array($flash) ? $flash : null;
}

/** Remove one resident's temporary receipt and ticket data before kiosk reuse. */
function kiosk_clear_session_artifacts(): void
{
    unset(
        $_SESSION['kiosk_last_queue_ticket'],
        $_SESSION['kiosk_last_request'],
        $_SESSION['kiosk_last_venue'],
        $_SESSION['kiosk_last_blotter_request']
    );
}

function kiosk_clear_session(): void
{
    unset(
        $_SESSION['kiosk_resident_id'],
        $_SESSION['kiosk_last_activity'],
        $_SESSION['kiosk_expires_at'],
        $_SESSION['kiosk_challenge_token'],
        $_SESSION['kiosk_flash']
    );
    kiosk_clear_session_artifacts();
}

function kiosk_set_resident(int $residentId): void
{
    $resident = db_one(
        'SELECT resident_id, archived_at FROM residents WHERE resident_id = ?',
        [$residentId]
    );
    if ($resident === null || $resident['archived_at'] !== null) {
        throw new RuntimeException('The resident record is not active. Please ask Staff for assistance.');
    }
    // A physical kiosk browser is reused by different residents. Never carry the
    // previous visitor's ticket or receipt payload into the next verified session.
    kiosk_clear_session_artifacts();
    // A new visitor must not inherit a flash message containing the previous visitor's
    // masked email/phone destination or request information.
    unset($_SESSION['kiosk_flash']);
    session_regenerate_id(true);
    $_SESSION['kiosk_resident_id'] = $residentId;
    $_SESSION['kiosk_last_activity'] = time();
    $_SESSION['kiosk_expires_at'] = time() + KIOSK_IDLE_SECONDS;
    unset($_SESSION['kiosk_challenge_token']);
}

function kiosk_current_resident(bool $touch = true): ?array
{
    $id = $_SESSION['kiosk_resident_id'] ?? null;
    $last = (int) ($_SESSION['kiosk_last_activity'] ?? 0);
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) { return null; }
    if ($last < 1 || time() - $last > KIOSK_IDLE_SECONDS) {
        kiosk_clear_session();
        return null;
    }
    $resident = db_one(
        'SELECT resident_id, resident_no, last_name, first_name, middle_name, suffix, address, purok_id,
                resident_status_id, contact_number, email_address, archived_at
         FROM residents WHERE resident_id = ?',
        [(int) $id]
    );
    if ($resident === null || $resident['archived_at'] !== null) {
        kiosk_clear_session();
        return null;
    }
    if ($touch) {
        $_SESSION['kiosk_last_activity'] = time();
        $_SESSION['kiosk_expires_at'] = time() + KIOSK_IDLE_SECONDS;
    }
    return $resident;
}

function kiosk_require_resident(): array
{
    $resident = kiosk_current_resident();
    if ($resident === null) {
        kiosk_flash('Your kiosk session ended. Please verify your identity again.', 'warning');
        redirect('kiosk/index.php');
    }
    return $resident;
}

/** Mask the email address used for one-time kiosk verification. */
function kiosk_mask_destination(string $destination): string
{
    $parts = explode('@', trim($destination), 2);
    if (count($parts) !== 2) { return 'email address'; }
    $name = $parts[0];
    $masked = ($name !== '' ? substr($name, 0, 1) : '') . str_repeat('•', max(2, min(6, strlen($name) - 1)));
    return $masked . '@' . $parts[1];
}

/** Send a one-time identity code to the resident's registered email only. */
function kiosk_send_verification_code(array $resident): array
{
    $residentId = (int) ($resident['resident_id'] ?? 0);
    if ($residentId < 1) {
        return ['ok' => false, 'message' => 'The resident could not be verified. Please visit the Staff counter.'];
    }
    rate_limit_require('kiosk_identity_resident', (string) $residentId, 3, 900,
        'Too many verification codes were requested for this resident. Please wait 15 minutes or visit Staff.');

    $email = trim((string) ($resident['email_address'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !email_provider_is_enabled()) {
        return ['ok' => false, 'message' => 'No configured email address is available for this resident. Please ask Staff to verify your identity at the counter.'];
    }

    $token = bin2hex(random_bytes(24));
    $code = (string) random_int(100000, 999999);
    $tokenHash = hash('sha256', $token);
    $codeHash = hash('sha256', $token . ':' . $code);
    db_exec(
        "INSERT INTO resident_verification_challenges
         (challenge_token_hash, code_hash, resident_id, channel, destination_snapshot, expires_at, attempt_count)
         VALUES (?, ?, ?, 'email', ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0)",
        [$tokenHash, $codeHash, $residentId, $email, KIOSK_CODE_TTL_SECONDS]
    );
    $challengeId = (int) db()->lastInsertId();
    $message = 'Your Barangay service kiosk verification code is ' . $code . '. It expires in 5 minutes. Do not share this code with anyone.';
    try {
        $notificationId = email_queue_notification($residentId, $email, 'Barangay kiosk verification code', $message, 'resident_verification', null);
        $result = email_send_notification($notificationId);
        if (empty($result['ok'])) {
            db_exec('UPDATE resident_verification_challenges SET consumed_at = NOW() WHERE challenge_id = ?', [$challengeId]);
            return ['ok' => false, 'message' => 'The verification email could not be sent. Please ask Staff to verify your identity at the counter.'];
        }
        $_SESSION['kiosk_challenge_token'] = $token;
        return ['ok' => true, 'channel' => 'email', 'destination' => kiosk_mask_destination($email)];
    } catch (Throwable $emailError) {
        db_exec('UPDATE resident_verification_challenges SET consumed_at = NOW() WHERE challenge_id = ?', [$challengeId]);
        error_log('BIRMS kiosk verification email failed: ' . get_class($emailError));
        return ['ok' => false, 'message' => 'The verification email could not be sent. Please ask Staff to verify your identity at the counter.'];
    }
}

/** Verify a one-time code, enforce expiry/attempt limits, and establish a short resident-only kiosk session. */
/** Consume a valid one-time code in one transaction, returning its resident only after commit. */
function kiosk_consume_verification_code(string $token, string $code): ?int
{
    if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1 || preg_match('/^\\d{6}$/', $code) !== 1) { return null; }
    $tokenHash = hash('sha256', $token);
    return db_transaction(function () use ($tokenHash, $token, $code): ?int {
        $challenge = db_one(
            'SELECT challenge_id, resident_id, code_hash, expires_at, attempt_count, verified_at, consumed_at
             FROM resident_verification_challenges WHERE challenge_token_hash = ? FOR UPDATE',
            [$tokenHash]
        );
        if ($challenge === null || $challenge['consumed_at'] !== null || $challenge['verified_at'] !== null
            || strtotime((string) $challenge['expires_at']) < time()
            || (int) $challenge['attempt_count'] >= KIOSK_CODE_MAX_ATTEMPTS) {
            return null;
        }
        db_exec('UPDATE resident_verification_challenges SET attempt_count = attempt_count + 1 WHERE challenge_id = ?', [(int) $challenge['challenge_id']]);
        $candidate = hash('sha256', $token . ':' . $code);
        if (!hash_equals((string) $challenge['code_hash'], $candidate)) {
            audit_best_effort('kiosk_verification_failed', 'Security', 'Failed', 'A one-time kiosk verification code was incorrect.');
            return null;
        }
        $changed = db_exec(
            'UPDATE resident_verification_challenges SET verified_at = NOW(), consumed_at = NOW()
             WHERE challenge_id = ? AND verified_at IS NULL AND consumed_at IS NULL',
            [(int) $challenge['challenge_id']]
        );
        if ($changed !== 1) { return null; }
        return (int) $challenge['resident_id'];
    });
}

/** Verify a one-time code, enforce expiry/attempt limits, and establish a short resident-only kiosk session. */
function kiosk_verify_code(string $token, string $code): bool
{
    if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1 || preg_match('/^\\d{6}$/', $code) !== 1) { return false; }
    rate_limit_require('kiosk_verify_ip', client_ip(), 15, 600, 'Too many verification attempts from this device. Please wait 10 minutes.');

    // The challenge is consumed and committed before the PHP resident session is created.
    $residentId = kiosk_consume_verification_code($token, $code);
    if ($residentId === null) { return false; }

    try {
        kiosk_set_resident($residentId);
    } catch (Throwable $sessionError) {
        error_log('BIRMS kiosk identity was verified but the resident session could not be established: ' . get_class($sessionError));
        audit_best_effort('kiosk_session_establishment_failed', 'Security', 'Failed',
            'A verified kiosk challenge was consumed but a resident session could not be established.');
        return false;
    }
    audit_best_effort('kiosk_identity_verified', 'Kiosk', 'Success', 'Resident #' . $residentId . ' completed one-time identity verification.');
    return true;
}

function kiosk_page_start(string $title, string $subtitle = ''): void
{
    $flash = kiosk_take_flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer"><title>' . e($title) . ' · Barangay Kiosk</title>'
        . '<link rel="stylesheet" href="' . e(base_url('assets/css/kiosk.css')) . '">'
        . '<script defer src="' . e(base_url('assets/js/kiosk.js')) . '"></script></head>'
        . '<body data-kiosk-timeout="' . KIOSK_IDLE_SECONDS . '"><main class="kiosk-shell">'
        . '<header class="kiosk-header"><div class="kiosk-brand"><span class="kiosk-mark">B</span><span><strong>Barangay Services</strong><small>Resident Self-Service Kiosk</small></span></div>'
        . '<div class="kiosk-clock">' . e(date('D, d M Y · h:i A')) . '</div></header>'
        . '<section class="kiosk-heading"><h1>' . e($title) . '</h1>' . ($subtitle !== '' ? '<p>' . e($subtitle) . '</p>' : '') . '</section>';
    if ($flash !== null) {
        $tone = in_array($flash['tone'] ?? '', ['success','warning','error','info'], true) ? $flash['tone'] : 'info';
        echo '<div class="kiosk-alert kiosk-alert-' . e($tone) . '" role="status">' . e((string) $flash['message']) . '</div>';
    }
}

function kiosk_page_end(): void
{
    echo '<footer class="kiosk-footer"><span>BIRMS · Barangay Information and Records Management System</span><a href="' . e(base_url('kiosk/index.php')) . '">Home</a></footer>'
        // The inactivity timer uses this CSRF-protected POST form; end.php intentionally rejects GET.
        . '<form id="kiosk-timeout-form" method="post" action="' . e(base_url('kiosk/end.php')) . '" hidden>'
        . csrf_field()
        . '</form></main></body></html>';
}
