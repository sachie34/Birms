# BIRMS working copy: install and run on XAMPP

This runbook points to the validated revised-MSIT demonstration candidate **`john/phase2-kiosk-no-sms`** (draft PR #39). For the revised resident, blotter, documents, venue, queue and kiosk workflows, use this branch and [MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md), not the older B1–B12 `main` presentation build or the earlier MSIT baseline branch. Setup and test tools target a separate `barangay_db_work` or `_test` database. Never point them at the original `barangay_db`.
It never touches your original `barangay_db`.

**Important:** this wizard provisions the isolated `barangay_db_work` working/demo database. It is not the production database procedure. For a real office deployment, follow [PRODUCTION_DEPLOYMENT_RUNBOOK.md](PRODUCTION_DEPLOYMENT_RUNBOOK.md), which provisions a separate production database and requires HTTPS, least-privilege access and go-live evidence.

> **MSIT acceptance status:** the revised candidate's exact-head CI evidence is linked from [PR #39](https://github.com/sachie34/Birms/pull/39) and [panel demo status](PANEL_DEMO_STATUS.md). Automated CI is not target-PC XAMPP/browser UAT. Follow [MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md) and mark manual steps PASS only after testing them on the actual office PC/device.

## Quick install (recommended)

1. Download the [validated revised-MSIT candidate ZIP](https://github.com/sachie34/Birms/archive/refs/heads/john/phase2-kiosk-no-sms.zip), extract the whole folder under `C:\xampp\htdocs\`, and rename the extracted folder to `Birms-main` (or use your own folder name consistently). Do not move only the `public` folder; the app, migrations, tests and private storage folders are needed.
2. Start Apache in the XAMPP Control Panel. Start the MySQL/MariaDB server you intend to use. The installer defaults to `127.0.0.1:3306`; use only one server on that port. If MySQL Community Server is already listening on port 3306, do not try to start XAMPP's MySQL service on the same port.
3. Double-click `SETUP_XAMPP.bat` in the extracted project folder. Enter the local MySQL root password when asked (a fresh XAMPP MySQL installation commonly has a blank password). Then choose a local administrator username, name and password.
4. The setup wizard creates `barangay_db_work`, creates/updates the limited `birms_app` account for that database, applies the available migrations (001–021), writes the private `app/config.local.php`, and asks you to create the first administrator. It does not import the original `barangay_db` or invent official Puroks, fees, certificate wording or signatories.
5. Open the localhost URL printed by the installer; it will reflect the folder name you extracted. Sign in with the administrator you created.
6. Before presenting, follow [PRESENTATION_RUNBOOK.md](PRESENTATION_RUNBOOK.md). The installer is a setup helper, not proof that the target browser/printer works. Complete the automated and manual checks below.

The wizard refuses a pre-existing non-empty `barangay_db_work` without migration history. It will not overwrite a manually created `app/config.local.php`; if its own generated config exists after an interrupted setup, it can resume using that local configuration.

## Repair an incomplete or outdated local project folder

If every module displays a PHP error saying `app/bootstrap.php`, `app/core.php`, or another required include cannot be found, the local folder is incomplete or contains files from different repository versions. The `john/phase2-kiosk-no-sms` candidate branch includes these files. Do not try to fix every page separately; they share the same bootstrap and application libraries.

1. Back up the current project folder. Keep your MySQL database as it is.
2. Download a fresh ZIP from the [validated MSIT candidate branch](https://github.com/sachie34/Birms/archive/refs/heads/john/phase2-kiosk-no-sms.zip) and extract it into a **new folder**, for example `C:\xampp\htdocs\Birms-main-fixed`. Do not extract over the old folder.
3. From the old project, copy only the private local configuration `app\config.local.php` into the matching `app` folder in the new copy. Do **not** copy the old `app` folder over the new one. Preserve the required private data from the backup, including `storage\private_uploads`, `storage\resident_photos` and Chairman signature assets where present. Do not copy obsolete SMS-provider configuration or the old root-level `Api` file.
4. Open Command Prompt and run these checks from the **new** project path:
   ```bat
   cd /d C:\xampp\htdocs\Birms-main-fixed
   C:\xampp\php\php.exe tools\check_php_includes.php
   C:\xampp\php\php.exe tools\setup_xampp.php --check
   ```
   Both should finish successfully. The include check is read-only: it checks that required source files exist and that statically referenced local include paths resolve.
5. Open `http://localhost/Birms-main-fixed/public/`. Use your existing Administrator account if `app\config.local.php` still points to the same `barangay_db_work` database. Do not run `SETUP_XAMPP.bat` again just to replace missing source files, and do not drop/recreate the database.
6. If the local config has a manually set `base_url` pointing to the old folder, update it to `/Birms-main-fixed/public`; otherwise leave an auto-detected/null base URL unchanged. The revised candidate has no SMS provider. Do not copy or configure an Infobip/SMS key.

This process fixes a missing/mixed local source tree without replacing your database. It does not prove that all interactive flows work; complete the browser/print tests and automated test suite below.


**Credentials warning:** BIRMS no longer includes the old root-level `Api` file. It was not used by application code and contained an unexplained credential-looking value. If you are extracting this ZIP over an older copy of the project, delete that old `Api` file manually. If it held a real API key/token, revoke and replace it because Git history retains earlier committed versions.

## 1. Requirements
- XAMPP with a supported, security-patched PHP runtime. As of 19 November 2026, minimum accepted versions are PHP 8.3.37, 8.4.28, 8.5.13 or 8.6.0; PHP 8.2.36 is accepted only through 31 December 2026 and upgrading to PHP 8.3 or newer is recommended. Verify both CLI PHP and Apache's loaded PHP. See [PHP supported versions](https://www.php.net/supported-versions.php).
- PHP extensions: `pdo_mysql` and `fileinfo` (required); `curl` for the automated HTTP tests; `mbstring` is optional (the application has a fallback).
- Keep BIRMS on the trusted local office network. **Do not expose it to the public internet.**

## 2. Install manually (advanced)
1. **Back up first** (see section 7). Never skip this.
2. Copy this folder to `C:\xampp\htdocs\Birms-main`.
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Create a limited database user (phpMyAdmin → SQL tab). Choose your own password:
   ```sql
   CREATE USER 'birms_app'@'localhost' IDENTIFIED BY 'your-own-password-here';
   GRANT SELECT, INSERT, UPDATE, DELETE ON `barangay_db_work`.* TO 'birms_app'@'localhost';
   ```
5. Copy `app\config.local.php.example` to `app\config.local.php` and put the same password in it.
6. Open **XAMPP Shell** (or Command Prompt) and run:
   ```
   cd C:\xampp\htdocs\Birms-main
   C:\xampp\php\php.exe tools\migrate.php status
   C:\xampp\php\php.exe tools\migrate.php up
   ```
   On this candidate the migration runner applies the numbered migrations through 021, including the MSIT resident/Purok foundation, queue/kiosk, venue/reservation, request history, notification outbox, blotter no-delete protections and reporting/directory indexes. Back up the working database first. Before migration 013, inspect duplicate hearing slots as shown in [MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md).
   The migration tool needs permission to create the database and tables. If `birms_app` is refused, run this one step
   with `root` by setting `BIRMS_DB_USER=root` for that command (`set BIRMS_DB_USER=root` then the command), then give
   `birms_app` only the SELECT/INSERT/UPDATE/DELETE rights above.
7. Create the first administrator (there is **no default account or password**):
   ```
   C:\xampp\php\php.exe tools\create_admin.php --username=admin --name="Your Full Name"
   ```
   Type a password when asked (it is visible on Windows). Minimum 10 characters, with a letter and a number.
8. Open `http://localhost/Birms-main/public/` and sign in.
9. Go to **Fees and Puroks** (Settings) and enter the official details **yourself**: Captain and Secretary names, the Purok list, each document's approved wording, and the email notification sender. They are blank on purpose. For fees, either set the per-document fee or enter formally approved rules under **Multi-tier fee matrix**. No fee rates are preloaded. If an active matrix rule applies to a document type, a new request must select an approved category/tier and valid quantity; the saved quote is carried onto the issuance. Paid certificates still require a receipt/O.R. number at issuance. Certificates cannot be issued until required wording and any referenced official/household details are available.
   Hearing summons work the same way: the Secretary enters approved text under **Settings → Hearing summons**; a summons cannot be printed until then.

## 3. Your existing database (`barangay_db`)
- `tools\migrate.php` **refuses** to run on any database that already has tables but no `schema_migrations` table,
  and on any name not ending `_work` or `_test` unless you add `--i-have-a-backup`. This protects your data.
- **Compare your real database safely (read-only, it never writes and reads no resident data):**
  ```
  C:\xampp\php\php.exe tools\schema_check.php --db=barangay_db
  ```
  It lists missing tables, missing or different columns, indexes and foreign keys. Send me the output. In my test against a
  database shaped like your pasted script it reported 59 differences and changed nothing.
- Migration scripts for your real schema are written only after I have seen that report. Nothing is applied to your original database.

## 4. Accounts and roles
Four operational roles: Admin, Chairman / Punong Barangay, Secretary and Staff. Existing database role IDs `captain` and `clerk` are retained internally and map to Chairman and Staff for account compatibility. Admin handles technical administration; Chairman is read-only; Secretary reviews document requirements, maintains permitted blotter/hearing records and controls room availability; Staff makes final request decisions, releases certificates and manages reservations/queues. The revised permission map is enforced server-side; obtain system-owner approval before production use.
- New users get a temporary password and must change it at first sign-in.
- Forgot password: the Administrator sets a new temporary password (User Accounts → Manage).
- If the Administrator is locked out: `C:\xampp\php\php.exe tools\create_admin.php --reset=username`.
- Five wrong passwords lock that account for about 15 minutes. The Administrator can unlock it.
- Sessions end after 30 idle minutes (setting `session.idle_minutes`).

## 5. HTTP and HTTPS
For a single-PC localhost demonstration, use the local XAMPP URL. Do not expose plain HTTP over the barangay Wi-Fi/LAN: credentials and resident records must be protected with trusted HTTPS before phones or other PCs connect. For production, enable the HTTPS requirement in the private configuration and follow the production deployment runbook. Restrict the firewall to the authorized local network; do not forward router ports to this PC.

## 6. Run the tests on YOUR XAMPP
```
cd C:\xampp\htdocs\Birms-main
C:\xampp\php\php.exe tests\run_tests.php http://localhost/Birms-main/public --root=http://localhost/Birms-main
C:\xampp\php\php.exe tests\run_modules.php http://localhost/Birms-main/public
```
- `run_tests.php` = security/authentication/audit, four-role access matrix, PHP runtime checks, safe CLI password prompt and failure-reporting checks. `run_modules.php` = all module integration suites B2–B22, including revised resident records, independent queues, kiosk, blotter, venue, notifications, report exports and unified tracker. Disable real email before testing.
- If a run is interrupted it can leave test rows behind (names starting `ZZ`). Rebuild the **test** database
  (drop `barangay_db_work`, run `migrate.php up` again) before re-running.
- The module runner refuses to run while email notifications are enabled, because test flows create synthetic notifications. Disable email before testing.
- Expect `FAIL 0`. `NOT TESTED` means a check could not run. Send me the full output, including any FAIL lines.
- The `--root` check confirms private folders are not downloadable through Apache. `app`, `storage`, and other project folders use `.htaccess` deny rules; the PHP built-in server ignores `.htaccess`, so run that particular protection check under Apache on XAMPP.
  It depends on Apache's `AllowOverride All` (the XAMPP default). Under Apache, also verify that these URLs return 403/404: `http://localhost/Birms-main/.git/config` (if using a Git clone), `http://localhost/Birms-main/README.md`, `http://localhost/Birms-main/MANIFEST.txt`, `http://localhost/Birms-main/SETUP_XAMPP.bat`, `http://localhost/Birms-main/.gitignore`, and `http://localhost/Birms-main/Api`. The last URL should remain blocked even if the file exists in an older extracted folder.

## 7. Backup before any change
```
cd C:\xampp\mysql\bin
mysqldump -u root -p --routines --triggers --events --single-transaction barangay_db_work > D:\BIRMS_BACKUP\barangay_db_work_FULL.sql
```
Do not add `--databases`. Test the restore into a scratch database. Copy the project folder too.

## 8. Known limitations
- **Implemented on the revised-MSIT candidate; pending target-PC acceptance:** revised resident/Purok/status/type records; documents and certificates; blotter request and case workflows; venue reservations and overlap protection; kiosk/QR identity and request paths; separate daily queues; email-only notification/outbox logic; Chairman signature snapshot; reports and the All Services Tracker. The complete checklist is in [MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md).
- The current migration set runs through 021; use `tools\migrate.php status` to identify any pending migrations on the working copy. Enter the barangay-approved employment-status and incident-type categories under Settings → Other lists and approved fee rules under Settings → Multi-tier fee matrix. No categories or rates are guessed.
- Resident identity and voter-list checks can be recorded manually in the resident profile with actor/date/history. The system does not query COMELEC. The separate cash-collection ledger records staff-entered amounts and reconciliation state; this is not a payment gateway and not independent proof of a bank deposit. Issuance history totals use ledger entries and label missing entries.
- The candidate supports local SQL export and complete ZIP backups that include private files. Optional HTTPS WebDAV upload can be configured, but a live destination upload and successful full restore are not verified here; scheduled backup jobs are not implemented. Rehearse restore only in a separate scratch application/database/storage location.
- Email delivery is disabled unless explicitly configured. Resident notifications use email only; legacy SMS outbox records and migrations remain for audit compatibility, but no SMS provider is active. CI checks the email outbox; actual mailbox delivery must still be tested on the local system.
- Blotter lookup shows matching case-party information only to authorized roles. The revised role map is implemented in code and must still receive system-owner approval before production.
- Visitor and request attachments accept PDF/JPG/PNG up to 10 MB, are stored in `storage/private_uploads` with randomized names (outside `public/`), and are served only through an authenticated, permission-checked download endpoint. Confirm the folder is writable by Apache and not served directly by the web server.
- Paid certificate issuance records a Pending item in the cash-collection ledger. Ledger management is Administrator-only; reconciled entries should be supported by the office's approved evidence process.
- Certificate PDF download is server-generated. Dompdf is used when installed; an offline-compatible built-in PDF writer is the fallback. The output is prominently marked **DRAFT — PENDING OFFICIAL LAYOUT APPROVAL**. Typed signatory text is not a cryptographic digital signature.
- Official facts are blank by design (see step 9). Certificate letterhead and layout are a **provisional default** until the Barangay Secretary approves them.
- **Target-PC XAMPP/browser UAT is NOT RUN from this repository audit.** Complete the role journeys, phone QR/LAN test, physical kiosk, real email, approved certificate/PDF/print and backup/restore tests in [MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md).
- To collect evidence for the paper's retrieval-speed objective, run `C:\\xampp\\php\\php.exe tools\\benchmark.php --iterations=10` against `barangay_db_work`. Compare the same task against the paper-based process using the worksheet in `docs/UAT_PERFORMANCE_EVALUATION.md`. Do not report benchmark or manual timings until actually measured.
- Compatibility with the exact PHP/Apache build on the target XAMPP PC remains unverified until both runtimes pass the setup preflight and the local acceptance steps are completed.

## 9. Troubleshooting
- **Blank page or "Something went wrong":** read `storage\logs\php-error.log`.
- **Cannot connect to the database:** check `app\config.local.php` and that MySQL is running.
- **Links or styles broken:** set `'base_url' => '/Birms-main/public'` in `config.local.php` (or use the actual folder name under `htdocs`).
- **Plain unstyled page:** the browser may be blocking assets; press F12 → Console and send me the messages.


## 10. Load the MSIT demonstration documents and sample records

The official document types remain blank until the barangay approves their wording, fees, availability and Chairman-signature policy. For a presentation, load the separate synthetic dataset into the isolated working database only.

From the project folder, review the read-only plan:

~~~bat
C:\xampp\php\php.exe tools\seed_msit_demo_data.php
~~~

Confirm that the target is barangay_db_work, then apply:

~~~bat
C:\xampp\php\php.exe tools\seed_msit_demo_data.php --apply
~~~

The seeder refuses database names that do not end in _work or _test. It does not create users/passwords, send email, or change official document types/settings. It inserts fictitious residents, three DEMO ONLY certificate templates, request lifecycle examples, blotter cases, venues, reservations and queue tickets. See [DEMO_DATA.md](DEMO_DATA.md) for the full list and presentation checks.

### Edit the demonstration templates

Open **System Settings → Documents and fees** as Admin or Secretary. The built-in template editor supports bold, italic, underline, headings, bullets, numbering, dividers, resident/request placeholders and a live preview. Formatting is carried into certificate print/PDF output.

The Secretary can edit template wording only. The Admin controls document fees, template availability and signature requirements. The Chairman remains read-only. Every sample document is clearly marked **DEMO ONLY — NOT AN OFFICIAL DOCUMENT**. Do not use the sample wording or zero demo fee as an official barangay certificate or fee schedule.

The seeder deliberately creates no accounts. Use the User Accounts page to create separate role accounts with strong passwords before testing the Admin, Chairman, Secretary and Staff journeys.
