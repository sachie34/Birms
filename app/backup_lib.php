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
