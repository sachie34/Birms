<?php
/**
 * Database export and optional HTTPS WebDAV backup support.
 * Cloud credentials must be supplied through app/config.local.php or environment variables.
 */
declare(strict_types=1);

/** Return base-table names only; identifiers are validated before use in SQL. */
function backup_table_names(): array
{
    $tables = db_all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $names = [];
    foreach ($tables as $row) {
        $name = (string) array_values($row)[0];
        if ($name !== '' && preg_match('/^[A-Za-z0-9_$]+$/', $name)) { $names[] = $name; }
    }
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

/** Write a consistent schema-and-data SQL export to an open writable stream. */
function backup_write_sql_export($out, array $tableNames): void
{
    if (!is_resource($out)) { throw new InvalidArgumentException('A writable backup stream is required.'); }
    $write = static function (string $line) use ($out): void {
        if (fwrite($out, $line . "\n") === false) { throw new RuntimeException('Could not write the SQL backup.'); }
    };
    $write('-- BIRMS SQL backup');
    $write('-- Generated: ' . date('c'));
    $write('-- Source database name: ' . preg_replace('/[^A-Za-z0-9_$-]/', '', (string) config('db.name')));
    $write('-- Contains confidential resident and administrative records. Store and transfer securely.');
    $write('SET NAMES utf8mb4;');
    $write('SET FOREIGN_KEY_CHECKS = 0;');
    $write('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";');
    $write('');

    $pdo = db();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        foreach ($tableNames as $table) {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', (string) $table)) {
            throw new InvalidArgumentException('A database table name failed validation.');
        }
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
        try {
            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = '`' . str_replace('`', '``', (string) ($meta['name'] ?? 'column' . $i)) . '`';
            }
            if (!$columns) { continue; }
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
        } finally {
            $stmt->closeCursor();
        }
            $write('');
        }
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
    $write('SET FOREIGN_KEY_CHECKS = 1;');
}

/** Read backup credentials from the protected config or environment; never from request parameters. */
function backup_webdav_configuration(): array
{
    return [
        'url' => trim((string) config('backup.webdav_url', '')),
        'username' => (string) config('backup.webdav_username', ''),
        'password' => (string) config('backup.webdav_password', ''),
    ];
}

function backup_webdav_is_configured(): bool
{
    $c = backup_webdav_configuration();
    return $c['url'] !== '' && $c['username'] !== '' && $c['password'] !== '';
}

/** Build an HTTPS WebDAV file URL and reject credentials, query strings, fragments and path traversal. */
function backup_webdav_destination_url(string $baseUrl, string $filename): string
{
    if (preg_match('/^[A-Za-z0-9_-]+\.(?:sql|zip)$/i', $filename) !== 1) {
        throw new InvalidArgumentException('The backup filename is invalid.');
    }
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || trim((string) ($parts['host'] ?? '')) === ''
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('Cloud backup requires an HTTPS WebDAV folder URL without embedded credentials, query strings or fragments.');
    }
    if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
        throw new InvalidArgumentException('The WebDAV port is invalid.');
    }
    $path = (string) ($parts['path'] ?? '/');
    foreach (explode('/', rawurldecode($path)) as $segment) {
        if ($segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('The WebDAV folder path may not contain dot segments.');
        }
    }
    $path = rtrim($path, '/') . '/' . rawurlencode($filename);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return 'https://' . (string) $parts['host'] . $port . $path;
}

/** Upload a generated SQL file by HTTPS WebDAV PUT with TLS verification and redirects disabled. */
/** Return the correct MIME type for the explicitly supported backup formats. */
function backup_webdav_content_type(string $filename): string
{
    if (preg_match('/^[A-Za-z0-9_-]+\\.zip$/i', $filename) === 1) {
        return 'application/zip';
    }
    if (preg_match('/^[A-Za-z0-9_-]+\\.sql$/i', $filename) === 1) {
        return 'application/sql';
    }
    throw new InvalidArgumentException('The backup filename is invalid.');
}

