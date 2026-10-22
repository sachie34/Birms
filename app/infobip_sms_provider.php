<?php
/**
 * Optional Infobip SMS adapter for BIRMS.
 * The API key is sent only in the HTTPS Authorization header and is never stored in logs/database.
 */
declare(strict_types=1);

function infobip_sms_config(?array $testOverride = null): array
{
    if ($testOverride !== null && PHP_SAPI !== 'cli') {
        throw new LogicException('SMS provider test overrides are only available to command-line tests.');
    }
    $raw = $testOverride ?? [
        'enabled' => config('sms.infobip_enabled', false),
        'api_key' => config('sms.infobip_api_key', ''),
        'base_url' => config('sms.infobip_base_url', ''),
        'sender' => config('sms.infobip_sender', 'ServiceSMS'),
        'connect_timeout' => config('sms.connect_timeout', 4),
        'timeout' => config('sms.timeout', 12),
    ];
    $enabledRaw = $raw['enabled'] ?? false;
    $requestedEnabled = is_bool($enabledRaw) ? $enabledRaw : filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
    $apiKey = trim((string) ($raw['api_key'] ?? ''));
    $baseUrl = rtrim(trim((string) ($raw['base_url'] ?? '')), '/');
    $sender = trim((string) ($raw['sender'] ?? 'ServiceSMS'));
    $validBase = preg_match('~^https://(?:[a-z0-9-]+\.)*api\.infobip\.com$~i', $baseUrl) === 1;
    $curlAvailable = function_exists('curl_init');
    $enabled = $requestedEnabled && $apiKey !== '' && $validBase && $curlAvailable;
    $reason = !$requestedEnabled ? 'Disabled in configuration'
        : ($apiKey === '' ? 'API key missing'
            : (!$validBase ? 'Infobip Base URL is missing or invalid; use the exact HTTPS Base URL shown in the Infobip account.'
                : (!$curlAvailable ? 'PHP cURL extension unavailable' : 'Ready to send')));
    return [
        'enabled' => $enabled,
        'requested_enabled' => $requestedEnabled,
        'has_key' => $apiKey !== '',
        'api_key' => $apiKey,
        'base_url' => $validBase ? $baseUrl : '',
        'sender' => $sender !== '' ? $sender : 'ServiceSMS',
        'connect_timeout' => max(1, min(10, (int) ($raw['connect_timeout'] ?? 4))),
        'timeout' => max(3, min(20, (int) ($raw['timeout'] ?? 12))),
        'reason' => $reason,
    ];
}

/** Normalize common Philippine mobile formats to the international digits-only form expected by Infobip. */
function infobip_sms_normalize_number(string $raw): ?string
{
    $number = preg_replace('/[\s().-]/', '', trim($raw)) ?? '';
    if (str_starts_with($number, '+')) { $number = substr($number, 1); }
    if (preg_match('/^09\d{9}$/', $number) === 1) { return '63' . substr($number, 1); }
    if (preg_match('/^9\d{9}$/', $number) === 1) { return '63' . $number; }
    if (preg_match('/^639\d{9}$/', $number) === 1) { return $number; }
    if (preg_match('/^0\d{9,10}$/', $number) === 1) { return '63' . substr($number, 1); }
    if (preg_match('/^63\d{9,10}$/', $number) === 1) { return $number; }
    return null;
}

/** Provider statuses differ; map only recognized Infobip responses into the shared BIRMS state set. */
function infobip_sms_normalize_status(array $message): ?string
{
    $status = strtoupper(trim((string) ($message['status']['name'] ?? '')));
    $group = strtoupper(trim((string) ($message['status']['groupName'] ?? '')));
    $candidate = $status . ' ' . $group;
    if ($status === '' && $group === '') { return null; }
    if (str_contains($candidate, 'DELIVERED') || preg_match('/\bSENT\b/', $candidate) === 1) { return 'sent'; }
    if (str_contains($candidate, 'PENDING') || str_contains($candidate, 'ACCEPTED') || str_contains($candidate, 'QUEUED')) { return 'queued'; }
    if (str_contains($candidate, 'REFUNDED')) { return 'refunded'; }
    if (str_contains($candidate, 'FAILED') || str_contains($candidate, 'REJECTED') || str_contains($candidate, 'UNDELIVERABLE') || str_contains($candidate, 'EXPIRED') || str_contains($candidate, 'INVALID')) { return 'failed'; }
    return null;
}

