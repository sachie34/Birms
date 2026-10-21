# Paper-to-Code Compliance Audit — BIRMS

**Auditor:** John — BIRMS Paper-to-Code Compliance & Functionality Auditor  
**Repository:** `sachie34/Birms`  
**Audit branch:** `john/paper-code-compliance`  
**Audit date:** 2026-10-22
**Verification status (2026-10-22):** Prior baseline run [#244](https://github.com/sachie34/Birms/actions/runs/39627101927) passed syntax, migrations 001–011, 37 authentication/security/audit checks and 133 module checks. The newer periodic-report and optional WebDAV backup changes are under a fresh CI run. Target-office XAMPP/browser UAT remains **NOT RUN**.

## Source-of-truth order

1. The submitted research paper defines the approved study objectives, core modules, scope, and limitations.
2. The two five-module process-documentation files define additional detailed workflows submitted by the team. These must be mapped to the approved paper before treating them as mandatory system scope.
3. Existing repository code, schema, tests, and setup notes determine what is actually implemented.
4. Never invent official barangay fees, Purok names, certificate wording, or SMS-provider settings.

## Paper-scope decisions required before final submission

The ten detailed modules supplied by Ron and Kurt are not all described as core scope in Chapters 1–3. Chapter 2, Table 2.2, marks Visitor Logbook/Form Filing, Multi-Tier Fee Matrix & Billing, Document Request Queue Tracker, Document Issuance History, Executive Overview Dashboard, and Cloud Backup/Data Export as **Confirm** or outside the Chapter 1 core areas. Authentication/RBAC, action logging, and periodic report generation are supported by the core paper requirements.

The current review branch implements several supplementary functions. To avoid a paper-system mismatch at defense, either revise Chapters 1–3 to include the approved supplementary features or identify them as optional/out-of-core functions in the final system description. The module documentation’s roles (Admin, Captain, Secretary, Clerk) differ from the paper’s Admin/Staff description; the code’s permissions are explicitly provisional pending confirmation. The fee matrix supports configured rates, but approved standard/business fee tiers must be entered before the application can calculate those services; rates are not invented by code.


## Core paper requirements

| Paper requirement | Evidence checked | Initial finding | Required action |
|---|---|---|---|
| User authentication and role-based access | `app/permissions.php`, `public/index.php`, XAMPP guide | Login and server-side permission gates exist. Roles are explicitly marked **PROVISIONAL** and use four roles, while the research paper's scope describes Admin/Staff levels. | Validate the final role matrix with the paper/panel and barangay before changing permissions. Keep server-side denial tests for every role and protected route. |
| Resident profiling, editing, search, demographic data | Resident forms/profile, `app/residents_lib.php`, `public/residents/view.php`, migrations 007 and 010 | Registration, editing, household/Purok assignment, resident search/filters, residency status, voter-status recording, demographic grouping, and a manual identity/voter-list verification history are implemented. The verification feature does not query COMELEC or external databases. | Automated resident and verification tests pass. Confirm the approved categories and perform browser tests with demo data. |
Server-side PDF generation is implemented (Dompdf when installed, offline-compatible fallback otherwise) and conspicuously labels output as draft pending official approval.
| Blotter and incident tracking | Menu, `app/blotters_lib.php` | Blotter statuses and hearing outcomes/rules exist in shared code. | Verify incident details, complainant/respondent, date/place, hearings/summons, status transitions, and case history end to end. |
Database-backed reports cover demographics, issuance, requests and blotters. A combined periodic administrative report selects DILG, Mayor’s Office, PNP or internal use and supports period selection with print/CSV output. Reconciled cash-ledger revenue is distinguished from issuance fee snapshots; population remains a current snapshot, not historical period-end population.
| Functional usability, data accuracy, retrieval speed evaluation | Research paper objective 6; `tools/benchmark.php`, `docs/UAT_PERFORMANCE_EVALUATION.md` | A read-only timing tool and UAT worksheet have been added. Automated integration tests cover data and workflow behavior, but no actual office-PC times or paper-vs-digital measurements have yet been recorded. | Run the benchmark on a representative `*_work` or `*_test` dataset; have a tester measure the same paper-based tasks with a stopwatch and record the actual results. Do not invent performance figures. |

## Team process-documentation workflows

| Workflow | Initial status based on available repository evidence | Compliance work still needed |
|---|---|---|
| Visitor Logbook & Form Filing | Visitor Logbook menu entry is marked built. | Confirm timestamping, purpose, classification, form/file reference, validation, update, search, and filing confirmation. |
| Automated Certificate Generation | Certificate issue/history routes are represented in the menu and shared library. | Verify all documented input-to-output steps and actual issuance history. |
| Multi-Tier Fee Matrix & Billing | Migration 009; `public/admin/settings.php`, `public/requests/form.php`, `public/certificates/issue.php`, `app/reports_lib.php` | Configurable effective-dated category/tier rules, unit rates, quantity bounds, overlap checks, request fee quotes, and immutable issuance snapshots are implemented. No default/official rates are seeded. Migration 011 adds a separate cash-collection ledger linked to each issuance, with staff-recorded amount/method/collector and pending/reconciled status. The ledger amount is not independent proof of money received; the issuance snapshot remains a separate immutable record. Legacy entries require manual review/backfill where reliable receipt evidence exists. | Automated tests exercise rule configuration, role denial, overlap rejection, quantity calculation, request-to-issuance snapshot, and report breakdown. Enter only approved barangay rates and reconcile O.R. records separately. |
| Document Request Queue & Processing Tracker | Migration 008; `public/requests/form.php`, `public/requests/action.php`, `app/certificates_lib.php` | The form records Not checked/Incomplete/Complete and follow-up notes. Approval to Ready and certificate issue are blocked until requirements are marked Complete. Queue statuses remain Pending/Review/Ready/Claimed/Rejected/Cancelled. | Automated tests cover missing requirement notes, blocked approval, valid review/approval, and certificate blockers. Confirm the barangay's actual checklist and stage terminology; the code does not invent supporting documents. |
| Document Issuance History | Issuance History menu entry is marked built. | Verify each issued document is linked to resident/request, reference number, issue timestamp, issuing personnel, searchable history, and authorized reporting. |
| User Authentication & RBAC | Shared authentication and permissions code exists. | Role map remains provisional. Confirm each role's intended access against approved paper and role decisions; test direct URL/POST access, not just hidden menus. |
Separate data-backed reports and a combined monthly/quarterly administrative report are implemented, with recipient selection, print/CSV and explicit distinctions between period records and current population.
| System Audit Trail & Action Logger | Audit screen and audit helpers are referenced by menu/dashboard. | Verify all defined create/update/archive/print/status/security actions are logged with actor, timestamp, module/record reference, and outcome. |
Shows database-derived residents/population by Purok, active requests, monthly issuance and open blotter cases. Revenue collected uses reconciled cash-ledger entries, while pending entries are shown separately.
Administrator-only SQL download is implemented. Optional one-click cloud transfer supports configured HTTPS WebDAV storage with TLS verification and audit events; credentials are not bundled in Git.

## Complete mapping of the supplementary 20-function team document

The following table tracks the additional 20 assigned functions in `BIRMS_Project_Summary_Documentation`. These supplement the thesis's five core modules; this table does not silently expand the approved thesis scope. “Implemented” means code and/or automated integration tests support the behavior. It does not replace target-PC UAT.

| # | Documented function | Current code flow / status | Remaining limit or verification |
|---:|---|---|---|
| 1 | Resident Profile Registration | **Implemented** — resident form validates and saves profile, household, Purok, category, demographics, voter status and employment category; B3 tests cover create/edit/validation. | Confirm form labels and required fields against the final data-collection instrument. |
| 2 | Purok-Based Masterlist Organization | **Implemented** — residents can be searched/filtered by household code, Purok, category and residency status; demographic report groups current population by Purok. | Enter the barangay-approved Purok list and reconcile the masterlist to authorized demo/source records. |
| 3 | COMELEC Voter & ID Verification | **Partially implemented within thesis scope** — authorized staff can record an in-person ID check or local voter-list check, including method, outcome, staff and date; a voter outcome updates the resident's saved voter status. | No COMELEC API, external database lookup, or automated identity proofing is performed. The main thesis expressly excludes external national-database integration. Do not claim COMELEC verification. |
| 4 | Demographic Breakdown | **Implemented and integration-tested** — database-derived current active-resident summaries by configured age bracket, sex, civil status, employment status, voter status and Purok. | Confirm the barangay's desired age brackets/category labels and reconcile summary totals to the working database. |
| 5 | Present Address & Status Tracking | **Implemented** — resident records link to a household address/Purok and keep Active/Relocated status; archived profiles are handled separately. | This is current-state tracking, not a separate historical address-change timeline. If the owner needs a full address-history ledger, approve it as an explicit additional requirement. |
PDF/JPG/PNG attachments (max 10 MB) are stored privately, checksummed and permission-checked on download.
Server-generated PDFs are available, but output remains marked draft pending official layout approval. Confirm final template and authorized Captain/Secretary signatory; typed name is not a cryptographically verified signature.
| 8 | Multi-Tier Fee Matrix & Billing | **Implemented for rule configuration and quotation** — authorized staff configure effective-dated category/tier rates and quantity bounds; the request stores the quote and the issuance keeps the fee-rule snapshot. An O.R. number is stored for paid issuance. | No official rates are seeded. Paid issuance creates a Pending ledger row; an Administrator can manage and reconcile entries, and issuance history/CSV use ledger amounts and reconciliation filters. Ledger amounts are staff-entered, not independent proof of cash/deposit. Other reports/dashboard retain their saved-issuance fee measures unless labelled otherwise. |
| 9 | Document Request Queue Tracker | **Implemented and integration-tested** — Pending/Review/Ready/Claimed/Rejected/Cancelled workflow, assignment, requirements status/notes, and server-side gate preventing Ready/issuance before requirements are Complete. | Confirm the final real-office checklist and wording for each request type; no specific supporting document is invented by code. |
| 10 | Document Issuance History | **Implemented and integration-tested** — search/filter by record, type and date, with stored certificate/reference, saved fee and O.R. details; authorized CSV export. | Confirm history results against a controlled set of issued demo records. |
| 11 | Incident & Complaint Logging | **Implemented** — blotter records capture case date/place/narrative and parties, including complainant/respondent details; case edits and audit history use existing forms/libraries. | Verify official intake fields and approved privacy/retention procedures with the barangay. |
| 12 | Active/Pending Case Status Tracker | **Implemented** — Pending/Active/Scheduled/Resolved states are governed by permitted transitions; hearing scheduling affects case schedule/status. | Conduct a complete case lifecycle browser test using fictitious cases. |
| 13 | Respondent/Complainant Lookup | **Implemented as an internal possible-match search** — searches recorded case parties locally and shows possible matching blotter cases in the applicable clearance flow. | A name match is not identity proof. The check currently informs staff; it does not automatically adjudicate or categorically block certificate issuance. Staff must review matches. |
| 14 | Hearing & Summons Scheduler | **Implemented in code** — hearings have scheduled time, venue and outcome; summons use official text configured by the Secretary and are blocked while required details are absent. | Approved summons wording and browser print appearance still need office review. |
| 15 | Incident Summary & Safety Metrics | **Implemented and integration-tested** — monthly/quarterly/annual blotter report includes status totals, incident type, Purok and resolution rate. | Higher-agency report layouts/KPIs are not considered approved until sample forms are provided and accepted. |
| 16 | User Authentication & RBAC | **Implemented and integration-tested** — password-protected login, server-side permission checks, CSRF protection, session controls and role-denial tests; Administrator has all currently defined module permissions. | The Captain/Secretary/Clerk matrix remains provisional pending owner approval and must be demonstrated using distinct test accounts. |
| 17 | Automated Periodic Report Generator | **Implemented for local data summaries** — monthly, quarterly and annual reports for requests, issuances/recorded fees and blotter cases; demographic report and authorized CSV/print. | DILG/Mayor/PNP standardized output templates and mappings have not been supplied/approved, so do not claim compliance with an official agency template yet. |
| 18 | System Audit Trail & Action Logger | **Implemented across key tested flows** — logs actor, event/action, module/entity, result, time and remarks for resident, request, issue/print, settings, access-denial, verification, fee-rule and backup events. | Verify any additional office-specific actions in UAT before claiming every possible system action is logged. |
| 19 | Executive Overview Dashboard | **Implemented and integration-tested** — live database counts for residents, requests, issuance counts/saved fees, blotter cases, role-authorized users/audit activity and resident population by Purok. | Fee figures are labelled as saved issuance amounts, not reconciled cash revenue. Compare each card with direct database counts in UAT. |
| 20 | Cloud Backup & Data Export | **Partially implemented** — Administrator-only local SQL export with audit logging and tests for server-side access restrictions. | Cloud storage, schedule/automation, notifications and a proven restoration are not implemented. Test a restore into a separate scratch database before relying on this export. |

## Scope conflicts to resolve before changing behavior

- The research paper's core scope names five modules: Authentication, Resident Management, Certificate Generation, Blotter Tracking, and Report Generation. The process documents add visitor logs, fee matrix/billing, request queue, issuance history, audit trail, dashboard, and backup/export. Treat these as detailed or supplemental workflows and map them to the approved study scope; do not rewrite the paper's scope by assumption.
- The paper describes Admin/Staff access in its scope, while the supplementary process document identifies Administrator, Captain, Secretary, and Clerk. The Administrator is mapped to all defined module permissions, but the Captain/Secretary/Clerk matrix remains provisional and should be approved by the Barangay Secretary / system owner.
- Report functionality is implemented and integration-tested on disposable MariaDB, but office-data reconciliation, actual XAMPP/browser checks, retrieval-time measurements and approved agency output templates remain outstanding.
- The paper excludes online payment gateways, resident self-service accounts, native mobile apps, and external national-LGU database integrations. Do not add these as part of this compliance pass.
- The second submitted paper contains an unresolved `{specify}` placeholder for the documents covered by communication/hearing notices. Do not infer a new feature from that placeholder.
- SMS is currently described in the repository setup notes as **log-only**. The paper's later objectives and panel recommendations request automated SMS. This is a documented gap until a real gateway is configured, tested, and authorized; do not claim messages are sent when they are only logged.

## Safe implementation and verification rules

1. Work on this branch and open a pull request; do not modify the original database or commit local credentials.
2. Inspect migrations and actual table/column names before writing report queries or changing schema.
3. Keep automated tests restricted to a database whose name ends in `_work` or `_test`.
4. Add regression tests for each corrected/implemented workflow, including role-denial cases and invalid/missing input.
5. Run PHP syntax checks, `php tests/run_tests.php`, and `php tests/run_modules.php` against a configured working database. `run_modules.php` includes request review, fee matrix, verification, reporting, backup, attachments, server-side PDF, ledger and reconciliation checks; record the real outputs.
6. Browser/XAMPP verification remains outstanding until run on the target office PC; the checklist is in `docs/BIRMS_LAB_ACTIVITY1_REMEDIATION_STATUS.md`.
7. Mark each requirement **Implemented and tested**, **Partially implemented**, **Missing**, or **Blocked by an unresolved decision**. Never mark a feature complete based only on a menu item or code file existing.

## Current audit conclusion

The review branch includes the core resident, certificate, blotter, authentication and report workflows plus data-driven request/issuance/blotter reports and dashboard indicators. Added changes include migration 008 (request requirements review and an approval/issuance gate), migration 009 (effective-dated configurable fee matrix and request/issuance snapshots), migration 010 (manual identity/voter verification history), and migration 011 (private visitor/request attachments, typed signatory snapshot, and a separate cash-collection ledger). The branch also includes server-side PDF download, a draft certificate notice, reconciliation-aware issuance history and CSV export, report CSV/print views, and an Administrator-only local SQL export. A read-only retrieval/report benchmark tool and a UAT/performance worksheet were also added. Latest CI verification for this remediation passed PHP syntax, migrations 001–011 on disposable MariaDB, **37 authentication/security/audit checks**, and **133 module integration checks** (including the 14 B8 upload/PDF/ledger/history checks), with zero failures and zero untested checks. Run: https://github.com/sachie34/Birms/actions/runs/39627101927.

**Still not verified / deliberately not claimed:** target-XAMPP/browser UAT; official role-matrix approval; official certificate/summons wording and fee rates; manual-vs-digital retrieval timings; office-data reconciliation; a successful backup restoration; cloud/scheduled backup; actual SMS delivery; and external COMELEC lookup. The verification log records a staff check and does not contact COMELEC. The fee matrix quotes an approved rate but does not prove cash was received. SMS remains log-only.

This report records the current automated verification and remaining gaps; it is not a substitute for end-to-end testing against a configured XAMPP working copy.


## Lab Activity 1 remediation update — 2026-10-21

The code now contains these changes on the review branch:

- **Visitors Logbook & Form Filing:** PDF/JPG/PNG uploads (10 MB maximum), randomized non-public storage paths, file metadata and SHA-256 hashes, authorized downloads, and links from visitor/request views.
- **Automated Certificate Generation:** server-generated PDF endpoint (Dompdf when installed; offline-compatible PDF writer fallback), typed signatory snapshot, and explicit draft/pending-approval labels. This is a typed-name field, not a cryptographic digital-signature service.
- **Multi-Tier Fee Matrix & Billing:** a separate per-issuance cash-collection ledger with date, requester, amount, method, collector, pending/reconciled state, timestamps, notes, audit trail and an Administrator UI. Paid certificate issuance creates a pending ledger row; the Administrator can record missing eligible legacy rows.
- **Document Request Queue:** file attachments are shown on request details; automated tests check the implemented flow, but official requirement checklists still must be provided/approved. Office-PC browser UAT is not complete.
- **Document Issuance History:** displayed total now sums ledger amounts, not issuance snapshot amounts; the original issuance snapshot remains separately labeled. Reconciled/unreconciled filtering also applies to CSV export. A missing entry contributes zero to the ledger total and is shown explicitly; legacy rows should be reviewed before financial use.

**Important verification status:** source changes and the automated test suite pass in CI. The target office PC is not accessible from this editing session, so XAMPP/browser UAT remains **NOT RUN**. No accuracy, retrieval-time, or performance target is claimed as achieved. The official certificate layout, text, typed-signature treatment, fee categories/rates, and request requirement checklist remain subject to stakeholder approval.
