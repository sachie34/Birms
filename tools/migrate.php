<?php
/**
 * tools/migrate.php  Apply numbered SQL migrations.
 *   php tools/migrate.php status
 *   php tools/migrate.php up [--dry-run] [--i-have-a-backup]
 *   php tools/migrate.php down [--force]       (undo the last migration; working/test databases only)
 *
 * SAFETY: it never runs on a database that already has tables but no schema_migrations table
 * (that is how an existing barangay_db looks). Databases not ending in _work or _test also need --i-have-a-backup.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
require __DIR__ . '/../app/core.php';

$cmd = $argv[1] ?? 'status';
$flags = array_slice($argv, 2);
$dry = in_array('--dry-run', $flags, true);
$backup = in_array('--i-have-a-backup', $flags, true);
$force = in_array('--force', $flags, true);

$c = config('db'); $name = (string) $c['name'];
$isWork = preg_match('/(_work|_test)$/', $name) === 1;
function out(string $s): void { echo $s . PHP_EOL; }
function die_msg(string $s, int $code = 2): never { fwrite(STDERR, $s . PHP_EOL); exit($code); }

out("Target database: {$name}" . ($isWork ? ' (working/test copy)' : ' (NOT a _work/_test name)'));
if (!$isWork && $cmd === 'up' && !$backup) { die_msg("Refusing: this is not a _work/_test database. Back it up, then add --i-have-a-backup."); }

try {
    $srv = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int) $c['port']), (string) $c['user'], (string) $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { die_msg('Cannot connect to the database server: ' . $e->getMessage()); }

$exists = (bool) $srv->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $srv->quote($name))->fetchColumn();
if (!$exists) {
    if (!$isWork) { die_msg("Database '{$name}' does not exist. Create it first (or use a name ending in _work)."); }
    if ($cmd !== 'up') { out('Database does not exist yet; nothing applied.'); exit(0); }
    if ($dry) { out("[dry-run] would create database {$name}"); }
    else { $srv->exec('CREATE DATABASE `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); out("Created database {$name}."); }
}
if (!$exists && $dry) { $applied = []; $tables = []; }
else {
    $pdo = db();
    $tables = array_map('current', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
    if ($tables && !in_array('schema_migrations', $tables, true)) {
        die_msg("STOP: '{$name}' already contains tables but has no schema_migrations record.\n"
              . "It looks like an existing database. Nothing was changed. Compare it first (docs/README_XAMPP.md, section 'Existing database').");
    }
    if (!in_array('schema_migrations', $tables, true) && $cmd === 'up' && !$dry) {
        $pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $tables[] = 'schema_migrations';
    }
    $applied = in_array('schema_migrations', $tables, true) ? array_column(db_all('SELECT version FROM schema_migrations ORDER BY version'), 'version') : [];
}

$files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
$files = array_values(array_filter($files, fn($f) => !str_ends_with($f, '.down.sql')));
sort($files);
$pending = array_values(array_filter($files, fn($f) => !in_array(basename($f, '.sql'), $applied, true)));

function run_sql_file(string $file): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file)) ?? '';
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) ?: [] as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') { db()->exec($stmt); }
    }
}

if ($cmd === 'status') {
    foreach ($files as $f) { $v = basename($f, '.sql'); out(sprintf('  %-28s %s', $v, in_array($v, $applied, true) ? 'applied' : 'PENDING')); }
    out(count($pending) . ' pending.'); exit(0);
}
if ($cmd === 'up') {
    if (!$pending) { out('Nothing to apply. Up to date.'); exit(0); }
    foreach ($pending as $f) {
        $v = basename($f, '.sql');
        if ($dry) { out("[dry-run] would apply {$v}"); continue; }
        out("Applying {$v} ...");
        run_sql_file($f);
        db_exec('INSERT INTO schema_migrations (version) VALUES (?)', [$v]);
        out("  done.");
    }
    exit(0);
}
if ($cmd === 'down') {
    if (!$isWork && !$force) { die_msg('Refusing: down only runs on _work/_test databases (or with --force).'); }
    if (!$applied) { out('Nothing to undo.'); exit(0); }
    $v = end($applied); $down = APP_ROOT . "/database/migrations/{$v}.down.sql";
    if (!is_file($down)) { die_msg("No down script for {$v}."); }
    out("Undoing {$v} ..."); run_sql_file($down); db_exec('DELETE FROM schema_migrations WHERE version = ?', [$v]); out('  done.'); exit(0);
}
die_msg('Usage: php tools/migrate.php status|up|down [--dry-run] [--i-have-a-backup] [--force]');
