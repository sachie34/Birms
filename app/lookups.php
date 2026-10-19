<?php
/** Settings, admin-managed lists, running numbers. */
declare(strict_types=1);

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) { $cache[$r['setting_key']] = $r['setting_value']; }
    }
    $v = $cache[$key] ?? null;
    return ($v !== null && $v !== '') ? (string) $v : $default;
}
function setting_set(string $key, ?string $value): void
{
    db_exec('INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
        [$key, $value === '' ? null : $value, audit_actor()['user_id'] ?? null]);
}

const LOOKUP_LISTS = [
    'purok'             => 'Puroks',
    'resident_category' => 'Resident categories',
    'visit_class'       => 'Visit classifications',
    'id_type'           => 'Government ID types',
];

/** @return array<int,string> id => label */
function lookup_list(string $key, bool $activeOnly = true): array
{
    $rows = db_all('SELECT lookup_id, label FROM lookups WHERE list_key = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, label', [$key]);
    $out = [];
    foreach ($rows as $r) { $out[(int) $r['lookup_id']] = (string) $r['label']; }
    return $out;
}
function lookup_label(?int $id): string
{
    return $id === null ? '' : (string) db_val('SELECT label FROM lookups WHERE lookup_id = ?', [$id]);
}

/** Next running number for a name and year (safe when two people save at the same moment). */
function next_counter(string $name, int $year): int
{
    db_exec('INSERT INTO counters (counter_name, counter_year, last_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)', [$name, $year]);
    return (int) db()->lastInsertId();
}

function barangay_title(): string { return 'Barangay ' . (string) setting('barangay_name', 'Wawa'); }

/** "0-17,18-59,60-200" => [[0,17,'0-17'],[18,59,'18-59'],[60,200,'60+']] */
function age_brackets(): array
{
    $out = [];
    foreach (explode(',', (string) setting('age_brackets', '0-17,18-59,60-200')) as $p) {
        if (preg_match('/^(\d{1,3})-(\d{1,3})$/', trim($p), $m)) {
            $lo = (int) $m[1]; $hi = (int) $m[2];
            $out[] = [$lo, $hi, $hi >= 120 ? $lo . '+' : $lo . '-' . $hi];
        }
    }
    return $out;
}
function v_age_brackets(string $s): bool
{
    if (!preg_match('/^\d{1,3}-\d{1,3}(,\d{1,3}-\d{1,3})*$/', $s)) { return false; }
    $prev = -1;
    foreach (explode(',', $s) as $p) {
        [$lo, $hi] = array_map('intval', explode('-', $p));
        if ($lo > $hi || $lo <= $prev || $hi > 200) { return false; }
        $prev = $hi;
    }
    return true;
}
function age_on(string $birthdate, ?string $today = null): int
{
    $b = new DateTimeImmutable($birthdate); $t = new DateTimeImmutable($today ?? 'today');
    return (int) $b->diff($t)->y;
}
function v_money(string $s): bool { return preg_match('/^\d{1,6}(\.\d{1,2})?$/', $s) === 1; }
function money(null|string|float $v): string { return $v === null || $v === '' ? 'not set' : '₱' . number_format((float) $v, 2); }
function v_date(string $s): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}
function v_datetime_local(string $s): ?string   // "2026-10-13T09:30" => "2026-10-13 09:30:00"
{
    $d = DateTime::createFromFormat('!Y-m-d\TH:i', $s);
    return ($d !== false && $d->format('Y-m-d\TH:i') === $s) ? $d->format('Y-m-d H:i:s') : null;
}

/* Placeholders allowed inside certificate wording. Wording itself is NOT shipped: the Secretary enters the official text. */
const TEMPLATE_TOKENS = ['full_name', 'age', 'civil_status', 'address', 'purok', 'purpose', 'date_issued', 'barangay', 'municipality', 'province', 'reference_no', 'captain_name', 'document_name', 'day_ordinal', 'month_year'];
const SMS_TOKENS = ['reference_no', 'document_name'];

function template_unknown_tokens(string $text, array $allowed): array
{
    preg_match_all('/\{\{([^{}]*)\}\}/', $text, $m);
    return array_values(array_unique(array_filter($m[1], fn($t) => !in_array($t, $allowed, true))));
}
function render_template(string $body, array $vars): string
{
    return (string) preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn($m) => (string) ($vars[$m[1]] ?? ''), $body);
}
