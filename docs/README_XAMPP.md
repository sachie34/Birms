# BIRMS working copy: install and run on XAMPP

Barangay Wawa, Balagtas, Bulacan. The **current main branch** contains the B1–B12 presentation build, data-derived reports/dashboard, request requirements review, fee matrix/cash ledger, manual resident verification history, private attachments/PDF output, global record search, local SQL export, demo-data seeder, resident CSV import, and optional Semaphore/Infobip SMS integrations.
It never touches your original `barangay_db`.

> **Latest verified CI:** [GitHub Actions run](https://github.com/sachie34/Birms/actions/runs/39861937756) passed PHP syntax validation, the repository-wide PHP include-path integrity check, setup preflight, migrations 001–012 on disposable MariaDB 10.11, **37/37 authentication/security/audit checks**, and **173/173 module integration checks** (0 failures, 0 untested). Module tests include reports, backup access, attachments, generated PDF, cash ledger, CSV import, demo-data seeding, and mocked Semaphore/Infobip provider checks. This is automated CI evidence only. Target-office XAMPP/browser UAT, actual printer/PDF-reader behavior, official settings approval, live SMS delivery, live cloud transfer, and successful restore still require separate local verification.

## Quick install (recommended)

1. Download the [one-click BIRMS ZIP from main](https://github.com/sachie34/Birms/archive/refs/heads/main.zip), extract the whole folder under `C:\xampp\htdocs\`, and rename the extracted folder to `birms_work`. Do not move only the `public` folder; the app, migrations, tests and private storage folders are needed. Renaming to `birms_work` makes the commands below work as written.
2. Start Apache in the XAMPP Control Panel. Start the MySQL/MariaDB server you intend to use. The installer defaults to `127.0.0.1:3306`; use only one server on that port. If MySQL Community Server is already listening on port 3306, do not try to start XAMPP's MySQL service on the same port.
3. Double-click `SETUP_XAMPP.bat` in the extracted project folder. Enter the local MySQL root password when asked (a fresh XAMPP MySQL installation commonly has a blank password). Then choose a local administrator username, name and password.
4. The setup wizard creates `barangay_db_work`, creates/updates the limited `birms_app` account for that database, applies migrations 001-012, writes the private `app/config.local.php`, and asks you to create the first administrator. It does not import the original `barangay_db` and does not seed official fees, certificate wording, Puroks or signatories.
5. Open the localhost URL printed by the installer; it will reflect the folder name you extracted. Sign in with the administrator you created.
6. Before presenting, follow [PRESENTATION_RUNBOOK.md](PRESENTATION_RUNBOOK.md). The installer is a setup helper, not proof that the target browser/printer works. Complete the automated and manual checks below.

The wizard refuses a pre-existing non-empty `barangay_db_work` without migration history. It will not overwrite a manually created `app/config.local.php`; if its own generated config exists after an interrupted setup, it can resume using that local configuration.

## Repair an incomplete or outdated local project folder

If every module displays a PHP error saying `app/bootstrap.php`, `app/core.php`, or another required include cannot be found, the local folder is incomplete or contains files from different repository versions. The current `main` branch includes these files. Do not try to fix every page separately; they share the same bootstrap and application libraries.

1. Back up the current project folder. Keep your MySQL database as it is.
2. Download a fresh ZIP from the [current main branch](https://github.com/sachie34/Birms/archive/refs/heads/main.zip) and extract it into a **new folder**, for example `C:\\xampp\\htdocs\\Birms-main-fixed`. Do not extract over the old folder.
3. From the old project, copy only the private local files that exist: `app\\config.local.php` and `app\\infobip.local.php`, placing each into the matching `app` folder in the new copy. Do **not** copy the old `app` folder over the new one. Preserve `storage\\private_uploads` and any backups you need. Never copy or restore the old root-level `Api` file.
4. Open Command Prompt and run these checks from the **new** project path:
   ```bat
   cd /d C:\\xampp\\htdocs\\Birms-main-fixed
   C:\\xampp\\php\\php.exe tools\\check_php_includes.php
   C:\\xampp\\php\\php.exe tools\\setup_xampp.php --check
   ```
   Both should finish successfully. The include check is read-only: it checks that required source files exist and that statically referenced local include paths resolve.
5. Open `http://localhost/Birms-main-fixed/public/`. Use your existing Administrator account if `app\\config.local.php` still points to the same `barangay_db_work` database. Do not run `SETUP_XAMPP.bat` again just to replace missing source files, and do not drop/recreate the database.
6. If the local config has a manually set `base_url` pointing to the old folder, update it to `/Birms-main-fixed/public`; otherwise leave an auto-detected/null base URL unchanged. Keep the Infobip key only in the private `app\\infobip.local.php` file.

This process fixes a missing/mixed local source tree without replacing your database. It does not prove that all interactive flows work; complete the browser/print tests and automated test suite below.


**Credentials warning:** BIRMS no longer includes the old root-level `Api` file. It was not used by application code and contained an unexplained credential-looking value. If you are extracting this ZIP over an older copy of the project, delete that old `Api` file manually. If it held a real API key/token, revoke and replace it because Git history retains earlier committed versions.

## 1. Requirements
- XAMPP with **PHP 8.1 or newer** and a local MySQL/MariaDB server (MySQL 5.7+ or MariaDB 10.3+). Check PHP with `C:\xampp\php\php.exe -v`.
- PHP extensions: `pdo_mysql` and `fileinfo` (required); `curl` for the automated HTTP tests; `mbstring` is optional (the application has a fallback).
- Keep BIRMS on the trusted local office network. **Do not expose it to the public internet.**

## 2. Install manually (advanced)
1. **Back up first** (see section 7). Never skip this.
2. Copy this folder to `C:\xampp\htdocs\birms_work`.
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Create a limited database user (phpMyAdmin → SQL tab). Choose your own password:
   ```sql
   CREATE USER 'birms_app'@'localhost' IDENTIFIED BY 'your-own-password-here';
   GRANT SELECT, INSERT, UPDATE, DELETE ON `barangay_db_work`.* TO 'birms_app'@'localhost';
   ```
5. Copy `app\config.local.php.example` to `app\config.local.php` and put the same password in it.
6. Open **XAMPP Shell** (or Command Prompt) and run:
   ```
   cd C:\xampp\htdocs\birms_work
   C:\xampp\php\php.exe tools\migrate.php status
   C:\xampp\php\php.exe tools\migrate.php up
   ```
   This applies migrations 001 to 012 to the new, empty `barangay_db_work` database. Migration 007 adds resident employment status and incident type fields for reports; migration 008 adds request requirements status/notes; migration 009 adds the configurable fee matrix and quote/issuance snapshots; migration 010 adds a manual resident identity/voter verification history; migration 012 adds optional SMS-provider tracking for Semaphore and Infobip. See [SEMAPHORE_SMS.md](SEMAPHORE_SMS.md) before enabling the provider.
   The migration tool needs permission to create the database and tables. If `birms_app` is refused, run this one step
   with `root` by setting `BIRMS_DB_USER=root` for that command (`set BIRMS_DB_USER=root` then the command), then give
   `birms_app` only the SELECT/INSERT/UPDATE/DELETE rights above.
7. Create the first administrator (there is **no default account or password**):
   ```
   C:\xampp\php\php.exe tools\create_admin.php --username=admin --name="Your Full Name"
   ```
   Type a password when asked (it is visible on Windows). Minimum 10 characters, with a letter and a number.
8. Open `http://localhost/birms_work/public/` and sign in.
9. Go to **Fees and Puroks** (Settings) and enter the official details **yourself**: Captain and Secretary names, the Purok list, each document's approved wording, and SMS text. They are blank on purpose. For fees, either set the per-document fee or enter formally approved rules under **Multi-tier fee matrix**. No fee rates are preloaded. If an active matrix rule applies to a document type, a new request must select an approved category/tier and valid quantity; the saved quote is carried onto the issuance. Paid certificates still require a receipt/O.R. number at issuance. Certificates cannot be issued until required wording and any referenced official/household details are available.
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
Four roles: Administrator, Barangay Captain, Barangay Secretary, Barangay Staff/Clerk.
In the merged presentation build, the Administrator has all currently defined module permissions plus account, settings, audit and backup access, matching the intended highest-privilege role. Captain, Secretary and Clerk use the existing **PROVISIONAL** role map until the Barangay Secretary/system owner approves the final matrix. The map must still be validated against the approved paper and office responsibilities.
- New users get a temporary password and must change it at first sign-in.
- Forgot password: the Administrator sets a new temporary password (User Accounts → Manage).
- If the Administrator is locked out: `C:\xampp\php\php.exe tools\create_admin.php --reset=username`.
- Five wrong passwords lock that account for about 15 minutes. The Administrator can unlock it.
- Sessions end after 30 idle minutes (setting `session.idle_minutes`).

## 5. HTTP and HTTPS
This version works over plain HTTP, which is acceptable for a trusted wired office network but not ideal because
passwords cross the network unencrypted. HTTPS is **still to be decided**. When HTTPS is on, secure cookies switch on
automatically. Keep the firewall limited to your office network and do not forward any router port to this PC.

## 6. Run the tests on YOUR XAMPP
```
cd C:\xampp\htdocs\birms_work
C:\xampp\php\php.exe tests\run_tests.php http://localhost/birms_work/public --root=http://localhost/birms_work
C:\xampp\php\php.exe tests\run_modules.php http://localhost/birms_work/public
```
- `run_tests.php` = accounts, security, audit (B1). `run_modules.php` = settings, residents, manual verification, request review, fee matrix, certificates, blotter, reports, backup-access, attachment, PDF, ledger/reconciliation, demo seeder, and mocked Semaphore and Infobip provider checks (B2–B12). Disable real SMS in private config before running tests on a database with real provider credentials.
- If a run is interrupted it can leave test rows behind (names starting `ZZ`). Rebuild the **test** database
  (drop `barangay_db_work`, run `migrate.php up` again) before re-running.
- Refuses to run unless the database name ends in `_work` or `_test`. Creates and deletes `zz_test_*` users.
- Expect `FAIL 0`. `NOT TESTED` means a check could not run. Send me the full output, including any FAIL lines.
- The `--root` check confirms private folders are not downloadable through Apache. `app`, `storage`, and other project folders use `.htaccess` deny rules; the PHP built-in server ignores `.htaccess`, so run that particular protection check under Apache on XAMPP.
  It depends on Apache's `AllowOverride All` (the XAMPP default). Under Apache, also verify that these URLs return 403/404: `http://localhost/birms_work/.git/config` (if using a Git clone), `http://localhost/birms_work/README.md`, `http://localhost/birms_work/MANIFEST.txt`, `http://localhost/birms_work/SETUP_XAMPP.bat`, `http://localhost/birms_work/.gitignore`, and `http://localhost/birms_work/Api`. The last URL should remain blocked even if the file exists in an older extracted folder.

## 7. Backup before any change
```
cd C:\xampp\mysql\bin
mysqldump -u root -p --routines --triggers --events --single-transaction barangay_db_work > D:\BIRMS_BACKUP\barangay_db_work_FULL.sql
```
Do not add `--databases`. Test the restore into a scratch database. Copy the project folder too.

## 8. Known limitations
- **Implemented in current `main` code; pending XAMPP verification:** demographic/age/employment/voter/Purok reports; monthly, quarterly and annual issuance/request/blotter summaries; fee category/tier breakdowns; CSV export; current dashboard indicators; requirements review before request approval/issuance; manual verification history; configurable fee matrix; and an Administrator-only local SQL backup download.
- Migrations 007–011 are required for the latest branch. Enter the barangay-approved employment-status and incident-type categories under Settings → Other lists and approved fee rules under Settings → Multi-tier fee matrix. No categories or rates are guessed.
- Resident identity and voter-list checks can be recorded manually in the resident profile with actor/date/history. The system does not query COMELEC. The separate cash-collection ledger records staff-entered amounts and reconciliation state; this is not a payment gateway and not independent proof of a bank deposit. Issuance history totals use ledger entries and label missing entries.
- The backup page downloads a local SQL file only. Cloud storage, scheduled backups, automatic restore verification, and a completed restoration test are not included.
- SMS providers are optional and disabled by default. When one is explicitly configured/enabled, the code can submit a Ready-for-pickup notification through Semaphore or Infobip. CI uses mocked provider calls; real-phone delivery must still be tested on the local system.
- Blotter look-up shows matching parties' information to roles with blotter lookup access. The role matrix is still provisional and needs approval by the Barangay Secretary / system owner.
- Visitor and request attachments accept PDF/JPG/PNG up to 10 MB, are stored in `storage/private_uploads` with randomized names (outside `public/`), and are served only through an authenticated, permission-checked download endpoint. Confirm the folder is writable by Apache and not served directly by the web server.
- Paid certificate issuance records a Pending item in the cash-collection ledger. Ledger management is Administrator-only; reconciled entries should be supported by the office's approved evidence process.
- Certificate PDF download is server-generated. Dompdf is used when installed; an offline-compatible built-in PDF writer is the fallback. The output is prominently marked **DRAFT — PENDING OFFICIAL LAYOUT APPROVAL**. Typed signatory text is not a cryptographic digital signature.
- Official facts are blank by design (see step 9). Certificate letterhead and layout are a **provisional default** until the Barangay Secretary approves them.
- The new reports, fee matrix, verification card, backup screen, private attachment uploads/downloads, server PDF response, ledger screen and reconciliation-aware history have not yet been verified in a real XAMPP/browser session. Follow `docs/BIRMS_LAB_ACTIVITY1_REMEDIATION_STATUS.md`; its target-office result is explicitly NOT RUN until performed on the actual PC. Layout, labels, print output, download integrity and the strict Content-Security-Policy must be checked on the target office PC.
- To collect evidence for the paper's retrieval-speed objective, run `C:\\xampp\\php\\php.exe tools\\benchmark.php --iterations=10` against `barangay_db_work`. Compare the same task against the paper-based process using the worksheet in `docs/UAT_PERFORMANCE_EVALUATION.md`. Do not report benchmark or manual timings until actually measured.
- Compatibility with your exact XAMPP versions is unverified.

## 9. Troubleshooting
- **Blank page or "Something went wrong":** read `storage\logs\php-error.log`.
- **Cannot connect to the database:** check `app\config.local.php` and that MySQL is running.
- **Links or styles broken:** set `'base_url' => '/birms_work/public'` in `config.local.php`.
- **Plain unstyled page:** the browser may be blocking assets; press F12 → Console and send me the messages.
