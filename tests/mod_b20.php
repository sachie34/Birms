<?php
/** B20: revised SRS venue reporting, role visibility and Excel export. */
declare(strict_types=1);
require_once __DIR__ . '/../app/reports_lib.php';

function run_b20(array $S, string $base): void
{
    $adm = $S['admin'];
    $cap = $S['captain'];      // legacy internal id mapped to Chairman
    $sec = $S['secretary'];
    $staff = $S['clerk'];      // legacy internal id mapped to Staff

    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $venueName = 'ZZ B20 Venue ' . $suffix;
    $residentNo = 'RES-B20-' . $suffix;
    $today = date('Y-m-d');
    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $adminId = (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_admin'");

    $venueId = 0;
    $residentId = 0;
    $reservationIds = [];
    try {
        db_exec(
            "INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
              notification_preference, residency_status, household_id, is_household_head, archived_at)
             VALUES (?, '20 Report Lane', ?, ?, 'Single', ?, 'ZZVenueReport', 'Rita', '1990-01-01',
                     'Female', 'Single', NULL, NULL, 'email', 'Active', NULL, 0, NULL)",
            [$residentNo, $purok['lookup_id'] ?? null, $statusId ?: null, $typeId ?: null]
        );
        $residentId = (int) db()->lastInsertId();

        db_exec(
            "INSERT INTO venues (name, description, availability_override, is_active, created_by)
             VALUES (?, 'Venue reporting fixture', 'automatic', 1, ?)",
            [$venueName, $adminId ?: null]
        );
        $venueId = (int) db()->lastInsertId();

        $fixtures = [
            ['Pending', '08:00:00', '09:00:00'],
            ['Approved', '09:00:00', '10:00:00'],
            ['Completed', '10:00:00', '11:00:00'],
            ['Rejected', '11:00:00', '12:00:00'],
        ];
        foreach ($fixtures as $index => [$status, $start, $end]) {
            $reference = 'B20-' . $suffix . '-' . ($index + 1);
            db_exec(
                'INSERT INTO venue_reservations
                 (reference_no, venue_id, resident_id, purpose, reservation_date, start_time, end_time, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$reference, $venueId, $residentId, 'B20 report fixture ' . $status, $today, $start, $end, $status, $adminId ?: null]
            );
            $reservationIds[] = (int) db()->lastInsertId();
        }

        $range = report_period_range('day', null, null, null, $today);
        $report = report_venues($range);
        $fixture = null;
        foreach ($report['by_venue'] as $row) {
            if ((string) $row['venue_name'] === $venueName) { $fixture = $row; break; }
        }
        $summaryOk = (int) $report['summary']['total'] >= 4
            && (int) $report['summary']['pending_count'] >= 1
            && (int) $report['summary']['approved_count'] >= 1
            && (int) $report['summary']['rejected_count'] >= 1
            && (int) $report['summary']['completed_count'] >= 1
            && (float) $report['summary']['booked_hours'] >= 2.0
            && $fixture !== null
            && (int) $fixture['reservation_count'] === 4
            && (int) $fixture['pending_count'] === 1
            && (int) $fixture['approved_count'] === 1
            && (int) $fixture['rejected_count'] === 1
            && (int) $fixture['completed_count'] === 1
            && (float) $fixture['booked_hours'] === 2.0;
        record('B20-01', 'Venue usage report counts each reservation status and booked hours from the reservation date',
            $summaryOk,
            'venue=' . ($fixture === null ? 'missing' : $venueName)
            . '; bookings=' . (string) ($fixture['reservation_count'] ?? 'n/a')
            . '; booked-hours=' . (string) ($fixture['booked_hours'] ?? 'n/a'));

        $views = [
            'admin' => $adm->get('/reports/index.php?type=venues&period=day&anchor_date=' . $today),
            'chairman' => $cap->get('/reports/index.php?type=venues&period=day&anchor_date=' . $today),
            'secretary' => $sec->get('/reports/index.php?type=venues&period=day&anchor_date=' . $today),
            'staff' => $staff->get('/reports/index.php?type=venues&period=day&anchor_date=' . $today),
        ];
        record('B20-02', 'Admin, Chairman and Secretary can view venue usage reports; Staff is denied',
            $views['admin']['status'] === 200
            && $views['chairman']['status'] === 200
            && $views['secretary']['status'] === 200
            && $views['staff']['status'] === 403
            && str_contains($views['secretary']['body'], $venueName)
            && str_contains($views['chairman']['body'], 'Booked hours'));

        $excelPath = '/reports/index.php?type=venues&period=day&anchor_date=' . $today . '&format=excel';
        $chairmanExcel = $cap->get($excelPath);
        $secretaryExcel = $sec->get($excelPath);
        $staffExcel = $staff->get($excelPath);
        $excelOk = $chairmanExcel['status'] === 200
            && $secretaryExcel['status'] === 200
            && $staffExcel['status'] === 403
            && str_contains(strtolower((string) ($secretaryExcel['headers']['content-type'] ?? '')), 'application/vnd.ms-excel')
            && str_contains((string) ($secretaryExcel['headers']['content-disposition'] ?? ''), '.xls')
            && str_contains($secretaryExcel['body'], '<table>')
            && str_contains($secretaryExcel['body'], $venueName)
            && str_contains($secretaryExcel['body'], 'Booked hours');
        record('B20-03', 'Chairman and Secretary can export Excel-compatible venue usage reports; Staff cannot',
            $excelOk,
            'chairman=' . $chairmanExcel['status'] . '; secretary=' . $secretaryExcel['status']
            . '; staff=' . $staffExcel['status']
            . '; content-type=' . (string) ($secretaryExcel['headers']['content-type'] ?? 'missing'));

        $csv = $sec->get('/reports/index.php?type=venues&period=day&anchor_date=' . $today . '&format=csv');
        record('B20-04', 'Venue usage report retains a CSV export for spreadsheet compatibility',
            $csv['status'] === 200
            && str_contains((string) ($csv['headers']['content-type'] ?? ''), 'text/csv')
            && str_contains($csv['body'], $venueName)
            && str_contains($csv['body'], 'Booked hours'));
    } finally {
        if ($reservationIds) {
            $marks = implode(',', array_fill(0, count($reservationIds), '?'));
            db_exec('DELETE FROM request_status_history WHERE reservation_id IN (' . $marks . ')', $reservationIds);
            db_exec('DELETE FROM venue_reservations WHERE reservation_id IN (' . $marks . ')', $reservationIds);
        }
        if ($venueId > 0) { db_exec('DELETE FROM venues WHERE venue_id = ?', [$venueId]); }
        if ($residentId > 0) { db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]); }
    }
}
