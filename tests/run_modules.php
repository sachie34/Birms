<?php
/**
 * tests/run_modules.php  Tests for batches B2 and later (B1 has run_tests.php).
 *   php tests/run_modules.php [base_url]
 * Safety: refuses unless the database name ends in _work or _test. Creates zz_test_* users and ZZ Test rows and removes them.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
require __DIR__ . '/../app/core.php';
date_default_timezone_set((string) config('timezone', 'Asia/Manila'));   // same clock as the app, or date checks fail for 8 hours a day
require __DIR__ . '/../app/lookups.php';
require __DIR__ . '/../app/permissions.php';
require __DIR__ . '/lib.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "The PHP curl extension is required.\n"); exit(2); }
$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) { fwrite(STDERR, "REFUSED: database '{$dbName}' does not end in _work or _test.\n"); exit(2); }

// Module tests intentionally move synthetic requests to Ready; an enabled real provider could send paid SMS.
$selectedSmsProvider = strtolower(trim((string) config('sms.provider', 'smsapiph')));
$smsEnabledFlag = match ($selectedSmsProvider) {
    'smsapiph' => config('sms.smsapiph_enabled', false),
    'infobip' => config('sms.infobip_enabled', false),
    'semaphore' => config('sms.semaphore_enabled', false),
    default => config('sms.semaphore_enabled', false),
};
$smsEnabled = is_bool($smsEnabledFlag)
    ? $smsEnabledFlag
    : filter_var($smsEnabledFlag, FILTER_VALIDATE_BOOLEAN);
if ($smsEnabled) {
    fwrite(STDERR, "REFUSED: the selected SMS provider is enabled. Disable it in app/config.local.php/environment before running module tests; test requests can transition to Ready. Use tools/test_smsapiph_sms.php only for an intentional, authorized live-send test.\n");
    exit(2);
}
$base = rtrim('http://127.0.0.1:8080', '/');
foreach (array_slice($argv, 1) as $a) { if (!str_starts_with($a, '--')) { $base = rtrim($a, '/'); } }
define('TP', 'Test-Pass-2026a');

echo "BIRMS module tests  base={$base}  db={$dbName}\n" . str_repeat('-', 78) . "\n";
$S = fx_sessions($base, TP);
$only = array_values(array_filter(array_slice($argv, 1), fn($a) => str_starts_with($a, '--only=')));
$want = $only ? explode(',', substr($only[0], 7)) : null;
foreach (glob(__DIR__ . '/mod_b*.php') ?: [] as $f) {
    $id = strtoupper(preg_replace('/^mod_(b\d+)\.php$/', '$1', basename($f)));
    if ($want !== null && !in_array($id, $want, true)) { continue; }
    require $f;
    echo "== {$id} ==\n";
    ($GLOBALS['run_' . strtolower($id)] ?? 'run_' . strtolower($id))($S, $base);
}
db_exec("DELETE FROM users WHERE username LIKE 'zz\\_test\\_%'");
db_exec("DELETE FROM login_failures WHERE username LIKE 'zz\\_test\\_%'");
$pass = count(array_filter($results, fn($r) => $r[2] === 'PASS')); $fail = count(array_filter($results, fn($r) => $r[2] === 'FAIL')); $skip = count(array_filter($results, fn($r) => $r[2] === 'NOT TESTED'));
echo str_repeat('-', 78) . "\nPASS {$pass}   FAIL {$fail}   NOT TESTED {$skip}   (total " . count($results) . ")\n";
exit($fail > 0 ? 1 : 0);
