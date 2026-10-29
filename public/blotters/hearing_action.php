<?php
/** POST only: schedule a hearing, or record its outcome (Held, Postponed, Cancelled). */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_csrf();
$do = post_str('do');
if (!in_array($do, ['schedule', 'outcome'], true)) { abort(404, 'Unknown action.'); }
$fail = function (string $why, int $caseId, string $caseNo) use ($do): never {
    audit_best_effort('hearing_refused', 'Blotter', 'Failed', $caseNo . ' ' . $do . ': ' . $why);
    flash_add('danger', $why); redirect('blotters/view.php', ['id' => $caseId]);
};
/** After an outcome: if no hearing is left open, a Scheduled case goes back to Active. */
$sync = function (array $c): ?string {
    if ($c['status'] === 'Scheduled' && (int) db_val("SELECT COUNT(*) FROM hearings WHERE blotter_id = ? AND status = 'Scheduled'", [$c['blotter_id']]) === 0) {
        db_exec("UPDATE blotters SET status = 'Active' WHERE blotter_id = ? AND status = 'Scheduled'", [$c['blotter_id']]);
        return ' Case returned to Active.';
    }
    return null;
};

if ($do === 'schedule') {
    require_permission('hearings.add');
    $id = post_int('id'); $c = $id === null ? null : db_one('SELECT * FROM blotters WHERE blotter_id = ?', [$id]);
    if ($c === null) { abort(404, 'That case was not found.'); }
    if (!case_is_open($c)) { $fail('Hearings can only be scheduled for a case that is not resolved or archived.', (int) $id, (string) $c['case_no']); }
    $at = v_datetime_local(post_str('scheduled_at')); $venue = post_str('venue'); $notes = post_str('notes'); $errs = [];
    if ($at === null) { $errs[] = 'Enter a valid date and time.'; }
    elseif (strtotime($at) <= time()) { $errs[] = 'The hearing must be in the future.'; }
    elseif (strtotime($at) > strtotime('+1 year')) { $errs[] = 'The hearing cannot be more than a year ahead.'; }
    if ($venue !== '' && !v_len($venue, 3, 150)) { $errs[] = 'The venue must be 3-150 characters.'; }
    if ($notes !== '' && !v_len($notes, 3, 500)) { $errs[] = 'Notes must be 3-500 characters.'; }
    if (!$errs && $at !== null && db_val("SELECT COUNT(*) FROM hearings WHERE blotter_id = ? AND status = 'Scheduled' AND scheduled_at = ?", [$id, $at])) { $errs[] = 'A hearing is already scheduled for that time.'; }
    if ($errs) { $fail(implode(' ', $errs), (int) $id, (string) $c['case_no']); }
    try {
        db_transaction(function () use ($id, $at, $venue, $notes): void {
            // Lock the parent case so duplicate checks and inserts serialize for this case.
            $lockedCase = db_one('SELECT blotter_id, case_no, status, archived_at FROM blotters WHERE blotter_id = ? FOR UPDATE', [$id]);
            if ($lockedCase === null || !case_is_open($lockedCase)) { throw new RuntimeException('case-closed'); }
            if (db_val("SELECT COUNT(*) FROM hearings WHERE blotter_id = ? AND status = 'Scheduled' AND scheduled_at = ?", [$id, $at])) {
                throw new RuntimeException('duplicate-hearing');
            }
            db_exec('INSERT INTO hearings (blotter_id, scheduled_at, venue, notes, created_by) VALUES (?, ?, ?, ?, ?)',
                [$lockedCase['blotter_id'], $at, $venue !== '' ? $venue : null, $notes !== '' ? $notes : null, audit_actor()['user_id'] ?? null]);
            db_exec("UPDATE blotters SET status = 'Scheduled' WHERE blotter_id = ? AND status IN ('Pending', 'Active')", [$lockedCase['blotter_id']]);
            audit('hearing_scheduled', 'Blotter', 'Success', $lockedCase['case_no'] . ': hearing on ' . date('j M Y g:i A', strtotime((string) $at)) . ($venue !== '' ? ' at ' . $venue : ''), 'blotter', (int) $lockedCase['blotter_id']);
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'duplicate-hearing') {
            $fail('A hearing is already scheduled for that time. Refresh the case and try another time.', (int) $id, (string) $c['case_no']);
        }
        if ($e->getMessage() === 'case-closed') {
            $fail('Hearings can only be scheduled for a case that is not resolved or archived.', (int) $id, (string) $c['case_no']);
        }
        throw $e;
    } catch (PDOException $e) {
        // Database uniqueness is the final defense for simultaneous requests, including clients
        // that do not use this application-level case lock.
        if ($e->getCode() === '23000') {
            $fail('A hearing is already scheduled for that case and time. Refresh the case before retrying.', (int) $id, (string) $c['case_no']);
        }
        throw $e;
    }
    flash_add('success', 'Hearing scheduled.'); redirect('blotters/view.php', ['id' => (int) $id]);
}

require_permission('hearings.edit');
$hid = post_int('hearing'); $h = $hid === null ? null : db_one('SELECT * FROM hearings WHERE hearing_id = ?', [$hid]);
if ($h === null) { abort(404, 'That hearing was not found.'); }
$c = db_one('SELECT * FROM blotters WHERE blotter_id = ?', [$h['blotter_id']]);
$out = post_str('outcome'); $reason = post_str('reason');
if ($h['status'] !== 'Scheduled') { $fail('This hearing already has an outcome (' . $h['status'] . ') and cannot be changed.', (int) $c['blotter_id'], (string) $c['case_no']); }
if (!in_array($out, HEARING_OUTCOMES, true)) { $fail('Choose Held, Postponed or Cancelled.', (int) $c['blotter_id'], (string) $c['case_no']); }
if ($out !== 'Held' && !v_len($reason, 3, 255)) { $fail('Give a reason for ' . $out . ' (3-255 characters).', (int) $c['blotter_id'], (string) $c['case_no']); }
if ($reason !== '' && !v_len($reason, 3, 255)) { $fail('The reason must be 3-255 characters.', (int) $c['blotter_id'], (string) $c['case_no']); }
if ($out === 'Held' && !db_val('SELECT scheduled_at <= NOW() FROM hearings WHERE hearing_id = ?', [$hid])) { $fail('This hearing is still in the future. It can be marked Held once its time has come.', (int) $c['blotter_id'], (string) $c['case_no']); }
$msg = db_transaction(function () use ($h, $c, $out, $reason, $sync): ?string {
    $n = db_exec("UPDATE hearings SET status = ?, status_note = ? WHERE hearing_id = ? AND status = 'Scheduled'", [$out, $reason !== '' ? $reason : null, $h['hearing_id']]);
    if ($n !== 1) { return null; }
    $extra = $sync($c);
    audit('hearing_outcome', 'Blotter', 'Success', $c['case_no'] . ': hearing of ' . date('j M Y g:i A', strtotime((string) $h['scheduled_at'])) . ' -> ' . $out . ($reason !== '' ? ' (' . $reason . ')' : '') . ($extra ?? ''), 'blotter', (int) $c['blotter_id']);
    return 'Hearing recorded as ' . $out . '.' . ($extra ?? '');
});
if ($msg === null) { $fail('This hearing was changed by someone else. Reload and try again.', (int) $c['blotter_id'], (string) $c['case_no']); }
flash_add('success', $msg); redirect('blotters/view.php', ['id' => (int) $c['blotter_id']]);
