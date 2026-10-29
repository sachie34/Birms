<?php
/**
 * app/core.php
 * Loaded by web pages AND command-line tools. Config, helpers, database, validators, audit.
 * Database credentials live ONLY in app/config.local.php (or environment variables).
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', APP_ROOT . '/public');

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
                'password_min_length' => 10, 'csp' => true,
            ],
            'backup' => [
                'webdav_url' => '', 'webdav_username' => '', 'webdav_password' => '',
            ],
            // Outbound notifications remain disabled until an operator explicitly configures a provider.
            'sms' => [
                'provider' => 'smsapiph',
                'smsapiph_enabled' => false, 'smsapiph_api_key' => '',
                'semaphore_enabled' => false, 'semaphore_api_key' => '', 'semaphore_sendername' => '',
                'infobip_enabled' => false, 'infobip_api_key' => '', 'infobip_base_url' => '', 'infobip_sender' => 'ServiceSMS',
                'connect_timeout' => 4, 'timeout' => 12,
            ],
        ];
        $local = __DIR__ . '/config.local.php';
        if (is_file($local)) {
            $o = require $local;
            if (is_array($o)) { $cfg = array_replace_recursive($cfg, $o); }
        }
        // Optional dedicated, Git-ignored Infobip credentials file. This file stays outside
        // the public web root and only provides the key, Base URL, and sender value.
        $infobipLocal = __DIR__ . '/infobip.local.php';
        if (is_file($infobipLocal)) {
            $infobip = require $infobipLocal;
            if (is_array($infobip)) {
                if (array_key_exists('api_key', $infobip)) {
                    $cfg['sms']['infobip_api_key'] = is_string($infobip['api_key']) ? trim($infobip['api_key']) : '';
                }
                if (array_key_exists('base_url', $infobip)) {
                    $cfg['sms']['infobip_base_url'] = is_string($infobip['base_url']) ? trim($infobip['base_url']) : '';
                }
                if (array_key_exists('sender', $infobip)) {
                    $cfg['sms']['infobip_sender'] = is_string($infobip['sender']) ? trim($infobip['sender']) : '';
                }
            }
        }

        // Optional dedicated, Git-ignored SMS API PH credentials file. A blank example key
        // must not erase a key explicitly entered in config.local.php.
        $smsapiphLocal = __DIR__ . '/smsapiph.local.php';
        if (is_file($smsapiphLocal)) {
            $smsapiph = require $smsapiphLocal;
            if (is_array($smsapiph) && isset($smsapiph['api_key']) && is_string($smsapiph['api_key'])
                && trim($smsapiph['api_key']) !== '') {
                $cfg['sms']['smsapiph_api_key'] = trim($smsapiph['api_key']);
            }
        }

        $envMap = [
            'BIRMS_DB_HOST' => ['db', 'host'], 'BIRMS_DB_PORT' => ['db', 'port'], 'BIRMS_DB_NAME' => ['db', 'name'],
            'BIRMS_DB_USER' => ['db', 'user'], 'BIRMS_DB_PASS' => ['db', 'pass'], 'BIRMS_ENV' => ['env', null],
            'BIRMS_IDLE_MINUTES' => ['session', 'idle_minutes'],
            'BIRMS_BACKUP_WEBDAV_URL' => ['backup', 'webdav_url'],
            'BIRMS_BACKUP_WEBDAV_USERNAME' => ['backup', 'webdav_username'],
            'BIRMS_BACKUP_WEBDAV_PASSWORD' => ['backup', 'webdav_password'],
            'BIRMS_SEMAPHORE_ENABLED' => ['sms', 'semaphore_enabled'],
            'BIRMS_SEMAPHORE_API_KEY' => ['sms', 'semaphore_api_key'],
            'BIRMS_SEMAPHORE_SENDERNAME' => ['sms', 'semaphore_sendername'],
            'BIRMS_SMS_PROVIDER' => ['sms', 'provider'],
            'BIRMS_SMSAPIPH_ENABLED' => ['sms', 'smsapiph_enabled'],
            'BIRMS_SMSAPIPH_API_KEY' => ['sms', 'smsapiph_api_key'],
            'BIRMS_INFOBIP_ENABLED' => ['sms', 'infobip_enabled'],
            'BIRMS_INFOBIP_API_KEY' => ['sms', 'infobip_api_key'],
            'BIRMS_INFOBIP_BASE_URL' => ['sms', 'infobip_base_url'],
            'BIRMS_INFOBIP_SENDER' => ['sms', 'infobip_sender'],
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

/**
 * Serialize critical administrative invariants across concurrent requests.
 * MySQL/MariaDB named locks are connection-scoped and released in finally.
 */
function with_named_lock(string $name, callable $fn, int $timeoutSeconds = 5): mixed
{
    if (preg_match('/^[A-Za-z0-9:_-]{1,64}$/D', $name) !== 1) {
        throw new InvalidArgumentException('Invalid application lock name.');
    }
    $timeoutSeconds = max(0, min(30, $timeoutSeconds));
    if ((int) db_val('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds]) !== 1) {
        throw new RuntimeException('The requested operation is busy. Please try again.');
    }
    try {
        return $fn();
    } finally {
        try {
            db_val('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (Throwable $e) {
            error_log('BIRMS named-lock release failed for ' . $name . ': ' . $e->getMessage());
        }
    }
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
