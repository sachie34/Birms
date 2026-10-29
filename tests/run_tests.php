<?php
/**
 * tests/run_tests.php  Automated checks for the B-batches built so far.
 *   php tests/run_tests.php [base_url] [--root=http://localhost/birms]
 *   default base_url: http://127.0.0.1:8080     (XAMPP example: http://localhost/birms/public)
 * Needs: PHP CLI with curl and pdo_mysql, and the web server running.
 * SAFETY: refuses to run unless the configured database name ends in _work or _test.
 * It creates users named zz_test_* and deletes them at the end.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
require __DIR__ . '/../app/core.php';
date_default_timezone_set((string) config('timezone', 'Asia/Manila'));   // same clock as the app, or date checks fail for 8 hours a day
require __DIR__ . '/../app/permissions.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "The PHP curl extension is required.\n"); exit(2); }

$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) { fwrite(STDERR, "REFUSED: database '{$dbName}' does not end in _work or _test. Tests only run on a working copy.\n"); exit(2); }

$base = rtrim('http://127.0.0.1:8080', '/'); $root = null;
foreach (array_slice($argv, 1) as $a) { if (str_starts_with($a, '--root=')) { $root = rtrim(substr($a, 7), '/'); } elseif (!str_starts_with($a, '--')) { $base = rtrim($a, '/'); } }
$basePath = (string) parse_url($base, PHP_URL_PATH);

require __DIR__ . '/lib.php';

echo "BIRMS test run  base={$base}  db={$dbName}\n" . str_repeat('-', 78) . "\n";

/* ------------------------------------------------------------ fixtures */
defined('TP') || define('TP', 'Test-Pass-2026a');
db_exec("DELETE FROM users WHERE username LIKE 'zz\\_test\\_%'");   // leftovers from an aborted run
$fx = [];
foreach (['admin', 'captain', 'secretary', 'clerk'] as $role) {
    $u = 'zz_test_' . $role; db_exec('DELETE FROM users WHERE username = ?', [$u]);
    db_exec("INSERT INTO users (username, password_hash, full_name, role, status, must_change_password) VALUES (?, ?, ?, ?, 'active', 0)", [$u, password_hash(TP, PASSWORD_DEFAULT), 'Test ' . ucfirst($role), $role]);
    $fx[$role] = $u;
}
db_exec("DELETE FROM login_failures WHERE username LIKE 'zz\\_test\\_%'");
$sessions = [];
foreach ($fx as $role => $u) { $sessions[$role] = new Http($base); $sessions[$role]->login($u, TP); }

