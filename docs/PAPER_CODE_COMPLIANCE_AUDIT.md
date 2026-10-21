# Paper-to-Code Compliance Audit — BIRMS

**Auditor:** John — BIRMS Paper-to-Code Compliance & Functionality Auditor  
**Repository:** `sachie34/Birms`  
**Audit branch:** `john/paper-code-compliance`  
**Audit date:** 2026-10-21  
**Status:** Current review branch passes automated PHP syntax checks, migrations 001–010 on disposable MariaDB, 37 authentication/security/audit tests, and 118 module integration tests. Target-XAMPP browser testing and UAT are still required before claiming full compliance.

## Source-of-truth order

1. The submitted research paper defines the approved study objectives, core modules, scope, and limitations.
2. The two five-module process-documentation files define additional detailed workflows submitted by the team. These must be mapped to the approved paper before treating them as mandatory system scope.
3. Existing repository code, schema, tests, and setup notes determine what is actually implemented.
4. Never invent official barangay fees, Purok names, certificate wording, or SMS-provider settings.

## Core paper requirements

| Paper requirement | Evidence checked | Initial finding | Required action |
|---|---|---|---|
| User authentication and role-based access | `app/permissions.php`, `public/index.php`, XAMPP guide | Login and server-side permission gates exist. Roles are explicitly marked **PROVISIONAL** and use four roles, while the research paper's scope describes Admin/Staff levels. | Validate the final role matrix with the paper/panel and barangay before changing permissions. Keep server-side denial tests for every role and protected route. |
| Resident profiling, editing, search, demographic data | Resident forms/profile, `app/residents_lib.php`, `public/residents/view.php`, migrations 007 and 010 | Registration, editing, household/Purok assignment, resident search/filters, residency status, voter-status recording, demographic grouping, and a manual identity/voter-list verification history are implemented. The verification feature does not query COMELEC or external databases. | Automated resident and verification tests pass. Confirm the approved categories and perform browser tests with demo data. |
| Certificate requests and generation (clearance, indigency, residency) | `public/requests/form.php`, `public/requests/action.php`, `app/certificates_lib.php`, `public/certificates/issue.php`, history/print routes | Request, requirements review, approval, issuance and printing flow through existing routes. Paid issuance requires an O.R. number. A saved fee-matrix quote can be used instead of the legacy per-document fee. The screen is browser-printable; a separate server-side PDF library has not been added. | Automated tests verify blockers, one issuance per request, stored output and fee snapshots. Confirm the barangay's approved wording, layout, rates and print output in browser UAT. |
| Blotter and incident tracking | Menu, `app/blotters_lib.php` | Blotter statuses and hearing outcomes/rules exist in shared code. | Verify incident details, complainant/respondent, date/place, hearings/summons, status transitions, and case history end to end. |
| Report generation and statistical summaries | `app/reports_lib.php`, `public/reports/index.php`, `tests/mod_b7.php` | Reports are queried from saved records and cover current resident demographics, monthly/quarterly/annual issuance and saved fee amounts, request queue by status/document type/assignee, and blotter statuses/incident type/Purok/resolution rate. CSV export and browser printing are available to authorized roles. | CI report tests pass. Reconcile figures against database counts and confirm any official agency-specific templates with the system owner.
| Functional usability, data accuracy, retrieval speed evaluation | Research paper objective 6; `tools/benchmark.php`, `docs/UAT_PERFORMANCE_EVALUATION.md` | A read-only timing tool and UAT worksheet have been added. Automated integration tests cover data and workflow behavior, but no actual office-PC times or paper-vs-digital measurements have yet been recorded. | Run the benchmark on a representative `*_work` or `*_test` dataset; have a tester measure the same paper-based tasks with a stopwatch and record the actual results. Do not invent performance figures. |

## Team process-documentation workflows

