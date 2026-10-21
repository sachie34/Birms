<?php
/** B7 tests: paper-required demographic, issuance and blotter reporting; dashboard data; admin backup access. */
declare(strict_types=1);
require_once __DIR__ . '/../app/reports_lib.php';

function run_b7(array $S, string $base): void
{
    $adm = $S['admin']; $cap = $S['captain']; $sec = $S['secretary']; $clk = $S['clerk'];

    // Clean interrupted B7 test rows only. These prefixes are reserved for this automated test.
    db_exec("DELETE FROM issuances WHERE reference_no LIKE 'ZB7I%'");
    db_exec("DELETE FROM document_requests WHERE reference_no LIKE 'ZB7R%'");
    db_exec("DELETE FROM blotter_parties WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE case_no LIKE 'ZB7C%')");
    db_exec("DELETE FROM hearings WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE case_no LIKE 'ZB7C%')");
    db_exec("DELETE FROM blotters WHERE case_no LIKE 'ZB7C%'");
    db_exec("DELETE FROM residents WHERE last_name = 'ZZB7Report'");
    db_exec("DELETE FROM households WHERE household_code LIKE 'ZB7H%'");
    db_exec("DELETE FROM lookups WHERE (list_key = 'employment_status' AND label = 'ZZ B7 Employment') OR (list_key = 'incident_type' AND label = 'ZZ B7 Incident') OR (list_key = 'purok' AND label = 'ZZ B7 Purok')");

    $bad = '';
    foreach (['/reports/index.php' => 'reports.view', '/admin/backup.php' => 'backup.run'] as $path => $perm) {
        [$ok, $why] = rbac_page($S, $path, $perm);
        if (!$ok) { $bad .= $path . ': ' . $why; }
    }
    record('B7-1', 'Reports page access follows server-side role permissions', $bad === '', $bad);

    $csv = $sec->get('/reports/index.php?type=issuance&period=month&format=csv');
    $csvAdmin = $adm->get('/reports/index.php?type=blotter&period=quarter&format=csv');
    $csvClerk = $clk->get('/reports/index.php?type=demographics&format=csv');
    record('B7-2', 'Authorized roles can export CSV reports; a role without report permission is denied',
        $csv['status'] === 200 && str_contains(strtolower((string) ($csv['headers']['content-type'] ?? '')), 'text/csv')
        && str_starts_with($csv['body'], "\xEF\xBB\xBF") && $csvAdmin['status'] === 200 && $csvClerk['status'] === 403);

    $month = report_period_range('month', (int) date('Y'), (int) date('n'));
    $quarter = report_period_range('quarter', (int) date('Y'), (int) ceil((int) date('n') / 3));
    $year = report_period_range('year', (int) date('Y'));
    record('B7-3', 'Monthly, quarterly and annual report ranges have correct exclusive boundaries',
        $month['start'] === date('Y-m-01') . ' 00:00:00'
        && strtotime($month['end']) > strtotime($month['start'])
        && (int) (new DateTimeImmutable($quarter['end']))->diff(new DateTimeImmutable($quarter['start']))->m === 3
        && $year['start'] === date('Y-01-01') . ' 00:00:00'
        && $year['end'] === ((int) date('Y') + 1) . '-01-01 00:00:00');

    db_exec("INSERT INTO lookups (list_key, label, sort_order) VALUES ('employment_status', 'ZZ B7 Employment', 9907)");
    $employmentId = (int) db()->lastInsertId();
    db_exec("INSERT INTO lookups (list_key, label, sort_order) VALUES ('incident_type', 'ZZ B7 Incident', 9907)");
    $incidentTypeId = (int) db()->lastInsertId();
    db_exec("INSERT INTO lookups (list_key, label, sort_order) VALUES ('purok', 'ZZ B7 Purok', 9907)");
    $purokId = (int) db()->lastInsertId();

    $stamp = date('ymdHis') . random_int(10, 99);
    $houseCode = 'ZB7H' . $stamp;
    db_exec('INSERT INTO households (household_code, purok_id, street_address) VALUES (?, ?, ?)', [$houseCode, $purokId, 'ZZ B7 Test Address']);
    $houseId = (int) db()->lastInsertId();

    $baselineDemo = report_demographics();
    $baselineMonthIssuance = report_issuances($month);
    $baselineMonthBlotters = report_blotters($month);
    $ageLabel = '18-59';
    foreach (age_brackets() as [$lo, $hi, $label]) {
        if (age_on('1990-01-01') >= $lo && age_on('1990-01-01') <= $hi) { $ageLabel = $label; break; }
    }

    db_exec("INSERT INTO residents (household_id, last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status, employment_status_id)
             VALUES (?, 'ZZB7Report', 'ReportTest', '1990-01-01', 'Male', 'Single', 'Active', 'Registered', ?)",
        [$houseId, $employmentId]);
    $residentId = (int) db()->lastInsertId();

    $typeId = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Barangay Clearance' LIMIT 1");
    $requestRef = 'ZB7R' . $stamp;
    $issuanceRef = 'ZB7I' . $stamp;
    $caseNo = 'ZB7C' . $stamp;
    $issuedAt = date('Y-m-d H:i:s');
    db_exec("INSERT INTO document_requests (reference_no, resident_id, type_id, purpose, status, requested_at)
             VALUES (?, ?, ?, 'ZZ B7 report test', 'Claimed', ?)", [$requestRef, $residentId, $typeId, $issuedAt]);
    $requestId = (int) db()->lastInsertId();
    db_exec("INSERT INTO issuances (reference_no, request_id, resident_id, type_id, resident_name, type_name, purpose, fee_amount, or_number, body_text, captain_name, issued_by, issued_by_name, issued_at)
             VALUES (?, ?, ?, ?, 'ZZB7Report, ReportTest', 'Barangay Clearance', 'ZZ B7 report test', 37.25, NULL, 'ZZ B7 test certificate', NULL, NULL, 'B7 test', ?)",
        [$issuanceRef, $requestId, $residentId, $typeId, $issuedAt]);
    db_exec("INSERT INTO blotters (case_no, incident_date, incident_place, purok_id, incident_type_id, narrative, status, recorded_at)
             VALUES (?, ?, 'ZZ B7 test location', ?, ?, 'ZZ-B7 report test incident', 'Pending', ?)",
        [$caseNo, date('Y-m-d'), $purokId, $incidentTypeId, $issuedAt]);

    $afterDemo = report_demographics();
    $afterMonthIssuance = report_issuances($month);
    $afterMonthBlotters = report_blotters($month);
    $employmentCountOkay = (int) ($afterDemo['employment_status']['ZZ B7 Employment'] ?? 0) === (int) ($baselineDemo['employment_status']['ZZ B7 Employment'] ?? 0) + 1;
    $purokCountOkay = (int) ($afterDemo['purok']['ZZ B7 Purok'] ?? 0) === (int) ($baselineDemo['purok']['ZZ B7 Purok'] ?? 0) + 1;
    $voterCountOkay = (int) ($afterDemo['voter_status']['Registered'] ?? 0) === (int) ($baselineDemo['voter_status']['Registered'] ?? 0) + 1;
    record('B7-4', 'Demographic reports calculate population, age, employment, voter status and Purok totals from stored residents',
        $afterDemo['total'] === $baselineDemo['total'] + 1 && $employmentCountOkay && $purokCountOkay && $voterCountOkay
        && (int) ($afterDemo['age_brackets'][$ageLabel] ?? 0) >= (int) ($baselineDemo['age_brackets'][$ageLabel] ?? 0) + 1);

    record('B7-5', 'Issuance report aggregates the saved issue count and stored fee amount for the selected period',
        (int) $afterMonthIssuance['summary']['issued_count'] === (int) $baselineMonthIssuance['summary']['issued_count'] + 1
        && abs((float) $afterMonthIssuance['summary']['recorded_fees'] - (float) $baselineMonthIssuance['summary']['recorded_fees'] - 37.25) < 0.001);

    record('B7-6', 'Blotter report aggregates real cases by period, current status, configured incident type and Purok',
        (int) $afterMonthBlotters['summary']['total'] === (int) $baselineMonthBlotters['summary']['total'] + 1
        && (int) ($afterMonthBlotters['summary']['pending_count']) === (int) $baselineMonthBlotters['summary']['pending_count'] + 1
        && count(array_filter($afterMonthBlotters['by_type'], fn($r) => $r['incident_type'] === 'ZZ B7 Incident' && (int) $r['case_count'] >= 1)) === 1
        && count(array_filter($afterMonthBlotters['by_purok'], fn($r) => $r['purok'] === 'ZZ B7 Purok' && (int) $r['case_count'] >= 1)) === 1);

    $reportPage = $sec->get('/reports/index.php?type=demographics');
    $issuancePage = $sec->get('/reports/index.php?type=issuance&period=month&year=' . date('Y') . '&month=' . date('n'));
    $blotterPage = $sec->get('/reports/index.php?type=blotter&period=month&year=' . date('Y') . '&month=' . date('n'));
    $dashboard = $sec->get('/dashboard.php');
    record('B7-7', 'Report screens and dashboard expose the configured categories and real operational summaries',
        $reportPage['status'] === 200 && str_contains($reportPage['body'], 'ZZ B7 Employment') && $issuancePage['status'] === 200
        && str_contains($issuancePage['body'], 'ZZ B7 report test') === false
        && $blotterPage['status'] === 200 && str_contains($blotterPage['body'], 'ZZ B7 Incident')
        && $dashboard['status'] === 200 && str_contains($dashboard['body'], 'ZZ B7 Purok'));
    $backupAdmin = $adm->get('/admin/backup.php');
    record('B7-8', 'SQL backup page is reachable for the Administrator only and explains local-download limits', $backupAdmin['status'] === 200 && str_contains($backupAdmin['body'], 'Download SQL backup') && $clk->get('/admin/backup.php')['status'] === 403 && $sec->get('/admin/backup.php')['status'] === 403);
    $backup = post_as($adm, '/admin/backup.php', '/admin/backup.php', ['do' => 'download']);
    $backupClerk = post_as($clk, '/admin/backup.php', '/admin/backup.php', ['do' => 'download']);
    record('B7-9', 'Administrator SQL backup streams database DDL and data, logs the operation, and rejects unauthorized exports',
        $backup['status'] === 200 && str_starts_with($backup['body'], '-- BIRMS local SQL export') && str_contains($backup['body'], 'CREATE TABLE `users`') && str_contains($backup['body'], 'CREATE TABLE `residents`') && $backupClerk['status'] === 403 && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'database_backup_exported'") >= 1);

    // Clean child records first to respect the schema's restrictive foreign keys.
    db_exec('DELETE FROM issuances WHERE reference_no = ?', [$issuanceRef]);
    db_exec('DELETE FROM document_requests WHERE request_id = ?', [$requestId]);
    db_exec('DELETE FROM blotters WHERE case_no = ?', [$caseNo]);
    db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
    db_exec('DELETE FROM households WHERE household_id = ?', [$houseId]);
    db_exec('DELETE FROM lookups WHERE lookup_id IN (?, ?, ?)', [$employmentId, $incidentTypeId, $purokId]);
}
