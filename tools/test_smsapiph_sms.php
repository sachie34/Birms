<?php
/**
 * CLI-only SMS API PH connection test.
 * Sends one real SMS only after the operator types SEND. Does not use the BIRMS database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found. Run this test from Command Prompt using PHP CLI.\n");
}

require_once __DIR__ . '/../app/core.php';
require_once __DIR__ . '/../app/smsapiph_sms_provider.php';

function smsapiph_test_exit(string $message, int $code = 1): never
{
    fwrite($code === 0 ? STDOUT : STDERR, $message . PHP_EOL);
    exit($code);
}
function smsapiph_test_prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

$localFile = APP_ROOT . '/app/smsapiph.local.php';
if (!is_file($localFile) && trim((string) config('sms.smsapiph_api_key', '')) === '') {
    smsapiph_test_exit("SMS API PH key not found. Copy app/smsapiph.local.php.example to app/smsapiph.local.php and add your key locally.");
}
$cfg = smsapiph_sms_config([
    'enabled' => true,
    'api_key' => config('sms.smsapiph_api_key', ''),
    'connect_timeout' => 4,
    'timeout' => 12,
]);
if (!$cfg['has_key']) {
    smsapiph_test_exit("SMS API PH API key is empty. Add it to app/smsapiph.local.php (api_key) or private app/config.local.php.");
}
if (!$cfg['enabled']) {
    smsapiph_test_exit("SMS API PH test cannot run: " . $cfg['reason']);
}

fwrite(STDOUT, "BIRMS SMS API PH test (Command Prompt only)\n");
fwrite(STDOUT, "Endpoint: " . SMSAPIPH_SEND_URL . "\n");
fwrite(STDOUT, "The API key is loaded privately and will not be printed.\n\n");

$rawRecipient = smsapiph_test_prompt("Enter a Philippine mobile number you own or are authorized to test (09XXXXXXXXX or +639XXXXXXXXX): ");
$recipient = smsapiph_normalize_number($rawRecipient);
if ($recipient === null) {
    smsapiph_test_exit("Invalid Philippine mobile number. Use 09XXXXXXXXX or +639XXXXXXXXX.");
}
$message = 'BIRMS SMS API PH connection test. This is a test message.';
fwrite(STDOUT, "\nOne real API request will be sent to {$recipient}. It may consume account quota or incur charges.\n");
$confirm = smsapiph_test_prompt("Type SEND to continue, or anything else to cancel: ");
if ($confirm !== 'SEND') {
    smsapiph_test_exit("Cancelled. No request was sent.", 0);
}

$result = smsapiph_http_request(
    'POST',
    SMSAPIPH_SEND_URL,
    ['recipient' => $recipient, 'message' => $message],
    $cfg
);
if ($result['transport_error'] !== '') {
    smsapiph_test_exit("The HTTPS result could not be confirmed. Check the SMS API PH dashboard before retrying to avoid a duplicate.");
}
$code = (int) $result['http_status'];
if ($code < 200 || $code >= 300) {
    smsapiph_test_exit("SMS API PH returned HTTP {$code}. Check your API key, account and recipient in the provider dashboard.");
}
$parsed = smsapiph_parse_response((string) $result['body']);
if ($parsed['status'] === null) {
    smsapiph_test_exit("The endpoint returned HTTP {$code}, but its response format was not recognized. Check the provider dashboard before retrying; no raw response was printed.");
}
fwrite(STDOUT, "SMS API PH response recognized. BIRMS status: " . ucfirst((string) $parsed['status']) . "\n");
if (is_string($parsed['message_id']) && $parsed['message_id'] !== '') {
    fwrite(STDOUT, "Provider reference: " . $parsed['message_id'] . "\n");
}
fwrite(STDOUT, "Check the provider dashboard and your phone. An accepted request does not by itself prove delivery.\n");
exit(in_array($parsed['status'], ['queued', 'pending', 'sent'], true) ? 0 : 1);
