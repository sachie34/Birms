# Optional Infobip SMS integration

BIRMS can optionally submit a ready-for-pickup notification to Infobip when an authorized user moves a document request to Ready. The provider remains disabled unless explicitly enabled in private configuration. Without an enabled configuration and a valid key/Base URL, BIRMS only records the notification in its outbox.

Official references:
- [Send your first SMS using Infobip API](https://www.infobip.com/docs/tutorials/send-your-first-sms-message-using-infobip-api)
- [Infobip API authentication](https://www.infobip.com/docs/essentials/api-essentials/api-authentication)
- [Infobip Base URL guide](https://www.infobip.com/docs/essentials/api-essentials/base-url)

## What BIRMS does

1. When an authorized staff member changes a request to Ready, BIRMS commits the request status and SMS outbox record first.
2. If Infobip is selected and explicitly enabled, BIRMS sends the outbox notification over HTTPS to the Infobip SMS API v3 endpoint.
3. BIRMS records the Infobip message ID and maps provider statuses into the shared SMS outbox status fields.
4. Authorized staff can open **Settings → SMS Delivery** to review records, refresh a delivery report, and manually retry only provider-confirmed Failed/Refunded results.
5. A network timeout or unrecognized result is marked Unknown and is not automatically retried, since Infobip may already have accepted the request.

The delivery report API can return no result until delivery processing advances. Infobip documents that delivery reports are pull-based and report retrieval may consume the available report; if a report is not returned, BIRMS preserves the last saved status. Use the Infobip portal to investigate uncertain results before manually retrying.

## Free-trial limits

Infobip's official quick-start guide says trial users can use the test sender **ServiceSMS** and can only send to a phone number verified in the trial account. Trial quotas and account entitlements can change; check the Infobip portal before testing, and do not assume a message is permanently free. Only send test messages to a phone you control or have permission to use.

## Get your Base URL

1. Sign in to the Infobip portal.
2. Open the API documentation or developer tools area where your account displays its **Base URL**.
3. Copy the complete HTTPS Base URL, for example `https://api.infobip.com` or the account-specific host shown in your portal.
4. Do not copy the API key into this document, a GitHub issue, screenshot, or this chat.

BIRMS accepts only HTTPS Infobip API hosts ending in `api.infobip.com`; it does not send keys to arbitrary hosts.

## Configure the private API key

Open your local, ignored **app/config.local.php** file. Merge the following keys into its existing `'sms'` array; keep all existing database/session/backup settings. Do not replace your whole config file.

~~~php
'sms' => [
    'provider' => 'infobip',
    'infobip_enabled' => false,
    'infobip_api_key' => 'PASTE_YOUR_KEY_LOCALLY',
    'infobip_base_url' => 'https://api.infobip.com',
    'infobip_sender' => 'ServiceSMS',
    'semaphore_enabled' => false,
    'semaphore_api_key' => '',
    'semaphore_sendername' => '',
    'connect_timeout' => 4,
    'timeout' => 12,
],
~~~

- Replace the Base URL example with the exact HTTPS Base URL from your Infobip portal.
- Keep `infobip_enabled` set to `false` while preparing the configuration. The key must remain only in the ignored local file or a protected environment variable.
- The API key needs the `sms:message:send` scope for sending. The `sms:logs:read` scope is optional for future log-focused operations; this integration obtains message-level delivery reports through the SMS reports endpoint.
- Confirm that `php_curl` is enabled in the PHP installation used by Apache.

Alternative environment variables:
- `BIRMS_SMS_PROVIDER=infobip`
- `BIRMS_INFOBIP_ENABLED=true`
- `BIRMS_INFOBIP_API_KEY`
- `BIRMS_INFOBIP_BASE_URL`
- `BIRMS_INFOBIP_SENDER=ServiceSMS`

Environment variables override local-file values. Never store the API key in tracked source files or commit it.

## Controlled end-to-end test

1. Pull the provider code after the Infobip support change is merged. Do not point BIRMS at the original `barangay_db`; use the existing working copy `barangay_db_work`.
2. Check `tools/migrate.php status`. No new database migration is required by this provider adapter if migration 012 for SMS tracking is already applied. Back up the working database before any schema changes.
3. Keep sending disabled until you are ready to test. In Infobip, verify the recipient phone number required by your trial account.
4. In private `app/config.local.php`, set `provider` to `infobip`, supply the API key and exact Base URL, use the permitted sender (usually `ServiceSMS` for the trial), then set `infobip_enabled` to `true`.
5. Restart or refresh the BIRMS request so PHP reloads configuration. Use fictitious demo resident/request data and a phone you control.
6. Move that request through the normal workflow and mark it Ready. Check **Settings → SMS Delivery** for the provider status and message ID. Use Refresh to request a delivery report if one is available.
7. Check the Infobip portal and your phone. A CI pass or a queued status does not prove live delivery. If the status is Unknown or the report is missing, verify the portal before resending.
8. After testing, set `infobip_enabled` to `false` if you do not want further texts or potential charges.

## Security and limits

- The key is sent in the `Authorization: App ...` HTTPS header, not in the URL or request body.
- The adapter validates the Base URL host, requires HTTPS, validates certificates, disables redirects, uses bounded timeouts, and never logs the API key or raw provider response.
- The default provider remains Semaphore, and both provider integrations are opt-in. To switch providers, explicitly set `'provider' => 'infobip'`; existing Semaphore settings are preserved.
- Automated tests use a mock transport and never contact Infobip or spend credits. A real successful SMS delivery remains unverified until you conduct your own controlled test.
