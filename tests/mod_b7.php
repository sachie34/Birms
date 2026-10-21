<?php
/** B7 tests: paper-required demographic, issuance and blotter reporting; dashboard data; admin backup access. */
declare(strict_types=1);
require_once __DIR__ . '/../app/reports_lib.php';
require_once __DIR__ . '/../app/backup_lib.php';

function run_b7(array $S, string $base): void
{
    $adm = $S['admin']; $cap = $S['captain']; $sec = $S['secretary']; $clk = $S['clerk'];

    // Clean interrupted B7 test rows only. Delete any ledger children before deleting issuance parents.
    db_exec("DELETE FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE reference_no LIKE 'ZB7I%' OR request_id IN (SELECT request_id FROM document_requests WHERE purpose = 'ZZ B7 Fee matrix quote test'))");
    db_exec("DELETE FROM issuances WHERE request_id IN (SELECT request_id FROM document_requests WHERE purpose = 'ZZ B7 Fee matrix quote test')");
    db_exec("DELETE FROM document_requests WHERE purpose = 'ZZ B7 Fee matrix quote test'");
    db_exec("DELETE FROM fee_matrix WHERE fee_category = 'ZZ B7 Fee Category' AND fee_tier = 'ZZ B7 Fee Tier'");
    db_exec("DELETE FROM document_types WHERE name = 'ZZ B7 Matrix Certificate'");
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
    $csvRequests = $sec->get('/reports/index.php?type=requests&period=month&format=csv');
    $csvAdmin = $adm->get('/reports/index.php?type=blotter&period=quarter&format=csv');
    $csvClerk = $clk->get('/reports/index.php?type=demographics&format=csv');
    $csvDisposition = (string) ($csv['headers']['content-disposition'] ?? '');
    $requestsDisposition = (string) ($csvRequests['headers']['content-disposition'] ?? '');
    record('B7-2', 'Authorized roles can export CSV reports with valid attachment filenames; unauthorized export is denied',
        $csv['status'] === 200 && str_contains(strtolower((string) ($csv['headers']['content-type'] ?? '')), 'text/csv')
        && preg_match('/^attachment; filename="birms-(?:internal|dilg|mayor|pnp)-issuance-[a-z0-9-]+\.csv"$/i', trim($csvDisposition)) === 1
        && $csvRequests['status'] === 200 && str_contains($csvRequests['body'], 'Request processing summary')
        && preg_match('/^attachment; filename="birms-(?:internal|dilg|mayor|pnp)-requests-[a-z0-9-]+\.csv"$/i', trim($requestsDisposition)) === 1
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
    $baselineMonthRequests = report_requests($month);
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
    $afterMonthRequests = report_requests($month);
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
    $requestsPage = $sec->get('/reports/index.php?type=requests&period=month&year=' . date('Y') . '&month=' . date('n'));
    $blotterPage = $sec->get('/reports/index.php?type=blotter&period=month&year=' . date('Y') . '&month=' . date('n'));
    $periodicPage = $sec->get('/reports/index.php?type=periodic&period=month&year=' . date('Y') . '&month=' . date('n') . '&recipient=pnp');
    $dashboard = $sec->get('/dashboard.php');
    record('B7-7', 'Report screens and dashboard expose the configured categories and real operational summaries',
        $reportPage['status'] === 200 && str_contains($reportPage['body'], 'ZZ B7 Employment') && $issuancePage['status'] === 200
        && str_contains($issuancePage['body'], 'ZZ B7 report test') === false
        && $requestsPage['status'] === 200 && str_contains($requestsPage['body'], 'Request processing summary')
        && $blotterPage['status'] === 200 && str_contains($blotterPage['body'], 'ZZ B7 Incident')
        && $periodicPage['status'] === 200 && str_contains($periodicPage['body'], 'Monthly / Quarterly Administrative Report')
        && str_contains($periodicPage['body'], 'Philippine National Police (PNP)')
        && str_contains($periodicPage['body'], 'Revenue collected')
        && $dashboard['status'] === 200 && str_contains($dashboard['body'], 'ZZ B7 Purok'));
    record('B7-10', 'Request-processing summaries derive totals, current status, document type and assignee from saved requests',
        (int) $afterMonthRequests['summary']['total'] === (int) $baselineMonthRequests['summary']['total'] + 1
        && (int) $afterMonthRequests['summary']['claimed_count'] === (int) $baselineMonthRequests['summary']['claimed_count'] + 1
        && count(array_filter($afterMonthRequests['by_type'], fn($r) => $r['type_name'] === 'Barangay Clearance' && (int) $r['request_count'] >= 1)) >= 1
        && count(array_filter($afterMonthRequests['by_assignee'], fn($r) => $r['assigned_to'] === 'Unassigned' && (int) $r['request_count'] >= 1)) >= 1);

    // End-to-end fee matrix flow: create an approved rule, quote a request, issue it, and report the saved category/tier.
    db_exec("INSERT INTO document_types (name, fee_amount, template_body, is_active, sort_order)
             VALUES ('ZZ B7 Matrix Certificate', NULL, 'Test certificate for {{full_name}}: {{purpose}}', 1, 9907)");
    $matrixTypeId = (int) db()->lastInsertId();

    $badLegacyPurpose = 'ZZ B7 Legacy quantity validation test';
    $badLegacyQuantity = post_as($clk, '/requests/form.php?resident=' . $residentId, '/requests/form.php?resident=' . $residentId, [
        'resident_id' => (string) $residentId, 'type_id' => (string) $matrixTypeId,
        'purpose' => $badLegacyPurpose, 'assigned_to' => '',
        'requirements_status' => 'Not checked', 'requirements_note' => '',
        'fee_rule_id' => '', 'fee_quantity' => '2',
    ]);
    $badLegacyQuantityBlocked = $badLegacyQuantity['status'] === 200
        && str_contains($badLegacyQuantity['body'], 'Quantity must be 1 when using the per-document fee')
        && (int) db_val('SELECT COUNT(*) FROM document_requests WHERE purpose = ?', [$badLegacyPurpose]) === 0;

    $feeRuleResponse = post_as($sec, '/admin/settings.php', '/admin/settings.php', [
        'do' => 'fee_rule_add', 'tab' => 'fee_matrix', 'type_id' => (string) $matrixTypeId,
        'fee_category' => 'ZZ B7 Fee Category', 'fee_tier' => 'ZZ B7 Fee Tier',
        'unit_rate' => '18.50', 'min_quantity' => '1', 'max_quantity' => '4',
        'effective_from' => date('Y-m-d'), 'effective_to' => '',
    ]);
    $matrixRuleId = (int) db_val("SELECT fee_rule_id FROM fee_matrix WHERE type_id = ? AND fee_category = 'ZZ B7 Fee Category' AND fee_tier = 'ZZ B7 Fee Tier' AND is_active = 1", [$matrixTypeId]);
    $badMatrixPurpose = 'ZZ B7 Fee matrix out-of-range test';
    $badMatrixQuantity = post_as($clk, '/requests/form.php?resident=' . $residentId, '/requests/form.php?resident=' . $residentId, [
        'resident_id' => (string) $residentId, 'type_id' => (string) $matrixTypeId,
        'purpose' => $badMatrixPurpose, 'assigned_to' => '',
        'requirements_status' => 'Not checked', 'requirements_note' => '',
        'fee_rule_id' => (string) $matrixRuleId, 'fee_quantity' => '5',
    ]);
    $badMatrixQuantityBlocked = $badMatrixQuantity['status'] === 200
        && str_contains($badMatrixQuantity['body'], 'Quantity does not fit the selected fee tier range')
        && (int) db_val('SELECT COUNT(*) FROM document_requests WHERE purpose = ?', [$badMatrixPurpose]) === 0;
    $quotePurpose = 'ZZ B7 Fee matrix quote test';
    $quotePost = post_as($clk, '/requests/form.php?resident=' . $residentId, '/requests/form.php?resident=' . $residentId, [
        'resident_id' => (string) $residentId, 'type_id' => (string) $matrixTypeId,
        'purpose' => $quotePurpose, 'assigned_to' => '',
        'requirements_status' => 'Not checked', 'requirements_note' => '',
        'fee_rule_id' => (string) $matrixRuleId, 'fee_quantity' => '3',
    ]);
    $quoteRequestId = (int) db_val('SELECT request_id FROM document_requests WHERE purpose = ?', [$quotePurpose]);
    $quoteRow = $quoteRequestId > 0 ? db_one('SELECT fee_rule_id, fee_category_snapshot, fee_tier_snapshot, fee_quantity, fee_unit_rate, quoted_fee_amount FROM document_requests WHERE request_id = ?', [$quoteRequestId]) : null;
    record('B7-11', 'Legacy per-document and matrix quantities are validated; a real request saves the approved category/tier snapshot and calculated quote',
        $badLegacyQuantityBlocked && $badMatrixQuantityBlocked
        && $feeRuleResponse['status'] === 302 && $matrixRuleId > 0 && $quotePost['status'] === 302 && $quoteRow !== null
        && (int) $quoteRow['fee_rule_id'] === $matrixRuleId
        && $quoteRow['fee_category_snapshot'] === 'ZZ B7 Fee Category'
        && $quoteRow['fee_tier_snapshot'] === 'ZZ B7 Fee Tier'
        && (int) $quoteRow['fee_quantity'] === 3
        && abs((float) $quoteRow['fee_unit_rate'] - 18.50) < 0.001
        && abs((float) $quoteRow['quoted_fee_amount'] - 55.50) < 0.001);

    $quoteComplete = post_as($clk, '/requests/form.php?id=' . $quoteRequestId, '/requests/form.php?id=' . $quoteRequestId, [
        'resident_id' => (string) $residentId, 'type_id' => (string) $matrixTypeId,
        'purpose' => $quotePurpose, 'assigned_to' => '',
        'requirements_status' => 'Complete', 'requirements_note' => '',
        'fee_rule_id' => (string) $matrixRuleId, 'fee_quantity' => '3',
    ]);
    $quoteReview = post_as($clk, '/requests/view.php?id=' . $quoteRequestId, '/requests/action.php', ['id' => (string) $quoteRequestId, 'to' => 'Review']);
    $quoteReady = post_as($sec, '/requests/view.php?id=' . $quoteRequestId, '/requests/action.php', ['id' => (string) $quoteRequestId, 'to' => 'Ready']);
    $quoteOr = 'ZB7OR' . $stamp;
    $quoteIssue = post_as($clk, '/certificates/issue.php?request=' . $quoteRequestId, '/certificates/issue.php?request=' . $quoteRequestId, [
        'request_id' => (string) $quoteRequestId, 'or_number' => $quoteOr,
    ]);
    $quoteIssuance = db_one('SELECT issuance_id, fee_amount, fee_rule_id, fee_category_snapshot, fee_tier_snapshot, fee_quantity, fee_unit_rate, body_text FROM issuances WHERE request_id = ?', [$quoteRequestId]);
    $quoteFeeReport = report_issuances($month);
    $quoteFeeReportRow = null;
    foreach ($quoteFeeReport['by_fee_rule'] as $fr) {
        if ($fr['fee_category'] === 'ZZ B7 Fee Category' && $fr['fee_tier'] === 'ZZ B7 Fee Tier') { $quoteFeeReportRow = $fr; break; }
    }
    $updatedIssuancePage = $sec->get('/reports/index.php?type=issuance&period=month&year=' . date('Y') . '&month=' . date('n'));
    record('B7-12', 'The saved quote survives review and issuance and appears in the fee report with the same category/tier',
        $quoteComplete['status'] === 302 && $quoteReview['status'] === 302 && $quoteReady['status'] === 302
        && $quoteIssue['status'] === 302 && $quoteIssuance !== null
        && abs((float) $quoteIssuance['fee_amount'] - 55.50) < 0.001
        && (int) $quoteIssuance['fee_rule_id'] === $matrixRuleId
        && $quoteIssuance['fee_category_snapshot'] === 'ZZ B7 Fee Category'
        && $quoteIssuance['fee_tier_snapshot'] === 'ZZ B7 Fee Tier'
        && (int) $quoteIssuance['fee_quantity'] === 3
        && abs((float) $quoteIssuance['fee_unit_rate'] - 18.50) < 0.001
        && str_contains((string) $quoteIssuance['body_text'], 'ZZ B7 Fee matrix quote test')
        && $quoteFeeReportRow !== null && (int) $quoteFeeReportRow['issued_count'] === 1
        && abs((float) $quoteFeeReportRow['recorded_fees'] - 55.50) < 0.001
        && $updatedIssuancePage['status'] === 200 && str_contains($updatedIssuancePage['body'], 'ZZ B7 Fee Category'));

    $benchmarkOut = []; $benchmarkCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/benchmark.php') . ' --iterations=1 --query=ZZ 2>&1', $benchmarkOut, $benchmarkCode);
    $benchmarkText = implode("\n", $benchmarkOut);
    record('B7-13', 'The read-only performance benchmark runs on the working database and reports timings without resident details',
        $benchmarkCode === 0
        && str_contains($benchmarkText, 'BIRMS read-only performance check')
        && str_contains($benchmarkText, 'Resident search + first 20 results')
        && str_contains($benchmarkText, 'Monthly request-processing report')
        && str_contains($benchmarkText, 'No data was created, updated, or deleted'));

    $backupAdmin = $adm->get('/admin/backup.php');
    record('B7-8', 'SQL backup page is reachable for the Administrator only and explains local-download limits', $backupAdmin['status'] === 200 && str_contains($backupAdmin['body'], 'Download SQL backup') && $clk->get('/admin/backup.php')['status'] === 403 && $sec->get('/admin/backup.php')['status'] === 403);
    $backup = post_as($adm, '/admin/backup.php', '/admin/backup.php', ['do' => 'download']);
    $backupClerk = post_as($clk, '/admin/backup.php', '/admin/backup.php', ['do' => 'download']);
    $backupDisposition = (string) ($backup['headers']['content-disposition'] ?? '');
    record('B7-9', 'Administrator SQL backup streams database DDL and data with a valid attachment filename, logs the operation, and rejects unauthorized exports',
        $backup['status'] === 200
        && preg_match('/^attachment; filename="birms-backup-[0-9-]+-[a-f0-9]{6}\.sql"$/i', trim($backupDisposition)) === 1
        && str_starts_with($backup['body'], '-- BIRMS SQL backup')
        && str_contains($backup['body'], 'CREATE TABLE `users`')
        && str_contains($backup['body'], 'CREATE TABLE `residents`')
        && $backupClerk['status'] === 403
        && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'database_backup_exported'") >= 1);

    $safeCloudTarget = backup_webdav_destination_url('https://cloud.example.test/remote.php/dav/files/birms/backups/', 'birms-backup-2026-10-22-abcdef.sql');
    $badCloudUrls = [
        'http://cloud.example.test/backups/',
        'https://user:password@cloud.example.test/backups/',
        'https://cloud.example.test/backups/?token=secret',
        'https://cloud.example.test/backups/../public/',
        'https://cloud.example.test/backups/#fragment',
    ];
    $invalidCloudUrlsRejected = true;
    foreach ($badCloudUrls as $badUrl) {
        try { backup_webdav_destination_url($badUrl, 'birms-backup-2026-10-22-abcdef.sql'); $invalidCloudUrlsRejected = false; }
        catch (InvalidArgumentException $expected) { /* expected validation rejection */ }
    }
    record('B7-14', 'Cloud backup URL validation requires HTTPS and rejects embedded credentials, query strings, fragments and dot-segment paths',
        $safeCloudTarget === 'https://cloud.example.test/remote.php/dav/files/birms/backups/birms-backup-2026-10-22-abcdef.sql'
        && $invalidCloudUrlsRejected);

    $cloudConfigured = backup_webdav_is_configured();
    $cloudStatusVisible = $cloudConfigured
        ? str_contains($backupAdmin['body'], 'Cloud destination is configured')
        : str_contains($backupAdmin['body'], 'Cloud upload is not configured yet');
    $cloudPassword = (string) config('backup.webdav_password', '');
    $passwordNotExposed = $cloudPassword === '' || !str_contains($backupAdmin['body'], $cloudPassword);
    record('B7-15', 'Cloud backup UI truthfully reflects configuration without printing configured credentials',
        $cloudStatusVisible && $passwordNotExposed);

    $periodicCsv = $sec->get('/reports/index.php?type=periodic&period=quarter&year=' . date('Y') . '&quarter=4&recipient=mayor&format=csv');
    $periodicCsvRaw = (string) $periodicCsv['body'];
    $periodicCsvBom = pack('CCC', 0xEF, 0xBB, 0xBF);
    $periodicCsvBody = str_starts_with($periodicCsvRaw, $periodicCsvBom) ? substr($periodicCsvRaw, 3) : $periodicCsvRaw;
    record('B7-16A', 'Periodic quarterly CSV response succeeds with a CSV content type',
        $periodicCsv['status'] === 200
        && str_contains(strtolower((string) ($periodicCsv['headers']['content-type'] ?? '')), 'text/csv'));
    record('B7-16B1', 'Periodic quarterly CSV includes selected recipient',
        str_contains($periodicCsvBody, 'Office of the Mayor'));
    record('B7-16B2', 'Periodic quarterly CSV identifies draft template status',
        str_contains($periodicCsvBody, 'Template status'));
    record('B7-16B3', 'Periodic quarterly CSV includes reporting period',
        str_contains($periodicCsvBody, 'Report period'));
    record('B7-16C', 'Periodic quarterly CSV distinguishes reconciled and pending cash-ledger amounts',
        str_contains($periodicCsvBody, 'Reconciled cash-collection ledger amount')
        && str_contains($periodicCsvBody, 'Pending reconciliation amount'));
    record('B7-16D', 'Periodic quarterly CSV distinguishes issuance fee snapshots from confirmed collections',
        str_contains($periodicCsvBody, 'Recorded issuance fee amounts (not confirmed collections)'));
    record('B7-16', 'Periodic quarterly report can be exported for a named recipient and distinguishes reconciled collections from issuance fee amounts',
        $periodicCsv['status'] === 200
        && str_contains($periodicCsvBody, 'Prepared for,Office of the Mayor')
        && str_contains($periodicCsvBody, 'Template status')
        && str_contains($periodicCsvBody, 'Reconciled cash-collection ledger amount')
        && str_contains($periodicCsvBody, 'Recorded issuance fee amounts (not confirmed collections)')
        && str_contains($periodicCsvBody, 'Pending reconciliation amount'));

    // Clean ledger children before removing issuances, and issuances before requests.
    db_exec("DELETE FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE reference_no = ? OR request_id IN (SELECT request_id FROM document_requests WHERE purpose = 'ZZ B7 Fee matrix quote test'))", [$issuanceRef]);
    if (isset($quoteRequestId) && $quoteRequestId > 0) { db_exec('DELETE FROM issuances WHERE request_id = ?', [$quoteRequestId]); db_exec('DELETE FROM document_requests WHERE request_id = ?', [$quoteRequestId]); }
    if (isset($matrixRuleId) && $matrixRuleId > 0) { db_exec('DELETE FROM fee_matrix WHERE fee_rule_id = ?', [$matrixRuleId]); }
    if (isset($matrixTypeId) && $matrixTypeId > 0) { db_exec('DELETE FROM document_types WHERE type_id = ?', [$matrixTypeId]); }
    db_exec('DELETE FROM issuances WHERE reference_no = ?', [$issuanceRef]);
    db_exec('DELETE FROM document_requests WHERE request_id = ?', [$requestId]);
    db_exec('DELETE FROM blotters WHERE case_no = ?', [$caseNo]);
    db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
    db_exec('DELETE FROM households WHERE household_id = ?', [$houseId]);
    db_exec('DELETE FROM lookups WHERE lookup_id IN (?, ?, ?)', [$employmentId, $incidentTypeId, $purokId]);
}
