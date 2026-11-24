<?php
/** Resident status notifications use the configured email transport only. */
declare(strict_types=1);

/**
 * Notify a resident that a document, blotter or venue request has changed status.
 * The status transaction must already be committed before this function is called.
 * A missing/unconfigured email never rolls back the service action.
 *
 * @return array{email:string}
 */
function resident_notify_status_change(
    int $residentId,
    string $service,
    string $referenceNo,
    string $status,
    string $eventType,
    string $extraMessage = ''
): array {
    if ($residentId < 1 || !in_array($service, ['Documents', 'Blotter', 'Venue'], true)
        || preg_match('/^[A-Za-z0-9._-]{1,40}$/', $referenceNo) !== 1
        || !v_len($status, 2, 40)
        || preg_match('/^[a-z0-9_]{3,60}$/', $eventType) !== 1
        || str_len($extraMessage) > 500) {
        throw new InvalidArgumentException('The resident status notification details are invalid.');
    }
    if (db()->inTransaction()) {
        throw new LogicException('Resident email notifications must be queued after the service transaction commits.');
    }

    $resident = db_one(
        'SELECT resident_id, email_address, archived_at
         FROM residents WHERE resident_id = ?',
        [$residentId]
    );
    if ($resident === null || $resident['archived_at'] !== null) {
        return ['email' => 'no_resident'];
    }

    $email = trim((string) ($resident['email_address'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        audit_best_effort(
            'resident_notification_unavailable',
            'Notifications',
            'Failed',
            $service . ' request ' . $referenceNo . ' changed to ' . $status . '; no valid resident email address is on record.'
        );
        return ['email' => 'no_email'];
    }

    $message = 'Your ' . $service . ' request ' . $referenceNo . ' status is now ' . $status . '.';
    $extraMessage = trim($extraMessage);
    if ($extraMessage !== '') { $message .= ' ' . $extraMessage; }

    $notificationId = email_queue_notification(
        $residentId,
        $email,
        'Barangay ' . $service . ' request update',
        $message,
        $eventType,
        $referenceNo
    );

    if (!email_provider_is_enabled()) {
        return ['email' => 'queued'];
    }

    try {
        $result = email_send_notification($notificationId);
        return ['email' => (string) ($result['status'] ?? 'unknown')];
    } catch (Throwable $emailError) {
        error_log('BIRMS status email failed for outbox #' . $notificationId . ': ' . get_class($emailError));
        audit_best_effort(
            'resident_notification_failed',
            'Notifications',
            'Failed',
            $service . ' request ' . $referenceNo . ' was updated; its email remains in the outbox for review.'
        );
        return ['email' => 'failed'];
    }
}
