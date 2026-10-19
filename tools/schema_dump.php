<?php
/**
 * tools/schema_dump.php  (developer tool) Write database/expected_schema.json from the configured WORKING database
 * after migrations. Read-only on the database. Run after adding a migration.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("Command line only.\n"); }
require __DIR__ . '/../app/core.php';
require __DIR__ . '/schema_lib.php';
$db = (string) config('db.name');
$schema = schema_read(schema_connect($db), $db);
file_put_contents(APP_ROOT . '/database/expected_schema.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Wrote database/expected_schema.json (' . count($schema) . " tables)\n";
