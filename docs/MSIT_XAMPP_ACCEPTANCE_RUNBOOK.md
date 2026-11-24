# MSIT Branch — XAMPP and Role Acceptance Runbook

**Repository:** [sachie34/Birms](https://github.com/sachie34/Birms)  
**Validated demonstration candidate branch:** `john/phase2-kiosk-no-sms`  
**Pull request:** [#39 — QR kiosk and email-only workflow](https://github.com/sachie34/Birms/pull/39)  
**Controlling requirements:** `BRMS_SRS_Revised.docx` (revised MSIT SRS)

> This runbook describes the revised MSIT candidate, not the older B1–B12 `main` build. Download the [validated candidate ZIP](https://github.com/sachie34/Birms/archive/refs/heads/john/phase2-kiosk-no-sms.zip), not the `main` ZIP or the earlier `john/msit-srs-compliance` snapshot.
>
> Baseline candidate commit a09e9c2b7725ee6f1c2a9f3f2085fd8ec3128486 was covered by GitHub Actions run [#1309](https://github.com/sachie34/Birms/actions/runs/45377461100): 8 PHP/MariaDB jobs passed, each with 48/48 security checks and 283/283 module checks. The follow-up branch `codex/email-only-failure-safety` adds email-only preference migration 022, removes channel selectors, and hardens email/OTP failure handling. Those follow-up changes are **NOT VERIFIED until their own exact-head CI run passes**. This is hosted CI evidence only; complete the target-PC checks below before calling local acceptance PASS.
>
> For a real office installation, also follow [PRODUCTION_DEPLOYMENT_RUNBOOK.md](PRODUCTION_DEPLOYMENT_RUNBOOK.md). The XAMPP setup wizard provisions only the isolated working/demo database; it is not the production database procedure.

## 1. What is and is not verified

GitHub Actions validates PHP syntax, include paths, setup preflight, migrations, the schema snapshot, security/authentication/audit tests, and module integration tests across PHP 8.3, 8.4, 8.5 and 8.6 against disposable MariaDB 10.4 and 10.11 databases.

Those hosted tests do **not** prove that Apache's PHP binary on the target XAMPP PC, a physical kiosk/touchscreen, a phone on the barangay LAN, real email delivery, printer output, or backup restoration works. Record those as **NOT RUN** until actually tested on the target machine. Do not change a NOT RUN result to PASS based only on GitHub Actions.

## 2. Safety before using a local working database

1. Back up the current project folder and the **working-copy** database before migration or testing.
2. Confirm `app/config.local.php` points to a database whose name ends in `_work` or `_test`, normally `barangay_db_work`. Do not run migrations or test suites against the original `barangay_db` or any live/production database.
3. Keep real resident data out of classroom testing. Use synthetic residents, addresses, email addresses and phone numbers.
4. Keep real email delivery disabled in **Settings → Notifications** and in the private server configuration while running module tests. The module runner refuses to start when real email notifications are enabled because tests may create outbound email. The current candidate has no active SMS provider or SMS sending workflow; historical SMS records are retained only for compatibility/audit.
5. Do not share `app/config.local.php`, API keys, database passwords, private uploads, or SQL backups when sending test reports.

## 3. Confirm the PHP version used by both CLI and Apache

As of 19 November 2026, the application guard requires these stable/security-patched branches:
- PHP 8.6.0 or later within 8.6
- PHP 8.5.13 or later within 8.5
- PHP 8.4.28 or later within 8.4
- PHP 8.3.37 or later within 8.3
- PHP 8.2.36 or later only through 31 December 2026; PHP 8.2 must be upgraded before that date

PHP 8.1 and older are rejected. Prefer a current supported branch; PHP 8.6.0 is stable, and the current candidate's hosted matrix includes PHP 8.6 with MariaDB 10.4 and 10.11. Still verify the installed CLI and Apache PHP versions on the target XAMPP PC. The PHP team published the listed security updates and the first stable PHP 8.6.0 on 19 November 2026. See the [official PHP supported versions](https://www.php.net/supported-versions.php) and [PHP 2026 security release archive](https://www.php.net/archive/2026.php).

Open Command Prompt and run from the MSIT branch folder:

```bat
cd /d C:\xampp\htdocs\Birms-main
C:\xampp\php\php.exe -v
C:\xampp\php\php.exe tools\check_php_includes.php
C:\xampp\php\php.exe tools\setup_xampp.php --check
C:\xampp\php\php.exe -m | findstr /I "zip"
```

The setup preflight requires `pdo_mysql`, `fileinfo` and `zip`, and checks that migrations 019, 020, 021 and 022 are present in the source folder; migration 022 is the current candidate's latest migration sentinel. If this check fails, do not proceed with a mixed or incomplete project copy.

Then verify the ZIP extension needed for complete system backups:
1. Run the CLI command above and confirm `zip` appears in the module list.
2. Open Apache's PHPInfo page and confirm the `zip` section is present there too. CLI PHP and Apache can load different `php.ini` files.
3. If it is missing, open the active XAMPP `php.ini`, enable `extension=zip` (remove a leading semicolon if present), save, and restart Apache. Repeat the CLI and Apache checks.
4. For browser uploads of large full-system ZIPs, review `upload_max_filesize`, `post_max_size`, `max_execution_time`, and `max_input_time`. They must permit the intended archive size (maximum 512 MiB) and enough time for staging/checksum verification. Do not raise limits unnecessarily on an internet-exposed server.

Then check the version used by **Apache**, not only the CLI:
1. Start Apache in XAMPP.
2. Open the XAMPP dashboard and its PHPInfo page, or temporarily create a local-only PHP page that prints `PHP_VERSION` and `PHP_VERSION_ID` inside the public folder.
3. Open that page through `http://localhost/Birms-main/public/`.
4. Delete the temporary PHP page immediately after checking it.
5. Confirm the Apache version meets the same patch requirement. If CLI and Apache differ, correct the XAMPP installation/configuration before continuing.

A PHP runtime guard cannot patch an old PHP binary. Install an updated supported XAMPP/PHP package if either runtime is rejected.

## 4. Check migrations without touching the original database

First inspect the configured database name in the private local config. Then run:

```bat
C:\xampp\php\php.exe tools\migrate.php status
C:\xampp\php\php.exe tools\schema_check.php
```

The status command is read-only. If there are pending migrations, back up `barangay_db_work` and restore that backup to a separate scratch database before rehearsing the upgrade.

**Important migration 013 pre-check:** before applying the hearing uniqueness guard to an existing working database, run this query in phpMyAdmin against the working copy:

```sql
SELECT blotter_id, scheduled_at, status, COUNT(*) AS total
FROM hearings
GROUP BY blotter_id, scheduled_at, status
HAVING COUNT(*) > 1;
```

If it returns rows, stop. Have the records reviewed and resolved by an authorized person. Do not delete hearing history simply to make the migration succeed.

Once the working-copy backup and duplicate check are complete, apply pending migrations:

```bat
C:\xampp\php\php.exe tools\migrate.php up
C:\xampp\php\php.exe tools\schema_check.php
```

The schema checker should report no differences. If migrations or the schema check fail, retain the complete output and stop; do not continue with role or kiosk tests on a partially migrated database.

## 5. Run the automated suites on XAMPP

Keep Apache and MySQL running. From the project folder, create a place to retain reports:

```bat
mkdir test-reports
```

Run the security/authentication/audit suite, including the Apache private-folder check:

```bat
C:\xampp\php\php.exe tests\run_tests.php http://localhost/Birms-main/public --root=http://localhost/Birms-main > test-reports\security-tests.log 2>&1
set SECURITY_EXIT=%ERRORLEVEL%
type test-reports\security-tests.log
echo Security test exit code: %SECURITY_EXIT%
```

Then run all module integration tests:

```bat
C:\xampp\php\php.exe tests\run_modules.php http://localhost/Birms-main/public > test-reports\module-tests.log 2>&1
set MODULE_EXIT=%ERRORLEVEL%
type test-reports\module-tests.log
echo Module test exit code: %MODULE_EXIT%
```

Expected result: **FAIL 0 and NOT TESTED 0** in both reports. A non-zero exit code is a failed test run. Read the individual failed test IDs and diagnostic messages printed at the end of the report. The test runners now preserve failed assertions, print a separate failure list, report untested checks, and return a failing exit code. If a module throws an exception, the module runner records a module-level FAIL plus a NOT TESTED remainder marker and continues with later modules.

The module runner refuses to start while real email delivery is enabled. Disable email first; do not bypass this safety check. The current candidate does not send SMS. Do not remove a failing test, change it to SKIP, or lower the expected result to force a green run. Fix the underlying cause, keep the regression test, and rerun both suites.

The full CI suite uses the same runners and also uploads `security-tests.log` and `module-tests.log` as GitHub Actions artifacts (including when tests fail). Historical failed runs remain visible in the repository's Actions history.

## 6. Manual role acceptance — use separate browser sessions

Create separate accounts in the MSIT working database for Admin, Chairman, Secretary and Staff. Use separate browser profiles or private windows so sessions do not overwrite each other. Use synthetic records only.

| Role | Actions that should work | Actions that must be denied |
|---|---|---|
| **Admin** | Manage accounts/roles, reference data, Puroks, residents, venues, signature asset, system settings, backups, reports and full records | None of the in-scope administrative functions |
| **Chairman / Punong Barangay** | View resident/request/blotter/hearing/venue/queue records, view/export reports, view the cash ledger; the stored signature is applied automatically to eligible certificates | Approve/reject requests, edit residents or blotters, manage queues/venues/settings/users, issue/print certificates as an operational action |
| **Secretary** | View residents; intake/edit document requests and complete requirements review; maintain permitted blotter/hearing records; edit certificate wording; toggle room/venue availability; view reports | Final document approval, final certificate issuance, venue-reservation approval, queue management, account/general-settings administration |
| **Staff** | Process and approve/reject document requests, review automatically generated certificate drafts/PDF previews and release eligible certificates, review resident blotter submissions, manage venue reservations and queues, use the All Services Tracker | User/account administration, system settings, unrestricted audit administration, backup configuration |
| **Resident** | Use the shared kiosk/QR; verify identity with Resident ID and birthdate (or the registered-email one-time-code fallback); request a document, file/follow up a blotter report, request a venue, get a queue number and track only their own requests; receive email notices only when an address is registered | Reach staff/admin pages or view another resident's private record |

For every role, test both the visible menu and a direct URL/POST to a forbidden action. A hidden button alone is not proof of authorization; the server must return a denial and must not change data. Record the URL, role, expected result, actual HTTP/result, and evidence for each denied action.

The automated role tests create Admin, Chairman, Secretary and Staff sessions, verify their dashboards and permissions, test restricted actions, and crawl each role's sidebar links. Manual browser testing is still required on XAMPP.

## 7. Resident kiosk and request journey

Use a synthetic resident with a unique Resident ID, birthdate and reserved `.invalid` email address. Keep real email delivery disabled while testing. A mobile number is not required for the email-only workflow.

1. Open the shared document-request QR entry URL from the local server. Do not use a QR that encodes personal resident information.
2. Verify the resident with Resident ID and birthdate. Test the name-and-birthdate fallback only with a controlled test email account; the one-time code is sent to the registered email address. There is no SMS fallback.
3. Open the queue, document request, blotter report and venue reservation kiosk forms. Confirm the notification choice is Email only and that no SMS or Both option is offered.
4. Submit one test queue ticket and one request of each supported type. Confirm a unique reference and the correct service queue number are shown.
5. Confirm the Email preference is saved to the resident and queue ticket. Verify that called and next-in-line alerts use email only when a valid registered address exists; an outbox/queued status is not proof of delivery.
6. Confirm the public queue screen shows service and current queue number but no resident-identifying information.
7. Leave the kiosk untouched and verify it returns to the resident home screen after the inactivity timeout. Confirm that the resident flow cannot access Admin/Staff routes.
8. Verify timeout submits the CSRF-protected POST logout form, not GET. Confirm it clears the resident identity, OTP token, flash message and all saved queue/request/venue/blotter receipt data, and that a second resident using the same browser cannot see the previous resident's receipt.
9. Test the request tracker using the reference number. Confirm document, blotter and venue statuses are shown according to their respective workflows.

Physical touchscreen sizing, kiosk inactivity behavior, the actual QR scan from a phone, and local-network access are **NOT RUN** until tested on the target equipment.

## 8. Venue, blotter, certificates and reports

- **Venue:** create a synthetic reservation, approve it as Staff, verify the venue status turns red while active and green when available, and attempt an overlapping reservation. The overlap must be rejected without saving a second reservation. Test manual override and completion.
- **Blotter:** submit a resident report; review it as Staff; verify acceptance creates one official case; update it through Ongoing, Unresolved, Resolved, Dismissed and Archived according to the workflow. Verify history/audit data and confirm there is no delete action for official blotter records. Resolved and dismissed cases must be archived, not deleted.
- **Documents/certificates:** submit a document request and complete the requirements review as Secretary. Approve it as Staff. Confirm approval automatically creates a saved certificate draft with a reserved certificate reference, resident/document wording, fee snapshot and the required Chairman signature. Open the protected PDF draft as Staff/Admin; confirm it is marked **DRAFT — NOT ISSUED**. Verify Secretary and Chairman receive a denial on the draft-PDF URL. Before Staff releases it, confirm there is no issuance row, O.R. number, cash-ledger entry, print count or Released status. Then release the certificate as Staff and confirm the final issuance reuses the saved reference, wording, fee and signature snapshot, creates the appropriate cash-ledger entry for a paid document, and moves the request to Released. The current layout is still a draft until the barangay approves the official wording, fees and layout.
- **Reports:** compare resident/Purok/type totals, documents, blotter statuses, venue usage and queue statistics against the same working database. Test PDF/Excel-compatible exports with Chairman and Secretary; confirm Staff access follows the permission map.

Do not describe a draft certificate as an official issued barangay certificate. Do not claim that an email was delivered because a notification only appears in an outbox or is labelled queued.

## 9. Network, performance, mail and restore acceptance

These require the actual target PC and/or approved barangay services:

- **Phone/LAN QR:** connect a phone to the same barangay LAN and open the QR URL using the LAN-reachable server address, not `localhost`. Verify the flow from the phone.
- **Offline network:** disconnect internet access while keeping the local network/server running. Verify residents and staff can still use the local application and local database functions.
- **Email:** only after approval, configure the barangay-approved sender/relay and send a controlled test to a test mailbox. Verify actual receipt and delivery status.
- **SMS:** not available in this candidate. SMS provider adapters and sending workflows were removed; historical SMS tables/records remain for compatibility and audit. Do not configure an SMS key or claim SMS delivery.
- **Performance:** use representative synthetic data and `tools/benchmark.php`; record the measured page/operation and time. The 3-second NFR is not verified until measurements are captured.
- **Full-system backup/restore:** enable ZIP in both CLI PHP and Apache. During a quiet maintenance window, create a complete ZIP backup. Verify its manifest, database SQL, `storage/private_uploads/` attachments and Chairman signature images, and `storage/resident_photos/` resident photos. Restore it only in a separate scratch application folder with its own scratch database and storage directory. Confirm resident photos, request attachments, current Chairman signature and historical issuance signature snapshots are available. Confirm the restored DB has no missing private-file references and inspect the application error log. Never test restoration against the source or original database.
- **Legacy SQL-only restore:** test only when explicitly needed. It restores database records but not private files; it is not a complete system backup.
- **Full restore behavior:** the ZIP restore validates paths and SHA-256 checksums, installs missing private files, refuses different files already present at the same path, and does not bulk-delete existing private files. Unreferenced older files may remain and require separate reviewed cleanup. Scheduled backups are not implemented.
- **Print/PDF:** open the PDF with the office's PDF reader and test the intended printer/paper layout. Record any clipped fields or layout changes.

## 10. How to report results — do not hide failures

Use these exact result labels:

- **PASS:** the expected result was observed and evidence was saved.
- **FAIL:** the observed result differs from the expected result, an exception occurred, data changed incorrectly, or an access control was bypassed.
- **NOT RUN:** the step was not performed on the target XAMPP PC/device/service.
- **NOT TESTED:** the automated runner explicitly reports that a check could not execute.

For every FAIL, preserve the test ID, complete output line, diagnostic, role, URL/action, database migration state and relevant screenshot/log. Do not delete the test or mark it NOT RUN to clear a red result. Fix the code or environment, retain the regression test and rerun the full suites.

Never report this system as flawless based on CI alone. Release acceptance requires zero failed tests, no unexplained NOT TESTED checks, and completed manual evidence for the target XAMPP, roles, kiosk, LAN, delivery providers, PDF/print and restore.


## 11. Prepare the MSIT demonstration dataset and document templates

Use the isolated working database only. The seeder refuses database names that do not end in _work or _test; never point it at the original barangay_db or a production database.

From the BIRMS project folder, first review the read-only plan:

~~~bat
C:\xampp\php\php.exe tools\seed_msit_demo_data.php
~~~

If the target shown is barangay_db_work and the plan is correct, insert the synthetic presentation records:

~~~bat
C:\xampp\php\php.exe tools\seed_msit_demo_data.php --apply
~~~

The seeder is idempotent: running it again after a complete seed reports that the demo data is already present. If it finds a partial reserved demo set, it refuses to add more rows rather than guessing how to repair it.

The sample set contains:
- 6 fictitious residents (DEMO-RES-0001 to DEMO-RES-0006) with reserved .invalid email addresses and no mobile numbers;
- 3 clearly labelled demo-only certificate templates (Barangay Clearance, Residency and Indigency), with zero demonstration fee and no Chairman signature requirement;
- 4 document requests: Pending, Approved with a saved unissued certificate draft, Rejected and Released with one sample issuance-history record;
- 3 fictitious blotter cases, 2 demo venues, 2 venue reservations and 3 service-queue tickets.

All generated records and document bodies say **DEMO ONLY**. No official document type, fee, barangay setting, account, password, email setting or real resident is changed. The .invalid email addresses cannot receive mail. The seeder does not send email or SMS.

### Edit and preview certificate templates

1. Sign in as an Admin or Secretary account.
2. Open **System Settings → Documents and fees**. The Secretary can edit document wording only; fees, template availability, signature requirements and other system settings remain Admin-only.
3. Open one of the **DEMO ONLY - ...** document types.
4. Use the toolbar for bold, italic, underline, headings, bullets, numbering, dividers and resident/request placeholders.
5. Review the live preview, then save.
6. Open **Document Requests** and review the seeded sample requests. The Approved sample has a saved certificate draft that Staff can preview and release.
7. Open **Certificate Generation** or **Issuance History** to review the generated output. Print/PDF content remains clearly marked as draft/demo; do not represent it as an approved barangay certificate.

The editor intentionally supports a small, safe formatting set rather than attempting to replace Microsoft Word. It edits the template text used by BIRMS certificate generation. The generated certificate draft is a snapshot: changes made to a template later do not rewrite an already-created draft or issued record.

### Demo accounts and role journey

The seeder deliberately creates **no accounts and no default passwords**. Create separate Admin, Chairman, Secretary and Staff accounts through the User Accounts page and use separate browser profiles when presenting. The Secretary can edit document wording but cannot change fees, template availability, signature requirements or general system settings. The Chairman remains read-only. Residents use the shared kiosk/request QR flow.

Before the presentation, confirm the demo dashboard, request tracker, certificate preview, print/PDF, blotter, venue availability and service queues on the actual XAMPP browser. Automated CI is not a substitute for this local check.
