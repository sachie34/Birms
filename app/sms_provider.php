<?php
/**
 * Optional Semaphore SMS provider.
 *
 * Outbound SMS is disabled unless the operator explicitly enables it and provides
 * an API key in private config/environment. Never put real credentials in source control.
 */
declare(strict_types=1);

const SMS_SEMAPHORE_MESSAGES_URL = 'https://api.semaphore.co/api/v4/messages';

/** Read SMS config without ever returning the API key to a page. Test overrides are CLI-only. */
function sms_provider_config(?array $testOverride = null): array
{
    if ($testOverride !== null && PHP_SAPI !== 'cli') {
        throw new LogicException('SMS provider test overrides are only available to command-line tests.');
    }
    $raw = $testOverride ?? [
        'enabled' => config('sms.semaphore_enabled', false),
        'api_key' => config('sms.semaphore_api_key', ''),
        'sendername' => config('sms.semaphore_sendername', ''),
        'connect_timeout' => config('sms.connect_timeout', 4),
        'timeout' => config('sms.timeout', 12),
    ];
    $enabledRaw = $raw['enabled'] ?? false;
    $requestedEnabled = is_bool($enabledRaw)
        ? $enabledRaw
        : filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
    $apiKey = trim((string) ($raw['api_key'] ?? ''));
    $sendername = trim((string) ($raw['sendername'] ?? ''));
    $curlAvailable = function_exists('curl_init');
    $enabled = $requestedEnabled && $apiKey !== '' && $curlAvailable;
    $reason = !$requestedEnabled ? 'Disabled in configuration'
        : ($apiKey === '' ? 'API key missing' : (!$curlAvailable ? 'PHP cURL extension unavailable' : 'Ready to send'));

    return [
        'enabled' => $enabled,
        'requested_enabled' => $requestedEnabled,
        'has_key' => $apiKey !== '',
        'api_key' => $apiKey,
        'sendername' => $sendername,
        'connect_timeout' => max(1, min(10, (int) ($raw['connect_timeout'] ?? 4))),
        'timeout' => max(3, min(20, (int) ($raw['timeout'] ?? 12))),
        'reason' => $reason,
    ];
}

/** Safe status for display; never returns the API key. */
function sms_provider_status_summary(): array
{
    $cfg = sms_provider_config();
    return [
        'provider' => 'Semaphore',
        'enabled' => $cfg['enabled'],
        'reason' => $cfg['reason'],
        'sendername_configured' => $cfg['sendername'] !== '',
    ];
}

function sms_provider_status_label(string $status): string
{
    $labels = [
        'not_configured' => 'Log only — provider disabled',
        'no_number' => 'No phone number',
        'not_attempted' => 'Waiting to send',
        'sending' => 'Sending / result not confirmed',
        'queued' => 'Queued by provider',
        'pending' => 'Pending with provider',
        'sent' => 'Sent to mobile network',
        'failed' => 'Failed',
        'refunded' => 'Refunded by provider',
        'unknown' => 'Unknown — verify before retry',
    ];
    return $labels[strtolower($status)] ?? 'Unknown status';
}

function sms_provider_status_tone(string $status): string
{
    return match (strtolower($status)) {
        'sent' => 'green',
        'queued', 'pending', 'not_attempted' => 'blue',
        'failed', 'refunded' => 'red',
        'unknown', 'sending' => 'amber',
        'no_number', 'not_configured' => 'grey',
        default => 'grey',
    };
}

/** Normalize only known provider statuses. Never treat an absent status as success. */
function sms_provider_normalize_status(string $status): ?string
{
    $s = strtolower(trim($status));
    if ($s === 'success') { $s = 'sent'; }
    return in_array($s, ['queued', 'pending', 'sent', 'failed', 'refunded'], true) ? $s : null;
}

/** Accept the object or single-item list returned by Semaphore. */
function sms_provider_response_message(string $body): ?array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { return null; }
    if (array_is_list($decoded)) {
        if (!isset($decoded[0]) || !is_array($decoded[0])) { return null; }
        $decoded = $decoded[0];
    }
    return is_array($decoded) ? $decoded : null;
}

/**
 * Do the HTTP call. A mock transport can be supplied by CLI tests; web requests
 * cannot pass a transport callback. The real transport requires verified HTTPS.
 *
 * Return keys: http_status, body, transport_error.
 */
