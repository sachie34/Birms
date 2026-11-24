# BIRMS — XAMPP deployment guide

This guide covers a fresh Windows/XAMPP installation. Use a separate database for staging or a demo, such as `barangay_db_work`. Never point tests or demo seeders at a live barangay database. For an internet-facing or office production deployment, follow [docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md](docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md) and require HTTPS.

## 1. Requirements

- Windows 10/11 and XAMPP with Apache, PHP 8.3.37 or later in the PHP 8.3 branch, and MariaDB/MySQL.
- PHP extensions: `pdo_mysql`, `fileinfo`, `curl` for automated HTTP tests, and `zip` for full-system ZIP backup/restore.
- Apache must allow project `.htaccess` rules (`AllowOverride All`, the normal XAMPP default).
- Keep the project under `C:\\xampp\\htdocs\\Birms-main` (or adjust every command and URL to match the actual folder).

Check both CLI PHP and Apache PHP. Create a temporary `phpinfo()` page only for local verification and remove it immediately afterward.

## 2. Back up before migrating

Before applying migrations to an existing working database:

1. Stop BIRMS writes and close active sessions.
2. Back up the project folder, including private files under `storage/` and `app/config.local.php`.
3. Export the database (replace the destination path as needed):

   ```bat
   C:\\xampp\\mysql\\bin\\mysqldump.exe -h 127.0.0.1 -P 3306 -u root -p --routines --triggers --events --single-transaction barangay_db_work > D:\\BIRMS_BACKUP\\barangay_db_work_before_migration.sql
   ```

4. Confirm the SQL file exists and is non-empty. Keep the backup outside `htdocs`.

Do not test restore over the source database. Restore only into a separate scratch database and folder.

## 3. Copy the project

1. Download the ZIP for the reviewed release branch or approved release tag.
2. Extract the complete repository to `C:\\xampp\\htdocs\\Birms-main`. Do not copy only `public/`; the application, migrations, tools and private storage are required.
3. Start Apache and the intended MySQL/MariaDB service in XAMPP. Do not start a second MySQL service on port 3306 if another server already owns that port.

## 4. Create a database and limited application user

In phpMyAdmin (SQL tab) or the MySQL command line, create an isolated database and a dedicated application account. Use a unique password; do not reuse the database root password.

```sql
CREATE DATABASE barangay_db_work
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'birms_app'@'localhost'
  IDENTIFIED BY 'REPLACE_WITH_A_LONG_UNIQUE_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON barangay_db_work.*
  TO 'birms_app'@'localhost';

FLUSH PRIVILEGES;
```

The application account is deliberately limited to CRUD privileges. Do not grant it global privileges. The migration step may need a separate temporary administrative database account because migrations create/alter tables; do not store that privileged password in the application configuration.

## 5. Configure BIRMS

From Command Prompt:

```bat
cd /d C:\\xampp\\htdocs\\Birms-main
copy app\\config.local.php.example app\\config.local.php
```

Edit `app/config.local.php` and set:

- `db.host` to `127.0.0.1`
- `db.port` to the actual MariaDB/MySQL port
- `db.name` to `barangay_db_work`
- `db.user` to `birms_app`
- `db.pass` to the unique password created above
- `env` to `production`
- `email.enabled` to `false` until the authorized mail sender is configured and tested

Keep `app/config.local.php` private. It is excluded by `.gitignore` and blocked by `app/.htaccess`. Never commit it or paste its credentials into tickets or chat.

## 6. Apply migrations and create the first administrator

Use the PHP executable belonging to the XAMPP installation:

First check the working database and source files:

```bat
C:\\xampp\\php\\php.exe tools\\setup_xampp.php --check
C:\\xampp\\php\\php.exe tools\\migrate.php status
```

The `birms_app` account created above has CRUD-only permissions and **cannot apply schema migrations**. Apply migrations with a separate authorized DBA/migration account. The following PowerShell example securely prompts for the local MySQL root password without echoing it, uses it only for the migration command, and clears the temporary environment variables afterward:

```powershell
$secure = Read-Host "MySQL migration account password" -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:BIRMS_DB_USER = "root"
    $env:BIRMS_DB_PASS = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    & C:\\xampp\\php\\php.exe tools\\migrate.php up
    if ($LASTEXITCODE -ne 0) { throw "BIRMS migration failed; stop and review the error." }
}
finally {
    Remove-Item Env:BIRMS_DB_USER, Env:BIRMS_DB_PASS -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}
```

Then verify the schema and create the first administrator using the limited application account:

```bat
C:\\xampp\\php\\php.exe tools\\schema_check.php
C:\\xampp\\php\\php.exe tools\\create_admin.php --username=admin --name="Barangay Administrator"
```

Do not grant DDL or global privileges permanently to `birms_app`. There is no default administrator password; follow the CLI prompt and choose a unique password that meets the password policy.

## 7. Open the application

Open:

`http://localhost/Birms-main/public/`

Sign in with the administrator account you just created. Enter only barangay-approved Puroks, fees, document wording, signatory details and other official reference data. Do not use sample values as official records.

Public queue display:

- `http://localhost/Birms-main/public/queue/display.php`
- `http://localhost/Birms-main/public/queue/board.php`

The public display is intended to show queue numbers and waiting counts only. Staff queue management remains behind authentication and permission checks.

## 8. Verify the installation

Run the automated checks only against the isolated `barangay_db_work` or another database ending in `_work` or `_test`. Do not run test scripts against a production database.

```bat
cd /d C:\\xampp\\htdocs\\Birms-main
C:\\xampp\\php\\php.exe tools\\check_php_includes.php
C:\\xampp\\php\\php.exe tools\\setup_xampp.php --check
C:\\xampp\\php\\php.exe tests\\run_tests.php http://localhost/Birms-main/public --root=http://localhost/Birms-main
C:\\xampp\\php\\php.exe tests\\run_modules.php http://localhost/Birms-main/public
```

Check the test output for both failures and `NOT TESTED` items. The repository currently does not contain `tests/queue_cli.php`; queue behavior is covered by the module integration suite. Do not report that missing script as passed.

Under Apache, verify that direct requests to `/app/`, `/database/`, `/storage/`, `/tools/`, `/tests/` and `/docs/` are denied, and that private files cannot be downloaded. Confirm that `storage/` is writable by the Apache account without making it publicly accessible.

The PHP built-in server ignores `.htaccess`; use Apache/XAMPP for directory-protection acceptance.

## 9. Rollback plan

If a migration or deployment causes a problem:

1. Put the site into maintenance mode or stop Apache to prevent new writes.
2. Preserve the failing release folder and logs for diagnosis.
3. Restore the pre-migration SQL backup into the intended database, and restore the matching project/private-storage backup as one consistent set.
4. Verify the restored database and private files in a separate scratch copy before reopening the site.
5. Reopen only after login, role access, request, queue and document smoke tests pass.

Do not assume a down migration can reconstruct data changed by an upgrade. Do not run `migrate.php down` against a live database as an emergency rollback. A verified database-and-files backup is the rollback mechanism.

## 10. Target-computer acceptance still required

Automated CI cannot prove the exact Windows/XAMPP installation, Apache configuration, browser behavior, printer output, TV/large-screen readability, LAN access, actual email delivery, writable NTFS permissions or a successful full restore on the target PC. Test those items with synthetic records before using real barangay data. For production, enable HTTPS and follow the separate production deployment runbook.
