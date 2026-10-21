# BIRMS paper-to-code alignment and presentation sign-off

**Review branch:** `john/paper-code-compliance`  
**Review date:** 2026-10-22  
**Purpose:** Compare the checked-in functions against the uploaded compiled Chapters 1-3, the earlier Design and Development paper, the SOE2 / panel-recommendation file, and the repository's documented project decisions.

## Overall finding

The current source code implements the main application flows for the five core functional areas in the compiled Chapters 1-3 paper, plus record searching/retrieval (FR6). The latest verified application-code CI run before the documentation and UI-label changes passed PHP syntax, migrations 001-011 on disposable MariaDB 10.11, **37/37 authentication/security/audit checks**, and **142/142 integration checks**.

That does **not** mean every sentence in every provided document is an exact match. Some uploaded documents describe different or supplementary requirements. Those conflicts are listed below rather than represented as completed functionality. The actual XAMPP/browser test on the presentation computer is still required.

## Core thesis requirements

| Core requirement in compiled Chapters 1-3 | Code/evidence | Alignment |
|---|---|---|
| FR1: User authentication, access control and user-action logging | `app/auth.php`, `app/permissions.php`, `public/admin/audit.php`, `tests/run_tests.php` | Implemented and automated checks passed. The paper says “Admin and Staff”; the software uses four permission profiles: Administrator, Captain, Secretary and Clerk. Treat the latter three as staff-role profiles for the demo, but the exact office permission matrix remains provisional pending owner approval. |
| FR2: Centralized resident information; record creation, update, view and search | `public/residents/`, `app/residents_lib.php`, resident integration tests | Implemented. Household and Purok organization add detail beyond the minimum core wording. Manual ID/voter-check history is not an external COMELEC lookup. |
| FR3: Generate and print Clearance, Indigency and Residency certificates from saved resident details and retain issuance history | `public/requests/`, `public/certificates/`, `app/certificates_lib.php`, B5/B8 tests | Implemented for the three core document types, including print view and server-generated PDF. Official wording, letterhead/layout, signatory and approved fees are not supplied in the repository; the output is prominently marked draft until the owner approves those settings. |
| FR4: Record blotter incidents, complainant/respondent details and monitor case status | `public/blotters/`, `app/blotters_lib.php`, B6 tests | Core incident and case-status workflow is implemented. Hearing/summons functionality is an additional workflow described by the supplemental documents; official summons wording still needs approval. |
| FR5: Compile administrative summaries/statistical reports from saved records | `app/reports_lib.php`, `public/reports/index.php`, B7 tests | Data-driven demographic, issuance, request and blotter summaries and CSV output are implemented. Agency-specific DILG/Mayor/PNP formats were not supplied, so reports must be described as draft summaries, not confirmed official templates. Demographics are a current active-resident snapshot, not a historical period-end count. |
| FR6: Search and retrieve resident and blotter information | Resident, request, issuance-history and blotter lookup/search pages | Implemented and covered by automated integration checks. Actual lookup timing/accuracy still needs local UAT; do not claim a measured speed-up or accuracy percentage before testing. |

## The supplemental files contain different scope

The compiled Chapters 1-3 explicitly define five core functional areas and classify some of the detailed 20-module items as needing confirmation / outside the core Chapter 1 scope. The earlier Design and Development document and the SOE2 file describe a broader feature set. The working branch contains several of those broader features, but this does not automatically make them part of the thesis's approved core scope.

