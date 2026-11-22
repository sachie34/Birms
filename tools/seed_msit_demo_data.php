<?php
/**
 * Safe MSIT-SRS demonstration seeder.
 *
 * CLI only. Creates synthetic residents, document templates/requests, a draft and
 * one issued demo certificate, blotter examples, venues, reservations and queue
 * tickets. Never changes real accounts, official document types, fees or settings.
 *
 * Usage:
 *   php tools/seed_msit_demo_data.php          (read-only plan)
 *   php tools/seed_msit_demo_data.php --apply  (insert once; repeatable)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/lookups.php';
require_once __DIR__ . '/../app/certificates_lib.php';

function msit_demo_out(string $message): void { echo $message . PHP_EOL; }
function msit_demo_fail(string $message): never { fwrite(STDERR, 'REFUSED: ' . $message . PHP_EOL); exit(2); }
function msit_demo_table_exists(string $table): bool
{
    return (int) db_val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) > 0;
}
function msit_demo_column_exists(string $table, string $column): bool
{
    return (int) db_val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) > 0;
}
function msit_demo_require_schema(): void
{
    $required = [
        'lookups' => ['lookup_id','list_key','label','is_active'],
        'residents' => ['resident_id','resident_no','first_name','last_name','birthdate','sex','civil_status','marital_status','address','purok_id','resident_status_id','resident_type_id','email_address','contact_number','notification_preference','household_id','is_household_head','created_by','archived_at'],
        'document_types' => ['type_id','name','fee_amount','template_body','is_active','requires_chairman_signature','sort_order'],
        'document_requests' => ['request_id','reference_no','resident_id','type_id','purpose','status','msit_status','requirements_status','requirements_note','requested_at','created_by'],
        'certificate_drafts' => ['draft_id','request_id','resident_id','type_id','request_reference_no','certificate_reference_no','resident_name','type_name','purpose','fee_amount','body_text','captain_name','signature_text','chairman_signature_path','generated_by','generated_by_name','generated_at'],
        'issuances' => ['issuance_id','reference_no','request_id','resident_id','type_id','resident_name','type_name','purpose','fee_amount','or_number','body_text','captain_name','signature_text','chairman_signature_path','issued_by','issued_by_name','issued_at'],
        'blotters' => ['blotter_id','case_no','incident_date','incident_time','incident_place','incident_type_id','narrative','status','recorded_at','recorded_by','msit_status','purok_id','resolution_note','resolved_at'],
        'blotter_parties' => ['party_id','blotter_id','party_role','full_name','age','address','contact_number'],
        'venues' => ['venue_id','name','description','availability_override','is_active','created_by'],
        'venue_reservations' => ['reservation_id','reference_no','venue_id','resident_id','purpose','reservation_date','start_time','end_time','status','created_by'],
        'queue_entries' => ['queue_id','service','queue_date','queue_number','resident_id','reference_no','purpose','status','issued_via','notification_preference'],
        'request_status_history' => ['history_id','service','reference_no','previous_status','new_status','changed_by','note','request_id','blotter_id','reservation_id'],
    ];
    foreach ($required as $table => $columns) {
        if (!msit_demo_table_exists($table)) { msit_demo_fail("required table {$table} is missing. Apply the current MSIT migrations first."); }
        foreach ($columns as $column) {
            if (!msit_demo_column_exists($table, $column)) { msit_demo_fail("required column {$table}.{$column} is missing. Apply the current MSIT migrations first."); }
        }
    }
}
function msit_demo_lookup_id(string $key, string $label, int $sortOrder): int
{
    $id = db_val('SELECT lookup_id FROM lookups WHERE list_key = ? AND label = ?', [$key, $label]);
    if ($id !== null) {
        db_exec('UPDATE lookups SET is_active = 1 WHERE lookup_id = ?', [(int) $id]);
        return (int) $id;
    }
    db_exec('INSERT INTO lookups (list_key, label, sort_order, is_active) VALUES (?, ?, ?, 1)', [$key, $label, $sortOrder]);
    return (int) db()->lastInsertId();
}
function msit_demo_puroks(): array
{
    $ids = array_map('intval', array_keys(lookup_list('purok')));
    if ($ids) { return $ids; }
    foreach (['DEMO ONLY - Purok 1','DEMO ONLY - Purok 2','DEMO ONLY - Purok 3'] as $i => $label) {
        msit_demo_lookup_id('purok', $label, 9900 + $i);
    }
    return array_map('intval', array_keys(lookup_list('purok')));
}
function msit_demo_marker_counts(): array
{
    return [
        'residents' => (int) db_val("SELECT COUNT(*) FROM residents WHERE resident_no LIKE 'DEMO-RES-%'"),
        'document_types' => (int) db_val("SELECT COUNT(*) FROM document_types WHERE name IN ('DEMO ONLY - Barangay Clearance', 'DEMO ONLY - Certificate of Residency', 'DEMO ONLY - Certificate of Indigency')"),
        'requests' => (int) db_val("SELECT COUNT(*) FROM document_requests WHERE reference_no LIKE 'DEMO-DOC-%'"),
        'drafts' => (int) db_val("SELECT COUNT(*) FROM certificate_drafts d JOIN document_requests r ON r.request_id = d.request_id WHERE r.reference_no LIKE 'DEMO-DOC-%'"),
        'issuances' => (int) db_val("SELECT COUNT(*) FROM issuances WHERE reference_no LIKE 'CERT-%' AND request_id IN (SELECT request_id FROM document_requests WHERE reference_no LIKE 'DEMO-DOC-%')"),
        'blotters' => (int) db_val("SELECT COUNT(*) FROM blotters WHERE case_no LIKE 'MSIT-DEMO-BLT-%'"),
        'venues' => (int) db_val("SELECT COUNT(*) FROM venues WHERE name LIKE 'DEMO ONLY - %'"),
        'reservations' => (int) db_val("SELECT COUNT(*) FROM venue_reservations WHERE reference_no LIKE 'DEMO-VEN-%'"),
        'queues' => (int) db_val("SELECT COUNT(*) FROM queue_entries WHERE purpose LIKE '[DEMO ONLY]%'"),
    ];
}
function msit_demo_is_complete(array $c): bool
{
    return $c === ['residents'=>6,'document_types'=>3,'requests'=>4,'drafts'=>1,'issuances'=>1,'blotters'=>3,'venues'=>2,'reservations'=>2,'queues'=>3];
}
function msit_demo_has_markers(array $c): bool
{
    // Existing demo-only document types may be safely reused and refreshed.
    foreach ($c as $key => $n) {
        if ($key !== 'document_types' && $n > 0) { return true; }
    }
    return false;
}
function msit_demo_template(string $type, string $body): string
{
    return "# DEMO ONLY — NOT AN OFFICIAL DOCUMENT\n"
        . "This fictitious sample is for software demonstration only. It has no legal effect and must not be used for government, school, employment, banking or legal transactions.\n\n"
        . "# " . $type . "\n\n" . $body . "\n\n"
        . "---\n"
        . "Resident: **{{full_name}}**\n"
        . "Address: {{address}}\n"
        . "Purok: {{purok}}\n"
        . "Purpose: {{purpose}}\n"
        . "Date prepared: {{date_issued}}\n"
        . "Reference: {{reference_no}}\n\n"
        . "DEMO ONLY — NOT AN OFFICIAL DOCUMENT";
}
function msit_demo_apply(): array
{
    return db_transaction(function (): array {
        $before = msit_demo_marker_counts();
        if (msit_demo_is_complete($before)) { return ['already_seeded' => true, 'counts' => $before]; }
        if (msit_demo_has_markers($before)) {
            throw new RuntimeException('Some reserved DEMO records already exist, but the complete seed set is not present. No records were changed. Inspect the demo database before retrying.');
        }

        $puroks = msit_demo_puroks();
        $activeStatus = msit_demo_lookup_id('resident_status', 'Active', 1);
        foreach (['Transferred', 'Deceased', 'Non-resident'] as $i => $requiredStatus) {
            msit_demo_lookup_id('resident_status', $requiredStatus, $i + 2);
        }
        $noneType = msit_demo_lookup_id('resident_type', 'None', 1);
        $incidentType = msit_demo_lookup_id('incident_type', 'DEMO ONLY - Community concern', 9900);

        $typeDefinitions = [
            ['DEMO ONLY - Barangay Clearance', msit_demo_template('BARANGAY CLEARANCE', 'This sample confirms that the named fictitious resident is listed in the sample BIRMS registry.')],
            ['DEMO ONLY - Certificate of Residency', msit_demo_template('CERTIFICATE OF RESIDENCY', 'This sample states that the named fictitious resident has a sample address in the selected Purok.')],
            ['DEMO ONLY - Certificate of Indigency', msit_demo_template('CERTIFICATE OF INDIGENCY', 'This sample is a layout demonstration only. It does not determine or certify actual indigency.')],
        ];
        $typeIds = [];
        foreach ($typeDefinitions as $i => [$name, $body]) {
            $existingTypeId = db_val('SELECT type_id FROM document_types WHERE name = ?', [$name]);
            if ($existingTypeId !== null) {
                // Only reserved DEMO ONLY types are refreshed; official document types are never touched.
                db_exec(
                    'UPDATE document_types SET fee_amount = 0.00, template_body = ?, is_active = 1, requires_chairman_signature = 0 WHERE type_id = ?',
                    [$body, (int) $existingTypeId]
                );
                $typeIds[] = (int) $existingTypeId;
            } else {
                db_exec(
                    'INSERT INTO document_types (name, fee_amount, template_body, is_active, requires_chairman_signature, sort_order) VALUES (?, 0.00, ?, 1, 0, ?)',
                    [$name, $body, 9900 + $i]
                );
                $typeIds[] = (int) db()->lastInsertId();
            }
        }

        $residents = [];
        $firstNames = [];
        $lastNames = [];
        $birthdates = [];
        $legacyDemoResidents = db_all(
            "SELECT resident_id, first_name, last_name, birthdate, sex, civil_status
             FROM residents
             WHERE last_name LIKE 'DEMO-%' AND (resident_no IS NULL OR resident_no = '') AND archived_at IS NULL
             ORDER BY resident_id LIMIT 6"
        );
        if (count($legacyDemoResidents) >= 6) {
            // Reuse six synthetic residents from the older presentation seed rather than duplicating them.
            foreach ($legacyDemoResidents as $i => $row) {
                db_exec(
                    'UPDATE residents
                     SET resident_no = ?, address = ?, purok_id = ?, resident_status_id = ?, marital_status = ?, household_id = NULL,
                         resident_type_id = ?, email_address = ?, contact_number = NULL,
                         notification_preference = \'email\', archived_at = NULL
                     WHERE resident_id = ? AND (resident_no IS NULL OR resident_no = \'\')',
                    [
                        sprintf('DEMO-RES-%04d', $i + 1), sprintf('DEMO ONLY - Sample Street %02d', $i + 1),
                        $puroks[$i % count($puroks)], $activeStatus, (string) ($row['civil_status'] ?? 'Single'),
                        $noneType, sprintf('demo%02d@example.invalid', $i + 1), (int) $row['resident_id'],
                    ]
                );
                $residents[] = (int) $row['resident_id'];
                $firstNames[] = (string) $row['first_name'];
                $lastNames[] = (string) $row['last_name'];
                $birthdates[] = (string) $row['birthdate'];
            }
        } else {
            $firstNames = ['DemoAlex','DemoJamie','DemoCasey','DemoMorgan','DemoRiley','DemoTaylor'];
            $lastNames = ['SampleRivera','SampleSantos','SampleCruz','SampleReyes','SampleGarcia','SampleMendoza'];
            $birthdates = ['1992-04-12','1988-09-30','2001-03-15','1998-10-06','1979-01-22','1995-07-11'];
            foreach ($firstNames as $i => $first) {
                $residentNo = sprintf('DEMO-RES-%04d', $i + 1);
                db_exec(
                    'INSERT INTO residents
                     (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
                      email_address, notification_preference, last_name, first_name, birthdate, sex, civil_status,
                      household_id, is_household_head, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, \'email\', ?, ?, ?, ?, ?, NULL, 0, NULL)',
                    [
                        $residentNo, sprintf('DEMO ONLY - Sample Street %02d', $i + 1), $puroks[$i % count($puroks)],
                        $activeStatus, 'Single', $noneType, sprintf('demo%02d@example.invalid', $i + 1),
                        $lastNames[$i], $first, $birthdates[$i], $i % 2 === 0 ? 'Male' : 'Female',
                        $i % 2 === 0 ? 'Single' : 'Married',
                    ]
                );
                $residents[] = (int) db()->lastInsertId();
            }
        }

        $docSpecs = [
            ['DEMO-DOC-PENDING-01', 0, 'Pending', 'Pending', 'Not checked'],
            ['DEMO-DOC-READY-01', 1, 'Ready', 'Approved', 'Complete'],
            ['DEMO-DOC-REJECT-01', 2, 'Rejected', 'Rejected', 'Complete'],
            ['DEMO-DOC-ISSUED-01', 0, 'Claimed', 'Released', 'Complete'],
        ];
        $requestIds = [];
        foreach ($docSpecs as $i => [$ref, $typeIndex, $legacyStatus, $publicStatus, $requirements]) {
            $residentIndex = $i % count($residents);
            $purpose = '[DEMO ONLY] Sample request ' . sprintf('%02d', $i + 1);
            db_exec(
                'INSERT INTO document_requests
                 (reference_no, resident_id, type_id, purpose, status, msit_status, requirements_status, requirements_note, requested_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL)',
                [$ref, $residents[$residentIndex], $typeIds[$typeIndex], $purpose, $legacyStatus, $publicStatus, $requirements,
                 '[DEMO ONLY] Fictitious sample request state. No official requirements were asserted.']
            );
            $requestIds[] = (int) db()->lastInsertId();
            db_exec(
                'INSERT INTO request_status_history (service, reference_no, previous_status, new_status, changed_by, note, request_id)
                 VALUES (\'documents\', ?, NULL, ?, NULL, \'[DEMO ONLY] Seeded presentation example.\', ?)',
                [$ref, $publicStatus, $requestIds[$i]]
            );
        }

        $draftReference = sprintf('CERT-%d-9001', (int) date('Y'));
        $draftBody = render_template((string) $typeDefinitions[1][1], [
            'full_name' => $firstNames[1] . ' ' . $lastNames[1], 'age' => '38', 'civil_status' => 'Married',
            'address' => 'DEMO ONLY - Sample Street 02', 'purok' => lookup_label($puroks[1 % count($puroks)]),
            'purpose' => '[DEMO ONLY] Software demonstration', 'date_issued' => date('j F Y'),
            'barangay' => setting('barangay_name', 'Wawa'), 'municipality' => setting('municipality', 'Balagtas'),
            'province' => setting('province', 'Bulacan'), 'reference_no' => $draftReference,
            'captain_name' => '', 'document_name' => 'DEMO ONLY - Certificate of Residency',
            'day_ordinal' => date('jS'), 'month_year' => date('F Y'),
        ]);
        db_exec(
            'INSERT INTO certificate_drafts
             (request_id, resident_id, type_id, request_reference_no, certificate_reference_no, resident_name, type_name,
              purpose, fee_amount, body_text, captain_name, signature_text, chairman_signature_path, generated_by,
              generated_by_name, generated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, NULL, NULL, NULL, NULL, \'DEMO DATA\', NOW())',
            [$requestIds[1], $residents[1], $typeIds[1], 'DEMO-DOC-READY-01', $draftReference,
             $firstNames[1] . ' ' . $lastNames[1], 'DEMO ONLY - Certificate of Residency',
             '[DEMO ONLY] Software demonstration', $draftBody]
        );

        $issuedReference = sprintf('CERT-%d-9002', (int) date('Y'));
        $issuedBody = render_template((string) $typeDefinitions[0][1], [
            'full_name' => $firstNames[3] . ' ' . $lastNames[3], 'age' => '28', 'civil_status' => 'Married',
            'address' => 'DEMO ONLY - Sample Street 04', 'purok' => lookup_label($puroks[3 % count($puroks)]),
            'purpose' => '[DEMO ONLY] Previously issued sample', 'date_issued' => date('j F Y'),
            'barangay' => setting('barangay_name', 'Wawa'), 'municipality' => setting('municipality', 'Balagtas'),
            'province' => setting('province', 'Bulacan'), 'reference_no' => $issuedReference,
            'captain_name' => '', 'document_name' => 'DEMO ONLY - Barangay Clearance',
            'day_ordinal' => date('jS'), 'month_year' => date('F Y'),
        ]);
        db_exec(
            'INSERT INTO issuances
             (reference_no, request_id, resident_id, type_id, resident_name, type_name, purpose, fee_amount, or_number,
              body_text, captain_name, signature_text, chairman_signature_path, issued_by, issued_by_name, issued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, NULL, ?, NULL, NULL, NULL, NULL, \'DEMO DATA\', NOW())',
            [$issuedReference, $requestIds[3], $residents[3], $typeIds[0],
             $firstNames[3] . ' ' . $lastNames[3], 'DEMO ONLY - Barangay Clearance',
             '[DEMO ONLY] Previously issued sample', $issuedBody]
        );

        $venues = [];
        foreach ([
            ['DEMO ONLY - Covered Court', 'Fictitious sample venue for availability and reservation demonstrations.'],
            ['DEMO ONLY - Multi-purpose Hall', 'Fictitious sample venue for availability and reservation demonstrations.'],
        ] as [$name, $description]) {
            db_exec('INSERT INTO venues (name, description, availability_override, is_active, created_by) VALUES (?, ?, \'automatic\', 1, NULL)', [$name, $description]);
            $venues[] = (int) db()->lastInsertId();
        }
        $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $venueRefs = ['DEMO-VEN-0001', 'DEMO-VEN-0002'];
        $venueStatuses = ['Approved', 'Pending'];
        foreach ($venueRefs as $i => $ref) {
            db_exec(
                'INSERT INTO venue_reservations
                 (reference_no, venue_id, resident_id, purpose, reservation_date, start_time, end_time, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
                [$ref, $venues[$i], $residents[$i], '[DEMO ONLY] Sample venue reservation',
                 $tomorrow, $i === 0 ? '09:00:00' : '13:00:00', $i === 0 ? '10:00:00' : '14:00:00', $venueStatuses[$i]]
            );
            db_exec(
                'INSERT INTO request_status_history (service, reference_no, previous_status, new_status, changed_by, note, reservation_id)
                 VALUES (\'venue\', ?, NULL, ?, NULL, \'[DEMO ONLY] Seeded presentation example.\', ?)',
                [$ref, $venueStatuses[$i], (int) db()->lastInsertId()]
            );
        }

        $caseRefs = ['MSIT-DEMO-BLT-0001','MSIT-DEMO-BLT-0002','MSIT-DEMO-BLT-0003'];
        $caseStatuses = ['Ongoing','Unresolved','Resolved'];
        foreach ($caseRefs as $i => $caseRef) {
            db_exec(
                'INSERT INTO blotters
                 (case_no, incident_date, incident_time, incident_place, purok_id, incident_type_id, narrative,
                  status, msit_status, recorded_at, recorded_by, resolution_note, resolved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, ?, ?)',
                [$caseRef, date('Y-m-d', strtotime('-' . ($i + 1) . ' days')), '09:30:00',
                 'DEMO ONLY - Sample location ' . ($i + 1), $puroks[$i % count($puroks)], $incidentType,
                 '[DEMO ONLY] Fictitious incident narrative created only to demonstrate the blotter workflow.',
                 $i === 2 ? 'Resolved' : 'Active', $caseStatuses[$i],
                 $i === 2 ? '[DEMO ONLY] Sample resolution note.' : null,
                 $i === 2 ? date('Y-m-d H:i:s') : null]
            );
            $caseId = (int) db()->lastInsertId();
            db_exec(
                'INSERT INTO blotter_parties (blotter_id, party_role, full_name, age, address, contact_number)
                 VALUES (?, \'Complainant\', ?, 30, ?, NULL), (?, \'Respondent\', ?, 31, ?, NULL)',
                [$caseId, $firstNames[$i] . ' ' . $lastNames[$i], 'DEMO ONLY - Sample Address ' . ($i + 1),
                 $caseId, 'DemoRespondent' . ($i + 1), 'DEMO ONLY - Sample Address ' . ($i + 2)]
            );
            db_exec(
                'INSERT INTO request_status_history (service, reference_no, previous_status, new_status, changed_by, note, blotter_id)
                 VALUES (\'blotter\', ?, NULL, ?, NULL, \'[DEMO ONLY] Seeded presentation example.\', ?)',
                [$caseRef, $caseStatuses[$i], $caseId]
            );
        }

        $queueItems = [
            ['documents', $residents[0], 'DEMO-DOC-PENDING-01', '[DEMO ONLY] Documents service queue sample'],
            ['blotter', $residents[1], 'DEMO-BLT-0001', '[DEMO ONLY] Blotter service queue sample'],
            ['venue', $residents[2], 'DEMO-VEN-0001', '[DEMO ONLY] Venue service queue sample'],
        ];
        foreach ($queueItems as [$service, $residentId, $reference, $purpose]) {
            $next = (int) db_val('SELECT COALESCE(MAX(queue_number), 0) + 1 FROM queue_entries WHERE service = ? AND queue_date = CURDATE()', [$service]);
            db_exec(
                'INSERT INTO queue_entries
                 (service, queue_date, queue_number, resident_id, reference_no, purpose, status, issued_via, notification_preference)
                 VALUES (?, CURDATE(), ?, ?, ?, ?, \'Waiting\', \'staff\', \'email\')',
                [$service, $next, $residentId, $reference, $purpose]
            );
        }

        audit('msit_demo_data_seeded', 'Demo Data', 'Success',
            'Created clearly labelled fictitious MSIT demonstration records: residents, document templates/requests, certificate draft/history, blotter cases, venues, reservations and queue tickets.');
        return ['already_seeded' => false, 'counts' => msit_demo_marker_counts()];
    });
}

$dbName = (string) config('db.name');
if (!preg_match('/(_work|_test)$/', $dbName)) {
    msit_demo_fail("database '{$dbName}' does not end in _work or _test. The original barangay_db and every production database are refused.");
}
try {
    msit_demo_require_schema();
    $counts = msit_demo_marker_counts();
    msit_demo_out('Target database: ' . $dbName . ' (isolated working/test database)');
    msit_demo_out('Current reserved DEMO records:');
    foreach ($counts as $name => $count) { msit_demo_out('  ' . str_pad($name, 16) . ': ' . $count); }
    if (msit_demo_is_complete($counts)) {
        msit_demo_out('Status: complete demo dataset already exists. No changes needed.');
        exit(0);
    }
    if (msit_demo_has_markers($counts)) {
        msit_demo_fail('partial reserved DEMO records exist. No rows were changed; inspect the working database before retrying.');
    }
    msit_demo_out('Read-only plan: add 6 fictitious residents, 3 DEMO ONLY certificate templates, 4 document requests (including one releasable draft and one issued history item), 3 blotter cases, 2 demo venues, 2 reservations and 3 queue tickets.');
    if (!in_array('--apply', $argv, true)) {
        msit_demo_out('Nothing was inserted. Re-run with --apply to create the demo data.');
        exit(0);
    }
    $result = msit_demo_apply();
    msit_demo_out($result['already_seeded'] ? 'Demo dataset was already present; no changes made.' : 'Demo dataset created successfully.');
    foreach ($result['counts'] as $name => $count) { msit_demo_out('  ' . str_pad($name, 16) . ': ' . $count); }
} catch (Throwable $e) {
    error_log('BIRMS MSIT demo seeder failed: ' . get_class($e) . ': ' . $e->getMessage());
    msit_demo_fail($e->getMessage());
}
