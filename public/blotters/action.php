<?php
/** POST only: move a case to its next status, archive a resolved case, or restore it. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_csrf();
require_permission('blotters.view');
$id = post_int('id'); $do = post_str('do');
if ($id === null || !in_array($do, ['move', 'archive', 'restore'], true)) { abort(404, 'Unknown action.'); }
$c = db_one('SELECT * FROM blotters WHERE blotter_id = ?', [$id]);
if ($c === null) { abort(404, 'That case was not found.'); }
$back = fn() => redirect('blotters/view.php', ['id' => $id]);
$refuse = function (string $why) use ($c, $do, $back): never {
    audit_best_effort('blotter_action_refused', 'Blotter', 'Failed', $c['case_no'] . ' ' . $do . ': ' . $why);
    flash_add('danger', $why); $back();
};

if ($do === 'move') {
    $to = post_str('to'); $note = post_str('note');
    if ($c['archived_at'] !== null) { $refuse('Archived cases cannot change status.'); }
    $perm = BLOTTER_MOVES[(string) $c['status']][$to] ?? null;
    if ($perm === null) { $refuse('A ' . $c['status'] . ' case cannot be moved to ' . ($to === '' ? 'that status' : $to) . '.'); }
    require_permission($perm);
    if ($to === 'Resolved') {
        if (!v_len($note, 3, 500)) { $refuse('Say how the case was resolved (3-500 characters).'); }
        if ((int) db_val("SELECT COUNT(*) FROM hearings WHERE blotter_id = ? AND status = 'Scheduled'", [$id]) > 0) { $refuse('Record the outcome of the scheduled hearing first (Held, Postponed or Cancelled), then resolve the case.'); }
    }
    $ok = db_transaction(function () use ($c, $to, $note): bool {
        $n = db_exec("UPDATE blotters SET status = ?, resolution_note = ?, resolved_at = " . ($to === 'Resolved' ? 'NOW()' : 'NULL') . " WHERE blotter_id = ? AND status = ? AND archived_at IS NULL",
            [$to, $to === 'Resolved' ? $note : null, $c['blotter_id'], $c['status']]);
        if ($n !== 1) { return false; }
        audit('blotter_status_changed', 'Blotter', 'Success', $c['case_no'] . ': ' . $c['status'] . ' -> ' . $to . ($to === 'Resolved' ? ' (' . $note . ')' : ''), 'blotter', (int) $c['blotter_id']);
        return true;
    });
    if (!$ok) { $refuse('This case was changed by someone else. Reload and try again.'); }
    flash_add('success', 'Case is now ' . $to . '.'); $back();
}

require_permission('blotters.archive');
if ($do === 'archive') {
    if ($c['status'] !== 'Resolved' || $c['archived_at'] !== null) { $refuse('Only a resolved case can be archived.'); }
    db_transaction(function () use ($c): void {
        db_exec('UPDATE blotters SET archived_at = NOW() WHERE blotter_id = ? AND archived_at IS NULL', [$c['blotter_id']]);
        audit('blotter_archived', 'Blotter', 'Success', $c['case_no'] . ' archived', 'blotter', (int) $c['blotter_id']);
    });
    flash_add('success', 'Case archived.'); $back();
}
if ($c['archived_at'] === null) { $refuse('This case is not archived.'); }
db_transaction(function () use ($c): void {
    db_exec('UPDATE blotters SET archived_at = NULL WHERE blotter_id = ? AND archived_at IS NOT NULL', [$c['blotter_id']]);
    audit('blotter_restored', 'Blotter', 'Success', $c['case_no'] . ' restored', 'blotter', (int) $c['blotter_id']);
});
flash_add('success', 'Case restored.'); $back();
