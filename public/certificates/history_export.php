<?php
/** CSV export of issuances (Captain, Secretary). Audited. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('reports.export');
require __DIR__ . '/history.php';          // reuses the filter functions only (it returns early)
$f = history_filters($_GET); [$where, $p] = history_where($f);
$rows = db_all("SELECT i.reference_no, i.resident_name, i.type_name, i.purpose, i.issued_at, i.issued_by_name,
    i.fee_amount AS issuance_fee_snapshot, COALESCE(cc.fee_amount, 0) AS ledger_amount,
    cc.reconciliation_status, cc.payment_method, cc.transaction_date, cc.collector_name, i.or_number
    FROM issuances i LEFT JOIN cash_collection_ledger cc ON cc.issuance_id = i.issuance_id $where
    ORDER BY i.issued_at DESC LIMIT 20000", $p);
audit('issuances_exported', 'Certificates', 'Success', count($rows) . ' rows exported');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="issuances_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Reference', 'Resident', 'Document', 'Purpose', 'Date issued', 'Issued by', 'Issuance fee snapshot', 'Cash ledger amount', 'Reconciliation status', 'Payment method', 'Transaction date', 'Collector', 'OR number']);
foreach ($rows as $r) {
    fputcsv($out, array_map('csv_safe_cell', [$r['reference_no'], $r['resident_name'], $r['type_name'], $r['purpose'], $r['issued_at'], $r['issued_by_name'],
        $r['issuance_fee_snapshot'], $r['ledger_amount'], $r['reconciliation_status'] ?? 'missing ledger entry', $r['payment_method'] ?? '', $r['transaction_date'] ?? '', $r['collector_name'] ?? '', $r['or_number']]));
}
fclose($out);
