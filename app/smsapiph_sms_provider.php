<?php
/**
 * Optional SMS API PH adapter for BIRMS.
 *
 * Uses the vendor-supplied single send endpoint. The vendor handles its own SMS,
 * Email and Push fallback. BIRMS treats an unrecognized response as Unknown and
 * never automatically resends it. API keys stay in ignored local config/env only.
 */
declare(strict_types=1);

const SMSAPIPH_SEND_URL = 'https://smsapiph.onrender.com/api/v1/send/sms';

/** Read private SMS API PH settings without returning the key to any page. */
function smsapiph_sms_config(?array $testOverride = null): array
{
    if ($testOverride !== null && PHP_SAPI !== 'cli') {
        throw new LogicException('SMS API PH test overrides are only available to command-line tests.');
    }
    $raw = $testOverride ?? [
        'enabled' => config('sms.smsapiph_enabled', false),
        'api_key' => config('sms.smsapiph_api_key', ''),
        'connect_timeout' => config('sms.connect_timeout', 4),
        'timeout' => config('sms.timeout', 12),
    ];
    $enabledRaw = $raw['enabled'] ?? false;
    $requestedEnabled = is_bool($enabledRaw)
        ? $enabledRaw
        : filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
    $apiKey = trim((string) ($raw['api_key'] ?? ''));
    $curlAvailable = function_exists('curl_init');
    $enabled = $requestedEnabled && $apiKey !== '' && $curlAvailable;
    $reason = !$requestedEnabled ? 'Disabled in configuration'
        : ($apiKey === '' ? 'API key missing'
            : (!$curlAvailable ? 'PHP cURL extension unavailable' : 'Ready to send'));

    return [
        'enabled' => $enabled,
        'requested_enabled' => $requestedEnabled,
        'has_key' => $apiKey !== '',
        'api_key' => $apiKey,
        'connect_timeout' => max(1, min(10, (int) ($raw['connect_timeout'] ?? 4))),
        'timeout' => max(3, min(20, (int) ($raw['timeout'] ?? 12))),
        'reason' => $reason,
    ];
}

/** Normalize common Philippine mobile-number formats to +639XXXXXXXXX. */
function smsapiph_normalize_number(string $raw): ?string
{
    $number = preg_replace('/[\\s().-]/', '', trim($raw)) ?? '';
    if (str_starts_with($number, '+')) {
        $number = substr($number, 1);
    }
    if (preg_match('/^09\\d{9}$/', $number) === 1) {
        return '+63' . substr($number, 1);
    }
    if (preg_match('/^9\\d{9}$/', $number) === 1) {
        return '+63' . $number;
    }
    if (preg_match('/^639\\d{9}$/', $number) === 1) {
        return '+' . $number;
    }
    return null;
}

/**
 * Send exactly one JSON POST to the fixed HTTPS vendor endpoint.
 * The mock transport is CLI-only. Request credentials are never returned or logged.
 *
 * Transport result: http_status, body, transport_error.
 */