| Supplemental requirement | Current software behavior | Match / remaining action |
|---|---|---|
| Visitor logbook and filed-form archive | Visitor log, linked request, private PDF/JPG/PNG attachment upload/download | Implemented as an add-on. File upload size/permissions must be tested under local Apache/XAMPP. |
| Request queue and status tracking | Database values remain `Pending`, `Review`, `Ready`, `Claimed`, plus `Rejected`/`Cancelled`. The user-facing labels now display `Pending`, `Under Review`, `Ready for Pickup` and `Issued` for the principal stages. | Improves the SOE2 wording without changing tested state values. The repository does **not** store a separate additional `Approved` status between review and Ready for Pickup: approval currently moves a request directly to Ready for Pickup. Explain this workflow honestly in the presentation. |
| Automated SMS alerts | A message is recorded in the outbox as `Logged only` or `No number`; no SMS is dispatched | **Not fully aligned with the SOE2 SMS recommendation.** Do not claim automatic texts are sent. An approved provider, credentials, recipient-consent/usage policy and a successful delivery test are still needed. No paid gateway was guessed or configured. |
| Advanced search by name, Purok and request status | Resident/household/Purok search, request search and tabs, case-party lookup, issuance-history filters | Implemented in the application code; verify target-PC behavior during UAT. |
| Fee matrix/billing and issuance history | Configurable effective-dated fee rules, saved request/issuance amounts, receipt number and a separate pending/reconciled staff-entered cash ledger | Implemented as supplementary over-the-counter billing support. It is **not** an online payment gateway and a reconciled flag is not independent proof of deposit/cash receipt. Official fee-to-tier mapping and reconciliation evidence remain barangay decisions. |
| Dashboard design described in Chapter 3 | Added separate dashboard counts for residents, households, certificates issued this month, requests, revenue and open blotter cases | The displayed overview better reflects the Chapter 3 screen description. The compiled Chapter 2 still classifies an executive dashboard as supplementary pending scope confirmation. |
| First-Time Jobseeker (in the earlier Design and Development document) | No default type/template/fee is seeded. The application supports adding a document type through authorized Settings, subject to its validation. | Not preconfigured because the compiled Chapters 1-3 core list only names Clearance, Indigency and Residency and the project decisions say not to invent fees or wording. Add it only if the owner confirms it is required, then enter approved wording and fee. |
| Voter/ID verification | Authorized staff can record a manual ID or local voter-list check and its history | Manual recording only. There is no COMELEC/PSA/PhilHealth API; this aligns with the thesis limitation against external database integration. |
| Hearing scheduler, summons and referral status | Hearings, outcome workflow, case-party lookup and summons generation/print route | Hearings/summons are supplementary and implemented/tested, but approved summons wording and local printer output must be checked. The code's case statuses are `Pending`, `Active`, `Scheduled`, `Resolved`; it does not separately store SOE2's `Settled` or `Referred to PNP/LGU` states, and no separate referral-tracking workflow exists. The core thesis/project summary uses pending/active/scheduled/resolved, so those statuses were not silently replaced; this remains a supplementary-spec difference to confirm with the panel/system owner. |
| Local/cloud backup | Administrator-only local SQL download; optional HTTPS WebDAV upload only if configured | Local export is implemented. Live cloud upload and a successful restore are **not verified**; scheduled backup/notifications/verified restoration are not included. Present this as optional supplementary functionality, not a proven backup service. |
| Mobile app, public portal, online payments | Not included | Correctly excluded by the thesis limitations. |

## Fixes made during this review

- Added a local XAMPP setup wizard and `SETUP_XAMPP.bat` so the repository can be initialized through a guided local setup instead of hand-creating every file and database setting.
- The wizard targets only `barangay_db_work`, applies the repository migrations, generates private app credentials, uses a limited app DB account, asks for the first admin instead of shipping a default password, and refuses to adopt an unrelated non-empty database without migration history.
- Improved visible request-status wording to match the principal status terms in SOE2 while preserving the internal enum values covered by tests.
- Added explicit separate household and monthly certificate counts to the dashboard, in line with the Chapter 3 dashboard description.
- Removed duplicate backup-configuration/environment-variable entries in the shared PHP configuration.
- Added this file to make the remaining paper differences visible instead of claiming perfect compliance.

The SOE2 document's implementation-status table leaves several functions as `{specify}`, so it is not evidence that those functions passed implementation or acceptance testing. Its note that the SMS gateway was “Under Testing” conflicts with the repository's explicit log-only decision and the absence of an approved provider/account. In this branch, SMS should be treated as **not implemented for actual delivery**, not as a finished feature.

## Local verification is still required

CI runs in GitHub's isolated test environment. It cannot establish that your own PHP extensions, MySQL server/port, folder permissions, browser, PDF reader and printer behave the same way.

1. Download the `john/paper-code-compliance` branch ZIP, extract under `C:\\xampp\\htdocs\\`, and keep the original `barangay_db` untouched.
2. Start Apache and the intended local MySQL/MariaDB server. The one-click installer defaults to `127.0.0.1:3306`; only one service can listen on that port.
3. Run `SETUP_XAMPP.bat`. If it completes, open the localhost URL it prints and configure only fictitious demo records and explicitly draft settings.
4. Follow [PRESENTATION_RUNBOOK.md](PRESENTATION_RUNBOOK.md) to run the two automated test scripts and test the actual browser, PDF, uploads, printer, roles and reports.
5. Record local test results honestly. Until then, the correct sign-off is **automated CI passed; target-PC XAMPP/browser UAT not yet verified**.

## roadmap.sh external development check

I checked roadmap.sh as a general development-quality reference, not as a certification or a source of the barangay's functional requirements. Its [PHP roadmap](https://roadmap.sh/php) covers the relevant areas for this codebase: local server setup, HTTP request handling, files/uploads, sessions, PDO/database migrations, input validation, SQL injection, XSS, CSRF, password hashing, testing/debugging and Apache/web-server configuration. The checked-in application uses prepared-statement PDO helpers, server-side role checks, CSRF/session controls, output escaping, migrations, and automated tests in these areas.

This roadmap reference does not resolve the paper's scope conflicts, approve official barangay settings or replace the local UAT.
