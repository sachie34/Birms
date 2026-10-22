<?php
/**
 * Safe, repeatable BIRMS presentation-data seeder.
 *
 * CLI only. It never changes official settings, configured document types, fees, or user accounts.
 * Run the tool against an explicitly disposable *_work or *_test database only.
 *
 * Commands:
 *   php tools/seed_demo_data.php                 (read-only plan)
 *   php tools/seed_demo_data.php --apply         (insert demo dataset once)
 *   php tools/seed_demo_data.php --remove --confirm-demo-only
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/lookups.php';

const DEMO_SEED_HOUSEHOLD_CODES = [
    'DEMO-HH-01', 'DEMO-HH-02', 'DEMO-HH-03', 'DEMO-HH-04',
    'DEMO-HH-05', 'DEMO-HH-06', 'DEMO-HH-07', 'DEMO-HH-08',
];
const DEMO_SEED_LAST_NAMES = [
    'DEMO-Rivera', 'DEMO-Santos', 'DEMO-Cruz', 'DEMO-Reyes', 'DEMO-Garcia', 'DEMO-Mendoza',
    'DEMO-Torres', 'DEMO-Flores', 'DEMO-Ramos', 'DEMO-Castillo', 'DEMO-Gonzales', 'DEMO-Perez',
    'DEMO-Aquino', 'DEMO-DelaCruz', 'DEMO-Bautista', 'DEMO-Villanueva', 'DEMO-Fernandez',
    'DEMO-Lopez', 'DEMO-Gomez', 'DEMO-Diaz', 'DEMO-Morales', 'DEMO-Soriano', 'DEMO-Rodriguez',
    'DEMO-Martinez',
];
const DEMO_SEED_TYPE_NAMES = [
    'DEMO ONLY - Barangay Clearance',
    'DEMO ONLY - Certificate of Residency',
    'DEMO ONLY - Certificate of Indigency',
];
const DEMO_SEED_VISITOR_NAMES = [
    'DEMO ONLY - Visitor 01', 'DEMO ONLY - Visitor 02', 'DEMO ONLY - Visitor 03', 'DEMO ONLY - Visitor 04',
    'DEMO ONLY - Visitor 05', 'DEMO ONLY - Visitor 06', 'DEMO ONLY - Visitor 07', 'DEMO ONLY - Visitor 08',
];
const DEMO_SEED_VISITOR_PURPOSES = [
    '[DEMO ONLY] General inquiry 01', '[DEMO ONLY] Document request 02',
    '[DEMO ONLY] General inquiry 03', '[DEMO ONLY] Document request 04',
    '[DEMO ONLY] General inquiry 05', '[DEMO ONLY] Document request 06',
    '[DEMO ONLY] General inquiry 07', '[DEMO ONLY] Document request 08',
];
const DEMO_SEED_REQUEST_REFS = [
    'DEMO-REQ-01', 'DEMO-REQ-02', 'DEMO-REQ-03', 'DEMO-REQ-04',
    'DEMO-REQ-05', 'DEMO-REQ-06', 'DEMO-REQ-07', 'DEMO-REQ-08',
    'DEMO-REQ-09', 'DEMO-REQ-10', 'DEMO-REQ-11', 'DEMO-REQ-12',
];
const DEMO_SEED_ISSUANCE_REFS = [
    'DEMO-CERT-01', 'DEMO-CERT-02', 'DEMO-CERT-03', 'DEMO-CERT-04',
];
const DEMO_SEED_CASE_REFS = ['DEMO-BLT-2026-01', 'DEMO-BLT-2026-02', 'DEMO-BLT-2026-03', 'DEMO-BLT-2026-04'];

function demo_out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}
function demo_fail(string $message, int $code = 2): never
{
    fwrite(STDERR, 'REFUSED: ' . $message . PHP_EOL);
    exit($code);
}
function demo_placeholders(array $values): string
{
    if (!$values) { throw new InvalidArgumentException('An IN-list needs at least one value.'); }
    return implode(',', array_fill(0, count($values), '?'));
}
function demo_table_exists(string $table): bool
{
    return (int) db_val(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    ) > 0;
}
function demo_column_exists(string $table, string $column): bool
{
    return (int) db_val(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$table, $column]
    ) > 0;
}
function demo_require_safe_database(): void
{
    $name = (string) config('db.name');
    if (!preg_match('/_(work|test)$/i', $name)) {
        demo_fail("database '{$name}' is not named *_work or *_test. The seeder never runs against the original barangay_db or a production-named database.");
    }
    if (!demo_table_exists('schema_migrations')) {
        demo_fail('schema_migrations is missing. Run the normal BIRMS setup/migrations against a disposable working database first.');
    }
    foreach ([
        ['households', 'household_code'], ['residents', 'employment_status_id'],
        ['lookups', 'list_key'], ['document_types', 'template_body'], ['document_requests', 'requirements_status'],
        ['issuances', 'body_text'], ['visitor_log', 'purpose'], ['blotters', 'incident_type_id'],
        ['blotter_parties', 'party_role'], ['hearings', 'scheduled_at'],
    ] as [$table, $column]) {
        if (!demo_table_exists($table) || !demo_column_exists($table, $column)) {
            demo_fail("required latest-schema object {$table}.{$column} is missing. Apply the repository's current migrations first; this script does not migrate databases.");
        }
    }
}
function demo_seed_lookup_labels(): array
{
    return [
        'purok' => ['DEMO ONLY - Purok 1', 'DEMO ONLY - Purok 2', 'DEMO ONLY - Purok 3'],
        'resident_category' => ['DEMO ONLY - Adult', 'DEMO ONLY - Youth', 'DEMO ONLY - Senior Citizen'],
        'employment_status' => ['DEMO ONLY - Employed', 'DEMO ONLY - Student', 'DEMO ONLY - Not employed', 'DEMO ONLY - Retired'],
        'visit_class' => ['DEMO ONLY - Document inquiry', 'DEMO ONLY - General inquiry', 'DEMO ONLY - Follow-up'],
        'incident_type' => ['DEMO ONLY - Community concern', 'DEMO ONLY - Noise concern', 'DEMO ONLY - Property concern'],
    ];
}
function demo_seed_lookup_ids(string $key, array $fallbackLabels): array
{
    $ids = array_map('intval', array_keys(lookup_list($key)));
    if ($ids) { return $ids; }

    foreach ($fallbackLabels as $i => $label) {
        db_exec(
            'INSERT INTO lookups (list_key, label, sort_order, is_active) VALUES (?, ?, ?, 1)',
            [$key, $label, 9900 + $i]
        );
    }
    return array_map('intval', array_keys(lookup_list($key)));
}
function demo_seed_marker_counts(): array
{
    $householdSql = demo_placeholders(DEMO_SEED_HOUSEHOLD_CODES);
    $residentSql = demo_placeholders(DEMO_SEED_LAST_NAMES);
    $typeSql = demo_placeholders(DEMO_SEED_TYPE_NAMES);
    $visitorSql = demo_placeholders(DEMO_SEED_VISITOR_NAMES);
    $visitPurpSql = demo_placeholders(DEMO_SEED_VISITOR_PURPOSES);
    $requestSql = demo_placeholders(DEMO_SEED_REQUEST_REFS);
    $issuanceSql = demo_placeholders(DEMO_SEED_ISSUANCE_REFS);
    $caseSql = demo_placeholders(DEMO_SEED_CASE_REFS);

    $counts = [
        'households' => (int) db_val("SELECT COUNT(*) FROM households WHERE household_code IN ({$householdSql})", DEMO_SEED_HOUSEHOLD_CODES),
        'residents' => (int) db_val("SELECT COUNT(*) FROM residents WHERE last_name IN ({$residentSql})", DEMO_SEED_LAST_NAMES),
        'document_types' => (int) db_val("SELECT COUNT(*) FROM document_types WHERE name IN ({$typeSql})", DEMO_SEED_TYPE_NAMES),
        'requests' => (int) db_val("SELECT COUNT(*) FROM document_requests WHERE reference_no IN ({$requestSql})", DEMO_SEED_REQUEST_REFS),
        'issuances' => (int) db_val("SELECT COUNT(*) FROM issuances WHERE reference_no IN ({$issuanceSql})", DEMO_SEED_ISSUANCE_REFS),
        'visitors' => (int) db_val("SELECT COUNT(*) FROM visitor_log WHERE visitor_name IN ({$visitorSql}) OR purpose IN ({$visitPurpSql})", array_merge(DEMO_SEED_VISITOR_NAMES, DEMO_SEED_VISITOR_PURPOSES)),
        'blotters' => (int) db_val("SELECT COUNT(*) FROM blotters WHERE case_no IN ({$caseSql})", DEMO_SEED_CASE_REFS),
        'hearings' => (int) db_val(
            "SELECT COUNT(*) FROM hearings WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE case_no IN ({$caseSql}))",
            DEMO_SEED_CASE_REFS
        ),
        'demo_lookups' => (int) db_val("SELECT COUNT(*) FROM lookups WHERE label LIKE 'DEMO ONLY - %' AND list_key IN ('purok','resident_category','employment_status','visit_class','incident_type')"),
    ];
    return $counts;
}
function demo_seed_counts_are_complete(array $c): bool
{
    return ($c['households'] ?? 0) === 8 && ($c['residents'] ?? 0) === 24
        && ($c['document_types'] ?? 0) === 3 && ($c['requests'] ?? 0) === 12
        && ($c['issuances'] ?? 0) === 4 && ($c['visitors'] ?? 0) === 8
        && ($c['blotters'] ?? 0) === 4 && ($c['hearings'] ?? 0) === 1;
}
function demo_seed_has_markers(array $c): bool
{
    foreach ($c as $key => $count) {
        if ($key !== 'demo_lookups' && $count > 0) { return true; }
    }
    return ($c['demo_lookups'] ?? 0) > 0;
}
function demo_seed_output_counts(array $counts): void
{
    foreach ($counts as $label => $value) {
        demo_out(str_pad($label, 16) . ': ' . $value);
    }
}
function demo_date_in_months_ago(int $monthsAgo, int $day = 10, int $hour = 10): string
{
    $first = new DateTimeImmutable('first day of this month 00:00:00');
    $date = $first->modify('-' . $monthsAgo . ' months')->modify('+' . max(0, $day - 1) . ' days');
    return $date->setTime($hour, 0)->format('Y-m-d H:i:s');
}
function demo_seed_apply(): array
{
    return db_transaction(function (): array {
        $before = demo_seed_marker_counts();
        if (demo_seed_has_markers($before)) {
            if (demo_seed_counts_are_complete($before)) {
                return ['already_seeded' => true, 'counts' => $before];
            }
            throw new RuntimeException('Reserved DEMO markers already exist but the full seed set is not present. No rows were changed. Inspect the database before retrying or use --remove --confirm-demo-only to remove only the reserved demo dataset.');
        }

        // Existing active configuration is reused, never updated. DEMO ONLY fallback lookups are added only when a list is empty.
        $lookups = demo_seed_lookup_labels();
        $puroks = demo_seed_lookup_ids('purok', $lookups['purok']);
        $categories = demo_seed_lookup_ids('resident_category', $lookups['resident_category']);
        $employment = demo_seed_lookup_ids('employment_status', $lookups['employment_status']);
        $visitClasses = demo_seed_lookup_ids('visit_class', $lookups['visit_class']);
        $incidentTypes = demo_seed_lookup_ids('incident_type', $lookups['incident_type']);

        $template = "DEMO ONLY - NOT AN OFFICIAL CERTIFICATE.\n"
            . "This fictitious document was created for a software demonstration. It has no legal effect.\n"
            . "Resident: {{full_name}}\nDocument: {{document_name}}\nPurpose: {{purpose}}\nReference: {{reference_no}}";
        $typeIds = [];
        foreach (DEMO_SEED_TYPE_NAMES as $i => $typeName) {
            db_exec(
                'INSERT INTO document_types (name, fee_amount, template_body, is_active, sort_order) VALUES (?, 0.00, ?, 1, ?)',
                [$typeName, $template, 9900 + $i]
            );
            $typeIds[] = (int) db()->lastInsertId();
        }

        $firstNames = [
            'Alex', 'Jamie', 'Casey', 'Morgan', 'Riley', 'Taylor', 'Jordan', 'Avery',
            'Cameron', 'Drew', 'Emerson', 'Frankie', 'Harper', 'Kai', 'Logan', 'Micah',
            'Noah', 'Parker', 'Quinn', 'Reese', 'Sam', 'Skyler', 'Rowan', 'Sage',
        ];
        $birthdates = [
            '1979-04-12','1981-09-30','2008-03-15','1998-10-06','1962-01-22','1957-07-11',
            '2004-08-09','1990-12-02','2012-06-18','1972-02-03','1988-03-21','1959-11-10',
            '1995-05-17','2001-09-27','1980-04-04','1968-06-13','2006-10-21','1976-12-07',
            '1992-02-02','1955-05-08','2009-01-30','1986-08-14','1999-10-01','1970-03-03',
        ];
        $sexes = [
            'Male','Female','Female','Male','Female','Male','Female','Male','Male','Female','Male','Female',
            'Female','Male','Female','Male','Female','Male','Female','Male','Male','Female','Female','Male',
        ];
        $civil = [
            'Married','Married','Single','Single','Widowed','Married','Single','Single','Single','Married','Married','Widowed',
            'Single','Single','Married','Married','Single','Married','Single','Widowed','Single','Married','Single','Married',
        ];
        $voter = [
            'Registered','Registered','Not registered','Registered','Registered','Registered','Unverified','Registered',
            'Not registered','Registered','Unverified','Registered','Registered','Registered','Registered','Registered',
            'Registered','Not registered','Unverified','Registered','Not registered','Registered','Registered','Registered',
        ];

        $householdIds = [];
        for ($i = 0; $i < count(DEMO_SEED_HOUSEHOLD_CODES); $i++) {
            $purokId = $puroks[$i % count($puroks)];
            db_exec(
                'INSERT INTO households (household_code, purok_id, street_address) VALUES (?, ?, ?)',
                [DEMO_SEED_HOUSEHOLD_CODES[$i], $purokId, sprintf('DEMO ONLY - Sample Street %02d', $i + 1)]
            );
            $householdIds[] = (int) db()->lastInsertId();
        }

        $residentIds = [];
        for ($i = 0; $i < 24; $i++) {
            $householdIndex = intdiv($i, 3);
            $residency = in_array($i, [13, 21], true) ? 'Relocated' : 'Active';
            $categoryId = $categories[$i % count($categories)] ?? null;
            $employmentId = $employment[$i % count($employment)] ?? null;
            db_exec(
                'INSERT INTO residents (household_id, last_name, first_name, birthdate, sex, civil_status, contact_number,
                    category_id, employment_status_id, residency_status, voter_status, is_household_head, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, NULL)',
                [
                    $householdIds[$householdIndex], DEMO_SEED_LAST_NAMES[$i], $firstNames[$i], $birthdates[$i],
                    $sexes[$i], $civil[$i], $categoryId, $employmentId, $residency, $voter[$i],
                    ($i % 3) === 0 ? 1 : 0,
                ]
            );
            $residentIds[] = (int) db()->lastInsertId();
        }

        $visitorIds = [];
        $visitDate = [
            demo_date_in_months_ago(2, 3, 9), demo_date_in_months_ago(1, 8, 10),
            demo_date_in_months_ago(0, 3, 11), demo_date_in_months_ago(0, 7, 13),
            demo_date_in_months_ago(1, 12, 9), demo_date_in_months_ago(0, 12, 10),
            demo_date_in_months_ago(2, 16, 14), demo_date_in_months_ago(0, 19, 15),
        ];
        for ($i = 0; $i < count(DEMO_SEED_VISITOR_NAMES); $i++) {
            db_exec(
                'INSERT INTO visitor_log (visited_at, visitor_name, purpose, class_id, logged_by) VALUES (?, ?, ?, ?, NULL)',
                [$visitDate[$i], DEMO_SEED_VISITOR_NAMES[$i], DEMO_SEED_VISITOR_PURPOSES[$i], $visitClasses[$i % count($visitClasses)]]
            );
            $visitorIds[] = (int) db()->lastInsertId();
        }

        $statuses = ['Pending','Review','Ready','Claimed','Pending','Review','Ready','Claimed','Claimed','Rejected','Cancelled','Claimed'];
        $monthOffsets = [0,0,0,0,1,1,1,1,2,2,2,2];
        $requestIds = [];
        $issueRows = [];
        $caseDays = [3, 9, 15, 24];
        for ($i = 0; $i < count(DEMO_SEED_REQUEST_REFS); $i++) {
            $status = $statuses[$i];
            $residentId = $residentIds[($i * 2 + 1) % count($residentIds)];
            $typeId = $typeIds[$i % count($typeIds)];
            $typeName = DEMO_SEED_TYPE_NAMES[$i % count(DEMO_SEED_TYPE_NAMES)];
            $months = $monthOffsets[$i];
            $requestedAt = demo_date_in_months_ago($months, 6 + ($i % 19), 10);
            $requirements = in_array($status, ['Ready','Claimed'], true) ? 'Complete' : 'Not checked';
            $purpose = '[DEMO ONLY] Sample ' . strtolower($typeName) . ' request ' . sprintf('%02d', $i + 1);
            $visitId = $i < count($visitorIds) ? $visitorIds[$i] : null;

            db_exec(
                'INSERT INTO document_requests
                    (reference_no, visit_id, resident_id, type_id, purpose, status, requested_at, created_by, requirements_status, requirements_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
                [
                    DEMO_SEED_REQUEST_REFS[$i], $visitId, $residentId, $typeId, $purpose, $status,
                    $requestedAt, $requirements, '[DEMO ONLY] Sample requirements state for presentation.',
                ]
            );
            $requestId = (int) db()->lastInsertId();
            $requestIds[] = $requestId;
            if ($status === 'Claimed') {
                $issueRows[] = [
                    'request_id' => $requestId, 'resident_id' => $residentId, 'type_id' => $typeId,
                    'resident_name' => $firstNames[array_search($residentId, $residentIds, true)] . ' ' . DEMO_SEED_LAST_NAMES[array_search($residentId, $residentIds, true)],
                    'type_name' => $typeName, 'purpose' => $purpose,
                    'issued_at' => demo_date_in_months_ago($months, 9 + ($i % 18), 11),
                ];
            }
        }

        foreach ($issueRows as $i => $issue) {
            $reference = DEMO_SEED_ISSUANCE_REFS[$i];
            $body = "DEMO ONLY - NOT AN OFFICIAL CERTIFICATE.\n"
                . "This is a fictitious record for software demonstration only. It is not valid for government, employment, school, banking, or legal use.\n"
                . 'Resident: ' . $issue['resident_name'] . "\n"
                . 'Document: ' . $issue['type_name'] . "\n"
                . 'Purpose: ' . $issue['purpose'] . "\n"
                . 'Reference: ' . $reference;
            db_exec(
                'INSERT INTO issuances (reference_no, request_id, resident_id, type_id, resident_name, type_name, purpose,
                    fee_amount, or_number, body_text, captain_name, signature_text, issued_by, issued_by_name, issued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, NULL, ?, NULL, NULL, NULL, ?, ?)',
                [
                    $reference, $issue['request_id'], $issue['resident_id'], $issue['type_id'], $issue['resident_name'],
                    $issue['type_name'], $issue['purpose'], $body, 'DEMO DATA SEEDER (NOT AN OFFICIAL)', $issue['issued_at'],
                ]
            );
        }

        $incidentIds = $incidentTypes;
        $caseStatuses = ['Pending','Active','Scheduled','Resolved'];
        $incidentDates = [
            date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('-9 days')),
            date('Y-m-d', strtotime('-15 days')), date('Y-m-d', strtotime('-24 days')),
        ];
        $caseIds = [];
        for ($i = 0; $i < count(DEMO_SEED_CASE_REFS); $i++) {
            $status = $caseStatuses[$i];
            $incidentTypeId = $incidentIds[$i % count($incidentIds)];
            $resolution = $status === 'Resolved' ? '[DEMO ONLY] Sample resolution; not a real incident.' : null;
            $resolvedAt = $status === 'Resolved' ? demo_date_in_months_ago(0, 1, 16) : null;
            db_exec(
                'INSERT INTO blotters (case_no, incident_date, incident_place, purok_id, incident_type_id, narrative,
                    status, resolution_note, resolved_at, recorded_at, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL)',
                [
                    DEMO_SEED_CASE_REFS[$i], $incidentDates[$i], 'DEMO ONLY - Sample location ' . ($i + 1),
                    $puroks[$i % count($puroks)], $incidentTypeId,
                    '[DEMO ONLY] Fictional community case narrative ' . ($i + 1) . '. No real persons or events are represented.',
                    $status, $resolution, $resolvedAt,
                ]
            );
            $caseIds[] = (int) db()->lastInsertId();
            $partyNames = [
                ['DEMO ONLY Complainant ' . ($i + 1), 'Complainant', 30 + $i],
                ['DEMO ONLY Respondent ' . ($i + 1), 'Respondent', 35 + $i],
            ];
            foreach ($partyNames as [$partyName, $role, $age]) {
                db_exec(
                    'INSERT INTO blotter_parties (blotter_id, party_role, full_name, age, address, contact_number)
                     VALUES (?, ?, ?, ?, ?, NULL)',
                    [$caseIds[$i], $role, $partyName, $age, 'DEMO ONLY - Sample address']
                );
            }
        }

        $hearingAt = (new DateTimeImmutable('now'))->modify('+5 days')->setTime(10, 0)->format('Y-m-d H:i:s');
        db_exec(
            'INSERT INTO hearings (blotter_id, scheduled_at, venue, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, NULL)',
            [$caseIds[2], $hearingAt, 'DEMO ONLY - Sample meeting room', '[DEMO ONLY] Sample future hearing.', 'Scheduled']
        );

        audit('demo_data_seeded', 'Demo data', 'Success',
            'Inserted 24 fictitious residents, 8 households, 12 requests, 4 demo-only issuance records, 8 visitor entries, 4 fictitious blotter cases, and 1 hearing.');
        return ['already_seeded' => false, 'counts' => demo_seed_marker_counts()];
    });
}

function demo_seed_check_removal_dependencies(): array
{
    $residentSql = demo_placeholders(DEMO_SEED_LAST_NAMES);
    $householdSql = demo_placeholders(DEMO_SEED_HOUSEHOLD_CODES);
    $typeSql = demo_placeholders(DEMO_SEED_TYPE_NAMES);
    $visitorSql = demo_placeholders(DEMO_SEED_VISITOR_NAMES);
    $requestSql = demo_placeholders(DEMO_SEED_REQUEST_REFS);
    $issuanceSql = demo_placeholders(DEMO_SEED_ISSUANCE_REFS);
    $caseSql = demo_placeholders(DEMO_SEED_CASE_REFS);
    $check = [];

    $check['non_demo_residents_in_demo_households'] = (int) db_val(
        "SELECT COUNT(*) FROM residents WHERE household_id IN (SELECT household_id FROM households WHERE household_code IN ({$householdSql})) AND last_name NOT IN ({$residentSql})",
        array_merge(DEMO_SEED_HOUSEHOLD_CODES, DEMO_SEED_LAST_NAMES)
    );
    $check['other_requests_for_demo_residents_or_types'] = (int) db_val(
        "SELECT COUNT(*) FROM document_requests WHERE
          (resident_id IN (SELECT resident_id FROM residents WHERE last_name IN ({$residentSql}))
            OR type_id IN (SELECT type_id FROM document_types WHERE name IN ({$typeSql})))
          AND reference_no NOT IN ({$requestSql})",
        array_merge(DEMO_SEED_LAST_NAMES, DEMO_SEED_TYPE_NAMES, DEMO_SEED_REQUEST_REFS)
    );
    $check['other_requests_linked_to_demo_visitors'] = (int) db_val(
        "SELECT COUNT(*) FROM document_requests WHERE visit_id IN (SELECT visit_id FROM visitor_log WHERE visitor_name IN ({$visitorSql})) AND reference_no NOT IN ({$requestSql})",
        array_merge(DEMO_SEED_VISITOR_NAMES, DEMO_SEED_REQUEST_REFS)
    );
    $check['non_demo_issuances_linked_to_demo_requests_or_residents'] = (int) db_val(
        "SELECT COUNT(*) FROM issuances WHERE
          (request_id IN (SELECT request_id FROM document_requests WHERE reference_no IN ({$requestSql}))
            OR resident_id IN (SELECT resident_id FROM residents WHERE last_name IN ({$residentSql})))
          AND reference_no NOT IN ({$issuanceSql})",
        array_merge(DEMO_SEED_REQUEST_REFS, DEMO_SEED_LAST_NAMES, DEMO_SEED_ISSUANCE_REFS)
    );
    $check['cash_ledger_entries_on_demo_issuances'] = (int) db_val(
        "SELECT COUNT(*) FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE reference_no IN ({$issuanceSql}))",
        DEMO_SEED_ISSUANCE_REFS
    );
    $check['attachments_on_demo_visits_or_requests'] = (int) db_val(
        "SELECT COUNT(*) FROM document_attachments WHERE
          visit_id IN (SELECT visit_id FROM visitor_log WHERE visitor_name IN ({$visitorSql}))
          OR request_id IN (SELECT request_id FROM document_requests WHERE reference_no IN ({$requestSql}))",
        array_merge(DEMO_SEED_VISITOR_NAMES, DEMO_SEED_REQUEST_REFS)
    );
    $check['other_cases_using_demo_lookups'] = (int) db_val(
        "SELECT COUNT(*) FROM blotters WHERE
          (purok_id IN (SELECT lookup_id FROM lookups WHERE label LIKE 'DEMO ONLY - %')
            OR incident_type_id IN (SELECT lookup_id FROM lookups WHERE label LIKE 'DEMO ONLY - %'))
          AND case_no NOT IN ({$caseSql})",
        DEMO_SEED_CASE_REFS
    );
    $check['other_households_using_demo_puroks'] = (int) db_val(
        "SELECT COUNT(*) FROM households WHERE purok_id IN (SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label LIKE 'DEMO ONLY - %') AND household_code NOT IN ({$householdSql})",
        DEMO_SEED_HOUSEHOLD_CODES
    );
    $check['other_residents_using_demo_lookups'] = (int) db_val(
        "SELECT COUNT(*) FROM residents WHERE
          (category_id IN (SELECT lookup_id FROM lookups WHERE list_key = 'resident_category' AND label LIKE 'DEMO ONLY - %')
            OR employment_status_id IN (SELECT lookup_id FROM lookups WHERE list_key = 'employment_status' AND label LIKE 'DEMO ONLY - %'))
          AND last_name NOT IN ({$residentSql})",
        DEMO_SEED_LAST_NAMES
    );
    $check['other_visitors_using_demo_classes'] = (int) db_val(
        "SELECT COUNT(*) FROM visitor_log WHERE class_id IN (SELECT lookup_id FROM lookups WHERE list_key = 'visit_class' AND label LIKE 'DEMO ONLY - %') AND visitor_name NOT IN ({$visitorSql})",
        DEMO_SEED_VISITOR_NAMES
    );
    return $check;
}
function demo_seed_remove(): array
{
    return db_transaction(function (): array {
        $markers = demo_seed_marker_counts();
        if (!demo_seed_has_markers($markers)) {
            return ['removed' => false, 'counts' => $markers, 'message' => 'No reserved demo dataset was found. No rows were changed.'];
        }
        $dependencies = demo_seed_check_removal_dependencies();
        $blocking = array_filter($dependencies, static fn(int $n): bool => $n > 0);
        if ($blocking) {
            throw new RuntimeException('Removal was stopped to protect records created or linked after seeding. Related dependencies: '
                . implode(', ', array_map(static fn(string $key, int $n): string => $key . '=' . $n, array_keys($blocking), array_values($blocking)))
                . '. No rows were removed. Export or reassign those linked records and retry only after reviewing them.');
        }

        $caseSql = demo_placeholders(DEMO_SEED_CASE_REFS);
        $issuanceSql = demo_placeholders(DEMO_SEED_ISSUANCE_REFS);
        $requestSql = demo_placeholders(DEMO_SEED_REQUEST_REFS);
        $visitorSql = demo_placeholders(DEMO_SEED_VISITOR_NAMES);
        $residentSql = demo_placeholders(DEMO_SEED_LAST_NAMES);
        $householdSql = demo_placeholders(DEMO_SEED_HOUSEHOLD_CODES);
        $typeSql = demo_placeholders(DEMO_SEED_TYPE_NAMES);

        db_exec("DELETE FROM sms_outbox WHERE request_id IN (SELECT request_id FROM document_requests WHERE reference_no IN ({$requestSql}))", DEMO_SEED_REQUEST_REFS);
        db_exec("DELETE FROM issuances WHERE reference_no IN ({$issuanceSql})", DEMO_SEED_ISSUANCE_REFS);
        db_exec("DELETE FROM document_requests WHERE reference_no IN ({$requestSql})", DEMO_SEED_REQUEST_REFS);
        db_exec("DELETE FROM hearings WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE case_no IN ({$caseSql}))", DEMO_SEED_CASE_REFS);
        db_exec("DELETE FROM blotter_parties WHERE blotter_id IN (SELECT blotter_id FROM blotters WHERE case_no IN ({$caseSql}))", DEMO_SEED_CASE_REFS);
        db_exec("DELETE FROM blotters WHERE case_no IN ({$caseSql})", DEMO_SEED_CASE_REFS);
        db_exec("DELETE FROM visitor_log WHERE visitor_name IN ({$visitorSql})", DEMO_SEED_VISITOR_NAMES);
        db_exec("DELETE FROM residents WHERE last_name IN ({$residentSql})", DEMO_SEED_LAST_NAMES);
        db_exec("DELETE FROM households WHERE household_code IN ({$householdSql})", DEMO_SEED_HOUSEHOLD_CODES);
        db_exec("DELETE FROM document_types WHERE name IN ({$typeSql})", DEMO_SEED_TYPE_NAMES);

        $labelGroups = demo_seed_lookup_labels();
        foreach ($labelGroups as $key => $labels) {
            $lsql = demo_placeholders($labels);
            db_exec("DELETE FROM lookups WHERE list_key = ? AND label IN ({$lsql})", array_merge([$key], $labels));
        }

        audit('demo_data_removed', 'Demo data', 'Success',
            'Removed only the reserved DEMO ONLY presentation dataset and its unused demo-only lookup values/types.');
        return ['removed' => true, 'counts' => $markers, 'message' => 'Reserved demo records were removed. Audit entries were retained.'];
    });
}

function demo_seed_usage(): void
{
    demo_out('BIRMS demo data seeder (CLI only)');
    demo_out('  php tools/seed_demo_data.php                 Read-only plan / current marker summary');
    demo_out('  php tools/seed_demo_data.php --apply         Add fictitious demonstration records');
    demo_out('  php tools/seed_demo_data.php --remove --confirm-demo-only  Remove only reserved demo records');
    demo_out('No official settings, user accounts, existing residents, official document types, fees, or cash-ledger entries are changed.');
}

date_default_timezone_set((string) config('timezone', 'Asia/Manila'));
$args = array_values(array_slice($argv, 1));
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    demo_seed_usage();
    exit(0);
}
$allowedArgs = ['--apply', '--remove', '--confirm-demo-only'];
foreach ($args as $arg) {
    if (!in_array($arg, $allowedArgs, true)) {
        demo_seed_usage();
        demo_fail('unknown command-line option ' . $arg);
    }
}
if (in_array('--apply', $args, true) && in_array('--remove', $args, true)) {
    demo_fail('choose either --apply or --remove, not both.');
}
if (in_array('--confirm-demo-only', $args, true) && !in_array('--remove', $args, true)) {
    demo_fail('--confirm-demo-only is only valid with --remove.');
}
if (in_array('--remove', $args, true) && !in_array('--confirm-demo-only', $args, true)) {
    demo_fail('removal requires BOTH --remove and --confirm-demo-only.');
}

try {
    demo_require_safe_database();
    $markers = demo_seed_marker_counts();
    demo_out('Database: ' . (string) config('db.name'));
    demo_out('Mode: ' . (in_array('--apply', $args, true) ? 'APPLY' : (in_array('--remove', $args, true) ? 'REMOVE' : 'READ-ONLY PLAN')));
    demo_seed_output_counts($markers);

    if (in_array('--remove', $args, true)) {
        $result = demo_seed_remove();
        demo_out($result['message'] ?? 'Removal finished.');
        exit(0);
    }
    if (!in_array('--apply', $args, true)) {
        if (demo_seed_counts_are_complete($markers)) {
            demo_out('The complete demo dataset is already present. Re-running --apply will not duplicate it.');
        } elseif (demo_seed_has_markers($markers)) {
            demo_out('Reserved demo markers are present but the dataset is incomplete. No changes were made; inspect first.');
        } else {
            demo_out('Plan: 8 households, 24 fictitious residents, 3 DEMO ONLY document types, 12 request statuses, 4 DEMO ONLY issuance records, 8 visitor entries, 4 fictitious blotter cases, and 1 hearing.');
            demo_out('Use --apply to insert. All inserted document bodies are visibly marked DEMO ONLY; no real fees or collections are recorded.');
        }
        exit(0);
    }

    $result = demo_seed_apply();
    demo_out(!empty($result['already_seeded']) ? 'Demo dataset already exists; no duplicate records were inserted.' : 'Demo dataset inserted successfully.');
    demo_seed_output_counts($result['counts']);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Demo seeder stopped safely: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