function smsapiph_http_request(
    string $method,
    string $url,
    array $payload,
    array $cfg,
    ?callable $transport = null
): array {
    if (strtoupper($method) !== 'POST' || $url !== SMSAPIPH_SEND_URL) {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'Only the configured SMS API PH HTTPS POST endpoint is allowed.'];
    }
    $headers = [
        'x-api-key' => (string) $cfg['api_key'],
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    if ($transport !== null) {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('Mock SMS transport is only available to command-line tests.');
        }
        $r = $transport('POST', $url, ['headers' => $headers, 'payload' => $payload]);
        if (!is_array($r)) {
            return ['http_status' => 0, 'body' => '', 'transport_error' => 'Mock transport returned an invalid response.'];
        }
        return [
            'http_status' => (int) ($r['http_status'] ?? 0),
            'body' => is_string($r['body'] ?? null) ? $r['body'] : '',
            'transport_error' => is_string($r['transport_error'] ?? null) ? $r['transport_error'] : '',
        ];
    }
    if (!function_exists('curl_init')) {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'PHP cURL extension is unavailable.'];
    }

    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'The JSON request body could not be encoded.'];
    }

    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $ch = curl_init(SMSAPIPH_SEND_URL);
    if ($ch === false) {
        return ['http_status' => 0, 'body' => '', 'transport_error' => 'Could not initialize the HTTPS request.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => (int) $cfg['connect_timeout'],
        CURLOPT_TIMEOUT => (int) $cfg['timeout'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
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

/**
 * Interpret only an explicit success/status field. The supplied quick-start has
 * no response example, so missing/unrecognized response fields must remain Unknown.
 */
function smsapiph_parse_response(string $body): array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['status' => null, 'message_id' => null];
    }
    $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $nested = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    $statusText = strtolower(trim((string) (
        $data['status'] ?? $data['state'] ?? $decoded['status'] ?? $decoded['state']
        ?? $nested['status'] ?? $nested['state'] ?? ''
    )));
    $status = match ($statusText) {
        'queued', 'pending', 'accepted', 'success', 'ok', 'submitted' => 'queued',
        'sent', 'delivered' => 'sent',
        'failed', 'error', 'rejected', 'undeliverable' => 'failed',
        'refunded' => 'refunded',
        default => null,
    };

    if ($status === null && array_key_exists('success', $decoded) && is_bool($decoded['success'])) {
        $status = $decoded['success'] ? 'queued' : 'failed';
    }
    if ($status === null && array_key_exists('success', $data) && is_bool($data['success'])) {
        $status = $data['success'] ? 'queued' : 'failed';
    }

    $id = $data['message_id'] ?? $data['messageId'] ?? $data['batch_id'] ?? $data['batchId'] ?? $data['id']
        ?? $decoded['message_id'] ?? $decoded['messageId'] ?? $decoded['batch_id'] ?? $decoded['id']
        ?? $nested['message_id'] ?? $nested['messageId'] ?? $nested['batch_id'] ?? $nested['id'] ?? null;
    $messageId = null;
    if ((is_string($id) || is_int($id)) && trim((string) $id) !== '') {
        $candidate = trim((string) $id);
        if (strlen($candidate) <= 80 && preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) === 1) {
            $messageId = $candidate;
        }
    }
    return ['status' => $status, 'message_id' => $messageId];
}

