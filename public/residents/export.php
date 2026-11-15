<?php
/** CSV export of revised resident directory fields; respects filters and neutralizes formulas. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.export');
$f = resident_filters($_GET, can('residents.archive'));
[$q, $p] = resident_query_parts($f);
$rows = db_all(
    "SELECT r.*,
            COALESCE(r.address, h.street_address) AS resident_address,
            pk.label AS purok,
            COALESCE(rs.label, CASE WHEN r.residency_status = 'Relocated' THEN 'Transferred' ELSE r.residency_status END) AS resident_status,
            COALESCE(rt.label, oldcat.label) AS resident_type,
            COALESCE(NULLIF(r.marital_status, ''), r.civil_status) AS display_marital_status
     " . $q . " ORDER BY r.last_name, r.first_name LIMIT 20000", $p
);
audit('resident_exported', 'Residents', 'Success', count($rows) . ' residents exported', 'resident', null);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="residents_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Resident ID','Last name','First name','Middle name','Suffix','Birthdate','Age','Sex','Marital status','Address','Purok','Resident status','Resident type','Mobile number','Email address','Notification preference']);
foreach ($rows as $r) {
    fputcsv($out, array_map('csv_safe_cell', [
        $r['resident_no'], $r['last_name'], $r['first_name'], $r['middle_name'], $r['suffix'],
        $r['birthdate'], age_on((string) $r['birthdate']), $r['sex'], $r['display_marital_status'],
        $r['resident_address'], $r['purok'], $r['resident_status'], $r['resident_type'],
        $r['contact_number'], $r['email_address'], $r['notification_preference'],
    ]));
}
fclose($out);
