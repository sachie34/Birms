<?php
/** Administrator-only local SQL export. No cloud upload or background schedule is implied. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('backup.run');

if (is_post()) {
    require_csrf();
    if (post_str('do') !== 'download') { abort(400, 'Unknown backup action.'); }

    // Do not stream until the audit event is safely persisted.
    $tables = db_all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $tableNames = [];
    foreach ($tables as $row) {
        $name = (string) array_values($row)[0];
        if ($name !== '' && preg_match('/^[A-Za-z0-9_$]+$/', $name)) { $tableNames[] = $name; }
    }
    sort($tableNames, SORT_NATURAL | SORT_FLAG_CASE);
    if (!$tableNames) { abort(500, 'No database tables were found. No backup file was created.'); }

    audit('database_backup_exported', 'Backup', 'Success', 'Local SQL export started; table count: ' . count($tableNames));

    $filename = 'birms-backup-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');
    $out = fopen('php://output', 'wb');
    if ($out === false) { abort(500, 'Could not open the download stream.'); }

    $write = static function (string $line) use ($out): void { fwrite($out, $line . "\n"); };
    $write('-- BIRMS local SQL export');
    $write('-- Generated: ' . date('c'));
    $write('-- Source database name: ' . preg_replace('/[^A-Za-z0-9_$-]/', '', (string) config('db.name')));
    $write('-- Contains confidential resident and administrative records. Store and transfer securely.');
    $write('SET NAMES utf8mb4;');
    $write('SET FOREIGN_KEY_CHECKS = 0;');
    $write('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";');
    $write('');

    $pdo = db();
    foreach ($tableNames as $table) {
        $identifier = '`' . str_replace('`', '``', $table) . '`';
        $createRow = db_one('SHOW CREATE TABLE ' . $identifier);
        if ($createRow === null) { continue; }
        $createSql = (string) ($createRow['Create Table'] ?? array_values($createRow)[1] ?? '');
        if ($createSql === '') { continue; }

        $write('-- --------------------------------------------------------');
        $write('-- Table: ' . $table);
        $write('DROP TABLE IF EXISTS ' . $identifier . ';');
        $write($createSql . ';');

        $stmt = $pdo->query('SELECT * FROM ' . $identifier);
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = '`' . str_replace('`', '``', (string) ($meta['name'] ?? 'column' . $i)) . '`';
        }
        if (!$columns) { $stmt->closeCursor(); continue; }
        $prefix = 'INSERT INTO ' . $identifier . ' (' . implode(', ', $columns) . ') VALUES ';
        $batch = [];
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) { $values[] = 'NULL'; }
                else {
                    $quoted = $pdo->quote((string) $value);
                    if ($quoted === false) { throw new RuntimeException('A database value could not be quoted for SQL export.'); }
                    $values[] = $quoted;
                }
            }
            $batch[] = '(' . implode(', ', $values) . ')';
            if (count($batch) >= 100) {
                $write($prefix . implode(",\n", $batch) . ';');
                $batch = [];
            }
        }
        if ($batch) { $write($prefix . implode(",\n", $batch) . ';'); }
        $stmt->closeCursor();
        $write('');
    }
    $write('SET FOREIGN_KEY_CHECKS = 1;');
    fclose($out);
    exit;
}

render_header('Database Backup', [['Administration', null], ['Database Backup', null]], 'backup');
page_head('Database Backup', 'Create a downloadable SQL copy of the configured BIRMS database.');
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-b">
      <div class="card-h"><h2>Download a SQL backup</h2><p>Restricted to the System Administrator.</p></div>
      <div class="card-body-b">
        <p>This creates a SQL file containing the database structure and records currently stored in the BIRMS database configured for this application. It does not change application records.</p>
        <div class="alert alert-warning"><strong>Confidential data:</strong> the file may contain resident details, document requests, case information, user accounts, and audit records. Save it only to an approved, access-controlled location. Do not email it or upload it to a public drive.</div>
        <form method="post" action="<?= e(base_url('admin/backup.php')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="download"><button class="btn btn-primary" type="submit"><i class="bi bi-download me-1"></i>Download SQL backup</button></form>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-b h-100">
      <div class="card-h"><h2>Current limitations</h2></div>
      <div class="card-body-b">
        <ul class="mb-0">
          <li>Downloads a local SQL file; it does not upload to cloud storage.</li>
          <li>It does not schedule automatic backups or send backup notifications.</li>
          <li>It does not prove a restore will succeed. Test restoration in a separate scratch database before relying on a backup.</li>
          <li>The export action is recorded in the audit trail.</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
