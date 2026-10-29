<?php
/** CSV export of the audit trail (Administrator only). The export itself is audited. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/audit_query.php';

require_login();
require_permission('audit.export');

[$where, $params] = audit_build_query($_GET);
$rows = db_all("SELECT a.occurred_at, a.username_snapshot, a.action, a.module, a.status, a.remarks, a.ip_address FROM audit_log a $where ORDER BY a.audit_id DESC LIMIT 20000", $params);
audit('audit_exported', 'Audit', 'Success', count($rows) . ' rows exported');

// Spreadsheet formula protection is shared across all exports via csv_safe_cell().

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit_trail_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['When', 'User', 'Action', 'Module', 'Result', 'Remarks', 'IP']);
foreach ($rows as $r) {
    fputcsv($out, array_map('csv_safe_cell', [$r['occurred_at'], $r['username_snapshot'], $r['action'], $r['module'], $r['status'], $r['remarks'], $r['ip_address']]));
}
fclose($out);
