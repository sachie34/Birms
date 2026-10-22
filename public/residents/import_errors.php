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

audit('resident_import_errors_downloaded', 'Residents', 'Success', count($report['rows']) . ' import-error rows downloaded');
$safe = static function ($value): string {
    $s = (string) ($value ?? '');
    return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $s) ? "'" . $s : $s;
};
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="birms_resident_import_errors_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['CSV row', 'Result', 'Last name', 'First name', 'Birthdate', 'Reason']);
foreach ($report['rows'] as $row) {
    fputcsv($out, [
        (int) ($row['line'] ?? 0), $safe($row['status'] ?? ''),
        $safe($row['last_name'] ?? ''), $safe($row['first_name'] ?? ''),
        $safe($row['birthdate'] ?? ''), $safe($row['details'] ?? ''),
    ]);
}
fclose($out);