/* ------------------------------------------------------------ unit checks */
$expect = ['admin' => 32, 'captain' => 15, 'secretary' => 24, 'clerk' => 12];
$counts = array_map('count', permission_map());
record('U1', 'Administrator has full module access; role counts match current map (32/15/24/12)', $counts === $expect, json_encode($counts));
record('U2', 'Deny by default; Secretary/Admin can review; Captain/Admin decide; Clerk print-only; Captain ledger read-only; Secretary cannot restore residents or manage users', !role_can('ghost', 'dashboard.view') && role_can('secretary', 'requests.review') && role_can('admin', 'requests.review') && !role_can('clerk', 'requests.review') && !role_can('secretary', 'requests.approve') && role_can('captain', 'requests.approve') && role_can('clerk', 'certificates.print') && !role_can('clerk', 'certificates.issue') && role_can('secretary', 'certificates.issue') && role_can('secretary', 'residents.archive') && !role_can('secretary', 'residents.restore') && role_can('admin', 'residents.restore') && role_can('secretary', 'settings.edit') && !role_can('secretary', 'users.manage') && role_can('captain', 'cash_ledger.view') && !role_can('captain', 'cash_ledger.manage') && role_can('admin', 'cash_ledger.manage') && !role_can('clerk', 'users.manage') && role_can('admin', 'residents.edit') && role_can('admin', 'reports.view') && !role_can('clerk', 'reports.view') && !role_can('clerk', 'nonsense.perm'));
$bad = ['short1', 'allletterspassword', '12345678901', 'zz_test_admin1X', 'Password123'];
$okPw = ['Tidy-Horse-2026'];
$pwOk = true; foreach ($bad as $p) { if (!password_policy_errors($p, 'zz_test_admin')) { $pwOk = false; } } foreach ($okPw as $p) { if (password_policy_errors($p, 'x')) { $pwOk = false; } }
record('U3', 'Password policy rejects weak passwords and accepts a good one', $pwOk);
record('U4', 'Input helpers never return arrays (finding M4)', input_str(['a' => ['x']], 'a') === '' && input_int(['a' => ['1']], 'a') === null && input_str(['a' => "ab\x00c"], 'a') === 'abc');
record('U5', 'Every database connection uses strict SQL mode: too-long data is rejected, not cut (finding M10)', str_contains((string) db_val('SELECT @@SESSION.sql_mode'), 'STRICT_ALL_TABLES') && (function (): bool { try { db_exec("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, 'h', 'n', 'clerk')", ['zz_test_' . str_repeat('x', 40)]); return false; } catch (Throwable $e) { return true; } })());
$noExtScript = tempnam(sys_get_temp_dir(), 'birms-no-mb-');
$noExt = 'Unable to create the no-mbstring probe script.';
if ($noExtScript !== false) {
    try {
        $probe = "<?php\nrequire " . var_export(APP_ROOT . '/app/core.php', true)
            . ";\necho str_len(" . var_export('Niño Dela Cruz', true)
            . "), \"|\", mb_substr_safe(" . var_export('Niño Dela', true)
            . ", 4), \"|\", mb_substr_safe(" . var_export('Ñoño', true) . ", 1);\n";
        if (file_put_contents($noExtScript, $probe) !== false) {
            $noExt = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($noExtScript) . ' 2>&1');
        } else {
            $noExt = 'Unable to write the no-mbstring probe script.';
        }
    } finally {
        @unlink($noExtScript);
    }
}
record('U6', 'Text helpers still work (by character) when the mbstring extension is missing (finding L3)', trim($noExt) === '14|Niño|Ñ', trim($noExt));
$csvGuardOk = csv_safe_cell('=1+1') === "'=1+1"
    && csv_safe_cell("\t=1+1") === "'\t=1+1"
    && csv_safe_cell('  @SUM(1,1)') === "'  @SUM(1,1)"
    && csv_safe_cell('ordinary text') === 'ordinary text'
    && csv_safe_cell(42) === 42;
record('U7', 'CSV formula guard neutralizes leading whitespace/control characters and preserves ordinary values', $csvGuardOk);
$guardEnvNames = ['BIRMS_SMS_PROVIDER', 'BIRMS_SMSAPIPH_ENABLED', 'BIRMS_DB_NAME'];
$oldGuardEnv = []; foreach ($guardEnvNames as $name) { $oldGuardEnv[$name] = getenv($name); }
$guardOutput = []; $guardExit = 0;
try {
    putenv('BIRMS_SMS_PROVIDER=smsapiph');
    putenv('BIRMS_SMSAPIPH_ENABLED=true');
    putenv('BIRMS_DB_NAME=barangay_db_work');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tests/run_modules.php') . ' 2>&1', $guardOutput, $guardExit);
} finally {
    foreach ($oldGuardEnv as $name => $value) {
        if ($value === false) { putenv($name); } else { putenv($name . '=' . $value); }
    }
}
$guardText = implode("\n", $guardOutput);
record('U8', 'Module test runner refuses before tests when a real SMS provider is enabled', $guardExit === 2 && str_contains($guardText, 'REFUSED: the selected SMS provider is enabled.') && !str_contains($guardText, 'BIRMS module tests  base='));
$phpFiles = []; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/public', FilesystemIterator::SKIP_DOTS)) as $f) { if (str_ends_with((string) $f, '.php')) { $phpFiles[] = (string) $f; } }
$pdoInPublic = array_filter($phpFiles, fn($f) => preg_match('/new PDO|mysql:host=/', (string) file_get_contents($f)));
record('S1', 'Database connection exists in ONE place, not in any public page (finding L4)', !$pdoInPublic);
$appPhpFiles = []; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app', FilesystemIterator::SKIP_DOTS)) as $f) { if (str_ends_with((string) $f, '.php')) { $appPhpFiles[] = (string) $f; } }
$inline = array_filter(array_merge($phpFiles, $appPhpFiles), fn($f) => preg_match('/\sstyle="|\son[a-z]+="|<script(?![^>]*\ssrc=)/i', (string) file_get_contents($f)));
record('S2', 'No inline styles or scripts (strict Content-Security-Policy)', !$inline, $inline ? implode(',', array_map('basename', $inline)) : '');

