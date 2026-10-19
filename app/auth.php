<?php
/**
 * app/auth.php  (web only)
 * Sessions, login with lockout, per-request account re-check, idle timeout, CSRF.
 */
declare(strict_types=1);

const ROLES = ['admin', 'captain', 'secretary', 'clerk'];

function role_label(string $role): string
{
    return [
        'admin' => 'Administrator', 'captain' => 'Barangay Captain',
        'secretary' => 'Barangay Secretary', 'clerk' => 'Barangay Staff / Clerk',
    ][$role] ?? $role;
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $cfgSecure = config('session.secure');
    $secure = $cfgSecure === null ? is_https() : (bool) $cfgSecure;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name((string) config('session.name', 'BIRMSSESS'));
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function auth_reason(?string $set = null): ?string
{
    static $reason = null;
    if ($set !== null) { $reason = $set; }
    return $reason;
}

function session_destroy_clean(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }
    session_boot(); // fresh empty session so flash messages still work
}

/** The signed-in user, re-read from the database on EVERY request (finding M2). Null if none. */
function auth_user(): ?array
{
    static $done = false, $user = null;
    if ($done) { return $user; }
    $done = true;
    session_boot();
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid)) { return $user = null; }

    $idle = (int) round((float) config('session.idle_minutes', 30) * 60);
    $last = (int) ($_SESSION['last'] ?? 0);
    if ($idle > 0 && $last > 0 && (time() - $last) > $idle) {
        session_destroy_clean(); auth_reason('timeout'); return $user = null;
    }
    $row = db_one(
        'SELECT user_id, username, full_name, role, status, must_change_password, email, contact_number, last_login_at
         FROM users WHERE user_id = ?', [$uid]
    );
    if ($row === null || $row['status'] !== 'active') {
        session_destroy_clean(); auth_reason($row === null ? 'login' : 'inactive'); return $user = null;
    }
    $_SESSION['last'] = time();
    return $user = $row;
}

function require_login(bool $allowForcedChange = false): array
{
    $u = auth_user();
    if ($u === null) { redirect('index.php', ['m' => auth_reason() ?? 'login']); }
    if (!$allowForcedChange && (int) $u['must_change_password'] === 1) { redirect('account/password.php', ['m' => 'forced']); }
    return $u;
}

/* ---------------------------------------------------------------- CSRF */

function csrf_token(): string
{
    session_boot();
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
/** Call first in every POST handler. Rejects non-POST (405) and a missing/wrong token (403). */
function require_csrf(): void
{
    if (!is_post()) { abort(405, 'This action must be submitted from a form.'); }
    session_boot();
    $sent = $_POST['_csrf'] ?? '';
    $ok = is_string($sent) && isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
    if (!$ok) {
        audit_best_effort('csrf_rejected', 'Security', 'Failed', 'Missing or invalid security token on ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        abort(403, 'The security check failed. Go back, reload the page and try again.');
    }
}

/* ---------------------------------------------------------------- login */

function login_locked(string $username, string $ip): bool
{
    $win = (int) config('security.lockout_minutes', 15);
    $max = (int) config('security.max_failed_logins', 5);
    $ipMax = (int) config('security.ip_max_failed', 30);
    $byUser = (int) db_val('SELECT COUNT(*) FROM login_failures WHERE username = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)', [$username, $win]);
    if ($byUser >= $max) { return true; }
    $byIp = (int) db_val('SELECT COUNT(*) FROM login_failures WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)', [$ip, $win]);
    return $byIp >= $ipMax;
}
function login_record_failure(string $username, string $ip): void
{
    db_exec('INSERT INTO login_failures (username, ip_address) VALUES (?, ?)', [mb_substr_safe($username, 50), $ip]);
    db_exec('DELETE FROM login_failures WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}
function login_clear_failures(string $username): void
{
    db_exec('DELETE FROM login_failures WHERE username = ?', [mb_substr_safe($username, 50)]);
}

/** Returns: ok | required | invalid | locked | inactive. Never says whether the username exists. */
function attempt_login(string $username, string $password): string
{
    $username = strtolower($username);
    if ($username === '' || $password === '') { return 'required'; }
    $ip = client_ip();
    if (login_locked($username, $ip)) {
        audit_best_effort('login_blocked', 'Auth', 'Failed', 'Locked out: ' . mb_substr_safe($username, 50), mb_substr_safe($username, 50));
        return 'locked';
    }
    $row = db_one('SELECT user_id, username, password_hash, status FROM users WHERE username = ?', [$username]);
    // Unknown users still pay for one password check, so timing does not reveal which usernames exist.
    $dummy = '$2y$10$9uXCrbqf2TVOiv2TGS4VBuV1vXyKll31q/AQAunWR4YnJrz1ByPi6';
    $valid = password_verify($password, $row['password_hash'] ?? $dummy) && $row !== null;
    if (!$valid) {
        login_record_failure($username, $ip);
        audit_best_effort('login_failed', 'Auth', 'Failed', 'Bad credentials for: ' . mb_substr_safe($username, 50), mb_substr_safe($username, 50));
        return 'invalid';
    }
    if ($row['status'] !== 'active') {
        audit_best_effort('login_refused', 'Auth', 'Failed', 'Inactive account: ' . $username, $username);
        return 'inactive';
    }
    session_boot();
    session_regenerate_id(true);               // new session id on login (prevents fixation)
    $_SESSION = ['uid' => (int) $row['user_id'], 'last' => time(), 'csrf' => bin2hex(random_bytes(32))];
    login_clear_failures($username);
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
    }
    db_exec('UPDATE users SET last_login_at = NOW() WHERE user_id = ?', [$row['user_id']]);
    audit('login', 'Auth', 'Success', 'Signed in', 'user', (int) $row['user_id'], $row['username'], (int) $row['user_id']);
    return 'ok';
}

function logout_user(): void
{
    $u = auth_user();
    if ($u !== null) { audit_best_effort('logout', 'Auth', 'Success', 'Signed out', $u['username']); }
    session_destroy_clean();
}
