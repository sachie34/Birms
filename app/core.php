<?php
/**
 * app/core.php
 * Loaded by web pages AND command-line tools. Config, helpers, database, validators, audit.
 * Database credentials live ONLY in app/config.local.php (or environment variables).
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', APP_ROOT . '/public');

/**
 * Return a user-safe reason when the active PHP runtime has not reached the
 * minimum security patch level pinned for each supported PHP branch. PHP 8.2
 * is accepted only while that branch remains security-supported.
 */
function php_runtime_security_issue(int $versionId, ?string $date = null): ?string
{
    $date ??= gmdate('Y-m-d');
    $php82StillSupported = $date < '2027-01-01';

    // Minimum patched releases as of 19 November 2026. Refresh these thresholds
    // whenever PHP publishes a security update for a supported branch.
    if ((($versionId >= 80236 && $versionId < 80300) && $php82StillSupported)
        || ($versionId >= 80337 && $versionId < 80400)
        || ($versionId >= 80428 && $versionId < 80500)
        || ($versionId >= 80513 && $versionId < 80600)
        || ($versionId >= 80600 && $versionId < 80700)) {
        return null;
    }

    if ($versionId >= 80500 && $versionId < 80600) {
        return 'PHP 8.5 must be updated to 8.5.13 or later within the 8.5 branch.';
    }
    if ($versionId >= 80400 && $versionId < 80500) {
        return 'PHP 8.4 must be updated to 8.4.28 or later within the 8.4 branch.';
    }
    if ($versionId >= 80300 && $versionId < 80400) {
        return 'PHP 8.3 must be updated to 8.3.37 or later within the 8.3 branch.';
    }
    if ($versionId >= 80200 && $versionId < 80300) {
        return $php82StillSupported
            ? 'PHP 8.2 must be updated to 8.2.36 or later. PHP 8.2 security support ends on 31 December 2026; upgrading to PHP 8.3 or newer is recommended.'
            : 'PHP 8.2 reached end of security support on 31 December 2026. Upgrade to a supported PHP branch before running BIRMS.';
    }
    return 'Use a supported stable PHP 8.2, 8.3, 8.4, 8.5, or 8.6 release at its required security patch level. See https://www.php.net/supported-versions.php.';
}

function php_runtime_upgrade_notice(int $versionId, ?string $date = null): ?string
{
    $date ??= gmdate('Y-m-d');
    return ($versionId >= 80200 && $versionId < 80300 && $date < '2027-01-01')
        ? 'WARNING: PHP 8.2 security support ends on 31 December 2026. Upgrade to PHP 8.3 or newer.'
        : null;
}

function config(?string $path = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'app_name' => 'BIRMS',
            'env'      => 'production',          // 'development' shows PHP errors on screen
            'timezone' => 'Asia/Manila',
            'base_url' => null,                  // null = auto-detect; or e.g. '/birms/public'
            'db' => [
                'host' => '127.0.0.1', 'port' => 3306,
                'name' => 'barangay_db_work',    // WORKING COPY. Never point tests at your original database.
                'user' => 'birms_app', 'pass' => '', 'charset' => 'utf8mb4',
            ],
            'session'  => ['name' => 'BIRMSSESS', 'idle_minutes' => 30, 'secure' => null], // secure null = auto (HTTPS only)
            'security' => [
                'max_failed_logins' => 5, 'lockout_minutes' => 15, 'ip_max_failed' => 30,
                'password_min_length' => 10, 'csp' => true, 'require_https' => false,
            ],
            'email' => ['enabled' => false, 'from_email' => '', 'from_name' => 'Barangay Office'],
            'backup' => [
                'webdav_url' => '', 'webdav_username' => '', 'webdav_password' => '',
            ],
        ];
        $local = __DIR__ . '/config.local.php';
        if (is_file($local)) {
            $o = require $local;
            if (is_array($o)) { $cfg = array_replace_recursive($cfg, $o); }
        }

        $envMap = [
            'BIRMS_DB_HOST' => ['db', 'host'],
            'BIRMS_EMAIL_ENABLED' => ['email', 'enabled'],
            'BIRMS_EMAIL_FROM' => ['email', 'from_email'],
            'BIRMS_EMAIL_FROM_NAME' => ['email', 'from_name'], 'BIRMS_DB_PORT' => ['db', 'port'], 'BIRMS_DB_NAME' => ['db', 'name'],
            'BIRMS_DB_USER' => ['db', 'user'], 'BIRMS_DB_PASS' => ['db', 'pass'], 'BIRMS_ENV' => ['env', null],
            'BIRMS_IDLE_MINUTES' => ['session', 'idle_minutes'],
            'BIRMS_REQUIRE_HTTPS' => ['security', 'require_https'],
            'BIRMS_BACKUP_WEBDAV_URL' => ['backup', 'webdav_url'],
            'BIRMS_BACKUP_WEBDAV_USERNAME' => ['backup', 'webdav_username'],
            'BIRMS_BACKUP_WEBDAV_PASSWORD' => ['backup', 'webdav_password'],
        ];
        foreach ($envMap as $name => [$a, $b]) {
            $v = getenv($name);
            if ($v !== false) { if ($b === null) { $cfg[$a] = $v; } else { $cfg[$a][$b] = $v; } }
        }
    }
    if ($path === null) { return $cfg; }
    $cur = $cfg;
    foreach (explode('.', $path) as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) { return $default; }
        $cur = $cur[$p];
    }
    return $cur;
}