/* ------------------------------------------------------------ http: access control */
$anon = new Http($base);
$prot = ['/dashboard.php', '/admin/users.php', '/admin/user_edit.php', '/admin/audit.php', '/admin/audit_export.php', '/account/password.php', '/search.php'];
$okAll = true; foreach ($prot as $p) { $r = $anon->get($p); if ($r['status'] !== 302 || !str_contains(loc($r), 'index.php')) { $okAll = false; } }
record('H1', 'Protected pages redirect to sign-in when signed out', $okAll);
$r = $anon->get('/index.php'); $h = $r['headers'];
record('H2', 'Sign-in page loads with a CSRF token and security headers', $r['status'] === 200 && str_contains($r['body'], 'name="_csrf"') && ($h['x-content-type-options'] ?? '') === 'nosniff' && ($h['x-frame-options'] ?? '') === 'DENY' && isset($h['content-security-policy'], $h['referrer-policy']));
$r = $anon->post('/index.php', ['username' => $fx['clerk'], 'password' => TP]);
record('H3', 'Sign-in POST without a CSRF token is rejected (403)', $r['status'] === 403);
$r = $anon->login($fx['clerk'], 'Wrong-Pass-1'); $after = $anon->get('/dashboard.php');
record('H4', 'Wrong password: back to sign-in with a fixed message, no session', $r['status'] === 302 && str_contains(loc($r), 'm=invalid') && $after['status'] === 302);
$r = $anon->login("x' OR '1'='1", "x' OR '1'='1");
record('H5', 'SQL injection in the sign-in form is rejected', str_contains(loc($r), 'm=invalid'));
$pre = new Http($base); $pre->get('/index.php'); $before = file_get_contents($pre->jar);
preg_match('/BIRMSSESS\s+(\S+)/', (string) $before, $m1);
$r = $pre->login($fx['clerk'], TP); $cookie = implode(' | ', $r['cookies']); $afterJar = file_get_contents($pre->jar); preg_match('/BIRMSSESS\s+(\S+)/', (string) $afterJar, $m2);
record('H6', 'Sign-in works; cookie is HttpOnly + SameSite; session id changes at login', $r['status'] === 302 && str_contains(loc($r), 'dashboard.php') && stripos($cookie, 'httponly') !== false && stripos($cookie, 'samesite=lax') !== false && ($m1[1] ?? 'a') !== ($m2[1] ?? 'a'), $cookie === '' ? 'no cookie' : '');
$roleLabel = ['admin' => 'Administrator', 'captain' => 'Barangay Captain', 'secretary' => 'Barangay Secretary', 'clerk' => 'Barangay Staff'];
$okAll = true; foreach ($sessions as $role => $s) { $r = $s->get('/dashboard.php'); if ($r['status'] !== 200 || !str_contains($r['body'], $roleLabel[$role]) || leaks($r)) { $okAll = false; } }
record('H7', 'Dashboard loads for all four roles with no error text or file paths', $okAll);

