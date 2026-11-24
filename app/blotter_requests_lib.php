<?php
/** Resident-submitted blotter intake, staff review and official-case creation. */
declare(strict_types=1);

const RESIDENT_BLOTTER_REVIEW_STATUSES = ['Pending', 'Accepted', 'Rejected'];

/** Validate and save a report submitted by a verified kiosk resident. */
function resident_blotter_request_submit(int $residentId, array $input, array &$errors): ?array
{
    $resident = db_one(
        'SELECT r.resident_id, r.resident_no, r.first_name, r.last_name, r.birthdate,
                COALESCE(NULLIF(r.address, \'\'), h.street_address) AS address,
                r.contact_number, r.archived_at
         FROM residents r LEFT JOIN households h ON h.household_id = r.household_id
         WHERE r.resident_id = ?',
        [$residentId]
    );
    if ($resident === null || $resident['archived_at'] !== null) {
        $errors[] = 'Your resident record could not be verified. Please ask Staff for assistance.';
        return null;
    }

    $incidentDate = input_str($input, 'incident_date');
    $incidentTime = input_str($input, 'incident_time');
    $place = input_str($input, 'incident_place');
    $purokText = input_str($input, 'purok_id');
    $typeText = input_str($input, 'incident_type_id');
    $narrative = input_str($input, 'narrative', true);
    $respondentName = input_str($input, 'respondent_name');
    $respondentAge = input_str($input, 'respondent_age');
    $respondentAddress = input_str($input, 'respondent_address');
    $respondentContact = input_str($input, 'respondent_contact');
    $notificationPreference = input_str($input, 'notification_preference');
    if ($notificationPreference !== '' && !queue_valid_notification_preference($notificationPreference)) {
        $errors[] = 'Choose Email notifications only.';
    }

    $oldest = date('Y-m-d', strtotime('-10 years'));
    if (!v_date($incidentDate)) { $errors[] = 'Enter a valid incident date.'; }
    elseif ($incidentDate > date('Y-m-d')) { $errors[] = 'The incident date cannot be in the future.'; }
    elseif ($incidentDate < $oldest) { $errors[] = 'The incident date cannot be more than 10 years ago.'; }
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $incidentTime)) {
        $errors[] = 'Enter a valid incident time.';
    }
    if (!v_len($place, 3, 150)) { $errors[] = 'Place of incident must be 3-150 characters.'; }
    if (!v_len($narrative, 10, 5000)) { $errors[] = 'Describe what happened (10-5000 characters).'; }

    $purokId = null;
    if ($purokText !== '') {
        if (preg_match('/^\d{1,9}$/', $purokText) !== 1 || !isset(lookup_list('purok')[(int) $purokText])) {
            $errors[] = 'Choose a Purok from the list.';
        } else {
            $purokId = (int) $purokText;
        }
    }
    $incidentTypeId = null;
    if ($typeText !== '') {
        if (preg_match('/^\d{1,9}$/', $typeText) !== 1 || !isset(lookup_list('incident_type')[(int) $typeText])) {
            $errors[] = 'Choose an incident type from the list.';
        } else {
            $incidentTypeId = (int) $typeText;
        }
    }

    $respondent = null;
    if ($respondentName . $respondentAge . $respondentAddress . $respondentContact !== '') {
        $respondent = party_clean($respondentName, $respondentAge, $respondentAddress, $respondentContact, 'Respondent', $errors);
    }
    if ($errors) { return null; }

    $result = db_transaction(function () use ($resident, $residentId, $incidentDate, $incidentTime, $place, $purokId, $incidentTypeId, $narrative, $respondent, $notificationPreference): array {
        // Reserve a case reference at submission. A rejected request may leave a gap, but references remain unique and auditable.
        $reference = new_case_no();
        db_exec(
            "INSERT INTO resident_blotter_requests
             (reference_no, resident_id, incident_date, incident_time, incident_place, purok_id, incident_type_id,
              narrative, respondent_name, respondent_age, respondent_address, respondent_contact, review_status, msit_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Ongoing')",
            [
                $reference, $residentId, $incidentDate, $incidentTime . ':00', $place, $purokId, $incidentTypeId,
                $narrative, $respondent['name'] ?? null, $respondent['age'] ?? null,
                $respondent['address'] ?? null, $respondent['contact'] ?? null,
            ]
        );
        $requestId = (int) db()->lastInsertId();
        db_exec(
            "INSERT INTO request_status_history
             (service, reference_no, previous_status, new_status, changed_by, note)
             VALUES ('Blotter', ?, NULL, 'Ongoing', NULL, 'Submitted by resident through the self-service kiosk; awaiting Staff review.')",
            [$reference]
        );
        audit('resident_blotter_request_created', 'Blotter', 'Success',
            $reference . ' submitted by verified resident #' . $residentId,
            'resident_blotter_request', $requestId, 'resident-kiosk', null);

        $ticket = queue_issue_number('Blotter', $residentId, $reference, 'kiosk', 'Resident blotter report',
            $notificationPreference !== '' ? $notificationPreference : null, true);
        return [
            'request_id' => $requestId,
            'reference_no' => $reference,
            'ticket' => $ticket,
            'submitted_at' => date('Y-m-d H:i:s'),
        ];
    });
    queue_notify_ticket_issued((int) $result['ticket']['queue_id']);
    return $result;

}

/** Staff or Admin accepts an intake and creates the permanent official blotter record atomically. */
function resident_blotter_request_accept(int $requestId, string $note = ''): int
{
    if (!can('blotter_requests.review')) {
        throw new DomainException('Your role cannot review resident blotter requests.');
    }
    if ($note !== '' && !v_len($note, 3, 500)) {
        throw new InvalidArgumentException('The review note must be 3-500 characters.');
    }

    $blotterId = db_transaction(function () use ($requestId, $note): int {
        $request = db_one(
            'SELECT * FROM resident_blotter_requests WHERE request_id = ? FOR UPDATE',
            [$requestId]
        );
        if ($request === null) { throw new RuntimeException('That resident blotter request was not found.'); }
        if ($request['review_status'] !== 'Pending' || $request['blotter_id'] !== null) {
            throw new InvalidArgumentException('This blotter request has already been reviewed.');
        }

        $resident = db_one(
            'SELECT r.resident_id, r.first_name, r.last_name, r.birthdate,
                    COALESCE(NULLIF(r.address, \'\'), h.street_address) AS address,
                    r.contact_number
             FROM residents r LEFT JOIN households h ON h.household_id = r.household_id
             WHERE r.resident_id = ?',
            [(int) $request['resident_id']]
        );
        if ($resident === null) { throw new RuntimeException('The resident record is no longer available.'); }

        db_exec(
            "INSERT INTO blotters
             (case_no, incident_date, incident_time, incident_place, purok_id, incident_type_id, narrative,
              status, msit_status, recorded_at, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', 'Ongoing', NOW(), ?)",
            [
                $request['reference_no'], $request['incident_date'], $request['incident_time'],
                $request['incident_place'], $request['purok_id'], $request['incident_type_id'],
                $request['narrative'], audit_actor()['user_id'] ?? null,
            ]
        );
        $blotterId = (int) db()->lastInsertId();
        $complainantName = trim((string) $resident['first_name'] . ' ' . (string) $resident['last_name']);
        db_exec(
            "INSERT INTO blotter_parties (blotter_id, party_role, full_name, age, address, contact_number)
             VALUES (?, 'Complainant', ?, ?, ?, ?)",
            [
                $blotterId, $complainantName, age_on((string) $resident['birthdate']),
                $resident['address'], $resident['contact_number'],
            ]
        );
        if (trim((string) $request['respondent_name']) !== '') {
            db_exec(
                "INSERT INTO blotter_parties (blotter_id, party_role, full_name, age, address, contact_number)
                 VALUES (?, 'Respondent', ?, ?, ?, ?)",
                [
                    $blotterId, $request['respondent_name'], $request['respondent_age'],
                    $request['respondent_address'], $request['respondent_contact'],
                ]
            );
        }

        $actorId = audit_actor()['user_id'] ?? null;
        $changed = db_exec(
            "UPDATE resident_blotter_requests
             SET review_status = 'Accepted', blotter_id = ?, reviewed_by = ?, reviewed_at = NOW(), status_note = ?
             WHERE request_id = ? AND review_status = 'Pending' AND blotter_id IS NULL",
            [$blotterId, $actorId, $note !== '' ? $note : null, $requestId]
        );
        if ($changed !== 1) { throw new RuntimeException('The request changed before it could be accepted. Refresh and try again.'); }

        db_exec(
            "INSERT INTO request_status_history
             (service, reference_no, previous_status, new_status, changed_by, note, blotter_id)
             VALUES ('Blotter', ?, 'Ongoing', 'Ongoing', ?, ?, ?)",
            [$request['reference_no'], $actorId, 'Accepted by Staff; official blotter case created. ' . $note, $blotterId]
        );
        audit('resident_blotter_request_accepted', 'Blotter', 'Success',
            $request['reference_no'] . ' accepted and created as official case #' . $blotterId,
            'blotter', $blotterId);
        return $blotterId;
    });

    $savedRequest = db_one(
        'SELECT resident_id, reference_no, msit_status FROM resident_blotter_requests WHERE request_id = ?',
        [$requestId]
    );
    if ($savedRequest !== null) {
        try {
            resident_notify_status_change(
                (int) $savedRequest['resident_id'],
                'Blotter',
                (string) $savedRequest['reference_no'],
                (string) $savedRequest['msit_status'],
                'resident_blotter_reviewed',
                'Your report was accepted by Staff and an official blotter case was created.'
            );
        } catch (Throwable $notificationError) {
            error_log('BIRMS blotter acceptance notification failed for ' . (string) $savedRequest['reference_no'] . ': ' . get_class($notificationError) . ': ' . $notificationError->getMessage());
            audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                'Blotter report ' . (string) $savedRequest['reference_no'] . ' was accepted but the resident notification could not be queued.');
        }
    }
    return $blotterId;
}

/** Staff or Admin rejects an intake. The resident-facing SRS status becomes Dismissed. */
function resident_blotter_request_reject(int $requestId, string $reason): void
{
    if (!can('blotter_requests.review')) {
        throw new DomainException('Your role cannot review resident blotter requests.');
    }
    if (!v_len($reason, 3, 500)) {
        throw new InvalidArgumentException('Enter a reason for dismissing this report (3-500 characters).');
    }

    db_transaction(function () use ($requestId, $reason): void {
        $request = db_one(
            'SELECT request_id, reference_no, review_status, blotter_id, msit_status
             FROM resident_blotter_requests WHERE request_id = ? FOR UPDATE',
            [$requestId]
        );
        if ($request === null) { throw new RuntimeException('That resident blotter request was not found.'); }
        if ($request['review_status'] !== 'Pending' || $request['blotter_id'] !== null) {
            throw new InvalidArgumentException('This blotter request has already been reviewed.');
        }
        $actorId = audit_actor()['user_id'] ?? null;
        $changed = db_exec(
            "UPDATE resident_blotter_requests
             SET review_status = 'Rejected', msit_status = 'Dismissed', status_note = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE request_id = ? AND review_status = 'Pending' AND blotter_id IS NULL",
            [$reason, $actorId, $requestId]
        );
        if ($changed !== 1) { throw new RuntimeException('The request changed before it could be rejected. Refresh and try again.'); }
        db_exec(
            "INSERT INTO request_status_history
             (service, reference_no, previous_status, new_status, changed_by, note)
             VALUES ('Blotter', ?, 'Ongoing', 'Dismissed', ?, ?)",
            [$request['reference_no'], $actorId, $reason]
        );
        audit('resident_blotter_request_rejected', 'Blotter', 'Success',
            $request['reference_no'] . ' rejected: ' . $reason,
            'resident_blotter_request', $requestId);
    });

    $savedRequest = db_one(
        'SELECT resident_id, reference_no, msit_status FROM resident_blotter_requests WHERE request_id = ?',
        [$requestId]
    );
    if ($savedRequest !== null) {
        try {
            resident_notify_status_change(
                (int) $savedRequest['resident_id'],
                'Blotter',
                (string) $savedRequest['reference_no'],
                (string) $savedRequest['msit_status'],
                'resident_blotter_reviewed',
                'Your report was dismissed by Staff. Reason: ' . $reason
            );
        } catch (Throwable $notificationError) {
            error_log('BIRMS blotter rejection notification failed for ' . (string) $savedRequest['reference_no'] . ': ' . get_class($notificationError) . ': ' . $notificationError->getMessage());
            audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                'Blotter report ' . (string) $savedRequest['reference_no'] . ' was dismissed but the resident notification could not be queued.');
        }
    }
}
