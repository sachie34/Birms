<?php
/** Shared filter builder for the audit trail page and its CSV export. */
declare(strict_types=1);

function audit_valid_date(string $v): ?string
{
    if ($v === '') { return null; }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** @return array{0:string,1:array,2:array} WHERE clause, parameters, cleaned filter values */
function audit_build_query(array $src): array
{
    $f = [
        'from'   => audit_valid_date(input_str($src, 'from')) ?? '',
        'to'     => audit_valid_date(input_str($src, 'to')) ?? '',
        'user'   => (string) (input_int($src, 'user') ?? ''),
        'module' => input_str($src, 'module'),
        'status' => in_array(input_str($src, 'status'), ['Success', 'Failed'], true) ? input_str($src, 'status') : '',
        'q'      => input_str($src, 'q'),
    ];
    $w = []; $p = [];
    if ($f['from'] !== '')   { $w[] = 'a.occurred_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '')     { $w[] = 'a.occurred_at < (? + INTERVAL 1 DAY)'; $p[] = $f['to'] . ' 00:00:00'; }
    if ($f['user'] !== '')   { $w[] = 'a.user_id = ?'; $p[] = (int) $f['user']; }
    if ($f['module'] !== '') { $w[] = 'a.module = ?'; $p[] = $f['module']; }
    if ($f['status'] !== '') { $w[] = 'a.status = ?'; $p[] = $f['status']; }
    if ($f['q'] !== '')      { $w[] = '(a.action LIKE ? OR a.remarks LIKE ? OR a.username_snapshot LIKE ?)'; $like = '%' . addcslashes($f['q'], '%_\\') . '%'; array_push($p, $like, $like, $like); }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p, $f];
}
