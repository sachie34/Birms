# Optional Semaphore SMS integration

BIRMS can optionally submit a readiness notification to Semaphore when an authorized user moves a document request to **Ready**. This is disabled by default. Without an enabled configuration and API key, BIRMS continues to record the notification in its outbox only.

Official provider references:
- [Semaphore API documentation](https://semaphore.co/docs)
- [Semaphore account and API access](https://api.semaphore.co/)

## What it does

1. The authorized status action saves the request status and writes the message into **sms_outbox** in one database transaction.
2. **After that transaction commits**, BIRMS calls Semaphore's HTTPS **POST /api/v4/messages** endpoint. A provider outage cannot undo the saved request approval.
3. BIRMS stores the provider's message ID/status, attempt count, last attempt time, and a short error explanation. It never stores the raw API response or API key in the outbox or audit log.
4. Settings → SMS Delivery displays the last 100 entries. Authorized users can refresh a provider status and manually retry only provider-confirmed **Failed** or **Refunded** results.
5. A network timeout or malformed response is marked **Unknown**. BIRMS does not retry Unknown messages automatically or offer a retry button for them because the provider may already have accepted the SMS.
6. If no recipient number is saved, the row is marked **No phone number**. Messages created while the provider is disabled remain log-only and are not sent retroactively when a key is added later.

**Queued/Pending does not mean the resident has read the SMS.** Provider status **Sent** reflects Semaphore's status, not proof that the resident saw the message. Use the provider dashboard for a separate confirmation.

## Before enabling a paid provider

- Create or sign in to your own Semaphore account and review current charges, terms and sender-name requirements on the official site. SMS credits may be consumed when requests become Ready.
- Use a phone number you control or have explicit permission to test. Do not test against real residents without an approved notification policy and their appropriate notice/consent.
- Keep the live API key only in the private **app/config.local.php** file or a server environment variable. Never paste it into GitHub, screenshots, chat, or a public issue.
- The machine running BIRMS must have working internet access and PHP's **curl** extension enabled.
- Back up the working database before applying migration 012. Do not run the migration against the original **barangay_db**.

## Apply the database migration

After downloading the updated repository into **C:\xampp\htdocs\birms_work**, open XAMPP Shell or Command Prompt and first inspect the migration status:

~~~bat
cd C:\xampp\htdocs\birms_work
C:\xampp\php\php.exe tools\migrate.php status
~~~

If migration 012 is pending, take a database backup, then apply it using an account that has ALTER permission on the **working** database. The runtime **birms_app** user is deliberately limited and may not have migration privileges. For a typical local setup:

~~~bat
set BIRMS_DB_USER=root
set BIRMS_DB_PASS=YOUR_MYSQL_ROOT_PASSWORD
C:\xampp\php\php.exe tools\migrate.php up
set BIRMS_DB_USER=
set BIRMS_DB_PASS=
~~~

Replace the placeholder with your actual local MySQL root password. If it is intentionally blank, use **set BIRMS_DB_PASS=**. These commands temporarily override only the database credentials for the command window; they do not write credentials to source control. Check **tools/migrate.php status** again to confirm migration 012 is applied.

## Configure the private API key

Open your **local, ignored app/config.local.php** file. Add or edit the following section in the returned PHP array; keep all your existing database/session/backup entries.

~~~php
'sms' => [
    'semaphore_enabled' => false,
    'semaphore_api_key' => '',
    'semaphore_sendername' => '',
    'connect_timeout' => 4,
    'timeout' => 12,
],
~~~

1. Paste your Semaphore API key into **semaphore_api_key**.
2. If Semaphore has an approved sender name for your account, put that exact active sender name in **semaphore_sendername**. Otherwise leave it blank to use the account's default Sender Name.
3. Keep **semaphore_enabled** set to **false** until the migration is applied and you are ready to run a real, controlled test.
4. Make sure PHP can read **app/config.local.php**. Do not edit **app/config.local.php.example** with the live key; that example is tracked in GitHub and intentionally has blank values.

Environment variable alternatives are **BIRMS_SEMAPHORE_ENABLED**, **BIRMS_SEMAPHORE_API_KEY**, and **BIRMS_SEMAPHORE_SENDERNAME**. A server environment variable overrides the corresponding private-file value.

## Controlled end-to-end test

1. Start Apache and the intended local MySQL/MariaDB server. Sign in with an Administrator or Secretary account.
2. In Settings → SMS text, review the ready-for-pickup message. Allowed placeholders include **{{reference_no}}** and **{{document_name}}**. Do not begin the message with the word **TEST** because Semaphore's official documentation says those messages may be silently ignored.
3. Register a clearly fictitious test resident using a phone number you control/are authorized to use. Do not use real resident data or an unrelated person's number.
4. Enable Semaphore in the private config and save it. Refresh BIRMS so the updated configuration is loaded.
5. Create a document request for that test resident, follow the real status workflow, mark requirements complete, then have an authorized approver move the request to **Ready**. Only that transition triggers the SMS send.
6. Open **SMS Delivery** in the sidebar or use Settings → SMS text → Open SMS Delivery Log. Check the provider status and message ID. Use **Refresh** to query Semaphore for its latest saved status.
7. If the result is Failed/Refunded, review the reason before using the manual **Retry** action. A status of Unknown requires checking Semaphore's dashboard before any decision to resend.
8. After testing, set **semaphore_enabled** back to **false** if you do not want further real texts or costs. Keep the API key private or remove it if the integration is no longer needed.

## Automated tests and limitations

The integration tests use a mocked HTTP transport, a fake test key, and database fixture rows; CI must never contact the live Semaphore endpoint or consume credits. The tests cover successful queueing, repeat-send protection, confirmed failures/manual retry, unknown outcomes without retry, and status refresh.

This code is not proof that your Semaphore account has been approved, your sender name is active, credits are available, carrier delivery works, or a live SMS has been delivered. Those items require your own account and controlled local test. There is no public BIRMS REST API in this feature; BIRMS is the client of the Semaphore SMS API.
