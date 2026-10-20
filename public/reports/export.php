<?php
/** CSV export for B7 reports. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/reports_lib.php';

require_login();
require_permission('reports.export');
$p = report_period($_GET);
$d = report_data($p);
audit('reports_exported', 'Reports', 'Success', 'Period ' . report_period_label($p));

function report_csv_safe(mixed $value): string
{
    $s = (string) ($value ?? '');
    return $s !== '' && strpos('=+-@', $s[0]) !== false ? "'" . $s : $s;
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="birms_report_' . preg_replace('/[^a-z0-9_-]/i', '_', report_period_label($p)) . '.csv"');
$out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['BIRMS report', report_period_label($p)]);
fputcsv($out, []);
fputcsv($out, ['Demographic breakdown']);
fputcsv($out, ['Age bracket', 'Residents']); foreach ($d['age'] as $k => $v) { fputcsv($out, [report_csv_safe($k), $v]); }
fputcsv($out, ['Sex', 'Residents']); foreach ($d['sex'] as $k => $v) { fputcsv($out, [report_csv_safe($k), $v]); }
fputcsv($out, ['Civil status', 'Residents']); foreach ($d['civil'] as $k => $v) { fputcsv($out, [report_csv_safe($k), $v]); }
fputcsv($out, ['Voter status field', 'Residents']); foreach ($d['voter'] as $k => $v) { fputcsv($out, [report_csv_safe($k), $v]); }
fputcsv($out, []);
fputcsv($out, ['Issuance summary']); fputcsv($out, ['Document', 'Issued', 'Fees']); foreach ($d['issuances'] as $r) { fputcsv($out, [report_csv_safe($r['type_name']), $r['total'], $r['fees']]); }
fputcsv($out, ['Total', $d['issuance_total'], $d['fees_total']]);
fputcsv($out, []);
fputcsv($out, ['Request status summary']); fputcsv($out, ['Status', 'Requests']); foreach ($d['requests'] as $r) { fputcsv($out, [report_csv_safe($r['status']), $r['total']]); }
fputcsv($out, []);
fputcsv($out, ['Blotter summary by Purok']); fputcsv($out, ['Purok', 'Cases']); foreach ($d['puroks'] as $r) { fputcsv($out, [report_csv_safe($r['purok']), $r['total']]); }
fclose($out);
