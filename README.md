# BIRMS

Barangay Information and Records Management System.

This repository contains the BIRMS B1–B8 application for Barangay Wawa, Balagtas, Bulacan. The paper-alignment and presentation-readiness changes have been merged into `main`: data-derived reports/dashboard figures, request requirements review, fee matrix/cash ledger, manual identity/voter verification history, private attachments/PDF output, local SQL export, global record search, UI consistency checks, and a guided XAMPP setup. The PowerPoint is a design reference; its sample content is not live system data.

## Repository layout

- `app/` — shared application bootstrap, authentication, permissions, database helpers, and module libraries.
- `public/` — authenticated module pages for records, requests, certificates, blotters, reporting, Administrator-only backup/ledger and permission-aware global search.
- `database/` — migrations and expected schema snapshot. Migrations 007–011 add reporting fields, request requirements review, fee matrix/quote snapshots, resident verification history, private attachment metadata, typed signatory snapshots, and the cash-collection ledger.
- `tools/` — migration, administrator, schema verification and `benchmark.php` timing tools (the benchmark is read-only and refuses databases not named `*_work` or `*_test`).
- `tests/` — module and application test runners, including fee matrix, requirements review, manual verification, reports, backup access, private attachments/PDF, ledger reconciliation, global search and shared UI checks.
- `docs/` — design decisions, implementation notes, and XAMPP setup guidance.
- `storage/` — runtime storage locations; logs and backups are ignored by Git.

## Branch provenance

Initial import branch: `integration/import-b6`

Source package: `birms_work_B1-B6.zip` from the uploaded `Claudecodebirms.zip` bundle.

The outer archive also contained earlier B1 and B1–B5 packages and duplicate SQL files; the B1–B6 package was selected as the initial self-contained import. The additional B7/B8 and presentation-readiness work has now been merged into `main`.

## Download and run locally (XAMPP)

For the presentation build, download the [one-click BIRMS ZIP from main](https://github.com/sachie34/Birms/archive/refs/heads/main.zip), extract the entire folder under `C:\xampp\htdocs\`, rename the extracted folder to `birms_work`, start Apache and the intended local MySQL/MariaDB server, then run `SETUP_XAMPP.bat`. The wizard initializes a separate `barangay_db_work` database, applies migrations 001-011, writes the private local configuration, and guides you through creating the first administrator. It does not import or modify the original `barangay_db`.

Read [the XAMPP setup guide](docs/README_XAMPP.md), [the paper-to-code alignment matrix](docs/PAPER_CODE_ALIGNMENT.md), and [the presentation runbook](docs/PRESENTATION_RUNBOOK.md). Configure only fictitious demo records and confirm the official settings with the system owner. Automated CI passing does not mean target-PC browser/printer UAT has passed.

## Local setup

Follow [`docs/README_XAMPP.md`](docs/README_XAMPP.md) for the intended PHP, MariaDB/MySQL, Apache, and XAMPP setup. Copy `app/config.local.php.example` to `app/config.local.php` and provide local credentials outside Git.

Do not commit database passwords, generated logs, backups, or local configuration files.

## Verification commands

```bash
php -l public/index.php
php -l public/dashboard.php
php tests/run_tests.php
php tests/run_modules.php
```

The test suite may require a configured local database. Run it only against a separate working database, never against an original or production database.

## Scope boundary

The current `main` branch is the one-click presentation build. It includes the paper-aligned additions and a shared UI shell across role-accessible tabs. The uploaded PowerPoint, BPMN and ERD remain separate reference/design artifacts; the live app uses its own data-backed pages. Run the complete test suite on a disposable `_work` or `_test` database before presenting.

The merged application-code revision passed the [latest completed application-code CI run](https://github.com/sachie34/Birms/actions/runs/39659718319): PHP syntax passed; migrations 001–011 applied on disposable MariaDB 10.11; **37/37 authentication/security/audit checks** and **146/146 module integration checks** passed (zero failures or untested checks). The module run includes the shared-shell check across 50 role-accessible routes and global-search tests. This run was completed on the final tested code revision immediately before the squash merge to `main`. Browser/UAT on the target office PC is a separate required step and remains NOT RUN until the checklist is executed on that PC. See [the paper-to-code audit](docs/PAPER_CODE_COMPLIANCE_AUDIT.md), [the UAT/performance worksheet](docs/UAT_PERFORMANCE_EVALUATION.md), and [the Lab Activity 1 remediation status and UAT record](docs/BIRMS_LAB_ACTIVITY1_REMEDIATION_STATUS.md).

## Important scope boundaries

- Fee rules must be entered from barangay-approved rates; no sample rates are seeded. Issuance history now sums entries in the separate cash-collection ledger and filters reconciled/unreconciled entries. A ledger amount is staff-recorded data, not independent proof of a bank deposit; older paid issuances without entries must be reviewed and reconciled.
- Resident verification is recorded manually and does not contact COMELEC or another external database.
- Backup supports a local SQL download and an optional one-click HTTPS WebDAV upload. Configure `BIRMS_BACKUP_WEBDAV_URL`, `BIRMS_BACKUP_WEBDAV_USERNAME`, and `BIRMS_BACKUP_WEBDAV_PASSWORD` in the server environment or private `app/config.local.php`; use an approved private folder and a dedicated app password. No cloud account/credentials are included in the repository. Scheduled backup, notifications, and a verified restoration are not included. Test restoration in a separate scratch database.
- SMS is log-only; the application does not send messages without an approved provider integration.
- Visitor/request attachments accept PDF/JPG/PNG up to 10 MB and are stored with random names outside the public web root; downloads are permission-checked and checksum-checked.
- Certificates can be downloaded as server-generated PDFs (Dompdf when installed, otherwise an offline-compatible PDF writer). The template is conspicuously marked DRAFT pending official layout approval. Typed signature names are not cryptographically verified digital signatures.
- Official certificate and summons text, fee rates, role permissions, and standard agency report templates require owner approval. Office-PC XAMPP/browser UAT and process timing/accuracy metrics must be recorded only after they are actually tested.
