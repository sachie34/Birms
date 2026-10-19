<?php
/** POST-only: move a request to its next status. The server decides what is allowed, not the page. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = post_int('id'); $to = post_str('to'); $note = post_str('note');
if ($id === null || !in_array($to, REQUEST_STATUSES, true)) { abort(404, 'Unknown action.'); }
require_csrf();
require_permission('requests.view');
$r = db_one('SELECT dr.request_id, dr.reference_no, dr.status, dr.assigned_to, r.contact_number, dt.name AS type_name FROM document_requests dr
              JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id WHERE dr.request_id = ?', [$id]);
if ($r === null) { abort(404, 'That request was not found.'); }
$perm = REQUEST_TRANSITIONS[$r['status']][$to] ?? null;
if ($perm === null) {
    audit_best_effort('request_move_refused', 'Requests', 'Failed', $r['reference_no'] . ': ' . $r['status'] . ' -> ' . $to . ' is not allowed');
    flash_add('danger', 'That step is not allowed from the current status.');
    redirect('requests/view.php', ['id' => $id]);
}
require_permission($perm);
if ($to === 'Rejected' && !v_len($note, 3, 255)) { flash_add('danger', 'Give a reason (3-255 characters) when rejecting.'); redirect('requests/view.php', ['id' => $id]); }
if (str_len($note) > 255) { flash_add('danger', 'The note is too long.'); redirect('requests/view.php', ['id' => $id]); }

$done = db_transaction(function () use ($r, $to, $note, $id): bool {
    $assign = ($to === 'Review' && $r['assigned_to'] === null) ? (audit_actor()['user_id'] ?? null) : $r['assigned_to'];
    $n = db_exec('UPDATE document_requests SET status = ?, status_note = ?, assigned_to = ? WHERE request_id = ? AND status = ?', [$to, $note !== '' ? $note : null, $assign, $id, $r['status']]);
    if ($n !== 1) { return false; }      // someone else moved it first
    audit('request_status_changed', 'Requests', 'Success', $r['reference_no'] . ': ' . $r['status'] . ' -> ' . $to . ($note !== '' ? ' (' . $note . ')' : ''), 'request', $id);
    if ($to === 'Ready') { sms_log_ready($r); }
    return true;
});
flash_add($done ? 'success' : 'warning', $done ? 'Status updated to ' . $to . '.' : 'Someone else changed this request first. Check its current status.');
redirect('requests/view.php', ['id' => $id]);
