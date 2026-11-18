<?php
/** Administrator-only database export with optional HTTPS WebDAV cloud upload. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/backup_lib.php';

require_login();
require_permission('backup.run');
$cloudConfigured = backup_webdav_is_configured();

if (is_post()) {
    require_csrf();
    $action = post_str('do');
    if (!in_array($action, ['download', 'cloud_upload', 'restore'], true)) { abort(400, 'Unknown backup action.'); }

    if ($action === 'restore') {
        $databaseName = (string) config('db.name');
        if (post_str('confirm_database') !== $databaseName) {
            flash_add('danger', 'The confirmation text did not match the configured database name. No data was changed.');
            redirect('admin/backup.php');
        }
        $upload = $_FILES['backup_file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))
            || (int) ($upload['size'] ?? 0) < 32
            || (int) ($upload['size'] ?? 0) > BACKUP_RESTORE_MAX_BYTES) {
            flash_add('danger', 'Choose a valid BIRMS SQL backup no larger than 100 MiB. Check the PHP upload limit if the file is rejected.');
            redirect('admin/backup.php');
        }
        $sql = file_get_contents((string) $upload['tmp_name']);
        if (!is_string($sql) || $sql === '') {
            flash_add('danger', 'The uploaded SQL backup could not be read. No data was changed.');
            redirect('admin/backup.php');
        }

        try {
            // Validate the complete upload against the current schema before changing any data.
            backup_restore_parse($sql, backup_table_names());

            // Keep a same-request safety snapshot so an unexpected failure can recover current data.
            $safetyPath = tempnam(sys_get_temp_dir(), 'birms-before-restore-');
            if ($safetyPath === false) { throw new RuntimeException('A pre-restore safety snapshot could not be created.'); }
            $safetyStream = fopen($safetyPath, 'wb');
            if ($safetyStream === false) { @unlink($safetyPath); throw new RuntimeException('The pre-restore safety snapshot could not be opened.'); }
            try {
                backup_write_sql_export($safetyStream, backup_table_names());
            } finally {
                fclose($safetyStream);
            }
            $safetyBytes = filesize($safetyPath);
            if ($safetyBytes === false || $safetyBytes < 1) {
                @unlink($safetyPath);
                throw new RuntimeException('The pre-restore safety snapshot is empty or unreadable.');
            }

            try {
                $restored = backup_restore_apply($sql);
            } catch (Throwable $restoreError) {
                $beforeSql = file_get_contents($safetyPath);
                if (is_string($beforeSql) && $beforeSql !== '') {
                    try {
                        backup_restore_apply($beforeSql);
                        audit_best_effort('database_backup_restore_recovered', 'Backup', 'Warning',
                            'A restore attempt failed; the pre-restore database snapshot was reapplied.');
                    } catch (Throwable $recoveryError) {
                        error_log('BIRMS restore recovery failed. Safety snapshot remains at ' . $safetyPath . '. Restore error: '
                            . $restoreError->getMessage() . '; recovery error: ' . $recoveryError->getMessage());
                        throw new RuntimeException('Restore failed and automatic recovery could not be confirmed. Preserve the server temporary file ' . basename($safetyPath) . ' and contact the system administrator immediately.');
                    }
                }
                @unlink($safetyPath);
                throw new RuntimeException('The uploaded backup could not be restored. The pre-restore database snapshot was reapplied; no partial restore was reported.');
            }

            @unlink($safetyPath);
            audit_best_effort('database_backup_restored', 'Backup', 'Success',
                'Restored ' . $restored['table_count'] . ' tables from an uploaded BIRMS SQL backup (' . $restored['insert_count'] . ' data statements).');
            flash_add('success', 'Database restore completed. ' . $restored['table_count'] . ' tables were restored. Sign out and back in, then verify key records and separately restore private uploaded files from their matching file backup.');
            redirect('admin/backup.php');
        } catch (InvalidArgumentException $e) {
            flash_add('danger', $e->getMessage() . ' No restore was performed.');
            redirect('admin/backup.php');
        } catch (Throwable $e) {
            error_log('BIRMS database restore failed: ' . $e->getMessage());
            flash_add('danger', $e->getMessage());
            redirect('admin/backup.php');
        }
    }

    if ($action === 'cloud_upload' && !$cloudConfigured) {
        abort(400, 'Cloud upload is not configured. Set the WebDAV URL and credentials in protected local configuration before trying again.');
    }

    $tableNames = backup_table_names();
    if (!$tableNames) { abort(500, 'No database tables were found. No backup file was created.'); }

    $filename = 'birms-backup-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
    $backupPath = tempnam(sys_get_temp_dir(), 'birms-backup-');
    if ($backupPath === false) { abort(500, 'Could not create a temporary backup file.'); }
    $out = fopen($backupPath, 'wb');
    if ($out === false) {
        @unlink($backupPath);
        abort(500, 'Could not open the temporary backup file.');
    }

    try {
        backup_write_sql_export($out, $tableNames);
        if (!fclose($out)) { $out = null; throw new RuntimeException('Could not finalize the SQL backup file.'); }
        $out = null;
        $size = filesize($backupPath);
        if ($size === false || $size < 1) { throw new RuntimeException('The generated SQL backup is empty or unreadable.'); }

        if ($action === 'download') {
            // Do not stream confidential records until the audit event has been persisted.
            audit('database_backup_exported', 'Backup', 'Success',
                'Local SQL export prepared; table count: ' . count($tableNames) . '; bytes: ' . $size);
            header('Content-Type: application/sql; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, max-age=0');
            $read = readfile($backupPath);
            @unlink($backupPath);
            if ($read === false) { error_log('BIRMS: could not stream completed local backup.'); }
            exit;
        }

        // Record the start before transferring confidential records off the office server.
        audit('database_backup_cloud_started', 'Backup', 'Success',
            'HTTPS WebDAV upload started; table count: ' . count($tableNames) . '; bytes: ' . $size);
        $upload = backup_webdav_upload_file($backupPath, $filename);
        @unlink($backupPath);
        if (!$upload['ok']) {
            audit_best_effort('database_backup_cloud_failed', 'Backup', 'Failed',
                'HTTPS WebDAV upload did not return success; HTTP status: ' . (int) $upload['http_status']);
            error_log('BIRMS WebDAV backup upload failed; HTTP status ' . (int) $upload['http_status'] . '; ' . (string) $upload['error']);
            flash_add('danger', 'Cloud backup upload failed (HTTP status ' . (int) $upload['http_status'] . '). The system did not report a successful cloud backup. Check the configured HTTPS WebDAV folder, app password, network, and server certificate.');
            redirect('admin/backup.php');
        }
        audit_best_effort('database_backup_cloud_uploaded', 'Backup', 'Success',
            'SQL backup uploaded to configured HTTPS WebDAV storage; HTTP status: ' . (int) $upload['http_status'] . '; bytes: ' . $size);
        flash_add('success', 'SQL backup uploaded to the configured cloud destination. Verify the file in the storage service and test restoration separately.');
        redirect('admin/backup.php');
    } catch (Throwable $e) {
        if (is_resource($out)) { fclose($out); }
        @unlink($backupPath);
        error_log('BIRMS backup generation/transfer error: ' . $e->getMessage());
        abort(500, 'Backup creation failed. No successful backup is being reported; check the application error log.');
    }
}

render_header('Database Backup', [['Administration', null], ['Database Backup', null]], 'backup');
page_head('Database Backup & Export', 'Create a one-click SQL backup and, when configured, upload a copy to private HTTPS WebDAV storage.');
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-b mb-3">
      <div class="card-h"><h2>Download a SQL backup</h2><p>Restricted to the System Administrator.</p></div>
      <div class="card-body-b">
        <p>This creates a SQL file containing the database structure and records currently stored in the BIRMS database configured for this application. It does not change application records.</p>
        <div class="alert alert-warning"><strong>Confidential data:</strong> the file may contain resident details, document requests, case information, user accounts, and audit records. Store it only in an approved, access-controlled location.</div>
        <form method="post" action="<?= e(base_url('admin/backup.php')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="download"><button class="btn btn-primary" type="submit"><i class="bi bi-download me-1"></i>Download SQL backup</button></form>
      </div>
    </div>
    <div class="card-b">
      <div class="card-h"><h2>Cloud backup</h2><p>Uploads the generated SQL file to a configured HTTPS WebDAV folder.</p></div>
      <div class="card-body-b">
        <?php if ($cloudConfigured): ?>
          <div class="alert alert-success">Cloud destination is configured. The endpoint and credentials are read from protected local configuration and are not displayed here.</div>
          <form method="post" action="<?= e(base_url('admin/backup.php')) ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="cloud_upload">
            <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i>Create and upload cloud backup</button>
          </form>
        <?php else: ?>
          <div class="alert alert-secondary mb-0">Cloud upload is not configured yet. Set <code>BIRMS_BACKUP_WEBDAV_URL</code>, <code>BIRMS_BACKUP_WEBDAV_USERNAME</code>, and <code>BIRMS_BACKUP_WEBDAV_PASSWORD</code> in the server environment or <code>app/config.local.php</code>. Use an approved private folder and a dedicated app password; do not put credentials in GitHub or the source code.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card-b">
      <div class="card-h"><h2>Restore a BIRMS SQL backup</h2><p>Administrator-only. Restore replaces the records in the database currently configured for this BIRMS installation.</p></div>
      <div class="card-body-b">
        <div class="alert alert-danger"><strong>Destructive action:</strong> this replaces all current database records, including residents, requests, users and audit history. Download a fresh backup first. Use a backup made by the same BIRMS schema version; backups for a different schema are refused.</div>
        <form method="post" enctype="multipart/form-data" action="<?= e(base_url('admin/backup.php')) ?>" class="row g-3">
          <?= csrf_field() ?><input type="hidden" name="do" value="restore">
          <div class="col-12"><label class="form-label req" for="backup_file">BIRMS SQL backup file</label><input class="form-control" type="file" name="backup_file" id="backup_file" accept=".sql,text/plain,application/sql" required><div class="form-text">Maximum 100 MiB. The PHP server upload limit may be lower.</div></div>
          <div class="col-12"><label class="form-label req" for="confirm_database">Type the target database name to confirm</label><input class="form-control" name="confirm_database" id="confirm_database" maxlength="64" autocomplete="off" required><div class="form-text">Target: <code><?= e((string) config('db.name')) ?></code></div></div>
          <div class="col-12"><button class="btn btn-danger" type="submit">Validate and restore database</button></div>
        </form>
        <p class="small text-muted mt-3 mb-0">BIRMS validates the backup format and table/column set before changing data, retains the installed schema, and uses a temporary pre-restore snapshot for automatic recovery from an unexpected restore failure.</p>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-b h-100">
      <div class="card-h"><h2>Important limitations</h2></div>
      <div class="card-body-b">
        <ul class="mb-0">
          <li>Cloud upload requires an approved HTTPS WebDAV endpoint and credentials; no real service or account is bundled.</li>
          <li>The SQL backup does not include uploaded attachment files. Back up <code>storage/private_uploads/</code> separately at the same time and preserve its directory structure so database records continue to point to the right files.</li>
          <li>Backups are one-click; scheduled backups and backup notifications are not included.</li>
          <li>Successful transfer does not prove restoration will work. Test restoration in a separate scratch database before relying on a backup.</li>
          <li>Download, upload-start, success, and failure events are written to the audit trail.</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
