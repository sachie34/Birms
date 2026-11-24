<?php
/** POST-only: move a request to its next status. The server decides what is allowed, not the page. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = post_int('id'); $to = post_str('to'); $note = post_str('note');
if ($id === null || !in_array($to, REQUEST_STATUSES, true)) { abort(404, 'Unknown action.'); }
require_csrf();
require_permission('requests.view');
$r = db_one('SELECT dr.request_id, dr.resident_id, dr.reference_no, dr.status, dr.msit_status, dr.requirements_status, dr.assigned_to, dr.created_by, r.email_address, dt.name AS type_name FROM document_requests dr
              JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id WHERE dr.request_id = ?', [$id]);
if ($r === null || !request_visible_to_current_user($r)) { abort(404, 'That request was not found.'); }
// Authorize the requested action before checking whether the current state permits it.
// This prevents a read-only role from probing or attempting staff-only actions through redirects.
$targetPermission = match ($to) {
    'Review' => 'requests.review',
    'Ready', 'Rejected' => 'requests.approve',
    'Cancelled' => 'requests.edit',
    default => null,
};
if ($targetPermission !== null && !can($targetPermission)) {
    audit_best_effort('request_move_refused', 'Requests', 'Failed',
        $r['reference_no'] . ': ' . $r['status'] . ' -> ' . $to . ' requires ' . $targetPermission);
    require_permission($targetPermission);
}
$perm = REQUEST_TRANSITIONS[$r['status']][$to] ?? null;
if ($perm === null) {
    audit_best_effort('request_move_refused', 'Requests', 'Failed', $r['reference_no'] . ': ' . $r['status'] . ' -> ' . $to . ' is not allowed');
    abort(403, 'That request transition is not permitted for this role or current status.');
}
require_permission($perm);
if (in_array($to, ['Ready', 'Rejected'], true) && $r['requirements_status'] !== 'Complete') {
    audit_best_effort('request_move_refused', 'Requests', 'Failed', $r['reference_no'] . ': cannot make a final decision before requirements are marked Complete');
    flash_add('danger', 'Complete the requirements review before approving or rejecting this request. The Secretary or Administrator must check the requirements and record Complete first.');
    redirect('requests/view.php', ['id' => $id]);
}
if ($to === 'Rejected' && !v_len($note, 3, 255)) { flash_add('danger', 'Give a reason (3-255 characters) when rejecting.'); redirect('requests/view.php', ['id' => $id]); }
if (str_len($note) > 255) { flash_add('danger', 'The note is too long.'); redirect('requests/view.php', ['id' => $id]); }

$newSrsStatus = 'Pending';
try {
    $done = db_transaction(function () use ($r, $to, $note, $id, &$newSrsStatus): bool {
    $assign = ($to === 'Review' && $r['assigned_to'] === null) ? (audit_actor()['user_id'] ?? null) : $r['assigned_to'];
    $newSrsStatus = match ($to) {
        'Pending', 'Review' => 'Pending',
        'Ready' => 'Approved',
        'Claimed' => 'Released',
        'Rejected', 'Cancelled' => 'Rejected',
        default => 'Pending',
    };
    $srsStatus = $newSrsStatus;
    $sql = 'UPDATE document_requests SET status = ?, msit_status = ?, status_note = ?, assigned_to = ?';
    // Intake cannot pre-complete requirements: starting formal review resets the checklist.
    if ($to === 'Review') { $sql .= ", requirements_status = 'Not checked', requirements_note = NULL"; }
    $sql .= ' WHERE request_id = ? AND status = ?';
    // Re-check requirements in the UPDATE itself so a concurrent requirements change cannot race a final decision.
    if (in_array($to, ['Ready', 'Rejected'], true)) { $sql .= " AND requirements_status = 'Complete'"; }
    $n = db_exec($sql, [$to, $srsStatus, $note !== '' ? $note : null, $assign, $id, $r['status']]);
    if ($n !== 1) { return false; }      // someone else moved it first
    // FR-D5: generate and persist the certificate draft in the same transaction as approval.
    // Any missing template, fee or required Chairman signature rolls the approval back.
    if ($to === 'Ready') {
        certificate_draft_create($id);
    }
    audit('request_status_changed', 'Requests', 'Success', $r['reference_no'] . ': ' . $r['status'] . ' -> ' . $to . ($note !== '' ? ' (' . $note . ')' : ''), 'request', $id);
    db_exec("INSERT INTO request_status_history
        (service, reference_no, previous_status, new_status, changed_by, note, request_id)
        VALUES ('Documents', ?, ?, ?, ?, ?, ?)",
        [$r['reference_no'], (string) ($r['msit_status'] ?? 'Pending'), $srsStatus, audit_actor()['user_id'] ?? null,
         'Internal workflow step: ' . $r['status'] . ' -> ' . $to . ($note !== '' ? ' — ' . $note : ''), $id]);
    return true;
    });
} catch (InvalidArgumentException $approvalError) {
    flash_add('danger', $approvalError->getMessage());
    redirect('requests/view.php', ['id' => $id]);
} catch (Throwable $approvalError) {
    error_log('BIRMS request approval transaction failed for ' . (string) $r['reference_no'] . ': ' . get_class($approvalError) . ': ' . $approvalError->getMessage());
    flash_add('danger', 'The request could not be approved and its certificate draft was not saved. No approval was committed. Check the application error log or contact Admin.');
    redirect('requests/view.php', ['id' => $id]);
}

// Notify the resident only when the public SRS status changed. Notification/provider failures
// do not reverse the already-committed request transition.
if (!$done) {
    flash_add('warning', 'Someone else changed this request first. Check its current status.');
} else {
    $flashType = 'success';
    $flashMessage = 'Status updated to ' . $to . '.';
    if ($newSrsStatus !== (string) ($r['msit_status'] ?? 'Pending')) {
        try {
            $notice = resident_notify_status_change(
                (int) $r['resident_id'],
                'Documents',
                (string) $r['reference_no'],
                $newSrsStatus,
                'document_request_status_changed',
                $note
            );
            if ($notice['email'] === 'queued') {
                $flashMessage .= ' An email notice is queued for the resident.';
            } elseif ($notice['email'] === 'sent') {
                $flashMessage .= ' The email notice was accepted by the configured mail transport.';
            }
        } catch (Throwable $notificationError) {
            error_log('BIRMS document status notification failed for ' . (string) $r['reference_no'] . ': ' . get_class($notificationError) . ': ' . $notificationError->getMessage());
            audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                'Document request ' . (string) $r['reference_no'] . ' changed status but notification could not be queued.');
            $flashType = 'warning';
            $flashMessage .= ' The status was saved, but the resident notification could not be queued. Check the notification log.';
        }
    }
    flash_add($flashType, $flashMessage);
}
redirect('requests/view.php', ['id' => $id]);
