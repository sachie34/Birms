<?php
/**
 * Additive extended MSIT demonstration fixtures.
 *
 * CLI only. Requires the base tools/seed_msit_demo_data.php dataset to be complete.
 * Does not create users, visitor-log entries, cash-ledger transactions, official fees,
 * Chairman signature assets, real contacts, or real notifications.
 *
 * Usage:
 *   php tools/seed_msit_extended_demo_data.php          (read-only plan)
 *   php tools/seed_msit_extended_demo_data.php --apply  (insert once; repeatable)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../app/core.php';
date_default_timezone_set((string) config('timezone', 'Asia/Manila'));

function msit_ext_out(string $message): void { echo $message . PHP_EOL; }
function msit_ext_fail(string $message): never { fwrite(STDERR, 'REFUSED: ' . $message . PHP_EOL); exit(2); }

function msit_ext_table_exists(string $table): bool
{
    return (int) db_val(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    ) > 0;
}

function msit_ext_column_exists(string $table, string $column): bool
{
    return (int) db_val(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    ) > 0;
}

function msit_ext_require_schema(): void
{
    $required = [
        'users' => ['user_id', 'username', 'role', 'status'],
        'lookups' => ['lookup_id', 'list_key', 'label', 'is_active'],
        'residents' => ['resident_id', 'resident_no', 'first_name', 'last_name', 'birthdate', 'sex', 'civil_status',
            'household_id', 'address', 'purok_id', 'resident_status_id', 'marital_status', 'resident_type_id',
            'email_address', 'contact_number', 'photo_path', 'notification_preference', 'residency_status', 'archived_at'],
        'blotters' => ['blotter_id', 'case_no', 'incident_date', 'incident_time', 'incident_place', 'purok_id',
            'incident_type_id', 'narrative', 'status', 'msit_status', 'archived_from_status', 'archived_at',
            'resolution_note', 'resolved_at', 'recorded_at', 'recorded_by'],
        'blotter_parties' => ['party_id', 'blotter_id', 'party_role', 'full_name', 'age', 'address', 'contact_number'],
        'venues' => ['venue_id', 'name', 'is_active'],
        'venue_reservations' => ['reservation_id', 'reference_no', 'venue_id', 'resident_id', 'purpose',
            'reservation_date', 'start_time', 'end_time', 'status', 'created_by'],
        'queue_entries' => ['queue_id', 'service', 'queue_date', 'queue_number', 'resident_id', 'reference_no',
            'status', 'issued_at', 'called_at', 'served_at', 'issued_via', 'called_by', 'served_by',
            'notification_preference', 'purpose'],
        'queue_counters' => ['service', 'queue_date', 'last_number'],
        'document_requests' => ['request_id', 'reference_no'],
        'document_attachments' => ['attachment_id', 'visit_id', 'request_id', 'original_name', 'storage_path',
            'mime_type', 'byte_size', 'sha256', 'uploaded_by'],
        'request_status_history' => ['history_id', 'service', 'reference_no', 'previous_status', 'new_status',
            'changed_by', 'changed_at', 'note', 'request_id', 'blotter_id', 'reservation_id'],
        'audit_log' => ['audit_id', 'action', 'module', 'remarks'],
    ];

    foreach ($required as $table => $columns) {
        if (!msit_ext_table_exists($table)) {
            msit_ext_fail("required table {$table} is missing. Apply the current MSIT migrations first.");
        }
        foreach ($columns as $column) {
            if (!msit_ext_column_exists($table, $column)) {
                msit_ext_fail("required column {$table}.{$column} is missing. Use a compatible current MSIT build.");
            }
        }
    }
}

function msit_ext_counts(): array
{
    return [
        'additional_residents' => (int) db_val("SELECT COUNT(*) FROM residents WHERE resident_no LIKE 'DEMO-RES-EXT-%'"),
        'resident_photos' => (int) db_val("SELECT COUNT(*) FROM residents WHERE resident_no LIKE 'DEMO-RES-EXT-%' AND photo_path LIKE 'storage/resident_photos/msit-demo-extended/%'"),
        'archived_blotters' => (int) db_val("SELECT COUNT(*) FROM blotters WHERE case_no LIKE 'MSIT-EXT-BLT-%'"),
        'archived_blotter_parties' => (int) db_val("SELECT COUNT(*) FROM blotter_parties p JOIN blotters b ON b.blotter_id = p.blotter_id WHERE b.case_no LIKE 'MSIT-EXT-BLT-%'"),
        'extra_reservations' => (int) db_val("SELECT COUNT(*) FROM venue_reservations WHERE reference_no LIKE 'DEMO-EXT-VEN-%'"),
        'historical_queues' => (int) db_val("SELECT COUNT(*) FROM queue_entries WHERE reference_no LIKE 'DEMO-EXT-Q-%'"),
        'private_request_attachments' => (int) db_val("SELECT COUNT(*) FROM document_attachments WHERE original_name LIKE 'DEMO ONLY - Extended support item %'"),
        'status_history' => (int) db_val("SELECT COUNT(*) FROM request_status_history WHERE note LIKE '[DEMO ONLY] EXTENDED MSIT%'"),
        'seed_audit_events' => (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'msit_extended_demo_seeded' AND module = 'Demo Data'"),
    ];
}

function msit_ext_expected_counts(): array
{
    return [
        'additional_residents' => 12,
        'resident_photos' => 12,
        'archived_blotters' => 2,
        'archived_blotter_parties' => 4,
        'extra_reservations' => 2,
        'historical_queues' => 4,
        'private_request_attachments' => 2,
        'status_history' => 11,
        'seed_audit_events' => 1,
    ];
}

function msit_ext_any_markers(array $counts): bool
{
    foreach ($counts as $count) {
        if ($count > 0) { return true; }
    }
    return false;
}

function msit_ext_is_complete(array $counts): bool
{
    return $counts === msit_ext_expected_counts();
}

function msit_ext_require_base_seed(): void
{
    $base = [
        'residents' => (int) db_val("SELECT COUNT(*) FROM residents WHERE resident_no LIKE 'DEMO-RES-%' AND resident_no NOT LIKE 'DEMO-RES-EXT-%'"),
        'document_types' => (int) db_val("SELECT COUNT(*) FROM document_types WHERE name IN ('DEMO ONLY - Barangay Clearance', 'DEMO ONLY - Certificate of Residency', 'DEMO ONLY - Certificate of Indigency')"),
        'requests' => (int) db_val("SELECT COUNT(*) FROM document_requests WHERE reference_no LIKE 'DEMO-DOC-%'"),
        'drafts' => (int) db_val("SELECT COUNT(*) FROM certificate_drafts d JOIN document_requests r ON r.request_id = d.request_id WHERE r.reference_no LIKE 'DEMO-DOC-%'"),
        'issuances' => (int) db_val("SELECT COUNT(*) FROM issuances WHERE request_id IN (SELECT request_id FROM document_requests WHERE reference_no LIKE 'DEMO-DOC-%')"),
        'blotters' => (int) db_val("SELECT COUNT(*) FROM blotters WHERE case_no LIKE 'MSIT-DEMO-BLT-%'"),
        'venues' => (int) db_val("SELECT COUNT(*) FROM venues WHERE name LIKE 'DEMO ONLY - %'"),
        'reservations' => (int) db_val("SELECT COUNT(*) FROM venue_reservations WHERE reference_no LIKE 'DEMO-VEN-%'"),
        'blotter_intakes' => (int) db_val("SELECT COUNT(*) FROM resident_blotter_requests WHERE reference_no LIKE 'MSIT-DEMO-BLT-%'"),
        'hearings' => (int) db_val("SELECT COUNT(*) FROM hearings h JOIN blotters b ON b.blotter_id = h.blotter_id WHERE b.case_no LIKE 'MSIT-DEMO-BLT-%' AND h.venue LIKE 'DEMO ONLY - %'"),
        'queues' => (int) db_val("SELECT COUNT(*) FROM queue_entries WHERE purpose LIKE '[DEMO ONLY]%' AND reference_no IN ('DEMO-DOC-PENDING-01', 'MSIT-DEMO-BLT-0001', 'DEMO-VEN-0001')"),
    ];
    $expected = [
        'residents' => 6, 'document_types' => 3, 'requests' => 4, 'drafts' => 1,
        'issuances' => 1, 'blotters' => 3, 'venues' => 2, 'reservations' => 2,
        'blotter_intakes' => 3, 'hearings' => 1, 'queues' => 3,
    ];
    if ($base !== $expected) {
        msit_ext_fail('the base MSIT demo seed is not complete. Run tools/seed_msit_demo_data.php --apply and tests/verify_msit_demo_seed.php first. Current base counts: ' . json_encode($base));
    }
}

function msit_ext_lookup_id(string $listKey, string $label): int
{
    $id = db_val(
        'SELECT lookup_id FROM lookups WHERE list_key = ? AND label = ? AND is_active = 1 ORDER BY lookup_id LIMIT 1',
        [$listKey, $label]
    );
    if ($id === null) {
        throw new RuntimeException("Required active lookup '{$listKey}: {$label}' is missing. No fixtures were committed.");
    }
    return (int) $id;
}

function msit_ext_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** Creates a neutral 96x96 generic silhouette PNG without GD or an external image service. */
function msit_ext_demo_avatar_png(): string
{
    $width = 96;
    $height = 96;
    $pixels = '';
    for ($y = 0; $y < $height; $y++) {
        $pixels .= "\0";
        for ($x = 0; $x < $width; $x++) {
            $head = (($x - 48) * ($x - 48) + ($y - 29) * ($y - 29)) <= 15 * 15;
            $dx = ($x - 48) / 29;
            $dy = ($y - 76) / 23;
            $body = ($dx * $dx + $dy * $dy) <= 1.0 && $y >= 51;
            if ($head || $body) {
                $pixels .= "\x55\x68\x7D";
            } else {
                $pixels .= "\xEC\xF1\xF5";
            }
        }
    }
    $compressed = gzcompress($pixels, 9);
    if (!is_string($compressed)) { throw new RuntimeException('Could not create synthetic resident photo placeholders.'); }
    $ihdr = pack('N', $width) . pack('N', $height) . "\x08\x02\x00\x00\x00";
    return "\x89PNG\r\n\x1A\n"
        . msit_ext_png_chunk('IHDR', $ihdr)
        . msit_ext_png_chunk('IDAT', $compressed)
        . msit_ext_png_chunk('IEND', '');
}