/* RBAC matrix: users.manage = admin; audit.view = admin+captain; audit.export = admin */
$matrix = ['/admin/users.php' => ['admin'], '/admin/user_edit.php' => ['admin'], '/admin/audit.php' => ['admin', 'captain'], '/admin/audit_export.php' => ['admin']];
$okAll = true; $why = '';
foreach ($matrix as $path => $allowed) { foreach ($sessions as $role => $s) {
    $r = $s->get($path); $want = in_array($role, $allowed, true) ? 200 : 403;
    if ($r['status'] !== $want) { $okAll = false; $why .= "{$role} {$path} got {$r['status']} want {$want}; "; }
} }
record('H8', 'Role matrix enforced on the server for every admin page and role', $okAll, $why);
$tok = $sessions['clerk']->token('/dashboard.php'); $nUsers = (int) db_val('SELECT COUNT(*) FROM users');
$r = $sessions['clerk']->post('/admin/user_edit.php', ['_csrf' => $tok, 'username' => 'zz_test_evil', 'full_name' => 'Evil User', 'role' => 'admin']);
record('H9', 'A clerk cannot create users even with a valid token (403, nothing saved)', $r['status'] === 403 && (int) db_val('SELECT COUNT(*) FROM users') === $nUsers);

/* CSRF */
$a = $sessions['admin'];
$r = $a->post('/admin/user_edit.php', ['username' => 'zz_test_nocsrf', 'full_name' => 'No Token', 'role' => 'clerk']);
record('H10', 'POST without a CSRF token is rejected and saves nothing', $r['status'] === 403 && !db_val("SELECT COUNT(*) FROM users WHERE username = 'zz_test_nocsrf'"));
$r = $a->post('/admin/user_edit.php', ['_csrf' => str_repeat('a', 64), 'username' => 'zz_test_badcsrf', 'full_name' => 'Bad Token', 'role' => 'clerk']);
record('H11', 'POST with a WRONG CSRF token is rejected and saves nothing', $r['status'] === 403 && !db_val("SELECT COUNT(*) FROM users WHERE username = 'zz_test_badcsrf'"));
$r = $a->post('/admin/user_edit.php', ['_csrf' => $a->token('/admin/user_edit.php'), 'username' => 'zz_test_new1', 'full_name' => 'Fresh Person', 'role' => 'clerk', 'temp_password' => 'Temp-Pass-2026a', 'contact_number' => '09171234567']);
$created = db_one("SELECT user_id, must_change_password, status FROM users WHERE username = 'zz_test_new1'");
record('H12', 'Admin creates a user with a valid token; forced password change is set', $r['status'] === 302 && $created !== null && (int) $created['must_change_password'] === 1);
$r = $a->post('/admin/user_edit.php', ['_csrf' => $a->token('/admin/user_edit.php'), 'username' => 'bad name!', 'full_name' => '<b>x</b>', 'role' => 'hacker', 'email' => 'nope', 'contact_number' => '---']);
record('H13', 'Invalid user input is rejected with messages (200, nothing saved)', $r['status'] === 200 && str_contains($r['body'], 'alert-danger') && !db_val("SELECT COUNT(*) FROM users WHERE username = 'bad name!'"));

/* forced password change flow */
$f = new Http($base); $f->login('zz_test_new1', 'Temp-Pass-2026a');
$r1 = $f->get('/dashboard.php'); $r2 = $f->get('/admin/users.php'); $r3 = $f->get('/account/password.php');
record('H14', 'Temporary password forces the change page before anything else', str_contains(loc($r1), 'account/password.php') && str_contains(loc($r2), 'account/password.php') && $r3['status'] === 200);
$tk = $f->token('/account/password.php');
$weak = $f->post('/account/password.php', ['_csrf' => $tk, 'current_password' => 'Temp-Pass-2026a', 'new_password' => 'short', 'confirm_password' => 'short']);
$good = $f->post('/account/password.php', ['_csrf' => $f->token('/account/password.php'), 'current_password' => 'Temp-Pass-2026a', 'new_password' => 'Brand-New-Pass-77', 'confirm_password' => 'Brand-New-Pass-77']);
$dash = $f->get('/dashboard.php');
record('H15', 'Weak new password refused; strong one accepted; dashboard then opens', $weak['status'] === 200 && str_contains($weak['body'], 'alert-danger') && $good['status'] === 302 && $dash['status'] === 200);

