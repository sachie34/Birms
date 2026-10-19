<?php
/** CSV export of issuances (Captain, Secretary). Audited. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('reports.export');
require __DIR__ . '/history.php';          // reuses the filter functions only (it returns early)
$f = history_filters($_GET); [$where, $p] = history_where($f);
$rows = db_all("SELECT i.reference_no, i.resident_name, i.type_name, i.purpose, i.issued_at, i.issued_by_name, i.fee_amount, i.or_number FROM issuances i $where ORDER BY i.issued_at DESC LIMIT 20000", $p);
audit('issuances_exported', 'Certificates', 'Success', count($rows) . ' rows exported');
$safe = fn($s): string => ($s !== null && $s !== '' && strpos('=+-@', (string) $s[0]) !== false) ? "'" . $s : (string) $s;
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="issuances_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Reference', 'Resident', 'Document', 'Purpose', 'Date issued', 'Issued by', 'Fee', 'OR number']);
foreach ($rows as $r) { fputcsv($out, [$r['reference_no'], $safe($r['resident_name']), $safe($r['type_name']), $safe($r['purpose']), $r['issued_at'], $safe($r['issued_by_name']), $r['fee_amount'], $safe($r['or_number'])]); }
fclose($out);