/** Creates a minimal, valid one-page PDF with a conspicuous demo-only warning. */
function msit_ext_demo_pdf(string $reference): string
{
    $lines = [
        'DEMO ONLY - NOT AN OFFICIAL SUPPORTING DOCUMENT',
        'Fictitious BIRMS presentation attachment. No legal or evidentiary effect.',
        'Reference: ' . $reference,
        'This file exists only to demonstrate private request-attachment storage.',
    ];
    $stream = "BT\n/F1 15 Tf\n48 740 Td\n";
    foreach ($lines as $index => $line) {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        if ($index > 0) { $stream .= "0 -30 Td\n/F1 10 Tf\n"; }
        $stream .= '(' . $safe . ") Tj\n";
    }
    $stream .= "ET\n";
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($number = 1; $number <= 5; $number++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    return $pdf;
}

/**
 * Writes a private demo asset with exclusive creation. An existing identical file may be reused;
 * a different file, symlink, or path outside the intended storage root is never overwritten.
 */
function msit_ext_write_asset(string $relative, string $bytes, array &$createdFiles): array
{
    if (str_contains($relative, "\0") || str_contains($relative, '\\')
        || preg_match('~^storage/(?:resident_photos|private_uploads)/[A-Za-z0-9._/-]+$~D', $relative) !== 1) {
        throw new RuntimeException('Refused an unsafe private demo asset path.');
    }
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('Refused an unsafe private demo asset path segment.');
        }
    }
    $root = realpath(APP_ROOT);
    if (!is_string($root)) { throw new RuntimeException('Application root is unavailable.'); }
    $segments = explode('/', $relative);
    $filename = array_pop($segments);
    $directory = $root;
    foreach ($segments as $segment) {
        $directory .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($directory)) { throw new RuntimeException('Refused a symbolic link in private demo storage.'); }
        if (!is_dir($directory) && !mkdir($directory, 0750) && !is_dir($directory)) {
            throw new RuntimeException('Could not create private demo storage directory.');
        }
        $resolved = realpath($directory);
        if (!is_string($resolved) || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Private demo storage resolved outside the application root.');
        }
    }
    $absolute = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_link($absolute)) { throw new RuntimeException('Refused a symbolic link at a private demo asset destination.'); }
    $expectedHash = hash('sha256', $bytes);
    if (is_file($absolute)) {
        $existingHash = hash_file('sha256', $absolute);
        if (!is_string($existingHash) || !hash_equals($expectedHash, $existingHash)) {
            throw new RuntimeException('A different file already exists at a reserved DEMO asset path; it was not overwritten.');
        }
        return ['path' => $relative, 'absolute' => $absolute, 'sha256' => $expectedHash, 'bytes' => strlen($bytes), 'created' => false];
    }

    $handle = @fopen($absolute, 'xb');
    if ($handle === false) {
        if (is_file($absolute) && hash_file('sha256', $absolute) === $expectedHash) {
            return ['path' => $relative, 'absolute' => $absolute, 'sha256' => $expectedHash, 'bytes' => strlen($bytes), 'created' => false];
        }
        throw new RuntimeException('Could not create a private demo asset without overwriting an existing file.');
    }
    try {
        $written = 0;
        $length = strlen($bytes);
        while ($written < $length) {
            $part = fwrite($handle, substr($bytes, $written));
            if (!is_int($part) || $part < 1) {
                throw new RuntimeException('A private demo asset could not be fully written.');
            }
            $written += $part;
        }
        fflush($handle);
    } catch (Throwable $e) {
        fclose($handle);
        @unlink($absolute);
        throw $e;
    }
    fclose($handle);
    @chmod($absolute, 0640);
    $createdFiles[] = $absolute;
    return ['path' => $relative, 'absolute' => $absolute, 'sha256' => $expectedHash, 'bytes' => strlen($bytes), 'created' => true];
}