function sms_provider_http_request(
    string $method,
    string $url,
    array $params,
    array $cfg,
    ?callable $transport = null
): array {
    if ($transport !== null) {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('Mock SMS transport is only available to command-line tests.');
        }
        $r = $transport(strtoupper($method), $url, $params);
        if (!is_array($r)) { return ['http_status' => 0, 'body' => '', 'transport_error' => 'Mock transport returned an invalid response.']; }
        return [
            'http_status' => (int) ($r['http_status'] ?? 0),
            'body' => is_string($r['body'] ?? null) ? $r['body'] : '',
            'transport_error' => is_string($r['transport_error'] ?? null) ? $r['transport_error'] : '',
        ];
    }
    if (!function_exists('curl_init')) {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'PHP cURL extension is unavailable.'];
    }

    $method = strtoupper($method);
    if ($method === 'GET') {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'Could not initialize the HTTPS request.'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => (int) $cfg['connect_timeout'],
        CURLOPT_TIMEOUT => (int) $cfg['timeout'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($params);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif ($method !== 'GET') {
        curl_close($ch);
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'Unsupported HTTP method.'];
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? 'The HTTPS request did not return a complete response.' : '';
    curl_close($ch);
    return [
        'http_status' => $code,
        'body' => is_string($body) ? $body : '',
        // Never persist raw cURL errors or provider request parameters (which may contain a key).
        'transport_error' => $error,
    ];
}

/** Save only safe, concise status/error information. Never save the raw provider response. */
function sms_provider_save_result(
    int $smsId,
    string $status,
    ?string $messageId,
    ?string $error
): void {
    db_exec(
        "UPDATE sms_outbox
         SET provider_status = ?, provider_message_id = COALESCE(?, provider_message_id), provider_error = ?
         WHERE sms_id = ? AND provider_status = 'sending'",
        [$status, $messageId !== null ? mb_substr_safe($messageId, 80) : null,
         $error !== null ? mb_substr_safe($error, 1000) : null, $smsId]
    );
    audit_best_effort(
        'sms_provider_result',
        'SMS',
        in_array($status, ['queued', 'pending', 'sent'], true) ? 'Success' : 'Failed',
        'SMS outbox #' . $smsId . ' provider result: ' . $status . ($error !== null ? ' (' . mb_substr_safe($error, 180) . ')' : '')
    );
}

/**
 * Send an outbox row once. In normal operation only not_attempted rows are sent.
 * Retrying a failed/refunded message requires an explicit caller action. Unknown
 * delivery outcomes are never retried automatically because the provider may have accepted them.
 */
function sms_send_outbox(
    int $smsId,
    bool $explicitRetry = false,
    ?callable $transport = null,
    ?array $testConfig = null
): array {
    $cfg = sms_provider_config($testConfig);
    $row = db_one('SELECT sms_id, recipient_number, message, provider_status, provider_message_id FROM sms_outbox WHERE sms_id = ?', [$smsId]);
    if ($row === null) { return ['ok' => false, 'status' => 'not_found', 'message' => 'SMS outbox record was not found.']; }

    if (trim((string) ($row['recipient_number'] ?? '')) === '') {
        if ((string) $row['provider_status'] !== 'no_number') {
            db_exec("UPDATE sms_outbox SET provider_status = 'no_number' WHERE sms_id = ? AND provider_status IN ('not_attempted','not_configured')", [$smsId]);
        }
        return ['ok' => false, 'status' => 'no_number', 'message' => 'No mobile number is saved; no SMS was sent.'];
    }
    if (!$cfg['enabled']) {
        db_exec("UPDATE sms_outbox SET provider_status = 'not_configured' WHERE sms_id = ? AND provider_status = 'not_attempted'", [$smsId]);
        return ['ok' => false, 'status' => 'not_configured', 'message' => 'Semaphore is disabled or not fully configured. The message remains logged only.'];
    }

    $allowedStatuses = $explicitRetry ? "('failed','refunded')" : "('not_attempted')";
    $claimed = db_exec(
        "UPDATE sms_outbox
         SET provider_name = 'Semaphore', provider_status = 'sending', provider_error = NULL,
             attempt_count = attempt_count + 1, last_attempt_at = NOW()
         WHERE sms_id = ? AND provider_status IN {$allowedStatuses}",
        [$smsId]
    );
    if ($claimed !== 1) {
        $latest = db_one('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$smsId]);
        return [
            'ok' => false,
            'status' => (string) ($latest['provider_status'] ?? 'not_found'),
            'message' => 'This SMS is not eligible to send or retry from its current status.',
        ];
    }

    $number = preg_replace('/[\\s-]/', '', (string) $row['recipient_number']) ?? '';
    if (str_starts_with($number, '+')) { $number = substr($number, 1); }
    $params = ['apikey' => $cfg['api_key'], 'number' => $number, 'message' => (string) $row['message']];
    if ($cfg['sendername'] !== '') { $params['sendername'] = $cfg['sendername']; }

    try {
        $http = sms_provider_http_request('POST', SMS_SEMAPHORE_MESSAGES_URL, $params, $cfg, $transport);
        $code = (int) $http['http_status'];
        if ($http['transport_error'] !== '') {
            sms_provider_save_result($smsId, 'unknown', null, 'Network/transport error; provider acceptance cannot be confirmed. Check the Semaphore dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'The SMS result could not be confirmed. Check Semaphore before retrying to avoid sending a duplicate.'];
        }
        if ($code < 200 || $code >= 300) {
            if ($code >= 400 && $code < 500) {
                $error = 'Semaphore rejected the request (HTTP ' . $code . '). Check the account, API key, recipient number and sender name.';
                sms_provider_save_result($smsId, 'failed', null, $error);
                return ['ok' => false, 'status' => 'failed', 'message' => $error];
            }
            sms_provider_save_result($smsId, 'unknown', null, 'Semaphore returned HTTP ' . $code . '; acceptance cannot be confirmed. Check the provider dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'Semaphore did not confirm acceptance. Check the provider dashboard before retrying.'];
        }

        $msg = sms_provider_response_message((string) $http['body']);
        $status = is_array($msg) ? sms_provider_normalize_status((string) ($msg['status'] ?? '')) : null;
        $messageId = isset($msg['message_id']) && (is_string($msg['message_id']) || is_int($msg['message_id']))
            ? trim((string) $msg['message_id']) : null;
        if ($status === null || $messageId === null || $messageId === '') {
            sms_provider_save_result($smsId, 'unknown', null, 'Semaphore returned an unrecognized response. Check the provider dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'The provider response was not recognized. Check Semaphore before retrying.'];
        }

        $error = in_array($status, ['failed', 'refunded'], true) ? 'Semaphore reported status ' . ucfirst($status) . '.' : null;
        sms_provider_save_result($smsId, $status, $messageId, $error);
        $ok = in_array($status, ['queued', 'pending', 'sent'], true);
        return [
            'ok' => $ok, 'status' => $status,
            'message' => $ok
                ? 'Semaphore accepted the message (status: ' . ucfirst($status) . ').'
                : 'Semaphore reported status ' . ucfirst($status) . '. Review the outbox before retrying.',
        ];
    } catch (Throwable $e) {
        // The status change to Ready and the outbox row were committed before this call.
        // Conservatively mark provider acceptance as unknown if the call cannot be completed.
        try {
            sms_provider_save_result($smsId, 'unknown', null, 'Unexpected sending error; provider acceptance cannot be confirmed. Check Semaphore before retrying.');
        } catch (Throwable $ignored) {
            error_log('BIRMS SMS status update failed for outbox #' . $smsId . '.');
        }
        error_log('BIRMS SMS provider call failed for outbox #' . $smsId . ' (' . get_class($e) . ').');
        return ['ok' => false, 'status' => 'unknown', 'message' => 'The SMS result could not be confirmed. Check Semaphore before retrying.'];
    }
}

/** Query Semaphore for a saved provider message ID; this never resends an SMS. */
function sms_refresh_outbox_status(
    int $smsId,
    ?callable $transport = null,
    ?array $testConfig = null
): array {
    $cfg = sms_provider_config($testConfig);
    $row = db_one('SELECT sms_id, provider_message_id, provider_status FROM sms_outbox WHERE sms_id = ?', [$smsId]);
    if ($row === null) { return ['ok' => false, 'status' => 'not_found', 'message' => 'SMS outbox record was not found.']; }
    if (!$cfg['enabled']) { return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => 'Semaphore is disabled or not fully configured.']; }
    $messageId = trim((string) ($row['provider_message_id'] ?? ''));
    if ($messageId === '' || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $messageId)) {
        return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => 'This record has no usable provider message ID, so its status cannot be refreshed.'];
    }

    try {
        $http = sms_provider_http_request(
            'GET',
            SMS_SEMAPHORE_MESSAGES_URL . '/' . rawurlencode($messageId),
            ['apikey' => $cfg['api_key']],
            $cfg,
            $transport
        );
        $code = (int) $http['http_status'];
        $msg = $http['transport_error'] === '' && $code >= 200 && $code < 300
            ? sms_provider_response_message((string) $http['body']) : null;
        $status = is_array($msg) ? sms_provider_normalize_status((string) ($msg['status'] ?? '')) : null;
        if ($status === null) {
            $error = $http['transport_error'] !== ''
                ? 'Status refresh failed due to a network error; the last saved provider status was retained.'
                : ($code < 200 || $code >= 300
                    ? 'Status refresh failed (HTTP ' . $code . '); the last saved provider status was retained.'
                    : 'Semaphore returned an unrecognized status; the last saved provider status was retained.');
            db_exec('UPDATE sms_outbox SET provider_error = ? WHERE sms_id = ?', [$error, $smsId]);
            return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => $error];
        }
        $error = in_array($status, ['failed', 'refunded'], true) ? 'Semaphore reported status ' . ucfirst($status) . '.' : null;
        db_exec('UPDATE sms_outbox SET provider_status = ?, provider_error = ? WHERE sms_id = ?', [$status, $error, $smsId]);
        audit_best_effort('sms_provider_status_refreshed', 'SMS', 'Success', 'SMS outbox #' . $smsId . ' provider status refreshed: ' . $status);
        return ['ok' => true, 'status' => $status, 'message' => 'Provider status refreshed: ' . ucfirst($status) . '.'];
    } catch (Throwable $e) {
        error_log('BIRMS SMS status refresh failed for outbox #' . $smsId . ' (' . get_class($e) . ').');
        return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => 'Could not refresh the provider status. The last saved status was retained.'];
    }
}
