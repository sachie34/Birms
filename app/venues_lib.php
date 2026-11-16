<?php
/** Venue directory, availability and reservation rules for the revised MSIT SRS. */
declare(strict_types=1);

const VENUE_RESERVATION_STATUSES = ['Pending', 'Approved', 'Rejected', 'Completed'];

function venue_reference_no(): string
{
    return 'VEN-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/** Green means available; Red means in use. Pending requests hold the time slot against conflicts but do not turn the live board red. */
function venue_current_state(array $venue, ?DateTimeImmutable $now = null): string
{
    $override = (string) ($venue['availability_override'] ?? 'automatic');
    if ($override === 'in_use') { return 'in_use'; }
    if ($override === 'available') { return 'available'; }
    $now ??= new DateTimeImmutable('now');
    $date = $now->format('Y-m-d');
    $time = $now->format('H:i:s');
    $active = (int) db_val(
        "SELECT COUNT(*) FROM venue_reservations
         WHERE venue_id = ? AND reservation_date = ? AND status = 'Approved'
           AND start_time <= ? AND end_time > ?",
        [(int) $venue['venue_id'], $date, $time, $time]
    );
    return $active > 0 ? 'in_use' : 'available';
}

function venue_reservations_overlap(int $venueId, string $date, string $start, string $end, ?int $exceptId = null): bool
{
    $sql = "SELECT COUNT(*) FROM venue_reservations
            WHERE venue_id = ? AND reservation_date = ?
              AND status IN ('Pending','Approved')
              AND start_time < ? AND end_time > ?";
    $params = [$venueId, $date, $end, $start];
    if ($exceptId !== null) { $sql .= ' AND reservation_id <> ?'; $params[] = $exceptId; }
    return (int) db_val($sql, $params) > 0;
}

function venue_reservation_validate(int $venueId, int $residentId, string $purpose, string $date, string $start, string $end): array
{
    $errors = [];
    $venue = db_one('SELECT venue_id, name, is_active FROM venues WHERE venue_id = ?', [$venueId]);
    if ($venue === null || (int) $venue['is_active'] !== 1) { $errors[] = 'Choose an available venue.'; }
    $resident = db_one('SELECT resident_id, archived_at FROM residents WHERE resident_id = ?', [$residentId]);
    if ($resident === null || $resident['archived_at'] !== null) { $errors[] = 'Verify an active resident before making a reservation.'; }
    if (!v_len($purpose, 3, 255)) { $errors[] = 'Purpose must be 3-255 characters.'; }
    if (!v_date($date)) { $errors[] = 'Choose a valid reservation date.'; }
    elseif ($date < date('Y-m-d')) { $errors[] = 'The reservation date cannot be in the past.'; }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $start)) { $errors[] = 'Enter a valid start time.'; }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $end)) { $errors[] = 'Enter a valid end time.'; }
    if (!$errors && $start >= $end) { $errors[] = 'End time must be later than start time.'; }
    return [$errors, $venue, $resident];
}

/** Create a Pending reservation after locking the venue row to prevent concurrent overlaps. */
function venue_create_reservation(int $venueId, int $residentId, string $purpose, string $date, string $start, string $end, ?int $createdBy = null): array
{
    [$errors] = venue_reservation_validate($venueId, $residentId, $purpose, $date, $start, $end);
    if ($errors) { throw new InvalidArgumentException(implode(' ', $errors)); }
    return db_transaction(function () use ($venueId, $residentId, $purpose, $date, $start, $end, $createdBy): array {
        $venue = db_one('SELECT venue_id, name, is_active FROM venues WHERE venue_id = ? FOR UPDATE', [$venueId]);
        if ($venue === null || (int) $venue['is_active'] !== 1) { throw new InvalidArgumentException('That venue is no longer available.'); }
        $resident = db_one('SELECT resident_id, archived_at FROM residents WHERE resident_id = ? FOR UPDATE', [$residentId]);
        if ($resident === null || $resident['archived_at'] !== null) { throw new InvalidArgumentException('The resident is no longer active. Verify the resident and try again.'); }
        if (venue_reservations_overlap($venueId, $date, $start, $end)) {
            throw new InvalidArgumentException('That time overlaps an existing Pending request or Approved booking for this venue. Choose another time.');
        }
        $reference = venue_reference_no();
        db_exec(
            "INSERT INTO venue_reservations
             (reference_no, venue_id, resident_id, purpose, reservation_date, start_time, end_time, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?)",
            [$reference, $venueId, $residentId, $purpose, $date, $start, $end, $createdBy]
        );
        $id = (int) db()->lastInsertId();
        db_exec(
            "INSERT INTO request_status_history
             (service, reference_no, previous_status, new_status, changed_by, note, reservation_id)
             VALUES ('Venue', ?, NULL, 'Pending', ?, 'Reservation request submitted.', ?)",
            [$reference, $createdBy, $id]
        );
        audit('venue_reservation_created', 'Venues', 'Success', $reference . ' submitted for ' . $venue['name'], 'venue_reservation', $id);
        return ['reservation_id' => $id, 'reference_no' => $reference, 'venue_id' => $venueId];
    });
}