/** Submit one outbox record. Unknown outcomes are never automatically retried. */
function sms_send_outbox_smsapiph(
    int $smsId,
    bool $explicitRetry = false,
    ?callable $transport = null,
    ?array $testConfig = null
): array {
    $cfg = smsapiph_sms_config($testConfig);
    $row = db_one('SELECT sms_id, recipient_number, message, provider_status FROM sms_outbox WHERE sms_id = ?', [$smsId]);
    if ($row === null) {
        return ['ok' => false, 'status' => 'not_found', 'message' => 'SMS outbox record was not found.'];
    }

    if (trim((string) ($row['recipient_number'] ?? '')) === '') {
        if ((string) $row['provider_status'] !== 'no_number') {
            db_exec("UPDATE sms_outbox SET provider_status = 'no_number' WHERE sms_id = ? AND provider_status IN ('not_attempted','not_configured')", [$smsId]);
        }
        return ['ok' => false, 'status' => 'no_number', 'message' => 'No mobile number is saved; no message was sent.'];
    }
    if (!$cfg['enabled']) {
        db_exec("UPDATE sms_outbox SET provider_status = 'not_configured' WHERE sms_id = ? AND provider_status = 'not_attempted'", [$smsId]);
        return ['ok' => false, 'status' => 'not_configured', 'message' => 'SMS API PH is disabled or not fully configured. The message remains logged only.'];
    }

    $recipient = smsapiph_normalize_number((string) $row['recipient_number']);
    if ($recipient === null) {
        db_exec(
            "UPDATE sms_outbox SET provider_status = 'failed', provider_name = 'SMS API PH',
             provider_error = 'Recipient is not a valid Philippine mobile number.' WHERE sms_id = ? AND provider_status = 'not_attempted'",
            [$smsId]
        );
        return ['ok' => false, 'status' => 'failed', 'message' => 'Recipient must be a valid Philippine mobile number, such as 09XXXXXXXXX or +639XXXXXXXXX.'];
    }

    $allowedStatuses = $explicitRetry ? "('failed','refunded')" : "('not_attempted')";
    $claimed = db_exec(
        "UPDATE sms_outbox
         SET provider_name = 'SMS API PH', provider_status = 'sending', provider_error = NULL,
             provider_message_id = NULL, attempt_count = attempt_count + 1, last_attempt_at = NOW()
         WHERE sms_id = ? AND provider_status IN {$allowedStatuses}",
        [$smsId]
    );
    if ($claimed !== 1) {
        $latest = db_one('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$smsId]);
        return [
            'ok' => false,
            'status' => (string) ($latest['provider_status'] ?? 'not_found'),
            'message' => 'This message is not eligible to send or retry from its current status.',
        ];
    }

    $payload = [
        'recipient' => $recipient,
        'message' => (string) $row['message'],
    ];

    try {
        $http = smsapiph_http_request('POST', SMSAPIPH_SEND_URL, $payload, $cfg, $transport);
        $code = (int) $http['http_status'];
        if ($http['transport_error'] !== '') {
            sms_provider_save_result($smsId, 'unknown', null, 'Network/transport error; provider acceptance cannot be confirmed. Check the SMS API PH dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'The result could not be confirmed. Check SMS API PH before retrying to avoid a duplicate.'];
        }
        if ($code < 200 || $code >= 300) {
            if ($code >= 400 && $code < 500) {
                $error = 'SMS API PH rejected the request (HTTP ' . $code . '). Check the account, API key and recipient number.';
                sms_provider_save_result($smsId, 'failed', null, $error);
                return ['ok' => false, 'status' => 'failed', 'message' => $error];
            }
            sms_provider_save_result($smsId, 'unknown', null, 'SMS API PH returned HTTP ' . $code . '; acceptance cannot be confirmed. Check the provider dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'SMS API PH did not confirm acceptance. Check the provider dashboard before retrying.'];
        }

        $parsed = smsapiph_parse_response((string) $http['body']);
        $status = $parsed['status'];
        if ($status === null) {
            sms_provider_save_result($smsId, 'unknown', $parsed['message_id'], 'SMS API PH returned an unrecognized response. Check the provider dashboard before retrying.');
            return ['ok' => false, 'status' => 'unknown', 'message' => 'The response format was not recognized. Check SMS API PH before retrying.'];
        }

        $error = in_array($status, ['failed', 'refunded'], true) ? 'SMS API PH reported status ' . ucfirst($status) . '.' : null;
        sms_provider_save_result($smsId, $status, $parsed['message_id'], $error);
        $ok = in_array($status, ['queued', 'pending', 'sent'], true);
        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $ok
                ? 'SMS API PH accepted the notification (status: ' . ucfirst($status) . '). Any SMS/Email/Push fallback is managed by the provider.'
                : 'SMS API PH reported status ' . ucfirst($status) . '. Review the outbox before retrying.',
        ];
    } catch (Throwable $e) {
        try {
            sms_provider_save_result($smsId, 'unknown', null, 'Unexpected sending error; provider acceptance cannot be confirmed. Check SMS API PH before retrying.');
        } catch (Throwable $ignored) {
            error_log('BIRMS SMS status update failed for outbox #' . $smsId . '.');
        }
        error_log('BIRMS SMS API PH call failed for outbox #' . $smsId . ' (' . get_class($e) . ').');
        return ['ok' => false, 'status' => 'unknown', 'message' => 'The result could not be confirmed. Check SMS API PH before retrying.'];
    }
}

/** The provided SMS API PH quick-start does not document a status-query endpoint. */
function sms_refresh_outbox_status_smsapiph(
    int $smsId,
    ?callable $transport = null,
    ?array $testConfig = null
): array {
    $row = db_one('SELECT sms_id, provider_status FROM sms_outbox WHERE sms_id = ?', [$smsId]);
    if ($row === null) {
        return ['ok' => false, 'status' => 'not_found', 'message' => 'SMS outbox record was not found.'];
    }
    return [
        'ok' => false,
        'status' => (string) $row['provider_status'],
        'message' => 'The supplied SMS API PH endpoint does not document delivery-status lookup. The last saved status was retained.',
    ];
}