/* ---------------------------------------------------------------- helpers */

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** REMOTE_ADDR only. We never trust X-Forwarded-For on a LAN deployment. */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $cfg = config('base_url');
        if (is_string($cfg)) {
            $base = rtrim($cfg, '/');
        } else {
            $base = '';
            $doc = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
            $pub = realpath(PUBLIC_ROOT);
            if ($doc !== false && $pub !== false) {
                $d = rtrim(str_replace('\\', '/', $doc), '/');
                $p = str_replace('\\', '/', $pub);
                if ($d !== '' && stripos($p, $d) === 0) { $base = substr($p, strlen($d)); }
            }
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path, array $query = []): never
{
    $u = base_url($path);
    if ($query) { $u .= '?' . http_build_query($query); }
    header('Location: ' . $u, true, 302);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/* Input helpers: always return a string or int, never an array (finding M4). Invalid UTF-8 becomes ''. */
function input_str(array $src, string $key, bool $multiline = false): string
{
    if (!isset($src[$key]) || !is_string($src[$key])) { return ''; }
    $v = trim($src[$key]);
    $v = preg_replace($multiline ? '/[^\P{C}\n\r\t]/u' : '/\p{C}/u', '', $v);
    return $v ?? '';
}
function post_str(string $key): string      { return input_str($_POST, $key); }
function post_text(string $key): string     { return input_str($_POST, $key, true); }
function get_str(string $key): string       { return input_str($_GET, $key); }
function post_raw(string $key): string      { return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : ''; }
function input_int(array $src, string $key): ?int
{
    $v = input_str($src, $key);
    return preg_match('/^\d{1,9}$/', $v) === 1 ? (int) $v : null;
}
function post_int(string $key): ?int { return input_int($_POST, $key); }
function get_int(string $key): ?int  { return input_int($_GET, $key); }

function str_len(string $s): int
{
    if (function_exists('mb_strlen')) { return mb_strlen($s, 'UTF-8'); }
    $n = preg_match_all('/./us', $s);
    return $n === false ? strlen($s) : $n;
}

function fmt_dt(?string $s): string
{
    if ($s === null || $s === '') { return '—'; }
    $t = strtotime($s);
    return $t === false ? '—' : date('d M Y H:i', $t);
}

/* Flash messages */
function flash_add(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/* ---------------------------------------------------------------- database */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) $c['port'], $c['name'], $c['charset']);
        $pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Strict SQL mode on every connection so bad data is rejected, not silently clamped (finding M10).
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+08:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
        ]);
    }
    return $pdo;
}
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}
function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql); $st->execute($params); $r = $st->fetch();
    return $r === false ? null : $r;
}
function db_val(string $sql, array $params = []): mixed
{
    $st = db()->prepare($sql); $st->execute($params); $r = $st->fetchColumn();
    return $r === false ? null : $r;
}
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql); $st->execute($params); return $st->rowCount();
}
/** Run $fn inside one transaction; an exception rolls everything back. Audit rows go in the same transaction. */
function db_transaction(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) { return $fn(); }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** Lock the current active administrator set; call only inside a transaction. */
function lock_active_admin_user_ids(): array
{
    if (!db()->inTransaction()) {
        throw new LogicException('Active administrator rows can only be locked inside a transaction.');
    }
    return array_map(
        static fn(array $row): int => (int) $row['user_id'],
        db_all("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY user_id FOR UPDATE")
    );
}

/* ---------------------------------------------------------------- validators */

