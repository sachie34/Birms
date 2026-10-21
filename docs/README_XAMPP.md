# BIRMS working copy: install and run on XAMPP

Barangay Wawa, Balagtas, Bulacan. **Batches B1 to B6** (accounts and roles, audit trail, settings, residents and households, visitor log, requests, certificates, blotter with hearings and summons). This is a **working copy**.
It never touches your original `barangay_db`.

> **Honest status:** every check below was run in my sandbox (PHP 8.3.6, MariaDB 10.11, PHP's built-in server):
> 36 checks in the B1 suite and 102 in the B2-B6 module suite, all passing, 1 not testable there (needs Apache).
> **Nothing has been run on your XAMPP yet.** Section 6 gives you the tests to run there.

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
   This applies migrations 001 to 007 to the new, empty `barangay_db_work` database. Migration 007 adds resident employment status and structured incident type fields used by reports.
   The migration tool needs permission to create the database and tables. If `birms_app` is refused, run this one step
   with `root` by setting `BIRMS_DB_USER=root` for that command (`set BIRMS_DB_USER=root` then the command), then give
   `birms_app` only the SELECT/INSERT/UPDATE/DELETE rights above.
7. Create the first administrator (there is **no default account or password**):
   ```
   C:\xampp\php\php.exe tools\create_admin.php --username=admin --name="Your Full Name"
   ```
   Type a password when asked (it is visible on Windows). Minimum 10 characters, with a letter and a number.
8. Open `http://localhost/birms_work/public/` and sign in.
9. Go to **Fees and Puroks** (Settings) and enter the official details **yourself**: Captain and Secretary names, the Purok list,
   each document's fee and wording, and the SMS text. They are blank on purpose. Certificates **cannot be issued** until the
   fee, wording, Captain name and the resident's Purok are set, because the system never guesses official facts.
   Hearing summons work the same way: the Secretary enters the official text under **Settings, Hearing summons**; a summons cannot be printed until then.

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
The Administrator manages the system only (accounts, settings, audit, backup). **Permissions are PROVISIONAL**
until the Barangay Secretary validates them (tracker sheet *Role Matrix (Provisional)*).
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
- `run_tests.php` = accounts, security, audit (B1). `run_modules.php` = settings, residents, requests, certificates, blotter, reports, and backup-access checks (B2-B7).
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
- **Available in the paper-compliance branch, pending XAMPP verification:** demographic/age/employment/voter/Purok reports; monthly, quarterly, and annual issuance and blotter summaries; CSV export; current dashboard indicators; and an Administrator-only local SQL backup download.
- Migration 007 is required before opening the new reports, resident form, or blotter form because it adds nullable reporting fields. Enter the barangay-approved employment-status and incident-type categories under Settings → Other lists. No categories are guessed.
- Voter registration status can be recorded by authorized staff, but the system does not verify it against COMELEC. Fees shown in reports are saved issuance fee amounts, not live payments or a payment-gateway total.
- The backup page downloads a local SQL file only. Cloud storage, scheduled backups, automatic restore verification, and a completed restoration test are not included.
- SMS is **log-only** by decision (nothing is ever sent).
- Blotter look-up shows matching parties' information to roles with blotter lookup access. The role matrix is still provisional and needs approval by the Barangay Secretary / system owner.
- Official facts are blank by design (see step 9). Certificate letterhead and layout are a **provisional default** until the Barangay Secretary approves them.
- The new reports and backup screens have not yet been verified in a real XAMPP/browser session. Layout, colors, print output, download integrity and the strict Content-Security-Policy must be checked on the target office PC.
- Compatibility with your exact XAMPP versions is unverified.

## 9. Troubleshooting
- **Blank page or "Something went wrong":** read `storage\logs\php-error.log`.
- **Cannot connect to the database:** check `app\config.local.php` and that MySQL is running.
- **Links or styles broken:** set `'base_url' => '/birms_work/public'` in `config.local.php`.
- **Plain unstyled page:** the browser may be blocking assets; press F12 → Console and send me the messages.