/* lockout + unlock */
$lk = new Http($base); $lockUser = $fx['secretary'];
for ($i = 0; $i < 5; $i++) { $lk->login($lockUser, 'Wrong-Pass-' . $i); }
$r = $lk->login($lockUser, TP);
$blocked = str_contains(loc($r), 'm=locked');
$uid = (int) db_val('SELECT user_id FROM users WHERE username = ?', [$lockUser]);
$a->post('/admin/user_action.php', ['_csrf' => $a->token('/admin/user_edit.php?id=' . $uid), 'id' => $uid, 'action' => 'unlock']);
$r = $lk->login($lockUser, TP);
record('H16', 'Five wrong passwords lock sign-in (even with the right password); admin unlock restores it', $blocked && str_contains(loc($r), 'dashboard.php'));

/* deactivated account */
$dc = new Http($base); $dc->login($fx['clerk'], TP); $okBefore = $dc->get('/dashboard.php')['status'] === 200;
$cid = (int) db_val('SELECT user_id FROM users WHERE username = ?', [$fx['clerk']]);
$a->post('/admin/user_action.php', ['_csrf' => $a->token('/admin/user_edit.php?id=' . $cid), 'id' => $cid, 'action' => 'deactivate']);
$r = $dc->get('/dashboard.php'); $r2 = (new Http($base))->login($fx['clerk'], TP);
record('H17', 'Deactivating a user ends their open session at once and blocks new sign-in (finding M2)', $okBefore && $r['status'] === 302 && str_contains(loc($r2), 'm=inactive'));
$a->post('/admin/user_action.php', ['_csrf' => $a->token('/admin/user_edit.php?id=' . $cid), 'id' => $cid, 'action' => 'activate']);

/* self protection */
$aid = (int) db_val('SELECT user_id FROM users WHERE username = ?', [$fx['admin']]);
$a->post('/admin/user_action.php', ['_csrf' => $a->token('/dashboard.php'), 'id' => $aid, 'action' => 'deactivate']);
record('H18', 'An administrator cannot deactivate their own account', db_val('SELECT status FROM users WHERE user_id = ?', [$aid]) === 'active');

/* logout */
$lo = new Http($base); $lo->login($fx['captain'], TP);
$lo->get('/logout.php'); $stay = $lo->get('/dashboard.php')['status'] === 200;
$noTok = $lo->post('/logout.php', []); $stay2 = $lo->get('/dashboard.php')['status'] === 200;
$ok = $lo->post('/logout.php', ['_csrf' => $lo->token('/dashboard.php')]); $gone = $lo->get('/dashboard.php')['status'] === 302;
record('L1', 'Sign-out: GET link does nothing; POST needs a token; then the session is gone (finding L1)', $stay && $noTok['status'] === 403 && $stay2 && str_contains(loc($ok), 'm=bye') && $gone);

