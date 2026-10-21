# BIRMS

Barangay Information and Records Management System.

This repository began as the clean Git import of the staged **B1–B6 BIRMS work package** from `Claudecodebirms.zip`. The `john/paper-code-compliance` review branch adds database-derived demographic, issuance, request and blotter reports; dashboard summaries; request requirements review before approval/issuance; an effective-dated configurable fee matrix; manual resident identity/voter verification history; and an Administrator-only local SQL export. It also adds a read-only performance benchmark and a UAT worksheet. It remains separate from `main` and the existing visual UI/UX prototype until reviewed.

## Repository layout

- `app/` — shared application bootstrap, authentication, permissions, database helpers, and module libraries.
- `public/` — web entry points and module screens, including reporting and Administrator-only local SQL backup on the paper-compliance branch.
- `database/` — migrations and expected schema snapshot. Migrations 007–010 add reporting fields, request requirements review, fee matrix/quote snapshots, and resident verification history.
- `tools/` — migration, administrator, schema verification and `benchmark.php` timing tools (the benchmark is read-only and refuses databases not named `*_work` or `*_test`).
- `tests/` — module and application test runners, including fee matrix, requirements-review, manual-verification, report, and backup-access tests.
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

The current review branch's latest verified CI run passed **37 authentication/security/audit tests** and **119 module integration tests**, along with PHP syntax and migrations 001–010. This does not replace browser/UAT testing on the target XAMPP computer. See [the paper-to-code audit](docs/PAPER_CODE_COMPLIANCE_AUDIT.md) and [the UAT/performance worksheet](docs/UAT_PERFORMANCE_EVALUATION.md) for remaining checks.

## Important scope boundaries

- Fee rules must be entered from barangay-approved rates; no sample rates are seeded. Issuance totals reflect saved fees and O.R. details, not independently reconciled cash receipts.
- Resident verification is recorded manually and does not contact COMELEC or another external database.
- The backup page downloads a local SQL file only. Cloud/scheduled backup and a verified restoration are not included.
- SMS is log-only; the application does not send messages without an approved provider integration.
- Official certificate and summons text, role permissions, standard agency report templates, and performance/UAT results require owner approval or actual testing.
