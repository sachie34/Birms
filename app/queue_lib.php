<?php
/** Queueing helpers for the revised MSIT SRS: separate daily queues for Blotter, Documents and Venue. */
declare(strict_types=1);

const QUEUE_SERVICES = ['Blotter', 'Documents', 'Venue'];
const QUEUE_STATUSES = ['Waiting', 'Called', 'Served', 'Skipped', 'Cancelled'];

function queue_valid_service(string $service): bool
{
    return in_array($service, QUEUE_SERVICES, true);
}

function queue_valid_notification_preference(string $preference): bool
{
    return in_array($preference, ['email', 'sms', 'both'], true);
}

/** Resolve a kiosk choice, or retain the resident's saved preference when older callers omit it. */
function queue_resolve_notification_preference(?string $requested, ?string $fallback = 'email'): string
{
    $fallback = is_string($fallback) && queue_valid_notification_preference($fallback) ? $fallback : 'email';
    if ($requested === null || trim($requested) === '') { return $fallback; }
    $requested = trim($requested);
    if (!queue_valid_notification_preference($requested)) {
        throw new InvalidArgumentException('Choose Email, SMS or Both for queue notifications.');
    }
    return $requested;
}

/** Render the same safe option list on every resident-facing kiosk form. */
function queue_notification_preference_options(?string $selected = 'email'): string
{
    $selected = is_string($selected) && queue_valid_notification_preference($selected) ? $selected : 'email';
    $labels = ['email' => 'Email', 'sms' => 'SMS', 'both' => 'Both'];
    $html = '';
    foreach ($labels as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Issue the next number for a service/day. The counter row is locked inside the transaction,
 * so simultaneous counter/kiosk requests cannot receive the same number.
 *
 * Notification delivery is deliberately deferred until the enclosing transaction commits.
 *
 * @return array{queue_id:int,service:string,queue_date:string,queue_number:int}
 */
function queue_issue_number(
    string $service,
    ?int $residentId = null,
    ?string $referenceNo = null,
    string $issuedVia = 'staff',
    ?string $purpose = null,
    ?string $notificationPreference = null,
    bool $deferNotifications = false
): array {
    if (!queue_valid_service($service)) {
        throw new InvalidArgumentException('Choose Blotter, Documents or Venue.');
    }
    if (!in_array($issuedVia, ['kiosk', 'qr', 'staff'], true)) {
        throw new InvalidArgumentException('Invalid queue issue source.');
    }
    $resident = null;
    if ($residentId !== null) {
        $resident = db_one(
            'SELECT resident_id, contact_number, email_address, notification_preference, archived_at
             FROM residents WHERE resident_id = ?',
            [$residentId]
        );
        if ($resident === null || $resident['archived_at'] !== null) {
            throw new InvalidArgumentException('The selected resident does not exist or is archived.');
        }
    }
    $referenceNo = $referenceNo !== null ? trim($referenceNo) : null;
    if ($referenceNo !== null && $referenceNo !== '' && !preg_match('/^[A-Za-z0-9._-]{1,24}$/', $referenceNo)) {
        throw new InvalidArgumentException('The reference number is not valid.');
    }
    $purpose = $purpose !== null ? trim($purpose) : null;
    if ($purpose !== null && !v_len($purpose, 3, 255)) {
        throw new InvalidArgumentException('Enter the reason for visiting (3-255 characters).');
    }
    $savedPreference = (string) ($resident['notification_preference'] ?? 'email');
    $preference = queue_resolve_notification_preference($notificationPreference, $savedPreference);
    $persistPreference = $residentId !== null && $notificationPreference !== null && trim($notificationPreference) !== '';

    $entry = db_transaction(function () use ($service, $residentId, $referenceNo, $issuedVia, $purpose, $preference, $persistPreference, $resident): array {
        $today = date('Y-m-d');
        db_exec('INSERT IGNORE INTO queue_counters (service, queue_date, last_number) VALUES (?, ?, 0)', [$service, $today]);
        $counter = db_one('SELECT last_number FROM queue_counters WHERE service = ? AND queue_date = ? FOR UPDATE', [$service, $today]);
        if ($counter === null) { throw new RuntimeException('The queue counter could not be locked.'); }
        $number = (int) $counter['last_number'] + 1;
        if ($number < 1 || $number > 4294967295) { throw new RuntimeException('The queue number limit has been reached for today.'); }
        db_exec('UPDATE queue_counters SET last_number = ? WHERE service = ? AND queue_date = ?', [$number, $service, $today]);
        db_exec(
            'INSERT INTO queue_entries (service, queue_date, queue_number, resident_id, reference_no, purpose, status, issued_via, notification_preference)
             VALUES (?, ?, ?, ?, ?, ?, \'Waiting\', ?, ?)',
            [$service, $today, $number, $residentId, ($referenceNo !== '' ? $referenceNo : null), $purpose, $issuedVia, $preference]
        );
        if ($persistPreference && (string) ($resident['notification_preference'] ?? 'email') !== $preference) {
            db_exec(
                'UPDATE residents SET notification_preference = ? WHERE resident_id = ? AND archived_at IS NULL',
                [$preference, $residentId]
            );
            audit_best_effort(
                'resident_notification_preference_changed',
                'Notifications',
                'Success',
                'Resident #' . $residentId . ' changed their self-service notification preference to ' . $preference . '.'
            );
        }
        $id = (int) db()->lastInsertId();
        audit('queue_number_issued', 'Queue', 'Success', $service . ' queue #' . $number . ' issued for ' . $today, 'queue_entry', $id);
        return ['queue_id' => $id, 'service' => $service, 'queue_date' => $today, 'queue_number' => $number];
    });

    // A direct counter/kiosk issuance is now committed. Callers already inside a larger
    // request/reservation/blotter transaction must explicitly dispatch after that commit.
    if ($residentId !== null && !$deferNotifications) {
        queue_dispatch_resident_notification(
            $residentId,
            $service,
            (int) $entry['queue_number'],
            'queue_issued',
            'Your queue number is ' . (int) $entry['queue_number'] . ' for ' . $service . '. Please watch the queue display.',
            $preference
        );
    }
    return $entry;
}

/** Dispatch one ticket's issue notification only after the owning workflow transaction commits. */
function queue_notify_ticket_issued(int $queueId): void
{
    if (db()->inTransaction()) {
        throw new LogicException('Queue issue notifications must be dispatched after the owning transaction commits.');
    }
    try {
        $entry = db_one(
            'SELECT service, queue_number, resident_id, notification_preference
             FROM queue_entries WHERE queue_id = ?',
            [$queueId]
        );
        if ($entry === null || empty($entry['resident_id'])) { return; }
        queue_dispatch_resident_notification(
            (int) $entry['resident_id'],
            (string) $entry['service'],
            (int) $entry['queue_number'],
            'queue_issued',
            'Your queue number is ' . (int) $entry['queue_number'] . ' for ' . (string) $entry['service'] . '. Please watch the queue display.',
            (string) ($entry['notification_preference'] ?? 'email')
        );
    } catch (Throwable $notificationError) {
        error_log('BIRMS queue issue notification lookup failed for queue #' . $queueId . ': ' . get_class($notificationError));
        audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
            'Queue #' . $queueId . ' was committed but its issue notification could not be dispatched.');
    }
}

/** Keep notification/provider errors from reversing an already-committed queue action. */
function queue_dispatch_resident_notification(
    int $residentId,
    string $service,
    int $number,
    string $event,
    string $message,
    ?string $preferenceOverride = null
): void {
    if (db()->inTransaction()) {
        throw new LogicException('Queue notifications cannot be dispatched inside a database transaction.');
    }
    try {
        queue_enqueue_notifications($residentId, $service, $number, $event, $message, $preferenceOverride);
    } catch (Throwable $notificationError) {
        error_log('BIRMS queue notification failed for resident #' . $residentId . ', ' . $service . ' #' . $number
            . ': ' . get_class($notificationError) . ': ' . $notificationError->getMessage());
        audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
            $service . ' queue #' . $number . ' was committed but its notification could not be queued.');
    }
}

/** Put channel notices in the outbox atomically, then contact providers only after that transaction commits. */
function queue_enqueue_notifications(
    int $residentId,
    string $service,
    int $number,
    string $event,
    string $message,
    ?string $preferenceOverride = null
): void {
    if (db()->inTransaction()) {
        throw new LogicException('Queue notification outbox rows and provider calls must be created after the service transaction commits.');
    }
    $resident = db_one('SELECT email_address, contact_number, notification_preference FROM residents WHERE resident_id = ?', [$residentId]);
    if ($resident === null) { return; }
    $preference = queue_resolve_notification_preference($preferenceOverride, (string) ($resident['notification_preference'] ?? 'email'));
    $email = trim((string) ($resident['email_address'] ?? ''));
    $mobile = trim((string) ($resident['contact_number'] ?? ''));
    $reference = $service . '-' . date('Ymd') . '-' . $number;
    $wantsEmail = in_array($preference, ['email', 'both'], true);
    $wantsSms = in_array($preference, ['sms', 'both'], true);
    $smsEnabled = $wantsSms && $mobile !== '' && sms_provider_is_enabled();

    // SMS-only choices fall back to email when SMS is unavailable.
    $emailFallback = $wantsSms && !$smsEnabled && !$wantsEmail;
    if ($wantsSms && !$smsEnabled) { $wantsEmail = true; }

    $emailId = null;
    $smsId = null;
    $emailQueued = false;
    db_transaction(function () use (
        $residentId, $email, $mobile, $message, $event, $reference,
        $wantsEmail, $wantsSms, $smsEnabled, $emailFallback, &$emailId, &$smsId, &$emailQueued
    ): void {
        if ($wantsEmail && $email !== '') {
            $emailEvent = $emailFallback ? $event . '_email_fallback' : $event;
            $emailId = email_queue_notification($residentId, $email, 'Barangay queue update', $message, $emailEvent, $reference);
            $emailQueued = true;
        }
        if ($wantsSms && $mobile !== '') {
            db_exec(
                'INSERT INTO sms_outbox (recipient_number, message, trigger_event, request_id, delivery, provider_name, provider_status)
                 VALUES (?, ?, ?, NULL, ?, ?, ?)',
                [
                    $mobile,
                    mb_substr_safe($message, 480),
                    $event,
                    $smsEnabled ? 'Waiting to send' : 'Logged only',
                    $smsEnabled ? sms_provider_selected_name() : null,
                    $smsEnabled ? 'not_attempted' : 'not_configured',
                ]
            );
            $smsId = (int) db()->lastInsertId();
        }
    });

    // All outbox rows are committed before any external provider call.
    if ($emailId !== null && email_provider_is_enabled()) {
        try {
            email_send_notification((int) $emailId);
        } catch (Throwable $emailError) {
            error_log('BIRMS queue email send failed for outbox #' . (int) $emailId . ': ' . get_class($emailError));
        }
    }

    if ($smsId !== null && $smsEnabled) {
        $status = 'unknown';
        try {
            $result = sms_send_outbox((int) $smsId, false);
            $status = (string) ($result['status'] ?? 'unknown');
        } catch (Throwable $smsError) {
            // Preserve the SMS row as an uncertain attempt; never resend it automatically.
            error_log('BIRMS queue SMS send failed for outbox #' . (int) $smsId . ': ' . get_class($smsError));
            audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                $service . ' queue #' . $number . ' SMS result could not be confirmed.');
        }
        if (!$emailQueued && $email !== '' && resident_sms_needs_email_fallback($status)) {
            resident_queue_email_fallback(
                $residentId, $email, $service, $message, $event, $reference, $status
            );
        }
    }
}

