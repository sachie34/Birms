<?php
/**
 * Standalone Infobip SMS API test.
 *
 * CLI-only: this file sends a real SMS only after the operator types SEND.
 * It reads credentials from the ignored app/infobip.local.php file.
 * It does not connect to the BIRMS database or change request records.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found. Run this test from Command Prompt using PHP CLI.\n");
}

require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/infobip_sms_provider.php';

function infobip_test_prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function infobip_test_exit(string $message, int $code = 1): never
{
    fwrite($code === 0 ? STDOUT : STDERR, $message . PHP_EOL);
    exit($code);
}

$localFile = APP_ROOT . '/app/infobip.local.php';
if (!is_file($localFile)) {
    infobip_test_exit(
        "Private Infobip configuration was not found. Copy app/infobip.local.php.example to app/infobip.local.php and add your key there."
    );
}

$cfg = infobip_sms_config([
    'enabled' => true,
    'api_key' => config('sms.infobip_api_key', ''),
    'base_url' => config('sms.infobip_base_url', ''),
    'sender' => config('sms.infobip_sender', ''),
    'connect_timeout' => 4,
    'timeout' => 12,
]);

if (!$cfg['has_key']) {
    infobip_test_exit("Infobip API key is empty. Add it to app/infobip.local.php (api_key).");
}
if (!$cfg['enabled']) {
    infobip_test_exit("Infobip configuration is not ready: " . $cfg['reason']);
}

fwrite(STDOUT, "BIRMS Infobip SMS API test (Command Prompt only)\n");
fwrite(STDOUT, "Base URL: " . $cfg['base_url'] . PHP_EOL);
fwrite(STDOUT, "Sender: " . $cfg['sender'] . PHP_EOL);
fwrite(STDOUT, "Your API key is loaded privately and will not be printed.\n\n");

$rawRecipient = infobip_test_prompt("Enter a test recipient number you own or are authorized to use (e.g. 09XXXXXXXXX or 639XXXXXXXXX): ");
$recipient = infobip_sms_normalize_number($rawRecipient);
if ($recipient === null) {
    infobip_test_exit("That does not look like a supported Philippine mobile number. Use 09XXXXXXXXX or +639XXXXXXXXX.");
}

$message = 'BIRMS Infobip API test. If you received this SMS, the API request reached your phone.';
fwrite(STDOUT, PHP_EOL . "One real SMS will be sent to {$recipient}. It may use trial quota or incur a charge.\n");
$confirmation = infobip_test_prompt("Type SEND to continue, or anything else to cancel: ");
if ($confirmation !== 'SEND') {
    infobip_test_exit("Cancelled. No SMS was sent.", 0);
}

$payload = [
    'messages' => [[
        'sender' => $cfg['sender'],
        'destinations' => [['to' => $recipient]],
        'content' => ['text' => $message],
    ]],
];

$result = infobip_sms_http_request(
    'POST',
    $cfg['base_url'] . '/sms/3/messages',
    $payload,
    $cfg
);

if ($result['transport_error'] !== '') {
    infobip_test_exit("The HTTPS request did not complete. Check your internet connection, PHP cURL, and Infobip Base URL. The result is not confirmed; check the Infobip portal before sending again.");
}

$status = (int) $result['http_status'];
$decoded = json_decode((string) $result['body'], true);

if ($status < 200 || $status >= 300) {
    $details = '';
    if (is_array($decoded)) {
        $details = (string) (
            $decoded['requestError']['serviceException']['text']
            ?? $decoded['requestError']['serviceException']['messageId']
            ?? $decoded['message']
            ?? ''
        );
    }
    infobip_test_exit("Infobip returned HTTP {$status}." . ($details !== '' ? " Details: {$details}" : " Check the API scopes, account trial rules, sender and verified recipient in the Infobip portal."));
}

$messages = is_array($decoded) && isset($decoded['messages']) && is_array($decoded['messages'])
    ? $decoded['messages'] : [];
$first = isset($messages[0]) && is_array($messages[0]) ? $messages[0] : [];
$messageId = (string) ($first['messageId'] ?? '');
$statusName = (string) ($first['status']['name'] ?? 'accepted; provider status not included');
$statusDescription = (string) ($first['status']['description'] ?? '');

fwrite(STDOUT, "Infobip returned HTTP {$status}. The API accepted the request.\n");
if ($messageId !== '') {
    fwrite(STDOUT, "Message ID: {$messageId}\n");
}
fwrite(STDOUT, "Provider status: {$statusName}\n");
if ($statusDescription !== '') {
    fwrite(STDOUT, "Status detail: {$statusDescription}\n");
}
fwrite(STDOUT, "Check your phone and the Infobip portal for delivery. An accepted request does not by itself prove the SMS was delivered.\n");
exit(0);
