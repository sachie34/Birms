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
 * @return array{queue_id:int,service:string,queue_date:string,queue_number:int}
 */
function queue_issue_number(string $service, ?int $residentId = null, ?string $referenceNo = null, string $issuedVia = 'staff', ?string $purpose = null, ?string $notificationPreference = null): array
{
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

    return db_transaction(function () use ($service, $residentId, $referenceNo, $issuedVia, $purpose, $preference, $persistPreference, $resident): array {
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
        // A resident's kiosk choice is saved for later request-status notifications too.
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
        $entry = ['queue_id' => $id, 'service' => $service, 'queue_date' => $today, 'queue_number' => $number];
        if ($residentId !== null) {
            queue_enqueue_notifications($residentId, $service, $number, 'queue_issued', 'Your queue number is ' . $number . ' for ' . $service . '. Please watch the queue display.', $preference);
        }
        return $entry;
    });
}

/** Put a queue notice in the outbox. Dispatch/delivery is a separate adapter and is not implied by enqueueing. */
function queue_enqueue_notifications(int $residentId, string $service, int $number, string $event, string $message, ?string $preferenceOverride = null): void
{
    $resident = db_one('SELECT email_address, contact_number, notification_preference FROM residents WHERE resident_id = ?', [$residentId]);
    if ($resident === null) { return; }
    $preference = queue_resolve_notification_preference($preferenceOverride, (string) ($resident['notification_preference'] ?? 'email'));
    $email = trim((string) ($resident['email_address'] ?? ''));
    $mobile = trim((string) ($resident['contact_number'] ?? ''));
    $reference = $service . '-' . date('Ymd') . '-' . $number;
    $wantsEmail = in_array($preference, ['email', 'both'], true);
    $wantsSms = in_array($preference, ['sms', 'both'], true);
    $smsEnabled = $wantsSms && $mobile !== '' && sms_provider_is_enabled();

    // Email is the default. If SMS is disabled, unconfigured, or lacks a number,
    // an SMS-only preference falls back to the resident's saved email address.
    if ($wantsSms && !$smsEnabled) { $wantsEmail = true; }
    $emailQueued = false;
    if ($wantsEmail && $email !== '') {
        $notificationId = email_queue_notification($residentId, $email, 'Barangay queue update', $message, $event, $reference);
        $emailQueued = true;
        if (email_provider_is_enabled()) { email_send_notification($notificationId); }
    }

    if ($wantsSms && $mobile !== '') {
        $enabled = sms_provider_is_enabled();
        $providerStatus = $enabled ? 'not_attempted' : 'not_configured';
        $providerName = $enabled ? sms_provider_selected_name() : null;
        db_exec(
            'INSERT INTO sms_outbox (recipient_number, message, trigger_event, request_id, delivery, provider_name, provider_status)
             VALUES (?, ?, ?, NULL, ?, ?, ?)',
            [$mobile, mb_substr_safe($message, 480), $event, $enabled ? 'Waiting to send' : 'Logged only', $providerName, $providerStatus]
        );
        $smsId = (int) db()->lastInsertId();
        if ($enabled) {
            $result = sms_send_outbox($smsId, false);
            if (in_array((string) ($result['status'] ?? ''), ['failed', 'refunded'], true) && !$emailQueued && $email !== '') {
                $notificationId = email_queue_notification($residentId, $email, 'Barangay queue update', $message, $event . '_email_fallback', $reference);
                if (email_provider_is_enabled()) { email_send_notification($notificationId); }
            }
        } elseif (!$emailQueued && $email !== '') {
            $notificationId = email_queue_notification($residentId, $email, 'Barangay queue update', $message, $event . '_email_fallback', $reference);
            if (email_provider_is_enabled()) { email_send_notification($notificationId); }
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
    return db_transaction(function () use ($service): ?array {
        $today = date('Y-m-d');
        db_exec('INSERT IGNORE INTO queue_counters (service, queue_date, last_number) VALUES (?, ?, 0)', [$service, $today]);
        db_one('SELECT last_number FROM queue_counters WHERE service = ? AND queue_date = ? FOR UPDATE', [$service, $today]);
        $current = db_one(
            'SELECT queue_id, queue_number FROM queue_entries
             WHERE service = ? AND queue_date = ? AND status = \'Called\' LIMIT 1 FOR UPDATE',
            [$service, $today]
        );
        if ($current !== null) { throw new RuntimeException('Serve, skip or cancel the current called number before calling the next one.'); }
        $next = db_one(
            'SELECT queue_id, queue_number, resident_id, reference_no, notification_preference FROM queue_entries
             WHERE service = ? AND queue_date = ? AND status = \'Waiting\'
             ORDER BY queue_number LIMIT 1 FOR UPDATE',
            [$service, $today]
        );
        if ($next === null) { return null; }
        $changed = db_exec(
            'UPDATE queue_entries SET status = \'Called\', called_at = NOW(), called_by = ?
             WHERE queue_id = ? AND status = \'Waiting\'',
            [audit_actor()['user_id'] ?? null, (int) $next['queue_id']]
        );
        if ($changed !== 1) { throw new RuntimeException('The queue entry changed before it could be called. Refresh and try again.'); }
        audit('queue_number_called', 'Queue', 'Success', $service . ' queue #' . (int) $next['queue_number'] . ' called', 'queue_entry', (int) $next['queue_id']);
        if (!empty($next['resident_id'])) {
            queue_enqueue_notifications((int) $next['resident_id'], $service, (int) $next['queue_number'], 'queue_called',
                'Your queue number ' . (int) $next['queue_number'] . ' for ' . $service . ' is now being called. Please proceed to the counter.',
                (string) ($next['notification_preference'] ?? 'email'));
        }
        $near = db_one(
            'SELECT queue_number, resident_id, notification_preference FROM queue_entries
             WHERE service = ? AND queue_date = ? AND status = \'Waiting\'
             ORDER BY queue_number LIMIT 1',
            [$service, $today]
        );
        if ($near !== null && !empty($near['resident_id'])) {
            queue_enqueue_notifications((int) $near['resident_id'], $service, (int) $near['queue_number'], 'queue_near',
                'Your queue number ' . (int) $near['queue_number'] . ' for ' . $service . ' is next after the number now being served. Please get ready.',
                (string) ($near['notification_preference'] ?? 'email'));
        }
        return $next;
    });
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
    $averageWait = db_val(
        'SELECT AVG(TIMESTAMPDIFF(SECOND, issued_at, served_at)) FROM queue_entries
         WHERE service = ? AND queue_date = ? AND status = \'Served\' AND served_at IS NOT NULL',
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
