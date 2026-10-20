<?php
/** Admin-only SQL backup download for the configured BIRMS database. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('backup.run');

if (is_post()) {
    require_csrf();
    if (post_str('action') !== 'download') { abort(400, 'Unknown backup action.'); }
    audit('backup_downloaded', 'Backup', 'Success', 'SQL backup downloaded');
    $name = (string) config('db.name');
    $safeName = preg_replace('/[^a-z0-9_-]/i', '_', $name) ?: 'birms';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '_backup_' . date('Ymd_His') . '.sql"');
    header('X-Content-Type-Options: nosniff');
    echo "-- BIRMS SQL backup\n-- Database: {$name}\n-- Generated: " . date('c') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';
        $create = db()->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
        $createSql = (string) ($create['Create Table'] ?? $create['Create View'] ?? '');
        if ($createSql !== '') { echo "DROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n\n"; }
        $columns = db()->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_COLUMN);
        $columnSql = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', (string) $c) . '`', $columns));
        if ($columnSql === '') { continue; }
        $rows = db()->query('SELECT * FROM ' . $quotedTable);
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $values = [];
            foreach ($row as $value) { $values[] = $value === null ? 'NULL' : (string) db()->quote((string) $value); }
            echo "INSERT INTO {$quotedTable} ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

render_header('Backup', [['Administration', null], ['Backup', null]], 'backup');
page_head('Database Backup', 'Download a SQL copy of the configured BIRMS database. Store it securely and test restores separately.');
card_start('Create a backup', 'The export includes the current schema and data. This action is restricted to the Administrator.');
echo '<div class="alert alert-warning"><i class="bi bi-shield-exclamation me-2"></i>Keep the downloaded SQL file private. It contains resident and administrative records.</div>';
echo '<form method="post" action="' . e(base_url('admin/backup.php')) . '">' . csrf_field() . '<input type="hidden" name="action" value="download"><button class="btn btn-primary" type="submit"><i class="bi bi-download me-1"></i>Download SQL backup</button></form>';
card_end();
render_footer();
