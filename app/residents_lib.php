<?php
/** Shared resident helpers: names, search, filters. */
declare(strict_types=1);

function resident_name(array $r): string
{
    $n = $r['last_name'] . ', ' . $r['first_name'];
    if (!empty($r['middle_name'])) { $n .= ' ' . $r['middle_name']; }
    if (!empty($r['suffix'])) { $n .= ' ' . $r['suffix']; }
    return $n;
}
function resident_name_natural(array $r): string   // for certificates: "Juan S. Dela Cruz Jr."
{
    return trim($r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name'] . ' ' . ($r['suffix'] ?? ''));
}

function resident_filters(array $src, bool $canSeeArchived): array
{
    $status = input_str($src, 'status');
    return [
        'q' => input_str($src, 'q'),
        'purok' => (string) (input_int($src, 'purok') ?? ''),
        'status' => in_array($status, ['Active', 'Relocated'], true) ? $status : '',
        'category' => (string) (input_int($src, 'category') ?? ''),
        'show' => ($canSeeArchived && input_str($src, 'show') === 'archived') ? 'archived' : '',   // enforced on the server
    ];
}
/** @return array{0:string,1:array} FROM/WHERE fragment and parameters */
function resident_query_parts(array $f): array
{
    $from = 'FROM residents r LEFT JOIN households h ON h.household_id = r.household_id
             LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id LEFT JOIN lookups cat ON cat.lookup_id = r.category_id';
    $w = [$f['show'] === 'archived' ? 'r.archived_at IS NOT NULL' : 'r.archived_at IS NULL']; $p = [];
    foreach (preg_split('/[\s,]+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
        $like = '%' . addcslashes($tok, '%_\\') . '%';
        $w[] = '(r.last_name LIKE ? OR r.first_name LIKE ? OR r.middle_name LIKE ? OR h.household_code LIKE ?)';
        array_push($p, $like, $like, $like, $like);
    }
    if ($f['purok'] !== '')    { $w[] = 'h.purok_id = ?'; $p[] = (int) $f['purok']; }
    if ($f['status'] !== '')   { $w[] = 'r.residency_status = ?'; $p[] = $f['status']; }
    if ($f['category'] !== '') { $w[] = 'r.category_id = ?'; $p[] = (int) $f['category']; }
    return [$from . ' WHERE ' . implode(' AND ', $w), $p];
}
function resident_search(array $f, int $limit, int $offset): array
{
    [$q, $p] = resident_query_parts($f);
    $total = (int) db_val('SELECT COUNT(*) ' . $q, $p);
    $rows = db_all('SELECT r.*, h.household_code, h.street_address, pk.label AS purok, cat.label AS category ' . $q
        . ' ORDER BY r.last_name, r.first_name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $p);
    return [$rows, $total];
}
function households_for_select(): array
{
    $out = [];
    foreach (db_all('SELECT h.household_id, h.household_code, h.street_address, pk.label AS purok FROM households h LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id ORDER BY h.household_code') as $h) {
        $out[(int) $h['household_id']] = $h['household_code'] . ' · ' . $h['street_address'] . ($h['purok'] ? ' · ' . $h['purok'] : '');
    }
    return $out;
}
