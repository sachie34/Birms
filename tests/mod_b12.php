<?php
/** B12 checks for optional SMS providers using mock transports only. */
declare(strict_types=1);
require_once __DIR__ . '/../app/sms_provider.php';

function b12_make_outbox(string $suffix, ?string $number = '09171230001'): int
{
    db_exec(
        "INSERT INTO sms_outbox
            (recipient_number, message, trigger_event, request_id, delivery, provider_name, provider_status)
         VALUES (?, ?, ?, NULL, 'Logged only', 'Semaphore', ?)",
        [$number, 'Your request DEMO-B12 (' . $suffix . ') is ready for pickup.', 'ZZ-B12-' . $suffix, $number === null || trim($number) === '' ? 'no_number' : 'not_attempted']
    );
    return (int) db()->lastInsertId();
}
function b12_clean_outbox(): void
{
    db_exec("DELETE FROM sms_outbox WHERE trigger_event LIKE 'ZZ-B12-%'");
}
function b12_mock_response(int $status, array|string $body, string $transportError = ''): array
{
    return ['http_status' => $status, 'body' => is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body, 'transport_error' => $transportError];
}

function run_b12(array $S, string $base): void
{
    b12_clean_outbox();
    $testConfig = [
        'provider' => 'semaphore',
        'enabled' => true,
        'api_key' => 'BIRMS-FAKE-TEST-KEY-NOT-A-REAL-SECRET',
        'sendername' => 'BIRMSMock',
        'connect_timeout' => 1,
        'timeout' => 3,
    ];

    $id = b12_make_outbox('success', '+639171230001');
    $calls = [];
    $successTransport = static function (string $method, string $url, array $params) use (&$calls): array {
        $calls[] = ['method' => $method, 'url' => $url, 'params' => $params];
        return b12_mock_response(200, [[
            'message_id' => 'b12-queued-123',
            'status' => 'Queued',
            'recipient' => '639171230001',
        ]]);
    };
    $sent = sms_send_outbox($id, false, $successTransport, $testConfig);
    $saved = db_one('SELECT provider_status, provider_message_id, attempt_count, provider_error FROM sms_outbox WHERE sms_id = ?', [$id]);
    record('B12-01', 'A mocked Semaphore success saves Queued/message ID and never stores the API key',
        $sent['status'] === 'queued' && count($calls) === 1
        && $calls[0]['method'] === 'POST'
        && $calls[0]['url'] === SMS_SEMAPHORE_MESSAGES_URL
        && ($calls[0]['params']['apikey'] ?? '') === $testConfig['api_key']
        && ($calls[0]['params']['number'] ?? '') === '639171230001'
        && ($calls[0]['params']['sendername'] ?? '') === 'BIRMSMock'
        && ($saved['provider_status'] ?? '') === 'queued'
        && ($saved['provider_message_id'] ?? '') === 'b12-queued-123'
        && (int) ($saved['attempt_count'] ?? -1) === 1
        && !str_contains((string) ($saved['provider_error'] ?? ''), $testConfig['api_key']),
        'status=' . ($saved['provider_status'] ?? 'missing') . '; calls=' . count($calls));

    $secondCalls = 0;
    $noDuplicateTransport = static function () use (&$secondCalls): array {
        $secondCalls++;
        return b12_mock_response(200, [['message_id' => 'duplicate', 'status' => 'Queued']]);
    };
    $repeat = sms_send_outbox($id, false, $noDuplicateTransport, $testConfig);
    record('B12-02', 'Normal dispatch cannot submit the same outbox row a second time',
        $secondCalls === 0 && $repeat['status'] === 'queued'
        && (int) db_val('SELECT attempt_count FROM sms_outbox WHERE sms_id = ?', [$id]) === 1);

    $failedId = b12_make_outbox('failed');
    $failCalls = 0;
    $failTransport = static function () use (&$failCalls): array {
        $failCalls++;
        return b12_mock_response(422, ['error' => 'invalid recipient']);
    };
    $failed = sms_send_outbox($failedId, false, $failTransport, $testConfig);
    $failedStatus = (string) db_val('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$failedId]);
    $retryTransport = static function (string $method, string $url, array $params) use (&$failCalls): array {
        $failCalls++;
        return b12_mock_response(200, [['message_id' => 'b12-retry-456', 'status' => 'Pending']]);
    };
    $retried = sms_send_outbox($failedId, true, $retryTransport, $testConfig);
    $retrySaved = db_one('SELECT provider_status, attempt_count, provider_message_id FROM sms_outbox WHERE sms_id = ?', [$failedId]);
    record('B12-03', 'A confirmed provider rejection can be explicitly retried once and the attempt is counted',
        $failed['status'] === 'failed' && $failedStatus === 'failed'
        && $retried['status'] === 'pending' && $failCalls === 2
        && ($retrySaved['provider_status'] ?? '') === 'pending'
        && (int) ($retrySaved['attempt_count'] ?? 0) === 2
        && ($retrySaved['provider_message_id'] ?? '') === 'b12-retry-456');

    $unknownId = b12_make_outbox('unknown');
    $unknownCalls = 0;
    $timeoutTransport = static function () use (&$unknownCalls): array {
        $unknownCalls++;
        return b12_mock_response(0, '', 'simulated timeout');
    };
    $unknown = sms_send_outbox($unknownId, false, $timeoutTransport, $testConfig);
    $unknownRetry = sms_send_outbox($unknownId, true, $timeoutTransport, $testConfig);
    record('B12-04', 'A transport timeout is marked Unknown and is never retried because provider acceptance is uncertain',
        $unknown['status'] === 'unknown' && $unknownRetry['status'] === 'unknown'
        && $unknownCalls === 1
        && db_val('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$unknownId]) === 'unknown');

    $refreshId = b12_make_outbox('refresh');
    $refreshClaim = sms_send_outbox($refreshId, false, $successTransport, $testConfig);
    $refreshCalls = [];
    $refreshTransport = static function (string $method, string $url, array $params) use (&$refreshCalls): array {
        $refreshCalls[] = ['method' => $method, 'url' => $url, 'params' => $params];
        return b12_mock_response(200, ['message_id' => 'b12-queued-123', 'status' => 'Sent']);
    };
    $refreshed = sms_refresh_outbox_status($refreshId, $refreshTransport, $testConfig);
    record('B12-05', 'Refreshing a provider message queries the status endpoint and updates status without resending',
        $refreshClaim['status'] === 'queued' && $refreshed['status'] === 'sent'
        && count($refreshCalls) === 1
        && $refreshCalls[0]['method'] === 'GET'
        && str_ends_with($refreshCalls[0]['url'], '/b12-queued-123')
        && ($refreshCalls[0]['params']['apikey'] ?? '') === $testConfig['api_key']
        && (int) db_val('SELECT attempt_count FROM sms_outbox WHERE sms_id = ?', [$refreshId]) === 1);

    $disabledId = b12_make_outbox('disabled');
    $disabledCalls = 0;
    $disabledTransport = static function () use (&$disabledCalls): array {
        $disabledCalls++;
        return b12_mock_response(200, [['message_id' => 'must-not-send', 'status' => 'Queued']]);
    };
    $disabled = sms_send_outbox($disabledId, false, $disabledTransport, [
        'enabled' => false, 'api_key' => '', 'sendername' => '',
    ]);
    record('B12-06', 'Provider remains disabled when no operator opt-in/API key is configured',
        $disabled['status'] === 'not_configured' && $disabledCalls === 0
        && db_val('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$disabledId]) === 'not_configured');

    $noNumberId = b12_make_outbox('no-number', null);
    $noNumberCalls = 0;
    $noNumberTransport = static function () use (&$noNumberCalls): array {
        $noNumberCalls++;
        return b12_mock_response(200, [['message_id' => 'must-not-send', 'status' => 'Queued']]);
    };
    $noNumber = sms_send_outbox($noNumberId, false, $noNumberTransport, $testConfig);
    record('B12-07', 'A missing recipient number is recorded but never sent to the provider',
        $noNumber['status'] === 'no_number' && $noNumberCalls === 0);

    $bad = '';
    foreach (['/admin/sms.php' => 'settings.edit'] as $path => $permission) {
        [$ok, $why] = rbac_page($S, $path, $permission);
        if (!$ok) { $bad .= $path . ': ' . $why . '; '; }
    }
    record('B12-08', 'SMS delivery and retry page follows the existing Settings edit permission', $bad === '', $bad);

    // Infobip adapter: mocked only; no credentials are live and no external call is made.
    $infobipConfig = [
        'provider' => 'infobip',
        'enabled' => true,
        'api_key' => 'BIRMS-FAKE-INFOBIP-KEY-NOT-A-REAL-SECRET',
        'base_url' => 'https://api.infobip.com',
        'sender' => 'ServiceSMS',
        'connect_timeout' => 1,
        'timeout' => 3,
    ];
    $infobipId = b12_make_outbox('infobip-success', '09171230001');
    $infobipCalls = [];
    $infobipTransport = static function (string $method, string $url, array $params) use (&$infobipCalls): array {
        $infobipCalls[] = ['method' => $method, 'url' => $url, 'params' => $params];
        return b12_mock_response(200, [
            'messages' => [[
                'messageId' => 'infobip-b12-queued-789',
                'status' => ['groupName' => 'PENDING', 'name' => 'PENDING_ACCEPTED'],
                'destination' => '639171230001',
            ]],
        ]);
    };
    $infobipSent = sms_send_outbox($infobipId, false, $infobipTransport, $infobipConfig);
    $infobipSaved = db_one('SELECT provider_name, provider_status, provider_message_id, attempt_count, provider_error FROM sms_outbox WHERE sms_id = ?', [$infobipId]);
    record('B12-09', 'Mocked Infobip send uses the SMS v3 endpoint, normalizes PH mobile number and saves only safe provider metadata',
        $infobipSent['status'] === 'queued' && count($infobipCalls) === 1
        && $infobipCalls[0]['method'] === 'POST'
        && $infobipCalls[0]['url'] === 'https://api.infobip.com/sms/3/messages'
        && (($infobipCalls[0]['params']['messages'][0]['destinations'][0]['to'] ?? '') === '639171230001')
        && (($infobipCalls[0]['params']['messages'][0]['sender'] ?? '') === 'ServiceSMS')
        && ($infobipSaved['provider_name'] ?? '') === 'Infobip'
        && ($infobipSaved['provider_status'] ?? '') === 'queued'
        && ($infobipSaved['provider_message_id'] ?? '') === 'infobip-b12-queued-789'
        && (int) ($infobipSaved['attempt_count'] ?? -1) === 1
        && !str_contains((string) ($infobipSaved['provider_error'] ?? ''), $infobipConfig['api_key']),
        'status=' . ($infobipSaved['provider_status'] ?? 'missing') . '; calls=' . count($infobipCalls));

    $infobipRefreshCalls = [];
    $infobipRefreshTransport = static function (string $method, string $url, array $params) use (&$infobipRefreshCalls): array {
        $infobipRefreshCalls[] = ['method' => $method, 'url' => $url, 'params' => $params];
        return b12_mock_response(200, [
            'results' => [[
                'messageId' => 'infobip-b12-queued-789',
                'status' => ['groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET'],
            ]],
        ]);
    };
    $infobipRefreshed = sms_refresh_outbox_status($infobipId, $infobipRefreshTransport, $infobipConfig);
    record('B12-10', 'Mocked Infobip delivery report changes status to Sent without resending the SMS',
        $infobipRefreshed['status'] === 'sent'
        && count($infobipRefreshCalls) === 1
        && $infobipRefreshCalls[0]['method'] === 'GET'
        && $infobipRefreshCalls[0]['url'] === 'https://api.infobip.com/sms/3/reports'
        && ($infobipRefreshCalls[0]['params']['messageId'] ?? '') === 'infobip-b12-queued-789'
        && (int) db_val('SELECT attempt_count FROM sms_outbox WHERE sms_id = ?', [$infobipId]) === 1
        && db_val('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$infobipId]) === 'sent');

    // SMS API PH adapter: mocked only; no real endpoint call or API credits are used.
    $smsapiphConfig = [
        'provider' => 'smsapiph',
        'enabled' => true,
        'api_key' => 'BIRMS-FAKE-SMSAPIPH-KEY-NOT-A-REAL-SECRET',
        'connect_timeout' => 1,
        'timeout' => 3,
    ];
    $smsapiphId = b12_make_outbox('smsapiph-success', '09171230001');
    $smsapiphCalls = [];
    $smsapiphTransport = static function (string $method, string $url, array $request) use (&$smsapiphCalls): array {
        $smsapiphCalls[] = ['method' => $method, 'url' => $url, 'request' => $request];
        return b12_mock_response(200, [
            'success' => true,
            'data' => ['batch_id' => 'smsapiph-b12-123'],
            'error' => null,
        ]);
    };
    $smsapiphSent = sms_send_outbox($smsapiphId, false, $smsapiphTransport, $smsapiphConfig);
    $smsapiphSaved = db_one('SELECT provider_name, provider_status, provider_message_id, attempt_count, provider_error FROM sms_outbox WHERE sms_id = ?', [$smsapiphId]);
    record('B12-11', 'Mocked SMS API PH send uses x-api-key + JSON, normalizes a PH number, and stores only safe metadata',
        $smsapiphSent['status'] === 'queued'
        && count($smsapiphCalls) === 1
        && $smsapiphCalls[0]['method'] === 'POST'
        && $smsapiphCalls[0]['url'] === SMSAPIPH_SEND_URL
        && (($smsapiphCalls[0]['request']['headers']['x-api-key'] ?? '') === $smsapiphConfig['api_key'])
        && (($smsapiphCalls[0]['request']['headers']['Content-Type'] ?? '') === 'application/json')
        && (($smsapiphCalls[0]['request']['payload']['recipient'] ?? '') === '+639171230001')
        && (($smsapiphCalls[0]['request']['payload']['message'] ?? '') !== '')
        && ($smsapiphSaved['provider_name'] ?? '') === 'SMS API PH'
        && ($smsapiphSaved['provider_status'] ?? '') === 'queued'
        && ($smsapiphSaved['provider_message_id'] ?? '') === 'smsapiph-b12-123'
        && (int) ($smsapiphSaved['attempt_count'] ?? -1) === 1
        && !str_contains((string) ($smsapiphSaved['provider_error'] ?? ''), $smsapiphConfig['api_key']),
        'status=' . ($smsapiphSaved['provider_status'] ?? 'missing') . '; calls=' . count($smsapiphCalls));

    $unknownApiId = b12_make_outbox('smsapiph-unknown-response', '09171230002');
    $unknownApiCalls = 0;
    $unknownApiTransport = static function () use (&$unknownApiCalls): array {
        $unknownApiCalls++;
        return b12_mock_response(200, ['message' => 'response shape not documented']);
    };
    $unknownApi = sms_send_outbox($unknownApiId, false, $unknownApiTransport, $smsapiphConfig);
    $unknownApiRetry = sms_send_outbox($unknownApiId, true, $unknownApiTransport, $smsapiphConfig);
    record('B12-12', 'An unrecognized HTTP 2xx response is Unknown and cannot be blindly retried',
        $unknownApi['status'] === 'unknown'
        && $unknownApiRetry['status'] === 'unknown'
        && $unknownApiCalls === 1
        && db_val('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$unknownApiId]) === 'unknown');

    $diagnostics = [
        smsapiph_transport_diagnostic(28, 'operation timed out with api key and recipient details'),
        smsapiph_transport_diagnostic(6, 'could not resolve host'),
        smsapiph_transport_diagnostic(60, 'SSL certificate problem'),
        smsapiph_transport_diagnostic(7, 'failed to connect'),
        smsapiph_transport_diagnostic(999, 'unexpected provider detail with api key and recipient'),
    ];
    record('B12-13', 'Transport diagnostics classify timeout, DNS, TLS/certificate, connection and other errors without raw details',
        $diagnostics === [
            'SMS API PH transport error: timeout (cURL 28).',
            'SMS API PH transport error: dns (cURL 6).',
            'SMS API PH transport error: tls/certificate (cURL 60).',
            'SMS API PH transport error: connection (cURL 7).',
            'SMS API PH transport error: other (cURL 999).',
        ]
        && !str_contains(implode(' ', $diagnostics), 'api key')
        && !str_contains(implode(' ', $diagnostics), 'recipient'));

    $timeoutApiId = b12_make_outbox('smsapiph-timeout', '09171230003');
    $timeoutApiCalls = 0;
    $timeoutApiTransport = static function () use (&$timeoutApiCalls): array {
        $timeoutApiCalls++;
        return b12_mock_response(0, '', 'simulated timeout; api key=private; recipient=+639171230003; cURL 28');
    };
    $timeoutApi = sms_send_outbox($timeoutApiId, false, $timeoutApiTransport, $smsapiphConfig);
    $timeoutApiRetry = sms_send_outbox($timeoutApiId, true, $timeoutApiTransport, $smsapiphConfig);
    $timeoutApiSaved = db_one('SELECT provider_status, provider_error, attempt_count FROM sms_outbox WHERE sms_id = ?', [$timeoutApiId]);

    $otherApiId = b12_make_outbox('smsapiph-other-transport', '09171230004');
    $otherApiTransport = static function (): array {
        return b12_mock_response(0, '', 'unexpected provider detail; api key=private; recipient=+639171230004');
    };
    $otherApi = sms_send_outbox($otherApiId, false, $otherApiTransport, $smsapiphConfig);
    $otherApiSaved = db_one('SELECT provider_status, provider_error FROM sms_outbox WHERE sms_id = ?', [$otherApiId]);
    record('B12-14', 'Mocked timeout and other transport errors remain Unknown, save only sanitized diagnostics, and are not retried',
        $timeoutApi['status'] === 'unknown'
        && $timeoutApiRetry['status'] === 'unknown'
        && $timeoutApiCalls === 1
        && ($timeoutApiSaved['provider_status'] ?? '') === 'unknown'
        && str_contains((string) ($timeoutApiSaved['provider_error'] ?? ''), 'transport error: timeout')
        && !str_contains((string) ($timeoutApiSaved['provider_error'] ?? ''), 'api key')
        && !str_contains((string) ($timeoutApiSaved['provider_error'] ?? ''), 'recipient')
        && (int) ($timeoutApiSaved['attempt_count'] ?? -1) === 1
        && $otherApi['status'] === 'unknown'
        && ($otherApiSaved['provider_status'] ?? '') === 'unknown'
        && str_contains((string) ($otherApiSaved['provider_error'] ?? ''), 'transport error: other')
        && !str_contains((string) ($otherApiSaved['provider_error'] ?? ''), 'api key')
        && !str_contains((string) ($otherApiSaved['provider_error'] ?? ''), 'recipient'));

    $refreshCallsBefore = count($smsapiphCalls);
    $unsupportedRefresh = sms_refresh_outbox_status($smsapiphId, $smsapiphTransport, $smsapiphConfig);
    record('B12-15', 'SMS API PH status refresh stays unavailable without a documented status endpoint',
        !$unsupportedRefresh['ok']
        && str_contains(strtolower($unsupportedRefresh['message']), 'does not document')
        && count($smsapiphCalls) === $refreshCallsBefore
        && db_val('SELECT provider_status FROM sms_outbox WHERE sms_id = ?', [$smsapiphId]) === 'queued');

    b12_clean_outbox();
}
