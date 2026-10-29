<?php
/** B5 tests: certificate issuing, fees, O.R. numbers, printing, issuance history. */
declare(strict_types=1);
require_once __DIR__ . '/../app/certificates_lib.php';   // ordinal() and certificate_vars()

function run_b5(array $S, string $base): void
{
    $clk = $S['clerk']; $sec = $S['secretary']; $cap = $S['captain']; $adm = $S['admin'];
    $capOld = db_val("SELECT setting_value FROM settings WHERE setting_key = 'captain_name'");
    db_exec("UPDATE settings SET setting_value = 'Test Captain Name' WHERE setting_key = 'captain_name'");
    db_exec("INSERT IGNORE INTO lookups (list_key, label, sort_order) VALUES ('purok', 'ZZ Test Purok B5', 9991)"); $pk = (int) db_val("SELECT lookup_id FROM lookups WHERE label = 'ZZ Test Purok B5'");
    db_exec("INSERT INTO households (household_code, purok_id, street_address) VALUES ('ZZTEST-B5', ?, 'ZZ Test Address 5')", [$pk]); $hh = (int) db()->lastInsertId();
    db_exec("INSERT INTO households (household_code, street_address) VALUES ('ZZTEST-B5N', 'x')"); $hhNoPurok = (int) db()->lastInsertId();
    $body = 'This certifies {{full_name}}, {{age}}, of {{purok}}, {{address}}, for {{purpose}}. Issued this {{day_ordinal}} day of {{month_year}}. {{captain_name}} {{reference_no}}';
    $mkType = function (string $name, ?string $fee, ?string $tpl) { db_exec('INSERT INTO document_types (name, fee_amount, template_body, is_active, sort_order) VALUES (?, ?, ?, 1, 9991)', [$name, $fee, $tpl]); return (int) db()->lastInsertId(); };
    $tPaid = $mkType('ZZ Test Cert Paid', '50.00', $body); $tFree = $mkType('ZZ Test Cert Free', '0.00', 'Free for {{full_name}}'); $tNoFee = $mkType('ZZ Test Cert NoFee', null, 'x {{full_name}}'); $tNoWord = $mkType('ZZ Test Cert NoWording', '10.00', null);
    $birth = '1990-05-12';
    $mkRes = function (string $first, ?int $household = null, bool $arch = false) use ($hh, $birth): int {
        db_exec("INSERT INTO residents (household_id, last_name, first_name, middle_name, birthdate, sex, civil_status, archived_at) VALUES (?, 'ZZTest5', ?, 'Mid', ?, 'Male', 'Single', ?)", [$household ?? $hh, $first, $birth, $arch ? date('Y-m-d H:i:s') : null]);
        return (int) db()->lastInsertId();
    };
    $n = 0; $mkReq = function (int $res, int $type, string $status = 'Ready', string $purpose = 'ZZ Test purpose') use (&$n): int {
        $n++;
        $requirementsStatus = $status === 'Ready' ? 'Complete' : 'Not checked';
        db_exec('INSERT INTO document_requests (reference_no, resident_id, type_id, purpose, status, requirements_status, requested_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            ['ZZREQ-' . substr(md5((string) microtime(true) . $n), 0, 10), $res, $type, $purpose, $status, $requirementsStatus]);
        return (int) db()->lastInsertId();
    };
    $issue = fn(Http $h, int $req, array $o = []) => post_as($h, '/certificates/issue.php?request=' . $req, '/certificates/issue.php?request=' . $req, ['request_id' => (string) $req] + $o);
    $iss = fn(int $req) => db_one('SELECT * FROM issuances WHERE request_id = ?', [$req]);
    $stat = fn(int $req) => (string) db_val('SELECT status FROM document_requests WHERE request_id = ?', [$req]);
    $res = $mkRes('Ana');

    $bad = ''; foreach (['/certificates/issue.php' => 'certificates.issue', '/certificates/history.php' => 'certificates.history', '/certificates/history_export.php' => 'reports.export'] as $p => $perm) { [$ok, $why] = rbac_page($S, $p, $perm); if (!$ok) { $bad .= "$p: $why"; } }
    record('B5-1', 'Issue, history and export pages: every role gets exactly what the permission map says', $bad === '', $bad);
    $rReady = $mkReq($res, $tPaid); $rPend = $mkReq($res, $tPaid, 'Pending');
    $list = $sec->get('/certificates/issue.php');
    record('B5-2', 'The issue list shows Ready requests only', str_contains($list['body'], 'request=' . $rReady) && !str_contains($list['body'], 'request=' . $rPend));

    // blockers
    $rFee = $mkReq($res, $tNoFee); $rWord = $mkReq($res, $tNoWord); $rArch = $mkReq($mkRes('Arch', null, true), $tPaid); $rNoAddr = $mkReq($mkRes('NoPurok', $hhNoPurok), $tPaid);
    $rNoRequirements = $mkReq($res, $tPaid);
    db_exec("UPDATE document_requests SET requirements_status = 'Not checked' WHERE request_id = ?", [$rNoRequirements]);
    db_exec("UPDATE settings SET setting_value = NULL WHERE setting_key = 'captain_name'"); $rNoCap = $mkReq($res, $tPaid);
    $pgs = [
        $rFee => 'fee for', $rWord => 'wording', $rArch => 'archived', $rNoAddr => '{{purok}}',
        $rNoCap => 'Captain', $rNoRequirements => 'requirements have not been marked Complete',
    ];
    $miss = []; foreach ($pgs as $id => $needle) { $pg = $sec->get('/certificates/issue.php?request=' . $id); $pp = issue_try($sec, $id); if (!str_contains($pg['body'], $needle) || $iss($id) !== null || $stat($id) !== 'Ready' || str_contains($pg['body'], 'name="or_number"')) { $miss[] = $needle; } }
    db_exec("UPDATE settings SET setting_value = 'Test Captain Name' WHERE setting_key = 'captain_name'");
    record('B5-3', 'Certificate issuance is blocked for unchecked requirements, unset fee, unset wording, missing Captain name, missing Purok and archived residents (no certificate, status unchanged)', !$miss, implode(',', $miss));
    $pp = $issue($sec, $rPend, ['or_number' => 'ZZOR-P']);
    record('B5-4', 'A request that is not Ready cannot be issued', $iss($rPend) === null && $stat($rPend) === 'Pending');

    // payment rules
    $clerkTry = $issue($clk, $rReady, ['or_number' => 'ZZOR-CLEK']);
    $a = $issue($sec, $rReady, ['or_number' => '']); $b = $issue($sec, $rReady, ['or_number' => 'bad number!']);
    record('B5-5', 'Clerk cannot issue; authorized issuance still validates O.R. values without saving invalid records', $clerkTry['status'] === 403 && $iss($rReady) === null && $stat($rReady) === 'Ready' && str_contains($a['body'], 'O.R.') && str_contains($b['body'], 'letters, numbers and dashes'));
    $ok = $issue($sec, $rReady, ['or_number' => 'ZZOR-1']); $row = $iss($rReady);
    $ageNow = age_on($birth);
    record('B5-6', 'Secretary issues: reference CERT-YYYY-NNNN, fee snapshot, O.R., request becomes Claimed', $ok['status'] === 302 && str_contains((string) loc($ok), 'print.php') && $row !== null && preg_match('/^CERT-' . date('Y') . '-\d{4,}$/', (string) $row['reference_no']) === 1 && (float) $row['fee_amount'] === 50.0 && $row['or_number'] === 'ZZOR-1' && $stat($rReady) === 'Claimed');
    $bt = (string) ($row['body_text'] ?? '');
    record('B5-7', 'The wording was filled from the record (uppercase name, age, Purok, address, day, month, Captain, reference)', str_contains($bt, 'ANA MID ZZTEST5') && str_contains($bt, (string) $ageNow) && str_contains($bt, 'ZZ Test Purok B5') && str_contains($bt, 'ZZ Test Address 5') && str_contains($bt, ordinal((int) date('j')) . ' day of ' . date('F Y')) && str_contains($bt, 'Test Captain Name') && str_contains($bt, (string) $row['reference_no']) && !str_contains($bt, '{{'), $bt);
    $audit = (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'certificate_issued' AND entity_id = ?", [$row['issuance_id'] ?? 0]) + (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'request_status_changed' AND entity_id = ? AND remarks LIKE '%Claimed via%'", [$rReady]);
    record('B5-8', 'Issuing and its two audit rows were saved together', $audit === 2);

    $r2 = $mkReq($res, $tPaid); $dup = $issue($sec, $r2, ['or_number' => 'ZZOR-1']);
    record('B5-9', 'The same O.R. number cannot be used twice; the second request stays Ready', str_contains($dup['body'], 'already used') && $iss($r2) === null && $stat($r2) === 'Ready');
    $again = $issue($sec, $rReady, ['or_number' => 'ZZOR-2']);
    record('B5-10', 'Issuing the same request again is refused (only one certificate per request)', (int) db_val('SELECT COUNT(*) FROM issuances WHERE request_id = ?', [$rReady]) === 1);
    $rFree = $mkReq($res, $tFree); $f1 = $issue($sec, $rFree, ['or_number' => '']); $frow = $iss($rFree);
    record('B5-11', 'A free document (fee 0, not "unset") issues without an O.R. number', $f1['status'] === 302 && $frow !== null && (float) $frow['fee_amount'] === 0.0 && $frow['or_number'] === null);

    // permanence
    db_exec("UPDATE document_types SET template_body = 'CHANGED WORDING', fee_amount = 999 WHERE type_id = ?", [$tPaid]); db_exec("UPDATE settings SET setting_value = 'Someone Else' WHERE setting_key = 'captain_name'");
    $pr = $sec->get('/certificates/print.php?id=' . $row['issuance_id']);
    record('B5-12', 'Issued certificates are permanent: later changes to wording, fee or Captain do not alter them', str_contains($pr['body'], 'ANA MID ZZTEST5') && !str_contains($pr['body'], 'CHANGED WORDING') && str_contains($pr['body'], 'Test Captain Name') && !str_contains($pr['body'], 'Someone Else') && str_contains($pr['body'], '₱50.00'));
    db_exec("UPDATE document_types SET template_body = ?, fee_amount = 50 WHERE type_id = ?", [$body, $tPaid]); db_exec("UPDATE settings SET setting_value = 'Test Captain Name' WHERE setting_key = 'captain_name'");

    // Viewing a certificate must not count as a print action; only the CSRF-protected POST does.
    $iid = (int) $row['issuance_id']; $url = '/certificates/print.php?id=' . $iid;
    $c0 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $capView = $cap->get($url); $c1 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $clkView = $clk->get($url); $c2 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $capPrint = post_as($cap, $url, $url, ['do' => 'print']);
    $afterCapPrint = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $clkPrint = post_as($clk, $url, $url, ['do' => 'print']);
    $c3 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $secView = $sec->get($url); $c4 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $secPrint = post_as($sec, $url, $url, ['do' => 'print']);
    $c5 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $admView = $adm->get($url); $c6 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    $admPrint = post_as($adm, $url, $url, ['do' => 'print']);
    $c7 = (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$iid]);
    record('B5-13', 'Previews do not change print counts; Clerk, Secretary and Administrator must submit a print action that is counted and audited',
        $capView['status'] === 200 && $c1 === $c0 && $clkView['status'] === 200 && $c2 === $c0
        && $capPrint['status'] === 403 && $afterCapPrint === $c0
        && $clkPrint['status'] === 302 && str_contains(loc($clkPrint), 'print=1') && $c3 === $c0 + 1
        && $secView['status'] === 200 && $c4 === $c3 && $secPrint['status'] === 302 && $c5 === $c3 + 1
        && $admView['status'] === 200 && $c6 === $c5 && $admPrint['status'] === 302 && $c7 === $c5 + 1
        && str_contains($clkView['body'], 'Republic of the Philippines')
        && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'certificate_print_requested'") >= 3);
    $capPdf = $cap->get('/certificates/pdf.php?id=' . $iid);
    record('B5-21', 'Captain can preview an issued certificate but cannot submit a print action or download a PDF; print-authorized roles receive controls',
        $capView['status'] === 200
        && str_contains($capView['body'], 'Preview only')
        && !str_contains($capView['body'], 'name="do" value="print"')
        && !str_contains($capView['body'], 'Download PDF')
        && str_contains($clkView['body'], 'name="do" value="print"')
        && str_contains($clkView['body'], 'Download PDF')
        && $capPdf['status'] === 403);

    // history
    $h = $sec->get('/certificates/history.php?q=ZZOR-1'); $h2 = $sec->get('/certificates/history.php?q=ZZTest5&type=' . $tFree); $h3 = $sec->get('/certificates/history.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d') . '&q=ZZTest5'); $h4 = $sec->get('/certificates/history.php?to=2000-01-01&q=ZZTest5');
    $sum = (float) db_val("SELECT SUM(fee_amount) FROM issuances WHERE resident_name LIKE '%ZZTEST5%'");
    $tbl = fn(array $r): string => preg_replace('~<select\b.*?</select>~s', '', $r['body']) ?? '';   // search the results only, not the filter dropdown that lists every document name
    record('B5-14', 'History: search by O.R./name, document and date filters, and the fee total match the data', str_contains($h['body'], (string) $row['reference_no']) && str_contains($tbl($h2), 'ZZ Test Cert Free') && !str_contains($tbl($h2), 'ZZ Test Cert Paid') && str_contains($tbl($h3), 'ZZ Test Cert Paid') && !str_contains($tbl($h4), 'ZZ Test Cert') && str_contains($tbl($h3), money($sum)));
    $csv = $sec->get('/certificates/history_export.php?q=ZZTest5'); $clkCsv = $clk->get('/certificates/history_export.php');
    record('B5-15', 'History export: secretary gets CSV with a header and it is audited; clerk is refused', $csv['status'] === 200 && str_contains($csv['body'], 'Reference') && str_contains($csv['body'], 'ZZOR-1') && $clkCsv['status'] === 403 && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'issuances_exported'") >= 1);

    // other pages show it
    $rv = $sec->get('/requests/view.php?id=' . $rReady); $rr = $sec->get('/residents/view.php?id=' . $res); $rReady2 = $sec->get('/requests/view.php?id=' . $r2);
    record('B5-16', 'Request view links the certificate; a Ready request shows the Issue button; the resident view lists the document', str_contains($rv['body'], (string) $row['reference_no']) && str_contains($rReady2['body'], 'Issue certificate') && str_contains($rr['body'], 'ZZ Test Cert Paid'));

    // safety
    $nt = $sec->post('/certificates/issue.php?request=' . $r2, ['request_id' => (string) $r2, 'or_number' => 'ZZOR-NT']); $cp = $issue($cap, $r2, ['or_number' => 'ZZOR-CAP']); $clerkDirect = $issue($clk, $r2, ['or_number' => 'ZZOR-CLERK']);
    $odd = [$clk->get('/certificates/issue.php?request[]=1'), $clk->get('/certificates/issue.php?request=99999999'), $sec->get('/certificates/print.php?id[]=1'), $sec->get('/certificates/print.php?id=99999999'), $sec->get('/certificates/history.php?q[]=x&type[]=1&from[]=a&to=garbage&page[]=2'),
            $clk->post('/certificates/issue.php', ['_csrf' => $clk->token('/certificates/issue.php'), 'request_id' => ['1'], 'or_number' => ['x']])];
    $bad = []; foreach ($odd as $i => $o) { if ($o['status'] >= 500 || leaks($o)) { $bad[] = $i . ':' . $o['status']; } }
    record('B5-17', 'No-token issue refused; Clerk and Captain cannot issue; odd parameters never crash', $nt['status'] === 403 && $cp['status'] === 403 && $clerkDirect['status'] === 403 && $iss($r2) === null && !$bad, implode(',', $bad));

    $ids = []; for ($i = 0; $i < 8; $i++) { $q = $mkReq($res, $tFree); $issue($sec, $q); $ids[] = (string) ($iss($q)['reference_no'] ?? ''); }
    record('B5-18', '8 certificates issued in a row all get different reference numbers', count(array_unique($ids)) === 8 && !in_array('', $ids, true));

    // database protections (findings H4 and M1)
    $blocked1 = $blocked2 = $blocked3 = false;
    try { db_exec('DELETE FROM residents WHERE resident_id = ?', [$res]); } catch (PDOException $e) { $blocked1 = true; }
    try { db_exec('DELETE FROM document_types WHERE type_id = ?', [$tPaid]); } catch (PDOException $e) { $blocked2 = true; }
    try { db_exec('INSERT INTO issuances (reference_no, request_id, resident_id, type_id, resident_name, type_name, purpose, fee_amount, or_number, body_text, issued_by_name, issued_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())', ['CERT-ZZ-DUP', $r2, $res, $tPaid, 'x', 'x', 'x', 'ZZOR-1', 'x', 'x']); } catch (PDOException $e) { $blocked3 = true; }
    record('B5-19', 'The DATABASE itself refuses: deleting a resident with certificates, deleting a used document type, and a duplicate O.R. number (findings H4, M1)', $blocked1 && $blocked2 && $blocked3 && (bool) db_val('SELECT COUNT(*) FROM residents WHERE resident_id = ?', [$res]));
    $out = []; $code = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/schema_check.php') . ' 2>&1', $out, $code);
    record('B5-20', 'schema_check.php still agrees with the database (expected_schema.json in sync)', $code === 0, $code === 0 ? '' : implode(' | ', array_slice($out, 0, 3)));

    // New paid-issuance tests create a separate ledger row with an FK to each issuance.
    db_exec("DELETE FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE resident_name LIKE '%ZZTEST5%')");
    db_exec("DELETE FROM issuances WHERE resident_name LIKE '%ZZTEST5%'");
    db_exec("DELETE FROM sms_outbox WHERE request_id IN (SELECT request_id FROM document_requests WHERE reference_no LIKE 'ZZREQ-%')");
    db_exec("DELETE FROM document_requests WHERE reference_no LIKE 'ZZREQ-%'");
    db_exec("DELETE FROM residents WHERE last_name LIKE 'ZZTest5%'"); db_exec("DELETE FROM households WHERE household_code LIKE 'ZZTEST%'");
    db_exec("DELETE FROM document_types WHERE name LIKE 'ZZ Test%'"); db_exec("DELETE FROM lookups WHERE label LIKE 'ZZ Test%'");
    db_exec("UPDATE settings SET setting_value = ? WHERE setting_key = 'captain_name'", [$capOld]);
}
function issue_try(Http $h, int $req): array { return post_as($h, '/certificates/issue.php?request=' . $req, '/certificates/issue.php?request=' . $req, ['request_id' => (string) $req, 'or_number' => 'ZZOR-TRY']); }
