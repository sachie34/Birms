<?php
/** B21: unified FR-T6 tracker across document, resident blotter and venue requests. */
declare(strict_types=1);

function run_b21(array $S, string $base): void
{
    $admin = $S['admin'];
    $chairman = $S['captain'];
    $secretary = $S['secretary'];
    $staff = $S['clerk'];

    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $residentNo = 'RES-B21-' . $suffix;
    $docRef = 'B21-DOC-' . $suffix;
    $blotterRef = 'B21-BLT-' . $suffix;
    $venueRef = 'B21-VEN-' . $suffix;
    $venueName = 'ZZ B21 Hall ' . $suffix;
    $today = date('Y-m-d');

    $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
    $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
    $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
    $docTypeId = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Barangay Clearance' LIMIT 1");
    $adminId = (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_admin'");

    $residentId = 0;
    $documentRequestId = 0;
    $blotterRequestId = 0;
    $venueId = 0;
    $reservationId = 0;

    try {
        db_exec(
            "INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
              notification_preference, residency_status, household_id, is_household_head, archived_at)
             VALUES (?, '21 Tracker Lane', ?, ?, 'Single', ?, 'ZZTracker', 'Rita', '1990-01-01',
                     'Female', 'Single', NULL, NULL, 'email', 'Active', NULL, 0, NULL)",
            [$residentNo, $purok['lookup_id'] ?? null, $statusId ?: null, $typeId ?: null]
        );
        $residentId = (int) db()->lastInsertId();

        if ($docTypeId < 1) {
            throw new RuntimeException('The required Barangay Clearance document type fixture is missing.');
        }
        db_exec(
            "INSERT INTO document_requests
             (reference_no, resident_id, type_id, purpose, status, msit_status, requirements_status,
              requested_at, created_by)
             VALUES (?, ?, ?, 'B21 document tracker test', 'Ready', 'Approved', 'Complete', NOW(), ?)",
            [$docRef, $residentId, $docTypeId, $adminId ?: null]
        );
        $documentRequestId = (int) db()->lastInsertId();

        db_exec(
            "INSERT INTO resident_blotter_requests
             (reference_no, resident_id, incident_date, incident_time, incident_place,
              narrative, review_status, msit_status)
             VALUES (?, ?, ?, '09:30:00', 'B21 Test Plaza',
                     'B21 resident blotter tracker integration test narrative', 'Pending', 'Ongoing')",
            [$blotterRef, $residentId, $today]
        );
        $blotterRequestId = (int) db()->lastInsertId();

        db_exec(
            "INSERT INTO venues (name, description, availability_override, is_active, created_by)
             VALUES (?, 'B21 tracker integration test venue', 'automatic', 1, ?)",
            [$venueName, $adminId ?: null]
        );
        $venueId = (int) db()->lastInsertId();

        db_exec(
            "INSERT INTO venue_reservations
             (reference_no, venue_id, resident_id, purpose, reservation_date, start_time, end_time, status, created_by)
             VALUES (?, ?, ?, 'B21 venue tracker test purpose', ?, '13:00:00', '14:00:00', 'Pending', ?)",
            [$venueRef, $venueId, $residentId, $today, $adminId ?: null]
        );
        $reservationId = (int) db()->lastInsertId();

        $allUrl = '/requests/all.php?from=' . rawurlencode($today) . '&to=' . rawurlencode($today);
        $adminPage = $admin->get($allUrl);
        $staffPage = $staff->get($allUrl);
        $chairmanPage = $chairman->get($allUrl);
        $secretaryPage = $secretary->get($allUrl);
        $allVisible = $adminPage['status'] === 200 && $staffPage['status'] === 200
            && str_contains($adminPage['body'], $docRef)
            && str_contains($adminPage['body'], $blotterRef)
            && str_contains($adminPage['body'], $venueRef)
            && str_contains($staffPage['body'], $docRef)
            && str_contains($staffPage['body'], $blotterRef)
            && str_contains($staffPage['body'], $venueRef);
        record('B21-01', 'All Services Tracker combines Documents, Blotter and Venue requests for Admin and Staff',
            $allVisible,
            'admin=' . $adminPage['status'] . '; staff=' . $staffPage['status']
            . '; document=' . (str_contains($adminPage['body'], $docRef) ? 'yes' : 'no')
            . '; blotter=' . (str_contains($adminPage['body'], $blotterRef) ? 'yes' : 'no')
            . '; venue=' . (str_contains($adminPage['body'], $venueRef) ? 'yes' : 'no'));

        record('B21-02', 'Chairman and Secretary cannot open the Staff/Admin-only unified tracker',
            $chairmanPage['status'] === 403 && $secretaryPage['status'] === 403,
            'chairman=' . $chairmanPage['status'] . '; secretary=' . $secretaryPage['status']);

        $documents = $staff->get('/requests/all.php?service=Documents&status=Approved&from=' . $today . '&to=' . $today);
        $blotters = $staff->get('/requests/all.php?service=Blotter&status=Ongoing&from=' . $today . '&to=' . $today);
        $venues = $staff->get('/requests/all.php?service=Venue&status=Pending&from=' . $today . '&to=' . $today);
        $filtersOk = $documents['status'] === 200
            && str_contains($documents['body'], $docRef)
            && !str_contains($documents['body'], $blotterRef)
            && !str_contains($documents['body'], $venueRef)
            && $blotters['status'] === 200
            && str_contains($blotters['body'], $blotterRef)
            && !str_contains($blotters['body'], $docRef)
            && !str_contains($blotters['body'], $venueRef)
            && $venues['status'] === 200
            && str_contains($venues['body'], $venueRef)
            && !str_contains($venues['body'], $docRef)
            && !str_contains($venues['body'], $blotterRef);
        record('B21-03', 'Service and status filters return only matching document, blotter or venue requests',
            $filtersOk,
            'documents=' . $documents['status'] . '; blotters=' . $blotters['status'] . '; venues=' . $venues['status']);

        $search = $admin->get('/requests/all.php?q=' . rawurlencode($residentNo) . '&from=' . $today . '&to=' . $today);
        record('B21-04', 'All Services Tracker searches by Resident ID and submission-date range',
            $search['status'] === 200
            && str_contains($search['body'], $docRef)
            && str_contains($search['body'], $blotterRef)
            && str_contains($search['body'], $venueRef));

        $blotterSearch = $admin->get('/blotter_requests/index.php?q=' . rawurlencode($blotterRef));
        record('B21-05', 'Unified tracker links can locate the matching resident-submitted blotter request by reference',
            $blotterSearch['status'] === 200 && str_contains($blotterSearch['body'], $blotterRef));
    } finally {
        if ($documentRequestId > 0) {
            db_exec('DELETE FROM request_status_history WHERE request_id = ?', [$documentRequestId]);
            db_exec('DELETE FROM sms_outbox WHERE request_id = ?', [$documentRequestId]);
            db_exec('DELETE FROM document_requests WHERE request_id = ?', [$documentRequestId]);
        }
        if ($blotterRequestId > 0) {
            db_exec('DELETE FROM request_status_history WHERE reference_no = ? AND service = \'Blotter\'', [$blotterRef]);
            db_exec('DELETE FROM resident_blotter_requests WHERE request_id = ?', [$blotterRequestId]);
        }
        if ($reservationId > 0) {
            db_exec('DELETE FROM request_status_history WHERE reservation_id = ?', [$reservationId]);
            db_exec('DELETE FROM venue_reservations WHERE reservation_id = ?', [$reservationId]);
        }
        if ($venueId > 0) { db_exec('DELETE FROM venues WHERE venue_id = ?', [$venueId]); }
        if ($residentId > 0) { db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]); }
    }
}
