<?php
/** POST-only resident archive/restore actions. Resident status is edited through the SRS profile form. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$action = post_str('action');
$id = post_int('id');
if (!in_array($action, ['archive','restore'], true) || $id === null) { abort(404, 'Unknown resident action.'); }
require_csrf();
if ($action === 'archive') { require_permission('residents.archive'); }
else { require_permission('residents.restore'); }

$r = db_one('SELECT resident_id, resident_no, last_name, first_name, archived_at FROM residents WHERE resident_id = ?', [$id]);
if ($r === null) { abort(404, 'That resident was not found.'); }

try {
    db_transaction(function () use ($action, $r, $id): void {
        $name = trim((string) ($r['resident_no'] ?? '') . ' ' . $r['last_name'] . ', ' . $r['first_name']);
        if ($action === 'archive') {
            if ($r['archived_at'] !== null) { throw new RuntimeException('already-archived'); }
            db_exec('UPDATE residents SET archived_at = NOW() WHERE resident_id = ? AND archived_at IS NULL', [$id]);
            audit('resident_archived', 'Residents', 'Success', 'Archived: ' . $name, 'resident', $id);
        } else {
            if ($r['archived_at'] === null) { throw new RuntimeException('not-archived'); }
            db_exec('UPDATE residents SET archived_at = NULL WHERE resident_id = ?', [$id]);
            audit('resident_restored', 'Residents', 'Success', 'Restored: ' . $name, 'resident', $id);
        }
    });
} catch (RuntimeException $e) {
    flash_add('warning', $e->getMessage() === 'already-archived'
        ? 'This resident is already archived.'
        : ($e->getMessage() === 'not-archived' ? 'This resident is not archived.' : 'The resident action could not be completed.'));
    redirect('residents/view.php', ['id' => $id]);
} catch (PDOException $e) {
    error_log('BIRMS resident archive database error: ' . $e->getMessage());
    flash_add('danger', 'The resident record could not be updated because of a database error.');
    redirect('residents/view.php', ['id' => $id]);
}
flash_add('success', $action === 'archive' ? 'Resident archived. The record was retained.' : 'Resident restored.');
redirect('residents/view.php', ['id' => $id]);
