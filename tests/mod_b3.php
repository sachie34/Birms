<?php
/** B3 tests: residents and households. */
declare(strict_types=1);

function run_b3(array $S, string $base): void
{
    db_exec("INSERT IGNORE INTO lookups (list_key, label, sort_order) VALUES ('purok', 'ZZ Test Purok B3', 9990)");
    $pk = (int) db_val("SELECT lookup_id FROM lookups WHERE label = 'ZZ Test Purok B3'");
    $sec = $S['secretary']; $clk = $S['clerk']; $cap = $S['captain']; $adm = $S['admin'];
    $mk = fn(array $o = []) => $o + ['last_name' => 'ZZTest', 'first_name' => 'Juan', 'middle_name' => 'Santos', 'suffix' => '', 'birthdate' => '1990-05-12', 'sex' => 'Male', 'civil_status' => 'Single',
        'contact_number' => '09171234567', 'category_id' => '', 'employment_status_id' => '', 'voter_status' => 'Unverified', 'household_id' => '', 'new_address' => 'ZZ Test Street 1', 'new_code' => '', 'new_purok' => (string) $pk];
    $count = fn() => (int) db_val("SELECT COUNT(*) FROM residents WHERE last_name LIKE 'ZZTest%'");
    $create = fn(Http $h, array $o = []) => post_as($h, '/residents/form.php', '/residents/form.php', $mk($o));

    $bad = ''; foreach (['/residents/index.php' => 'residents.view', '/residents/households.php' => 'residents.view', '/residents/form.php' => 'residents.add', '/residents/export.php' => 'residents.export', '/residents/household_edit.php' => 'residents.add'] as $p => $perm) { [$ok, $why] = rbac_page($S, $p, $perm); if (!$ok) { $bad .= "$p: $why"; } }
    record('B3-1', 'Resident pages: access is enforced server-side for every role; administrator has full access', $bad === '', $bad);

    $before = $count();
    $r = post_as($cap, '/residents/index.php', '/residents/form.php', $mk());
    record('B3-2', 'A view-only role (captain) cannot register a resident (403, nothing saved)', $r['status'] === 403 && $count() === $before);
    $r = $clk->post('/residents/form.php', $mk());
    record('B3-3', 'Registering without a CSRF token is rejected', $r['status'] === 403 && $count() === $before);

    $variants = ['future birthdate' => ['birthdate' => date('Y-m-d', strtotime('+1 day'))], 'age 176' => ['birthdate' => '1850-01-01'], 'impossible date' => ['birthdate' => '2023-02-31'],
        'bad phone' => ['contact_number' => '-------'], 'digit in name' => ['first_name' => 'Juan3'], 'no sex' => ['sex' => ''], 'bad civil status' => ['civil_status' => 'Complicated'],
        'no purok' => ['new_purok' => ''], 'both household choices' => ['household_id' => '1'], 'unknown household' => ['new_address' => '', 'household_id' => '99999999'],
        'bad category' => ['category_id' => '99999999'], 'bad suffix' => ['suffix' => '<b>'], 'array value' => ['last_name' => ['x']]];
    $notRej = []; foreach ($variants as $name => $o) { $r = $create($clk, $o + ['first_name' => 'Val' . substr(md5($name), 0, 5)] + ['first_name' => 'Val']); if ($r['status'] !== 200 || (!str_contains($r['body'], 'alert-danger') && $name !== 'array value')) { $notRej[] = $name . '=' . $r['status']; } }
    record('B3-4', 'All 13 invalid registrations are rejected with messages and nothing is saved', !$notRej && $count() === $before, implode(', ', $notRej));

    $r = $create($clk, ['first_name' => 'Maria']);
    $rid = (int) db_val("SELECT resident_id FROM residents WHERE last_name = 'ZZTest' AND first_name = 'Maria'");
    $hh = db_one('SELECT h.household_code, h.purok_id FROM residents r JOIN households h ON h.household_id = r.household_id WHERE r.resident_id = ?', [$rid]);
    record('B3-5', 'A clerk registers a resident with a new household (automatic code, Purok saved)', $r['status'] === 302 && $rid > 0 && $hh !== null && preg_match('/^HH-\d{4,}$/', (string) $hh['household_code']) === 1 && (int) $hh['purok_id'] === $pk);
    $r = $create($clk, ['first_name' => 'Maria']);
    $r2 = $create($clk, ['first_name' => 'Maria', 'confirm_dup' => '1', 'new_address' => 'ZZ Test Street 2']);
    record('B3-6', 'Same name and birthdate is refused; allowed only after the "different person" box is ticked', str_contains($r['body'], 'already exists') && $r2['status'] === 302 && (int) db_val("SELECT COUNT(*) FROM residents WHERE last_name = 'ZZTest' AND first_name = 'Maria'") === 2);

    $hid = (int) db_val('SELECT household_id FROM residents WHERE resident_id = ?', [$rid]);
    $e = post_as($clk, '/residents/form.php?id=' . $rid, '/residents/form.php?id=' . $rid, $mk(['first_name' => 'Maria', 'civil_status' => 'Married', 'residency_status' => 'Relocated', 'household_id' => (string) $hid, 'new_address' => '', 'new_purok' => '']));
    $remarks = (string) db_val("SELECT remarks FROM audit_log WHERE action = 'resident_updated' AND entity_id = ? ORDER BY audit_id DESC LIMIT 1", [$rid]);
    record('B3-7', 'Editing saves changes (even for a confirmed same-name twin) and the audit row records civil status and residency changes', $e['status'] === 302 && str_contains($remarks, 'civil_status: Single -> Married') && str_contains($remarks, 'residency_status: Active -> Relocated'), $remarks);

    $r = post_as($clk, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'archive']);
    $denied = $r['status'] === 403 && db_val('SELECT archived_at FROM residents WHERE resident_id = ?', [$rid]) === null;
    $r = post_as($sec, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'archive']);
    $arch = db_val('SELECT archived_at FROM residents WHERE resident_id = ?', [$rid]) !== null;
    $clkList = $clk->get('/residents/index.php?q=ZZTest&show=archived'); $clkView = $clk->get('/residents/view.php?id=' . $rid);
    $secList = $sec->get('/residents/index.php?q=ZZTest&show=archived');
    record('B3-8', 'Clerk cannot archive; Secretary soft-archives; archived residents are hidden from clerks even with ?show=archived', $denied && $arch && $clkView['status'] === 404 && !str_contains($clkList['body'], 'view.php?id=' . $rid . '"') && str_contains($secList['body'], 'view.php?id=' . $rid . '"'));
    $secretaryRestore = post_as($sec, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'restore']);
    $stillArchived = $secretaryRestore['status'] === 403 && db_val('SELECT archived_at FROM residents WHERE resident_id = ?', [$rid]) !== null;
    $adminRestore = post_as($adm, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'restore']);
    record('B3-9', 'Secretary cannot restore; Administrator restores an archived resident', $stillArchived && $adminRestore['status'] === 302 && db_val('SELECT archived_at FROM residents WHERE resident_id = ?', [$rid]) === null && $clk->get('/residents/view.php?id=' . $rid)['status'] === 200);
    $tg = post_as($sec, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'set_active']);
    $okToggle = db_val('SELECT residency_status FROM residents WHERE resident_id = ?', [$rid]) === 'Active';
    record('B3-9b', 'Marking a resident active works and is audited (Relocated -> Active)', $tg['status'] === 302 && $okToggle && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'residency_status_changed' AND entity_id = ?", [$rid]) === 1);
    post_as($sec, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'set_relocated']);
    post_as($sec, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'archive']);
    $st = post_as($sec, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'set_active']);
    record('B3-10', 'Changing the status of an ARCHIVED resident is refused gracefully (no crash, nothing changed)', $st['status'] === 302 && db_val('SELECT residency_status FROM residents WHERE resident_id = ?', [$rid]) === 'Relocated');
    post_as($adm, '/residents/view.php?id=' . $rid, '/residents/action.php', ['id' => (string) $rid, 'action' => 'restore']);

    $a = $mk(['first_name' => 'Pedro', 'birthdate' => '1985-03-03', 'is_head' => '1', 'new_address' => 'ZZ Test Street 3']); $create($clk, $a);
    $pid = (int) db_val("SELECT resident_id FROM residents WHERE first_name = 'Pedro' AND last_name = 'ZZTest'"); $phh = (int) db_val('SELECT household_id FROM residents WHERE resident_id = ?', [$pid]);
    $create($clk, $mk(['first_name' => 'Ana', 'birthdate' => '1986-04-04', 'is_head' => '1', 'household_id' => (string) $phh, 'new_address' => '', 'new_purok' => '']));
    $heads = (int) db_val('SELECT COUNT(*) FROM residents WHERE household_id = ? AND is_household_head = 1', [$phh]);
    record('B3-11', 'Only one household head per household (new head replaces the old one)', $heads === 1 && (int) db_val("SELECT is_household_head FROM residents WHERE first_name = 'Ana' AND last_name = 'ZZTest'") === 1);

    $codeP = (string) db_val('SELECT household_code FROM households WHERE household_id = ?', [$phh]);
    $s1 = $sec->get('/residents/index.php?q=ZZTest+Pedro'); $s2 = $sec->get('/residents/index.php?q=' . urlencode($codeP)); $s3 = $sec->get('/residents/index.php?q=ZZTest&purok=' . $pk); $s4 = $sec->get('/residents/index.php?q=ZZTest&status=Relocated');
    record('B3-12', 'Search works by name parts, household code, Purok and residency status', str_contains($s1['body'], 'ZZTest, Pedro') && !str_contains($s1['body'], 'ZZTest, Ana') && str_contains($s2['body'], 'ZZTest, Pedro') && str_contains($s3['body'], 'ZZTest, Pedro') && str_contains($s4['body'], 'ZZTest, Maria') && !str_contains($s4['body'], 'ZZTest, Pedro'));
    $born = date('Y-m-d', strtotime('-18 years -1 day')); $create($clk, $mk(['first_name' => 'Teen', 'birthdate' => $born, 'new_address' => 'ZZ Test Street 4']));
    $tid = (int) db_val("SELECT resident_id FROM residents WHERE first_name = 'Teen' AND last_name = 'ZZTest'");
    record('B3-13', 'Age is calculated from the birthdate and shown', str_contains($sec->get('/residents/view.php?id=' . $tid)['body'], '· 18'));

    db_exec("UPDATE households SET street_address = '=HYPERLINK(\"http://x\",\"y\")' WHERE household_id = ?", [$phh]);
    $csv = $sec->get('/residents/export.php?q=ZZTest'); $lines = preg_split('/\r?\n/', ltrim($csv['body'], "\xEF\xBB\xBF")) ?: []; $formula = false;
    foreach ($lines as $ln) { foreach (str_getcsv($ln, ',', '"', '\\') as $c) { if (is_string($c) && $c !== '' && strpos('=+-@', $c[0]) !== false) { $formula = true; } } }
    $clkExp = $clk->get('/residents/export.php');
    record('B3-14', 'CSV export: secretary gets a file with a header, formulas are neutralized, clerk is refused, export is audited', $csv['status'] === 200 && str_starts_with($csv['body'], "\xEF\xBB\xBF\"Last name\",\"First name\"") && !$formula && $clkExp['status'] === 403 && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'resident_exported'") >= 1);

    db_exec("UPDATE households SET street_address = '<script>alert(1)</script>' WHERE household_id = ?", [$phh]);
    $pages = [$sec->get('/residents/view.php?id=' . $pid), $sec->get('/residents/households.php?q=script'), $sec->get('/residents/index.php?q=ZZTest')];
    $leak = array_filter($pages, fn($x) => str_contains($x['body'], '<script>alert(1)</script>'));
    record('B3-15', 'Stored text with a script tag is escaped everywhere it is shown (XSS)', !$leak && str_contains($pages[0]['body'], '&lt;script&gt;'));
    db_exec("UPDATE households SET street_address = 'ZZ Test Street 3' WHERE household_id = ?", [$phh]);

    $odd = [$sec->get('/residents/index.php?page[]=1&q[]=x&purok[]=1&status[]=a&category[]=2&show[]=archived'), $sec->get('/residents/view.php?id[]=1'), $sec->get('/residents/view.php?id=99999999'), $sec->get('/residents/form.php?id[]=1'),
            $sec->get('/residents/index.php?page=-9&q=' . str_repeat('A', 4000)), $sec->post('/residents/action.php', ['_csrf' => $sec->token('/residents/index.php'), 'id' => ['1'], 'action' => ['archive']]), $sec->get('/residents/households.php?q[]=x&page[]=1')];
    $bad = []; foreach ($odd as $i => $x) { if ($x['status'] >= 500 || leaks($x)) { $bad[] = $i . ':' . $x['status']; } }
    record('B3-16', 'Array, oversized and unknown-id parameters never crash a resident page', !$bad, implode(',', $bad));

    $r = post_as($clk, '/residents/household_edit.php', '/residents/household_edit.php', ['code' => $codeP, 'purok' => (string) $pk, 'address' => 'ZZ Test Dup']);
    $r2 = post_as($clk, '/residents/household_edit.php', '/residents/household_edit.php', ['code' => 'ZZTEST-HH', 'purok' => (string) $pk, 'address' => 'ZZ Test Street 9']);
    $capHh = post_as($cap, '/residents/households.php', '/residents/household_edit.php', ['code' => 'ZZTEST-X', 'purok' => (string) $pk, 'address' => 'ZZ Test Street 8']);
    record('B3-17', 'Households: duplicate code refused; clerk can add; captain cannot', str_contains($r['body'], 'already used') && $r2['status'] === 302 && $capHh['status'] === 403 && (int) db_val("SELECT COUNT(*) FROM households WHERE household_code = 'ZZTEST-HH'") === 1);

    // Manual ID and voter-list verification, without any external COMELEC/API integration.
    $verifyBefore = (int) db_val('SELECT COUNT(*) FROM resident_verifications WHERE resident_id = ?', [$rid]);
    $verificationPost = [
        'verification_subject' => 'voter_registration', 'verification_method' => 'barangay_voter_list',
        'verification_outcome' => 'registered', 'reference_note' => '2026 local list row reference',
        'remarks' => 'Matched against the staff-held local list.',
    ];
    $verifyCaptain = post_as($cap, '/residents/view.php?id=' . $rid, '/residents/verify.php?id=' . $rid, $verificationPost);
    $verifyNoToken = $clk->post('/residents/verify.php?id=' . $rid, $verificationPost);
    $verifyInvalidMethod = post_as($clk, '/residents/view.php?id=' . $rid, '/residents/verify.php?id=' . $rid, [
        'verification_subject' => 'identity', 'verification_method' => 'barangay_voter_list',
        'verification_outcome' => 'verified', 'reference_note' => '', 'remarks' => '',
    ]);
    $identityCheck = post_as($clk, '/residents/view.php?id=' . $rid, '/residents/verify.php?id=' . $rid, [
        'verification_subject' => 'identity', 'verification_method' => 'government_id',
        'verification_outcome' => 'verified', 'reference_note' => 'Government ID checked in person', 'remarks' => 'ID visually checked; no ID number stored.',
    ]);
    $voterCheck = post_as($clk, '/residents/view.php?id=' . $rid, '/residents/verify.php?id=' . $rid, $verificationPost);
    $verifyCount = (int) db_val('SELECT COUNT(*) FROM resident_verifications WHERE resident_id = ?', [$rid]);
    $verifyRow = db_one("SELECT verification_subject, verification_method, outcome FROM resident_verifications WHERE resident_id = ? ORDER BY verification_id DESC LIMIT 1", [$rid]);
    $verifyView = $sec->get('/residents/view.php?id=' . $rid);
    $captainView = $cap->get('/residents/view.php?id=' . $rid);
    $verifyStatus = (string) db_val('SELECT voter_status FROM residents WHERE resident_id = ?', [$rid]);
    $verifyAuditCount = (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'resident_verification_recorded' AND entity_id = ?", [$rid]);
    $verifyDebug = 'captain=' . $verifyCaptain['status'] . '; no-token=' . $verifyNoToken['status']
        . '; invalid=' . $verifyInvalidMethod['status'] . '; identity=' . $identityCheck['status'] . '; voter=' . $voterCheck['status']
        . '; count=' . $verifyCount . '/' . ($verifyBefore + 2) . '; last=' . json_encode($verifyRow)
        . '; status=' . $verifyStatus . '; audit=' . $verifyAuditCount
        . '; view-heading=' . (str_contains($verifyView['body'], 'Identity & voter verification') ? 'yes' : 'no')
        . '; view-voter-method=' . (str_contains($verifyView['body'], 'Local barangay voter list checked') ? 'yes' : 'no')
        . '; captain-form=' . (str_contains($captainView['body'], 'name="verification_subject"') ? 'present' : 'hidden');
    record('B3-18', 'Staff can record manual ID and voter-list checks with history/audit; local voter outcome updates voter status; invalid methods, missing CSRF and Captain write attempts are refused',
        $verifyCaptain['status'] === 403 && $verifyNoToken['status'] === 403
        && $verifyInvalidMethod['status'] === 302
        && $identityCheck['status'] === 302 && $voterCheck['status'] === 302
        && $verifyCount === $verifyBefore + 2
        && $verifyRow !== null && $verifyRow['verification_subject'] === 'voter_registration'
        && $verifyRow['verification_method'] === 'barangay_voter_list' && $verifyRow['outcome'] === 'registered'
        && $verifyStatus === 'Registered'
        && $verifyAuditCount >= 2
        && str_contains($verifyView['body'], 'Identity & voter verification')
        && str_contains($verifyView['body'], 'Local barangay voter list checked')
        && !str_contains($captainView['body'], 'name="verification_subject"'), $verifyDebug);

    $need = ['resident_created', 'resident_updated', 'resident_archived', 'resident_restored', 'residency_status_changed', 'household_created', 'resident_rejected', 'resident_verification_recorded'];
    $miss = array_filter($need, fn($a) => (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$a]) < 1);
    record('B3-19', 'Every resident action left an audit row', !$miss, implode(',', $miss));
    $tx = (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'resident_created' AND entity_id = ?", [$pid]);
    record('B3-20', 'A resident and its audit row were saved together', $tx === 1);
    $out = []; $code = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/schema_check.php') . ' 2>&1', $out, $code);
    record('B3-21', 'schema_check.php still agrees with the database (expected_schema.json in sync)', $code === 0, $code === 0 ? '' : implode(' | ', array_slice($out, 0, 3)));

    db_exec("DELETE FROM resident_verifications WHERE resident_id IN (SELECT resident_id FROM residents WHERE last_name LIKE 'ZZTest%')");
    db_exec("DELETE FROM residents WHERE last_name LIKE 'ZZTest%'");
    db_exec("DELETE FROM households WHERE street_address LIKE 'ZZ Test%' OR household_code LIKE 'ZZTEST%'");
    db_exec("DELETE FROM lookups WHERE label LIKE 'ZZ Test%'");
}