function msit_ext_insert_history(
    string $service,
    string $reference,
    string $previous,
    string $next,
    ?int $requestId,
    ?int $blotterId,
    ?int $reservationId,
    string $note
): void {
    db_exec(
        'INSERT INTO request_status_history (service, reference_no, previous_status, new_status, changed_by, note, request_id, blotter_id, reservation_id)
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)',
        [$service, $reference, $previous, $next, $note, $requestId, $blotterId, $reservationId]
    );
}

function msit_ext_seed_residents(array $purokIds, array $photoPng, array &$createdFiles): array
{
    $statusLabels = ['Active', 'Transferred', 'Deceased', 'Non-resident'];
    $typeLabels = ['PWD', 'Senior Citizen', 'Solo Parent', '4Ps Beneficiary', 'Indigent', 'None'];
    $statusIds = [];
    foreach ($statusLabels as $label) { $statusIds[$label] = msit_ext_lookup_id('resident_status', $label); }
    $typeIds = [];
    foreach ($typeLabels as $label) { $typeIds[$label] = msit_ext_lookup_id('resident_type', $label); }

    $samples = [
        ['DemoJordan', 'SampleNavarro', '1998-04-12', 'Male', 'Single', 'Active', 'PWD'],
        ['DemoAvery', 'SampleVillanueva', '1952-09-30', 'Female', 'Widowed', 'Active', 'Senior Citizen'],
        ['DemoBlake', 'SampleTorres', '1995-03-15', 'Male', 'Single', 'Active', 'Solo Parent'],
        ['DemoCasey', 'SampleMendoza', '1984-10-06', 'Female', 'Married', 'Active', '4Ps Beneficiary'],
        ['DemoDrew', 'SampleCastillo', '1979-01-22', 'Male', 'Married', 'Active', 'Indigent'],
        ['DemoElliot', 'SampleRamos', '2002-07-11', 'Female', 'Single', 'Active', 'None'],
        ['DemoFinley', 'SampleAquino', '1990-02-14', 'Male', 'Separated', 'Transferred', 'None'],
        ['DemoHarper', 'SampleGarcia', '1968-06-08', 'Female', 'Married', 'Deceased', 'None'],
        ['DemoKai', 'SampleReyes', '2000-12-01', 'Male', 'Single', 'Non-resident', 'None'],
        ['DemoLogan', 'SampleCruz', '1950-11-19', 'Male', 'Married', 'Active', 'Senior Citizen'],
        ['DemoMorgan', 'SampleFlores', '1989-05-27', 'Female', 'Separated', 'Active', 'PWD'],
        ['DemoParker', 'SampleSantos', '1993-08-09', 'Male', 'Married', 'Active', 'Indigent'],
    ];

    $ids = [];
    foreach ($samples as $index => [$first, $last, $birthdate, $sex, $marital, $status, $type]) {
        $number = sprintf('DEMO-RES-EXT-%04d', $index + 1);
        $relativePhoto = 'storage/resident_photos/msit-demo-extended/' . $number . '.png';
        $photo = msit_ext_write_asset($relativePhoto, $photoPng, $createdFiles);
        $legacyResidency = $status === 'Transferred' ? 'Relocated' : 'Active';
        $address = sprintf('DEMO ONLY - Sample Address %02d, Sample Street', $index + 1);
        db_exec(
            'INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              email_address, photo_path, notification_preference, last_name, first_name, birthdate, sex,
              civil_status, contact_number, residency_status, household_id, is_household_head, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'email\', ?, ?, ?, ?, ?, NULL, ?, NULL, 0, NULL)',
            [
                $number, $address, $purokIds[$index % count($purokIds)], $statusIds[$status], $marital, $typeIds[$type],
                sprintf('demoext%02d@example.invalid', $index + 1), $photo['path'], $last, $first, $birthdate, $sex,
                $marital, $legacyResidency,
            ]
        );
        $ids[] = (int) db()->lastInsertId();
    }
    return $ids;
}