/**
 * HTTPS JSON transport. Test transport is CLI-only, never used by web traffic.
 * Returned diagnostics never include request headers, body, or API key.
 */
function infobip_sms_http_request(
    string $method,
    string $url,
    array $params,
    array $cfg,
    ?callable $transport = null
): array {
    $method = strtoupper($method);
    if ($transport !== null) {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('Mock SMS transport is only available to command-line tests.');
        }
        $r = $transport($method, $url, $params);
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
    if (!str_starts_with($url, 'https://')) {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'Only HTTPS provider requests are allowed.'];
    }
    if ($method === 'GET') {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    } elseif ($method !== 'POST') {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'Unsupported HTTP method.'];
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
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Authorization: App ' . $cfg['api_key'],
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ];
    if ($method === 'POST') {
        $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? 'The HTTPS request did not return a complete response.' : '';
    curl_close($ch);
    return [
        'http_status' => $code,
        'body' => is_string($body) ? $body : '',
        'transport_error' => $error,
    ];
}

function infobip_sms_response_message(string $body): ?array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { return null; }
    if (isset($decoded['messages']) && is_array($decoded['messages']) && isset($decoded['messages'][0]) && is_array($decoded['messages'][0])) {
        return $decoded['messages'][0];
    }
    if (isset($decoded['results']) && is_array($decoded['results']) && isset($decoded['results'][0]) && is_array($decoded['results'][0])) {
        return $decoded['results'][0];
    }
    return null;
}

