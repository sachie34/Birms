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
        $_SESSION['kiosk_after_verify']
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
                resident_status_id, contact_number, email_address, notification_preference, archived_at
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

function kiosk_mask_destination(string $channel, string $destination): string
{
    $destination = trim($destination);
    if ($channel === 'sms') {
        $digits = preg_replace('/\D/', '', $destination) ?? '';
        return $digits === '' ? 'mobile number' : '••••••' . substr($digits, -4);
    }
    $parts = explode('@', $destination, 2);
    if (count($parts) !== 2) { return 'email address'; }
    $name = $parts[0];
    $masked = ($name !== '' ? substr($name, 0, 1) : '') . str_repeat('•', max(2, min(6, strlen($name) - 1)));
    return $masked . '@' . $parts[1];
}

/**
 * Send a one-time identity code through an enabled resident contact channel.
 * The raw code is returned only in CLI test mode; web callers never receive it.
 */
function kiosk_send_verification_code(array $resident): array
{
    $residentId = (int) ($resident['resident_id'] ?? 0);
    if ($residentId < 1) {
        return ['ok' => false, 'message' => 'The resident could not be verified. Please visit the Staff counter.'];
    }
    rate_limit_require('kiosk_identity_resident', (string) $residentId, 3, 900,
        'Too many verification codes were requested for this resident. Please wait 15 minutes or visit Staff.');

    $email = trim((string) ($resident['email_address'] ?? ''));
    $mobile = trim((string) ($resident['contact_number'] ?? ''));
    $preference = (string) ($resident['notification_preference'] ?? 'email');
    $smsEnabled = $mobile !== '' && sms_provider_is_enabled();
    $emailEnabled = $email !== '' && email_provider_is_enabled();

    $channel = null;
    if ($preference === 'sms' && $smsEnabled) { $channel = 'sms'; }
    elseif ($preference === 'both' && $smsEnabled) { $channel = 'sms'; }
    elseif ($preference !== 'sms' && $emailEnabled) { $channel = 'email'; }
    elseif ($smsEnabled) { $channel = 'sms'; }
    elseif ($emailEnabled) { $channel = 'email'; }
    if ($channel === null) {
        return ['ok' => false, 'message' => 'A one-time code cannot be delivered because no verified email or SMS service is configured. Please ask Staff to verify your record at the counter.'];
    }

    $token = bin2hex(random_bytes(24));
    $code = (string) random_int(100000, 999999);
    $tokenHash = hash('sha256', $token);
    $codeHash = hash('sha256', $token . ':' . $code);
    $destination = $channel === 'sms' ? $mobile : $email;
    db_exec(
        'INSERT INTO resident_verification_challenges
         (challenge_token_hash, code_hash, resident_id, channel, destination_snapshot, expires_at, attempt_count)
         VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0)',
        [$tokenHash, $codeHash, $residentId, $channel, $destination, KIOSK_CODE_TTL_SECONDS]
    );
    $challengeId = (int) db()->lastInsertId();
    $message = 'Your Barangay service kiosk verification code is ' . $code . '. It expires in 5 minutes. Do not share this code with anyone.';

    if ($channel === 'sms') {
        db_exec(
            "INSERT INTO sms_outbox (recipient_number, message, trigger_event, request_id, delivery, provider_name, provider_status)
             VALUES (?, ?, 'resident_verification', NULL, 'Waiting to send', ?, 'not_attempted')",
            [$mobile, mb_substr_safe($message, 480), sms_provider_selected_name()]
        );
        $smsId = (int) db()->lastInsertId();
        $result = sms_send_outbox($smsId);
        $status = (string) ($result['status'] ?? 'unknown');
        if (in_array($status, ['queued','pending','sent'], true)) {
            $_SESSION['kiosk_challenge_token'] = $token;
            return ['ok' => true, 'channel' => 'sms', 'destination' => kiosk_mask_destination('sms', $destination), 'token' => $token];
        }
        // A confirmed failure may fall back to email. Unknown is not retried because the
        // provider may already have accepted the SMS.
        if (in_array($status, ['failed','refunded','not_configured','no_number'], true) && $emailEnabled) {
            db_exec('UPDATE resident_verification_challenges SET channel = \'email\', destination_snapshot = ? WHERE challenge_id = ?', [$email, $challengeId]);
            $notificationId = email_queue_notification($residentId, $email, 'Barangay kiosk verification code', $message, 'resident_verification', null);
            $emailResult = email_send_notification($notificationId);
            if (!empty($emailResult['ok'])) {
                $_SESSION['kiosk_challenge_token'] = $token;
                return ['ok' => true, 'channel' => 'email', 'destination' => kiosk_mask_destination('email', $email), 'token' => $token];
            }
        }
        db_exec('UPDATE resident_verification_challenges SET consumed_at = NOW() WHERE challenge_id = ?', [$challengeId]);
        return ['ok' => false, 'message' => 'The verification message could not be sent or confirmed. Please ask Staff to verify your record at the counter.'];
    }

    $notificationId = email_queue_notification($residentId, $email, 'Barangay kiosk verification code', $message, 'resident_verification', null);
    $result = email_send_notification($notificationId);
    if (!empty($result['ok'])) {
        $_SESSION['kiosk_challenge_token'] = $token;
        return ['ok' => true, 'channel' => 'email', 'destination' => kiosk_mask_destination('email', $destination), 'token' => $token];
    }
    db_exec('UPDATE resident_verification_challenges SET consumed_at = NOW() WHERE challenge_id = ?', [$challengeId]);
    return ['ok' => false, 'message' => 'The verification email could not be sent. Please ask Staff to verify your record at the counter.'];
}