/**
 * Call the next waiting resident for one service. There can be only one current Called entry
 * per service/day; another number cannot be called until the current one is served/skipped/cancelled.
 */
function queue_call_next(string $service): ?array
{
    if (!queue_valid_service($service)) { throw new InvalidArgumentException('Unknown queue service.'); }
    $result = db_transaction(function () use ($service): array {
        $today = date('Y-m-d');
        db_exec('INSERT IGNORE INTO queue_counters (service, queue_date, last_number) VALUES (?, ?, 0)', [$service, $today]);
        db_one('SELECT last_number FROM queue_counters WHERE service = ? AND queue_date = ? FOR UPDATE', [$service, $today]);
        $current = db_one(
            'SELECT queue_id, queue_number FROM queue_entries
             WHERE service = ? AND queue_date = ? AND status = \'Called\' LIMIT 1 FOR UPDATE',
            [$service, $today]
        );
        if ($current !== null) {
            throw new RuntimeException('Serve, skip or cancel the current called number before calling the next one.');
        }
        $next = db_one(
            'SELECT queue_id, queue_number, resident_id, reference_no, notification_preference FROM queue_entries
             WHERE service = ? AND queue_date = ? AND status = \'Waiting\'
             ORDER BY queue_number LIMIT 1 FOR UPDATE',
            [$service, $today]
        );
        if ($next === null) { return ['called' => null, 'near' => null]; }
        $changed = db_exec(
            'UPDATE queue_entries SET status = \'Called\', called_at = NOW(), called_by = ?
             WHERE queue_id = ? AND status = \'Waiting\'',
            [audit_actor()['user_id'] ?? null, (int) $next['queue_id']]
        );
        if ($changed !== 1) { throw new RuntimeException('The queue entry changed before it could be called. Refresh and try again.'); }
        audit('queue_number_called', 'Queue', 'Success', $service . ' queue #' . (int) $next['queue_number'] . ' called', 'queue_entry', (int) $next['queue_id']);
        $near = db_one(
            'SELECT queue_number, resident_id, notification_preference FROM queue_entries
             WHERE service = ? AND queue_date = ? AND status = \'Waiting\'
             ORDER BY queue_number LIMIT 1',
            [$service, $today]
        );
        return ['called' => $next, 'near' => $near];
    });

    // Notifications are sent only after the call status is committed.
    $called = $result['called'];
    $near = $result['near'];
    if ($called !== null && !empty($called['resident_id'])) {
        queue_dispatch_resident_notification(
            (int) $called['resident_id'], $service, (int) $called['queue_number'],
            'queue_called',
            'Your queue number ' . (int) $called['queue_number'] . ' for ' . $service . ' is now being called. Please proceed to the counter.',
            (string) ($called['notification_preference'] ?? 'email')
        );
    }
    if ($near !== null && !empty($near['resident_id'])) {
        queue_dispatch_resident_notification(
            (int) $near['resident_id'], $service, (int) $near['queue_number'],
            'queue_near',
            'Your queue number ' . (int) $near['queue_number'] . ' for ' . $service . ' is next after the number now being served. Please get ready.',
            (string) ($near['notification_preference'] ?? 'email')
        );
    }
    return $called;
}

