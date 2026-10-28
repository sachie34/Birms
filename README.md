# BIRMS

Barangay Information and Records Management System.

This repository contains the BIRMS application for Barangay Wawa, Balagtas, Bulacan, including the B1–B12 presentation modules, safe demo data seeder, resident CSV import, and optional SMS API PH, Semaphore or Infobip delivery (all disabled unless explicitly configured). The paper-alignment and presentation-readiness changes have been merged into `main`: data-derived reports/dashboard figures, request requirements review, fee matrix/cash ledger, manual identity/voter verification history, private attachments/PDF output, local SQL export, global record search, UI consistency checks, and a guided XAMPP setup. The PowerPoint is a design reference; its sample content is not live system data.

## Repository layout

- `app/` — shared PHP bootstrap, authentication, permissions, database helpers, layout, and module libraries.
- `public/` — Apache web entry point and module pages. `public/index.php` handles sign-in; `public/assets/css/birms.css` holds the shared theme; `public/assets/js/app.js` holds shared browser behavior; `public/assets/vendor/` contains local Bootstrap and Bootstrap Icons files.
- `database/` — migrations and expected schema snapshot. Migrations 007–012 add reporting fields, request requirements review, fee matrix/quote snapshots, resident verification history, private attachment metadata, typed signatory snapshots, the cash-collection ledger, and optional SMS provider tracking.
- `tools/` — migration, administrator, schema verification, checksum-manifest generation, and `benchmark.php` timing tools (the benchmark is read-only and refuses databases not named `*_work` or `*_test`).
- `tests/` — module and application test runners, including fee matrix, requirements review, manual verification, reports, backup access, private attachments/PDF, ledger reconciliation, global search and shared UI checks.
- `docs/` — design decisions, implementation notes, setup guidance, and the [project structure review](docs/PROJECT_STRUCTURE.md).
- `storage/` — runtime storage locations; logs and backups are ignored by Git.
  
BIRMS is a PHP application, so its executable entry point is `public/index.php`, not a static root `index.html`. Keep public assets separate from PHP logic and keep credentials, logs, backups, and private uploads outside the public web root. See the [project structure guide](docs/PROJECT_STRUCTURE.md) before proposing file moves.

## Branch provenance

Initial import branch: `integration/import-b6`

Source package: `birms_work_B1-B6.zip` from the uploaded `Claudecodebirms.zip` bundle.

The outer archive also contained earlier B1 and B1–B5 packages and duplicate SQL files; the B1–B6 package was selected as the initial self-contained import. The additional B7/B8 and presentation-readiness work has now been merged into `main`.

## Download and run locally (XAMPP)

