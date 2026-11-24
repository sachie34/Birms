<?php
/** Email-only resident status notifications. Workflow commits are never reversed by mail failures. */
declare(strict_types=1);

/** Queue and send a status notice after the related workflow transaction commits. */
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
    $resident = db_one(
        'SELECT resident_id, email_address, archived_at FROM residents WHERE resident_id = ?',
        [$residentId]
    );
    if ($resident === null || $resident['archived_at'] !== null) {
        return ['email' => 'no_resident', 'sms' => 'removed'];
    }
    $email = trim((string) ($resident['email_address'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        audit_best_effort('resident_notification_unavailable', 'Notifications', 'Failed',
            $service . ' request ' . $referenceNo . ' changed to ' . $status . '; no valid resident email is recorded.');
        return ['email' => 'no_email', 'sms' => 'removed'];
    }
    $message = 'Your ' . $service . ' request ' . $referenceNo . ' status is now ' . $status . '.';
    $extraMessage = trim($extraMessage);
    if ($extraMessage !== '') { $message .= ' ' . $extraMessage; }
    $emailId = email_queue_notification(
        $residentId, $email, 'Barangay ' . $service . ' request update',
        $message, $eventType, $referenceNo
    );
    if (!email_provider_is_enabled()) {
        return ['email' => 'queued', 'sms' => 'removed'];
    }
    try {
        $result = email_send_notification($emailId);
        return ['email' => (string) ($result['status'] ?? 'unknown'), 'sms' => 'removed'];
    } catch (Throwable $emailError) {
        error_log('BIRMS resident status email failed for ' . $referenceNo . ': ' . get_class($emailError));
        audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
            $service . ' request ' . $referenceNo . ' changed status but its email could not be sent immediately.');
        return ['email' => 'queued', 'sms' => 'removed'];
    }
}
