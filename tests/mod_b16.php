<?php
/** B16 end-to-end tests for resident blotter intake, staff review and reference tracking. */
declare(strict_types=1);

function run_b16(array $S, string $base): void
{
    $adm = $S['admin'];
    $cap = $S['captain'];
    $sec = $S['secretary'];
    $staff = $S['clerk'];

    $residentNo = 'RES-B16-' . strtoupper(bin2hex(random_bytes(4)));
    $purokLabel = 'ZZ B16 Resident Purok ' . strtoupper(strtr(bin2hex(random_bytes(3)), '0123456789abcdef', 'abcdefghijklmnop'));
    $residentId = 0;
    $purokId = 0;
    $references = [];
    $officialCaseIds = [];

    db_exec('DELETE FROM residents WHERE resident_no = ?', [$residentNo]);
    db_exec("DELETE FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);

    try {
        db_exec("INSERT INTO lookups (list_key, label, sort_order, is_active) VALUES ('purok', ?, 9997, 1)", [$purokLabel]);
        $purokId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
        $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
        $typeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");

        db_exec(
            "INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status,
              contact_number, email_address, notification_preference, household_id, is_household_head, archived_at)
             VALUES (?, 'B16 Resident Address', ?, ?, 'Single', ?, 'Baker', 'Jordan', '1990-05-20',
                     'female', 'single', 'active', 'unverified', NULL, 'b16-jordan@example.test', 'email', NULL, 0, NULL)",
            [$residentNo, $purokId, $statusId ?: null, $typeId ?: null]
        );
        $residentId = (int) db()->lastInsertId();

        [$pageOk, $pageWhy] = rbac_page($S, '/blotter_requests/index.php', 'blotter_requests.view');
        $dashboardLinks = true;
        foreach ([$adm, $cap, $sec, $staff] as $client) {
            $dashboard = $client->get('/dashboard.php');
            if (!str_contains($dashboard['body'], 'blotter_requests/index.php')) { $dashboardLinks = false; }
        }
        record('B16-01', 'Admin, Chairman, Secretary and Staff can view the resident blotter request tracker',
            $pageOk && $dashboardLinks, $pageWhy);

        // Follow the same identity flow used by a QR/kiosk resident: Resident ID + birthdate.
        $kiosk = new Http($base);
        $identity = $kiosk->post('/kiosk/identify.php', [
            '_csrf' => $kiosk->token('/kiosk/index.php'),
            'resident_no' => $residentNo,
            'birthdate' => '1990-05-20',
            'next' => 'home',
        ]);
        $identityOk = $identity['status'] === 302 && str_contains(loc($identity), 'kiosk/index.php');

        $submit = static function (Http $resident, string $place, string $story, string $respondent = '') use ($purokId): array {
            $token = $resident->token('/kiosk/blotter.php');
            return $resident->post('/kiosk/blotter.php', [
                '_csrf' => $token,
                'incident_date' => date('Y-m-d'),
                'incident_time' => '09:30',
                'incident_place' => $place,
                'purok_id' => (string) $purokId,
                'incident_type_id' => '',
                'narrative' => $story,
                'respondent_name' => $respondent,
                'respondent_age' => '',
                'respondent_address' => '',
                'respondent_contact' => '',
            ]);
        };

        $firstSubmit = $identityOk
            ? $submit($kiosk, 'B16 Test Street One', 'B16 test report describing a resident incident with enough detail.')
            : ['status' => 0, 'location' => null, 'body' => ''];
        $firstReference = (string) db_val('SELECT reference_no FROM resident_blotter_requests WHERE resident_id = ? ORDER BY request_id DESC LIMIT 1', [$residentId]);
        if ($firstReference !== '') { $references[] = $firstReference; }
        $firstRow = $firstReference !== '' ? db_one('SELECT * FROM resident_blotter_requests WHERE reference_no = ?', [$firstReference]) : null;
        $firstQueue = $firstReference !== '' ? db_one("SELECT * FROM queue_entries WHERE reference_no = ? AND service = 'Blotter'", [$firstReference]) : null;
        $statusBefore = $firstReference !== '' ? $kiosk->get('/kiosk/status.php?ref=' . rawurlencode($firstReference)) : ['body' => ''];
        record('B16-02', 'Verified resident can submit a blotter report, receive a reference and Blotter queue ticket, and track Ongoing status',
            $identityOk && $firstSubmit['status'] === 302 && str_contains(loc($firstSubmit), 'blotter_receipt.php')
            && preg_match('/^BLT-' . date('Y') . '-[0-9]{4}$/', $firstReference) === 1
            && $firstRow !== null && $firstRow['review_status'] === 'Pending' && $firstRow['msit_status'] === 'Ongoing'
            && $firstQueue !== null && $firstQueue['status'] === 'Waiting'
            && str_contains($statusBefore['body'], 'Ongoing'),
            'identity=' . ($identityOk ? 'yes' : 'no') . '; submit=' . $firstSubmit['status'] . '; ref=' . $firstReference);

        $privateValues = [
            'Jordan Baker',
            $residentNo,
            'b16-jordan@example.test',
            'B16 Resident Address',
            'B16 test report describing a resident incident with enough detail.',
        ];
        $privateLeaks = array_values(array_filter(
            $privateValues,
            static fn(string $value): bool => $value !== '' && str_contains((string) ($statusBefore['body'] ?? ''), $value)
        ));
        record(
            'B16-09',
            'Public request status lookup shows the reference and service status but never discloses resident identity, contact, address or incident narrative',
            ($statusBefore['status'] ?? 0) === 200
            && str_contains((string) $statusBefore['body'], $firstReference)
            && str_contains((string) $statusBefore['body'], 'Ongoing')
            && !$privateLeaks,
            $privateLeaks ? 'private values exposed: ' . implode(', ', $privateLeaks) : 'reference/status visible; resident PII and narrative absent'
        );

        $requestId = (int) ($firstRow['request_id'] ?? 0);
        $rejectAttemptCap = $requestId > 0 ? post_as($cap, '/blotter_requests/index.php', '/blotter_requests/action.php', [
            'do' => 'accept', 'request_id' => (string) $requestId, 'note' => '',
        ]) : ['status' => 0];
        $rejectAttemptSecretary = $requestId > 0 ? post_as($sec, '/blotter_requests/index.php', '/blotter_requests/action.php', [
            'do' => 'accept', 'request_id' => (string) $requestId, 'note' => '',
        ]) : ['status' => 0];
        $noCsrf = $requestId > 0 ? $staff->post('/blotter_requests/action.php', [
            'do' => 'accept', 'request_id' => (string) $requestId, 'note' => '',
        ]) : ['status' => 0];
        record('B16-03', 'Chairman and Secretary are view-only; Staff review requires CSRF',
            $rejectAttemptCap['status'] === 403 && $rejectAttemptSecretary['status'] === 403
            && $noCsrf['status'] === 403
            && role_can('clerk', 'blotter_requests.review')
            && role_can('admin', 'blotter_requests.review')
            && !role_can('captain', 'blotter_requests.review')
            && !role_can('secretary', 'blotter_requests.review'));

        $accepted = $requestId > 0 ? post_as($staff, '/blotter_requests/index.php', '/blotter_requests/action.php', [
            'do' => 'accept', 'request_id' => (string) $requestId, 'note' => 'Verified by Staff during B16 test.',
        ]) : ['status' => 0, 'location' => null];
        $firstAfter = $firstReference !== '' ? db_one('SELECT * FROM resident_blotter_requests WHERE reference_no = ?', [$firstReference]) : null;
        $official = $firstAfter && $firstAfter['blotter_id'] !== null
            ? db_one('SELECT * FROM blotters WHERE blotter_id = ?', [(int) $firstAfter['blotter_id']])
            : null;
        $complainant = $official ? db_one("SELECT * FROM blotter_parties WHERE blotter_id = ? AND party_role = 'Complainant'", [(int) $official['blotter_id']]) : null;
        if ($official) { $officialCaseIds[] = (int) $official['blotter_id']; }
        $duplicateAccept = $requestId > 0 ? post_as($staff, '/blotter_requests/index.php', '/blotter_requests/action.php', [
            'do' => 'accept', 'request_id' => (string) $requestId, 'note' => '',
        ]) : ['status' => 0];
        $acceptNotice = db_one("SELECT recipient, channel, delivery_status, message FROM notification_outbox WHERE resident_id = ? AND reference_no = ? AND event_type = 'resident_blotter_reviewed' ORDER BY notification_id DESC LIMIT 1", [$residentId, $firstReference]);
        record('B16-04', 'Staff acceptance creates one official blotter case with the resident as complainant; duplicate acceptance is blocked and resident is notified',
            $accepted['status'] === 302 && $firstAfter !== null && $firstAfter['review_status'] === 'Accepted'
            && $official !== null && $official['case_no'] === $firstReference && $official['msit_status'] === 'Ongoing'
            && $complainant !== null && $complainant['full_name'] === 'Jordan Baker'
            && $duplicateAccept['status'] === 302
            && (int) db_val('SELECT COUNT(*) FROM blotters WHERE case_no = ?', [$firstReference]) === 1
            && $acceptNotice !== null && $acceptNotice['recipient'] === 'b16-jordan@example.test'
            && $acceptNotice['channel'] === 'email' && in_array($acceptNotice['delivery_status'], ['queued','sent'], true)
            && str_contains($acceptNotice['message'], 'accepted by Staff'));

        $officialId = (int) ($firstAfter['blotter_id'] ?? 0);
        $changed = $officialId > 0 ? post_as($sec, '/blotters/view.php?id=' . $officialId, '/blotters/action.php', [
            'id' => (string) $officialId, 'do' => 'srs_move', 'to' => 'Unresolved', 'note' => '',
        ]) : ['status' => 0];
        $afterChange = $firstReference !== '' ? db_one('SELECT msit_status FROM resident_blotter_requests WHERE reference_no = ?', [$firstReference]) : null;
        $statusAfter = $firstReference !== '' ? $kiosk->get('/kiosk/status.php?ref=' . rawurlencode($firstReference)) : ['body' => ''];
        $caseNotice = db_one("SELECT recipient, channel, delivery_status, message FROM notification_outbox WHERE resident_id = ? AND reference_no = ? AND event_type = 'blotter_case_status_changed' ORDER BY notification_id DESC LIMIT 1", [$residentId, $firstReference]);
        record('B16-05', 'Official case status changes synchronize to the resident reference tracker and notify the resident',
            $changed['status'] === 302 && ($afterChange['msit_status'] ?? '') === 'Unresolved'
            && str_contains($statusAfter['body'], 'Unresolved')
            && $caseNotice !== null && $caseNotice['recipient'] === 'b16-jordan@example.test'
            && $caseNotice['channel'] === 'email' && in_array($caseNotice['delivery_status'], ['queued','sent'], true)
            && str_contains($caseNotice['message'], 'Unresolved'));

        // Submit a second report in the same verified resident session, then reject it as Staff.
        $secondSubmit = $submit($kiosk, 'B16 Test Street Two', 'B16 second test report which will be rejected with a reason.');
        $secondReference = (string) db_val('SELECT reference_no FROM resident_blotter_requests WHERE resident_id = ? ORDER BY request_id DESC LIMIT 1', [$residentId]);
        if ($secondReference !== '' && $secondReference !== $firstReference) { $references[] = $secondReference; }
        $secondRow = $secondReference !== '' ? db_one('SELECT * FROM resident_blotter_requests WHERE reference_no = ?', [$secondReference]) : null;
        $rejected = $secondRow ? post_as($staff, '/blotter_requests/index.php', '/blotter_requests/action.php', [
            'do' => 'reject', 'request_id' => (string) $secondRow['request_id'], 'note' => 'This report needs more details before acceptance.',
        ]) : ['status' => 0];
        $secondAfter = $secondReference !== '' ? db_one('SELECT * FROM resident_blotter_requests WHERE reference_no = ?', [$secondReference]) : null;
        $secondStatus = $secondReference !== '' ? $kiosk->get('/kiosk/status.php?ref=' . rawurlencode($secondReference)) : ['body' => ''];
        $rejectNotice = db_one("SELECT recipient, channel, delivery_status, message FROM notification_outbox WHERE resident_id = ? AND reference_no = ? AND event_type = 'resident_blotter_reviewed' ORDER BY notification_id DESC LIMIT 1", [$residentId, $secondReference]);
        record('B16-06', 'Staff rejection is reasoned, audited and appears as Dismissed to the resident without creating an official case; resident is notified',
            $secondSubmit['status'] === 302 && $secondRow !== null && $rejected['status'] === 302
            && $secondAfter !== null && $secondAfter['review_status'] === 'Rejected'
            && $secondAfter['msit_status'] === 'Dismissed' && $secondAfter['blotter_id'] === null
            && str_contains($secondStatus['body'], 'Dismissed')
            && (int) db_val('SELECT COUNT(*) FROM blotters WHERE case_no = ?', [$secondReference]) === 0
            && $rejectNotice !== null && $rejectNotice['recipient'] === 'b16-jordan@example.test'
            && $rejectNotice['channel'] === 'email' && in_array($rejectNotice['delivery_status'], ['queued','sent'], true)
            && str_contains($rejectNotice['message'], 'dismissed by Staff'));

        $historyOk = true;
        foreach ($references as $ref) {
            if ((int) db_val("SELECT COUNT(*) FROM request_status_history WHERE service = 'Blotter' AND reference_no = ?", [$ref]) < 2) {
                $historyOk = false;
            }
        }
        record('B16-07', 'Resident blotter submission, acceptance/rejection and official status changes are stored in request history',
            count($references) === 2 && $historyOk);

        $badInput = $kiosk->post('/kiosk/blotter.php', [
            '_csrf' => $kiosk->token('/kiosk/blotter.php'),
            'incident_date' => 'not-a-date', 'incident_time' => '26:90',
            'incident_place' => 'x', 'purok_id' => '99999999',
            'incident_type_id' => '', 'narrative' => 'short',
            'respondent_name' => '', 'respondent_age' => '', 'respondent_address' => '', 'respondent_contact' => '',
        ]);
        record('B16-08', 'Invalid dates, times, places and short narratives are rejected without creating a report',
            $badInput['status'] === 200
            && (int) db_val('SELECT COUNT(*) FROM resident_blotter_requests WHERE resident_id = ?', [$residentId]) === 2);

    } finally {
        // FR-B5 makes official blotter cases permanent. Keep the resident submission,
        // created case, parties, status history, audit rows, queue ticket, resident and
        // its Purok together so the fixture never destroys evidence or violates the
        // database no-delete guard. Run-specific resident/Purok IDs prevent collisions.
    }
}
