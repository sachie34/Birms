<?php
/** B4 tests: revised document requests, request tracker, SMS outbox and optional provider. */
declare(strict_types=1);

function run_b4(array $S, string $base): void
{
    $clk = $S['clerk']; $sec = $S['secretary']; $cap = $S['captain']; $adm = $S['admin'];
    $typeId = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Barangay Clearance'");
    db_exec("INSERT IGNORE INTO document_types (name, is_active, sort_order) VALUES ('ZZ Test Inactive Doc', 0, 9990)");
    $inactive = (int) db_val("SELECT type_id FROM document_types WHERE name = 'ZZ Test Inactive Doc'");
    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeIdNone = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $purokId = (int) ($purok['lookup_id'] ?? 0);
    $mkRes = function (string $first, ?string $phone, bool $arch = false) use ($purokId, $statusId, $typeIdNone): int {
        $residentNo = 'RES-B4-' . strtoupper(bin2hex(random_bytes(4)));
        db_exec("INSERT INTO residents
            (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
             last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
             notification_preference, residency_status, household_id, is_household_head, archived_at)
            VALUES (?, 'ZZ-B4 Test Street', ?, ?, 'Single', ?, 'ZZTestFour', ?, '1991-01-01', 'Female', 'Single', ?, NULL,
                    'email', 'Active', NULL, 0, ?)",
            [$residentNo, $purokId > 0 ? $purokId : null, $statusId > 0 ? $statusId : null,
             $typeIdNone > 0 ? $typeIdNone : null, $first, $phone, $arch ? date('Y-m-d H:i:s') : null]);
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
        $row = db_one('SELECT resident_id, type_id, purpose, assigned_to, fee_rule_id, fee_quantity FROM document_requests WHERE request_id = ?', [$id]);
        return post_as($h, '/requests/form.php?id=' . $id, '/requests/form.php?id=' . $id, [
            'resident_id' => (string) $row['resident_id'], 'type_id' => (string) $row['type_id'],
            'purpose' => (string) $row['purpose'], 'assigned_to' => (string) ($row['assigned_to'] ?? ''),
            'fee_rule_id' => (string) ($row['fee_rule_id'] ?? ''), 'fee_quantity' => (string) ($row['fee_quantity'] ?? 1),
            'requirements_status' => 'Complete', 'requirements_note' => '',
        ]);
    };

    $bad = '';
    foreach (['/requests/index.php' => 'requests.view', '/requests/form.php' => 'requests.add'] as $p => $perm) {
        [$ok, $why] = rbac_page($S, $p, $perm);
        if (!$ok) { $bad .= "$p: $why"; }
    }
    record('B4-1', 'Request tracker and request-form pages enforce their role permissions', $bad === '', $bad);

    // The revised SRS retires the manual visitor logbook. Do not write new visitor rows.
    $visitorCount = (int) db_val('SELECT COUNT(*) FROM visitor_log');
    $retiredVisitor = $clk->get('/visitors/index.php');
    $retiredVisitorPost = $clk->post('/visitors/index.php', ['visitor_name' => 'ZZTest Visitor', 'purpose' => 'Should not be stored']);
    record('B4-2', 'Legacy visitor logbook redirects to service queue and cannot create visitor records',
        $retiredVisitor['status'] === 302 && str_contains((string) $retiredVisitor['location'], 'queue/index.php')
        && $retiredVisitorPost['status'] === 302
        && (int) db_val('SELECT COUNT(*) FROM visitor_log') === $visitorCount);
    $legacySearch = $sec->get('/visitors/index.php?q=ZZTest+Visitor');
    record('B4-3', 'Legacy visitor search no longer exposes a visitor-logbook page',
        $legacySearch['status'] === 302 && str_contains((string) $legacySearch['location'], 'queue/index.php'));

    // request filing
    $fs = $clk->get('/requests/form.php?q=ZZTestFour');
    $roleForm = $clk->get('/requests/form.php?resident=' . $rita);
    record('B4-4a', 'Staff sees disabled requirements-review controls on request intake', preg_match('~<select[^>]*name="requirements_status"[^>]*disabled~', $roleForm['body']) === 1 && preg_match('~<textarea[^>]*name="requirements_note"[^>]*disabled~', $roleForm['body']) === 1);
    $b44Ok = str_contains($fs['body'], 'ZZTestFour, Rita') && str_contains($fs['body'], 'resident=' . $rita)
        && !str_contains($fs['body'], 'ZZTestFour, Gone') && str_contains($fs['body'], 'RES-B4-')
        && str_contains($fs['body'], 'ZZ-B4 Test Street');
    record('B4-4', 'Request intake searches active residents and shows Resident ID, individual address and Purok', $b44Ok,
        'search=' . $fs['status'] . '; resident-name=' . (str_contains($fs['body'], 'ZZTestFour, Rita') ? 'yes' : 'no')
        . '; select-link=' . (str_contains($fs['body'], 'resident=' . $rita) ? 'yes' : 'no')
        . '; archived-hidden=' . (!str_contains($fs['body'], 'ZZTestFour, Gone') ? 'yes' : 'no')
        . '; ID=' . (str_contains($fs['body'], 'RES-B4-') ? 'yes' : 'no')
        . '; address=' . (str_contains($fs['body'], 'ZZ-B4 Test Street') ? 'yes' : 'no'));
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
    $chairmanTry = $move($cap, $id1, 'Ready'); $a = $chairmanTry['status'] === 403 && $status($id1) === 'Pending'
        && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'request_move_refused' AND remarks LIKE ?", [$row['reference_no'] . ': Pending -> Ready%']) >= 1;
    $clerkReview = $move($clk, $id1, 'Review');
    $clerkBlocked = $clerkReview['status'] === 403 && $status($id1) === 'Pending';
    $x2 = $move($sec, $id1, 'Review'); $b = $status($id1) === 'Review' && db_val('SELECT assigned_to FROM document_requests WHERE request_id = ?', [$id1]) !== null;
    $reviewDetail = $sec->get('/requests/view.php?id=' . $id1);
    $reviewStepOk = str_contains($reviewDetail['body'], 'Request progress') && str_contains($reviewDetail['body'], 'Secretary requirements review') && str_contains($reviewDetail['body'], 'aria-current="step"');
    $clerkRequirements = $completeRequirements($clk, $id1);
    $clerkCannotComplete = db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$id1]) === 'Not checked';
    $x3 = $move($clk, $id1, 'Ready'); $c = $x3['status'] === 302 && $status($id1) === 'Review';
    $adminQueue = $adm->get('/requests/index.php?tab=my'); $secQueue = $sec->get('/requests/index.php?tab=my'); $capQueue = $cap->get('/requests/index.php?tab=my'); $clkQueue = $clk->get('/requests/index.php?tab=my');
    record('B4-8a', 'Secretary starts review; Staff cannot forge requirements completion and cannot approve until the checklist is complete',
        $a && $clerkBlocked && $b && $clerkCannotComplete && $c && $reviewStepOk,
        'chairman=' . $chairmanTry['status'] . '; staff-review=' . $clerkReview['status']
        . '; secretary-review=' . $x2['status'] . '; status=' . $status($id1)
        . '; assigned=' . (string) db_val('SELECT assigned_to FROM document_requests WHERE request_id = ?', [$id1])
        . '; forged-requirements=' . (string) db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$id1])
        . '; staff-ready=' . $x3['status']
        . '; review-step=' . ($reviewStepOk ? 'yes' : 'no')
        . '; review-page=' . substr(trim(strip_tags($reviewDetail['body'])), 0, 300));
    record('B4-8c', 'Each role gets a role-labeled work queue containing the right request',
        str_contains($adminQueue['body'], 'Needs Action') && str_contains($adminQueue['body'], (string) $row['reference_no'])
        && str_contains($secQueue['body'], 'Needs Review') && str_contains($capQueue['body'], 'View Only')
        && str_contains($clkQueue['body'], 'Needs Final Decision')
        && str_contains($secQueue['body'], (string) $row['reference_no'])
        && str_contains($capQueue['body'], (string) $row['reference_no'])
        && str_contains($clkQueue['body'], (string) $row['reference_no']));

    // Staff may not discover or change another user's unreviewed request by swapping an ID or tab.
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
    $notComplete = $move($clk, $id1, 'Ready');
    $blockedPage = $clk->get('/requests/view.php?id=' . $id1);
    $blocked = $status($id1) === 'Review' && $notComplete['status'] === 302
        && str_contains($blockedPage['body'], 'requirements review');
    $markedComplete = $completeRequirements($sec, $id1);
    $savedComplete = db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$id1]) === 'Complete';
    $reviewedDetail = $sec->get('/requests/view.php?id=' . $id1);
    record('B4-8e', 'Progress tracker advances to final decision when Secretary completes requirements', str_contains($reviewedDetail['body'], 'Staff final decision') && str_contains($reviewedDetail['body'], 'aria-current="step"'));
    $secQueueAfterReview = $sec->get('/requests/index.php?tab=my');
    $capQueueAfterReview = $cap->get('/requests/index.php?tab=my');
    $staffQueueAfterReview = $clk->get('/requests/index.php?tab=my');
    record('B4-8d', 'Complete requirements removes request from Secretary queue and moves it to final approver queue',
        !str_contains($secQueueAfterReview['body'], (string) $row['reference_no'])
        && str_contains($staffQueueAfterReview['body'], (string) $row['reference_no'])
        && str_contains($capQueueAfterReview['body'], (string) $row['reference_no']));
    $secretaryTry = $move($sec, $id1, 'Ready');
    $secretaryDenied = $secretaryTry['status'] === 403 && $status($id1) === 'Review';
    $x4 = $move($clk, $id1, 'Ready'); $d = $x4['status'] === 302 && $status($id1) === 'Ready';
    $readyDetail = $cap->get('/requests/view.php?id=' . $id1);
    record('B4-8f', 'Approved request shows certificate issuance as the current next step', str_contains($readyDetail['body'], 'Certificate release') && str_contains($readyDetail['body'], 'aria-current="step"'));
    $x5 = $move($cap, $id1, 'Review'); $x6 = $move($sec, $id1, 'Cancelled'); $e = $status($id1) === 'Ready';
    record('B4-8b', 'Incomplete requirements block Staff approval; Secretary is denied final approval; Staff can approve completed requirements; a Ready request cannot move backward or cancel', $invalidRequirements && $blocked && $markedComplete['status'] === 302 && $savedComplete && $secretaryDenied && $d && $e);
    $o = db_one('SELECT recipient_number, message, delivery, trigger_event FROM sms_outbox WHERE request_id = ? ORDER BY sms_id DESC LIMIT 1', [$id1]);
    record('B4-9', 'With SMS provider disabled, approval records a log-only outbox entry: recipient, default text and reference', $o !== null && $o['recipient_number'] === '09170004444' && str_contains((string) $o['message'], (string) $row['reference_no']) && $o['delivery'] === 'Logged only' && (int) db_val('SELECT COUNT(*) FROM sms_outbox') === $outBefore + 1);
    $filed('ZZ Test purpose nophone', $nophone); $id2 = $rid('ZZ Test purpose nophone'); $move($sec, $id2, 'Review'); $completeRequirements($sec, $id2); $move($clk, $id2, 'Ready');
    $o2 = db_one('SELECT recipient_number, delivery FROM sms_outbox WHERE request_id = ?', [$id2]);
    $oldTxt = db_val("SELECT setting_value FROM settings WHERE setting_key = 'sms_ready_text'"); db_exec("UPDATE settings SET setting_value = 'Custom: {{reference_no}} / {{document_name}}' WHERE setting_key = 'sms_ready_text'");
    $filed('ZZ Test purpose custom'); $id3 = $rid('ZZ Test purpose custom'); $move($sec, $id3, 'Review'); $completeRequirements($sec, $id3); $move($clk, $id3, 'Ready');
    $o3 = (string) db_val('SELECT message FROM sms_outbox WHERE request_id = ?', [$id3]); db_exec("UPDATE settings SET setting_value = ? WHERE setting_key = 'sms_ready_text'", [$oldTxt]);
    record('B4-10', 'No phone number: logged as "No number"; a custom SMS text from Settings is used', $o2 !== null && $o2['recipient_number'] === null && $o2['delivery'] === 'No number' && str_starts_with($o3, 'Custom: REQ-') && str_contains($o3, 'Barangay Clearance'));

    $filed('ZZ Test purpose reject', $rita); $id4 = $rid('ZZ Test purpose reject');
    $pendingReject = $move($cap, $id4, 'Rejected', 'Attempt before review');
    $pendingStill = $status($id4) === 'Pending';
    $move($sec, $id4, 'Review');
    $secretaryReject = $move($sec, $id4, 'Rejected', 'Incomplete requirements');
    $secretaryDenied = $secretaryReject['status'] === 403 && $status($id4) === 'Review';
    $incompleteView = $cap->get('/requests/view.php?id=' . $id4);
    $hiddenFinalActions = !str_contains($incompleteView['body'], 'name="to" value="Rejected"')
        && !str_contains($incompleteView['body'], 'name="to" value="Ready"');
    $rejectBeforeComplete = $move($clk, $id4, 'Rejected', 'Incomplete requirements');
    $blockedUntilComplete = $rejectBeforeComplete['status'] === 302 && $status($id4) === 'Review';
    $markComplete = $completeRequirements($sec, $id4);
    $completed = $markComplete['status'] === 302
        && db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$id4]) === 'Complete';
    $noReason = $move($clk, $id4, 'Rejected', ''); $still = $status($id4) === 'Review';
    $finalReason = 'Eligibility criteria not met';
    $withReason = $move($clk, $id4, 'Rejected', $finalReason);
    $rejected = $status($id4) === 'Rejected' && db_val('SELECT status_note FROM document_requests WHERE request_id = ?', [$id4]) === $finalReason;
    $after = $move($clk, $id4, 'Review'); $term = $status($id4) === 'Rejected';
    record('B4-11', 'Final rejection requires completed requirements; Secretary cannot reject; Staff must give a reason; rejected requests cannot be reopened',
        $pendingReject['status'] === 403 && $pendingStill && $secretaryDenied && $hiddenFinalActions
        && $blockedUntilComplete && $completed && $still && $rejected && $term);
    $filed('ZZ Test purpose cancel', $rita); $id5 = $rid('ZZ Test purpose cancel');
    $capCancel = $move($cap, $id5, 'Cancelled'); $clkCancel = $move($clk, $id5, 'Cancelled', 'Resident left');
    record('B4-12', 'Cancel: captain refused (403); clerk allowed', $capCancel['status'] === 403 && $clkCancel['status'] === 302 && $status($id5) === 'Cancelled');

    $t = $sec->get('/requests/index.php?tab=Approved'); $tc = $sec->get('/requests/index.php?tab=Rejected'); $ts = $sec->get('/requests/index.php?q=' . urlencode((string) $row['reference_no']));
    $nReady = (int) db_val("SELECT COUNT(*) FROM document_requests WHERE msit_status = 'Approved'");
    $b413Ok = str_contains($t['body'], 'REQ-')
        && str_contains($t['body'], 'Approved <span class="badge text-bg-light">' . $nReady . '</span>')
        && str_contains($tc['body'], 'ZZTestFour, Rita')
        && str_contains($ts['body'], (string) $row['reference_no'])
        && !str_contains($t['body'], 'ZZ Test purpose cancel');
    record('B4-13', 'Tracker tabs, counts and search match the data', $b413Ok,
        'approved-status=' . $t['status'] . '; approved-count=' . $nReady
        . '; approved-label=' . (str_contains($t['body'], 'Approved <span class="badge text-bg-light">' . $nReady . '</span>') ? 'yes' : 'no')
        . '; closed-status=' . $tc['status'] . '; closed-resident=' . (str_contains($tc['body'], 'ZZTestFour, Rita') ? 'yes' : 'no')
        . '; search-status=' . $ts['status'] . '; search-ref=' . (str_contains($ts['body'], (string) $row['reference_no']) ? 'yes' : 'no')
        . '; canceled-hidden=' . (!str_contains($t['body'], 'ZZ Test purpose cancel') ? 'yes' : 'no'));

    $refs = []; for ($i = 0; $i < 12; $i++) { $filed('ZZ Test purpose bulk ' . $i); $refs[] = (string) db_val('SELECT reference_no FROM document_requests WHERE purpose = ?', ['ZZ Test purpose bulk ' . $i]); }
    record('B4-14', '12 requests in a row all get different reference numbers', count(array_unique($refs)) === 12 && !in_array('', $refs, true));

    $filed('ZZ Test purpose edit'); $ide = $rid('ZZ Test purpose edit');
    $e1 = post_as($clk, '/requests/form.php?id=' . $ide, '/requests/form.php?id=' . $ide, ['resident_id' => (string) $rita, 'type_id' => (string) $typeId, 'purpose' => 'ZZ Test purpose edited', 'assigned_to' => '', 'requirements_status' => 'Not checked', 'requirements_note' => '']);
    $e2 = $clk->get('/requests/form.php?id=' . $id1); $e3 = $cap->get('/requests/form.php?id=' . $ide);
    record('B4-15', 'Editing: clerk can edit a Pending request; a Ready request and a captain are refused (403)', $e1['status'] === 302 && db_val('SELECT purpose FROM document_requests WHERE request_id = ?', [$ide]) === 'ZZ Test purpose edited' && $e2['status'] === 403 && $e3['status'] === 403);

    // Requests under review are protected from intake edits, and material edits invalidate old checklist decisions.
    $filed('ZZ Test purpose checklist lock');
    $idLock = $rid('ZZ Test purpose checklist lock');
    $move($sec, $idLock, 'Review');
    $completeRequirements($sec, $idLock);
    $clerkEditReview = post_as($clk, '/requests/view.php?id=' . $idLock, '/requests/form.php?id=' . $idLock, [
        'resident_id' => (string) $rita, 'type_id' => (string) $typeId, 'purpose' => 'ZZ Test clerk changed review',
        'assigned_to' => '', 'requirements_status' => 'Complete', 'requirements_note' => '',
        'fee_rule_id' => '', 'fee_quantity' => '1',
    ]);
    $clerkEditLink = $clk->get('/requests/view.php?id=' . $idLock);
    $reviewRow = db_one('SELECT resident_id, type_id, purpose, assigned_to, fee_rule_id, fee_quantity FROM document_requests WHERE request_id = ?', [$idLock]);
    $changedDuringReview = post_as($sec, '/requests/view.php?id=' . $idLock, '/requests/form.php?id=' . $idLock, [
        'resident_id' => (string) $reviewRow['resident_id'], 'type_id' => (string) $reviewRow['type_id'],
        'purpose' => 'ZZ Test purpose checklist changed', 'assigned_to' => (string) ($reviewRow['assigned_to'] ?? ''),
        'requirements_status' => 'Complete', 'requirements_note' => '',
        'fee_rule_id' => (string) ($reviewRow['fee_rule_id'] ?? ''), 'fee_quantity' => (string) ($reviewRow['fee_quantity'] ?? 1),
    ]);
    $afterChange = db_one('SELECT purpose, status, requirements_status, requirements_note FROM document_requests WHERE request_id = ?', [$idLock]);
    $blockedStaleDecision = $move($clk, $idLock, 'Ready');
    $staleStillReview = $status($idLock) === 'Review';
    $recomplete = $completeRequirements($sec, $idLock);
    $readyAfterRecheck = $move($clk, $idLock, 'Ready');
    record('B4-20', 'Review-only edits are role-protected and material changes reset requirements before final approval',
        $clerkEditReview['status'] === 403
        && !str_contains($clerkEditLink['body'], 'href="' . e(base_url('requests/form.php?id=' . $idLock)) . '"')
        && $changedDuringReview['status'] === 302
        && $afterChange['purpose'] === 'ZZ Test purpose checklist changed'
        && $afterChange['status'] === 'Review'
        && $afterChange['requirements_status'] === 'Not checked'
        && $afterChange['requirements_note'] === null
        && $blockedStaleDecision['status'] === 302 && $staleStillReview
        && $recomplete['status'] === 302
        && db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$idLock]) === 'Complete'
        && $readyAfterRecheck['status'] === 302 && $status($idLock) === 'Ready');

    $filed('ZZ Test <script>alert(1)</script>'); $idx = $rid('ZZ Test <script>alert(1)</script>');
    $pages = [$sec->get('/requests/view.php?id=' . $idx), $sec->get('/requests/index.php?q=ZZTest4')];
    record('B4-16', 'A script tag in the purpose is escaped (XSS)', !str_contains($pages[0]['body'], '<script>alert(1)</script>') && str_contains($pages[0]['body'], '&lt;script&gt;'));
    $nc = post_as($clk, '/requests/view.php?id=' . $idx, '/requests/action.php', []); // no id
    $odd = [$sec->get('/requests/index.php?tab[]=x&q[]=y&page[]=2'), $sec->get('/requests/view.php?id[]=1'), $sec->get('/requests/view.php?id=99999999'), $sec->get('/requests/form.php?resident[]=1&q[]=3'), $sec->post('/requests/action.php', ['_csrf' => $sec->token('/requests/index.php'), 'id' => ['1'], 'to' => ['Ready'], 'note' => ['x']]), $sec->get('/visitors/index.php?q[]=x&page[]=3&new[]=1'), $sec->get('/requests/index.php?page=-4&q=' . str_repeat('Z', 4000))];
    $bad = []; foreach ($odd as $i => $o) { if ($o['status'] >= 500 || leaks($o)) { $bad[] = $i . ':' . $o['status']; } }
    record('B4-17', 'Array, oversized and unknown-id parameters never crash a request page', !$bad && $nc['status'] === 404, implode(',', $bad));
    $need = ['request_created', 'request_updated', 'request_status_changed', 'request_move_refused', 'request_rejected'];
    $miss = array_filter($need, fn($a) => (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$a]) < 1);
    $rm = (string) db_val("SELECT remarks FROM audit_log WHERE action = 'request_status_changed' AND entity_id = ? ORDER BY audit_id LIMIT 1", [$id1]);
    record('B4-18', 'Every request action is audited, including the old and new status', !$miss && str_contains($rm, 'Pending -> Review'), implode(',', $miss));
    $out = []; $code = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/schema_check.php') . ' 2>&1', $out, $code);
    record('B4-19', 'schema_check.php still agrees with the database (expected_schema.json in sync)', $code === 0, $code === 0 ? '' : implode(' | ', array_slice($out, 0, 3)));

    $testRequestIds = array_map(static fn(array $r): int => (int) $r['request_id'], db_all("SELECT request_id FROM document_requests WHERE purpose LIKE 'ZZ Test%'"));
    if ($testRequestIds) {
        $marks = implode(',', array_fill(0, count($testRequestIds), '?'));
        db_exec('DELETE FROM request_status_history WHERE request_id IN (' . $marks . ')', $testRequestIds);
        db_exec('DELETE FROM sms_outbox WHERE request_id IN (' . $marks . ')', $testRequestIds);
        db_exec('DELETE FROM document_attachments WHERE request_id IN (' . $marks . ')', $testRequestIds);
        db_exec('DELETE FROM document_requests WHERE request_id IN (' . $marks . ')', $testRequestIds);
    }
    db_exec("DELETE FROM visitor_log WHERE visitor_name LIKE 'ZZTest%'");
    db_exec("DELETE FROM residents WHERE last_name LIKE 'ZZTest%'");
    db_exec("DELETE FROM households WHERE household_code LIKE 'ZZTEST%'");
    db_exec("DELETE FROM document_types WHERE name LIKE 'ZZ Test%'");
}
