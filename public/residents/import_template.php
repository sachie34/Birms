<?php
/** Download the exact column header required by the resident CSV importer. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.add');
audit('resident_import_template_downloaded', 'Residents', 'Success', 'Downloaded resident CSV import template');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="birms_resident_import_template.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, resident_import_headers());
fclose($out);