/** Complete a called entry as Served, Skipped or Cancelled. */
function queue_finish_entry(int $queueId, string $nextStatus): void
{
    if (!in_array($nextStatus, ['Served', 'Skipped', 'Cancelled'], true)) {
        throw new InvalidArgumentException('Invalid queue completion status.');
    }
    db_transaction(function () use ($queueId, $nextStatus): void {
        $entry = db_one('SELECT queue_id, service, queue_date, queue_number, status FROM queue_entries WHERE queue_id = ? FOR UPDATE', [$queueId]);
        if ($entry === null) { throw new RuntimeException('That queue entry was not found.'); }
        if ((string) $entry['queue_date'] !== date('Y-m-d')) { throw new RuntimeException('Only today’s queue entries can be changed from this console.'); }
        $allowed = match ($nextStatus) {
            'Served' => ['Called'],
            'Skipped' => ['Waiting', 'Called'],
            'Cancelled' => ['Waiting', 'Called'],
            default => [],
        };
        if (!in_array((string) $entry['status'], $allowed, true)) {
            throw new RuntimeException('That queue entry can no longer be ' . strtolower($nextStatus) . '.');
        }
        $servedAtSql = $nextStatus === 'Served' ? 'NOW()' : 'NULL';
        $changed = db_exec(
            'UPDATE queue_entries SET status = ?, served_at = ' . $servedAtSql . ', served_by = ?
             WHERE queue_id = ? AND status = ?',
            [$nextStatus, audit_actor()['user_id'] ?? null, $queueId, $entry['status']]
        );
        if ($changed !== 1) { throw new RuntimeException('The queue entry changed before this action completed. Refresh and try again.'); }
        audit('queue_number_' . strtolower($nextStatus), 'Queue', 'Success',
            $entry['service'] . ' queue #' . (int) $entry['queue_number'] . ' marked ' . $nextStatus,
            'queue_entry', $queueId);
    });
}