function sms_send_outbox_infobip(
    int $smsId,
    bool $explicitRetry = false,
    ?callable $transport = null,
    ?array $testConfig = null
): array {
    $cfg = infobip_sms_config($testConfig);
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
        return ['ok' => false, 'status' => 'not_configured', 'message' => 'Infobip is disabled or not fully configured. The message remains logged only.'];
    }

    $allowedStatuses = $explicitRetry ? "('failed','refunded')" : "('not_attempted')";
    $claimed = db_exec(
        "UPDATE sms_outbox
         SET provider_name = 'Infobip', provider_status = 'sending', provider_error = NULL,
             provider_message_id = NULL, attempt_count = attempt_count + 1, last_attempt_at = NOW()
         WHERE sms_id = ? AND provider_status IN {$allowedStatuses}",
        [$smsId]
    );
    if ($claimed !== 1) {
        $latest = db_one('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$smsId]);
        return ['ok' => false, 'status' => (string) ($latest['provider_status'] ?? 'not_found'), 'message' => 'This SMS is not eligible to send or retry from its current status.'];
    }

    $number = infobip_sms_normalize_number((string) $row['recipient_number']);
    if ($number === null) {
        sms_provider_save_result($smsId, 'failed', null, 'Recipient number is not in a supported Philippine number format. Use 09XXXXXXXXX or +639XXXXXXXXX.');
        return ['ok' => false, 'status' => 'failed', 'message' => 'Recipient number must be a valid Philippine number, such as 09XXXXXXXXX or +639XXXXXXXXX.'];
    }
    $payload = [
        'messages' => [[
            'sender' => $cfg['sender'],
            'destinations' => [['to' => $number]],
            'content' => ['text' => (string) $row['message']],
        ]],
    ];

    try {
        $http = infobip_sms_http_request('POST', $cfg['base_url'] . '/sms/3/messages', $payload, $cfg, $transport);
        $code = (int) $http['http_status'];
        if ($http['transport_error'] !== '') {
            sms_provider_save_result($smsId, 'unknown', null, 'Network/transport error; provider acceptance cannot be confirmed. Check the Infobip dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'The SMS result could not be confirmed. Check Infobip before retrying to avoid a duplicate.'];
        }
        if ($code < 200 || $code >= 300) {
            if ($code >= 400 && $code < 500) {
                $error = 'Infobip rejected the request (HTTP ' . $code . '). Check API scope, account/trial limits, recipient number and sender.';
                sms_provider_save_result($smsId, 'failed', null, $error);
                return ['ok' => false, 'status' => 'failed', 'message' => $error];
            }
            sms_provider_save_result($smsId, 'unknown', null, 'Infobip returned HTTP ' . $code . '; acceptance cannot be confirmed. Check its dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'Infobip did not confirm acceptance. Check the provider dashboard before retrying.'];
        }

        $message = infobip_sms_response_message((string) $http['body']);
        $status = is_array($message) ? infobip_sms_normalize_status($message) : null;
        $messageId = is_array($message) && isset($message['messageId']) && is_string($message['messageId'])
            ? trim($message['messageId']) : '';
        if ($status === null || $messageId === '' || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $messageId)) {
            sms_provider_save_result($smsId, 'unknown', null, 'Infobip returned an unrecognized response. Check the provider dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'The provider response was not recognized. Check Infobip before retrying.'];
        }
        $error = in_array($status, ['failed', 'refunded'], true) ? 'Infobip reported status ' . ucfirst($status) . '.' : null;
        sms_provider_save_result($smsId, $status, $messageId, $error);
        $ok = in_array($status, ['queued', 'pending', 'sent'], true);
        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $ok
                ? 'Infobip accepted the message (status: ' . ucfirst($status) . ').'
                : 'Infobip reported status ' . ucfirst($status) . '. Review the outbox before retrying.',
        ];
    } catch (Throwable $e) {
        try {
            sms_provider_save_result($smsId, 'unknown', null, 'Unexpected sending error; provider acceptance cannot be confirmed. Check Infobip before retrying.');
        } catch (Throwable $ignored) {
            error_log('BIRMS SMS status update failed for outbox #' . $smsId . '.');
        }
        error_log('BIRMS Infobip SMS call failed for outbox #' . $smsId . ' (' . get_class($e) . ').');
        return ['ok' => false, 'status' => 'unknown', 'message' => 'The SMS result could not be confirmed. Check Infobip before retrying.'];
    }
}

/** Infobip delivery reports are pull-based; the API may return no report until delivery processing advances. */
function sms_refresh_outbox_status_infobip(
    int $smsId,
    ?callable $transport = null,
    ?array $testConfig = null
): array {
    $cfg = infobip_sms_config($testConfig);
    $row = db_one('SELECT sms_id, provider_message_id, provider_status FROM sms_outbox WHERE sms_id = ?', [$smsId]);
    if ($row === null) { return ['ok' => false, 'status' => 'not_found', 'message' => 'SMS outbox record was not found.']; }
    if (!$cfg['enabled']) { return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => 'Infobip is disabled or not fully configured.']; }
    $messageId = trim((string) ($row['provider_message_id'] ?? ''));
    if ($messageId === '' || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $messageId)) {
        return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => 'This record has no usable provider message ID, so its status cannot be refreshed.'];
    }

    try {
        $http = infobip_sms_http_request(
            'GET',
            $cfg['base_url'] . '/sms/3/reports',
            ['messageId' => $messageId],
            $cfg,
            $transport
        );
        $code = (int) $http['http_status'];
        $message = $http['transport_error'] === '' && $code >= 200 && $code < 300
            ? infobip_sms_response_message((string) $http['body']) : null;
        $status = is_array($message) ? infobip_sms_normalize_status($message) : null;
        if ($status === null) {
            $error = $http['transport_error'] !== ''
                ? 'Status refresh failed due to a network error; the last saved provider status was retained.'
                : ($code < 200 || $code >= 300
                    ? 'Status refresh failed (HTTP ' . $code . '); the last saved provider status was retained.'
                    : 'Infobip did not return a delivery report yet; the last saved status was retained.');
            db_exec('UPDATE sms_outbox SET provider_error = ? WHERE sms_id = ?', [$error, $smsId]);
            return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => $error];
        }
        $error = in_array($status, ['failed', 'refunded'], true) ? 'Infobip reported status ' . ucfirst($status) . '.' : null;
        db_exec('UPDATE sms_outbox SET provider_status = ?, provider_error = ? WHERE sms_id = ?', [$status, $error, $smsId]);
        audit_best_effort('sms_provider_status_refreshed', 'SMS', 'Success', 'SMS outbox #' . $smsId . ' Infobip status refreshed: ' . $status);
        return ['ok' => true, 'status' => $status, 'message' => 'Infobip delivery status refreshed: ' . ucfirst($status) . '.'];
    } catch (Throwable $e) {
        error_log('BIRMS Infobip status refresh failed for outbox #' . $smsId . ' (' . get_class($e) . ').');
        return ['ok' => false, 'status' => (string) $row['provider_status'], 'message' => 'Could not refresh Infobip status. The last saved status was retained.'];
    }
}
