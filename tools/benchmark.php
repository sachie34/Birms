<?php
/**
 * Read-only local timing check for thesis system evaluation.
 * Usage: php tools/benchmark.php [--iterations=10] [--query=search-text]
 * Safety: only runs on a database whose name ends in _work or _test.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Command line only.\n"); exit(2); }

require __DIR__ . '/../app/core.php';
date_default_timezone_set((string) config('timezone', 'Asia/Manila'));
require __DIR__ . '/../app/lookups.php';
require __DIR__ . '/../app/residents_lib.php';
require __DIR__ . '/../app/reports_lib.php';

$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) {
    fwrite(STDERR, "REFUSED: database name must end in _work or _test; no query was run.\n");
    exit(2);
}

$iterations = 10;
$query = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--iterations=')) {
        $raw = substr($arg, strlen('--iterations='));
        if (!ctype_digit($raw)) { fwrite(STDERR, "Iterations must be an integer from 1 to 50.\n"); exit(2); }
        $iterations = (int) $raw;
    } elseif (str_starts_with($arg, '--query=')) {
        $query = trim(substr($arg, strlen('--query=')));
        if (strlen($query) > 60) { fwrite(STDERR, "Search query is limited to 60 characters.\n"); exit(2); }
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}
if ($iterations < 1 || $iterations > 50) {
    fwrite(STDERR, "Iterations must be from 1 to 50.\n");
    exit(2);
}

$range = report_period_range('month');
$emptyFilters = ['q' => $query, 'purok' => '', 'status' => '', 'category' => '', 'show' => ''];
$checks = [
    'Resident search + first 20 results' => static function () use ($emptyFilters): void {
        [$rows, $total] = resident_search($emptyFilters, 20, 0);
        // Consume only counts; never print resident names or personal information.
        $unused = count($rows) + $total;
    },
    'Current resident demographic report' => static function (): void {
        $report = report_demographics();
        $unused = (int) $report['total'];
    },
    'Monthly issuance + recorded-fee report' => static function () use ($range): void {
        $report = report_issuances($range);
        $unused = (int) $report['summary']['issued_count'];
    },
    'Monthly request-processing report' => static function () use ($range): void {
        $report = report_requests($range);
        $unused = (int) $report['summary']['total'];
    },
    'Monthly blotter report' => static function () use ($range): void {
        $report = report_blotters($range);
        $unused = (int) $report['summary']['total'];
    },
];

echo "BIRMS read-only performance check\n";
echo "Iterations: {$iterations}\n";
echo "Resident-search filter: " . ($query === '' ? '(all current residents, first 20 displayed)' : '(user-supplied search filter)') . "\n";
echo "These results describe this working database only; they are not a comparison with paper records.\n\n";
printf("%-44s %12s %12s %12s\n", 'Operation', 'Median (ms)', 'Mean (ms)', 'Max (ms)');
echo str_repeat('-', 82) . "\n";

foreach ($checks as $label => $check) {
    $times = [];
    try {
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $check();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Check failed: {$label}. Inspect storage/logs/php-error.log; this tool does not print record data.\n");
        exit(1);
    }
    sort($times, SORT_NUMERIC);
    $n = count($times);
    $median = $n % 2 === 1 ? $times[intdiv($n, 2)] : ($times[$n / 2 - 1] + $times[$n / 2]) / 2;
    $mean = array_sum($times) / $n;
    $max = max($times);
    printf("%-44s %12.2f %12.2f %12.2f\n", $label, $median, $mean, $max);
}
echo "\nNo data was created, updated, or deleted. Do not treat the result as final UAT evidence until you record machine specs, database size, repeated trial settings, and user-side results.\n";
