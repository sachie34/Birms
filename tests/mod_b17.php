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
    $fkProbeKey = 'zz_b17_fk_restore_probe';
    $fkResidentId = 0;
    $fkChallengeHash = '';
    $fkBackupPath = null;
    $fullArchivePath = null;
    $badFullArchivePath = null;
    $fullPhotoRelative = '';
    $fullAttachmentRelative = '';
    $fullProbeKey = 'zz_b17_full_restore_probe';

    try {
        db_exec('DELETE FROM settings WHERE setting_key = ?', [$probeKey]);

        // A Chairman may view records, but backup/restore is an Admin-only data-management action.
        $chairmanPage = $chairman->get('/admin/backup.php');
        $adminPage = $admin->get('/admin/backup.php');
        record('B17-01', 'Only Admin can open the backup and restore screen',
            $chairmanPage['status'] === 403 && $adminPage['status'] === 200
            && str_contains($adminPage['body'], 'Restore a complete ZIP or legacy SQL backup')
            && str_contains($adminPage['body'], 'Download complete system ZIP'));

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

        // A syntactically valid backup with a missing referenced resident must be rejected atomically.
        $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
        $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
        $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
        if ($statusId < 1 || $typeId < 1) { throw new RuntimeException('B17 foreign-key fixture lookups are missing.'); }

        $fkSuffix = strtoupper(bin2hex(random_bytes(4)));
        $fkResidentNo = 'RES-B17-' . $fkSuffix;
        db_exec(
            "INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
              notification_preference, residency_status, household_id, is_household_head, archived_at)
             VALUES (?, '17 Restore Lane', ?, ?, 'Single', ?, 'ZZBackupFK', 'Lara', '1991-01-01',
                     'Female', 'Single', NULL, NULL, 'email', 'Active', NULL, 0, NULL)",
            [$fkResidentNo, $purok['lookup_id'] ?? null, $statusId, $typeId]
        );
        $fkResidentId = (int) db()->lastInsertId();
        $fkChallengeHash = hash('sha256', random_bytes(32));
        $fkCodeHash = hash('sha256', random_bytes(32));
        $fkDestination = 'b17-' . strtolower($fkSuffix) . '@example.invalid';
        db_exec(
            'INSERT INTO resident_verification_challenges
             (challenge_token_hash, code_hash, resident_id, channel, destination_snapshot, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$fkChallengeHash, $fkCodeHash, $fkResidentId, 'email', $fkDestination, date('Y-m-d H:i:s', time() + 600)]
        );

        $fkBackupPath = tempnam(sys_get_temp_dir(), 'birms-b17-fk-');
        if ($fkBackupPath === false) { throw new RuntimeException('Could not create B17 foreign-key backup fixture.'); }
        $fkOut = fopen($fkBackupPath, 'wb');
        if ($fkOut === false) { throw new RuntimeException('Could not open B17 foreign-key backup fixture.'); }
        try {
            backup_write_sql_export($fkOut, backup_table_names());
        } finally {
            fclose($fkOut);
        }
        $fkSql = file_get_contents($fkBackupPath);
        if (!is_string($fkSql) || $fkSql === '') { throw new RuntimeException('B17 foreign-key backup fixture is empty.'); }

        $badResidentId = (int) db_val('SELECT COALESCE(MAX(resident_id), 0) + 1000 FROM residents');
        $pdo = db();
        $needle = $pdo->quote($fkChallengeHash) . ', ' . $pdo->quote($fkCodeHash) . ', '
            . $pdo->quote((string) $fkResidentId) . ', ' . $pdo->quote('email') . ', ' . $pdo->quote($fkDestination);
        $replacement = $pdo->quote($fkChallengeHash) . ', ' . $pdo->quote($fkCodeHash) . ', '
            . $pdo->quote((string) $badResidentId) . ', ' . $pdo->quote('email') . ', ' . $pdo->quote($fkDestination);
        $badFkSql = str_replace($needle, $replacement, $fkSql, $fkReplacementCount);
        if ($fkReplacementCount !== 1) { throw new RuntimeException('Could not isolate the B17 resident foreign-key value in the SQL backup.'); }

        db_exec('DELETE FROM settings WHERE setting_key = ?', [$fkProbeKey]);
        db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$fkProbeKey, 'must survive invalid foreign-key restore']);
        $badFkRejected = false;
        try {
            backup_restore_apply($badFkSql);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'resident_verification_challenges.resident_id contains')) { throw $e; }
            $badFkRejected = true;
        }
        $fkProbeCount = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$fkProbeKey]);
        $challengeResidentAfter = (int) db_val(
            'SELECT resident_id FROM resident_verification_challenges WHERE challenge_token_hash = ?',
            [$fkChallengeHash]
        );
        record('B17-06', 'Restore rejects a backup with a nonexistent resident foreign-key reference and rolls back every table change',
            $badFkRejected && $fkProbeCount === 1 && $challengeResidentAfter === $fkResidentId,
            'orphan-reference-rejected=' . ($badFkRejected ? 'yes' : 'no')
            . '; preserved-post-backup-setting=' . $fkProbeCount
            . '; challenge-resident-after-rollback=' . $challengeResidentAfter);

        if (backup_full_zip_available()) {
            // Create one referenced resident photo and one additional private attachment file.
            $jpegFixture = fx_chairman_signature_jpeg();
            try { $photoBytes = file_get_contents($jpegFixture); }
            finally { @unlink($jpegFixture); }
            if (!is_string($photoBytes) || $photoBytes === '') { throw new RuntimeException('B17 full-backup photo fixture is empty.'); }

            $suffix = strtolower(bin2hex(random_bytes(12)));
            $fullPhotoRelative = 'storage/resident_photos/b17-fixtures/' . $suffix . '.jpg';
            $fullAttachmentRelative = 'storage/private_uploads/b17-fixtures/' . $suffix . '.pdf';
            $photoAbsolute = APP_ROOT . '/' . $fullPhotoRelative;
            $attachmentAbsolute = APP_ROOT . '/' . $fullAttachmentRelative;
            foreach ([dirname($photoAbsolute), dirname($attachmentAbsolute)] as $directory) {
                if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory))) {
                    throw new RuntimeException('B17 private-file fixture folders could not be created safely.');
                }
            }
            if (file_put_contents($photoAbsolute, $photoBytes, LOCK_EX) !== strlen($photoBytes)) {
                throw new RuntimeException('B17 resident photo fixture could not be written.');
            }
            $attachmentBytes = "%PDF-1.4\n%BIRMS B17 private attachment fixture\n";
            if (file_put_contents($attachmentAbsolute, $attachmentBytes, LOCK_EX) !== strlen($attachmentBytes)) {
                throw new RuntimeException('B17 private attachment fixture could not be written.');
            }
            db_exec('UPDATE residents SET photo_path = ? WHERE resident_id = ?', [$fullPhotoRelative, $fkResidentId]);

            $fullArchivePath = tempnam(sys_get_temp_dir(), 'birms-b17-full-');
            if ($fullArchivePath === false) { throw new RuntimeException('B17 full-system archive path could not be created.'); }
            $fullCreated = backup_full_system_create_archive($fullArchivePath);
            $fullZip = new ZipArchive();
            if ($fullZip->open($fullArchivePath, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('B17 full-system ZIP could not be opened after creation.');
            }
            try {
                $manifestRaw = $fullZip->getFromName('manifest.json');
                $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
                $manifestPaths = is_array($manifest['files'] ?? null)
                    ? array_column($manifest['files'], 'path') : [];
                $manifestValid = ($manifest['format'] ?? null) === 'BIRMS_FULL_BACKUP'
                    && in_array($fullPhotoRelative, $manifestPaths, true)
                    && in_array($fullAttachmentRelative, $manifestPaths, true)
                    && $fullZip->locateName('database/backup.sql') !== false;
            } finally {
                $fullZip->close();
            }

            db_exec('DELETE FROM settings WHERE setting_key = ?', [$fullProbeKey]);
            db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$fullProbeKey, 'must disappear after full restore']);
            @unlink($photoAbsolute);
            @unlink($attachmentAbsolute);

            $fullRestore = backup_full_system_restore_archive($fullArchivePath);
            $fullPhotoHash = hash_file('sha256', APP_ROOT . '/' . $fullPhotoRelative);
            $fullAttachmentHash = hash_file('sha256', APP_ROOT . '/' . $fullAttachmentRelative);
            $fullPhotoDbPath = (string) db_val('SELECT photo_path FROM residents WHERE resident_id = ?', [$fkResidentId]);
            $fullProbeAfter = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$fullProbeKey]);
            $fullRoundTrip = $manifestValid
                && $fullCreated['file_count'] >= 2
                && $fullRestore['file_count'] >= 2
                && $fullRestore['file_count_installed'] >= 2
                && is_string($fullPhotoHash) && hash_equals(hash('sha256', $photoBytes), $fullPhotoHash)
                && is_string($fullAttachmentHash) && hash_equals(hash('sha256', $attachmentBytes), $fullAttachmentHash)
                && $fullPhotoDbPath === $fullPhotoRelative
                && $fullProbeAfter === 0;
            record('B17-07', 'Complete ZIP backup round-trips the SQL database, resident photo and private attachment with SHA-256 verification',
                $fullRoundTrip,
                'manifest=' . ($manifestValid ? 'valid' : 'invalid')
                . '; archived-files=' . $fullCreated['file_count']
                . '; newly-installed-files=' . $fullRestore['file_count_installed']
                . '; photo-restored=' . (is_string($fullPhotoHash) && hash_equals(hash('sha256', $photoBytes), $fullPhotoHash) ? 'yes' : 'no')
                . '; attachment-restored=' . (is_string($fullAttachmentHash) && hash_equals(hash('sha256', $attachmentBytes), $fullAttachmentHash) ? 'yes' : 'no')
                . '; post-backup-setting=' . $fullProbeAfter);

            // A path-traversal entry in a ZIP manifest must fail before the database or filesystem is changed.
            $badFullArchivePath = tempnam(sys_get_temp_dir(), 'birms-b17-zip-slip-');
            if ($badFullArchivePath === false) { throw new RuntimeException('B17 unsafe ZIP fixture path could not be created.'); }
            $badZip = new ZipArchive();
            if ($badZip->open($badFullArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('B17 unsafe ZIP fixture could not be created.');
            }
            $unsafeRelative = 'storage/private_uploads/../../outside.txt';
            $unsafeBytes = 'must never be extracted';
            $badManifest = [
                'format' => 'BIRMS_FULL_BACKUP',
                'format_version' => 1,
                'created_at' => date(DATE_ATOM),
                'source_database' => $databaseName,
                'database' => [
                    'entry' => 'database/backup.sql',
                    'size' => strlen($sql),
                    'sha256' => hash('sha256', $sql),
                ],
                'files' => [[
                    'path' => $unsafeRelative,
                    'entry' => 'files/' . $unsafeRelative,
                    'size' => strlen($unsafeBytes),
                    'sha256' => hash('sha256', $unsafeBytes),
                ]],
            ];
            $badZip->addFromString('database/backup.sql', $sql);
            $badZip->addFromString('files/' . $unsafeRelative, $unsafeBytes);
            $badZip->addFromString('manifest.json', json_encode($badManifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $badZip->close();

            $settingsBeforeBadZip = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$fkProbeKey]);
            $unsafeRejected = false;
            try {
                backup_full_system_restore_archive($badFullArchivePath);
            } catch (InvalidArgumentException $e) {
                $unsafeRejected = str_contains($e->getMessage(), 'invalid private-file record');
            }
            $settingsAfterBadZip = (int) db_val('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$fkProbeKey]);
            $outsidePathCreated = is_file(APP_ROOT . '/outside.txt');
            record('B17-08', 'Full-system restore rejects ZIP path traversal before extraction or database changes',
                $unsafeRejected && $settingsBeforeBadZip === $settingsAfterBadZip && !$outsidePathCreated,
                'unsafe-path-rejected=' . ($unsafeRejected ? 'yes' : 'no')
                . '; settings-before=' . $settingsBeforeBadZip
                . '; settings-after=' . $settingsAfterBadZip
                . '; outside-file-created=' . ($outsidePathCreated ? 'yes' : 'no'));
        } else {
            record('B17-07', 'Complete ZIP backup and private-file restore requires the PHP ZIP extension', null,
                'Enable extension=zip in the active PHP configuration to run this test.');
            record('B17-08', 'Full-system ZIP path-traversal validation requires the PHP ZIP extension', null,
                'Enable extension=zip in the active PHP configuration to run this test.');
        }
    } finally {
        @unlink($backupPath);
        @unlink($badPath);
        if (is_string($staleBackupPath)) { @unlink($staleBackupPath); }
        if (is_string($fkBackupPath)) { @unlink($fkBackupPath); }
        if (is_string($fullArchivePath)) { @unlink($fullArchivePath); }
        if (is_string($badFullArchivePath)) { @unlink($badFullArchivePath); }
        if ($fullPhotoRelative !== '') { @unlink(APP_ROOT . '/' . $fullPhotoRelative); }
        if ($fullAttachmentRelative !== '') { @unlink(APP_ROOT . '/' . $fullAttachmentRelative); }
        @rmdir(APP_ROOT . '/storage/resident_photos/b17-fixtures');
        @rmdir(APP_ROOT . '/storage/private_uploads/b17-fixtures');
        if ($fkChallengeHash !== '') {
            db_exec('DELETE FROM resident_verification_challenges WHERE challenge_token_hash = ?', [$fkChallengeHash]);
        }
        if ($fkResidentId > 0) { db_exec('DELETE FROM residents WHERE resident_id = ?', [$fkResidentId]); }
        db_exec('DELETE FROM settings WHERE setting_key IN (?, ?, ?, ?)', [$probeKey, $staleProbeKey, $fkProbeKey, $fullProbeKey]);
    }
}
