# BIRMS

Barangay Information and Records Management System.

This repository began as the clean Git import of the staged **B1–B6 BIRMS work package** from `Claudecodebirms.zip`. The `john/paper-code-compliance` review branch adds database-derived demographic, issuance, request and blotter reports; dashboard summaries; request requirements review before approval/issuance; an effective-dated configurable fee matrix; manual resident identity/voter verification history; and an Administrator-only local SQL export. It also adds a read-only performance benchmark and a UAT worksheet. It remains separate from `main` and the existing visual UI/UX prototype until reviewed.

## Repository layout

- `app/` — shared application bootstrap, authentication, permissions, database helpers, and module libraries.
- `public/` — web entry points and module screens, including reporting and Administrator-only local SQL backup on the paper-compliance branch.
- `database/` — migrations and expected schema snapshot. Migrations 007–011 add reporting fields, request requirements review, fee matrix/quote snapshots, resident verification history, private attachment metadata, typed signatory snapshots, and the cash-collection ledger.
- `tools/` — migration, administrator, schema verification and `benchmark.php` timing tools (the benchmark is read-only and refuses databases not named `*_work` or `*_test`).
- `tests/` — module and application test runners, including fee matrix, requirements-review, manual-verification, report, backup-access, private-file upload/download, server-side PDF, ledger lifecycle, and reconciled-history tests.
- `docs/` — design decisions, implementation notes, and XAMPP setup guidance.
- `storage/` — runtime storage locations; logs and backups are ignored by Git.

## Branch provenance

Initial import branch: `integration/import-b6`

Source package: `birms_work_B1-B6.zip` from the uploaded `Claudecodebirms.zip` bundle.

The outer archive also contained earlier B1 and B1–B5 packages and duplicate SQL files. Those were not mixed into this repository. The B1–B6 package was selected as the latest self-contained work package, while its existing documentation remains the source of truth for scope and pending decisions.

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

The original main branch reflects the initial B1–B6 package. The `john/paper-code-compliance` review branch contains the paper-aligned additions described above. It does not change the existing BIRMS UI/UX prototype, PowerPoint materials, BPMN, or ERD. Review and run the complete test suite on a disposable `_work` or `_test` database before accepting or deploying these changes.

Verified by [GitHub Actions run #317](https://github.com/sachie34/Birms/actions/runs/39644766060) passed PHP syntax validation, migrations 001–011 on disposable MariaDB, **37/37 authentication/security/audit checks**, and **142/142 module integration checks**, with zero failures and zero untested checks. Browser/UAT on the target office PC is a separate required step and remains NOT RUN until the checklist is executed on that PC. See [the paper-to-code audit](docs/PAPER_CODE_COMPLIANCE_AUDIT.md), [the UAT/performance worksheet](docs/UAT_PERFORMANCE_EVALUATION.md), and [the Lab Activity 1 remediation status and UAT record](docs/BIRMS_LAB_ACTIVITY1_REMEDIATION_STATUS.md).

## Important scope boundaries

- Fee rules must be entered from barangay-approved rates; no sample rates are seeded. Issuance history now sums entries in the separate cash-collection ledger and filters reconciled/unreconciled entries. A ledger amount is staff-recorded data, not independent proof of a bank deposit; older paid issuances without entries must be reviewed and reconciled.
- Resident verification is recorded manually and does not contact COMELEC or another external database.
- Backup supports a local SQL download and an optional one-click HTTPS WebDAV upload. Configure `BIRMS_BACKUP_WEBDAV_URL`, `BIRMS_BACKUP_WEBDAV_USERNAME`, and `BIRMS_BACKUP_WEBDAV_PASSWORD` in the server environment or private `app/config.local.php`; use an approved private folder and a dedicated app password. No cloud account/credentials are included in the repository. Scheduled backup, notifications, and a verified restoration are not included. Test restoration in a separate scratch database.
- SMS is log-only; the application does not send messages without an approved provider integration.
- Visitor/request attachments accept PDF/JPG/PNG up to 10 MB and are stored with random names outside the public web root; downloads are permission-checked and checksum-checked.
- Certificates can be downloaded as server-generated PDFs (Dompdf when installed, otherwise an offline-compatible PDF writer). The template is conspicuously marked DRAFT pending official layout approval. Typed signature names are not cryptographically verified digital signatures.
- Official certificate and summons text, fee rates, role permissions, and standard agency report templates require owner approval. Office-PC XAMPP/browser UAT and process timing/accuracy metrics must be recorded only after they are actually tested.
