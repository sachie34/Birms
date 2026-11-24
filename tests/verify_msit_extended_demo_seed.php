<?php
/**
 * Read-only verification for tools/seed_msit_extended_demo_data.php.
 * Run only after the extended MSIT seeder has completed successfully.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Command line only.\n");
}
require_once __DIR__ . '/../app/core.php';
date_default_timezone_set((string) config('timezone', 'Asia/Manila'));

$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) {
    fwrite(STDERR, "REFUSED: database '{$dbName}' does not end in _work or _test.\n");
    exit(2);
}

$passed = 0;
$failed = 0;

function ext_verify(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo 'PASS ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    } else {
        $failed++;
        echo 'FAIL ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    }
}

function ext_count(string $sql, array $params = []): int
{
    return (int) db_val($sql, $params);
}

function ext_private_file(string $relative, string $prefix): ?string
{
    if (!str_starts_with($relative, $prefix) || str_contains($relative, "\0")
        || str_contains($relative, '\\') || preg_match('~^[A-Za-z0-9._/-]+$~D', $relative) !== 1) {
        return null;
    }
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') { return null; }
    }
    $root = realpath(APP_ROOT);
    if (!is_string($root)) { return null; }
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $parent = realpath(dirname($absolute));
    if (!is_string($parent) || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))
        || is_link($absolute) || !is_file($absolute)) {
        return null;
    }
    return $absolute;
}

echo "BIRMS extended MSIT demo verification — {$dbName}\n";
echo str_repeat('-', 78) . "\n";

$residents = db_all(
    "SELECT resident_id, resident_no, address, purok_id, resident_status_id, marital_status,
            resident_type_id, email_address, contact_number, photo_path, household_id
     FROM residents WHERE resident_no LIKE 'DEMO-RES-EXT-%' ORDER BY resident_no"
);
ext_verify(count($residents) === 12, '12 additional synthetic residents exist', 'found ' . count($residents));

$residentIds = array_map('intval', array_column($residents, 'resident_id'));
$residentIdsUnique = count($residentIds) === count(array_unique($residentIds));
$residentFieldsValid = count($residents) === 12;
foreach ($residents as $row) {
    $residentFieldsValid = $residentFieldsValid
        && trim((string) $row['resident_no']) !== ''
        && str_starts_with((string) $row['resident_no'], 'DEMO-RES-EXT-')
        && str_starts_with((string) $row['address'], 'DEMO ONLY - ')
        && (int) $row['purok_id'] > 0
        && (int) $row['resident_status_id'] > 0
        && trim((string) $row['marital_status']) !== ''
        && (int) $row['resident_type_id'] > 0
        && str_ends_with((string) $row['email_address'], '@example.invalid')
        && $row['contact_number'] === null
        && $row['household_id'] === null;
}
ext_verify($residentFieldsValid && $residentIdsUnique, 'Resident fields follow the revised SRS', 'address and Purok are stored per resident; no household grouping or real contact details');

$statusCoverage = ext_count(
    "SELECT COUNT(DISTINCT s.label) FROM residents r
     JOIN lookups s ON s.lookup_id = r.resident_status_id
     WHERE r.resident_no LIKE 'DEMO-RES-EXT-%' AND s.list_key = 'resident_status'"
);
$typeCoverage = ext_count(
    "SELECT COUNT(DISTINCT t.label) FROM residents r
     JOIN lookups t ON t.lookup_id = r.resident_type_id
     WHERE r.resident_no LIKE 'DEMO-RES-EXT-%' AND t.list_key = 'resident_type'"
);
ext_verify($statusCoverage === 4, 'All four revised resident statuses are represented', 'Active, Transferred, Deceased, Non-resident');
ext_verify($typeCoverage === 6, 'All six revised resident types are represented', 'PWD, Senior Citizen, Solo Parent, 4Ps Beneficiary, Indigent, None');

$photoValid = count($residents) === 12;
foreach ($residents as $row) {
    $path = (string) $row['photo_path'];
    $absolute = ext_private_file($path, 'storage/resident_photos/msit-demo-extended/');
    if ($absolute === null) { $photoValid = false; continue; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) { $photoValid = false; continue; }
    try { $mime = finfo_file($finfo, $absolute); } finally { finfo_close($finfo); }
    if ($mime !== 'image/png') { $photoValid = false; }
}
ext_verify($photoValid, '12 private placeholder resident photos exist', 'PNG files remain outside the public web root');

$archivedCases = db_all(
    "SELECT blotter_id, case_no, msit_status, archived_from_status, archived_at, resolution_note
     FROM blotters WHERE case_no LIKE 'MSIT-EXT-BLT-%' ORDER BY case_no"
);
$archiveStates = array_count_values(array_map(static fn(array $r): string => (string) $r['archived_from_status'], $archivedCases));
$archivesValid = count($archivedCases) === 2;
foreach ($archivedCases as $row) {
    $archivesValid = $archivesValid && $row['msit_status'] === 'Archived'
        && $row['archived_at'] !== null
        && str_starts_with((string) $row['resolution_note'], '[DEMO ONLY]');
}
ext_verify($archivesValid && ($archiveStates['Resolved'] ?? 0) === 1 && ($archiveStates['Dismissed'] ?? 0) === 1,
    'Resolved and dismissed blotter examples are retained in Archived', 'no blotter records are deleted');

$partyCount = ext_count(
    "SELECT COUNT(*) FROM blotter_parties p JOIN blotters b ON b.blotter_id = p.blotter_id
     WHERE b.case_no LIKE 'MSIT-EXT-BLT-%'"
);
ext_verify($partyCount === 4, 'Archived blotter examples have complainant/respondent parties', '2 parties per case');

$blotterHistoryCount = ext_count(
    "SELECT COUNT(*) FROM request_status_history
     WHERE reference_no IN ('MSIT-EXT-BLT-01','MSIT-EXT-BLT-02')
       AND note LIKE '[DEMO ONLY] EXTENDED MSIT%'"
);
ext_verify($blotterHistoryCount === 4, 'Blotter status transitions are recorded', 'Resolved/Dismissed followed by Archived');

$reservations = db_all(
    "SELECT reservation_id, reference_no, status FROM venue_reservations
     WHERE reference_no IN ('DEMO-EXT-VEN-0003','DEMO-EXT-VEN-0004') ORDER BY reference_no"
);
$reservationStates = array_count_values(array_map(static fn(array $r): string => (string) $r['status'], $reservations));
ext_verify(count($reservations) === 2 && ($reservationStates['Rejected'] ?? 0) === 1 && ($reservationStates['Completed'] ?? 0) === 1,
    'Venue examples cover Rejected and Completed outcomes');
$venueHistoryCount = ext_count(
    "SELECT COUNT(*) FROM request_status_history
     WHERE reference_no IN ('DEMO-EXT-VEN-0003','DEMO-EXT-VEN-0004')
       AND note LIKE '[DEMO ONLY] EXTENDED MSIT%'"
);
ext_verify($venueHistoryCount === 3, 'Venue status changes have a complete sample history', 'Rejected and Approved → Completed');

$queues = db_all(
    "SELECT queue_id, service, queue_date, queue_number, reference_no, status, issued_at, called_at, served_at
     FROM queue_entries WHERE reference_no IN
       ('DEMO-EXT-Q-01','DEMO-EXT-Q-02','DEMO-EXT-Q-03','DEMO-EXT-Q-04')
     ORDER BY reference_no"
);
$queueStates = array_count_values(array_map(static fn(array $r): string => (string) $r['status'], $queues));
$queueFieldsValid = count($queues) === 4;
foreach ($queues as $row) {
    $queueFieldsValid = $queueFieldsValid && (int) $row['queue_number'] > 0;
    if ($row['status'] === 'Called') {
        $queueFieldsValid = $queueFieldsValid && $row['called_at'] !== null && $row['served_at'] === null;
    } elseif ($row['status'] === 'Served') {
        $queueFieldsValid = $queueFieldsValid && $row['called_at'] !== null && $row['served_at'] !== null
            && strtotime((string) $row['served_at']) >= strtotime((string) $row['called_at']);
    } elseif ($row['status'] === 'Skipped') {
        $queueFieldsValid = $queueFieldsValid && $row['called_at'] !== null && $row['served_at'] === null;
    } elseif ($row['status'] === 'Cancelled') {
        $queueFieldsValid = $queueFieldsValid && $row['called_at'] === null && $row['served_at'] === null;
    } else {
        $queueFieldsValid = false;
    }
}
$queueCoverage = count($queueStates) === 4 && isset($queueStates['Called'], $queueStates['Served'], $queueStates['Skipped'], $queueStates['Cancelled']);
ext_verify($queueFieldsValid && $queueCoverage, 'Historical queues show Called, Served, Skipped and Cancelled', 'timestamps match each status');

$countersValid = true;
foreach ($queues as $row) {
    $counter = db_val(
        'SELECT last_number FROM queue_counters WHERE service = ? AND queue_date = ?',
        [$row['service'], $row['queue_date']]
    );
    $maximum = ext_count(
        'SELECT COALESCE(MAX(queue_number), 0) FROM queue_entries WHERE service = ? AND queue_date = ?',
        [$row['service'], $row['queue_date']]
    );
    $countersValid = $countersValid && $counter !== null && (int) $counter >= $maximum;
}
ext_verify($countersValid, 'Historical queue counters remain synchronized', 'no duplicate next number should be generated');

$attachments = db_all(
    "SELECT attachment_id, request_id, original_name, storage_path, mime_type, byte_size, sha256
     FROM document_attachments
     WHERE original_name LIKE 'DEMO ONLY - Extended support item %' ORDER BY original_name"
);
$attachmentValid = count($attachments) === 2;
foreach ($attachments as $row) {
    $absolute = ext_private_file((string) $row['storage_path'], 'storage/private_uploads/msit-demo-extended/');
    if ($absolute === null) { $attachmentValid = false; continue; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) { $attachmentValid = false; continue; }
    try { $mime = finfo_file($finfo, $absolute); } finally { finfo_close($finfo); }
    $hash = hash_file('sha256', $absolute);
    $size = filesize($absolute);
    $requestExists = ext_count('SELECT COUNT(*) FROM document_requests WHERE request_id = ?', [(int) $row['request_id']]) === 1;
    $attachmentValid = $attachmentValid && $mime === 'application/pdf' && $row['mime_type'] === 'application/pdf'
        && $size === (int) $row['byte_size'] && is_string($hash) && hash_equals((string) $row['sha256'], $hash)
        && $requestExists;
}
ext_verify($attachmentValid, 'Two private DEMO ONLY PDF request attachments pass file checks', 'request links, MIME, size and SHA-256');

$documentHistory = ext_count(
    "SELECT COUNT(*) FROM request_status_history
     WHERE reference_no IN ('DEMO-DOC-READY-01','DEMO-DOC-REJECT-01','DEMO-DOC-ISSUED-01')
       AND note LIKE '[DEMO ONLY] EXTENDED MSIT%'"
);
ext_verify($documentHistory === 4, 'Document request history shows approval, rejection and release transitions', '4 labelled demo history events');

$auditExamples = db_all(
    "SELECT username_snapshot, action, module, entity_type, remarks
     FROM audit_log
     WHERE action = 'msit_extended_demo_fixture' AND module = 'Demo Data'
     ORDER BY audit_id"
);
$auditValid = count($auditExamples) === 4;
foreach ($auditExamples as $row) {
    $auditValid = $auditValid && $row['username_snapshot'] === 'DEMO DATA'
        && str_contains((string) $row['remarks'], '[DEMO ONLY]');
}
ext_verify($auditValid, 'Audit examples are clearly attributed to DEMO DATA', 'they do not impersonate real staff');

$seedAudit = ext_count(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'msit_extended_demo_seeded'
     AND module = 'Demo Data' AND remarks LIKE '[DEMO ONLY]%'"
);
ext_verify($seedAudit === 1, 'The extended seed operation itself is recorded in audit history');

echo str_repeat('-', 78) . PHP_EOL;
echo "EXTENDED DEMO CHECKS: PASS {$passed} / FAIL {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
