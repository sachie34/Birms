<?php
/** POST-only hearing scheduler. Hearing lifecycle never changes the five SRS blotter case statuses. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
if (!is_post()) { abort(405, 'Hearing actions must use POST.'); }
require_csrf();

$do = post_str('do');
if (!in_array($do, ['schedule', 'outcome'], true)) { abort(404, 'Unknown hearing action.'); }

$fail = function (string $why, ?int $caseId, string $caseNo = '') use ($do): never {
    audit_best_effort('hearing_refused', 'Blotter', 'Failed',
        ($caseNo !== '' ? $caseNo : 'Unknown case') . ' ' . $do . ': ' . $why);
    flash_add('danger', $why);
    if ($caseId !== null && $caseId > 0) {
        redirect('blotters/view.php', ['id' => $caseId]);
    }
    redirect('blotters/hearings.php');
};

if ($do === 'schedule') {
    require_permission('hearings.add');
    $id = post_int('id');
    $case = $id === null ? null : db_one('SELECT blotter_id, case_no, msit_status, archived_at FROM blotters WHERE blotter_id = ?', [$id]);
    if ($case === null) { abort(404, 'That case was not found.'); }
    if (!case_is_open($case)) {
        $fail('Hearings can only be scheduled for an Ongoing or Unresolved case.', (int) $id, (string) $case['case_no']);
    }

    $at = v_datetime_local(post_str('scheduled_at'));
    $venue = post_str('venue');
    $notes = post_str('notes');
    $errors = [];
    if ($at === null) {
        $errors[] = 'Enter a valid date and time.';
    } elseif (strtotime($at) <= time()) {
        $errors[] = 'The hearing must be in the future.';
    } elseif (strtotime($at) > strtotime('+1 year')) {
        $errors[] = 'The hearing cannot be more than a year ahead.';
    }
    if ($venue !== '' && !v_len($venue, 3, 150)) { $errors[] = 'The venue must be 3-150 characters.'; }
    if ($notes !== '' && !v_len($notes, 3, 500)) { $errors[] = 'Notes must be 3-500 characters.'; }
    if ($errors) { $fail(implode(' ', $errors), (int) $id, (string) $case['case_no']); }

    $result = db_transaction(function () use ($id, $at, $venue, $notes): ?string {
        // Lock the case first. Status changes use the same lock order, preventing a hearing
        // from being added concurrently with a final status change.
        $lockedCase = db_one(
            'SELECT blotter_id, case_no, msit_status, archived_at FROM blotters WHERE blotter_id = ? FOR UPDATE',
            [$id]
        );
        if ($lockedCase === null) { return 'That case was not found.'; }
        if (!case_is_open($lockedCase)) { return 'The case is no longer Ongoing or Unresolved.'; }
        $duplicate = (int) db_val(
            "SELECT COUNT(*) FROM hearings WHERE blotter_id = ? AND status = 'Scheduled' AND scheduled_at = ?",
            [$id, $at]
        );
        if ($duplicate > 0) { return 'A hearing is already scheduled for that time.'; }

        db_exec(
            'INSERT INTO hearings (blotter_id, scheduled_at, venue, notes, created_by) VALUES (?, ?, ?, ?, ?)',
            [$id, $at, $venue !== '' ? $venue : null, $notes !== '' ? $notes : null, audit_actor()['user_id'] ?? null]
        );
        audit('hearing_scheduled', 'Blotter', 'Success',
            $lockedCase['case_no'] . ': hearing on ' . date('j M Y g:i A', strtotime((string) $at))
            . ($venue !== '' ? ' at ' . $venue : ''),
            'blotter', (int) $id);
        return null;
    });
    if ($result !== null) { $fail($result, (int) $id, (string) $case['case_no']); }
    flash_add('success', 'Hearing scheduled. The case status remains ' . (string) $case['msit_status'] . '.');
    redirect('blotters/view.php', ['id' => (int) $id]);
}

require_permission('hearings.edit');
$hearingId = post_int('hearing');
$hearing = $hearingId === null ? null : db_one('SELECT * FROM hearings WHERE hearing_id = ?', [$hearingId]);
if ($hearing === null) { abort(404, 'That hearing was not found.'); }
$case = db_one(
    'SELECT blotter_id, case_no, msit_status, archived_at FROM blotters WHERE blotter_id = ?',
    [$hearing['blotter_id']]
);
if ($case === null) { abort(404, 'The case for this hearing was not found.'); }

$outcome = post_str('outcome');
$reason = post_str('reason');
if (!in_array($outcome, HEARING_OUTCOMES, true)) {
    $fail('Choose Held, Postponed or Cancelled.', (int) $case['blotter_id'], (string) $case['case_no']);
}
if ($outcome !== 'Held' && !v_len($reason, 3, 255)) {
    $fail('Give a reason for ' . $outcome . ' (3-255 characters).', (int) $case['blotter_id'], (string) $case['case_no']);
}
if ($reason !== '' && !v_len($reason, 3, 255)) {
    $fail('The reason must be 3-255 characters.', (int) $case['blotter_id'], (string) $case['case_no']);
}

$result = db_transaction(function () use ($hearingId, $case, $outcome, $reason): array {
    // Keep the same case-then-hearing lock order as scheduling and case-status updates.
    $lockedCase = db_one(
        'SELECT blotter_id, case_no, msit_status, archived_at FROM blotters WHERE blotter_id = ? FOR UPDATE',
        [$case['blotter_id']]
    );
    $lockedHearing = db_one('SELECT * FROM hearings WHERE hearing_id = ? FOR UPDATE', [$hearingId]);
    if ($lockedCase === null || $lockedHearing === null) {
        return ['error' => 'The case or hearing no longer exists.'];
    }
    if ($lockedHearing['status'] !== 'Scheduled') {
        return ['error' => 'This hearing already has an outcome (' . $lockedHearing['status'] . ') and cannot be changed.'];
    }
    if (!case_is_open($lockedCase)) {
        return ['error' => 'A hearing outcome cannot be recorded after the case is Resolved, Dismissed or Archived.'];
    }
    if ($outcome === 'Held' && (int) db_val('SELECT scheduled_at <= NOW() FROM hearings WHERE hearing_id = ?', [$hearingId]) !== 1) {
        return ['error' => 'This hearing is still in the future. It can be marked Held once its time has come.'];
    }

    $changed = db_exec(
        "UPDATE hearings SET status = ?, status_note = ? WHERE hearing_id = ? AND status = 'Scheduled'",
        [$outcome, $reason !== '' ? $reason : null, $hearingId]
    );
    if ($changed !== 1) {
        return ['error' => 'This hearing was changed by someone else. Refresh and try again.'];
    }
    audit('hearing_outcome', 'Blotter', 'Success',
        $lockedCase['case_no'] . ': hearing of ' . date('j M Y g:i A', strtotime((string) $lockedHearing['scheduled_at']))
        . ' -> ' . $outcome . ($reason !== '' ? ' (' . $reason . ')' : ''),
        'blotter', (int) $lockedCase['blotter_id']);
    return ['message' => 'Hearing recorded as ' . $outcome . '. Case status remains ' . (string) $lockedCase['msit_status'] . '.'];
});

if (isset($result['error'])) {
    $fail((string) $result['error'], (int) $case['blotter_id'], (string) $case['case_no']);
}
flash_add('success', (string) $result['message']);
redirect('blotters/view.php', ['id' => (int) $case['blotter_id']]);
