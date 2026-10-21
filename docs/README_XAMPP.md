# BIRMS working copy: install and run on XAMPP

Barangay Wawa, Balagtas, Bulacan. This **working copy** starts from B1–B6 and adds database-derived reports/dashboard summaries, a request requirements-review gate, an effective-dated fee matrix, manual resident identity/voter verification history, and a local SQL export.
It never touches your original `barangay_db`.

> **Latest verified automated CI status:** GitHub Actions ran PHP 8.3, MariaDB 10.11, and the PHP built-in server. The B1 authentication/security/audit suite passed **37 checks**; the B2–B7 module suite passed **119 checks**; no failures or untested checks were reported.
> These are automated CI results, **not an office-XAMPP or browser user-acceptance test**. Section 6 gives you the tests to run on your XAMPP setup.

## 1. Requirements
- XAMPP with **PHP 8.1 or newer** and MySQL/MariaDB (5.7+ / 10.3+). Check with `C:\xampp\php\php.exe -v`.
- PHP extensions: `pdo_mysql` (needed), `curl` (only for the tests), `mbstring` (optional, there is a fallback).
- Use the system on your office network only. **Do not expose it to the public internet.**

## 2. Install (working copy)
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
   This applies migrations 001 to 010 to the new, empty `barangay_db_work` database. Migration 007 adds resident employment status and incident type fields for reports; migration 008 adds request requirements status/notes; migration 009 adds the configurable fee matrix and quote/issuance snapshots; migration 010 adds a manual resident identity/voter verification history.
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
In the paper-compliance branch, the Administrator has all currently defined module permissions plus account, settings, audit and backup access, matching the intended highest-privilege role. Captain, Secretary and Clerk use the existing **PROVISIONAL** role map until the Barangay Secretary/system owner approves the final matrix. The map must still be validated against the approved paper and office responsibilities.
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
- `run_tests.php` = accounts, security, audit (B1). `run_modules.php` = settings, residents, manual verification, request review, fee matrix, certificates, blotter, reports, and backup-access checks (B2–B7).
- If a run is interrupted it can leave test rows behind (names starting `ZZ`). Rebuild the **test** database
  (drop `barangay_db_work`, run `migrate.php up` again) before re-running.
- Refuses to run unless the database name ends in `_work` or `_test`. Creates and deletes `zz_test_*` users.
- Expect `FAIL 0`. `NOT TESTED` means a check could not run. Send me the full output, including any FAIL lines.
- The `--root` check confirms the private folders (`app`, `database`, `storage`, `tests`, `tools`, `docs`) cannot be downloaded.
  It depends on Apache's `AllowOverride All` (the XAMPP default).

## 7. Backup before any change
```
cd C:\xampp\mysql\bin
mysqldump -u root -p --routines --triggers --events --single-transaction barangay_db_work > D:\BIRMS_BACKUP\barangay_db_work_FULL.sql
```
Do not add `--databases`. Test the restore into a scratch database. Copy the project folder too.

## 8. Known limitations
- **Available in the paper-compliance branch, pending XAMPP verification:** demographic/age/employment/voter/Purok reports; monthly, quarterly and annual issuance/request/blotter summaries; fee category/tier breakdowns; CSV export; current dashboard indicators; requirements review before request approval/issuance; manual verification history; configurable fee matrix; and an Administrator-only local SQL backup download.
- Migrations 007–010 are required for the latest branch. Enter the barangay-approved employment-status and incident-type categories under Settings → Other lists and approved fee rules under Settings → Multi-tier fee matrix. No categories or rates are guessed.
- Resident identity and voter-list checks can be recorded manually in the resident profile with actor/date/history. The system does not query COMELEC. Fees shown in reports are saved issuance amounts, not live payments or a payment-gateway total.
- The backup page downloads a local SQL file only. Cloud storage, scheduled backups, automatic restore verification, and a completed restoration test are not included.
- SMS is **log-only** by decision (nothing is ever sent).
- Blotter look-up shows matching parties' information to roles with blotter lookup access. The role matrix is still provisional and needs approval by the Barangay Secretary / system owner.
- Official facts are blank by design (see step 9). Certificate letterhead and layout are a **provisional default** until the Barangay Secretary approves them.
- The new reports, fee matrix, verification card and backup screens have not yet been verified in a real XAMPP/browser session. Layout, labels, print output, download integrity and the strict Content-Security-Policy must be checked on the target office PC.
- To collect evidence for the paper's retrieval-speed objective, run `C:\\xampp\\php\\php.exe tools\\benchmark.php --iterations=10` against `barangay_db_work`. Compare the same task against the paper-based process using the worksheet in `docs/UAT_PERFORMANCE_EVALUATION.md`. Do not report benchmark or manual timings until actually measured.
- Compatibility with your exact XAMPP versions is unverified.

## 9. Troubleshooting
- **Blank page or "Something went wrong":** read `storage\logs\php-error.log`.
- **Cannot connect to the database:** check `app\config.local.php` and that MySQL is running.
- **Links or styles broken:** set `'base_url' => '/birms_work/public'` in `config.local.php`.
- **Plain unstyled page:** the browser may be blocking assets; press F12 → Console and send me the messages.
