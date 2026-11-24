# BIRMS

Barangay Information and Records Management System.

This branch implements the revised MSIT SRS for BIRMS, including resident self-service, separate service queues, document requests/certificate generation, blotter, venue reservations, role-based processing, reports, and protected backup/restore. It also includes a safe MSIT demo dataset seeder and a lightweight Word-style certificate-template editor with live preview and formatted print/PDF output. Resident notifications use email only. The QR kiosk supports resident self-service; historical SMS records are retained for audit but no SMS is sent. The PowerPoint is a design reference; its sample content is not live system data.

> **Current demonstration build:** use `main`. The revised MSIT candidate from `john/phase2-kiosk-no-sms` was squash-merged through [PR #39](https://github.com/sachie34/Birms/pull/39) at commit `735a5404f71f24ca1d697d61919704274d3b1e07`. Post-merge GitHub Actions run [#1332](https://github.com/sachie34/Birms/actions/runs/45382522696) passed all 8 PHP/MariaDB jobs; each reported 48/48 security and 283/283 module checks, zero failures and zero untested checks. Target-PC XAMPP/kiosk/printer acceptance is still required; the code is prepared for controlled demo use, not production-approved.

## Repository layout

- `app/` — shared PHP bootstrap, authentication, permissions, database helpers, layout, and module libraries.
- `public/` — Apache web entry point and module pages. `public/index.php` handles sign-in; `public/assets/css/birms.css` holds the shared theme; `public/assets/js/app.js` holds shared browser behavior; `public/assets/vendor/` contains local Bootstrap and Bootstrap Icons files.
- `database/` — migrations and expected schema snapshot. Migrations 007–012 add reporting fields, request requirements review, fee matrix/quote snapshots, resident verification history, private attachment metadata, typed signatory snapshots, the cash-collection ledger.
- `tools/` — migration, administrator, schema verification, checksum-manifest generation, and `benchmark.php` timing tools (the benchmark is read-only and refuses databases not named `*_work` or `*_test`).
- `tests/` — module and application test runners, including fee matrix, requirements review, manual verification, reports, backup access, private attachments/PDF, ledger reconciliation, global search and shared UI checks.
- `docs/` — design decisions, implementation notes, setup guidance, and the [project structure review](docs/PROJECT_STRUCTURE.md).
- `storage/` — runtime storage locations; logs and backups are ignored by Git.
  
BIRMS is a PHP application, so its executable entry point is `public/index.php`, not a static root `index.html`. Keep public assets separate from PHP logic and keep credentials, logs, backups, and private uploads outside the public web root. See the [project structure guide](docs/PROJECT_STRUCTURE.md) before proposing file moves.

## Branch provenance

Initial import branch: `integration/import-b6`

Source package: `birms_work_B1-B6.zip` from the uploaded `Claudecodebirms.zip` bundle.

The outer archive also contained earlier B1 and B1–B5 packages and duplicate SQL files; the B1–B6 package was selected as the initial self-contained import. The revised MSIT candidate was later merged into `main` through PR #39. The source branch `john/phase2-kiosk-no-sms` is retained as merge history; `main` is now the authoritative defense build.

## Download and run locally (XAMPP)

For the revised MSIT demo build, download the [current main-branch ZIP](https://github.com/sachie34/Birms/archive/refs/heads/main.zip), extract it under `C:\xampp\htdocs\`, and rename the extracted folder to `Birms-main` if you want to use the paths in the runbook. Start Apache and the intended local MySQL/MariaDB server, then run `SETUP_XAMPP.bat`. The wizard initializes the separate `barangay_db_work` database, applies the current migrations, writes private local configuration, and guides you through creating the first administrator. It does not import or modify the original `barangay_db`.

### Load the ready-made demo records

After setup and administrator creation, open Command Prompt in the project folder and first review the read-only plan:

```bat
C:\xampp\php\php.exe tools\seed_msit_demo_data.php
```

If it confirms `barangay_db_work`, apply the sample records:

```bat
C:\xampp\php\php.exe tools\seed_msit_demo_data.php --apply
```

This adds fictitious residents, demo-only certificate templates and requests, sample blotter cases, venue reservations and queue tickets. It creates no users or default passwords and sends no email. Then open **System Settings → Documents and fees** as Admin or Secretary to edit the certificate wording with the formatting toolbar and live preview. Secretary can edit wording only; Admin controls fees, availability and signature policy. Read [docs/DEMO_DATA.md](docs/DEMO_DATA.md) before seeding.

**UI/UX status: consistent across all interactive tabs.** The same sidebar, top bar, global search, page container and shared theme are exercised across 57 role-accessible menu routes in the current main-branch automated UI checks. See the [UI/UX consistency audit](docs/UI_UX_CONSISTENCY_AUDIT.md). Page content varies by module, and the live pages are not claimed to be pixel-identical to the PowerPoint mockups.

Read [the XAMPP setup guide](docs/README_XAMPP.md), [the MSIT XAMPP acceptance runbook](docs/MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md), [the panel demo status and sequence](docs/PANEL_DEMO_STATUS.md), [the timed panel demo run sheet](docs/PANEL_DEMO_RUNSHEET.md), [the panel defense Q&A](docs/PANEL_DEFENSE_QA.md), [the demo-data seeder guide](docs/DEMO_DATA.md), [the quick user workflow](docs/QUICK_USER_WORKFLOW.md), and [the presentation runbook](docs/PRESENTATION_RUNBOOK.md). The [20-function verification file](docs/FINAL_20_FUNCTION_VERIFICATION.md) is a historical audit snapshot, not the current role or acceptance authority. Configure only fictitious demo records and confirm the official settings with the system owner. Automated CI passing does not mean target-PC browser/printer UAT has passed.

## Local setup

Follow [`docs/README_XAMPP.md`](docs/README_XAMPP.md) for the intended PHP, MariaDB/MySQL, Apache, and XAMPP setup. Copy `app/config.local.php.example` to `app/config.local.php` and provide local credentials outside Git.

Do not commit database passwords, generated logs, backups, or local configuration files.

## Verification commands

```bat
cd /d C:\xampp\htdocs\Birms-main
C:\xampp\php\php.exe tests\run_tests.php http://localhost/Birms-main/public --root=http://localhost/Birms-main
C:\xampp\php\php.exe tests\run_modules.php http://localhost/Birms-main/public
```

Start Apache and MySQL first. These commands are for the example XAMPP folder above; adjust the URL if your folder name differs. The test harness refuses to run unless the configured database name ends in `_work` or `_test`. The module test runner refuses to run while real email notifications are enabled, because test workflows can create outbound notifications. Never run the integration suites against the original or production database.

## Security note

The current code does not read a root-level file named `Api`; it was removed because it contained an unexplained credential-looking value and had no application use. If that value was a real API key or token, revoke it and create a replacement: removing a file in a later commit does not remove its earlier copies from Git history. Use only ignored local configuration (`app/config.local.php`) for application settings and the server's private mail transport configuration or protected server environment variables for credentials. If you are extracting over an older folder instead of using a clean folder, manually delete the old `Api` file.

After extracting the repository, refresh the local SHA-256 manifest with `C:\\xampp\\php\\php.exe tools\\generate_manifest.php` if you need a checksum snapshot of that working copy.

## Scope boundary

The `main` branch now contains the revised MSIT demonstration build. The older B1–B12 workflow is historical and must not be presented as the current MSIT workflow. Use the `main` ZIP linked above. The uploaded PowerPoint, BPMN and ERD remain separate reference/design artifacts; the live app uses its own data-backed pages. Run the complete test suite on a disposable `_work` or `_test` database before presenting.

Read the workflow run attached to the exact commit or pull request you are reviewing. Results from an older branch do not certify newer changes. The CI workflow checks PHP syntax, include paths, XAMPP preflight, migrations, authentication/security/audit behavior, and module integration on a disposable MariaDB database. Historical SMS provider tests have been removed; no SMS integration is active. Browser/UAT on the target office PC, official template approval, live email delivery, and live cloud backup/restore are separate acceptance checks. See [the paper-to-code audit](docs/PAPER_CODE_COMPLIANCE_AUDIT.md), [the UAT/performance worksheet](docs/UAT_PERFORMANCE_EVALUATION.md), and [the Lab Activity 1 remediation status and UAT record](docs/BIRMS_LAB_ACTIVITY1_REMEDIATION_STATUS.md).

## Important scope boundaries

- Fee rules must be entered from barangay-approved rates; no sample rates are seeded. Issuance history now sums entries in the separate cash-collection ledger and filters reconciled/unreconciled entries. A ledger amount is staff-recorded data, not independent proof of a bank deposit; older paid issuances without entries must be reviewed and reconciled.
- Resident verification is recorded manually and does not contact COMELEC or another external database.
- Backup supports a local SQL download and an optional one-click HTTPS WebDAV upload. Configure `BIRMS_BACKUP_WEBDAV_URL`, `BIRMS_BACKUP_WEBDAV_USERNAME`, and `BIRMS_BACKUP_WEBDAV_PASSWORD` in the server environment or private `app/config.local.php`; use an approved private folder and a dedicated app password. No cloud account/credentials are included in the repository. Scheduled backup, notifications, and a verified restoration are not included. Test restoration in a separate scratch database.
- Resident notifications are email-only. Existing SMS outbox tables and migration history are retained to preserve older records, but the application no longer sends SMS or exposes SMS administration.
- Visitor/request attachments accept PDF/JPG/PNG up to 10 MB and are stored with random names outside the public web root; downloads are permission-checked and checksum-checked.
- Certificates can be downloaded as server-generated PDFs (Dompdf when installed, otherwise an offline-compatible PDF writer). The template is conspicuously marked DRAFT pending official layout approval. Typed signature names are not cryptographically verified digital signatures.
- Official certificate and summons text, fee rates, role permissions, and standard agency report templates require owner approval. Office-PC XAMPP/browser UAT and process timing/accuracy metrics must be recorded only after they are actually tested.