/* odd input */
$bad = [];
$resp = [
    $anon->get('/index.php?m[]=x'), $anon->post('/index.php', ['_csrf' => $anon->token('/index.php'), 'username' => ['a'], 'password' => ['b']]),
    $a->get('/admin/users.php?page[]=1&q[]=x&role[]=y&status[]=z'), $a->get('/admin/audit.php?from[]=x&to[]=y&user[]=1&module[]=m&status[]=s&q[]=q&page[]=2'),
    $a->get('/admin/users.php?page=-5&q=' . str_repeat('A', 5000)), $a->get('/admin/user_edit.php?id[]=1'), $a->get('/admin/user_edit.php?id=99999999'),
];
foreach ($resp as $i => $r) { if ($r['status'] >= 500 || leaks($r)) { $bad[] = $i . ':' . $r['status']; } }
record('M4', 'Array / oversized / odd parameters never crash a page or leak errors (finding M4)', !$bad, implode(',', $bad));
$r = $anon->get('/index.php?m=%3Cscript%3Ealert(1)%3C/script%3E'); $r2 = $anon->get('/index.php?m=Account+locked+call+0917');
record('L2', 'Message text in the URL is never displayed (finding L2)', !str_contains($r['body'], '<script>alert') && !str_contains($r2['body'], 'call 0917'));

/* every sidebar link must open for every role: no dead links (finding M6) */
$crawlBad = []; $crawled = 0;
foreach ($sessions as $role => $s) {
    $d = $s->get('/dashboard.php');
    preg_match_all('~class="nav-link-item[^"]*"\s+href="([^"]+)"~', $d['body'], $m);
    foreach (array_unique($m[1]) as $href) {
        $path = html_entity_decode($href);
        if ($basePath !== '' && str_starts_with($path, $basePath)) { $path = substr($path, strlen($basePath)); }
        $r = $s->get($path); $crawled++;
        if ($r['status'] !== 200 || leaks($r)) { $crawlBad[] = "{$role}:{$path}={$r['status']}"; }
    }
}
record('H19', 'Every sidebar link opens for every role; no dead links or error text (finding M6)', $crawled >= 10 && !$crawlBad, $crawlBad ? implode(', ', $crawlBad) : "{$crawled} links checked");

