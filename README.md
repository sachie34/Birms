# BIRMS

Barangay Information and Records Management System.

This repository began as the clean Git import of the staged **B1–B6 BIRMS work package** from `Claudecodebirms.zip`. The paper-compliance branch adds a reporting module, reporting data fields, and a local SQL backup download while keeping changes isolated from `main` until reviewed. It remains separate from the existing visual UI/UX prototype.

## Repository layout

- `app/` — shared application bootstrap, authentication, permissions, database helpers, and module libraries.
- `public/` — web entry points and module screens, including reporting and Administrator-only local SQL backup on the paper-compliance branch.
- `database/` — migrations and expected schema snapshot, including migration 007 for reporting fields.
- `tools/` — migration, administrator, and schema verification tools.
- `tests/` — module and application test runners, including B7 report and backup-access tests.
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

The original main branch reflects the initial B1–B6 package. The `john/paper-code-compliance` review branch contains the reporting and backup additions. It does not change the existing BIRMS UI/UX prototype, PowerPoint materials, BPMN, or ERD. Review and run the complete test suite on a disposable `_work` or `_test` database before accepting or deploying these changes.
