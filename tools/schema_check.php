<?php
/**
 * tools/schema_check.php  Compare a database with the structure BIRMS expects. READ-ONLY: it never writes.
 *   php tools/schema_check.php                  checks the configured database
 *   php tools/schema_check.php --db=barangay_db checks any database by name (your original is safe: only SELECTs run)
 * It reads structure only (no resident data) and prints what is missing, different, or extra.
 * Table and column names are compared ignoring capital letters (Windows is case-insensitive).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
require __DIR__ . '/../app/core.php';
require __DIR__ . '/schema_lib.php';

$db = (string) config('db.name');
foreach (array_slice($argv, 1) as $a) { if (str_starts_with($a, '--db=')) { $db = substr($a, 5); } }
if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $db)) { fwrite(STDERR, "Invalid database name.\n"); exit(2); }
$expected = json_decode((string) file_get_contents(APP_ROOT . '/database/expected_schema.json'), true);
if (!is_array($expected)) { fwrite(STDERR, "database/expected_schema.json is missing or unreadable.\n"); exit(2); }
try { $actual = schema_read(schema_connect($db), $db); } catch (PDOException $e) { fwrite(STDERR, 'Cannot read ' . $db . ': ' . $e->getMessage() . "\n"); exit(2); }

$issues = []; $same = 0;
foreach ($expected as $t => $e) {
    if (!isset($actual[$t])) { $issues[] = "MISSING TABLE      {$t}"; continue; }
    $a = $actual[$t]; $tableOk = true;
    foreach ($e['columns'] as $c => $ec) {
        if (!isset($a['columns'][$c])) { $issues[] = "MISSING COLUMN     {$t}.{$c} ({$ec['type']})"; $tableOk = false; continue; }
        $ac = $a['columns'][$c]; $d = [];
        if ($ac['type'] !== $ec['type']) { $d[] = "type {$ac['type']} (expected {$ec['type']})"; }
        if ($ac['nullable'] !== $ec['nullable']) { $d[] = 'nullable ' . ($ac['nullable'] ? 'YES' : 'NO') . ' (expected ' . ($ec['nullable'] ? 'YES' : 'NO') . ')'; }
        if ($d) { $issues[] = "DIFFERENT COLUMN  {$t}.{$c}: " . implode('; ', $d); $tableOk = false; }
    }
    foreach ($a['columns'] as $c => $_) { if (!isset($e['columns'][$c])) { $issues[] = "EXTRA COLUMN (yours) {$t}.{$c}"; $tableOk = false; } }
    foreach ($e['indexes'] as $n => $ei) {
        $found = false;
        foreach ($a['indexes'] as $ai) { if ($ai['columns'] === $ei['columns'] && $ai['unique'] === $ei['unique']) { $found = true; break; } }
        if (!$found) { $issues[] = "MISSING INDEX      {$t} (" . implode(',', $ei['columns']) . ')' . ($ei['unique'] ? ' UNIQUE' : ''); $tableOk = false; }
    }
    foreach ($e['fks'] as $n => $ef) {
        $found = null;
        foreach ($a['fks'] as $af) { if ($af['columns'] === $ef['columns'] && $af['ref'] === $ef['ref']) { $found = $af; break; } }
        if ($found === null) { $issues[] = "MISSING FOREIGN KEY {$t} (" . implode(',', $ef['columns']) . ") -> {$ef['ref']}"; $tableOk = false; }
        elseif ($found['on_delete'] !== $ef['on_delete']) { $issues[] = "DIFFERENT DELETE RULE {$t} (" . implode(',', $ef['columns']) . "): yours {$found['on_delete']}, expected {$ef['on_delete']}" . ($found['on_delete'] === 'CASCADE' ? '  <-- deleting a parent row deletes child rows' : ''); $tableOk = false; }
    }
    if ($tableOk) { $same++; }
}
foreach ($actual as $t => $_) { if (!isset($expected[$t])) { $issues[] = "EXTRA TABLE (yours) {$t}"; } }

echo "Database checked: {$db}  (read-only)\nTables identical to what BIRMS expects: {$same} of " . count($expected) . "\n";
if (!$issues) { echo "No differences found.\n"; exit(0); }
echo count($issues) . " difference(s):\n"; foreach ($issues as $i) { echo '  - ' . $i . "\n"; }
echo "\nNothing was changed. Send this report so the migration for your real schema can be written.\n";
exit(1);
