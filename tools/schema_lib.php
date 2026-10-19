<?php
/** Shared by schema_dump.php and schema_check.php. READ-ONLY: only SELECTs on information_schema. */
declare(strict_types=1);

function schema_norm_type(string $t): string
{
    $t = strtolower(trim($t));
    return (string) preg_replace('/\b(bigint|int|smallint|mediumint)\(\d+\)/', '$1', $t);   // MariaDB vs MySQL 8 display widths
}
function schema_norm_default(?string $d): string
{
    if ($d === null) { return 'NULL'; }
    $d = strtolower(trim($d, "'\" "));
    return str_replace('current_timestamp()', 'current_timestamp', $d);
}
function schema_norm_extra(string $e): string
{
    $e = strtolower($e);
    $e = str_replace(['default_generated', 'current_timestamp()'], ['', 'current_timestamp'], $e);
    return trim((string) preg_replace('/\s+/', ' ', $e));
}

/** @return array<string,array> table => [columns, indexes, fks] (names lower-cased) */
function schema_read(PDO $pdo, string $db): array
{
    $out = [];
    $q = fn(string $sql, array $p) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); })();
    foreach ($q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$db]) as $t) {
        $name = strtolower((string) $t['TABLE_NAME']);
        if ($name === 'schema_migrations') { continue; }
        $out[$name] = ['columns' => [], 'indexes' => [], 'fks' => []];
    }
    foreach ($q('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$db]) as $c) {
        $t = strtolower((string) $c['TABLE_NAME']); if (!isset($out[$t])) { continue; }
        $out[$t]['columns'][strtolower((string) $c['COLUMN_NAME'])] = [
            'type' => schema_norm_type((string) $c['COLUMN_TYPE']), 'nullable' => $c['IS_NULLABLE'] === 'YES',
            'default' => schema_norm_default($c['COLUMN_DEFAULT'] === null ? null : (string) $c['COLUMN_DEFAULT']), 'extra' => schema_norm_extra((string) $c['EXTRA']),
        ];
    }
    foreach ($q('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', [$db]) as $i) {
        $t = strtolower((string) $i['TABLE_NAME']); if (!isset($out[$t])) { continue; }
        $n = strtolower((string) $i['INDEX_NAME']);
        $out[$t]['indexes'][$n]['unique'] = (int) $i['NON_UNIQUE'] === 0;
        $out[$t]['indexes'][$n]['columns'][] = strtolower((string) $i['COLUMN_NAME']);
    }
    foreach ($q('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [$db]) as $f) {
        $t = strtolower((string) $f['TABLE_NAME']); if (!isset($out[$t])) { continue; }
        $n = strtolower((string) $f['CONSTRAINT_NAME']);
        $out[$t]['fks'][$n]['columns'][] = strtolower((string) $f['COLUMN_NAME']);
        $out[$t]['fks'][$n]['ref'] = strtolower((string) $f['REFERENCED_TABLE_NAME']) . '(' . strtolower((string) $f['REFERENCED_COLUMN_NAME']) . ')';
        $out[$t]['fks'][$n]['on_delete'] = strtoupper((string) $f['DELETE_RULE']);
        $out[$t]['fks'][$n]['on_update'] = strtoupper((string) $f['UPDATE_RULE']);
    }
    ksort($out);
    return $out;
}

function schema_connect(string $db): PDO
{
    $c = config('db');
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $db), (string) $c['user'], (string) $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