function v_len(string $s, int $min, int $max): bool
{
    $n = str_len($s);
    return $n >= $min && $n <= $max;
}
/** Preserve CSV values while preventing spreadsheet formulas after leading ASCII whitespace/control characters. */
function csv_safe_cell(mixed $value): mixed
{
    if (!is_string($value) || $value === '') { return $value; }
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}
function v_username(string $u): bool       { return preg_match('/^[a-z0-9._-]{3,30}$/', $u) === 1; }
function v_person_name(string $n): bool    { return v_len($n, 2, 100) && preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'-]*$/u", $n) === 1; }
function v_email(string $e): bool          { return v_len($e, 3, 100) && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
/** Philippine numbers: 09XXXXXXXXX, +639XXXXXXXXX, or landline-style with area code (9-10 digits after 0). */
function v_phone(string $p): bool
{
    $c = preg_replace('/[\s-]/', '', $p) ?? '';
    return preg_match('/^(\+63|0)\d{9,10}$/', $c) === 1;
}
function password_policy_errors(string $pw, string $username = ''): array
{
    $errs = [];
    $min = (int) config('security.password_min_length', 10);
    if (strlen($pw) < $min)   { $errs[] = "Use at least {$min} characters."; }
    if (strlen($pw) > 72)     { $errs[] = 'Use at most 72 characters.'; }
    if (!preg_match('/\p{L}/u', $pw) || !preg_match('/\d/', $pw)) { $errs[] = 'Include at least one letter and one number.'; }
    if ($username !== '' && stripos($pw, $username) !== false)     { $errs[] = 'Do not include your username.'; }
    $common = ['password123', 'admin12345', 'barangay123', 'qwerty12345', '1234567890', 'birms12345'];
    if (in_array(strtolower($pw), $common, true)) { $errs[] = 'That password is too common.'; }
    return $errs;
}
function random_password(int $len = 12): string
{
    $letters = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz'; $digits = '23456789';
    $all = $letters . $digits; $out = $letters[random_int(0, strlen($letters) - 1)] . $digits[random_int(0, strlen($digits) - 1)];
    for ($i = strlen($out); $i < $len; $i++) { $out .= $all[random_int(0, strlen($all) - 1)]; }
    return str_shuffle($out);
}

/* ---------------------------------------------------------------- audit trail */

function audit_actor(): ?array
{
    return function_exists('auth_user') ? auth_user() : null;
}
/**
 * Write one audit row. THROWS on failure so callers inside db_transaction() roll back:
 * a state change is never saved without its audit entry (finding M9).
 */
function audit(string $action, string $module, string $status = 'Success', string $remarks = '', ?string $entityType = null, ?int $entityId = null, ?string $actorName = null, ?int $actorId = null): void
{
    $u = audit_actor();
    db_exec(
        'INSERT INTO audit_log (user_id, username_snapshot, action, module, entity_type, entity_id, status, remarks, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $actorId ?? ($u['user_id'] ?? null), $actorName ?? ($u['username'] ?? null), $action, $module,
            $entityType, $entityId, $status, mb_substr_safe($remarks, 1000), PHP_SAPI === 'cli' ? 'cli' : client_ip(),
        ]
    );
}
/** For events that change nothing (failed login, access denied): never let logging break the request. */
function audit_best_effort(string $action, string $module, string $status, string $remarks, ?string $actorName = null): void
{
    try { audit($action, $module, $status, $remarks, null, null, $actorName); }
    catch (Throwable $e) { error_log('BIRMS audit failed: ' . $e->getMessage()); }
}
function mb_substr_safe(string $s, int $len): string
{
    if (function_exists('mb_substr')) { return mb_substr($s, 0, $len, 'UTF-8'); }
    // No mbstring (some PHP setups): cut by characters, never in the middle of a multi-byte letter.
    return preg_match('/^.{0,' . max(0, $len) . '}/us', $s, $m) === 1 ? $m[0] : substr($s, 0, $len);
}


/**
 * Fixed-window, database-backed limiter. Raw identifiers (such as IP or resident ID)
 * are never stored; only a SHA-256 bucket digest is persisted.
 */
function rate_limit_allow(string $scope, string $identifier, int $limit, int $windowSeconds, ?int $now = null): bool
{
    $now ??= time();
    $scope = trim($scope);
    $identifier = trim($identifier);
    $limit = max(1, min(10000, $limit));
    $windowSeconds = max(1, min(86400, $windowSeconds));
    if ($scope === '' || $identifier === '') {
        throw new InvalidArgumentException('Rate-limit scope and identifier are required.');
    }
    $bucketHash = hash('sha256', $scope . "\0" . $identifier);
    $windowStart = date('Y-m-d H:i:s', intdiv($now, $windowSeconds) * $windowSeconds);
    db_exec(
        'INSERT INTO rate_limit_buckets (bucket_hash, window_started_at, hit_count)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE hit_count = hit_count + 1',
        [$bucketHash, $windowStart]
    );
    $hits = (int) db_val(
        'SELECT hit_count FROM rate_limit_buckets WHERE bucket_hash = ? AND window_started_at = ?',
        [$bucketHash, $windowStart]
    );
    if (random_int(1, 100) === 1) {
        db_exec('DELETE FROM rate_limit_buckets WHERE window_started_at < (NOW() - INTERVAL 2 DAY)');
    }
    return $hits <= $limit;
}

function rate_limit_require(string $scope, string $identifier, int $limit, int $windowSeconds, string $message = 'Too many attempts. Please try again later.'): void
{
    if (!rate_limit_allow($scope, $identifier, $limit, $windowSeconds)) {
        audit_best_effort('rate_limit_exceeded', 'Security', 'Failed', 'Rate limit exceeded for ' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $scope));
        abort(429, $message);
    }
}
