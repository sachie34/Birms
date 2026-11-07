<?php
/**
 * Verify that the BIRMS SQL export can be restored into a separate disposable database.
 * This is a CI/deployment check. It refuses to run unless the configured source database
 * ends in _work or _test and only drops the exact database name birms_restore_work.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/backup_lib.php';
require_once __DIR__ . '/schema_lib.php';

$sourceDb = (string) config('db.name');
if (!preg_match('/^[A-Za-z0-9_$]{1,64}_(?:work|test)$/i', $sourceDb)) {
    fwrite(STDERR, "Refusing backup-restore test: source database must end in _work or _test.\n");
    exit(2);
}
$restoreDb = 'birms_restore_work';
if ($sourceDb === $restoreDb) {
    fwrite(STDERR, "Refusing backup-restore test: source and restore databases must differ.\n");
    exit(2);
}

$db = config('db');
$restoreUser = (string) (getenv('BIRMS_RESTORE_DB_USER') ?: ($db['user'] ?? ''));
$restorePass = (string) (getenv('BIRMS_RESTORE_DB_PASS') ?: ($db['pass'] ?? ''));
$host = (string) ($db['host'] ?? '127.0.0.1');
$port = (int) ($db['port'] ?? 3306);
$admin = null;
$restore = null;
$restoreCreated = false;
$exitCode = 0;

try {
    $admin = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $restoreUser,
        $restorePass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
    );

    // This is the only database this test is allowed to remove.
    $admin->exec('DROP DATABASE IF EXISTS `birms_restore_work`');
    $admin->exec('CREATE DATABASE `birms_restore_work` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $restoreCreated = true;

    $stream = tmpfile();
    if ($stream === false) { throw new RuntimeException('Could not create a temporary SQL export stream.'); }
    try {
        $tables = backup_table_names();
        backup_write_sql_export($stream, $tables);
        rewind($stream);
        $sql = stream_get_contents($stream);
        if (!is_string($sql) || $sql === '') { throw new RuntimeException('The generated SQL export is empty.'); }
    } finally {
        fclose($stream);
    }

    $restore = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $restoreDb),
        $restoreUser,
        $restorePass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
    );
    $restore->exec($sql);

    $sourcePdo = db();
    $sourceSchema = schema_read($sourcePdo, $sourceDb);
    $restoredSchema = schema_read($restore, $restoreDb);
    if ($sourceSchema !== $restoredSchema) {
        throw new RuntimeException('Restored table, column, index or foreign-key structure does not match the source database.');
    }

    $rowCountChecks = 0;
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', (string) $table)) {
            throw new RuntimeException('A source table name failed validation.');
        }
        $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
        $sourceCount = (int) $sourcePdo->query('SELECT COUNT(*) FROM ' . $identifier)->fetchColumn();
        $restoredCount = (int) $restore->query('SELECT COUNT(*) FROM ' . $identifier)->fetchColumn();
        if ($sourceCount !== $restoredCount) {
            throw new RuntimeException('Row-count mismatch after restore for table ' . $table . '.');
        }
        $rowCountChecks++;
    }

    echo 'PASS: SQL backup restored to ' . $restoreDb . '; ' . count($tables)
        . ' tables, ' . $rowCountChecks . ' row counts and full schema match.\n';
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: Backup restore verification: ' . $e->getMessage() . "\n");
    $exitCode = 1;
} finally {
    $restore = null;
    if ($admin instanceof PDO && $restoreCreated) {
        try { $admin->exec('DROP DATABASE IF EXISTS `birms_restore_work`'); }
        catch (Throwable $cleanupError) { fwrite(STDERR, 'WARNING: Could not remove disposable restore database: ' . $cleanupError->getMessage() . "\n"); $exitCode = 1; }
    }
}
exit($exitCode);
