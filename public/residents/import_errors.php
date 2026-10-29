<?php
/** Download row-level import skips/rejections for the currently signed-in user. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.add');
$token = get_str('token');
$report = $_SESSION['resident_import_error_report'] ?? null;
if (!is_array($report) || !is_string($report['token'] ?? null)
    || !hash_equals((string) $report['token'], $token)
    || (int) ($report['user_id'] ?? 0) !== (int) ((auth_user()['user_id'] ?? 0))
    || (int) ($report['created_at'] ?? 0) < time() - RESIDENT_IMPORT_STAGE_TTL
    || !is_array($report['rows'] ?? null) || !$report['rows']) {
    abort(404, 'That import error report expired or is not available for this sign-in.');
}
rate_limit_enforce('csv_export', 10, 300, null, 50);

audit('resident_import_errors_downloaded', 'Residents', 'Success', count($report['rows']) . ' import-error rows downloaded');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="birms_resident_import_errors_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['CSV row', 'Result', 'Last name', 'First name', 'Birthdate', 'Reason']);
foreach ($report['rows'] as $row) {
    fputcsv($out, [
        (int) ($row['line'] ?? 0), csv_safe_cell($row['status'] ?? ''),
        csv_safe_cell($row['last_name'] ?? ''), csv_safe_cell($row['first_name'] ?? ''),
        csv_safe_cell($row['birthdate'] ?? ''), csv_safe_cell($row['details'] ?? ''),
    ]);
}
fclose($out);
