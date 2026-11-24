<?php
/**
 * Read-only verification of the complete MSIT demo seed, plus a disposable queue
 * counter continuation probe. Run only after seed_msit_demo_data.php --apply.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../app/bootstrap.php';

$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) {
    fwrite(STDERR, "REFUSED: database '{$dbName}' does not end in _work or _test.\n");
    exit(2);
}

$failures = 0;
function seed_check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) { $failures++; }
}

$residents = db_all("SELECT resident_id, resident_no FROM residents WHERE resident_no LIKE 'DEMO-RES-%' AND resident_no NOT LIKE 'DEMO-RES-EXT-%' ORDER BY resident_no");
$types = db_all("SELECT type_id, name, fee_amount, template_body, is_active, requires_chairman_signature FROM document_types WHERE name IN ('DEMO ONLY - Barangay Clearance', 'DEMO ONLY - Certificate of Residency', 'DEMO ONLY - Certificate of Indigency') ORDER BY name");
$requests = db_all("SELECT request_id, reference_no, msit_status, requirements_status FROM document_requests WHERE reference_no LIKE 'DEMO-DOC-%' ORDER BY reference_no");
$draft = db_one("SELECT d.*, r.reference_no AS request_ref FROM certificate_drafts d JOIN document_requests r ON r.request_id = d.request_id WHERE r.reference_no = 'DEMO-DOC-READY-01'");
$issuance = db_one("SELECT i.*, r.reference_no AS request_ref FROM issuances i JOIN document_requests r ON r.request_id = i.request_id WHERE r.reference_no = 'DEMO-DOC-ISSUED-01'");
$cases = db_all("SELECT blotter_id, case_no, msit_status FROM blotters WHERE case_no LIKE 'MSIT-DEMO-BLT-%' ORDER BY case_no");
$intakes = db_all("SELECT reference_no, review_status, msit_status, blotter_id, status_note FROM resident_blotter_requests WHERE reference_no LIKE 'MSIT-DEMO-BLT-%' ORDER BY reference_no");
$hearing = db_one("SELECT h.*, b.case_no, b.msit_status AS case_status FROM hearings h JOIN blotters b ON b.blotter_id = h.blotter_id WHERE b.case_no LIKE 'MSIT-DEMO-BLT-%' AND h.venue LIKE 'DEMO ONLY - %'");
$venues = db_all("SELECT venue_id, name, availability_override FROM venues WHERE name LIKE 'DEMO ONLY - %' ORDER BY name");
$reservations = db_all("SELECT reference_no, status FROM venue_reservations WHERE reference_no LIKE 'DEMO-VEN-%' ORDER BY reference_no");
$queues = db_all("SELECT queue_id, service, queue_date, queue_number, status, reference_no, purpose
    FROM queue_entries
    WHERE purpose LIKE '[DEMO ONLY]%'
      AND reference_no IN ('DEMO-DOC-PENDING-01', 'MSIT-DEMO-BLT-0001', 'DEMO-VEN-0001')
    ORDER BY service");

seed_check('six synthetic residents exist', count($residents) === 6, 'found ' . count($residents));
seed_check('Secretary can maintain certificate wording and hearings but cannot issue certificates or manage queues',
    role_can('secretary', 'documents.templates.manage')
    && role_can('secretary', 'hearings.summons.manage')
    && role_can('secretary', 'hearings.add')
    && role_can('secretary', 'hearings.edit')
    && role_can('secretary', 'venues.availability.manage')
    && !role_can('secretary', 'certificates.issue')
    && !role_can('secretary', 'requests.approve')
    && !role_can('secretary', 'queue.manage'));
seed_check('Chairman remains read-only and cannot create, edit, approve, print or issue documents',
    !role_can('captain', 'documents.templates.manage')
    && !role_can('captain', 'hearings.summons.manage')
    && !role_can('captain', 'requests.add')
    && !role_can('captain', 'requests.approve')
    && !role_can('captain', 'certificates.issue')
    && !role_can('captain', 'certificates.print')
    && !role_can('captain', 'hearings.add')
    && !role_can('captain', 'queue.manage'));
seed_check('Staff has the operational permissions to approve/reject, issue certificates, review blotter intake, approve venues and call queues',
    !role_can('clerk', 'hearings.summons.manage')
    && role_can('clerk', 'requests.approve')
    && role_can('clerk', 'certificates.issue')
    && role_can('clerk', 'certificates.print')
    && role_can('clerk', 'blotter_requests.review')
    && role_can('clerk', 'venues.approve')
    && role_can('clerk', 'queue.manage'));
seed_check('three demo-only certificate templates exist', count($types) === 3, 'found ' . count($types));
$templatesSafe = count($types) === 3;
foreach ($types as $type) {
    $templatesSafe = $templatesSafe
        && (float) $type['fee_amount'] === 0.0
        && (int) $type['is_active'] === 1
        && (int) $type['requires_chairman_signature'] === 0
        && str_contains((string) $type['template_body'], 'DEMO ONLY')
        && str_contains((string) $type['template_body'], '{{captain_name}}')
        && str_contains((string) $type['template_body'], 'Prepared by: DEMO ONLY');
}
seed_check('all seeded templates are zero-fee, enabled, watermarked, and identify the Secretary/Captain roles', $templatesSafe);

$docStates = array_map(static fn(array $r): string => (string) $r['msit_status'], $requests);
sort($docStates);
$expectedDocStates = ['Approved','Pending','Rejected','Released'];
sort($expectedDocStates);
seed_check('four document requests cover Pending, Approved, Rejected and Released', count($requests) === 4 && $docStates === $expectedDocStates);
seed_check('approved request has a saved, unissued certificate draft', $draft !== null
    && (float) $draft['fee_amount'] === 0.0
    && str_contains((string) $draft['body_text'], 'DEMO ONLY')
    && str_contains((string) $draft['body_text'], 'For the Barangay Captain:')
    && trim((string) $draft['captain_name']) !== '');
seed_check('released request has a clearly watermarked issuance-history record', $issuance !== null
    && (float) $issuance['fee_amount'] === 0.0
    && str_contains((string) $issuance['body_text'], 'DEMO ONLY')
    && trim((string) $issuance['captain_name']) !== '');
$issuable = $draft !== null ? issuable_request((int) $draft['request_id']) : null;
$certificateBlockers = $issuable !== null ? certificate_blockers($issuable, certificate_vars($issuable)) : ['the demo request/draft is missing'];
seed_check('demo certificate draft has no generation blockers', $certificateBlockers === [], implode('; ', $certificateBlockers));

seed_check('three official demo blotter cases exist', count($cases) === 3, 'found ' . count($cases));
$intakeMap = [];
foreach ($intakes as $intake) { $intakeMap[(string) $intake['review_status']] = $intake; }
$accepted = $intakeMap['Accepted'] ?? null;
seed_check('resident blotter intake has Pending, Accepted and Rejected examples',
    count($intakes) === 3 && isset($intakeMap['Pending'], $accepted, $intakeMap['Rejected'])
    && $intakeMap['Pending']['blotter_id'] === null
    && $accepted !== null && (int) $accepted['blotter_id'] > 0
    && $intakeMap['Rejected']['blotter_id'] === null
    && (string) ($intakeMap['Rejected']['msit_status'] ?? '') === 'Dismissed');

$hearingReady = $hearing !== null
    && (string) $hearing['status'] === 'Scheduled'
    && (string) $hearing['case_status'] === 'Unresolved'
    && str_starts_with((string) $hearing['venue'], 'DEMO ONLY - ')
    && strtotime((string) $hearing['scheduled_at']) > time();
seed_check('Secretary hearing is scheduled against an open demo case', $hearingReady);
$captainName = trim((string) setting('captain_name', ''));
$secretaryName = trim((string) setting('secretary_name', ''));
$summonsWording = (string) setting('summons_wording', '');
seed_check('demo Captain and Secretary names are available without overwriting configured names', $captainName !== '' && $secretaryName !== '');
seed_check('Secretary-managed summons template has all required role and hearing placeholders',
    str_contains($summonsWording, 'DEMO ONLY')
    && str_contains($summonsWording, '{{case_no}}')
    && str_contains($summonsWording, '{{respondent_names}}')
    && str_contains($summonsWording, '{{hearing_date}}')
    && str_contains($summonsWording, '{{captain_name}}')
    && str_contains($summonsWording, '{{secretary_name}}'));
$summonsParties = $hearing !== null ? case_parties((int) $hearing['blotter_id']) : [];
$summonsVars = $hearing !== null ? summons_vars(['case_no' => (string) $hearing['case_no']], $hearing, $summonsParties) : [];
$summonsBlockers = $hearing !== null ? summons_blockers($summonsWording, $summonsVars, $summonsParties, $hearing) : ['the demo hearing is missing'];
seed_check('demo summons can be opened by authorized preview roles without missing-data blockers', $summonsBlockers === [], implode('; ', $summonsBlockers));

seed_check('two demo venues and two reservation states exist', count($venues) === 2 && count($reservations) === 2);

$baseHistoryRows = db_all(
    "SELECT service, reference_no, new_status FROM request_status_history
     WHERE note = '[DEMO ONLY] Seeded presentation example.'
       AND (reference_no LIKE 'DEMO-DOC-%'
            OR reference_no LIKE 'DEMO-VEN-%'
            OR reference_no IN ('MSIT-DEMO-BLT-0001', 'MSIT-DEMO-BLT-0002', 'MSIT-DEMO-BLT-0003'))
     ORDER BY service, reference_no"
);
$baseHistoryCounts = [];
foreach ($baseHistoryRows as $historyRow) {
    $service = (string) $historyRow['service'];
    $baseHistoryCounts[$service] = ($baseHistoryCounts[$service] ?? 0) + 1;
}
ksort($baseHistoryCounts);
$expectedBaseHistoryCounts = ['Blotter' => 3, 'Documents' => 4, 'Venue' => 2];
seed_check('nine base status-history rows use the exact SRS service labels',
    count($baseHistoryRows) === 9 && $baseHistoryCounts === $expectedBaseHistoryCounts,
    json_encode($baseHistoryCounts, JSON_UNESCAPED_SLASHES));
$queueServices = array_map(static fn(array $q): string => (string) $q['service'], $queues);
sort($queueServices);
$expectedServices = ['Blotter','Documents','Venue'];
sort($expectedServices);
seed_check('one waiting demo ticket exists for each of the three service queues',
    count($queues) === 3 && $queueServices === $expectedServices
    && count(array_filter($queues, static fn(array $q): bool => $q['status'] === 'Waiting')) === 3);

$counterOkay = true;
$counterDetails = [];
foreach ($queues as $queue) {
    $service = (string) $queue['service'];
    $date = (string) $queue['queue_date'];
    $maxEntry = (int) db_val('SELECT COALESCE(MAX(queue_number), 0) FROM queue_entries WHERE service = ? AND queue_date = ?', [$service, $date]);
    $counter = (int) (db_val('SELECT last_number FROM queue_counters WHERE service = ? AND queue_date = ?', [$service, $date]) ?? -1);
    if ($counter < $maxEntry) { $counterOkay = false; }
    $counterDetails[] = $service . ': counter=' . $counter . ', max=' . $maxEntry;
}
seed_check('queue counters are synchronized with the seeded tickets', $counterOkay, implode('; ', $counterDetails));

$probeReference = 'SC-' . date('ymdHis') . '-' . bin2hex(random_bytes(2));
$probe = null;
$queueDate = date('Y-m-d');
$counterBeforeProbe = (int) (db_val(
    "SELECT last_number FROM queue_counters WHERE service = 'Documents' AND queue_date = ?",
    [$queueDate]
) ?? 0);
try {
    $before = (int) db_val(
        "SELECT COALESCE(MAX(queue_number), 0) FROM queue_entries WHERE service = 'Documents' AND queue_date = ?",
        [$queueDate]
    );
    $probe = queue_issue_number('Documents', null, $probeReference, 'staff', '[CI ONLY] Seed counter continuation check');
    seed_check('Staff can issue the next Documents number immediately after demo seeding',
        (int) $probe['queue_number'] > $before,
        'previous max=' . $before . '; next=' . (int) $probe['queue_number']);
} catch (Throwable $e) {
    seed_check('Staff can issue the next Documents number immediately after demo seeding', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if (is_array($probe) && isset($probe['queue_id'], $probe['queue_number'])) {
        db_exec('DELETE FROM queue_entries WHERE queue_id = ?', [(int) $probe['queue_id']]);
        db_exec(
            "UPDATE queue_counters SET last_number = ? WHERE service = 'Documents' AND queue_date = ? AND last_number = ?",
            [$counterBeforeProbe, $queueDate, (int) $probe['queue_number']]
        );
    }
}

echo str_repeat('-', 72) . PHP_EOL;
echo 'DEMO SEED CHECKS: ' . ($failures === 0 ? 'PASS' : 'FAIL (' . $failures . ')') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
