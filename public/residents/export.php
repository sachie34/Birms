<?php
/** CSV export of residents (respects the current filters). The export is audited. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.export');
$f = resident_filters($_GET, can('residents.archive'));
[$q, $p] = resident_query_parts($f);
$rows = db_all('SELECT r.*, h.household_code, h.street_address, pk.label AS purok, cat.label AS category ' . $q . ' ORDER BY r.last_name, r.first_name LIMIT 20000', $p);
audit('resident_exported', 'Residents', 'Success', count($rows) . ' residents exported', 'resident', null);

$safe = fn($s): string => ($s !== null && $s !== '' && strpos('=+-@', (string) $s[0]) !== false) ? "'" . $s : (string) $s;   // spreadsheet formula guard
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="residents_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Last name', 'First name', 'Middle name', 'Suffix', 'Birthdate', 'Age', 'Sex', 'Civil status', 'Contact', 'Household', 'Purok', 'Address', 'Category', 'Residency', 'Voter status']);
foreach ($rows as $r) {
    fputcsv($out, [$safe($r['last_name']), $safe($r['first_name']), $safe($r['middle_name']), $safe($r['suffix']), $r['birthdate'], age_on((string) $r['birthdate']), $r['sex'], $r['civil_status'],
        $safe($r['contact_number']), $safe($r['household_code']), $safe($r['purok']), $safe($r['street_address']), $safe($r['category']), $r['residency_status'], $r['voter_status']]);
}
fclose($out);
