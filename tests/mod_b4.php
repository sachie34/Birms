<?php
/** B4 tests: visitor logbook, document requests, request tracker, SMS outbox and optional provider. */
declare(strict_types=1);

function run_b4(array $S, string $base): void
{
    $clk = $S['clerk']; $sec = $S['secretary']; $cap = $S['captain']; $adm = $S['admin'];
    $typeId = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Barangay Clearance'");
    db_exec("INSERT IGNORE INTO document_types (name, is_active, sort_order) VALUES ('ZZ Test Inactive Doc', 0, 9990)");
    $inactive = (int) db_val("SELECT type_id FROM document_types WHERE name = 'ZZ Test Inactive Doc'");
    db_exec("INSERT INTO households (household_code, street_address) VALUES ('ZZTEST-B4', 'ZZ Test Street B4')"); $hh = (int) db()->lastInsertId();
    $mkRes = function (string $first, ?string $phone, bool $arch = false) use ($hh): int {
        db_exec("INSERT INTO residents (household_id, last_name, first_name, birthdate, sex, civil_status, contact_number, archived_at) VALUES (?, 'ZZTest4', ?, '1991-01-01', 'Female', 'Single', ?, ?)", [$hh, $first, $phone, $arch ? date('Y-m-d H:i:s') : null]);
        return (int) db()->lastInsertId();
    };
    $rita = $mkRes('Rita', '09170004444'); $nophone = $mkRes('NoPhone', null); $gone = $mkRes('Gone', '09170004445', true);
    $filed = fn(string $purpose, ?int $res = null, array $o = []) => post_as($clk, '/requests/form.php?resident=' . ($res ?? $rita), '/requests/form.php?resident=' . ($res ?? $rita), [
        'resident_id' => (string) ($res ?? $rita), 'type_id' => (string) $typeId, 'purpose' => $purpose, 'assigned_to' => '',
        'requirements_status' => 'Not checked', 'requirements_note' => '',
    ] + $o);
    $rid = fn(string $purpose) => (int) db_val('SELECT request_id FROM document_requests WHERE purpose = ?', [$purpose]);
    $status = fn(int $id) => (string) db_val('SELECT status FROM document_requests WHERE request_id = ?', [$id]);
    $move = fn(Http $h, int $id, string $to, string $note = '') => post_as($h, '/requests/view.php?id=' . $id, '/requests/action.php', ['id' => (string) $id, 'to' => $to, 'note' => $note]);
    $completeRequirements = function (Http $h, int $id) {
        $row = db_one('SELECT resident_id, type_id, purpose, assigned_to FROM document_requests WHERE request_id = ?', [$id]);
        return post_as($h, '/requests/form.php?id=' . $id, '/requests/form.php?id=' . $id, [
            'resident_id' => (string) $row['resident_id'], 'type_id' => (string) $row['type_id'],
            'purpose' => (string) $row['purpose'], 'assigned_to' => (string) ($row['assigned_to'] ?? ''),
            'requirements_status' => 'Complete', 'requirements_note' => '',
        ]);
    };

    $bad = ''; foreach (['/visitors/index.php' => 'requests.view', '/requests/index.php' => 'requests.view', '/requests/form.php' => 'requests.add'] as $p => $perm) { [$ok, $why] = rbac_page($S, $p, $perm); if (!$ok) { $bad .= "$p: $why"; } }
    record('B4-1', 'Visitor, tracker and request-form pages: every role gets exactly what the permission map says', $bad === '', $bad);

    // visitors
    $vb = (int) db_val('SELECT COUNT(*) FROM visitor_log');
    $visit = fn(Http $h, array $o = []) => post_as($h, '/visitors/index.php', '/visitors/index.php', $o + ['visitor_name' => 'ZZTest Visitor', 'visited_at' => date('Y-m-d\TH:i'), 'purpose' => 'Needs a clearance', 'class_id' => '']);
    $variants = ['empty name' => ['visitor_name' => ''], 'digits in name' => ['visitor_name' => 'Bob9'], 'bad time' => ['visited_at' => 'tomorrow'], 'future' => ['visited_at' => date('Y-m-d\TH:i', strtotime('+2 days'))],
                 'over a year ago' => ['visited_at' => date('Y-m-d\TH:i', strtotime('-2 years'))], 'short purpose' => ['purpose' => 'x'], 'array' => ['purpose' => ['a']]];
    $notRej = []; foreach ($variants as $n => $o) { $r = $visit($clk, $o); if ($r['status'] !== 200 || ($n !== 'array' && !str_contains($r['body'], 'alert-danger'))) { $notRej[] = $n; } }
    $r = $visit($clk); $cr = $visit($cap); $nt = $clk->post('/visitors/index.php', ['visitor_name' => 'ZZTest NoToken', 'visited_at' => date('Y-m-d\TH:i'), 'purpose' => 'No token here']);
    record('B4-2', 'Visitor log: 7 bad entries rejected; clerk saves a good one; captain (view only) refused; no-token refused', !$notRej && $r['status'] === 302 && $cr['status'] === 403 && $nt['status'] === 403 && (int) db_val('SELECT COUNT(*) FROM visitor_log') === $vb + 1, implode(',', $notRej));
    $vid = (int) db_val("SELECT visit_id FROM visitor_log WHERE visitor_name = 'ZZTest Visitor' ORDER BY visit_id DESC LIMIT 1");
    $l = $sec->get('/visitors/index.php?q=ZZTest+Vis');
    record('B4-3', 'Visitor search finds the entry and offers "New request"', str_contains($l['body'], 'ZZTest Visitor') && str_contains($l['body'], 'requests/form.php?visit=' . $vid));

    // request filing
    $fs = $clk->get('/requests/form.php?q=ZZTest4');
    $pre = $clk->get('/requests/form.php?visit=' . $vid);
    $roleForm = $clk->get('/requests/form.php?resident=' . $rita);
    record('B4-4a', 'Clerk sees disabled requirements-review controls on request intake', preg_match('~<select[^>]*name="requirements_status"[^>]*disabled~', $roleForm['body']) === 1 && preg_match('~<textarea[^>]*name="requirements_note"[^>]*disabled~', $roleForm['body']) === 1);
    record('B4-4', 'Resident search lists active residents with a Select link and hides archived ones', str_contains($fs['body'], 'ZZTest4, Rita') && str_contains($fs['body'], 'resident=' . $rita) && !str_contains($fs['body'], 'ZZTest4, Gone') && str_contains($pre['body'], 'value="ZZTest Visitor"'));
    $r = $filed('ZZ Test purpose 1'); $id1 = $rid('ZZ Test purpose 1');
    $row = db_one('SELECT reference_no, status FROM document_requests WHERE request_id = ?', [$id1]);
    record('B4-5', 'A request is filed: reference like REQ-YYYY-NNNN, status Pending, audited', $r['status'] === 302 && $row !== null && preg_match('/^REQ-' . date('Y') . '-\d{4,}$/', (string) $row['reference_no']) === 1 && $row['status'] === 'Pending' && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'request_created' AND entity_id = ?", [$id1]) === 1);
    $before = (int) db_val('SELECT COUNT(*) FROM document_requests');
    $rej = []; foreach (['bogus type' => ['type_id' => '99999999'], 'inactive type' => ['type_id' => (string) $inactive], 'short purpose' => ['purpose' => 'x'], 'bad assignee' => ['assigned_to' => '99999999'], 'archived resident' => ['resident_id' => (string) $gone]] as $n => $o) {
        $actor = $n === 'bad assignee' ? $sec : $clk; // Secretary can exercise the assignment validator; Clerk's assignment field is intentionally read-only.
        $r = post_as($actor, '/requests/form.php?resident=' . $rita, '/requests/form.php?resident=' . ($n === 'archived resident' ? $gone : $rita), $o + ['resident_id' => (string) $rita, 'type_id' => (string) $typeId, 'purpose' => 'ZZ Test purpose bad', 'assigned_to' => '', 'requirements_status' => 'Not checked', 'requirements_note' => '']);
        if ($r['status'] >= 500) { $rej[] = $n . '=' . $r['status']; } }
    record('B4-6', 'Bad requests (bogus/inactive document, short purpose, bad assignee, archived resident) are refused and nothing is saved', !$rej && (int) db_val('SELECT COUNT(*) FROM document_requests') === $before, implode(',', $rej));
    $r = post_as($cap, '/requests/index.php', '/requests/form.php?resident=' . $rita, ['resident_id' => (string) $rita, 'type_id' => (string) $typeId, 'purpose' => 'ZZ Test purpose cap']);
    record('B4-7', 'A captain cannot file a request (403, nothing saved)', $r['status'] === 403 && !$rid('ZZ Test purpose cap'));

    // transitions
    $x = $move($cap, $id1, 'Ready'); $a = $status($id1) === 'Pending';
    $clerkReview = $move($clk, $id1, 'Review');
    $clerkBlocked = $clerkReview['status'] === 403 && $status($id1) === 'Pending';
    $x2 = $move($sec, $id1, 'Review'); $b = $status($id1) === 'Review' && db_val('SELECT assigned_to FROM document_requests WHERE request_id = ?', [$id1]) !== null;
    $reviewDetail = $sec->get('/requests/view.php?id=' . $id1);
    $reviewStepOk = str_contains($reviewDetail['body'], 'Request progress') && str_contains($reviewDetail['body'], 'Secretary requirements review') && str_contains($reviewDetail['body'], 'aria-current="step"');
    $clerkRequirements = $completeRequirements($clk, $id1);
    $clerkCannotComplete = db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$id1]) === 'Not checked';
    $x3 = $move($clk, $id1, 'Ready'); $c = $x3['status'] === 403 && $status($id1) === 'Review';
    $adminQueue = $adm->get('/requests/index.php?tab=my'); $secQueue = $sec->get('/requests/index.php?tab=my'); $capQueue = $cap->get('/requests/index.php?tab=my'); $clkQueue = $clk->get('/requests/index.php?tab=my');
    record('B4-8a', 'Only Secretary can start review; Clerk cannot forge requirements completion or approve',
        $a && $clerkBlocked && $b && $clerkCannotComplete && $c && $reviewStepOk);
    record('B4-8c', 'Each role gets a role-labeled work queue containing the right request',
        str_contains($adminQueue['body'], 'Needs Action') && str_contains($adminQueue['body'], (string) $row['reference_no'])
        && str_contains($secQueue['body'], 'Needs Review') && str_contains($capQueue['body'], 'Pending Final Approval')
        && str_contains($clkQueue['body'], 'My Requests')
        && str_contains($secQueue['body'], (string) $row['reference_no'])
        && !str_contains($capQueue['body'], (string) $row['reference_no'])
        && str_contains($clkQueue['body'], (string) $row['reference_no']));

    // A Clerk may not discover or change another user's request by swapping an ID or tab.
    $foreignPurpose = 'ZZ Test foreign-owned request';
    $foreignCreate = post_as($sec, '/requests/form.php?resident=' . $rita, '/requests/form.php?resident=' . $rita, [
        'resident_id' => (string) $rita, 'type_id' => (string) $typeId, 'purpose' => $foreignPurpose,
        'assigned_to' => '', 'requirements_status' => 'Not checked', 'requirements_note' => '',
    ]);
    $foreignId = $rid($foreignPurpose);
    $foreignRef = (string) db_val('SELECT reference_no FROM document_requests WHERE request_id = ?', [$foreignId]);
    $clerkAll = $clk->get('/requests/index.php?tab=all');
    $clerkForeignView = $clk->get('/requests/view.php?id=' . $foreignId);
    $clerkForeignEdit = $clk->get('/requests/form.php?id=' . $foreignId);
    $clerkForeignCancel = $move($clk, $foreignId, 'Cancelled', 'Not my request');
    record('B4-8g', 'Clerk list, direct detail, edit and state-change routes are scoped to filed/assigned requests',
        $foreignCreate['status'] === 302 && $foreignRef !== ''
        && !str_contains($clerkAll['body'], $foreignRef)
        && $clerkForeignView['status'] === 404
        && $clerkForeignEdit['status'] === 404
        && $clerkForeignCancel['status'] === 404
        && $status($foreignId) === 'Pending');
    $outBefore = (int) db_val('SELECT COUNT(*) FROM sms_outbox');
    $incompleteNoNote = post_as($sec, '/requests/form.php?id=' . $id1, '/requests/form.php?id=' . $id1, [
        'resident_id' => (string) $rita, 'type_id' => (string) $typeId, 'purpose' => 'ZZ Test purpose 1',
        'assigned_to' => '', 'requirements_status' => 'Incomplete', 'requirements_note' => '',
    ]);
    $invalidRequirements = $incompleteNoNote['status'] === 200
        && str_contains($incompleteNoNote['body'], 'Explain which requirements are missing')
        && db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$id1]) === 'Not checked';
    $notComplete = $move($cap, $id1, 'Ready');
    $blockedPage = $cap->get('/requests/view.php?id=' . $id1);
    $blocked = $status($id1) === 'Review' && $notComplete['status'] === 302
        && str_contains($blockedPage['body'], 'requirements review');
    $markedComplete = $completeRequirements($sec, $id1);
    $savedComplete = db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$id1]) === 'Complete';
    $reviewedDetail = $sec->get('/requests/view.php?id=' . $id1);
    record('B4-8e', 'Progress tracker advances to final decision when Secretary completes requirements', str_contains($reviewedDetail['body'], 'Captain/Administrator final decision') && str_contains($reviewedDetail['body'], 'aria-current="step"'));
    $secQueueAfterReview = $sec->get('/requests/index.php?tab=my');
    $capQueueAfterReview = $cap->get('/requests/index.php?tab=my');
    record('B4-8d', 'Complete requirements removes request from Secretary queue and moves it to final approver queue',
        !str_contains($secQueueAfterReview['body'], (string) $row['reference_no'])
        && str_contains($capQueueAfterReview['body'], (string) $row['reference_no']));
    $secretaryTry = $move($sec, $id1, 'Ready');
    $secretaryDenied = $secretaryTry['status'] === 403 && $status($id1) === 'Review';
    $x4 = $move($cap, $id1, 'Ready'); $d = $status($id1) === 'Ready';
    $readyDetail = $cap->get('/requests/view.php?id=' . $id1);
    record('B4-8f', 'Approved request shows certificate issuance as the current next step', str_contains($readyDetail['body'], 'Certificate issuance') && str_contains($readyDetail['body'], 'aria-current="step"'));
    $x5 = $move($cap, $id1, 'Review'); $x6 = $move($sec, $id1, 'Cancelled'); $e = $status($id1) === 'Ready';
    record('B4-8b', 'Incomplete requirements block approval; Secretary is denied final approval; Captain can approve completed requirements; a Ready request cannot move backward or cancel', $invalidRequirements && $blocked && $markedComplete['status'] === 302 && $savedComplete && $secretaryDenied && $d && $e);
    $o = db_one('SELECT recipient_number, message, delivery, trigger_event FROM sms_outbox WHERE request_id = ? ORDER BY sms_id DESC LIMIT 1', [$id1]);
    record('B4-9', 'With SMS provider disabled, approval records a log-only outbox entry: recipient, default text and reference', $o !== null && $o['recipient_number'] === '09170004444' && str_contains((string) $o['message'], (string) $row['reference_no']) && $o['delivery'] === 'Logged only' && (int) db_val('SELECT COUNT(*) FROM sms_outbox') === $outBefore + 1);
    $filed('ZZ Test purpose nophone', $nophone); $id2 = $rid('ZZ Test purpose nophone'); $move($sec, $id2, 'Review'); $completeRequirements($sec, $id2); $move($cap, $id2, 'Ready');
    $o2 = db_one('SELECT recipient_number, delivery FROM sms_outbox WHERE request_id = ?', [$id2]);
    $oldTxt = db_val("SELECT setting_value FROM settings WHERE setting_key = 'sms_ready_text'"); db_exec("UPDATE settings SET setting_value = 'Custom: {{reference_no}} / {{document_name}}' WHERE setting_key = 'sms_ready_text'");
    $filed('ZZ Test purpose custom'); $id3 = $rid('ZZ Test purpose custom'); $move($sec, $id3, 'Review'); $completeRequirements($sec, $id3); $move($cap, $id3, 'Ready');
    $o3 = (string) db_val('SELECT message FROM sms_outbox WHERE request_id = ?', [$id3]); db_exec("UPDATE settings SET setting_value = ? WHERE setting_key = 'sms_ready_text'", [$oldTxt]);
    record('B4-10', 'No phone number: logged as "No number"; a custom SMS text from Settings is used', $o2 !== null && $o2['recipient_number'] === null && $o2['delivery'] === 'No number' && str_starts_with($o3, 'Custom: REQ-') && str_contains($o3, 'Barangay Clearance'));

    $filed('ZZ Test purpose reject', $rita); $id4 = $rid('ZZ Test purpose reject'); $move($sec, $id4, 'Review');
    $secretaryReject = $move($sec, $id4, 'Rejected', 'Incomplete requirements'); $secretaryDenied = $secretaryReject['status'] === 403 && $status($id4) === 'Review';
    $noReason = $move($cap, $id4, 'Rejected', ''); $still = $status($id4) === 'Review';
    $withReason = $move($cap, $id4, 'Rejected', 'Incomplete requirements'); $rejected = $status($id4) === 'Rejected' && db_val('SELECT status_note FROM document_requests WHERE request_id = ?', [$id4]) === 'Incomplete requirements';
    $after = $move($cap, $id4, 'Review'); $term = $status($id4) === 'Rejected';
    record('B4-11', 'Secretary cannot reject; Captain must give a reason; a rejected request cannot be reopened', $secretaryDenied && $still && $rejected && $term);
    $filed('ZZ Test purpose cancel', $rita); $id5 = $rid('ZZ Test purpose cancel');
    $capCancel = $move($cap, $id5, 'Cancelled'); $clkCancel = $move($clk, $id5, 'Cancelled', 'Resident left');
    record('B4-12', 'Cancel: captain refused (403); clerk allowed', $capCancel['status'] === 403 && $clkCancel['status'] === 302 && $status($id5) === 'Cancelled');

    $t = $sec->get('/requests/index.php?tab=Ready'); $tc = $sec->get('/requests/index.php?tab=closed'); $ts = $sec->get('/requests/index.php?q=' . urlencode((string) $row['reference_no']));
    $nReady = (int) db_val("SELECT COUNT(*) FROM document_requests WHERE status = 'Ready'");
    record('B4-13', 'Tracker tabs, counts and search match the data', str_contains($t['body'], 'REQ-') && str_contains($t['body'], 'Ready for Pickup <span class="badge text-bg-light">' . $nReady . '</span>') && str_contains($tc['body'], 'ZZTest4, Rita') && str_contains($ts['body'], (string) $row['reference_no']) && !str_contains($t['body'], 'ZZ Test purpose cancel'));

    $refs = []; for ($i = 0; $i < 12; $i++) { $filed('ZZ Test purpose bulk ' . $i); $refs[] = (string) db_val('SELECT reference_no FROM document_requests WHERE purpose = ?', ['ZZ Test purpose bulk ' . $i]); }
    record('B4-14', '12 requests in a row all get different reference numbers', count(array_unique($refs)) === 12 && !in_array('', $refs, true));

    $filed('ZZ Test purpose edit'); $ide = $rid('ZZ Test purpose edit');
    $e1 = post_as($clk, '/requests/form.php?id=' . $ide, '/requests/form.php?id=' . $ide, ['resident_id' => (string) $rita, 'type_id' => (string) $typeId, 'purpose' => 'ZZ Test purpose edited', 'assigned_to' => '', 'requirements_status' => 'Not checked', 'requirements_note' => '']);
    $e2 = $clk->get('/requests/form.php?id=' . $id1); $e3 = $cap->get('/requests/form.php?id=' . $ide);
    record('B4-15', 'Editing: clerk can edit a Pending request; a Ready request and a captain are refused (403)', $e1['status'] === 302 && db_val('SELECT purpose FROM document_requests WHERE request_id = ?', [$ide]) === 'ZZ Test purpose edited' && $e2['status'] === 403 && $e3['status'] === 403);

    $filed('ZZ Test <script>alert(1)</script>'); $idx = $rid('ZZ Test <script>alert(1)</script>');
    $pages = [$sec->get('/requests/view.php?id=' . $idx), $sec->get('/requests/index.php?q=ZZTest4')];
    record('B4-16', 'A script tag in the purpose is escaped (XSS)', !str_contains($pages[0]['body'], '<script>alert(1)</script>') && str_contains($pages[0]['body'], '&lt;script&gt;'));
    $nc = post_as($clk, '/requests/view.php?id=' . $idx, '/requests/action.php', []); // no id
    $odd = [$sec->get('/requests/index.php?tab[]=x&q[]=y&page[]=2'), $sec->get('/requests/view.php?id[]=1'), $sec->get('/requests/view.php?id=99999999'), $sec->get('/requests/form.php?resident[]=1&visit[]=2&q[]=3'), $sec->post('/requests/action.php', ['_csrf' => $sec->token('/requests/index.php'), 'id' => ['1'], 'to' => ['Ready'], 'note' => ['x']]), $sec->get('/visitors/index.php?q[]=x&page[]=3&new[]=1'), $sec->get('/requests/index.php?page=-4&q=' . str_repeat('Z', 4000))];
    $bad = []; foreach ($odd as $i => $o) { if ($o['status'] >= 500 || leaks($o)) { $bad[] = $i . ':' . $o['status']; } }
    record('B4-17', 'Array, oversized and unknown-id parameters never crash a request page', !$bad && $nc['status'] === 404, implode(',', $bad));
    $need = ['visit_logged', 'visit_rejected', 'request_created', 'request_updated', 'request_status_changed', 'request_move_refused', 'request_rejected'];
    $miss = array_filter($need, fn($a) => (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$a]) < 1);
    $rm = (string) db_val("SELECT remarks FROM audit_log WHERE action = 'request_status_changed' AND entity_id = ? ORDER BY audit_id LIMIT 1", [$id1]);
    record('B4-18', 'Every request action is audited, including the old and new status', !$miss && str_contains($rm, 'Pending -> Review'), implode(',', $miss));
    $out = []; $code = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/schema_check.php') . ' 2>&1', $out, $code);
    record('B4-19', 'schema_check.php still agrees with the database (expected_schema.json in sync)', $code === 0, $code === 0 ? '' : implode(' | ', array_slice($out, 0, 3)));

    db_exec("DELETE FROM sms_outbox WHERE request_id IN (SELECT request_id FROM document_requests WHERE purpose LIKE 'ZZ Test%')");
    db_exec("DELETE FROM document_requests WHERE purpose LIKE 'ZZ Test%'");
    db_exec("DELETE FROM visitor_log WHERE visitor_name LIKE 'ZZTest%'");
    db_exec("DELETE FROM residents WHERE last_name LIKE 'ZZTest%'");
    db_exec("DELETE FROM households WHERE household_code LIKE 'ZZTEST%'");
    db_exec("DELETE FROM document_types WHERE name LIKE 'ZZ Test%'");
}
