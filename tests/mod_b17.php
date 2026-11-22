<?php
/** B17 end-to-end tests for administrator backup restore and its safeguards. */
declare(strict_types=1);
require_once __DIR__ . '/../app/backup_lib.php';

function run_b17(array $S, string $base): void
{
    $admin = $S['admin'];
    $chairman = $S['captain'];
    $databaseName = (string) config('db.name');
    $backupPath = tempnam(sys_get_temp_dir(), 'birms-b17-backup-');
    $badPath = tempnam(sys_get_temp_dir(), 'birms-b17-bad-');
    $probeKey = 'zz_b17_restore_probe';
    $valid = false;
    $restored = false;
    $staleBackupPath = null;
    $staleProbeKey = 'zz_b17_stale_restore_probe';

    try {
        db_exec('DELETE FROM settings WHERE setting_key = ?', [$probeKey]);

        // A Chairman may view records, but backup/restore is an Admin-only data-management action.
        $chairmanPage = $chairman->get('/admin/backup.php');
        $adminPage = $admin->get('/admin/backup.php');
        record('B17-01', 'Only Admin can open the backup and restore screen',
            $chairmanPage['status'] === 403 && $adminPage['status'] === 200
            && str_contains($adminPage['body'], 'Restore a BIRMS SQL backup'));

        // Make a real backup using the same exporter as the Download SQL Backup button.
        $out = fopen($backupPath, 'wb');
        if ($out === false) { throw new RuntimeException('Could not open B17 backup fixture.'); }
        try {
            backup_write_sql_export($out, backup_table_names());
        } finally {
            fclose($out);
        }
        $sql = file_get_contents($backupPath);
        if (!is_string($sql) || $sql === '') { throw new RuntimeException('B17 backup fixture is empty.'); }
        $parsed = backup_restore_parse($sql, backup_table_names());
        $valid = count($parsed['tables']) === count(backup_table_names())
            && count($parsed['inserts']) > 0;
        record('B17-02', 'BIRMS backup preflight accepts its own complete database export and validates every current table',
            $valid, 'tables=' . count($parsed['tables']) . '; insert statements=' . count($parsed['inserts']));

        // This row is deliberately added after the backup. A successful restore must remove it.
        db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$probeKey, 'must disappear after restore']);
        $noCsrf = $admin->post('/admin/backup.php', [
            'do' => 'restore', 'confirm_database' => $databaseName,
        ]);
        $mismatch = $admin->upload('/admin/backup.php', [
            '_csrf' => $admin->token('/admin/backup.php'),
            'do' => 'restore',
            'confirm_database' => $databaseName . '_wrong',
        ], 'backup_file', $backupPath, 'birms-b17.sql', 'application/sql');
        $probeAfterMismatch = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$probeKey]);

        // The real restore must use CSRF and an exact typed database-name confirmation.
        $restore = $admin->upload('/admin/backup.php', [
            '_csrf' => $admin->token('/admin/backup.php'),
            'do' => 'restore',
            'confirm_database' => $databaseName,
        ], 'backup_file', $backupPath, 'birms-b17.sql', 'application/sql');
        $probeAfterRestore = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$probeKey]);
        $restoreAudit = (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'database_backup_restored'");
        $restored = $restore['status'] === 302 && $probeAfterRestore === 0 && $restoreAudit >= 1;

        record('B17-03', 'Restore requires CSRF and the exact database name; valid backup restores the saved rows and records an audit event',
            $noCsrf['status'] === 403
            && $mismatch['status'] === 302
            && $probeAfterMismatch === 1
            && $restored,
            'no-csrf=' . $noCsrf['status'] . '; wrong-db=' . $mismatch['status']
            . '; probe-after-wrong-db=' . $probeAfterMismatch
            . '; restore=' . $restore['status'] . '; probe-after-restore=' . $probeAfterRestore
            . '; restore-audit=' . $restoreAudit);

        // Arbitrary SQL must be refused before any database table is modified.
        file_put_contents($badPath, "-- BIRMS SQL backup\nDROP DATABASE `" . $databaseName . "`;\n");
        $badUpload = $admin->upload('/admin/backup.php', [
            '_csrf' => $admin->token('/admin/backup.php'),
            'do' => 'restore',
            'confirm_database' => $databaseName,
        ], 'backup_file', $badPath, 'not-a-birms-backup.sql', 'application/sql');
        record('B17-04', 'A non-BIRMS/arbitrary SQL upload is rejected before the restore process begins',
            $badUpload['status'] === 302
            && (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$probeKey]) === 0
            && (int) db_val('SELECT COUNT(*) FROM users WHERE username = ?', ['zz_test_admin']) === 1);

        // An older backup must not erase an official blotter case created after that snapshot.
        $staleBackupPath = tempnam(sys_get_temp_dir(), 'birms-b17-stale-');
        if ($staleBackupPath === false) { throw new RuntimeException('Could not create B17 stale-backup fixture.'); }
        $staleOut = fopen($staleBackupPath, 'wb');
        if ($staleOut === false) { throw new RuntimeException('Could not open B17 stale-backup fixture.'); }
        try {
            backup_write_sql_export($staleOut, backup_table_names());
        } finally {
            fclose($staleOut);
        }
        $staleSql = file_get_contents($staleBackupPath);
        if (!is_string($staleSql) || $staleSql === '') { throw new RuntimeException('B17 stale-backup fixture is empty.'); }

        db_exec('DELETE FROM settings WHERE setting_key = ?', [$staleProbeKey]);
        db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$staleProbeKey, 'must survive stale restore']);
        $newCaseNo = 'B17-' . strtoupper(bin2hex(random_bytes(4)));
        $rollbackMarker = 'B17_EXPECTED_STALE_RESTORE_ROLLBACK';
        $staleRestoreBlocked = false;
        try {
            db_transaction(function () use ($staleSql, $newCaseNo, $rollbackMarker, &$staleRestoreBlocked): void {
                db_exec(
                    "INSERT INTO blotters (case_no, incident_date, narrative, recorded_at) VALUES (?, CURDATE(), ?, NOW())",
                    [$newCaseNo, 'B17 stale restore guard fixture']
                );
                try {
                    backup_restore_apply($staleSql);
                } catch (RuntimeException $e) {
                    if (!str_contains($e->getMessage(), 'does not contain permanent blotter case')) { throw $e; }
                    $staleRestoreBlocked = true;
                    // The outer transaction rolls back this synthetic case after the guard is verified.
                    throw new RuntimeException($rollbackMarker);
                }
                throw new RuntimeException('B17 stale restore was unexpectedly accepted.');
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== $rollbackMarker) { throw $e; }
        }
        $newCaseCount = (int) db_val('SELECT COUNT(*) FROM blotters WHERE case_no = ?', [$newCaseNo]);
        $staleProbeCount = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$staleProbeKey]);
        record('B17-05', 'Restore refuses a stale snapshot that would erase a newer permanent blotter case and leaves other current data unchanged',
            $staleRestoreBlocked && $newCaseCount === 0 && $staleProbeCount === 1,
            'guard-blocked=' . ($staleRestoreBlocked ? 'yes' : 'no')
            . '; rolled-back-test-case=' . $newCaseCount
            . '; preserved-post-snapshot-setting=' . $staleProbeCount);
    } finally {
        @unlink($backupPath);
        @unlink($badPath);
        if (is_string($staleBackupPath)) { @unlink($staleBackupPath); }
        db_exec('DELETE FROM settings WHERE setting_key IN (?, ?)', [$probeKey, $staleProbeKey]);
    }
}
