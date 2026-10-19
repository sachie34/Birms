<?php
/** POST-only: archive, restore, set residency status. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$action = post_str('action'); $id = post_int('id');
if (!in_array($action, ['archive', 'restore', 'set_active', 'set_relocated'], true) || $id === null) { abort(404, 'Unknown action.'); }
require_csrf();
require_permission(in_array($action, ['archive', 'restore'], true) ? 'residents.archive' : 'residents.edit');
$r = db_one('SELECT resident_id, last_name, first_name, residency_status, archived_at FROM residents WHERE resident_id = ?', [$id]);
if ($r === null) { abort(404, 'That resident was not found.'); }

try {
db_transaction(function () use ($action, $r, $id): void {
    $name = $r['last_name'] . ', ' . $r['first_name'];
    switch ($action) {
        case 'archive':
            db_exec('UPDATE residents SET archived_at = NOW() WHERE resident_id = ? AND archived_at IS NULL', [$id]);
            audit('resident_archived', 'Residents', 'Success', 'Archived: ' . $name, 'resident', $id); break;
        case 'restore':
            db_exec('UPDATE residents SET archived_at = NULL WHERE resident_id = ?', [$id]);
            audit('resident_restored', 'Residents', 'Success', 'Restored: ' . $name, 'resident', $id); break;
        default:
            if ($r['archived_at'] !== null) { throw new RuntimeException('archived'); }
            $new = $action === 'set_active' ? 'Active' : 'Relocated';
            db_exec('UPDATE residents SET residency_status = ? WHERE resident_id = ?', [$new, $id]);
            audit('residency_status_changed', 'Residents', 'Success', $name . ': ' . $r['residency_status'] . ' -> ' . $new, 'resident', $id);
    }
});
} catch (RuntimeException $e) {
    flash_add('warning', 'Restore this archived resident before changing their status.');
    redirect('residents/view.php', ['id' => $id]);
}
flash_add('success', 'Done.');
redirect('residents/view.php', ['id' => $id]);