| Workflow | Initial status based on available repository evidence | Compliance work still needed |
|---|---|---|
| Visitor Logbook & Form Filing | Visitor Logbook menu entry is marked built. | Confirm timestamping, purpose, classification, form/file reference, validation, update, search, and filing confirmation. |
| Automated Certificate Generation | Certificate issue/history routes are represented in the menu and shared library. | Verify all documented input-to-output steps and actual issuance history. |
| Multi-Tier Fee Matrix & Billing | Migration 009; `public/admin/settings.php`, `public/requests/form.php`, `public/certificates/issue.php`, `app/reports_lib.php` | Configurable effective-dated category/tier rules, unit rates, quantity bounds, overlap checks, request fee quotes, and immutable issuance snapshots are implemented. No default/official rates are seeded. A dedicated reconciled cash ledger is not present; issued amounts are stored fee amounts, not proof of money received. | Automated tests exercise rule configuration, role denial, overlap rejection, quantity calculation, request-to-issuance snapshot, and report breakdown. Enter only approved barangay rates and reconcile O.R. records separately. |
| Document Request Queue & Processing Tracker | Migration 008; `public/requests/form.php`, `public/requests/action.php`, `app/certificates_lib.php` | The form records Not checked/Incomplete/Complete and follow-up notes. Approval to Ready and certificate issue are blocked until requirements are marked Complete. Queue statuses remain Pending/Review/Ready/Claimed/Rejected/Cancelled. | Automated tests cover missing requirement notes, blocked approval, valid review/approval, and certificate blockers. Confirm the barangay's actual checklist and stage terminology; the code does not invent supporting documents. |
| Document Issuance History | Issuance History menu entry is marked built. | Verify each issued document is linked to resident/request, reference number, issue timestamp, issuing personnel, searchable history, and authorized reporting. |
| User Authentication & RBAC | Shared authentication and permissions code exists. | Role map remains provisional. Confirm each role's intended access against approved paper and role decisions; test direct URL/POST access, not just hidden menus. |
| Automated Monthly & Quarterly Report Generator | `public/reports/index.php`, `app/reports_lib.php` | Monthly, quarterly, and annual periods now cover issuance, request-processing and blotter summaries; the demographic view is a current snapshot. | CI integration tests exercise saved-data totals, category breakdowns, download headers, and role restrictions. Standardized agency templates still need owner-approved examples. |
| System Audit Trail & Action Logger | Audit screen and audit helpers are referenced by menu/dashboard. | Verify all defined create/update/archive/print/status/security actions are logged with actor, timestamp, module/record reference, and outcome. |
| Executive Overview Dashboard | `public/dashboard.php` | Adds current resident/active request/document issuance/open blotter summaries and population by Purok. Fee figures are labelled recorded issuance fees, not verified receipts. | Execute the B7 dashboard test and compare figures against database queries. |
| Backup & Data Export | `public/admin/backup.php`, audit logger | An Administrator-only local SQL export with access checks and audit event is implemented. Cloud upload, scheduled backups, automatic restore verification, and a proven restoration are not implemented. | Download a test backup and verify that it restores into a separate scratch database. Cloud use needs an approved provider, credentials and deployment decision; do not upload sensitive resident records to an unapproved service. |

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
5. Run PHP syntax checks, `php tests/run_tests.php`, and `php tests/run_modules.php` against a configured working database. `run_modules.php` includes request review, fee matrix, verification, reporting and backup checks; record the real outputs.
6. Browser/XAMPP verification remains outstanding until run on the user's machine.
7. Mark each requirement **Implemented and tested**, **Partially implemented**, **Missing**, or **Blocked by an unresolved decision**. Never mark a feature complete based only on a menu item or code file existing.

## Current audit conclusion

The review branch includes the core resident, certificate, blotter, authentication and report workflows plus data-driven request/issuance/blotter reports and dashboard indicators. Added changes include migration 008 (request requirements review and an approval/issuance gate), migration 009 (effective-dated configurable fee matrix and request/issuance snapshots), migration 010 (manual identity/voter verification history), report CSV/print views, and an Administrator-only local SQL export. A read-only retrieval/report benchmark tool and a UAT/performance worksheet were also added. Latest verified GitHub Actions results on the manual-verification branch passed PHP syntax validation, migrations 001–010 on disposable MariaDB, **37 authentication/security/audit tests**, and **118 module integration tests**, including fee quote through certificate issuance and verification-history checks.

**Still not verified / deliberately not claimed:** target-XAMPP/browser UAT; official role-matrix approval; official certificate/summons wording and fee rates; manual-vs-digital retrieval timings; office-data reconciliation; a successful backup restoration; cloud/scheduled backup; actual SMS delivery; and external COMELEC lookup. The verification log records a staff check and does not contact COMELEC. The fee matrix quotes an approved rate but does not prove cash was received. SMS remains log-only.

This report records the current automated verification and remaining gaps; it is not a substitute for end-to-end testing against a configured XAMPP working copy.
