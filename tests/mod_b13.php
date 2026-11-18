<?php
/** B13 tests: revised MSIT SRS data foundation (schema and key uniqueness rules). */
declare(strict_types=1);

function run_b13(array $S, string $base): void
{
    $today = date('Y-m-d');
    $residentNo = 'ZZMSIT-' . date('Ymd');
    $purokLabel = 'ZZ MSIT Test Purok';
    $venueName = 'ZZ MSIT Test Venue';
    $residentId = null;
    $venueId = null;
    $queueIds = [];

    try {
        db_exec("INSERT IGNORE INTO lookups (list_key, label, sort_order, is_active) VALUES ('purok', ?, 999, 1)", [$purokLabel]);
        $purokId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
        $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active'");
        $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None'");

        $requiredColumns = ['resident_no','address','purok_id','resident_status_id','marital_status','resident_type_id','email_address','photo_path','notification_preference'];
        $columnRows = db_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents'");
        $actualColumns = array_map(static fn(array $r): string => (string) $r['COLUMN_NAME'], $columnRows);
        $missingColumns = array_values(array_diff($requiredColumns, $actualColumns));
        record('B13-01', 'Resident records contain the revised SRS identity, address, Purok, status, marital status, type and contact fields',
            !$missingColumns, $missingColumns ? 'Missing: ' . implode(', ', $missingColumns) : '');

        $defaultLists = [
            'resident_status' => ['Active','Transferred','Deceased','Non-resident'],
            'resident_type' => ['PWD','Senior Citizen','Solo Parent','4Ps Beneficiary','Indigent','None'],
        ];
        $listProblems = [];
        foreach ($defaultLists as $key => $labels) {
            $found = db_all('SELECT label FROM lookups WHERE list_key = ? AND is_active = 1', [$key]);
            $actual = array_map(static fn(array $r): string => (string) $r['label'], $found);
            foreach ($labels as $label) {
                if (!in_array($label, $actual, true)) { $listProblems[] = $key . ':' . $label; }
            }
        }
        record('B13-02', 'The revised default resident statuses and resident types are preloaded', !$listProblems, implode(', ', $listProblems));

        db_exec('DELETE FROM residents WHERE resident_no = ?', [$residentNo]);
        db_exec("INSERT INTO residents
            (resident_no, address, purok_id, resident_status_id, resident_type_id, marital_status,
             last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status)
            VALUES (?, ?, ?, ?, ?, 'Single', 'ZZMSIT', 'Foundation', '1990-01-01', 'Male', 'Single', 'Active', 'Unverified')",
            [$residentNo, '123 MSIT Test Street', $purokId, $statusId, $typeId]);
        $residentId = (int) db()->lastInsertId();
        $resident = db_one('SELECT resident_no, address, purok_id, resident_status_id, resident_type_id, notification_preference FROM residents WHERE resident_id = ?', [$residentId]);
        record('B13-03', 'A resident can be stored with an individual address/Purok and defaults to email notifications',
            $resident !== null
                && $resident['resident_no'] === $residentNo
                && $resident['address'] === '123 MSIT Test Street'
                && (int) $resident['purok_id'] === $purokId
                && (int) $resident['resident_status_id'] === $statusId
                && (int) $resident['resident_type_id'] === $typeId
                && $resident['notification_preference'] === 'email');

        $duplicateResidentRejected = false;
        try {
            db_exec("INSERT INTO residents
                (resident_no, address, purok_id, resident_status_id, resident_type_id, marital_status,
                 last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status)
                VALUES (?, ?, ?, ?, ?, 'Single', 'ZZMSIT', 'Duplicate', '1990-01-01', 'Male', 'Single', 'Active', 'Unverified')",
                [$residentNo, 'Duplicate Address', $purokId, $statusId, $typeId]);
        } catch (PDOException $e) {
            $duplicateResidentRejected = true;
        }
        record('B13-04', 'Resident ID numbers are unique at the database layer', $duplicateResidentRejected);

        foreach (['Documents','Blotter','Venue'] as $service) {
            db_exec("INSERT INTO queue_entries (service, queue_date, queue_number, resident_id) VALUES (?, ?, 1, ?)", [$service, $today, $residentId]);
            $queueIds[] = (int) db()->lastInsertId();
        }
        $separateQueues = (int) db_val('SELECT COUNT(*) FROM queue_entries WHERE queue_date = ? AND queue_number = 1 AND resident_id = ?', [$today, $residentId]);
        $duplicateQueueRejected = false;
        try {
            db_exec("INSERT INTO queue_entries (service, queue_date, queue_number, resident_id) VALUES ('Documents', ?, 1, ?)", [$today, $residentId]);
        } catch (PDOException $e) {
            $duplicateQueueRejected = true;
        }
        record('B13-05', 'Blotter, Documents and Venue can each issue number 1 independently, while duplicate numbers within one service/day are rejected',
            $separateQueues === 3 && $duplicateQueueRejected, 'separate queues=' . $separateQueues . ', duplicate rejected=' . ($duplicateQueueRejected ? 'yes' : 'no'));

        db_exec("INSERT INTO venues (name, availability_override) VALUES (?, 'automatic')", [$venueName]);
        $venueId = (int) db()->lastInsertId();
        $venue = db_one('SELECT name, availability_override, is_active FROM venues WHERE venue_id = ?', [$venueId]);
        record('B13-06', 'Venues support automatic availability mode as the default',
            $venue !== null && $venue['name'] === $venueName && $venue['availability_override'] === 'automatic' && (int) $venue['is_active'] === 1);

        $blotterType = db_val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotters' AND COLUMN_NAME = 'msit_status'");
        $expectedBlotterValues = ["'ongoing'","'unresolved'","'resolved'","'dismissed'","'archived'"];
        $blotterType = strtolower((string) $blotterType);
        $missingBlotterValues = array_values(array_filter($expectedBlotterValues, static fn(string $v): bool => !str_contains($blotterType, $v)));
        record('B13-07', 'Blotter schema supports the five revised MSIT statuses', !$missingBlotterValues, $missingBlotterValues ? implode(', ', $missingBlotterValues) : '');
    } finally {
        if ($venueId !== null) {
            db_exec('DELETE FROM venue_reservations WHERE venue_id = ?', [$venueId]);
            db_exec('DELETE FROM venues WHERE venue_id = ?', [$venueId]);
        }
        if ($queueIds) {
            $marks = implode(',', array_fill(0, count($queueIds), '?'));
            db_exec('DELETE FROM queue_entries WHERE queue_id IN (' . $marks . ')', $queueIds);
        }
        if ($residentId !== null) {
            db_exec('DELETE FROM resident_verification_challenges WHERE resident_id = ?', [$residentId]);
            db_exec('DELETE FROM notification_outbox WHERE resident_id = ?', [$residentId]);
            db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
        }
        db_exec("DELETE FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
    }
}