/** Staff decision. The venue lock and second overlap check protect against concurrent approvals. */
function venue_change_status(int $reservationId, string $to, string $note = ''): void
{
    if (!in_array($to, ['Approved', 'Rejected', 'Completed'], true)) { throw new InvalidArgumentException('Choose a valid reservation status.'); }
    db_transaction(function () use ($reservationId, $to, $note): void {
        $reservation = db_one('SELECT * FROM venue_reservations WHERE reservation_id = ? FOR UPDATE', [$reservationId]);
        if ($reservation === null) { throw new RuntimeException('That reservation was not found.'); }
        $venue = db_one('SELECT venue_id FROM venues WHERE venue_id = ? FOR UPDATE', [(int) $reservation['venue_id']]);
        if ($venue === null) { throw new RuntimeException('The venue was not found.'); }

        $from = (string) $reservation['status'];
        $allowed = match ($to) {
            'Approved', 'Rejected' => ['Pending'],
            'Completed' => ['Approved'],
            default => [],
        };
        if (!in_array($from, $allowed, true)) { throw new InvalidArgumentException('A ' . $from . ' reservation cannot be changed to ' . $to . '.'); }
        if ($to === 'Approved' && venue_reservations_overlap(
            (int) $reservation['venue_id'],
            (string) $reservation['reservation_date'],
            (string) $reservation['start_time'],
            (string) $reservation['end_time'],
            $reservationId
        )) {
            throw new InvalidArgumentException('The venue has another pending or approved reservation that overlaps this time. It cannot be approved.');
        }
        if ($to === 'Rejected' && !v_len($note, 3, 500)) {
            throw new InvalidArgumentException('Enter a reason (3-500 characters) when rejecting a reservation.');
        }
        if (str_len($note) > 500) { throw new InvalidArgumentException('The note is limited to 500 characters.'); }

        $changed = db_exec(
            'UPDATE venue_reservations SET status = ?, status_note = ? WHERE reservation_id = ? AND status = ?',
            [$to, $note !== '' ? $note : null, $reservationId, $from]
        );
        if ($changed !== 1) { throw new RuntimeException('The reservation changed while you were processing it. Refresh and try again.'); }
        db_exec(
            'INSERT INTO request_status_history
             (service, reference_no, previous_status, new_status, changed_by, note, reservation_id)
             VALUES (\'Venue\', ?, ?, ?, ?, ?, ?)',
            [$reservation['reference_no'], $from, $to, audit_actor()['user_id'] ?? null, $note !== '' ? $note : null, $reservationId]
        );
        audit('venue_reservation_status_changed', 'Venues', 'Success',
            $reservation['reference_no'] . ': ' . $from . ' -> ' . $to,
            'venue_reservation', $reservationId);
    });

    // Send the resident-facing update only after the reservation status transaction commits.
    $saved = db_one(
        'SELECT resident_id, reference_no, status FROM venue_reservations WHERE reservation_id = ?',
        [$reservationId]
    );
    if ($saved !== null) {
        try {
            resident_notify_status_change(
                (int) $saved['resident_id'],
                'Venue',
                (string) $saved['reference_no'],
                (string) $saved['status'],
                'venue_reservation_status_changed',
                $note
            );
        } catch (Throwable $notificationError) {
            error_log('BIRMS venue status notification failed for ' . (string) $saved['reference_no'] . ': ' . get_class($notificationError));
            audit_best_effort('resident_notification_failed', 'Notifications', 'Warning',
                'Venue reservation ' . (string) $saved['reference_no'] . ' changed status but notification could not be queued.');
        }
    }
}

function venue_set_override(int $venueId, string $override): void
{
    if (!in_array($override, ['automatic', 'available', 'in_use'], true)) {
        throw new InvalidArgumentException('Choose Automatic, Green/Available or Red/In Use.');
    }
    db_transaction(function () use ($venueId, $override): void {
        $venue = db_one('SELECT venue_id, name FROM venues WHERE venue_id = ? FOR UPDATE', [$venueId]);
        if ($venue === null) { throw new RuntimeException('That venue was not found.'); }
        db_exec('UPDATE venues SET availability_override = ? WHERE venue_id = ?', [$override, $venueId]);
        audit('venue_override_changed', 'Venues', 'Success',
            $venue['name'] . ' availability override set to ' . $override, 'venue', $venueId);
    });
}

function venue_list(bool $activeOnly = true): array
{
    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    $rows = db_all("SELECT * FROM venues {$where} ORDER BY name");
    foreach ($rows as &$row) {
        $row['current_state'] = venue_current_state($row);
    }
    unset($row);
    return $rows;
}
