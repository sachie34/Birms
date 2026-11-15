<?php
/** Shared resident helpers: names, direct Address/Purok fields, SRS filters. */
declare(strict_types=1);

function resident_name(array $r): string
{
    $n = $r['last_name'] . ', ' . $r['first_name'];
    if (!empty($r['middle_name'])) { $n .= ' ' . $r['middle_name']; }
    if (!empty($r['suffix'])) { $n .= ' ' . $r['suffix']; }
    return $n;
}
function resident_name_natural(array $r): string
{
    return trim($r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name'] . ' ' . ($r['suffix'] ?? ''));
}

function resident_filters(array $src, bool $canSeeArchived): array
{
    $status = input_str($src, 'status');
    $validStatuses = array_values(lookup_list('resident_status', false));
    $type = input_int($src, 'type') ?? input_int($src, 'category');
    return [
        'q' => input_str($src, 'q'),
        'purok' => (string) (input_int($src, 'purok') ?? ''),
        'status' => in_array($status, $validStatuses, true) ? $status : '',
        'type' => (string) ($type ?? ''),
        'show' => ($canSeeArchived && input_str($src, 'show') === 'archived') ? 'archived' : '',
    ];
}

/** @return array{0:string,1:array} FROM/WHERE fragment and parameters. Household joins are read-only fallbacks for old rows. */
function resident_query_parts(array $f): array
{
    $from = "FROM residents r
             LEFT JOIN households h ON h.household_id = r.household_id
             LEFT JOIN lookups pk ON pk.lookup_id = COALESCE(r.purok_id, h.purok_id) AND pk.list_key = 'purok'
             LEFT JOIN lookups rs ON rs.lookup_id = r.resident_status_id AND rs.list_key = 'resident_status'
             LEFT JOIN lookups rt ON rt.lookup_id = r.resident_type_id AND rt.list_key = 'resident_type'
             LEFT JOIN lookups oldcat ON oldcat.lookup_id = r.category_id AND oldcat.list_key = 'resident_category'";
    $w = [$f['show'] === 'archived' ? 'r.archived_at IS NOT NULL' : 'r.archived_at IS NULL'];
    $p = [];
    foreach (preg_split('/[\s,]+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
        $like = '%' . addcslashes($tok, '%_\\') . '%';
        $w[] = '(r.last_name LIKE ? OR r.first_name LIKE ? OR r.middle_name LIKE ? OR r.resident_no LIKE ? OR COALESCE(r.address, h.street_address, \'\') LIKE ? OR COALESCE(r.email_address, \'\') LIKE ?)';
        array_push($p, $like, $like, $like, $like, $like, $like);
    }
    if ($f['purok'] !== '') {
        $w[] = 'COALESCE(r.purok_id, h.purok_id) = ?';
        $p[] = (int) $f['purok'];
    }
    if ($f['status'] !== '') {
        $legacy = $f['status'] === 'Active' ? 'Active' : ($f['status'] === 'Transferred' ? 'Relocated' : null);
        if ($legacy !== null) {
            $w[] = '(rs.label = ? OR (r.resident_status_id IS NULL AND r.residency_status = ?))';
            array_push($p, $f['status'], $legacy);
        } else {
            $w[] = 'rs.label = ?';
            $p[] = $f['status'];
        }
    }
    if ($f['type'] !== '') {
        $w[] = 'r.resident_type_id = ?';
        $p[] = (int) $f['type'];
    }
    return [$from . ' WHERE ' . implode(' AND ', $w), $p];
}

function resident_search(array $f, int $limit, int $offset): array
{
    [$q, $p] = resident_query_parts($f);
    $total = (int) db_val('SELECT COUNT(*) ' . $q, $p);
    $rows = db_all('SELECT r.*, h.household_code, COALESCE(r.address, h.street_address) AS address, pk.label AS purok,
                           COALESCE(rs.label, CASE WHEN r.residency_status = \'Relocated\' THEN \'Transferred\' ELSE r.residency_status END) AS resident_status,
                           COALESCE(rt.label, oldcat.label) AS resident_type
                    ' . $q . ' ORDER BY r.last_name, r.first_name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $p);
    return [$rows, $total];
}

/** Retained only for legacy migration/support pages; the revised resident form does not use households. */
function households_for_select(): array
{
    $out = [];
    foreach (db_all('SELECT h.household_id, h.household_code, h.street_address, pk.label AS purok FROM households h LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id ORDER BY h.household_code') as $h) {
        $out[(int) $h['household_id']] = $h['household_code'] . ' · ' . $h['street_address'] . ($h['purok'] ? ' · ' . $h['purok'] : '');
    }
    return $out;
}