For the presentation build, download the [one-click BIRMS ZIP from main](https://github.com/sachie34/Birms/archive/refs/heads/main.zip), extract the entire folder under `C:\xampp\htdocs\`, rename the extracted folder to `birms_work`, start Apache and the intended local MySQL/MariaDB server, then run `SETUP_XAMPP.bat`. The wizard initializes a separate `barangay_db_work` database, applies migrations 001-012, writes the private local configuration, and guides you through creating the first administrator. It does not import or modify the original `barangay_db`.

**UI/UX status: consistent across all interactive tabs.** The same sidebar, top bar, global search, page container and shared theme were tested across 52 role-accessible menu routes. See the [UI/UX consistency audit](docs/UI_UX_CONSISTENCY_AUDIT.md). Page content varies by module, and the live pages are not claimed to be pixel-identical to the PowerPoint mockups.

Read [the XAMPP setup guide](docs/README_XAMPP.md), [the demo-data seeder guide](docs/DEMO_DATA.md), [the optional SMS API PH setup guide](docs/SMSAPIPH_SMS.md), [the optional Semaphore SMS setup guide](docs/SEMAPHORE_SMS.md), [the optional Infobip SMS setup guide](docs/INFOBIP_SMS.md), [the resident CSV import guide](docs/RESIDENT_CSV_IMPORT.md), [the external-guidance scope review](docs/EXTERNAL_GUIDANCE_REVIEW.md), [the paper-to-code alignment matrix](docs/PAPER_CODE_ALIGNMENT.md), [the final 20-function verification checklist](docs/FINAL_20_FUNCTION_VERIFICATION.md), [the quick user workflow](docs/QUICK_USER_WORKFLOW.md), and [the presentation runbook](docs/PRESENTATION_RUNBOOK.md). Configure only fictitious demo records and confirm the official settings with the system owner. Automated CI passing does not mean target-PC browser/printer UAT has passed.

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

## Security note

The current code does not read a root-level file named `Api`; it was removed because it contained an unexplained credential-looking value and had no application use. If that value was a real API key or token, revoke it and create a replacement: removing a file in a later commit does not remove its earlier copies from Git history. Use only ignored local configuration (`app/config.local.php`, `app/infobip.local.php`, or `app/smsapiph.local.php`) or protected server environment variables for credentials. If you are extracting over an older folder instead of using a clean folder, manually delete the old `Api` file.

After extracting the repository, refresh the local SHA-256 manifest with `C:\\xampp\\php\\php.exe tools\\generate_manifest.php` if you need a checksum snapshot of that working copy.

## Scope boundary

The current `main` branch is the one-click presentation build. It includes the paper-aligned additions and a shared UI shell across role-accessible tabs. The uploaded PowerPoint, BPMN and ERD remain separate reference/design artifacts; the live app uses its own data-backed pages. Run the complete test suite on a disposable `_work` or `_test` database before presenting.

Read the workflow run attached to the exact commit or pull request you are reviewing. Results from an older branch do not certify newer changes. The CI workflow checks PHP syntax, include paths, XAMPP preflight, migrations, authentication/security/audit behavior, and module integration on a disposable MariaDB database. Mocked SMS tests never send a real text. Browser/UAT on the target office PC, official template approval, live provider delivery, and live cloud backup/restore are separate acceptance checks. See [the paper-to-code audit](docs/PAPER_CODE_COMPLIANCE_AUDIT.md), [the UAT/performance worksheet](docs/UAT_PERFORMANCE_EVALUATION.md), and [the Lab Activity 1 remediation status and UAT record](docs/BIRMS_LAB_ACTIVITY1_REMEDIATION_STATUS.md).

## Important scope boundaries

- Fee rules must be entered from barangay-approved rates; no sample rates are seeded. Issuance history now sums entries in the separate cash-collection ledger and filters reconciled/unreconciled entries. A ledger amount is staff-recorded data, not independent proof of a bank deposit; older paid issuances without entries must be reviewed and reconciled.
- Resident verification is recorded manually and does not contact COMELEC or another external database.
- Backup supports a local SQL download and an optional one-click HTTPS WebDAV upload. Configure `BIRMS_BACKUP_WEBDAV_URL`, `BIRMS_BACKUP_WEBDAV_USERNAME`, and `BIRMS_BACKUP_WEBDAV_PASSWORD` in the server environment or private `app/config.local.php`; use an approved private folder and a dedicated app password. No cloud account/credentials are included in the repository. Scheduled backup, notifications, and a verified restoration are not included. Test restoration in a separate scratch database.
- SMS API PH, Semaphore and Infobip are optional providers, disabled by default. SMS API PH uses a fixed HTTPS send endpoint and an API key in private configuration; mocked tests cover request shape, number normalization, status handling and duplicate-send protection. Confirm live behavior only with an authorized test number, provider credentials and the provider dashboard. SMS API PH has no documented status-refresh endpoint in the supplied quick-start, so BIRMS does not offer Refresh for that provider. BIRMS does not expose a public REST API in this feature.
- Visitor/request attachments accept PDF/JPG/PNG up to 10 MB and are stored with random names outside the public web root; downloads are permission-checked and checksum-checked.
- Certificates can be downloaded as server-generated PDFs (Dompdf when installed, otherwise an offline-compatible PDF writer). The template is conspicuously marked DRAFT pending official layout approval. Typed signature names are not cryptographically verified digital signatures.
- Official certificate and summons text, fee rates, role permissions, and standard agency report templates require owner approval. Office-PC XAMPP/browser UAT and process timing/accuracy metrics must be recorded only after they are actually tested.
