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
    if (preg_match('/^[A-Za-z0-9_-]+\.sql$/', $filename) !== 1) {
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
        CURLOPT_HTTPHEADER => ['Content-Type: application/sql', 'Expect:'],
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
        });
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    return ['table_count' => count($parsed['tables']), 'insert_count' => count($parsed['inserts'])];
}