function msit_ext_seed_blotters(array $residentIds, int $incidentTypeId): void
{
    $samples = [
        ['MSIT-EXT-BLT-01', $residentIds[0], 'Resolved', date('Y-m-d', strtotime('-8 days')),
            'DEMO ONLY - Archived resolved sample location', '[DEMO ONLY] Fictitious incident retained in Archived after a sample resolution.',
            '[DEMO ONLY] Sample resolution completed; archived for presentation.', 'Resolved'],
        ['MSIT-EXT-BLT-02', $residentIds[3], 'Dismissed', date('Y-m-d', strtotime('-10 days')),
            'DEMO ONLY - Archived dismissed sample location', '[DEMO ONLY] Fictitious incident retained in Archived after a sample dismissal.',
            '[DEMO ONLY] Sample complaint dismissed for demonstration; archived, not deleted.', 'Dismissed'],
    ];
    foreach ($samples as [$caseNo, $residentId, $fromStatus, $incidentDate, $place, $narrative, $note, $archiveFrom]) {
        $resolvedAt = $archiveFrom === 'Resolved' ? date('Y-m-d H:i:s', strtotime('-2 days')) : null;
        // The legacy status enum has no Dismissed value; the revised msit_status and
        // archived_from_status fields carry the SRS state without deleting the record.
        db_exec(
            'INSERT INTO blotters
             (case_no, incident_date, incident_time, incident_place, purok_id, incident_type_id, narrative,
              status, msit_status, archived_from_status, archived_at, resolution_note, resolved_at, recorded_at, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'Resolved\', \'Archived\', ?, NOW(), ?, ?, NOW(), NULL)',
            [
                $caseNo, $incidentDate, '11:20:00', $place,
                db_val('SELECT purok_id FROM residents WHERE resident_id = ?', [$residentId]),
                $incidentTypeId, $narrative, $archiveFrom, $note, $resolvedAt,
            ]
        );
        $blotterId = (int) db()->lastInsertId();
        $complainant = (string) db_val('SELECT CONCAT(first_name, \' \', last_name) FROM residents WHERE resident_id = ?', [$residentId]);
        db_exec(
            'INSERT INTO blotter_parties (blotter_id, party_role, full_name, age, address, contact_number)
             VALUES (?, \'Complainant\', ?, 28, ?, NULL), (?, \'Respondent\', ?, 32, ?, NULL)',
            [$blotterId, $complainant, 'DEMO ONLY - Sample complainant address',
             $blotterId, 'DemoRespondentArchived' . substr($caseNo, -2), 'DEMO ONLY - Sample respondent address']
        );
        if ($archiveFrom === 'Resolved') {
            msit_ext_insert_history('Blotter', $caseNo, 'Ongoing', 'Resolved', null, $blotterId, null,
                '[DEMO ONLY] EXTENDED MSIT archived case began as Ongoing and was resolved.');
        } else {
            msit_ext_insert_history('Blotter', $caseNo, 'Ongoing', 'Dismissed', null, $blotterId, null,
                '[DEMO ONLY] EXTENDED MSIT archived case began as Ongoing and was dismissed.');
        }
        msit_ext_insert_history('Blotter', $caseNo, $archiveFrom, 'Archived', null, $blotterId, null,
            '[DEMO ONLY] EXTENDED MSIT terminal blotter state was retained in Archived; no case was deleted.');
    }
}

function msit_ext_seed_reservations(array $residentIds): void
{
    $venues = db_all("SELECT venue_id, name FROM venues WHERE name IN ('DEMO ONLY - Covered Court', 'DEMO ONLY - Multi-purpose Hall') AND is_active = 1 ORDER BY name");
    if (count($venues) !== 2) { throw new RuntimeException('The two base DEMO ONLY venues must exist before extended reservation fixtures can be added.'); }
    $courtId = (int) db_val("SELECT venue_id FROM venues WHERE name = 'DEMO ONLY - Covered Court'");
    $hallId = (int) db_val("SELECT venue_id FROM venues WHERE name = 'DEMO ONLY - Multi-purpose Hall'");
    $specs = [
        ['DEMO-EXT-VEN-0003', $courtId, $residentIds[4], date('Y-m-d', strtotime('+1 day')), '15:00:00', '16:00:00', 'Rejected',
            'Pending', 'Rejected', '[DEMO ONLY] Extended reservation was rejected in the presentation fixture.'],
        ['DEMO-EXT-VEN-0004', $hallId, $residentIds[5], date('Y-m-d', strtotime('-1 day')), '15:00:00', '16:00:00', 'Completed',
            'Pending', 'Approved', '[DEMO ONLY] Extended reservation was approved before its sample completion.'],
    ];
    foreach ($specs as [$reference, $venueId, $residentId, $date, $start, $end, $status, $firstPrevious, $firstNext, $note]) {
        db_exec(
            'INSERT INTO venue_reservations
             (reference_no, venue_id, resident_id, purpose, reservation_date, start_time, end_time, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$reference, $venueId, $residentId, '[DEMO ONLY] Extended venue outcome example', $date, $start, $end, $status]
        );
        $reservationId = (int) db()->lastInsertId();
        msit_ext_insert_history('Venue', $reference, $firstPrevious, $firstNext, null, null, $reservationId, $note);
        if ($status === 'Completed') {
            msit_ext_insert_history('Venue', $reference, 'Approved', 'Completed', null, null, $reservationId,
                '[DEMO ONLY] EXTENDED MSIT approved venue reservation was later marked Completed.');
        }
    }
}

function msit_ext_seed_queue_history(array $residentIds): void
{
    $queueDate = date('Y-m-d', strtotime('-1 day'));
    $baseTime = new DateTimeImmutable($queueDate . ' 08:30:00');
    $items = [
        ['Documents', $residentIds[0], 'DEMO-EXT-Q-01', 'Called', 0, 3, null],
        ['Documents', $residentIds[1], 'DEMO-EXT-Q-02', 'Served', 15, 4, 8],
        ['Blotter', $residentIds[2], 'DEMO-EXT-Q-03', 'Skipped', 30, 5, null],
        ['Venue', $residentIds[3], 'DEMO-EXT-Q-04', 'Cancelled', 45, null, null],
    ];
    foreach ($items as [$service, $residentId, $reference, $status, $offsetMinutes, $calledAfter, $servedAfter]) {
        db_exec(
            'INSERT IGNORE INTO queue_counters (service, queue_date, last_number) VALUES (?, ?, 0)',
            [$service, $queueDate]
        );
        $counter = (int) (db_val(
            'SELECT last_number FROM queue_counters WHERE service = ? AND queue_date = ? FOR UPDATE',
            [$service, $queueDate]
        ) ?? 0);
        $maxEntry = (int) db_val(
            'SELECT COALESCE(MAX(queue_number), 0) FROM queue_entries WHERE service = ? AND queue_date = ?',
            [$service, $queueDate]
        );
        $next = max($counter, $maxEntry) + 1;
        $issued = $baseTime->modify('+' . $offsetMinutes . ' minutes');
        $called = $calledAfter === null ? null : $issued->modify('+' . $calledAfter . ' minutes')->format('Y-m-d H:i:s');
        $served = $servedAfter === null ? null : $issued->modify('+' . $servedAfter . ' minutes')->format('Y-m-d H:i:s');
        db_exec(
            'INSERT INTO queue_entries
             (service, queue_date, queue_number, resident_id, reference_no, purpose, status,
              issued_at, called_at, served_at, issued_via, called_by, served_by, notification_preference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'staff\', NULL, NULL, \'email\')',
            [$service, $queueDate, $next, $residentId, $reference, '[DEMO ONLY] Extended historical queue outcome: ' . $status,
             $status, $issued->format('Y-m-d H:i:s'), $called, $served]
        );
        db_exec(
            'UPDATE queue_counters SET last_number = ? WHERE service = ? AND queue_date = ?',
            [$next, $service, $queueDate]
        );
    }
}

function msit_ext_seed_attachments(array $createdFiles): void
{
    $fileRows = [
        ['DEMO ONLY - Extended support item 01.pdf', 'storage/private_uploads/msit-demo-extended/MSIT-DEMO-SUPPORT-01.pdf',
            'DEMO-DOC-PENDING-01', msit_ext_demo_pdf('DEMO-DOC-PENDING-01')],
        ['DEMO ONLY - Extended support item 02.pdf', 'storage/private_uploads/msit-demo-extended/MSIT-DEMO-SUPPORT-02.pdf',
            'DEMO-DOC-READY-01', msit_ext_demo_pdf('DEMO-DOC-READY-01')],
    ];
    foreach ($fileRows as [$originalName, $relative, $requestRef, $bytes]) {
        $asset = msit_ext_write_asset($relative, $bytes, $createdFiles);
        $requestId = db_val('SELECT request_id FROM document_requests WHERE reference_no = ?', [$requestRef]);
        if ($requestId === null) { throw new RuntimeException("Base demo request {$requestRef} is missing."); }
        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $asset['absolute']);
        if ($mime !== 'application/pdf') { throw new RuntimeException('A demo request attachment did not validate as application/pdf.'); }
        db_exec(
            'INSERT INTO document_attachments
             (visit_id, request_id, original_name, storage_path, mime_type, byte_size, sha256, uploaded_by)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, NULL)',
            [(int) $requestId, $originalName, $relative, $mime, $asset['bytes'], $asset['sha256']]
        );
    }
}

function msit_ext_assert_files(): void
{
    $residentRows = db_all(
        "SELECT resident_no, photo_path FROM residents
         WHERE resident_no LIKE 'DEMO-RES-EXT-%' ORDER BY resident_no"
    );
    foreach ($residentRows as $row) {
        $relative = (string) $row['photo_path'];
        $absolute = APP_ROOT . '/' . $relative;
        if (!is_file($absolute) || is_link($absolute) || !str_starts_with($relative, 'storage/resident_photos/msit-demo-extended/')) {
            throw new RuntimeException('A synthetic resident photo is missing or outside the private resident-photo directory: ' . $row['resident_no']);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) { throw new RuntimeException('PHP fileinfo is required to verify demo photo files.'); }
        $mime = finfo_file($finfo, $absolute);
        finfo_close($finfo);
        if ($mime !== 'image/png') { throw new RuntimeException('A synthetic resident photo failed MIME validation: ' . $row['resident_no']); }
    }

    $attachmentRows = db_all(
        "SELECT original_name, storage_path, byte_size, sha256, mime_type
         FROM document_attachments
         WHERE original_name LIKE 'DEMO ONLY - Extended support item %' ORDER BY original_name"
    );
    foreach ($attachmentRows as $row) {
        $relative = (string) $row['storage_path'];
        $absolute = APP_ROOT . '/' . $relative;
        if (!is_file($absolute) || is_link($absolute) || !str_starts_with($relative, 'storage/private_uploads/msit-demo-extended/')) {
            throw new RuntimeException('A private synthetic request attachment is missing or outside private storage.');
        }
        $size = filesize($absolute);
        $hash = hash_file('sha256', $absolute);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) { throw new RuntimeException('PHP fileinfo is required to verify demo attachment files.'); }
        $mime = finfo_file($finfo, $absolute);
        finfo_close($finfo);
        if ($size !== (int) $row['byte_size'] || !is_string($hash) || !hash_equals((string) $row['sha256'], $hash)
            || $mime !== 'application/pdf' || $row['mime_type'] !== 'application/pdf') {
            throw new RuntimeException('A private synthetic request attachment failed size, SHA-256 or MIME verification.');
        }
    }
}

