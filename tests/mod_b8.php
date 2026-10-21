<?php
/** B8 tests: private attachments, PDF generation, ledger lifecycle and reconciled history totals. */
declare(strict_types=1);
require_once __DIR__ . '/../app/certificates_lib.php';

function run_b8(array $S, string $base): void
{
    $clk = $S['clerk']; $sec = $S['secretary']; $adm = $S['admin'];
    $tmp = tempnam(sys_get_temp_dir(), 'birms-b8-pdf');
    if ($tmp === false) { record('B8-1', 'Test fixture for PDF upload created', false); return; }
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj <<>> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    $paidIssuanceId = null;

    $cleanup = static function () use ($tmp): void {
        $fileRows = db_all("SELECT da.storage_path FROM document_attachments da
            LEFT JOIN visitor_log vl ON vl.visit_id = da.visit_id
            LEFT JOIN document_requests dr ON dr.request_id = da.request_id
            WHERE (vl.visitor_name LIKE 'ZZB %' OR vl.purpose LIKE 'ZZ-B8%') OR dr.purpose LIKE 'ZZ-B8%'");
        foreach ($fileRows as $row) { @unlink(APP_ROOT . '/' . ltrim((string) $row['storage_path'], '/\\')); }
        db_exec("DELETE FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE resident_name LIKE '%ZZTestB8%')");
        db_exec("DELETE FROM issuances WHERE resident_name LIKE '%ZZTestB8%'");
        db_exec("DELETE FROM document_attachments WHERE visit_id IN (SELECT visit_id FROM visitor_log WHERE (visitor_name LIKE 'ZZB %' OR purpose LIKE 'ZZ-B8%')) OR request_id IN (SELECT request_id FROM document_requests WHERE purpose LIKE 'ZZ-B8%')");
        db_exec("DELETE FROM document_requests WHERE purpose LIKE 'ZZ-B8%'");
        db_exec("DELETE FROM visitor_log WHERE (visitor_name LIKE 'ZZB %' OR purpose LIKE 'ZZ-B8%')");
        db_exec("DELETE FROM residents WHERE last_name = 'ZZTestB8'");
        db_exec("DELETE FROM households WHERE household_code LIKE 'ZZTEST-B8%'");
        db_exec("DELETE FROM document_types WHERE name LIKE 'ZZ-B8%'");
        db_exec("DELETE FROM lookups WHERE label = 'ZZ-B8 Purok'");
    };

    try {
        // Remove residue from an interrupted run, scoped only to the reserved ZZ-B8 fixture names.
        $cleanup();

        $visitClass = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'visit_class' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
        $visitorFields = [
            '_csrf' => $clk->token('/visitors/index.php'),
            'visitor_name' => 'ZZB Visitor',
            'purpose' => 'ZZ-B8 Supporting ID upload test',
            'class_id' => $visitClass ? (string) $visitClass['lookup_id'] : '',
            'visited_at' => date('Y-m-d\\TH:i'),
        ];
        $visitorPost = $clk->upload('/visitors/index.php', $visitorFields, 'attachment', $tmp, 'ZZ-B8 identity.pdf');
        $visitId = null;
        if (preg_match('/[?&]new=(\\d+)/', (string) loc($visitorPost), $m)) { $visitId = (int) $m[1]; }
        $visitorAttachment = $visitId ? db_one('SELECT * FROM document_attachments WHERE visit_id = ? LIMIT 1', [$visitId]) : null;
        $visitorPage = $clk->get('/visitors/index.php?q=ZZ-B8');
        record('B8-1', 'Visitor logbook saves a private PDF attachment and shows its download link',
            $visitorPost['status'] === 302 && $visitId !== null && $visitorAttachment !== null
            && str_contains($visitorPage['body'], 'ZZ-B8 identity.pdf')
            && (int) db_val('SELECT COUNT(*) FROM document_attachments WHERE visit_id = ?', [$visitId]) === 1);

        $download = $visitorAttachment
            ? $clk->get('/files/download.php?id=' . (int) $visitorAttachment['attachment_id'])
            : ['status' => 0, 'headers' => [], 'body' => ''];
        record('B8-2', 'Private attachment download checks authorization and returns the stored file',
            $download['status'] === 200 && str_starts_with($download['body'], '%PDF-')
            && strtolower((string) ($download['headers']['content-type'] ?? '')) === 'application/pdf');

        $invalidPost = $clk->upload('/visitors/index.php', [
            '_csrf' => $clk->token('/visitors/index.php'), 'visitor_name' => 'ZZB Invalid Upload',
            'purpose' => 'ZZ-B8 disallowed extension', 'class_id' => $visitClass ? (string) $visitClass['lookup_id'] : '',
            'visited_at' => date('Y-m-d\\TH:i'),
        ], 'attachment', $tmp, 'not-allowed.txt', 'text/plain');
        record('B8-3', 'Disallowed upload types are rejected without saving a visitor record',
            $invalidPost['status'] === 200 && str_contains($invalidPost['body'], 'Allowed attachment formats')
            && (int) db_val("SELECT COUNT(*) FROM visitor_log WHERE visitor_name = 'ZZB Invalid Upload'") === 0);

        db_exec("INSERT IGNORE INTO lookups (list_key, label, sort_order) VALUES ('purok', 'ZZ-B8 Purok', 9998)");
        $purokId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label = 'ZZ-B8 Purok'");
        $householdCode = 'ZZTEST-B8-' . substr(md5((string) microtime(true)), 0, 6);
        db_exec('INSERT INTO households (household_code, purok_id, street_address) VALUES (?, ?, ?)', [$householdCode, $purokId, 'ZZ-B8 Test Street']);
        $householdId = (int) db()->lastInsertId();
        db_exec("INSERT INTO residents (household_id, last_name, first_name, middle_name, birthdate, sex, civil_status, archived_at)
                 VALUES (?, 'ZZTestB8', 'Test', 'Upload', '1999-04-21', 'Male', 'Single', NULL)", [$householdId]);
        $residentId = (int) db()->lastInsertId();

        db_exec("INSERT INTO document_types (name, fee_amount, template_body, is_active, sort_order)
                 VALUES ('ZZ-B8 Free Certificate', '0.00', 'Draft certificate for {{full_name}}; purpose {{purpose}}.', 1, 9997)");
        $freeTypeId = (int) db()->lastInsertId();
        $requestForm = $clk->upload('/requests/form.php', [
            '_csrf' => $clk->token('/requests/form.php?resident=' . $residentId),
            'resident_id' => (string) $residentId, 'visit_id' => (string) ($visitId ?? ''),
            'type_id' => (string) $freeTypeId, 'purpose' => 'ZZ-B8 Supporting documents',
            'assigned_to' => '', 'requirements_status' => 'Not checked', 'requirements_note' => '',
            'fee_rule_id' => '', 'fee_quantity' => '1',
        ], 'attachment', $tmp, 'ZZ-B8 request-form.pdf');
        $requestId = null;
        if (preg_match('/[?&]id=(\\d+)/', (string) loc($requestForm), $m)) { $requestId = (int) $m[1]; }
        $requestAttachment = $requestId ? db_one('SELECT * FROM document_attachments WHERE request_id = ? LIMIT 1', [$requestId]) : null;
        $requestView = $requestId ? $clk->get('/requests/view.php?id=' . $requestId) : ['status' => 0, 'body' => ''];
        record('B8-4', 'Document request form uploads supporting documents and shows them in request details',
            $requestForm['status'] === 302 && $requestId !== null && $requestAttachment !== null
            && $requestView['status'] === 200 && str_contains($requestView['body'], 'ZZ-B8 request-form.pdf'));

        $freePost = ['status' => 0, 'location' => null];
        $freeIssuanceId = null;
        if ($requestId !== null) {
            db_exec("UPDATE document_requests SET status = 'Ready', requirements_status = 'Complete' WHERE request_id = ?", [$requestId]);
            $freePost = $sec->post('/certificates/issue.php?request=' . $requestId, [
                '_csrf' => $sec->token('/certificates/issue.php?request=' . $requestId),
                'request_id' => (string) $requestId, 'or_number' => '',
                'payment_method' => 'cash', 'signature_text' => 'ZZ B8 Typed Signatory',
            ]);
            if (preg_match('/[?&]id=(\\d+)/', (string) loc($freePost), $m)) { $freeIssuanceId = (int) $m[1]; }
        }
        $freeIssuance = $freeIssuanceId ? db_one('SELECT * FROM issuances WHERE issuance_id = ?', [$freeIssuanceId]) : null;
        $pdf = $freeIssuanceId ? $sec->get('/certificates/pdf.php?id=' . $freeIssuanceId) : ['status' => 0, 'headers' => [], 'body' => ''];
        record('B8-5', 'Issuance saves a typed-signatory snapshot and returns a server-generated PDF',
            $freePost['status'] === 302 && $freeIssuance !== null
            && (string) ($freeIssuance['signature_text'] ?? '') === 'ZZ B8 Typed Signatory'
            && $pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-')
            && strtolower((string) ($pdf['headers']['content-type'] ?? '')) === 'application/pdf'
            && str_contains($pdf['headers']['content-disposition'] ?? '', '.pdf'));

        db_exec("INSERT INTO document_types (name, fee_amount, template_body, is_active, sort_order)
                 VALUES ('ZZ-B8 Paid Certificate', '25.00', 'Paid draft for {{full_name}}.', 1, 9998)");
        $paidTypeId = (int) db()->lastInsertId();
        $paidRef = 'ZZB8-' . substr(md5((string) microtime(true)), 0, 8);
        db_exec("INSERT INTO document_requests (reference_no, resident_id, type_id, purpose, status, requirements_status, requested_at)
                 VALUES (?, ?, ?, 'ZZ-B8 paid issuance', 'Ready', 'Complete', NOW())", [$paidRef, $residentId, $paidTypeId]);
        $paidRequestId = (int) db()->lastInsertId();
        $receipt = 'ZZB8OR-' . substr(md5((string) microtime(true)), 0, 8);
        $paidPost = $sec->post('/certificates/issue.php?request=' . $paidRequestId, [
            '_csrf' => $sec->token('/certificates/issue.php?request=' . $paidRequestId),
            'request_id' => (string) $paidRequestId, 'or_number' => $receipt,
            'payment_method' => 'bank_transfer', 'signature_text' => 'ZZ B8 Ledger Signatory',
        ]);
        if (preg_match('/[?&]id=(\\d+)/', (string) loc($paidPost), $m)) { $paidIssuanceId = (int) $m[1]; }
        else { $paidIssuanceId = null; }
        $paidIssuance = $paidIssuanceId ? db_one('SELECT * FROM issuances WHERE issuance_id = ?', [$paidIssuanceId]) : null;
        $ledger = $paidIssuanceId ? db_one('SELECT * FROM cash_collection_ledger WHERE issuance_id = ?', [$paidIssuanceId]) : null;
        $secretaryId = (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_secretary'");
        record('B8-6', 'Paid issuance creates one pending ledger entry linked to issuance, payment method and collector',
            $paidPost['status'] === 302 && $paidIssuance !== null && $ledger !== null
            && (float) $ledger['fee_amount'] === 25.0
            && $ledger['payment_method'] === 'bank_transfer' && $ledger['reconciliation_status'] === 'pending'
            && (int) $ledger['collector_id'] === $secretaryId
            && (int) db_val('SELECT COUNT(*) FROM cash_collection_ledger WHERE issuance_id = ?', [$paidIssuanceId]) === 1);

        record('B8-7', 'Cash ledger page is available to Administrator and denied to Secretary',
            $adm->get('/admin/ledger.php')['status'] === 200
            && $sec->get('/admin/ledger.php')['status'] === 403
            && str_contains($adm->get('/admin/ledger.php')['body'], 'Cash Collection Ledger'));

        if ($ledger !== null && $paidIssuanceId !== null) {
            $ledgerId = (int) $ledger['ledger_id'];
            $edit = $adm->post('/admin/ledger.php', [
                '_csrf' => $adm->token('/admin/ledger.php'), 'do' => 'update', 'id' => (string) $ledgerId,
                'transaction_date' => date('Y-m-d\\TH:i'), 'fee_amount' => '27.50',
                'payment_method' => 'other', 'notes' => 'ZZ-B8 reviewed edit',
            ]);
            $updated = db_one('SELECT * FROM cash_collection_ledger WHERE ledger_id = ?', [$ledgerId]);
            record('B8-8', 'Ledger update persists amount, method, date and notes',
                $edit['status'] === 302 && (float) ($updated['fee_amount'] ?? 0) === 27.5
                && (string) ($updated['payment_method'] ?? '') === 'other'
                && (string) ($updated['notes'] ?? '') === 'ZZ-B8 reviewed edit');

            $reconcile = $adm->post('/admin/ledger.php', [
                '_csrf' => $adm->token('/admin/ledger.php'), 'do' => 'reconcile', 'id' => (string) $ledgerId, 'to' => 'reconciled',
            ]);
            $updated = db_one('SELECT * FROM cash_collection_ledger WHERE ledger_id = ?', [$ledgerId]);
            $ref = (string) $paidIssuance['reference_no'];
            $historyReconciled = $sec->get('/certificates/history.php?q=' . urlencode($ref) . '&reconciliation=reconciled');
            $historyPending = $sec->get('/certificates/history.php?q=' . urlencode($ref) . '&reconciliation=unreconciled');
            record('B8-9', 'History filters reflect reconciliation and totals come from ledger amount rather than issuance snapshot',
                $reconcile['status'] === 302 && ($updated['reconciliation_status'] ?? '') === 'reconciled'
                && $updated['reconciled_at'] !== null && str_contains($historyReconciled['body'], '27.50')
                && str_contains($historyReconciled['body'], 'Reconciled')
                && str_contains($historyPending['body'], 'No certificates issued yet.'));

            $lockedEdit = $adm->post('/admin/ledger.php', [
                '_csrf' => $adm->token('/admin/ledger.php'), 'do' => 'update', 'id' => (string) $ledgerId,
                'transaction_date' => date('Y-m-d\\TH:i'), 'fee_amount' => '35.00',
                'payment_method' => 'cash', 'notes' => 'ZZ-B8 invalid reconciled edit',
            ]);
            $lockedRow = db_one('SELECT * FROM cash_collection_ledger WHERE ledger_id = ?', [$ledgerId]);
            record('B8-10', 'Reconciled ledger rows cannot be edited without first returning to Pending',
                $lockedEdit['status'] === 200 && str_contains($lockedEdit['body'], 'Mark this transaction Pending before editing')
                && (float) ($lockedRow['fee_amount'] ?? 0) === 27.5 && ($lockedRow['reconciliation_status'] ?? '') === 'reconciled');

            $csv = $sec->get('/certificates/history_export.php?q=' . urlencode($ref) . '&reconciliation=reconciled');
            record('B8-11', 'History CSV contains cash-ledger amount and reconciliation status',
                $csv['status'] === 200 && str_contains($csv['body'], 'Cash ledger amount')
                && str_contains($csv['body'], '27.50') && str_contains(strtolower($csv['body']), 'reconciled'));

            $pending = $adm->post('/admin/ledger.php', [
                '_csrf' => $adm->token('/admin/ledger.php'), 'do' => 'reconcile', 'id' => (string) $ledgerId, 'to' => 'pending',
            ]);
            $delete = $adm->post('/admin/ledger.php', [
                '_csrf' => $adm->token('/admin/ledger.php'), 'do' => 'delete', 'id' => (string) $ledgerId,
            ]);
            record('B8-12', 'Pending ledger records can be deleted with an audit record',
                $pending['status'] === 302 && $delete['status'] === 302
                && db_one('SELECT ledger_id FROM cash_collection_ledger WHERE ledger_id = ?', [$ledgerId]) === null
                && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'cash_ledger_deleted' AND entity_id = ?", [$ledgerId]) > 0);

            $restore = $adm->post('/admin/ledger.php', [
                '_csrf' => $adm->token('/admin/ledger.php'), 'do' => 'create',
                'issuance_id' => (string) $paidIssuanceId, 'payment_method' => 'cash',
            ]);
            $restored = db_one('SELECT * FROM cash_collection_ledger WHERE issuance_id = ?', [$paidIssuanceId]);
            record('B8-13', 'Administrator can record a missing legacy ledger entry for an issued certificate with O.R.',
                $restore['status'] === 302 && $restored !== null
                && $restored['reconciliation_status'] === 'pending' && $restored['payment_method'] === 'cash');
        } else {
            for ($n = 8; $n <= 13; $n++) { record('B8-' . $n, 'Cash ledger lifecycle prerequisite', null, 'Paid issuance/ledger setup failed'); }
        }

        $schemaOutput = []; $schemaCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/schema_check.php') . ' 2>&1', $schemaOutput, $schemaCode);
        record('B8-14', 'Expected schema agrees with the migrated database', $schemaCode === 0, $schemaCode === 0 ? '' : implode(' | ', array_slice($schemaOutput, 0, 4)));
    } finally {
        $cleanup();
        @unlink($tmp);
    }
}
