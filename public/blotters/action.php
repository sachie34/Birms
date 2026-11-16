<?php
/** POST-only SRS case-status action. Legacy Pending/Active/Scheduled actions are retired. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
if (!is_post()) { abort(405, 'Blotter actions must use POST.'); }
require_csrf();
require_permission('blotters.view');

$id = post_int('id');
$do = post_str('do');
$to = post_str('to');
$note = post_str('note');
if ($id === null || $id < 1 || $do !== 'srs_move') {
    abort(404, 'Unknown blotter action.');
}

$case = db_one('SELECT blotter_id, case_no, msit_status FROM blotters WHERE blotter_id = ?', [$id]);
if ($case === null) { abort(404, 'That case was not found.'); }

$permission = MSIT_BLOTTER_MOVES[(string) $case['msit_status']][$to] ?? null;
if ($permission === null) {
    audit_best_effort('blotter_action_refused', 'Blotter', 'Failed',
        (string) $case['case_no'] . ': invalid transition to ' . ($to !== '' ? $to : '(empty)'));
    flash_add('danger', 'That case cannot be moved to the selected status.');
    redirect('blotters/view.php', ['id' => $id]);
}
require_permission($permission);

try {
    blotter_srs_change_status($id, $to, $note);
    flash_add('success', 'Case status updated to ' . $to . '.');
} catch (DomainException $e) {
    abort(403, $e->getMessage());
} catch (InvalidArgumentException | RuntimeException $e) {
    audit_best_effort('blotter_action_refused', 'Blotter', 'Failed',
        (string) $case['case_no'] . ': ' . $e->getMessage());
    flash_add('danger', $e->getMessage());
} catch (Throwable $e) {
    error_log('BIRMS blotter status update failed: ' . get_class($e) . ': ' . $e->getMessage());
    flash_add('danger', 'The case status could not be saved. Refresh and try again.');
}
redirect('blotters/view.php', ['id' => $id]);
