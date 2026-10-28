# Optional SMS API PH integration

BIRMS can submit a ready-for-pickup notification to SMS API PH when an authorized user moves a request to Ready. The integration is **disabled by default**. Without a private API key and explicit enablement, BIRMS only records notifications in its existing SMS outbox.

This adapter uses the endpoint and request shape supplied in the SMS API PH quick-start:

- Endpoint: `https://smsapiph.onrender.com/api/v1/send/sms`
- Header: `x-api-key: YOUR_API_KEY`
- JSON body: `recipient` and `message`

The quick-start says the provider manages SMS-primary, Email-first-fallback and Push-final-fallback delivery. BIRMS submits one request and does not implement those fallbacks itself. The supplied quick-start does not document a status-lookup endpoint or an exact response example, so BIRMS does not offer Refresh for this provider and does not claim that a message was delivered just because the HTTP request returned successfully.

## Configure a private API key

1. Create/sign in to your SMS API PH account and generate a new API key in its dashboard.
2. In your local working copy, copy `app/smsapiph.local.php.example` to `app/smsapiph.local.php`.
3. Open `app/smsapiph.local.php` and add the key to `api_key`. This file is ignored by Git. Never paste the real key into GitHub, screenshots, commits or chat.
4. Open your existing, ignored `app/config.local.php`. Keep all database/session/backup settings and change only the SMS settings to select the new provider and enable it when ready:

~~~php
'sms' => [
    'provider' => 'smsapiph',
    'smsapiph_enabled' => false, // Leave false until the controlled test is ready.
    'smsapiph_api_key' => '', // Optional if the key is in app/smsapiph.local.php.
    'semaphore_enabled' => false,
    'infobip_enabled' => false,
    'connect_timeout' => 4,
    'timeout' => 12,
],
~~~

Merge these keys into your existing array; do not replace the whole config file. A non-empty key in `app/smsapiph.local.php` takes precedence over `smsapiph_api_key` in `app/config.local.php`. Environment variables `BIRMS_SMS_PROVIDER=smsapiph`, `BIRMS_SMSAPIPH_ENABLED=true`, and `BIRMS_SMSAPIPH_API_KEY` are also supported. Do not set the enabled flag until you are ready to send a real test message.

## Test the API without touching BIRMS records

The repository includes a CLI-only test. It sends a real request only after you type `SEND` and does not access the BIRMS database.

~~~bat
cd C:\xampp\htdocs\birms_work
C:\xampp\php\php.exe tools\test_smsapiph_sms.php
~~~

Enter a mobile number you control or have explicit permission to use. The test may use API quota or incur charges. After it completes, check the SMS API PH dashboard and the phone. If the endpoint returns an unrecognized response shape, the test reports that it needs verification and does not print the raw response. Do not retry until you have checked the provider dashboard.

## Run through BIRMS

1. Keep using the separate working database `barangay_db_work`; do not test on the original `barangay_db`.
2. Set `provider` to `smsapiph`, keep `smsapiph_enabled` false while preparing, and confirm PHP's cURL extension is enabled in XAMPP.
3. Use fictitious demo resident/request data and a phone number you control or are authorized to test.
4. When ready, set `smsapiph_enabled` to `true`, then follow the usual BIRMS flow and move the demo request to Ready.
5. Review **Settings → SMS Delivery** and the provider dashboard. A `Queued` status means the API accepted the notification request; it is not proof of handset delivery. An `Unknown` result means check the provider dashboard before retrying.
6. After the test, set `smsapiph_enabled` back to `false` if you do not want additional real notifications.

No new database migration is required because BIRMS already has the shared SMS tracking fields from migration 012. Existing outbox rows marked `not_configured` are not automatically sent later when a provider is enabled; trigger a new demo request for an end-to-end test.

## Response-format limitation

The quick-start request example supplied for this integration decodes JSON but does not show a sample response. The adapter recognizes explicit `success` booleans and common explicit status values such as `queued`, `accepted`, `sent`, and `failed`. A 2xx response without a recognized success/status field is recorded as **Unknown** to avoid falsely reporting success or encouraging a duplicate send. If the provider documents a different response schema, update the parser and tests against that documented schema before using live notifications.

## Security

- The endpoint is fixed in code and HTTPS certificate validation is enabled; redirects are disabled.
- API keys are only sent in the `x-api-key` request header. They are never saved to the outbox or audit log, and raw provider response bodies are not stored.
- Never test with unrelated residents' numbers or real resident data without office approval and an appropriate notification policy.
- Automated tests use a mocked transport; they do not contact SMS API PH and do not verify live delivery.