function backup_webdav_upload_file(string $localPath, string $filename): array
{
    $cfg = backup_webdav_configuration();
    if ($cfg['url'] === '' || $cfg['username'] === '' || $cfg['password'] === '') {
        return ['ok' => false, 'http_status' => 0, 'error' => 'Cloud backup is not configured.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_status' => 0, 'error' => 'The PHP cURL extension is not available.'];
    }
    if (!is_file($localPath) || !is_readable($localPath)) {
        return ['ok' => false, 'http_status' => 0, 'error' => 'The generated backup file is not readable.'];
    }
    try {
        $destination = backup_webdav_destination_url($cfg['url'], $filename);
    } catch (Throwable $e) {
        return ['ok' => false, 'http_status' => 0, 'error' => $e->getMessage()];
    }
    $in = fopen($localPath, 'rb');
    if ($in === false) { return ['ok' => false, 'http_status' => 0, 'error' => 'Could not open the generated backup file.']; }
    $ch = curl_init($destination);
    if ($ch === false) {
        fclose($in);
        return ['ok' => false, 'http_status' => 0, 'error' => 'Could not initialize the HTTPS upload.'];
    }
    $size = filesize($localPath);
    curl_setopt_array($ch, [
        CURLOPT_UPLOAD => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_INFILE => $in,
        CURLOPT_INFILESIZE => $size === false ? 0 : $size,
        CURLOPT_USERPWD => $cfg['username'] . ':' . $cfg['password'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . backup_webdav_content_type($filename), 'Expect:'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? (string) curl_error($ch) : '';
    curl_close($ch);
    fclose($in);
    $ok = $body !== false && in_array($status, [200, 201, 204], true);
    return ['ok' => $ok, 'http_status' => $status, 'error' => $error];
}


const BACKUP_RESTORE_MAX_BYTES = 104857600; // 100 MiB

/** Split BIRMS-generated SQL without splitting semicolons inside quoted values. */
function backup_restore_split_statements(string $sql): array
{
    if (!str_contains(substr($sql, 0, 1024), '-- BIRMS SQL backup')) {
        throw new InvalidArgumentException('This is not a BIRMS SQL backup file.');
    }
    $statements = [];
    $buffer = '';
    $quote = null;
    $escaped = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if ($quote !== null) {
            $buffer .= $ch;
            if ($escaped) { $escaped = false; continue; }
            if (($quote === "'" || $quote === '"') && $ch === '\\') { $escaped = true; continue; }
            if ($ch === $quote) {
                if ($next === $quote) { $buffer .= $next; $i++; }
                else { $quote = null; }
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === chr(96)) { $quote = $ch; $buffer .= $ch; continue; }
        if ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
            if (trim($buffer) !== '') { throw new InvalidArgumentException('The backup contains an unsupported inline SQL comment.'); }
            while ($i < $length && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        if ($ch === ';') {
            $statement = trim($buffer);
            if ($statement !== '') { $statements[] = $statement; }
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }
    if ($quote !== null) { throw new InvalidArgumentException('The backup contains an unfinished quoted SQL value.'); }
    if (trim($buffer) !== '') { throw new InvalidArgumentException('The backup ends with an incomplete SQL statement.'); }
    if (!$statements) { throw new InvalidArgumentException('The backup contains no restorable SQL statements.'); }
    return $statements;
}

/** Validate that INSERT values contain only BIRMS-exported string literals or NULL. */
function backup_restore_values_are_literals(string $values): bool
{
    $i = 0;
    $length = strlen($values);
    $skipSpace = static function () use (&$i, $length, $values): void {
        while ($i < $length && ctype_space($values[$i])) { $i++; }
    };
    $parseValue = static function () use (&$i, $length, $values, $skipSpace): bool {
        $skipSpace();
        if ($i >= $length) { return false; }
        if (strncasecmp(substr($values, $i, 4), 'NULL', 4) === 0) { $i += 4; return true; }
        if ($values[$i] !== "'") { return false; }
        $i++;
        while ($i < $length) {
            $ch = $values[$i];
            if ($ch === '\\') { $i += 2; continue; }
            if ($ch === "'") {
                if ($i + 1 < $length && $values[$i + 1] === "'") { $i += 2; continue; }
                $i++;
                return true;
            }
            $i++;
        }
        return false;
    };

    $tuples = 0;
    while (true) {
        $skipSpace();
        if ($i >= $length || $values[$i] !== '(') { return false; }
        $i++;
        $fields = 0;
        while (true) {
            if (!$parseValue()) { return false; }
            $fields++;
            $skipSpace();
            if ($i >= $length) { return false; }
            if ($values[$i] === ',') { $i++; continue; }
            if ($values[$i] === ')') { $i++; break; }
            return false;
        }
        if ($fields < 1) { return false; }
        $tuples++;
        $skipSpace();
        if ($i === $length) { return $tuples > 0; }
        if ($values[$i] !== ',') { return false; }
        $i++;
    }
}

/**
 * Preflight a BIRMS SQL backup against the current database schema.
 * DROP/CREATE are validated but never executed: restore replaces rows and preserves the installed schema.
 *
 * @param list<string> $allowedTables Current base-table names.
 * @return array{tables:list<string>,inserts:list<string>,blotter_cases:array<string,int>}
 */
function backup_restore_parse(string $sql, array $allowedTables): array
{
    if (strlen($sql) < 32 || strlen($sql) > BACKUP_RESTORE_MAX_BYTES) {
        throw new InvalidArgumentException('The SQL backup must be non-empty and no larger than 100 MiB.');
    }
    $allowed = [];
    foreach ($allowedTables as $table) {
        $table = (string) $table;
        if (preg_match('/^[A-Za-z0-9_$]+$/', $table) !== 1) {
            throw new LogicException('A current database table name failed validation.');
        }
        $allowed[$table] = true;
    }
    if (!$allowed) { throw new RuntimeException('No current database tables were found.'); }

    $dropped = [];
    $created = [];
    $inserts = [];
    $blotterCases = [];
    foreach (backup_restore_split_statements($sql) as $statement) {
        if (preg_match('/^SET NAMES utf8mb4$/i', $statement) === 1
            || preg_match('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement) === 1
            || preg_match('/^SET SQL_MODE\s*=\s*["\']NO_AUTO_VALUE_ON_ZERO["\']$/i', $statement) === 1) {
            continue;
        }
        if (preg_match('/^DROP TABLE IF EXISTS \x60([A-Za-z0-9_$]+)\x60$/i', $statement, $m) === 1) {
            $table = $m[1];
            if (!isset($allowed[$table]) || isset($dropped[$table])) {
                throw new InvalidArgumentException('The backup contains an unknown or duplicate table entry.');
            }
            $dropped[$table] = true;
            continue;
        }
        if (preg_match('/^CREATE TABLE \x60([A-Za-z0-9_$]+)\x60\s*\(/is', $statement, $m) === 1) {
            $table = $m[1];
            if (!isset($allowed[$table]) || !isset($dropped[$table]) || isset($created[$table])) {
                throw new InvalidArgumentException('The backup table structure does not match the current BIRMS database.');
            }
            $created[$table] = true;
            continue;
        }
        if (preg_match('/^INSERT INTO \x60([A-Za-z0-9_$]+)\x60\s*\((.*?)\)\s+VALUES\s+(.+)$/is', $statement, $m) === 1) {
            $table = $m[1];
            if (!isset($created[$table])) { throw new InvalidArgumentException('The backup contains rows for a table without a validated table definition.'); }
            $columnSql = trim($m[2]);
            if (preg_match('/^\x60[A-Za-z0-9_$]+\x60(?:\s*,\s*\x60[A-Za-z0-9_$]+\x60)*$/', $columnSql) !== 1) {
                throw new InvalidArgumentException('The backup contains an invalid INSERT column list.');
            }
            preg_match_all('/\x60([A-Za-z0-9_$]+)\x60/', $columnSql, $columnMatches);
            $columns = $columnMatches[1] ?? [];
            if (!$columns || count($columns) !== count(array_unique($columns))) {
                throw new InvalidArgumentException('The backup contains an empty or duplicated INSERT column list.');
            }
            if ($table === 'blotters') {
                if (($columns[0] ?? '') !== 'blotter_id' || ($columns[1] ?? '') !== 'case_no') {
                    throw new InvalidArgumentException('The backup blotter rows do not contain their permanent case identifiers.');
                }
                preg_match_all("/\\(\\s*'([0-9]+)'\\s*,\\s*'([A-Za-z0-9_-]+)'/s", (string) $m[3], $caseMatches, PREG_SET_ORDER);
                foreach ($caseMatches as $caseMatch) {
                    $caseNo = (string) $caseMatch[2];
                    $caseId = (int) $caseMatch[1];
                    if ($caseNo === '' || $caseId < 1 || (isset($blotterCases[$caseNo]) && $blotterCases[$caseNo] !== $caseId)) {
                        throw new InvalidArgumentException('The backup contains invalid or duplicate blotter identifiers.');
                    }
                    $blotterCases[$caseNo] = $caseId;
                }
            }
            $quote = chr(96);
            $currentColumns = array_map(
                static fn(array $row): string => (string) $row['Field'],
                db_all('SHOW COLUMNS FROM ' . $quote . $table . $quote)
            );
            foreach ($columns as $column) {
                if (!in_array($column, $currentColumns, true)) {
                    throw new InvalidArgumentException('The backup was created for a different BIRMS schema. Missing column: ' . $table . '.' . $column . '.');
                }
            }
            if (!backup_restore_values_are_literals((string) $m[3])) {
                throw new InvalidArgumentException('The backup contains an unsupported SQL expression. Only literal data values are accepted.');
            }
            $inserts[] = $statement;
            continue;
        }
        throw new InvalidArgumentException('The backup contains an unsupported SQL statement. Upload a file created by BIRMS Database Backup.');
    }

    $expected = array_keys($allowed);
    sort($expected, SORT_STRING);
    $dropTables = array_keys($dropped);
    $createTables = array_keys($created);
    sort($dropTables, SORT_STRING);
    sort($createTables, SORT_STRING);
    if ($dropTables !== $expected || $createTables !== $expected) {
        throw new InvalidArgumentException('The backup does not contain the complete table set for this BIRMS database. Restore was not started.');
    }
    return ['tables' => $expected, 'inserts' => $inserts, 'blotter_cases' => $blotterCases];
}

/**
 * Validate restored rows against every foreign key before the restore transaction commits.
 *
 * MySQL/MariaDB do not retroactively validate rows inserted while FOREIGN_KEY_CHECKS=0
 * when the setting is turned back on. This explicit pass prevents a damaged or
 * tampered SQL backup from leaving orphaned child records behind.
 */
function backup_restore_assert_foreign_keys_valid(): void
{
    $rows = db_all(
        'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, '
        . 'REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
        . 'FROM information_schema.KEY_COLUMN_USAGE '
        . 'WHERE CONSTRAINT_SCHEMA = DATABASE() '
        . 'AND REFERENCED_TABLE_SCHEMA = DATABASE() '
        . 'AND REFERENCED_TABLE_NAME IS NOT NULL '
        . 'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
    );

    $groups = [];
    foreach ($rows as $row) {
        $childTable = (string) ($row['TABLE_NAME'] ?? '');
        $constraint = (string) ($row['CONSTRAINT_NAME'] ?? '');
        $childColumn = (string) ($row['COLUMN_NAME'] ?? '');
        $parentTable = (string) ($row['REFERENCED_TABLE_NAME'] ?? '');
        $parentColumn = (string) ($row['REFERENCED_COLUMN_NAME'] ?? '');
        foreach ([$childTable, $constraint, $childColumn, $parentTable, $parentColumn] as $identifier) {
            if ($identifier === '' || preg_match('/^[A-Za-z0-9_$]+$/', $identifier) !== 1) {
                throw new LogicException('A foreign-key identifier failed validation during backup restore.');
            }
        }

        $key = $childTable . ':' . $constraint;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'child_table' => $childTable,
                'parent_table' => $parentTable,
                'child_columns' => [],
                'parent_columns' => [],
            ];
        } elseif ($groups[$key]['parent_table'] !== $parentTable) {
            throw new LogicException('A foreign-key constraint references inconsistent parent tables.');
        }
        $groups[$key]['child_columns'][] = $childColumn;
        $groups[$key]['parent_columns'][] = $parentColumn;
    }

    $quote = chr(96);
    foreach ($groups as $constraint) {
        $child = $quote . $constraint['child_table'] . $quote;
        $parent = $quote . $constraint['parent_table'] . $quote;
        $joins = [];
        $nonNull = [];
        foreach ($constraint['child_columns'] as $index => $childColumn) {
            $parentColumn = $constraint['parent_columns'][$index];
            $joins[] = 'p.' . $quote . $parentColumn . $quote . ' = c.' . $quote . $childColumn . $quote;
            $nonNull[] = 'c.' . $quote . $childColumn . $quote . ' IS NOT NULL';
        }
        if (!$joins) { continue; }

        $probeColumn = $quote . $constraint['parent_columns'][0] . $quote;
        $sql = 'SELECT COUNT(*) FROM ' . $child . ' AS c LEFT JOIN ' . $parent . ' AS p ON '
            . implode(' AND ', $joins)
            . ' WHERE ' . implode(' AND ', $nonNull)
            . ' AND p.' . $probeColumn . ' IS NULL';
        $orphanCount = (int) db_val($sql);
        if ($orphanCount > 0) {
            throw new RuntimeException(
                'Restore refused because ' . $constraint['child_table'] . '.'
                . implode(',', $constraint['child_columns']) . ' contains '
                . $orphanCount . ' row(s) with missing parent records. No database changes were committed.'
            );
        }
    }
}

/** Restore all rows atomically while retaining the current table definitions and permanent blotter cases. */
function backup_restore_apply(string $sql): array
{
    $parsed = backup_restore_parse($sql, backup_table_names());
    $pdo = db();
    $quote = chr(96);

    // FR-B5 prohibits deleting a blotter case, including through the restore feature.
    // A restore is permitted only when its snapshot contains every current case with
    // the same permanent ID. This prevents an older backup from erasing newer cases
    // or relinking their child records to a different case.
    $currentCases = db_all('SELECT blotter_id, case_no FROM blotters');
    $currentIds = [];
    foreach ($currentCases as $case) {
        $caseNo = (string) $case['case_no'];
        $caseId = (int) $case['blotter_id'];
        if (!isset($parsed['blotter_cases'][$caseNo])) {
            throw new RuntimeException(
                'Restore refused because the uploaded backup does not contain permanent blotter case '
                . $caseNo . '. FR-B5 does not allow a restore to delete newer case records. Use a backup that includes every current case.'
            );
        }
        if ($parsed['blotter_cases'][$caseNo] !== $caseId) {
            throw new RuntimeException(
                'Restore refused because permanent blotter case ' . $caseNo
                . ' has a different database ID in the uploaded backup. No records were changed.'
            );
        }
        $currentIds[$caseId] = $caseNo;
    }
    foreach ($parsed['blotter_cases'] as $caseNo => $caseId) {
        if (isset($currentIds[$caseId]) && $currentIds[$caseId] !== $caseNo) {
            throw new RuntimeException(
                'Restore refused because the uploaded backup reuses a permanent blotter ID for another case. No records were changed.'
            );
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        db_transaction(function () use ($parsed, $quote): void {
            foreach ($parsed['tables'] as $table) {
                // Never DELETE from blotters. Its current rows were checked against the
                // snapshot above and are synchronized with an upsert below.
                if ($table === 'blotters') { continue; }
                db_exec('DELETE FROM ' . $quote . $table . $quote);
            }

            foreach ($parsed['inserts'] as $statement) {
                $pattern = '/^INSERT INTO ' . preg_quote($quote . 'blotters' . $quote, '/') . '\s*\((.*?)\)\s+VALUES\s+(.+)$/is';
                if (preg_match($pattern, $statement, $m) === 1) {
                    preg_match_all('/' . preg_quote($quote, '/') . '([A-Za-z0-9_$]+)' . preg_quote($quote, '/') . '/', (string) $m[1], $matches);
                    $columns = $matches[1] ?? [];
                    $updates = [];
                    foreach ($columns as $column) {
                        if ($column === 'blotter_id') { continue; }
                        $identifier = $quote . $column . $quote;
                        $updates[] = $identifier . ' = VALUES(' . $identifier . ')';
                    }
                    if (!$updates) { throw new RuntimeException('The backup blotter rows cannot be safely restored.'); }
                    $statement .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
                }
                db()->exec($statement);
            }

            // Foreign-key checks were disabled for ordered bulk replacement. Explicitly
            // validate every relationship before commit because re-enabling the session
            // flag does not check rows that were inserted while it was disabled.
            backup_restore_assert_foreign_keys_valid();
        });
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    return ['table_count' => count($parsed['tables']), 'insert_count' => count($parsed['inserts'])];
}


/* ---------------------------------------------------------------- full-system archive backup */

const BACKUP_FULL_MAX_ARCHIVE_BYTES = 536870912;       // 512 MiB upload/download
const BACKUP_FULL_MAX_TOTAL_BYTES = 1073741824;       // 1 GiB uncompressed payload
const BACKUP_FULL_MAX_FILE_BYTES = 104857600;         // 100 MiB per private file
const BACKUP_FULL_MAX_FILES = 20000;
const BACKUP_FULL_MAX_MANIFEST_BYTES = 8388608;       // 8 MiB

function backup_full_zip_available(): bool
{
    return class_exists('ZipArchive');
}

function backup_full_require_zip(): void
{
    if (!backup_full_zip_available()) {
        throw new RuntimeException('The PHP ZIP extension is required for complete database-and-files backups. Enable extension=zip in the active XAMPP php.ini, restart Apache, and check the CLI PHP configuration too.');
    }
}

/**
 * Create a private pre-restore SQL snapshot and clean it up on every preparation failure.
 *
 * @param callable(resource):void $writeSnapshot Writes the current database to the supplied stream.
 * @return array{path:string,sql:string}
 */
function backup_full_create_safety_snapshot(callable $writeSnapshot): array
{
    $path = tempnam(sys_get_temp_dir(), 'birms-full-before-restore-');
    if ($path === false) {
        throw new RuntimeException('A pre-restore database safety snapshot could not be created.');
    }

    $out = fopen($path, 'wb');
    if ($out === false) {
        @unlink($path);
        throw new RuntimeException('The pre-restore database safety snapshot could not be opened.');
    }

    try {
        try {
            $writeSnapshot($out);
        } finally {
            if (is_resource($out)) { fclose($out); }
        }

        $size = filesize($path);
        if ($size === false || $size < 32 || $size > BACKUP_RESTORE_MAX_BYTES) {
            throw new RuntimeException('The current database is too large for the automatic pre-restore safety snapshot. Restore was not started.');
        }

        $sql = file_get_contents($path);
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('The pre-restore database safety snapshot could not be read. Restore was not started.');
        }

        return ['path' => $path, 'sql' => $sql];
    } catch (Throwable $e) {
        if (is_resource($out)) { fclose($out); }
        if (is_file($path) && !@unlink($path)) {
            error_log('BIRMS could not remove an incomplete pre-restore safety snapshot at ' . $path);
        }
        throw $e;
    }
}

/** Accept only application-owned private file paths; archive paths are never trusted as filesystem paths. */
function backup_full_relative_path_is_allowed(string $relative): bool
{
    if ($relative === '' || str_contains($relative, chr(0)) || str_contains($relative, '\\')
        || preg_match('~^storage/(?:private_uploads|resident_photos)/[A-Za-z0-9._/-]+$~D', $relative) !== 1) {
        return false;
    }
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') { return false; }
    }
    return true;
}

/** Return every private path referenced by the current database. */
function backup_full_referenced_private_paths(): array
{
    $paths = [];
    foreach (db_all("SELECT storage_path AS path FROM document_attachments WHERE storage_path IS NOT NULL AND storage_path <> ''") as $row) {
        $paths[] = (string) $row['path'];
    }
    foreach (db_all("SELECT photo_path AS path FROM residents WHERE photo_path IS NOT NULL AND photo_path <> ''") as $row) {
        $paths[] = (string) $row['path'];
    }
    foreach (db_all("SELECT setting_value AS path FROM settings WHERE setting_key = 'chairman_signature_path' AND setting_value IS NOT NULL AND setting_value <> ''") as $row) {
        $paths[] = (string) $row['path'];
    }
    foreach (db_all("SELECT chairman_signature_path AS path FROM issuances WHERE chairman_signature_path IS NOT NULL AND chairman_signature_path <> ''") as $row) {
        $paths[] = (string) $row['path'];
    }

    $unique = [];
    foreach ($paths as $relative) {
        if (!backup_full_relative_path_is_allowed($relative)) {
            throw new RuntimeException('A database record points to a file outside the approved private backup folders. Full backup was stopped.');
        }
        $unique[$relative] = true;
    }
    $result = array_keys($unique);
    sort($result, SORT_STRING);
    return $result;
}

/**
 * Walk only the two private upload roots. Symlinks and special files are refused
 * rather than silently following or omitting them.
 */
function backup_full_scan_private_files(): array
{
    $appRoot = realpath(APP_ROOT);
    if ($appRoot === false) { throw new RuntimeException('The application folder could not be resolved.'); }
    $files = [];
    $totalBytes = 0;

    foreach (['storage/private_uploads', 'storage/resident_photos'] as $relativeRoot) {
        $rootPath = APP_ROOT . '/' . $relativeRoot;
        if (!file_exists($rootPath) && !is_link($rootPath)) { continue; }
        if (is_link($rootPath) || !is_dir($rootPath)) {
            throw new RuntimeException('A private storage root is not a normal directory. Full backup was stopped.');
        }
        $realRoot = realpath($rootPath);
        if ($realRoot === false || !str_starts_with($realRoot, $appRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('A private storage root resolves outside the application folder.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            $source = $item->getPathname();
            if (is_link($source)) {
                throw new RuntimeException('A symbolic link exists in private storage. Remove or resolve it before creating a full backup.');
            }
            if ($item->isDir()) { continue; }
            if (!$item->isFile()) {
                throw new RuntimeException('A non-regular file exists in private storage. Full backup was stopped.');
            }
            $real = realpath($source);
            if ($real === false || !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('A private file resolves outside its approved storage root.');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($appRoot) + 1));
            if (!backup_full_relative_path_is_allowed($relative)) {
                throw new RuntimeException('A private file has an unsupported storage path.');
            }
            $size = filesize($real);
            if ($size === false || $size < 0 || $size > BACKUP_FULL_MAX_FILE_BYTES) {
                throw new RuntimeException('A private file exceeds the 100 MiB full-backup file limit.');
            }
            $totalBytes += $size;
            if ($totalBytes > BACKUP_FULL_MAX_TOTAL_BYTES || count($files) >= BACKUP_FULL_MAX_FILES) {
                throw new RuntimeException('Private storage exceeds the full-backup file-count or total-size limit.');
            }
            $hashBefore = hash_file('sha256', $real);
            if (!is_string($hashBefore)) { throw new RuntimeException('A private file could not be checksummed.'); }
            $files[] = ['path' => $relative, 'source' => $real, 'size' => (int) $size, 'sha256' => $hashBefore];
        }
    }

    usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    return $files;
}

/** Safely create a directory tree below a model-owned temporary folder. */
function backup_full_make_stage_parent(string $base, string $relative): string
{
    $target = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('A temporary backup folder could not be created.');
    }
    return $target;
}

/** Remove only a temporary directory created by the full backup/restore helpers. */
function backup_full_remove_stage(string $directory): void
{
    if ($directory === '' || !is_dir($directory) || is_link($directory)) { return; }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isLink() || $item->isFile()) { @unlink($path); }
        elseif ($item->isDir()) { @rmdir($path); }
    }
    @rmdir($directory);
}

/**
 * Create a ZIP containing database/backup.sql, a SHA-256 manifest, and only
 * storage/private_uploads plus storage/resident_photos.
 *
 * Run during a quiet maintenance window: the database and filesystem do not share
 * a transaction, so the operator should pause uploads/record changes while it runs.
 */
function backup_full_system_create_archive(string $archivePath): array
{
    backup_full_require_zip();
    $stage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'birms-full-backup-' . bin2hex(random_bytes(10));
    if (!mkdir($stage, 0700, true)) { throw new RuntimeException('A temporary full-backup workspace could not be created.'); }
    $zip = null;
    try {
        $sqlPath = backup_full_make_stage_parent($stage, 'database/backup.sql');
        $sqlOut = fopen($sqlPath, 'wb');
        if ($sqlOut === false) { throw new RuntimeException('The SQL snapshot could not be opened.'); }
        try {
            $tables = backup_table_names();
            if (!$tables) { throw new RuntimeException('No database tables were found.'); }
            backup_write_sql_export($sqlOut, $tables);
        } finally {
            fclose($sqlOut);
        }
        $sqlSize = filesize($sqlPath);
        if ($sqlSize === false || $sqlSize < 32 || $sqlSize > BACKUP_RESTORE_MAX_BYTES) {
            throw new RuntimeException('The SQL snapshot is empty or exceeds the 100 MiB SQL restore limit.');
        }
        $sqlHash = hash_file('sha256', $sqlPath);
        if (!is_string($sqlHash)) { throw new RuntimeException('The SQL snapshot could not be checksummed.'); }

        $files = backup_full_scan_private_files();
        $fileMap = [];
        foreach ($files as &$file) {
            $stageRelative = 'files/' . $file['path'];
            $stagedPath = backup_full_make_stage_parent($stage, $stageRelative);
            if (!copy($file['source'], $stagedPath)) {
                throw new RuntimeException('A private file could not be copied into the backup workspace.');
            }
            @chmod($stagedPath, 0600);
            $stagedSize = filesize($stagedPath);
            $stagedHash = hash_file('sha256', $stagedPath);
            $sourceHashAfter = hash_file('sha256', $file['source']);
            if ($stagedSize !== $file['size'] || !is_string($stagedHash)
                || !hash_equals($file['sha256'], $stagedHash)
                || !is_string($sourceHashAfter) || !hash_equals($file['sha256'], $sourceHashAfter)) {
                throw new RuntimeException('A private file changed while the backup was being created. Retry during a quiet period.');
            }
            $file['sha256'] = $stagedHash;
            $file['entry'] = $stageRelative;
            unset($file['source']);
            $fileMap[$file['path']] = true;
        }
        unset($file);

        foreach (backup_full_referenced_private_paths() as $referenced) {
            if (!isset($fileMap[$referenced])) {
                throw new RuntimeException('The database references a private file missing from storage: ' . basename($referenced) . '. Full backup was stopped.');
            }
        }
        backup_full_verify_database_files();

        $manifest = [
            'format' => 'BIRMS_FULL_BACKUP',
            'format_version' => 1,
            'created_at' => date(DATE_ATOM),
            'source_database' => (string) config('db.name'),
            'database' => ['entry' => 'database/backup.sql', 'size' => (int) $sqlSize, 'sha256' => $sqlHash],
            'files' => $files,
        ];
        $manifestPath = $stage . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (strlen($manifestJson) > BACKUP_FULL_MAX_MANIFEST_BYTES
            || file_put_contents($manifestPath, $manifestJson, LOCK_EX) === false) {
            throw new RuntimeException('The full-backup manifest could not be written.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) { throw new RuntimeException('The ZIP archive could not be created.'); }
        if (!$zip->addFile($sqlPath, 'database/backup.sql')) {
            throw new RuntimeException('The database snapshot could not be added to the ZIP archive.');
        }
        foreach ($files as $file) {
            $stagedPath = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file['entry']);
            if (!$zip->addFile($stagedPath, 'files/' . $file['path'])) {
                throw new RuntimeException('A private file could not be added to the ZIP archive.');
            }
        }
        if (!$zip->addFile($manifestPath, 'manifest.json')) {
            throw new RuntimeException('The full-backup manifest could not be added to the ZIP archive.');
        }
        if (method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName('database/backup.sql', ZipArchive::CM_DEFLATE, 6);
            $zip->setCompressionName('manifest.json', ZipArchive::CM_DEFLATE, 6);
        }
        if (!$zip->close()) {
            $zip = null;
            throw new RuntimeException('The ZIP archive could not be finalized.');
        }
        $zip = null;
        clearstatcache(true, $archivePath);
        $archiveSize = filesize($archivePath);
        if ($archiveSize === false || $archiveSize < 1 || $archiveSize > BACKUP_FULL_MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('The completed full backup exceeds the 512 MiB archive limit.');
        }
        @chmod($archivePath, 0600);
        return [
            'table_count' => count($tables),
            'file_count' => count($files),
            'file_bytes' => array_sum(array_column($files, 'size')),
            'archive_bytes' => (int) $archiveSize,
        ];
    } finally {
        if ($zip instanceof ZipArchive) { @$zip->close(); }
        backup_full_remove_stage($stage);
    }
}

/** Resolve a whitelisted destination under storage without following symlinks. */
function backup_full_target_details(string $relative, bool $createDirectories = false): array
{
    if (!backup_full_relative_path_is_allowed($relative)) {
        throw new InvalidArgumentException('The archive contains a file path outside approved private storage.');
    }
    $storage = APP_ROOT . '/storage';
    if (is_link($storage)) { throw new RuntimeException('The application storage folder must not be a symbolic link.'); }
    if (!is_dir($storage)) {
        if (!$createDirectories || (!mkdir($storage, 0750, true) && !is_dir($storage))) {
            throw new RuntimeException('The application storage folder is unavailable.');
        }
    }
    $storageRoot = realpath($storage);
    if ($storageRoot === false) { throw new RuntimeException('The application storage folder could not be resolved.'); }

    $segments = explode('/', substr($relative, strlen('storage/')));
    $current = $storageRoot;
    for ($i = 0; $i < count($segments) - 1; $i++) {
        $current .= DIRECTORY_SEPARATOR . $segments[$i];
        if (is_link($current)) { throw new RuntimeException('A symbolic link blocks a private restore path.'); }
        if (file_exists($current) && !is_dir($current)) {
            throw new RuntimeException('A non-directory blocks a private restore folder.');
        }
        if (!is_dir($current)) {
            if (!$createDirectories || (!mkdir($current, 0750) && !is_dir($current))) {
                if (!$createDirectories) {
                    return ['path' => $current . DIRECTORY_SEPARATOR . $segments[count($segments) - 1], 'exists' => false];
                }
                throw new RuntimeException('A private restore folder could not be created.');
            }
        }
        $resolved = realpath($current);
        if ($resolved === false || !str_starts_with($resolved, $storageRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('A private restore folder resolves outside application storage.');
        }
        $current = $resolved;
    }

    $target = $current . DIRECTORY_SEPARATOR . $segments[count($segments) - 1];
    if (is_link($target)) { throw new RuntimeException('A symbolic link blocks a private restore file.'); }
    if (file_exists($target) && !is_file($target)) {
        throw new RuntimeException('A non-file already exists at a private restore destination.');
    }
    return ['path' => $target, 'exists' => is_file($target)];
}

/** Write all bytes, handling partial fwrite results. */
function backup_full_write_stream($stream, string $bytes): void
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = fwrite($stream, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('A staged private file could not be written completely.');
        }
        $offset += $written;
    }
}

/** Validate an archive and stage its private files without using ZipArchive::extractTo(). */
function backup_full_archive_stage(string $archivePath): array
{
    backup_full_require_zip();
    if (!is_file($archivePath) || !is_readable($archivePath)) {
        throw new InvalidArgumentException('The uploaded full-system backup could not be read.');
    }
    $archiveSize = filesize($archivePath);
    if ($archiveSize === false || $archiveSize < 1 || $archiveSize > BACKUP_FULL_MAX_ARCHIVE_BYTES) {
        throw new InvalidArgumentException('The full-system ZIP must be no larger than 512 MiB.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($archivePath, ZipArchive::RDONLY);
    if ($opened !== true) { throw new InvalidArgumentException('The uploaded file is not a readable BIRMS ZIP archive.'); }
    $stage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'birms-full-restore-' . bin2hex(random_bytes(10));
    $stageCreated = false;
    try {
        if ($zip->numFiles < 2 || $zip->numFiles > BACKUP_FULL_MAX_FILES + 2) {
            throw new InvalidArgumentException('The archive has an invalid number of entries.');
        }
        $archiveEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name'], $stat['size'])) {
                throw new InvalidArgumentException('The archive contains an unreadable entry.');
            }
            $name = (string) $stat['name'];
            if ($name === '' || str_contains($name, chr(0)) || str_contains($name, '\\')
                || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)
                || str_ends_with($name, '/')) {
                throw new InvalidArgumentException('The archive contains an unsafe entry name.');
            }
            if (isset($archiveEntries[$name])) { throw new InvalidArgumentException('The archive contains duplicate entry names.'); }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                && defined('ZipArchive::OPSYS_UNIX') && $opsys === ZipArchive::OPSYS_UNIX) {
                $fileType = (((int) $attributes) >> 16) & 0170000;
                if ($fileType !== 0 && $fileType !== 0100000) {
                    throw new InvalidArgumentException('The archive contains a symbolic link or non-regular file.');
                }
            }
            $entrySize = (int) $stat['size'];
            if ($entrySize < 0 || $entrySize > BACKUP_FULL_MAX_FILE_BYTES + BACKUP_RESTORE_MAX_BYTES) {
                throw new InvalidArgumentException('An archive entry exceeds its permitted size.');
            }
            $archiveEntries[$name] = $entrySize;
        }

        $manifestSize = $archiveEntries['manifest.json'] ?? null;
        $databaseSize = $archiveEntries['database/backup.sql'] ?? null;
        if (!is_int($manifestSize) || $manifestSize < 2 || $manifestSize > BACKUP_FULL_MAX_MANIFEST_BYTES
            || !is_int($databaseSize) || $databaseSize < 32 || $databaseSize > BACKUP_RESTORE_MAX_BYTES) {
            throw new InvalidArgumentException('The archive is missing its manifest or has an invalid SQL snapshot.');
        }
        $manifestJson = $zip->getFromName('manifest.json');
        $sql = $zip->getFromName('database/backup.sql');
        if (!is_string($manifestJson) || !is_string($sql)
            || strlen($manifestJson) !== $manifestSize || strlen($sql) !== $databaseSize) {
            throw new InvalidArgumentException('The archive manifest or SQL snapshot could not be read completely.');
        }
        try { $manifest = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new InvalidArgumentException('The archive manifest is not valid JSON.'); }
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'BIRMS_FULL_BACKUP'
            || ($manifest['format_version'] ?? null) !== 1
            || !is_array($manifest['database'] ?? null) || !is_array($manifest['files'] ?? null)) {
            throw new InvalidArgumentException('The archive is not a supported BIRMS full-system backup.');
        }
        $dbInfo = $manifest['database'];
        if (($dbInfo['entry'] ?? null) !== 'database/backup.sql'
            || (int) ($dbInfo['size'] ?? -1) !== $databaseSize
            || !is_string($dbInfo['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $dbInfo['sha256']) !== 1
            || !hash_equals($dbInfo['sha256'], hash('sha256', $sql))) {
            throw new InvalidArgumentException('The SQL snapshot checksum or size does not match the manifest.');
        }
        if (count($manifest['files']) > BACKUP_FULL_MAX_FILES) {
            throw new InvalidArgumentException('The manifest contains too many private files.');
        }

        $expectedEntries = ['manifest.json' => true, 'database/backup.sql' => true];
        $seenPaths = [];
        $totalBytes = $databaseSize + $manifestSize;
        $normalizedFiles = [];
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)
                || !is_int($file['size'] ?? null) || $file['size'] < 0 || $file['size'] > BACKUP_FULL_MAX_FILE_BYTES
                || !is_string($file['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $file['sha256']) !== 1
                || !backup_full_relative_path_is_allowed($file['path'])) {
                throw new InvalidArgumentException('The manifest contains an invalid private-file record.');
            }
            $relative = $file['path'];
            if (isset($seenPaths[$relative])) { throw new InvalidArgumentException('The manifest contains duplicate private-file paths.'); }
            $seenPaths[$relative] = true;
            $entry = 'files/' . $relative;
            if (($file['entry'] ?? null) !== $entry || ($archiveEntries[$entry] ?? null) !== $file['size']) {
                throw new InvalidArgumentException('A private-file entry is missing or has a different size than the manifest.');
            }
            $expectedEntries[$entry] = true;
            $totalBytes += $file['size'];
            if ($totalBytes > BACKUP_FULL_MAX_TOTAL_BYTES) {
                throw new InvalidArgumentException('The uncompressed archive payload exceeds the 1 GiB limit.');
            }
            $normalizedFiles[] = ['path' => $relative, 'entry' => $entry, 'size' => $file['size'], 'sha256' => $file['sha256']];
        }
        if (count($archiveEntries) !== count($expectedEntries)) {
            throw new InvalidArgumentException('The archive contains files not listed in its manifest.');
        }
        foreach ($archiveEntries as $name => $_size) {
            if (!isset($expectedEntries[$name])) { throw new InvalidArgumentException('The archive contains an unrecognized entry.'); }
        }

        // Validate the SQL against the currently installed schema before staging files or mutating data.
        backup_restore_parse($sql, backup_table_names());

        if (!mkdir($stage, 0700, true)) { throw new RuntimeException('A temporary restore workspace could not be created.'); }
        $stageCreated = true;
        foreach ($normalizedFiles as $file) {
            $target = backup_full_make_stage_parent($stage, 'files/' . $file['path']);
            $input = $zip->getStream($file['entry']);
            if ($input === false) { throw new InvalidArgumentException('A private archive file could not be opened.'); }
            $output = fopen($target, 'xb');
            if ($output === false) { fclose($input); throw new RuntimeException('A staged private file could not be created.'); }
            $hash = hash_init('sha256');
            $written = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 1024 * 1024);
                    if ($chunk === false) { throw new RuntimeException('A private archive file could not be read.'); }
                    if ($chunk === '') { break; }
                    $written += strlen($chunk);
                    if ($written > $file['size']) { throw new InvalidArgumentException('A private archive file exceeds its declared size.'); }
                    hash_update($hash, $chunk);
                    backup_full_write_stream($output, $chunk);
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($written !== $file['size'] || !hash_equals($file['sha256'], hash_final($hash))) {
                throw new InvalidArgumentException('A private archive file failed its size or SHA-256 check.');
            }
            @chmod($target, 0600);
        }

        return ['sql' => $sql, 'files' => $normalizedFiles, 'staging_dir' => $stage];
    } catch (Throwable $e) {
        if ($stageCreated) { backup_full_remove_stage($stage); }
        throw $e;
    } finally {
        $zip->close();
    }
}

