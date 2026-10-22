<?php
/** B12 checks for the opt-in Semaphore provider using a mock transport only. */
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

    b12_clean_outbox();
}
