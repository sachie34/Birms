<?php
/** Outbound email notifications via the PHP mail transport configured on the server. */
declare(strict_types=1);

function email_provider_config(): array
{
    $configuredEnabled = config('email.enabled', false);
    $defaultEnabled = is_bool($configuredEnabled) ? $configuredEnabled : filter_var($configuredEnabled, FILTER_VALIDATE_BOOLEAN);
    $enabledRaw = setting('email_enabled', $defaultEnabled ? '1' : '0');
    $requested = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
    $from = trim((string) setting('email_from_email', (string) config('email.from_email', '')));
    $name = trim((string) setting('email_from_name', (string) config('email.from_name', 'Barangay Office')));
    $available = function_exists('mail') && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;
    return [
        'enabled' => $requested && $available,
        'requested_enabled' => $requested,
        'from_email' => $from,
        'from_name' => preg_replace('/[\r\n]+/', ' ', $name) ?: 'Barangay Office',
        'reason' => !$requested ? 'Disabled in configuration'
            : ($from === '' ? 'Sender email is not configured'
                : (!$available ? 'PHP mail transport or sender address is unavailable' : 'Ready to send')),
    ];
}

function email_provider_is_enabled(): bool
{
    return (bool) email_provider_config()['enabled'];
}

/** Create an email outbox row. No delivery claim is made until the transport accepts it. */
function email_queue_notification(
    ?int $residentId,
    string $recipient,
    string $subject,
    string $message,
    string $eventType,
    ?string $referenceNo = null
): int {
    $recipient = trim($recipient);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid recipient email address is required.');
    }
    if (str_len($subject) < 1 || str_len($subject) > 200 || str_len($message) < 1 || str_len($message) > 5000) {
        throw new InvalidArgumentException('The email subject or message is outside the allowed length.');
    }
    db_exec(
        'INSERT INTO notification_outbox (resident_id, channel, recipient, subject, message, event_type, reference_no, delivery_status)
         VALUES (?, \'email\', ?, ?, ?, ?, ?, \'queued\')',
        [$residentId, $recipient, $subject, $message, $eventType, $referenceNo]
    );
    return (int) db()->lastInsertId();
}

/**
 * Send one queued email. The optional mock transport is only available to CLI tests.
 * The operation is claimed atomically so two workers cannot send the same row.
 */
function email_send_notification(int $notificationId, ?callable $transport = null, ?array $testConfig = null): array
{
    if ($transport !== null && PHP_SAPI !== 'cli') {
        throw new LogicException('Mock email transport is only available to command-line tests.');
    }
    $cfg = $testConfig ?? email_provider_config();
    if (!($cfg['enabled'] ?? false)) {
        return ['ok' => false, 'status' => 'not_configured', 'message' => 'Email is disabled or the server mail transport is not configured.'];
    }
    $row = db_one(
        'SELECT notification_id, recipient, subject, message, delivery_status, attempt_count
         FROM notification_outbox WHERE notification_id = ?',
        [$notificationId]
    );
    if ($row === null) {
        return ['ok' => false, 'status' => 'not_found', 'message' => 'Email notification was not found.'];
    }
    if ((string) $row['delivery_status'] !== 'queued' || (int) $row['attempt_count'] >= 3) {
        return ['ok' => false, 'status' => (string) $row['delivery_status'], 'message' => 'This email is not eligible for sending or automatic retry.'];
    }
    $claimed = db_exec(
        "UPDATE notification_outbox
         SET delivery_status = 'sending', attempt_count = attempt_count + 1, last_attempt_at = NOW(), last_error = NULL
         WHERE notification_id = ? AND delivery_status = 'queued' AND attempt_count < 3",
        [$notificationId]
    );
    if ($claimed !== 1) {
        return ['ok' => false, 'status' => 'sending', 'message' => 'Another worker is already processing this email.'];
    }

    $from = trim((string) ($cfg['from_email'] ?? ''));
    $fromName = preg_replace('/[\r\n]+/', ' ', (string) ($cfg['from_name'] ?? 'Barangay Office')) ?: 'Barangay Office';
    $subject = trim((string) $row['subject']);
    $encodeHeader = static fn(string $value): string => function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($value, 'UTF-8', 'B') : $value;
    $headers = [
        'From: ' . $encodeHeader($fromName) . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Content-Type-Options: nosniff',
    ];
    try {
        $ok = $transport !== null
            ? (bool) $transport((string) $row['recipient'], $subject, (string) $row['message'], $headers)
            : mail((string) $row['recipient'], $encodeHeader($subject), (string) $row['message'], implode("\r\n", $headers));
        if ($ok) {
            db_exec("UPDATE notification_outbox SET delivery_status = 'sent', sent_at = NOW(), last_error = NULL WHERE notification_id = ? AND delivery_status = 'sending'", [$notificationId]);
            audit_best_effort('email_notification_sent', 'Notifications', 'Success', 'Email outbox #' . $notificationId . ' accepted by the configured mail transport.');
            return ['ok' => true, 'status' => 'sent', 'message' => 'Email accepted by the configured mail transport.'];
        }
        db_exec("UPDATE notification_outbox SET delivery_status = 'failed', last_error = 'The configured mail transport did not accept the message.' WHERE notification_id = ? AND delivery_status = 'sending'", [$notificationId]);
        audit_best_effort('email_notification_failed', 'Notifications', 'Failed', 'Email outbox #' . $notificationId . ' was not accepted by the mail transport.');
        return ['ok' => false, 'status' => 'failed', 'message' => 'The mail transport did not accept the email.'];
    } catch (Throwable $e) {
        db_exec("UPDATE notification_outbox SET delivery_status = 'failed', last_error = 'The configured mail transport raised an error.' WHERE notification_id = ? AND delivery_status = 'sending'", [$notificationId]);
        error_log('BIRMS email send failed for outbox #' . $notificationId . ' (' . get_class($e) . ').');
        return ['ok' => false, 'status' => 'failed', 'message' => 'The mail transport encountered an error.'];
    }
}