function msit_ext_apply(array &$createdFiles): array
{
    return db_transaction(function () use (&$createdFiles): array {
        $before = msit_ext_counts();
        if (msit_ext_is_complete($before)) {
            msit_ext_assert_files();
            return ['already_seeded' => true, 'counts' => $before];
        }
        if (msit_ext_any_markers($before)) {
            throw new RuntimeException('Reserved extended DEMO markers are partial. No data was changed; inspect the rows and files before retrying.');
        }

        $purokRows = db_all("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id");
        $purokIds = array_map('intval', array_column($purokRows, 'lookup_id'));
        if (!$purokIds) { throw new RuntimeException('No active Puroks exist. Complete the base MSIT demo seeder first.'); }
        $incidentTypeId = msit_ext_lookup_id('incident_type', 'DEMO ONLY - Community concern');

        $avatarPng = msit_ext_demo_avatar_png();
        $residentIds = msit_ext_seed_residents($purokIds, $avatarPng, $createdFiles);
        msit_ext_seed_blotters($residentIds, $incidentTypeId);
        msit_ext_seed_reservations($residentIds);
        msit_ext_seed_queue_history($residentIds);
        msit_ext_seed_attachments($createdFiles);

        // All transitions are labelled as fixtures and deliberately have no impersonated actor.
        $documentHistory = [
            ['DEMO-DOC-READY-01', 'Pending', 'Approved'],
            ['DEMO-DOC-REJECT-01', 'Pending', 'Rejected'],
            ['DEMO-DOC-ISSUED-01', 'Pending', 'Approved'],
            ['DEMO-DOC-ISSUED-01', 'Approved', 'Released'],
        ];
        foreach ($documentHistory as [$reference, $previous, $next]) {
            $requestId = (int) db_val('SELECT request_id FROM document_requests WHERE reference_no = ?', [$reference]);
            if ($requestId < 1) { throw new RuntimeException("Base demo document request {$reference} is missing."); }
            msit_ext_insert_history('Documents', $reference, $previous, $next, $requestId, null, null,
                '[DEMO ONLY] EXTENDED MSIT document status transition fixture; no real staff action occurred.');
        }

        audit('msit_extended_demo_seeded', 'Demo Data', 'Success',
            '[DEMO ONLY] Added Purok-based residents and photos, archived blotter cases/history, venue outcomes/history, historical service queue outcomes and private sample request attachments.');

        $after = msit_ext_counts();
        if (!msit_ext_is_complete($after)) {
            throw new RuntimeException('The extended demo seed did not produce the exact expected marker counts. Transaction will be rolled back.');
        }
        return ['already_seeded' => false, 'counts' => $after];
    });
}