/* audit trail */
db_exec("INSERT INTO audit_log (username_snapshot, action, module, status, remarks) VALUES ('@sneaky', '=SUM(1+1)', 'Test', 'Success', '+cmd|calc')");
db_exec('INSERT INTO audit_log (username_snapshot, action, module, status, remarks) VALUES (?, ?, ?, ?, ?)', ["\t@control_formula", "\t=SUM(1+1)", 'Test', 'Success', "\t+cmd|calc"]);
$csv = $a->get('/admin/audit_export.php');
$lines = preg_split('/\r?\n/', ltrim($csv['body'], "\xEF\xBB\xBF")) ?: []; $formula = false;
foreach ($lines as $ln) { foreach (str_getcsv($ln, ',', '"', '\\') as $cell) { if (is_string($cell) && preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1) { $formula = true; } } }
record('A1', 'CSV export works for the admin and neutralizes spreadsheet formulas', $csv['status'] === 200 && str_contains($csv['headers']['content-type'] ?? '', 'text/csv') && str_starts_with($csv['body'], "\xEF\xBB\xBFWhen,User") && !$formula);
$av = $a->get('/admin/audit.php?status=Failed'); $av2 = $a->get('/admin/audit.php?from=2023-02-31&to=garbage');
record('A2', 'Audit viewer filters work; impossible dates are ignored', $av['status'] === 200 && str_contains($av['body'], 'pill-red') && $av2['status'] === 200);
$need = ['login' => 0, 'login_failed' => 0, 'login_blocked' => 0, 'user_created' => 0, 'access_denied' => 0, 'csrf_rejected' => 0, 'user_deactivated' => 0, 'user_unlocked' => 0, 'password_changed' => 0, 'logout' => 0, 'audit_exported' => 0];
foreach ($need as $k => $_) { $need[$k] = (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$k]); }
$missing = array_keys(array_filter($need, fn($n) => $n < 1));
record('A3', 'Every event type left an audit row (sign-in, failures, lockout, denials, CSRF, user changes, export)', !$missing, implode(',', $missing));
$sameTx = (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'user_created' AND username_snapshot = ? AND entity_id = ?", [$fx['admin'], $created['user_id'] ?? 0]);
record('A4', 'User creation and its audit row were saved together', $sameTx === 1);
$beforeCnt = (int) db_val('SELECT COUNT(*) FROM audit_log WHERE user_id IS NOT NULL AND username_snapshot = ?', [$fx['secretary']]);
db_exec('DELETE FROM users WHERE username = ?', [$fx['secretary']]);
$afterCnt = (int) db_val('SELECT COUNT(*) FROM audit_log WHERE username_snapshot = ?', [$fx['secretary']]);
record('A5', 'Deleting a user keeps their audit rows (username snapshot) (finding H4, audit part)', $beforeCnt > 0 && $afterCnt >= $beforeCnt);


/* M9 failure injection: cause an audit INSERT to fail without DDL privileges. */
$rollbackUsername = 'zz_test_rollback';
db_exec('DELETE FROM users WHERE username = ?', [$rollbackUsername]);
$auditInsertFailed = false;
try {
    db_transaction(function () use ($rollbackUsername): void {
        db_exec(
            "INSERT INTO users (username, password_hash, full_name, role, status, must_change_password) VALUES (?, ?, ?, 'clerk', 'active', 0)",
            [$rollbackUsername, password_hash('Audit-Failure-Test-2026', PASSWORD_DEFAULT), 'Rollback Check']
        );
        // audit_log.remarks is VARCHAR(1000). Strict SQL mode must reject this value,
        // simulating a failed audit write using the restricted application DB account.
        db_exec(
            "INSERT INTO audit_log (username_snapshot, action, module, status, remarks) VALUES (?, 'user_created', 'Users', 'Success', ?)",
            [$rollbackUsername, str_repeat('X', 1001)]
        );
    });
} catch (Throwable $e) {
    $auditInsertFailed = true;
}
$rollbackUserSaved = (int) db_val('SELECT COUNT(*) FROM users WHERE username = ?', [$rollbackUsername]) > 0;
record(
    'A6',
    'A failed audit INSERT rolls back preceding action data without elevated privileges',
    $auditInsertFailed && !$rollbackUserSaved,
    $auditInsertFailed ? ($rollbackUserSaved ? 'audit failed but user row remained' : 'audit INSERT failed; user row rolled back') : 'audit INSERT unexpectedly succeeded'
);

/* exposure check (needs the project root URL, e.g. XAMPP) */
if ($root === null) { record('E1', 'Private folders are not downloadable (needs --root=http://localhost/birms)', null, 'skipped'); }
else {
    $c = new Http($root); $okAll = true; $why = '';
    foreach (['/database/migrations/001_core.sql', '/app/core.php', '/app/config.local.php', '/storage/logs/php-error.log', '/tests/run_tests.php', '/tools/migrate.php', '/docs/README_XAMPP.md'] as $p) {
        $r = $c->get($p); if (!in_array($r['status'], [403, 404], true)) { $okAll = false; $why .= "{$p}={$r['status']} "; }
    }
    record('E1', 'Private folders (database, app, storage, tests, tools, docs) are not downloadable (finding M7)', $okAll, $why);
}

/* ------------------------------------------------------------ cleanup */
db_exec("DELETE FROM users WHERE username LIKE 'zz\\_test\\_%'");
db_exec("DELETE FROM login_failures WHERE username LIKE 'zz\\_test\\_%' OR username LIKE 'x%'");
db_exec("DELETE FROM audit_log WHERE module = 'Test'");

$pass = count(array_filter($results, fn($r) => $r[2] === 'PASS')); $fail = count(array_filter($results, fn($r) => $r[2] === 'FAIL')); $skip = count(array_filter($results, fn($r) => $r[2] === 'NOT TESTED'));
echo str_repeat('-', 78) . "\nPASS {$pass}   FAIL {$fail}   NOT TESTED {$skip}   (total " . count($results) . ")\n";
exit($fail > 0 ? 1 : 0);
