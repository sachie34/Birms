# BIRMS production deployment runbook

**Application:** BIRMS — Barangay Information and Records Management System  
**Repository:** https://github.com/sachie34/Birms  
**Current combined release candidate:** `release/msit-demo-ready` (draft PR #48; not production-approved)  
**Controlling requirements:** `BRMS_SRS_Revised.docx`  
**Target:** Windows Server/office PC with Apache, PHP and MariaDB/MySQL on a private barangay LAN

> This is a production deployment procedure, not a claim that the current office PC has passed acceptance. Do not deploy to the public internet. Keep the original `barangay_db` untouched. The XAMPP setup wizard creates a working/demo database called `barangay_db_work`; it is not the production provisioning procedure.

## 1. Release prerequisites

Do not proceed until each prerequisite is evidenced:

- [ ] The barangay/system owner and MSIT adviser approve the revised SRS scope, official certificate wording, fees, signatory use and printed form layout.
- [ ] A dedicated Windows host is selected, patched, access-controlled and protected by UPS and physical security appropriate for resident records.
- [ ] The host has a fixed LAN address and a stable internal DNS name. The shared QR code must use that stable HTTPS address, not `localhost` or a temporary IP.
- [ ] Apache serves **only the repository's `public/` directory** as its document root. Do not expose the repository root, `app/`, `database/`, `docs/`, `tests/`, `tools/`, or `storage/` as the web root.
- [ ] HTTPS is configured with a certificate issued by a CA trusted by every office PC, kiosk and resident device that will access BIRMS. Do not use plain HTTP for credentials or resident records over Wi-Fi/LAN. Do not bypass browser certificate warnings.
- [ ] The PHP version is a currently supported, security-patched release. Recommended baseline for this release is PHP 8.3.37 or newer in the 8.3 branch; PHP 8.4.28+, 8.5.13+ and 8.6.0+ are accepted by the runtime guard. PHP 8.2.36+ is only temporarily supported through 31 December 2026. Prefer a maintained branch with a longer support window and verify it against CI before upgrading.
- [ ] Apache PHP and CLI PHP use the same intended PHP build and required extensions: `pdo_mysql`, `fileinfo`, and `zip`. The current candidate uses email only; SMS sending and SMS administration are not available.
- [ ] Database engine/version, disk capacity, private-storage capacity, backup location, restore operator and recovery procedure are approved.
- [ ] All manual gates in `MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md` have recorded evidence, not assumptions.

Official PHP release policy and patched versions: https://www.php.net/supported-versions.php and https://www.php.net/archive/2026.php.

## 2. Database separation — never reuse the old database

The repository's setup wizard is intentionally limited to `barangay_db_work`. It is for development, demo and acceptance testing.

For production:

1. Keep the existing `barangay_db` unchanged and do not point the application at it.
2. Take and verify a separate backup of any existing source data before planning an import. Do not import it until the data owner approves the mapping and reconciliation.
3. Create a **new, empty** database with a distinct name such as `barangay_db_prod`, using `utf8mb4` and InnoDB. Do not name it with the `_work` or `_test` suffix; the automated test runners intentionally refuse production-style names.
4. Create a dedicated application account (for example `birms_prod_app`) restricted to connections from the application host and that database only. Grant ordinary runtime privileges `SELECT, INSERT, UPDATE, DELETE`; do not grant global privileges, `GRANT OPTION`, or schema DDL to the web application account.
5. Keep database administrator credentials separate from the web application credentials. Use the DBA/root account only for initial database provisioning and migrations. Do not save the DBA password in `app/config.local.php`, source control, scripts or a browser-accessible file.
6. Configure the private `app/config.local.php` with the production database name, dedicated limited account and a strong unique password. Set `env` to `production`, `timezone` to `Asia/Manila`, notifications disabled until tested, `session.secure` to `true`, and `security.require_https` to `true` after the trusted HTTPS virtual host is active. The application then returns HTTP 503 on plain HTTP rather than accepting a credential/session request or redirecting to an untrusted Host header.
7. Before migration, take a verified database backup and record its location/checksum. Apply migrations to the **new production database** with a DBA account using `tools/migrate.php up --i-have-a-backup`. That flag is an operator attestation; the script does not independently prove that a backup exists. Confirm the configured database name shown by the tool before proceeding.
8. Run `tools/schema_check.php` and `tools/migrate.php status`; require zero pending migrations and a schema match. Then switch back to the limited application account and verify normal read/write operations.
9. Create the first production administrator using `tools/create_admin.php`; use a unique strong password and complete the forced password change. Do not reuse demo credentials.

Example migration command from Command Prompt (replace the database administrator password securely; do not paste it into shared chat or commit it):

```bat
set BIRMS_DB_HOST=127.0.0.1
set BIRMS_DB_PORT=3306
set BIRMS_DB_NAME=barangay_db_prod
set BIRMS_DB_USER=root
set BIRMS_DB_PASS=YOUR_DATABASE_ADMIN_PASSWORD
C:\\xampp\\php\\php.exe tools\\migrate.php up --i-have-a-backup
C:\\xampp\\php\\php.exe tools\\schema_check.php
C:\\xampp\\php\\php.exe tools\\migrate.php status
set BIRMS_DB_PASS=
```

Use a protected terminal on the server. Clear the environment variable after the operation. Do not run the module/security test suites against this production database; they only accept `_work` or `_test` databases.

## 3. Apache and Windows host hardening

- Set Apache `DocumentRoot` and its matching `<Directory>` to the absolute `public/` path. Set `AllowOverride All` for that public directory so the checked-in `.htaccess` rules apply; keep directory indexes disabled.
- Enable Apache SSL/TLS modules and configure an internal CA-issued certificate. Install the CA trust chain on every managed client and mobile device used for the kiosk/QR workflow. Keep the private key readable only by the Apache service account and administrators.
- Restrict inbound firewall access to the required HTTPS port and authorized barangay LAN/VLAN ranges. Do not forward the service from the public router or expose phpMyAdmin, MariaDB port 3306, XAMPP dashboards, or Windows file sharing to the internet.
- Restrict phpMyAdmin to localhost/administrator access; it is not part of the resident-facing application.
- Run Apache and MariaDB as dedicated, non-interactive service accounts where supported. Do not run the web server as a domain administrator.
- Grant the Apache service account write access only to the required runtime folders under `storage/` (logs, private uploads and resident photos). Keep source code, migrations, configuration and backups read-only to the web process except where a documented feature requires otherwise.
- Keep `app/config.local.php`, provider credential files, logs, uploaded documents, resident photos, signature images and backups out of public web access. The application contains deny rules as defense in depth; serving only `public/` is the primary boundary.
- Configure Windows Defender/endpoint protection and encrypted disk/backup storage. Exclude only folders when a documented application requirement makes it necessary; do not broadly disable scanning.
- Synchronize the server clock and verify the configured Asia/Manila time zone.

## 4. Application configuration and services

- Keep email delivery disabled until the office has approved the sender, message content and consent process. The current candidate has no SMS provider, SMS sending workflow or SMS administration.
- Use a controlled test mailbox before enabling live email notifications. A `Queued` notification is not proof of delivery; verify actual mailbox receipt and failure handling.
- Keep WebDAV/cloud backup credentials in the ignored private configuration, use HTTPS and a dedicated app password. Verify the uploaded archive exists and is downloadable.
- Keep certificate wording, fee schedules, Chairman signature and effective dates aligned with written office approval. Do not treat the current PDF's draft warning as approval of the official form.
- Configure actual Purok and reference values only after the office validates the master lists. Do not leave demo/sample residents or demo accounts in production.
- Change or remove any temporary/demo accounts and verify every role with separate accounts. Chairman remains read-only for operational records.

## 5. Backup, restore and recovery

- Use the **complete system ZIP** for normal recovery: SQL database, manifest/checksums, resident photos and private uploads/signature snapshots. SQL-only backups do not contain uploaded files.
- Confirm PHP ZIP is enabled in both CLI and Apache. Check upload limits, execution time and free temporary disk space before large restores.
- Perform a full restore rehearsal on a separate scratch application folder, scratch database and scratch storage. Never rehearse by restoring over the live database or source storage.
- Verify residents, requests, permanent blotter cases, attachments, resident photos, current Chairman signature, historical issuance signatures and pending certificate-draft signatures after restore.
- Record backup time, archive size, checksum, storage location, operator and restore test result. Encrypt backups at rest and restrict access.
- The application does not currently implement scheduled backup jobs or backup notifications. Until an independently monitored backup schedule is configured and tested, an authorized operator must create and verify full-system backups manually at the frequency approved by the office.
- Agree on retention, off-site copy, RPO and RTO with the data owner. A successful download or WebDAV HTTP response alone is not a restore test.

Current full-system archive limits: 512 MiB compressed, 1 GiB expanded, 20,000 files, 100 MiB per private file, and 100 MiB SQL snapshot. Confirm actual data fits these limits before production; do not increase them without capacity, timeout and security testing.

## 6. Go-live acceptance

Record a named tester, date/time, build commit and evidence for every test:

- [ ] Fresh deployment from the exact release commit; no mixed/old files.
- [ ] PHP CLI and Apache version/extension checks; no PHP errors or warnings in logs.
- [ ] HTTPS works on all office PCs, kiosk and phone; no certificate warning; QR opens the correct stable hostname.
- [ ] Admin, Chairman, Secretary, Staff and Resident journeys verified, including direct URL/POST denial for forbidden actions.
- [ ] Resident create/edit/search/filter/export, photo upload, CSV import and status/type rules.
- [ ] Blotter intake, review, hearing, status history, archive and permanent-record protection.
- [ ] Document request, requirements, automatic draft generation, Staff/Admin PDF preview, final release, O.R./cash ledger and resident tracking privacy.
- [ ] Venue availability, overlapping-booking prevention, staff override and reservation notifications.
- [ ] Separate queue types, issue/call/serve/skip/cancel, kiosk touch targets, display and QR entry.
- [ ] Email controlled delivery, queued-vs-delivered state and failure fallback (if enabled). SMS is out of scope for this candidate.
- [ ] Certificate printed on the actual office printer; wording, signature, margins, QR/reference and paper stock approved.
- [ ] Full-system backup and scratch restore completed; all private files and DB references verified.
- [ ] Representative-data performance and concurrent-user test; LAN outage/restart recovery; power-loss recovery.
- [ ] Office staff training, operating procedure, incident contact and owner/adviser sign-off.

A failed critical test blocks go-live. Keep the deployment internal and use synthetic data until the data owner accepts the evidence.

## 7. Rollback and incident response

1. Stop new requests and announce a maintenance window.
2. Preserve current database, private storage and logs before attempting repair.
3. Do not run destructive migration-down scripts against production.
4. If a release must be rolled back, restore the previous tested application code only when schema compatibility is confirmed. Database rollback requires a rehearsed restore into a controlled environment and an explicit data-loss decision.
5. Preserve audit logs and permanent blotter records. Record who authorized the action, the time, reason and recovery result.
6. If resident information or credentials may have been exposed, isolate the host from the network, preserve logs, rotate affected credentials and follow the barangay's incident/privacy response process.

## 8. Current release status

The current combined candidate is `release/msit-demo-ready`, reviewed through draft [PR #48](https://github.com/sachie34/Birms/pull/48). Its exact commit `34dc0c73930a4017ba8e8ca86808fbda8059fc95` passed [GitHub Actions run 45489464306](https://github.com/sachie34/Birms/actions/runs/45489464306) on 24 November 2026; all eight PHP 8.3/8.4/8.5/8.6 × MariaDB 10.4/10.11 jobs succeeded.

- The integrated exact-head workflow passed PHP syntax lint, include-path checks, XAMPP setup preflight, migration apply/rollback/reapply, schema comparison, least-privilege database-account setup, public-route and signed-out protection smoke tests, HTTPS-required and Secure-cookie checks, manifest exclusions, security/authentication/audit tests, module integration tests, and base plus extended MSIT demo seed apply/verification/idempotency checks.
- These are hosted CI results against disposable Linux/MariaDB databases using PHP's built-in development server. They do not prove Apache `.htaccess` enforcement, the actual Windows/XAMPP setup, office TLS trust, printer output, LAN behavior, real SMTP delivery, representative-data performance or a successful full-system restore.
- The candidate does not include `tests/queue_cli.php`; queue lifecycle and public display privacy are covered by the module integration suite.
- The original `barangay_db` remains protected and is not a deployment target.
- PR #48 must remain a draft and must not be merged or deployed to production until the manual acceptance gates in this runbook and `MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md` are completed and signed off.


## Demo data is never production data

Do not run tools/seed_msit_demo_data.php on a production database. It intentionally refuses database names that do not end in _work or _test. Keep the fictitious DEMO ONLY records in a separate presentation database and do not copy them into the barangay's live resident registry. Official certificate wording, letterhead, fees and signatory settings must be approved separately before live use.
