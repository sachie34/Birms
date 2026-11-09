<?php
/** CSV export of the audit trail (Administrator only). The export itself is audited. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/audit_query.php';

require_login();
require_permission('audit.export');

[$where, $params] = audit_build_query($_GET);
$rows = db_all("SELECT a.occurred_at, a.username_snapshot, a.full_name_snapshot, a.role_snapshot, a.action, a.module, a.status, a.remarks, a.ip_address FROM audit_log a $where ORDER BY a.audit_id DESC LIMIT 20000", $params);
audit('audit_exported', 'Audit', 'Success', count($rows) . ' rows exported');

// Spreadsheet programs run cells that start with = + - @ as formulas; neutralize them.
$safe = fn(?string $s): string => ($s !== null && $s !== '' && strpos('=+-@', $s[0]) !== false) ? "'" . $s : (string) $s;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="audit_trail_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['When', 'User name', 'Username', 'Role', 'Action', 'Module', 'Result', 'Remarks', 'IP']);
foreach ($rows as $r) {
    $userName = (string) (($r['full_name_snapshot'] ?? '') !== '' ? $r['full_name_snapshot'] : (($r['username_snapshot'] ?? '') !== '' ? $r['username_snapshot'] : 'system'));
    $role = (string) ($r['role_snapshot'] ?? '');
    $result = strtolower((string) $r['status']) === 'success' ? 'Success' : 'Failed';
    fputcsv($out, [
        $r['occurred_at'], $safe($userName), $safe($r['username_snapshot']),
        $role !== '' ? role_label($role) : 'Not recorded',
        $safe($r['action']), $safe($r['module']), $result, $safe($r['remarks']), $r['ip_address'],
    ]);
}
fclose($out);