$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) {
    msit_ext_fail("database '{$dbName}' does not end in _work or _test. Original and production databases are refused.");
}
$arguments = array_slice($argv, 1);
foreach ($arguments as $argument) {
    if ($argument !== '--apply') { msit_ext_fail('Usage: php tools/seed_msit_extended_demo_data.php [--apply]'); }
}
if (count($arguments) > 1) { msit_ext_fail('Use --apply at most once.'); }

try {
    msit_ext_require_schema();
    msit_ext_require_base_seed();
    $counts = msit_ext_counts();
    msit_ext_out('Target database: ' . $dbName . ' (isolated working/test database)');
    msit_ext_out('Current extended MSIT DEMO records:');
    foreach ($counts as $name => $count) { msit_ext_out('  ' . str_pad($name, 28) . ': ' . $count); }

    if (msit_ext_is_complete($counts)) {
        msit_ext_assert_files();
        msit_ext_out('Status: extended MSIT demo set already complete. No changes needed.');
        exit(0);
    }
    if (msit_ext_any_markers($counts)) {
        msit_ext_fail('partial extended DEMO markers exist. No database or file changes were made; inspect the working database and private storage before retrying.');
    }

    msit_ext_out('Read-only plan: add 12 synthetic residents across all four SRS resident statuses and all six resident types, each with its own address, Purok and generic private photo; 2 archived blotter cases with complainant/respondent parties; 2 further venue reservation outcomes (Rejected and Completed); 4 historical queue outcomes (Called, Served, Skipped, Cancelled); 11 clearly labelled status-history entries; and 2 optional private DEMO ONLY PDF attachments on sample document requests.');
    msit_ext_out('Not created: visitor-logbook rows (removed by the SRS), demo user accounts/default passwords, paid cash-ledger entries or O.R. numbers, official fees, real contacts/notifications, or a Chairman signature image.');
    msit_ext_out('Nothing was inserted or written.');
    if (!in_array('--apply', $arguments, true)) {
        msit_ext_out('Re-run with --apply to apply this additive extended demo set.');
        exit(0);
    }

    $lockName = 'birms_msit_extended_demo_seed';
    $lock = (int) db_val('SELECT GET_LOCK(?, 10)', [$lockName]);
    if ($lock !== 1) { msit_ext_fail('another extended demo seed operation is running or the database lock could not be acquired.'); }
    $createdFiles = [];
    try {
        $result = msit_ext_apply($createdFiles);
    } finally {
        db_val('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
    msit_ext_out($result['already_seeded'] ? 'Extended MSIT demo data was already present; no rows changed.' : 'Extended MSIT demo data created successfully.');
    foreach ($result['counts'] as $name => $count) { msit_ext_out('  ' . str_pad($name, 28) . ': ' . $count); }
    msit_ext_assert_files();
    msit_ext_out('Private photo and attachment files: integrity checks passed.');
    msit_ext_out('Cash ledger untouched; no paid transaction, approved fee or official receipt was fabricated.');
} catch (Throwable $e) {
    if (isset($createdFiles) && is_array($createdFiles)) {
        foreach (array_reverse($createdFiles) as $path) {
            if (is_file($path) && !is_link($path)) { @unlink($path); }
        }
    }
    error_log('BIRMS extended MSIT demo seeder failed: ' . get_class($e) . ': ' . $e->getMessage());
    msit_ext_fail($e->getMessage());
}
