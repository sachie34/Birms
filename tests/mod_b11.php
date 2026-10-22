<?php
/** B11 checks for the safe demo seeder; only runs in the existing *_work / *_test integration database. */
declare(strict_types=1);

function b11_run_seed(array $args): array
{
    $parts = array_merge([escapeshellarg(PHP_BINARY), escapeshellarg(APP_ROOT . '/tools/seed_demo_data.php')], array_map('escapeshellarg', $args));
    $output = [];
    $code = 0;
    exec(implode(' ', $parts) . ' 2>&1', $output, $code);
    return ['code' => $code, 'output' => implode("\n", $output)];
}
function b11_count_demo_records(): array
{
    return [
        'households' => (int) db_val("SELECT COUNT(*) FROM households WHERE household_code LIKE 'DEMO-HH-%'"),
        'residents' => (int) db_val("SELECT COUNT(*) FROM residents WHERE last_name LIKE 'DEMO-%'"),
        'types' => (int) db_val("SELECT COUNT(*) FROM document_types WHERE name LIKE 'DEMO ONLY - %'"),
        'requests' => (int) db_val("SELECT COUNT(*) FROM document_requests WHERE reference_no LIKE 'DEMO-REQ-%'"),
        'issuances' => (int) db_val("SELECT COUNT(*) FROM issuances WHERE reference_no LIKE 'DEMO-CERT-%'"),
        'visitors' => (int) db_val("SELECT COUNT(*) FROM visitor_log WHERE visitor_name LIKE 'DEMO ONLY - Visitor %'"),
        'blotters' => (int) db_val("SELECT COUNT(*) FROM blotters WHERE case_no LIKE 'DEMO-BLT-%'"),
        'hearings' => (int) db_val("SELECT COUNT(*) FROM hearings WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE case_no LIKE 'DEMO-BLT-%')"),
    ];
}

function run_b11(array $S, string $base): void
{
    // This module is deliberately run after the other module tests and cleans up its own reserved fixtures.
    $cleanup = b11_run_seed(['--remove', '--confirm-demo-only']);
    record('B11-01', 'Demo seeder safely removes any previous reserved seed fixture before testing',
        $cleanup['code'] === 0, substr($cleanup['output'], -300));

    $before = b11_count_demo_records();
    $guardOld = getenv('BIRMS_DB_NAME');
    putenv('BIRMS_DB_NAME=barangay_db');
    $guard = b11_run_seed(['--apply']);
    if ($guardOld === false) { putenv('BIRMS_DB_NAME'); } else { putenv('BIRMS_DB_NAME=' . $guardOld); }
    record('B11-02', 'Seeder refuses to run against the original barangay_db name',
        $guard['code'] !== 0 && str_contains($guard['output'], 'not named *_work or *_test')
        && b11_count_demo_records() === $before, substr($guard['output'], -250));

    $plan = b11_run_seed([]);
    record('B11-03', 'Default seeder command is a read-only plan and does not insert demo records',
        $plan['code'] === 0 && str_contains($plan['output'], 'READ-ONLY PLAN')
        && str_contains($plan['output'], 'Plan: 8 households, 24 fictitious residents')
        && b11_count_demo_records() === $before, substr($plan['output'], -350));

    $apply = b11_run_seed(['--apply']);
    $counts = b11_count_demo_records();
    $expected = [
        'households' => 8, 'residents' => 24, 'types' => 3, 'requests' => 12,
        'issuances' => 4, 'visitors' => 8, 'blotters' => 4, 'hearings' => 1,
    ];
    record('B11-04', 'Apply seeds the expected number of fictitious records and valid relationships',
        $apply['code'] === 0 && $counts === $expected
        && (int) db_val("SELECT COUNT(*) FROM issuances WHERE reference_no LIKE 'DEMO-CERT-%' AND body_text LIKE 'DEMO ONLY - NOT AN OFFICIAL CERTIFICATE.%'") === 4
        && (int) db_val("SELECT COUNT(*) FROM document_requests WHERE reference_no LIKE 'DEMO-REQ-%' AND status = 'Pending'") >= 1
        && (int) db_val("SELECT COUNT(*) FROM document_requests WHERE reference_no LIKE 'DEMO-REQ-%' AND status = 'Ready' AND requirements_status = 'Complete'") >= 1
        && (int) db_val("SELECT COUNT(*) FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE reference_no LIKE 'DEMO-CERT-%')") === 0
        && (int) db_val("SELECT COUNT(*) FROM residents r LEFT JOIN households h ON h.household_id = r.household_id WHERE r.last_name LIKE 'DEMO-%' AND h.household_id IS NULL") === 0,
        implode(', ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($counts), array_values($counts))) . ' ' . substr($apply['output'], -150));

    $countAfterApply = b11_count_demo_records();
    $rerun = b11_run_seed(['--apply']);
    record('B11-05', 'Rerunning apply is idempotent and does not duplicate demo records',
        $rerun['code'] === 0 && str_contains($rerun['output'], 'already exists; no duplicate records')
        && b11_count_demo_records() === $countAfterApply, substr($rerun['output'], -250));

    // No files or real receipts are generated for the seed data. These are intentionally fictitious records.
    $remove = b11_run_seed(['--remove', '--confirm-demo-only']);
    $afterRemove = b11_count_demo_records();
    record('B11-06', 'Explicit removal removes only reserved demo records and leaves no seeded resident/request records',
        $remove['code'] === 0 && str_contains($remove['output'], 'Reserved demo records were removed')
        && array_sum($afterRemove) === 0, substr($remove['output'], -300));

    $withoutConfirm = b11_run_seed(['--remove']);
    record('B11-07', 'Removal refuses to run without the extra --confirm-demo-only switch',
        $withoutConfirm['code'] !== 0 && str_contains($withoutConfirm['output'], 'requires BOTH --remove and --confirm-demo-only')
        && array_sum(b11_count_demo_records()) === 0, substr($withoutConfirm['output'], -250));
}