/** Verify restored DB references point to files that exist and match stored attachment hashes. */
function backup_full_verify_database_files(): void
{
    foreach (backup_full_referenced_private_paths() as $relative) {
        $target = backup_full_target_details($relative, false);
        if (!$target['exists']) {
            throw new RuntimeException('The database references a private file that is not present in the archive or storage: ' . basename($relative) . '.');
        }
    }

    if (!function_exists('finfo_open')) {
        throw new RuntimeException('The PHP fileinfo extension is required to verify private files during a full backup or restore.');
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) { throw new RuntimeException('Private file MIME verification could not be initialized.'); }
    try {
        foreach (db_all("SELECT storage_path, sha256, mime_type FROM document_attachments WHERE storage_path IS NOT NULL AND storage_path <> ''") as $row) {
            $relative = (string) $row['storage_path'];
            $target = backup_full_target_details($relative, false);
            $actualHash = $target['exists'] ? hash_file('sha256', $target['path']) : false;
            if (!is_string($actualHash) || !is_string($row['sha256'] ?? null)
                || !hash_equals((string) $row['sha256'], $actualHash)) {
                throw new RuntimeException('A document attachment failed its stored SHA-256 check: ' . basename($relative) . '.');
            }
            $actualMime = finfo_file($finfo, $target['path']);
            if (!is_string($actualMime) || !hash_equals((string) $row['mime_type'], $actualMime)) {
                throw new RuntimeException('A document attachment failed its stored MIME-type check: ' . basename($relative) . '.');
            }
        }

        foreach (db_all("SELECT photo_path FROM residents WHERE photo_path IS NOT NULL AND photo_path <> ''") as $row) {
            $relative = (string) $row['photo_path'];
            $target = backup_full_target_details($relative, false);
            $image = $target['exists'] ? @getimagesize($target['path']) : false;
            if (!is_array($image) || !in_array((string) ($image['mime'] ?? ''), ['image/jpeg', 'image/png'], true)) {
                throw new RuntimeException('A resident photo is missing or is not a valid JPEG/PNG image: ' . basename($relative) . '.');
            }
        }
    } finally {
        finfo_close($finfo);
    }

    $signaturePaths = [];
    $currentSignature = chairman_signature_current_relative();
    if (is_string($currentSignature) && $currentSignature !== '') { $signaturePaths[$currentSignature] = true; }
    foreach (db_all("SELECT chairman_signature_path FROM issuances WHERE chairman_signature_path IS NOT NULL AND chairman_signature_path <> ''") as $row) {
        $signaturePaths[(string) $row['chairman_signature_path']] = true;
    }
    foreach (array_keys($signaturePaths) as $relative) {
        if (chairman_signature_read($relative) === null) {
            throw new RuntimeException('A Chairman signature image is missing or invalid: ' . basename($relative) . '.');
        }
    }
}

