<?php
/** Resident status notifications. Queueing and workflow notices share the saved contact preference. */
declare(strict_types=1);

/**
 * SRS FR-Q10: fall back to email when SMS is unavailable, rejected, failed or cannot be confirmed.
 * "unknown" is deliberately not retried as SMS; an email fallback prevents silent loss while
 * preserving the provider's uncertainty and avoiding a duplicate SMS attempt.
 */
function resident_sms_needs_email_fallback(string $status): bool
{
    return in_array(strtolower(trim($status)), [
        'failed', 'refunded', 'not_configured', 'no_number', 'unknown',
    ], true);
}

/**
 * Queue a single email fallback for an SMS outcome that cannot notify the resident.
 * The queued email is durable even when the mail transport is not configured.
 *
 * @return array{notification_id:int,status:string}
 */
function resident_queue_email_fallback(
    int $residentId,
    string $email,
    string $service,
    string $message,
    string $eventType,
    string $referenceNo,
    string $smsStatus
): array {
    if ($residentId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !in_array($service, ['Blotter', 'Documents', 'Venue'], true)
        || !resident_sms_needs_email_fallback($smsStatus)) {
        throw new InvalidArgumentException('The SMS email-fallback details are invalid.');
    }
    $notificationId = email_queue_notification(
        $residentId,
        $email,
        'Barangay ' . $service . ' update',
        $message,
        $eventType . '_email_fallback',
        $referenceNo
    );
    $status = 'queued';
    if (email_provider_is_enabled()) {
        try {
            $result = email_send_notification($notificationId);
            $status = (string) ($result['status'] ?? 'unknown');
        } catch (Throwable $emailError) {
            error_log('BIRMS SMS fallback email failed for outbox #' . $notificationId . ': ' . get_class($emailError));
            audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                $service . ' update ' . $referenceNo . ' has a queued email fallback that could not be sent immediately.');
            $status = 'failed';
        }
    }
    audit_best_effort(
        'resident_sms_email_fallback_queued',
        'Notifications',
        'Success',
        $service . ' update ' . $referenceNo . ' queued email fallback after SMS status ' . strtolower($smsStatus) . '.'
    );
    return ['notification_id' => $notificationId, 'status' => $status];
}

/**
 * Notify a verified resident that a document, blotter or venue request has changed status.
 * Delivery attempts happen after the related status transaction commits; provider failures never
 * reverse an approval, rejection, release or case update.
 *
 * @return array{email:string,sms:string}
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

    $resident = db_one(
        'SELECT resident_id, email_address, contact_number, notification_preference, archived_at
         FROM residents WHERE resident_id = ?',
        [$residentId]
    );
    if ($resident === null || $resident['archived_at'] !== null) {
        return ['email' => 'no_resident', 'sms' => 'no_resident'];
    }

    $email = trim((string) ($resident['email_address'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }
    $mobile = preg_replace('/[\s()-]/', '', trim((string) ($resident['contact_number'] ?? ''))) ?? '';
    if ($mobile !== '' && !v_phone($mobile)) { $mobile = ''; }
    $preference = (string) ($resident['notification_preference'] ?? 'email');
    if (!in_array($preference, ['email', 'sms', 'both'], true)) { $preference = 'email'; }

    $wantsEmail = in_array($preference, ['email', 'both'], true);
    $wantsSms = in_array($preference, ['sms', 'both'], true);
    $smsEnabled = $wantsSms && $mobile !== '' && sms_provider_is_enabled();

    // SRS fallback: when SMS is selected but unavailable, use email if the resident has one.
    if ($wantsSms && !$smsEnabled) { $wantsEmail = true; }

    $message = 'Your ' . $service . ' request ' . $referenceNo . ' status is now ' . $status . '.';
    $extraMessage = trim($extraMessage);
    if ($extraMessage !== '') { $message .= ' ' . $extraMessage; }

    $emailStatus = 'not_requested';
    $emailQueued = false;
    if ($wantsEmail && $email !== '') {
        $emailId = email_queue_notification(
            $residentId,
            $email,
            'Barangay ' . $service . ' request update',
            $message,
            $eventType,
            $referenceNo
        );
        $emailQueued = true;
        $emailResult = email_provider_is_enabled()
            ? email_send_notification($emailId)
            : ['ok' => false, 'status' => 'queued'];
        $emailStatus = (string) ($emailResult['status'] ?? 'unknown');
    } elseif ($wantsEmail) {
        $emailStatus = 'no_email';
    }

    $smsStatus = 'not_requested';
    if ($wantsSms && $mobile !== '') {
        $enabled = sms_provider_is_enabled();
        db_exec(
            'INSERT INTO sms_outbox
             (recipient_number, message, trigger_event, request_id, delivery, provider_name, provider_status)
             VALUES (?, ?, ?, NULL, ?, ?, ?)',
            [
                $mobile,
                mb_substr_safe($message, 480),
                $eventType,
                $enabled ? 'Waiting to send' : 'Logged only',
                $enabled ? sms_provider_selected_name() : null,
                $enabled ? 'not_attempted' : 'not_configured',
            ]
        );
        $smsId = (int) db()->lastInsertId();
        if ($enabled) {
            try {
                $smsResult = sms_send_outbox($smsId, false);
                $smsStatus = (string) ($smsResult['status'] ?? 'unknown');
            } catch (Throwable $smsError) {
                // Do not retry a call with an uncertain provider result. Queue email fallback instead.
                $smsStatus = 'unknown';
                error_log('BIRMS SMS status notification failed for outbox #' . $smsId . ': ' . get_class($smsError));
                audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                    $service . ' request ' . $referenceNo . ' SMS result could not be confirmed.');
            }
        } else {
            $smsStatus = 'not_configured';
        }

        if (!$emailQueued && $email !== '' && resident_sms_needs_email_fallback($smsStatus)) {
            $fallback = resident_queue_email_fallback(
                $residentId, $email, $service, $message, $eventType,
                $referenceNo, $smsStatus
            );
            $emailQueued = true;
            $emailStatus = $fallback['status'];
        }
    } elseif ($wantsSms) {
        $smsStatus = 'no_number';
    }

    if ($wantsSms && !$emailQueued && $email === '' && (!$smsEnabled || in_array($smsStatus, ['failed', 'refunded'], true))) {
        audit_best_effort(
            'resident_notification_unavailable',
            'Notifications',
            'Failed',
            $service . ' request ' . $referenceNo . ' changed to ' . $status . '; no configured resident contact channel was available.'
        );
    }

    return ['email' => $emailStatus, 'sms' => $smsStatus];
}
