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
$emptyFilters = ['q' => $query, 'purok' => '', 'status' => '', 'type' => '', 'show' => ''];
$checks = [
    'Resident search + first 20 results' => static function () use ($emptyFilters): void {
        [$rows, $total] = resident_search($emptyFilters, 20, 0);
        // Consume only counts; never print resident names or personal information.
        $unused = count($rows) + $total;
    },
    'Recent request directory (first 25)' => static function (): void {
        $rows = db_all(
            'SELECT dr.request_id
             FROM document_requests dr
             JOIN residents r ON r.resident_id = dr.resident_id
             JOIN document_types dt ON dt.type_id = dr.type_id
             LEFT JOIN users u ON u.user_id = dr.assigned_to
             ORDER BY dr.requested_at DESC, dr.request_id DESC
             LIMIT 25'
        );
        $unused = count($rows);
    },
    'Recent blotter directory (first 25)' => static function (): void {
        $rows = db_all(
            "SELECT b.blotter_id,
                    (SELECT GROUP_CONCAT(x.full_name ORDER BY x.party_id SEPARATOR ', ')
                     FROM blotter_parties x WHERE x.blotter_id = b.blotter_id AND x.party_role = 'Complainant') AS complainants,
                    (SELECT GROUP_CONCAT(y.full_name ORDER BY y.party_id SEPARATOR ', ')
                     FROM blotter_parties y WHERE y.blotter_id = b.blotter_id AND y.party_role = 'Respondent') AS respondents,
                    (SELECT MIN(h.scheduled_at) FROM hearings h
                     WHERE h.blotter_id = b.blotter_id AND h.status = 'Scheduled') AS next_hearing
             FROM blotters b
             LEFT JOIN lookups pk ON pk.lookup_id = b.purok_id AND pk.list_key = 'purok'
             ORDER BY b.incident_date DESC, b.incident_time DESC, b.blotter_id DESC
             LIMIT 25"
        );
        $unused = count($rows);
    },
    'Recent venue directory (latest 200)' => static function (): void {
        $rows = db_all(
            'SELECT vr.reservation_id
             FROM venue_reservations vr
             JOIN venues v ON v.venue_id = vr.venue_id
             JOIN residents r ON r.resident_id = vr.resident_id
             ORDER BY vr.reservation_date DESC, vr.start_time DESC, vr.reservation_id DESC
             LIMIT 200'
        );
        $unused = count($rows);
    },
    'Today queue dashboard totals' => static function (): void {
        $counts = db_one(
            "SELECT COUNT(*) AS total_n,
                    COALESCE(SUM(status = 'Waiting'), 0) AS waiting_n,
                    COALESCE(SUM(status = 'Called'), 0) AS called_n,
                    COALESCE(SUM(status = 'Served'), 0) AS served_n
             FROM queue_entries WHERE queue_date = CURDATE()"
        ) ?? ['total_n' => 0];
        $unused = (int) $counts['total_n'];
    },
    'Current resident demographic report' => static function (): void {
        $report = report_demographics();
        $unused = (int) $report['total'];
    },
    'Monthly issuance + recorded-fee report' => static function () use ($range): void {
        $report = report_issuances($range);
        $unused = (int) $report['summary']['issued_count'];
    },
    'Monthly cash-collection report' => static function () use ($range): void {
        $report = report_cash_collections($range);
        $unused = (int) $report['ledger_entry_count'];
    },
    'Monthly request-processing report' => static function () use ($range): void {
        $report = report_requests($range);
        $unused = (int) $report['summary']['total'];
    },
    'Monthly blotter report' => static function () use ($range): void {
        $report = report_blotters($range);
        $unused = (int) $report['summary']['total'];
    },
    'Monthly venue-reservation report' => static function () use ($range): void {
        $report = report_venues($range);
        $unused = (int) $report['summary']['total'];
    },
    'Monthly queueing report' => static function () use ($range): void {
        $report = report_queueing($range);
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