/**
 * Restore a staged full archive. Files are overlaid, never bulk-deleted or overwritten:
 * this keeps unrelated private assets safe. The pre-restore SQL snapshot is reapplied
 * if installing files or verifying database references fails after the database restore.
 */
function backup_full_system_restore_staged(array $staged): array
{
    $sql = $staged['sql'] ?? null;
    $files = $staged['files'] ?? null;
    $stageDir = $staged['staging_dir'] ?? null;
    if (!is_string($sql) || !is_array($files) || !is_string($stageDir) || !is_dir($stageDir)) {
        throw new InvalidArgumentException('The staged full-system backup is incomplete.');
    }
    backup_restore_parse($sql, backup_table_names());

    $createdFiles = [];
    $databaseRestoreStarted = false;
    $preserveSafety = false;
    $safetySnapshot = backup_full_create_safety_snapshot(
        static function ($stream): void {
            backup_write_sql_export($stream, backup_table_names());
        }
    );
    $safetyPath = $safetySnapshot['path'];
    $safetySql = $safetySnapshot['sql'];

    try {
        // Preflight conflicts before changing the database. Existing identical files are safe to reuse.
        foreach ($files as $file) {
            $target = backup_full_target_details((string) $file['path'], false);
            if ($target['exists']) {
                $existingHash = hash_file('sha256', $target['path']);
                if (!is_string($existingHash) || !hash_equals((string) $file['sha256'], $existingHash)) {
                    throw new RuntimeException('A different private file already exists at the restore destination: ' . basename((string) $file['path']) . '. No data was changed.');
                }
            }
        }

        $databaseRestoreStarted = true;
        backup_restore_apply($sql);

        foreach ($files as $file) {
            $target = backup_full_target_details((string) $file['path'], true);
            if ($target['exists']) { continue; }
            $source = $stageDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, (string) $file['path']);
            $input = fopen($source, 'rb');
            if ($input === false) { throw new RuntimeException('A staged private restore file could not be opened.'); }
            $output = @fopen($target['path'], 'xb');
            if ($output === false) { fclose($input); throw new RuntimeException('A private restore destination could not be created safely.'); }
            $createdFiles[] = $target['path'];
            try {
                if (stream_copy_to_stream($input, $output) !== (int) $file['size']) {
                    throw new RuntimeException('A private restore file was copied incompletely.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            @chmod($target['path'], 0640);
            $installedHash = hash_file('sha256', $target['path']);
            if (!is_string($installedHash) || !hash_equals((string) $file['sha256'], $installedHash)) {
                throw new RuntimeException('A private restore file failed its post-copy SHA-256 check.');
            }
        }

        backup_full_verify_database_files();
        return [
            'table_count' => count(backup_table_names()),
            'file_count' => count($files),
            'file_count_installed' => count($createdFiles),
        ];
    } catch (Throwable $e) {
        $recoveryFailed = false;
        if ($databaseRestoreStarted) {
            try {
                backup_restore_apply($safetySql);
            } catch (Throwable $recoveryError) {
                $recoveryFailed = true;
                $preserveSafety = true;
                error_log('BIRMS full restore database recovery failed. Preserve safety snapshot at '
                    . $safetyPath . '. Original restore error: ' . $e->getMessage()
                    . '; recovery error: ' . $recoveryError->getMessage());
            }
        }
        if (!$recoveryFailed) {
            foreach ($createdFiles as $createdPath) { @unlink($createdPath); }
            @unlink($safetyPath);
        }
        if ($recoveryFailed) {
            throw new RuntimeException('Full restore failed and automatic database recovery could not be confirmed. Preserve the server safety snapshot file ' . basename($safetyPath) . ' and contact the administrator immediately.');
        }
        throw $e;
    } finally {
        // The SQL snapshot contains confidential resident records and must not be retained after success/recovery.
        if (!$preserveSafety && is_file($safetyPath)) { @unlink($safetyPath); }
    }
}

/** Validate and restore an uploaded full-system archive, always removing its temporary staging folder. */
function backup_full_system_restore_archive(string $archivePath): array
{
    $staged = backup_full_archive_stage($archivePath);
    try {
        return backup_full_system_restore_staged($staged);
    } finally {
        backup_full_remove_stage((string) $staged['staging_dir']);
    }
}