/** Current service snapshot for staff/public display. No resident-identifying details are returned. */
function queue_service_snapshot(string $service): array
{
    if (!queue_valid_service($service)) { throw new InvalidArgumentException('Unknown queue service.'); }
    $today = date('Y-m-d');
    $current = db_one(
        'SELECT queue_number, called_at FROM queue_entries WHERE service = ? AND queue_date = ? AND status = \'Called\' ORDER BY called_at LIMIT 1',
        [$service, $today]
    );
    $waiting = db_all(
        'SELECT queue_id, queue_number, issued_at, reference_no, purpose FROM queue_entries
         WHERE service = ? AND queue_date = ? AND status = \'Waiting\' ORDER BY queue_number LIMIT 50',
        [$service, $today]
    );
    $waitingCount = (int) db_val(
        'SELECT COUNT(*) FROM queue_entries WHERE service = ? AND queue_date = ? AND status = \'Waiting\'',
        [$service, $today]
    );
    // Waiting time ends when the ticket is called; served_at also includes service time.
    $averageWait = db_val(
        'SELECT AVG(TIMESTAMPDIFF(SECOND, issued_at, called_at)) FROM queue_entries
         WHERE service = ? AND queue_date = ? AND status = \'Served\' AND called_at IS NOT NULL',
        [$service, $today]
    );
    return [
        'service' => $service,
        'current_number' => $current !== null ? (int) $current['queue_number'] : null,
        'current_called_at' => $current['called_at'] ?? null,
        'waiting' => $waiting,
        'waiting_count' => $waitingCount,
        'average_wait_seconds' => $averageWait !== null ? (int) round((float) $averageWait) : null,
    ];
}

/** All three queue snapshots for the display and reports. */
function queue_all_snapshots(): array
{
    $out = [];
    foreach (QUEUE_SERVICES as $service) { $out[$service] = queue_service_snapshot($service); }
    return $out;
}
