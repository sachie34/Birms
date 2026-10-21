<?php
/** B6 tests: blotter cases, parties, hearings, case look-up, clearance hint, summons. */
declare(strict_types=1);

function run_b6(array $S, string $base): void
{
    $clk = $S['clerk']; $sec = $S['secretary']; $cap = $S['captain']; $adm = $S['admin'];
    $residue = function (): void {   // also clears rows left by an interrupted run
        db_exec("DELETE FROM hearings WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE narrative LIKE 'ZZ-B6%')");
        db_exec("DELETE FROM blotter_parties WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE narrative LIKE 'ZZ-B6%')");
        db_exec("DELETE FROM blotters WHERE narrative LIKE 'ZZ-B6%'");
        db_exec("DELETE FROM sms_outbox WHERE request_id IN (SELECT request_id FROM document_requests WHERE purpose LIKE 'ZZ-B6%')");
        db_exec("DELETE FROM document_requests WHERE purpose LIKE 'ZZ-B6%'");
        db_exec("DELETE FROM residents WHERE last_name LIKE 'ZZTest6%'"); db_exec("DELETE FROM households WHERE household_code LIKE 'ZZTEST-B6%'");
    };
    $residue();
    $wOld = db_val("SELECT setting_value FROM settings WHERE setting_key = 'summons_wording'");
    db_exec("UPDATE settings SET setting_value = NULL WHERE setting_key = 'summons_wording'");

    $today = date('Y-m-d');
    $d = ['incident_date' => $today, 'incident_place' => 'ZZ Test Plaza', 'purok_id' => '', 'narrative' => 'ZZ-B6 default narrative text', 'c_name' => 'Zzcomp Alpha', 'c_age' => '34', 'c_address' => 'ZZ Test Street', 'c_contact' => '09171230001',
          'r_name' => 'Zzresp Bravo', 'r_age' => '41', 'r_address' => 'ZZ Other Street', 'r_contact' => ''];
    $file   = fn(Http $h, array $o = []) => post_as($h, '/blotters/form.php', '/blotters/form.php', $o + $d);
    $cid    = fn(string $tag) => (int) db_val('SELECT blotter_id FROM blotters WHERE narrative LIKE ?', ['ZZ-B6 ' . $tag . '%']);
    $cno    = fn(int $id) => (string) db_val('SELECT case_no FROM blotters WHERE blotter_id = ?', [$id]);
    $status = fn(int $id) => (string) db_val('SELECT status FROM blotters WHERE blotter_id = ?', [$id]);
    $cases  = fn() => (int) db_val("SELECT COUNT(*) FROM blotters WHERE narrative LIKE 'ZZ-B6%'");
    $page   = fn(int $id) => '/blotters/view.php?id=' . $id;
    $move   = fn(Http $h, int $id, string $to, string $note = '') => post_as($h, $page($id), '/blotters/action.php', ['id' => (string) $id, 'do' => 'move', 'to' => $to, 'note' => $note]);
    $do     = fn(Http $h, int $id, string $what) => post_as($h, $page($id), '/blotters/action.php', ['id' => (string) $id, 'do' => $what]);
    $sched  = fn(Http $h, int $id, string $when, string $venue = 'ZZ Test Hall') => post_as($h, $page($id), '/blotters/hearing_action.php', ['do' => 'schedule', 'id' => (string) $id, 'scheduled_at' => $when, 'venue' => $venue, 'notes' => '']);
    $outc   = fn(Http $h, int $id, int $hid, string $out, string $why = '') => post_as($h, $page($id), '/blotters/hearing_action.php', ['do' => 'outcome', 'hearing' => (string) $hid, 'outcome' => $out, 'reason' => $why]);
    $future = fn(int $days) => date('Y-m-d\TH:i', strtotime("+{$days} days 10:00"));
    $hid    = fn(int $id, string $st = 'Scheduled') => (int) db_val('SELECT hearing_id FROM hearings WHERE blotter_id = ? AND status = ? ORDER BY hearing_id DESC LIMIT 1', [$id, $st]);
    $nHear  = fn(int $id) => (int) db_val('SELECT COUNT(*) FROM hearings WHERE blotter_id = ?', [$id]);
    $nPart  = fn(int $id) => (int) db_val('SELECT COUNT(*) FROM blotter_parties WHERE blotter_id = ?', [$id]);
    $audit  = fn(string $a) => (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$a]);

    $bad = ''; foreach (['/blotters/index.php' => 'blotters.view', '/blotters/form.php' => 'blotters.add', '/blotters/lookup.php' => 'blotters.view', '/blotters/hearings.php' => 'hearings.view'] as $p => $perm) { [$ok, $why] = rbac_page($S, $p, $perm); if (!$ok) { $bad .= "$p: $why"; } }
    record('B6-1', 'Blotter, filing, look-up and hearing pages: every role gets exactly what the permission map says', $bad === '', $bad);

    /* ---- filing ---- */
    $n0 = $cases();
    $r = $file($clk, ['narrative' => 'ZZ-B6 one: fence dispute between neighbours']); $A = $cid('one'); $aNo = $cno($A);
    $file($clk, ['narrative' => 'ZZ-B6 two: noise complaint', 'c_name' => 'Zzcomp Charlie', 'r_name' => 'Zzresp Delta']); $B = $cid('two'); $bNo = $cno($B);
    $adminTry = $file($adm, ['narrative' => 'ZZ-B6 admin attempt']); $AdminCase = $cid('admin attempt');
    $parts = db_all('SELECT party_role FROM blotter_parties WHERE blotter_id = ? ORDER BY party_id', [$A]);
    record('B6-2', 'A clerk files a case: numbered BLT-YYYY-NNNN, Pending, both parties saved, audited; numbers are unique and sequential; the Administrator also files a case',
        $r['status'] === 302 && preg_match('/^BLT-' . date('Y') . '-\d{4}$/', $aNo) === 1 && $status($A) === 'Pending' && array_column($parts, 'party_role') === ['Complainant', 'Respondent']
        && $audit('blotter_filed') >= 3 && (int) substr($bNo, -4) === (int) substr($aNo, -4) + 1 && $AdminCase > 0 && $adminTry['status'] === 302 && $status($AdminCase) === 'Pending' && $cases() === $n0 + 3);

    $variants = ['empty narrative' => ['narrative' => ''], 'short narrative' => ['narrative' => 'ZZ-B6'], 'future date' => ['incident_date' => date('Y-m-d', strtotime('+1 day'))], 'bad date' => ['incident_date' => '31/12/2026'],
                 'older than 10 years' => ['incident_date' => date('Y-m-d', strtotime('-11 years'))], 'short place' => ['incident_place' => 'ab'], 'no complainant name' => ['c_name' => ''], 'digits in name' => ['c_name' => 'Bob9 Cruz'],
                 'age 150' => ['c_age' => '150'], 'age abc' => ['c_age' => 'abc'], 'bad phone' => ['c_contact' => '-------'], 'respondent age only' => ['r_name' => '', 'r_age' => '30', 'r_address' => '', 'r_contact' => ''],
                 'unknown purok' => ['purok_id' => '99999999'], 'html in name' => ['r_name' => '<b>Evil</b>'], 'array narrative' => ['narrative' => ['x']]];
    $notRej = []; $nb = $cases();
    foreach ($variants as $name => $o) { $r = $file($clk, $o); if ($r['status'] !== 200 || !str_contains($r['body'], 'alert-danger')) { $notRej[] = $name . '=' . $r['status']; } }
    record('B6-3', 'Fifteen kinds of bad filing are refused with a message and nothing is saved', !$notRej && $cases() === $nb, implode(', ', $notRej));

    /* ---- editing ---- */
    $e0 = $clk->get('/blotters/form.php?id=' . $A);
    $e1 = post_as($sec, '/blotters/form.php?id=' . $A, '/blotters/form.php?id=' . $A, ['incident_date' => $today, 'incident_place' => 'ZZ Test Corrected Place', 'purok_id' => '', 'narrative' => 'ZZ-B6 one: fence dispute (corrected)']);
    $e2 = post_as($sec, '/blotters/form.php?id=' . $A, '/blotters/form.php?id=' . $A, ['incident_date' => 'nope', 'incident_place' => 'x', 'purok_id' => '', 'narrative' => 'short']);
    record('B6-4', 'Editing: a clerk may not; the Secretary corrects details (audited); bad edits are refused; unknown or array ids never crash',
        $e0['status'] === 403 && $e1['status'] === 302 && db_val('SELECT incident_place FROM blotters WHERE blotter_id = ?', [$A]) === 'ZZ Test Corrected Place' && $status($A) === 'Pending' && $nPart($A) === 2
        && $audit('blotter_updated') >= 1 && $e2['status'] === 200 && str_contains($e2['body'], 'alert-danger') && $cap->get('/blotters/form.php?id=' . $A)['status'] === 200
        && $sec->get('/blotters/form.php?id=99999999')['status'] === 404 && $sec->get('/blotters/form.php?id[]=1')['status'] < 500);

    /* ---- status moves ---- */
    $m1 = $move($clk, $A, 'Active'); $m1a = $move($adm, $AdminCase, 'Active'); $adminCaseStatus = $status($AdminCase); $s1 = $status($A);
    $m2 = $move($cap, $A, 'Scheduled'); $s2 = $status($A);
    $m3 = $move($cap, $A, 'Active'); $s3 = $status($A);
    $m4 = $move($cap, $A, 'Pending'); $s4 = $status($A);
    $m5 = $move($cap, $A, 'Resolved', ''); $s5 = $status($A);
    $m6 = post_as($cap, $page($A), '/blotters/action.php', ['id' => (string) $A, 'do' => 'move', 'to' => ['Resolved'], 'note' => 'x']);
    record('B6-5', 'Status rules: the clerk is denied while Administrator and Captain can make permitted moves; Pending to Active works for the Captain; Scheduled cannot be set by hand; no way back; Resolved needs a note',
        $m1['status'] === 403 && $m1a['status'] === 302 && $adminCaseStatus === 'Active' && $s1 === 'Pending' && $s2 === 'Pending' && $m3['status'] === 302 && $s3 === 'Active' && $s4 === 'Active' && $s5 === 'Active' && $m6['status'] < 500 && $status($A) === 'Active'
        && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'blotter_status_changed' AND remarks LIKE ?", [$aNo . ': Pending -> Active%']) === 1);
    $m7 = $move($cap, $B, 'Resolved', 'ZZ withdrawn by complainant'); $rowB = db_one('SELECT status, resolution_note, resolved_at FROM blotters WHERE blotter_id = ?', [$B]);
    $m8 = $move($sec, $B, 'Active'); $m9 = $move($sec, $B, 'Resolved', 'again');
    record('B6-6', 'A Pending case can be resolved straight away with a note (resolved time recorded); a Resolved case is final', $m7['status'] === 302 && $rowB['status'] === 'Resolved' && $rowB['resolution_note'] === 'ZZ withdrawn by complainant' && $rowB['resolved_at'] !== null && $status($B) === 'Resolved');

    /* ---- parties ---- */
    $p0 = $clk->get('/blotters/party.php?case=' . $A); $np = $nPart($A);
    $p1 = post_as($clk, $page($A), '/blotters/party.php?case=' . $A, ['party_role' => 'Respondent', 'name' => 'Zzresp Echo', 'age' => '22', 'address' => '', 'contact' => '']);
    $p2 = post_as($sec, '/blotters/party.php?case=' . $A, '/blotters/party.php?case=' . $A, ['party_role' => 'Respondent', 'name' => 'Zzresp Echo', 'age' => '22', 'address' => 'ZZ Test Lane', 'contact' => '0917 123 0002']);
    $p3 = post_as($sec, '/blotters/party.php?case=' . $A, '/blotters/party.php?case=' . $A, ['party_role' => 'Respondent', 'name' => 'Bad9', 'age' => '', 'address' => '', 'contact' => '']);
    $p4 = post_as($sec, '/blotters/party.php?case=' . $A, '/blotters/party.php?case=' . $A, ['party_role' => 'Witness', 'name' => 'Zzwit Foxtrot', 'age' => '', 'address' => '', 'contact' => '']);
    $pid = (int) db_val("SELECT party_id FROM blotter_parties WHERE blotter_id = ? AND full_name = 'Zzresp Echo'", [$A]);
    $p5 = post_as($sec, '/blotters/party.php?id=' . $pid, '/blotters/party.php?id=' . $pid, ['party_role' => 'Respondent', 'name' => 'Zzresp Echo', 'age' => '23', 'address' => 'ZZ Test Lane', 'contact' => '09171230002']);
    record('B6-7', 'Parties: a clerk cannot add; the Secretary adds and corrects (audited); bad names and roles are refused; a resolved case takes no new party; unknown ids give 404',
        $p0['status'] === 403 && $p1['status'] === 403 && $p2['status'] === 302 && $nPart($A) === $np + 1 && $p3['status'] === 200 && str_contains($p3['body'], 'alert-danger') && $p4['status'] === 200 && $nPart($A) === $np + 1
        && $p5['status'] === 302 && (int) db_val('SELECT age FROM blotter_parties WHERE party_id = ?', [$pid]) === 23 && db_val('SELECT contact_number FROM blotter_parties WHERE party_id = ?', [$pid]) === '09171230002'
        && $sec->get('/blotters/party.php?case=' . $B)['status'] === 403 && $sec->get('/blotters/party.php?id=99999999')['status'] === 404 && $audit('blotter_party_added') >= 1 && $audit('blotter_party_updated') >= 1);

    /* ---- hearings: scheduling ---- */
    $stat = [$sched($clk, $A, $future(3))['status'], $sched($cap, $A, $future(4))['status'], $sched($adm, $AdminCase, $future(3))['status']];
    $hn = $nHear($A);
    $r = $sched($sec, $A, $future(3)); $H1 = $hid($A);
    $bads = ['past' => '2020-01-01T09:00', 'garbage' => 'soon', 'over a year' => date('Y-m-d\TH:i', strtotime('+2 years')), 'duplicate time' => $future(3)];
    $okBad = true; $before = $nHear($A); foreach ($bads as $lbl => $w) { $sched($sec, $A, $w); if ($nHear($A) !== $before) { $okBad = false; } }
    $sched($sec, $A, $future(9), 'ab'); $sched($sec, $A, $future(9), 'ZZ Test Hall');  $extra = $nHear($A);
    $aw = post_as($sec, $page($A), '/blotters/hearing_action.php', ['do' => 'schedule', 'id' => (string) $A, 'scheduled_at' => ['x'], 'venue' => '', 'notes' => '']);
    record('B6-8', 'Scheduling: only the Secretary may; it sets the case to Scheduled (audited); past, garbage, far-future, duplicate times and short venues are refused; a resolved case takes no hearing',
        $stat === [403, 403, 302] && $hn === 0 && $r['status'] === 302 && $H1 > 0 && $status($A) === 'Scheduled' && $okBad && $extra === 2 && $aw['status'] < 500
        && $audit('hearing_scheduled') >= 2 && $nHear($B) === 0 && $sched($sec, $B, $future(4))['status'] === 302 && $nHear($B) === 0);

    /* ---- hearings: outcomes ---- */
    $H2 = $hid($A);  // the later one (+9 days) is the newest
    $o0 = $outc($sec, $A, $H1, 'Held'); $s0 = db_val('SELECT status FROM hearings WHERE hearing_id = ?', [$H1]);
    $o1 = $outc($clk, $A, $H1, 'Cancelled', 'ZZ clerk try'); $o1a = $outc($adm, $A, $H1, 'Held'); $afterAdminOutcome = db_one('SELECT status, scheduled_at FROM hearings WHERE hearing_id = ?', [$H1]);
    $o2 = $outc($cap, $A, $H1, 'Postponed', ''); $afterEmptyPostpone = db_val('SELECT status FROM hearings WHERE hearing_id = ?', [$H1]);
    $o3 = $outc($cap, $A, $H1, 'Cancelled', 'ZZ complainant unavailable'); $stillSched = $status($A);   // H2 is still open
    $o4 = $outc($cap, $A, $H2, 'Postponed', 'ZZ hall in use'); $afterAll = $status($A);                    // none left: back to Active
    $o5 = $outc($cap, $A, $H1, 'Held');                                                                     // already final
    $sched($sec, $A, $future(5)); $H3 = $hid($A); db_exec('UPDATE hearings SET scheduled_at = ? WHERE hearing_id = ?', [date('Y-m-d H:i:s', strtotime('-2 hours')), $H3]);
    $o6 = $outc($cap, $A, $H3, 'Held');
    $outcomeChecks = [
        'held-too-early-not-saved' => $o0['status'] === 302 && $s0 === 'Scheduled',
        'clerk-denied' => $o1['status'] === 403,
        'admin-held-too-early-not-saved' => $o1a['status'] === 302 && $afterAdminOutcome['status'] === 'Scheduled',
        'postponed-without-reason-not-saved' => $o2['status'] === 302 && $afterEmptyPostpone === 'Scheduled',
        'cancelled-with-reason' => db_val('SELECT status FROM hearings WHERE hearing_id = ?', [$H1]) === 'Cancelled',
        'case-stays-scheduled-with-open-hearing' => $stillSched === 'Scheduled',
        'case-returns-active-when-no-hearing-open' => $afterAll === 'Active',
        'postponed-hearing-saved' => db_val('SELECT status FROM hearings WHERE hearing_id = ?', [$H2]) === 'Postponed',
        'cancelled-hearing-saved' => db_val('SELECT status FROM hearings WHERE hearing_id = ?', [$H1]) === 'Cancelled',
        'past-held-hearing-saved' => $o6['status'] === 302 && db_val('SELECT status FROM hearings WHERE hearing_id = ?', [$H3]) === 'Held',
        'case-remains-active' => $status($A) === 'Active',
        'outcomes-audited' => $audit('hearing_outcome') >= 3,
    ];
    $outcomeFailures = array_keys(array_filter($outcomeChecks, static fn(bool $ok): bool => !$ok));
    record('B6-9', 'Hearing outcomes enforce role access, reasons, due times, case-state synchronization and audit logging',
        !$outcomeFailures, $outcomeFailures ? implode(', ', $outcomeFailures) : '');

    /* ---- resolve rules ---- */
    $file($clk, ['narrative' => 'ZZ-B6 four: boundary dispute', 'c_name' => 'Zzcomp Golf', 'r_name' => 'Zzresp Hotel']); $D = $cid('four');
    $sched($sec, $D, $future(6)); $HD = $hid($D);
    $rs1 = $move($cap, $D, 'Resolved', 'ZZ settled'); $afterRefuse = $status($D);
    $outc($cap, $D, $HD, 'Cancelled', 'ZZ parties settled out of court');
    $rs2 = $move($cap, $D, 'Resolved', 'ZZ settled at the barangay'); $rowD = db_one('SELECT status, resolved_at FROM blotters WHERE blotter_id = ?', [$D]);
    $after = [$sched($sec, $D, $future(7))['status'], $nHear($D)]; $np2 = $nPart($D);
    $pp = $sec->get('/blotters/party.php?case=' . $D)['status']; $ee = $sec->get('/blotters/form.php?id=' . $D)['status']; $back = $move($sec, $D, 'Active'); 
    record('B6-10', 'A case with an open hearing cannot be resolved; after the outcome it can; a resolved case accepts no hearing, party or edit and cannot reopen',
        $rs1['status'] === 302 && $afterRefuse === 'Scheduled' && $rs2['status'] === 302 && $rowD['status'] === 'Resolved' && $rowD['resolved_at'] !== null && $after === [302, 1] && $nPart($D) === $np2 && $pp === 403 && $ee === 403 && $status($D) === 'Resolved');

    /* ---- archive ---- */
    $ar0 = $do($cap, $B, 'archive'); $ar1 = $do($clk, $B, 'archive'); $ar2 = $do($sec, $A, 'archive');   // A is not resolved
    $ar3 = $do($sec, $B, 'archive'); $archived = db_val('SELECT archived_at FROM blotters WHERE blotter_id = ?', [$B]) !== null;
    $listSec = $sec->get('/blotters/index.php')['body']; $arcSec = $sec->get('/blotters/index.php?tab=archived')['body']; $arcClk = $clk->get('/blotters/index.php?tab=archived')['body'];
    $viewClk = $clk->get($page($B));
    $rest = $do($sec, $B, 'restore'); $restored = db_val('SELECT archived_at FROM blotters WHERE blotter_id = ?', [$B]) === null;
    $do($sec, $B, 'archive');   // leave it archived for the look-up test
    record('B6-11', 'Archive: only a Resolved case, only by the Secretary; hidden from the main list; the Archived tab is Secretary-only; archived cases stay viewable; restore works',
        $ar0['status'] === 403 && $ar1['status'] === 403 && $ar2['status'] === 302 && $status($A) === 'Active' && db_val('SELECT archived_at FROM blotters WHERE blotter_id = ?', [$A]) === null && $ar3['status'] === 302 && $archived
        && !str_contains($listSec, $bNo) && str_contains($arcSec, $bNo) && !str_contains($arcClk, 'tab=archived') && !str_contains($arcClk, $bNo) && $viewClk['status'] === 200 && str_contains($viewClk['body'], 'archived')
        && $rest['status'] === 302 && $restored && $audit('blotter_archived') >= 2 && $audit('blotter_restored') >= 1);

    /* ---- look-up ---- */
    $lk = fn(Http $h, string $q) => $h->get('/blotters/lookup.php?q=' . rawurlencode($q));
    $before = $audit('blotter_lookup');
    $l1 = $lk($clk, 'zzcomp alpha'); $l2 = $lk($clk, 'alpha ZZCOMP'); $l3 = $lk($sec, 'Charlie'); $l4 = $lk($clk, 'z'); $l5 = $lk($clk, '%%'); $l6 = $lk($clk, 'Zznobody Qxz'); $l7 = $lk($adm, 'alpha');
    record('B6-12', 'Look-up: finds a person by name words in any order across all cases including archived ones; one letter is not enough; % and _ are plain text; each real search is audited; Administrator access is also available',
        str_contains($l1['body'], $aNo) && str_contains($l2['body'], $aNo) && str_contains($l3['body'], $bNo) && str_contains($l3['body'], 'Archived') && str_contains($l4['body'], 'at least two letters') && !str_contains($l4['body'], $aNo)
        && str_contains($l5['body'], 'No blotter record') && str_contains($l6['body'], 'No blotter record') && $audit('blotter_lookup') === $before + 6 && $l7['status'] === 200 && str_contains($l7['body'], $aNo) && $clk->get('/blotters/lookup.php?q[]=x')['status'] < 500);

    /* ---- clearance hint ---- */
    db_exec("INSERT INTO households (household_code, street_address) VALUES ('ZZTEST-B6', 'ZZ Test Street B6')"); $hh = (int) db()->lastInsertId();
    $mk = function (string $first, string $last) use ($hh): int { db_exec("INSERT INTO residents (household_id, last_name, first_name, birthdate, sex, civil_status, contact_number) VALUES (?, ?, ?, '1990-01-01', 'Male', 'Single', NULL)", [$hh, $last, $first]); return (int) db()->lastInsertId(); };
    $rMatch = $mk('Zzcomp', 'Alpha'); $rNone = $mk('Zznone', 'ZZTest6Nomatch'); db_exec("UPDATE residents SET last_name = 'ZZTest6Alpha' WHERE resident_id = ?", [$rMatch]);
    db_exec("UPDATE blotter_parties SET full_name = 'Zzcomp ZZTest6Alpha' WHERE blotter_id = ? AND full_name = 'Zzcomp Alpha'", [$A]);
    $clr = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Barangay Clearance'"); $res = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Certificate of Residency'");
    $req = function (int $resident, int $type, string $purpose) use ($clk): int {
        post_as($clk, '/requests/form.php?resident=' . $resident, '/requests/form.php?resident=' . $resident, ['resident_id' => (string) $resident, 'type_id' => (string) $type, 'purpose' => $purpose, 'assigned_to' => '']);
        return (int) db_val('SELECT request_id FROM document_requests WHERE purpose = ?', [$purpose]);
    };
    $q1 = $req($rMatch, $clr, 'ZZ-B6 clearance for work'); $q2 = $req($rNone, $clr, 'ZZ-B6 clearance other person'); $q3 = $req($rMatch, $res, 'ZZ-B6 residency same person');
    $v1 = $sec->get('/requests/view.php?id=' . $q1)['body']; $v1c = $clk->get('/requests/view.php?id=' . $q1)['body']; $v1p = $cap->get('/requests/view.php?id=' . $q1)['body'];
    $v2 = $sec->get('/requests/view.php?id=' . $q2)['body']; $v3 = $sec->get('/requests/view.php?id=' . $q3)['body'];
    record('B6-13', 'Clearance background check: a matching name shows the possible case (never blocks); no match says so; other documents show nothing',
        $q1 > 0 && $q2 > 0 && $q3 > 0 && str_contains($v1, 'Possible blotter record') && str_contains($v1, $aNo) && str_contains($v1c, 'Possible blotter record') && str_contains($v1p, 'Possible blotter record')
        && str_contains($v2, 'No blotter record matches this name') && !str_contains($v3, 'Possible blotter record') && !str_contains($v3, 'No blotter record matches'));

    /* ---- agenda ---- */
    $file($clk, ['narrative' => 'ZZ-B6 three: stray animals', 'c_name' => 'Zzcomp India', 'r_name' => 'Zzresp Juliet']); $C = $cid('three'); $cNo = $cno($C);
    $sched($sec, $C, $future(6)); $C1 = $hid($C); $sched($sec, $C, $future(7), ''); $C2 = $hid($C);
    $upc = $sec->get('/blotters/hearings.php')['body']; db_exec('UPDATE hearings SET scheduled_at = ? WHERE hearing_id = ?', [date('Y-m-d H:i:s', strtotime('-3 hours')), $C2]);
    $ovr = $sec->get('/blotters/hearings.php?tab=overdue')['body']; $pst = $clk->get('/blotters/hearings.php?tab=past')['body'];
    record('B6-14', 'Hearing agenda: upcoming shows scheduled hearings; a past-due one moves to "Outcome needed"; finished hearings are under Past',
        str_contains($upc, $cNo) && str_contains($ovr, $cNo) && str_contains($ovr, 'Outcome needed') && str_contains($pst, $aNo) && $adm->get('/blotters/hearings.php')['status'] === 200);

    /* ---- summons ---- */
    $sumPage = fn(Http $h, int $id) => $h->get('/blotters/summons.php?id=' . $id);
    $saveW = fn(Http $h, string $t) => post_as($h, '/admin/settings.php?tab=summons', '/admin/settings.php', ['do' => 'summons_save', 'tab' => 'summons', 'summons_wording' => $t]);
    $cnt = fn(int $h) => (int) db_val('SELECT summons_count FROM hearings WHERE hearing_id = ?', [$h]);
    db_exec("UPDATE hearings SET status = 'Held' WHERE hearing_id = ?", [$C2]);                       // keep C2 out of the way
    $s1 = $sumPage($sec, $C1);
    $w0 = $saveW($cap, 'ZZ captain try'); $w0c = $saveW($clk, 'ZZ clerk try'); $w1 = $saveW($sec, 'Appear on {{hearing_date}} {{secret_code}}'); $wNow = db_val("SELECT setting_value FROM settings WHERE setting_key = 'summons_wording'");
    $wording = "You are summoned to appear at {{venue}} on {{hearing_date}} at {{hearing_time}} regarding case {{case_no}}.\nRespondent: {{respondent_names}}.\n<b>Bring a valid ID</b>";
    $w2 = $saveW($sec, $wording);
    $sp0 = $audit('summons_printed');   // audit rows persist between runs, so count from here
    $p1 = $sumPage($sec, $C1); $c1 = $cnt($C1); $p2 = $sumPage($cap, $C1); $p3 = $sumPage($clk, $C1); $c2 = $cnt($C1); $p4 = $sumPage($adm, $C1); $c3 = $cnt($C1);
    $sched($sec, $C, $future(8), ''); $C3 = $hid($C); $s3 = $sumPage($sec, $C3);
    $file($clk, ['narrative' => 'ZZ-B6 five: no respondent known', 'r_name' => '', 'r_age' => '', 'r_address' => '', 'r_contact' => '', 'c_name' => 'Zzcomp Kilo']); $E = $cid('five'); $sched($sec, $E, $future(6)); $HE = $hid($E); $s4 = $sumPage($sec, $HE);
    $s5 = $sumPage($sec, $H1); $s6 = $sumPage($sec, 99999999); $s7 = $sec->get('/blotters/summons.php?id[]=1');
    record('B6-15', 'Summons: blocked until the Secretary sets the official wording; unknown placeholders are refused; only the Secretary/Captain-style permissions save it; blockers for empty venue, no respondent and a finished hearing',
        str_contains($s1['body'], 'cannot be printed yet') && str_contains($s1['body'], 'wording is not set') && $w0['status'] === 403 && $w0c['status'] === 403 && $wNow === null && $w1['status'] < 500 && $w2['status'] === 302
        && db_val("SELECT setting_value FROM settings WHERE setting_key = 'summons_wording'") === $wording
        && str_contains($s3['body'], 'cannot be printed yet') && str_contains($s3['body'], 'venue') && str_contains($s4['body'], 'no respondent') && str_contains($s5['body'], 'still Scheduled') && $s6['status'] === 404 && $s7['status'] < 500);
    record('B6-16', 'Printing a summons: wording is escaped and correct; view-only roles do not increment print count, while Secretary and Administrator actions are counted and audited',
        $p1['status'] === 200 && str_contains($p1['body'], 'SUMMONS') && str_contains($p1['body'], $cNo) && str_contains($p1['body'], 'ZZ Test Hall') && str_contains($p1['body'], 'Zzresp Juliet') && str_contains($p1['body'], '&lt;b&gt;Bring a valid ID&lt;/b&gt;') && !str_contains($p1['body'], '<b>Bring')
        && !str_contains($p1['body'], '{{') && $c1 === 1 && $p2['status'] === 200 && $p3['status'] === 200 && $c2 === 1 && $p4['status'] === 200 && $c3 === $c2 + 1 && $audit('summons_printed') === $sp0 + 2);

    /* ---- CSRF, methods, odd input, XSS ---- */
    $snap = [$status($A), $nPart($A), $nHear($A), $cases()]; $nt = [];
    foreach (['/blotters/action.php' => ['id' => (string) $A, 'do' => 'move', 'to' => 'Resolved', 'note' => 'ZZ no token'], '/blotters/hearing_action.php' => ['do' => 'schedule', 'id' => (string) $A, 'scheduled_at' => $future(2), 'venue' => 'x1', 'notes' => ''],
              '/blotters/party.php?case=' . $A => ['party_role' => 'Respondent', 'name' => 'Zznotoken Lima'], '/blotters/form.php' => $d] as $path => $data) { $nt[] = $sec->post($path, $data)['status']; }
    record('B6-17', 'Every blotter form refuses a POST without a CSRF token (403) and changes nothing; the action endpoints refuse GET (405)', $nt === [403, 403, 403, 403] && [$status($A), $nPart($A), $nHear($A), $cases()] === $snap && $sec->get('/blotters/action.php')['status'] === 405 && $sec->get('/blotters/hearing_action.php')['status'] === 405);
    $odd = []; $paths = ['/blotters/index.php?tab[]=x&q[]=y&page[]=2', '/blotters/index.php?page=-5&q=' . str_repeat('A', 4000), '/blotters/view.php?id[]=1', '/blotters/view.php?id=99999999', '/blotters/hearings.php?tab[]=x&page[]=1', '/blotters/lookup.php?q[]=a&q[]=b', '/blotters/party.php?case[]=1', '/blotters/party.php', '/blotters/summons.php?id[]=2'];
    foreach ($paths as $p) { $r = $sec->get($p); if ($r['status'] >= 500 || leaks($r)) { $odd[] = $p . '=' . $r['status']; } }
    record('B6-18', 'Odd, array and oversized parameters never crash a blotter page or leak errors', !$odd, implode(', ', $odd));
    $file($clk, ['narrative' => 'ZZ-B6 six <script>alert(1)</script> text', 'incident_place' => '<img src=x onerror=alert(1)>', 'c_name' => 'Zzcomp Mike', 'r_name' => 'Zzresp November']); $X = $cid('six');
    $xv = $sec->get($page($X))['body']; $xi = $sec->get('/blotters/index.php')['body'];
    record('B6-19', 'Script and tag text in a narrative or place is shown as text, never run', $X > 0 && str_contains($xv, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($xv, '<script>alert(1)') && str_contains($xv, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($xv, '<img src=x') && !str_contains($xi, '<script>alert'));

    /* ---- the database itself ---- */
    $blocked = [];
    $try = function (string $label, callable $f) use (&$blocked): void { try { $f(); } catch (PDOException $e) { $blocked[] = $label; } };
    $try('delete case with parties', fn() => db_exec('DELETE FROM blotters WHERE blotter_id = ?', [$A]));
    $try('duplicate case number', fn() => db_exec("INSERT INTO blotters (case_no, incident_date, narrative, recorded_at) VALUES (?, CURDATE(), 'ZZ-B6 dup', NOW())", [$aNo]));
    $try('party for a missing case', fn() => db_exec("INSERT INTO blotter_parties (blotter_id, party_role, full_name) VALUES (99999999, 'Respondent', 'Zz Nobody')"));
    $try('hearing for a missing case', fn() => db_exec("INSERT INTO hearings (blotter_id, scheduled_at) VALUES (99999999, NOW())"));
    $try('invalid status value', fn() => db_exec("UPDATE blotters SET status = 'Bogus' WHERE blotter_id = ?", [$A]));
    $try('delete a case that has only hearings', function () use ($sec, $file, $cid, $sched, $future, $hid): void { db_exec("INSERT INTO blotters (case_no, incident_date, narrative, recorded_at) VALUES ('ZZ-ONLY-HEAR', CURDATE(), 'ZZ-B6 hearings only', NOW())"); $i = (int) db()->lastInsertId(); db_exec("INSERT INTO hearings (blotter_id, scheduled_at) VALUES (?, NOW())", [$i]); db_exec('DELETE FROM blotters WHERE blotter_id = ?', [$i]); });
    record('B6-20', 'The DATABASE refuses: deleting a case that has parties or hearings, duplicate case numbers, orphan parties or hearings, and an invalid status (findings H4)', count($blocked) === 6, implode(', ', $blocked));

    $need = ['blotter_filed', 'blotter_updated', 'blotter_status_changed', 'blotter_party_added', 'blotter_party_updated', 'hearing_scheduled', 'hearing_outcome', 'blotter_archived', 'blotter_restored', 'blotter_lookup', 'summons_printed', 'hearing_refused', 'blotter_action_refused', 'blotter_rejected'];
    $missing = array_values(array_filter($need, fn($a) => $audit($a) < 1));
    record('B6-21', 'Every blotter action left an audit row (including refusals and searches)', !$missing, implode(',', $missing));
    $side = [];
    foreach (['admin' => true, 'captain' => true, 'secretary' => true, 'clerk' => true] as $role => $should) {
        $b = $S[$role]->get('/dashboard.php')['body']; $has = str_contains($b, 'blotters/index.php') && str_contains($b, 'blotters/lookup.php') && str_contains($b, 'blotters/hearings.php');
        if ($has !== $should) { $side[] = $role; }
    }
    record('B6-22', 'The sidebar shows Blotter, Hearings and Case Look-up only to roles allowed to use them', !$side, implode(',', $side));
    $out = []; $code = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/schema_check.php') . ' 2>&1', $out, $code);
    record('B6-23', 'schema_check.php agrees with the database (expected_schema.json includes the three blotter tables)', $code === 0, $code === 0 ? '' : implode(' | ', array_slice($out, 0, 3)));

    $residue();
    db_exec("UPDATE settings SET setting_value = ? WHERE setting_key = 'summons_wording'", [$wOld]);
}