/** Verify a one-time code, enforce expiry/attempt limits, and establish a short resident-only kiosk session. */
function kiosk_verify_code(string $token, string $code): bool
{
    if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1 || preg_match('/^\d{6}$/', $code) !== 1) { return false; }
    rate_limit_require('kiosk_verify_ip', client_ip(), 15, 600, 'Too many verification attempts from this device. Please wait 10 minutes.');
    $tokenHash = hash('sha256', $token);
    return db_transaction(function () use ($tokenHash, $token, $code): bool {
        $challenge = db_one(
            'SELECT challenge_id, resident_id, code_hash, expires_at, attempt_count, verified_at, consumed_at
             FROM resident_verification_challenges WHERE challenge_token_hash = ? FOR UPDATE',
            [$tokenHash]
        );
        if ($challenge === null || $challenge['consumed_at'] !== null || $challenge['verified_at'] !== null
            || strtotime((string) $challenge['expires_at']) < time()
            || (int) $challenge['attempt_count'] >= KIOSK_CODE_MAX_ATTEMPTS) {
            return false;
        }
        db_exec('UPDATE resident_verification_challenges SET attempt_count = attempt_count + 1 WHERE challenge_id = ?', [(int) $challenge['challenge_id']]);
        $candidate = hash('sha256', $token . ':' . $code);
        if (!hash_equals((string) $challenge['code_hash'], $candidate)) {
            audit_best_effort('kiosk_verification_failed', 'Security', 'Failed', 'A one-time kiosk verification code was incorrect.');
            return false;
        }
        db_exec(
            'UPDATE resident_verification_challenges SET verified_at = NOW(), consumed_at = NOW()
             WHERE challenge_id = ? AND verified_at IS NULL AND consumed_at IS NULL',
            [(int) $challenge['challenge_id']]
        );
        kiosk_set_resident((int) $challenge['resident_id']);
        audit_best_effort('kiosk_identity_verified', 'Kiosk', 'Success', 'Resident #' . (int) $challenge['resident_id'] . ' completed one-time identity verification.');
        return true;
    });
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
        . '</main></body></html>';
}
