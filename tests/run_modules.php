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
require __DIR__ . '/../app/email_provider.php';
require __DIR__ . '/../app/permissions.php';
require __DIR__ . '/lib.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "The PHP curl extension is required.\n"); exit(2); }
$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) { fwrite(STDERR, "REFUSED: database '{$dbName}' does not end in _work or _test.\n"); exit(2); }

$defaultEmailEnabled = filter_var(config('email.enabled', false), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
$emailEnabled = filter_var(setting('email_enabled', $defaultEmailEnabled), FILTER_VALIDATE_BOOLEAN);
if ($emailEnabled) {
    fwrite(STDERR, "REFUSED: real email notifications are enabled. Disable email in the database Settings and app/config.local.php/environment before running module tests; test requests may create outbound notifications.\n");
    exit(2);
}
$base = trim((string) (getenv('BIRMS_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'));
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = substr($a, 7);
    } elseif (!str_starts_with($a, '--')) {
        $base = $a;
    }
}
$base = rtrim($base, '/');
$baseParts = parse_url($base);
if (!is_array($baseParts) || !in_array(strtolower((string) ($baseParts['scheme'] ?? '')), ['http', 'https'], true)
    || empty($baseParts['host'])) {
    fwrite(STDERR, "REFUSED: invalid test base URL. Pass http://localhost/<project>/public or set BIRMS_TEST_BASE_URL.\\n");
    exit(2);
}

// Fail before creating test accounts if the configured application URL is unavailable.
// CI starts its own PHP server on 127.0.0.1:8080; local XAMPP users should pass their public URL.
$probe = curl_init($base . '/index.php');
curl_setopt_array($probe, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_NOBODY => true,
]);
$probeResult = curl_exec($probe);
$probeStatus = (int) curl_getinfo($probe, CURLINFO_RESPONSE_CODE);
$probeError = curl_error($probe);
curl_close($probe);
if ($probeResult === false || $probeStatus < 200 || $probeStatus >= 400) {
    fwrite(STDERR, "REFUSED: BIRMS test server did not return a successful response from {$base}/index.php (HTTP {$probeStatus}). {$probeError}\\n");
    fwrite(STDERR, "Start Apache or the CI PHP server, then pass the correct public base URL, for example: php tests/run_modules.php http://localhost/<project>/public\\n");
    exit(2);
}

// The module suite creates and removes test fixtures. Never run it against a database
// that already contains either the revised MSIT demo seed or the retired household demo seed.
$demoFixtureCount = (int) db_val("SELECT COUNT(*) FROM residents WHERE resident_no LIKE 'DEMO-RES-%'")
    + (int) db_val("SELECT COUNT(*) FROM document_requests WHERE reference_no LIKE 'DEMO-DOC-%'")
    + (int) db_val("SELECT COUNT(*) FROM queue_entries WHERE reference_no IN ('DEMO-DOC-PENDING-01', 'MSIT-DEMO-BLT-0001', 'DEMO-VEN-0001')")
    + (int) db_val("SELECT COUNT(*) FROM households WHERE household_code LIKE 'DEMO-HH-%'");
if ($demoFixtureCount > 0) {
    fwrite(STDERR, "REFUSED: reserved demo seed records already exist in {$dbName}. Module tests are destructive fixtures and must run against a separate, clean migrated *_test database. Keep the presentation database intact; apply the MSIT demo seed only after module tests have completed.\\n");
    exit(2);
}
define('TP', 'Test-Pass-2026a');

echo "BIRMS module tests  base={$base}  db={$dbName}\n" . str_repeat('-', 78) . "\n";
$S = fx_sessions($base, TP);
$only = array_values(array_filter(array_slice($argv, 1), fn($a) => str_starts_with($a, '--only=')));
$want = $only ? explode(',', substr($only[0], 7)) : null;
foreach (glob(__DIR__ . '/mod_b*.php') ?: [] as $f) {
    $id = strtoupper(preg_replace('/^mod_(b\\d+)\\.php$/', '$1', basename($f)));
    if ($want !== null && !in_array($id, $want, true)) { continue; }
    echo "== {$id} ==\n";
    try {
        require $f;
        $runnerName = 'run_' . strtolower($id);
        $runner = $GLOBALS[$runnerName] ?? $runnerName;
        if (!is_callable($runner)) {
            throw new RuntimeException("Module runner {$runnerName} is missing or not callable.");
        }
        $runner($S, $base);
    } catch (Throwable $e) {
        $message = preg_replace('/[\r\n]+/', ' ', $e->getMessage()) ?? 'No exception message.';
        error_log("BIRMS module test {$id} aborted: " . (string) $e);
        record($id . '-EXCEPTION', "Module {$id} aborted unexpectedly; this is a test failure", false,
            get_class($e) . ': ' . substr($message, 0, 500));
        record($id . '-REMAINDER', "Remaining assertions in module {$id} did not execute", null,
            'Module aborted after an exception; inspect the failure above.');
        // Continue with the next module so one exception does not hide unrelated failures.
    }
}
db_exec("DELETE FROM users WHERE username LIKE 'zz\\_test\\_%'");
db_exec("DELETE FROM login_failures WHERE username LIKE 'zz\\_test\\_%'");
$pass = count(array_filter($results, fn($r) => $r[2] === 'PASS'));
$fail = count(array_filter($results, fn($r) => $r[2] === 'FAIL'));
$skip = count(array_filter($results, fn($r) => $r[2] === 'NOT TESTED'));
echo str_repeat('-', 78) . "\nPASS {$pass}   FAIL {$fail}   NOT TESTED {$skip}   (total " . count($results) . ")\n";
if ($fail > 0) {
    echo "FAILED CHECKS (retained; fix the cause rather than removing the test):\n";
    foreach ($results as [$testId, $testName, $testStatus, $testNote]) {
        if ($testStatus === 'FAIL') {
            echo " - {$testId}: {$testName}" . ($testNote !== '' ? " [{$testNote}]" : '') . "\n";
        }
    }
}
if ($skip > 0) {
    echo "NOT TESTED CHECKS (investigate before release):\n";
    foreach ($results as [$testId, $testName, $testStatus, $testNote]) {
        if ($testStatus === 'NOT TESTED') {
            echo " - {$testId}: {$testName}" . ($testNote !== '' ? " [{$testNote}]" : '') . "\n";
        }
    }
}
exit($fail > 0 || $skip > 0 ? 1 : 0);
