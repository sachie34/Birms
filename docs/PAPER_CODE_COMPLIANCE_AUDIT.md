# Paper-to-Code Compliance Audit — BIRMS

**Auditor:** John — BIRMS Paper-to-Code Compliance & Functionality Auditor  
**Repository:** `sachie34/Birms`  
**Audit branch:** `john/paper-code-compliance`  
**Audit date:** 2026-10-21  
**Status:** Implementation added on the review branch; PHP syntax checks, database-backed tests, target-XAMPP testing, and UAT are still required before claiming full compliance.

## Source-of-truth order

1. The submitted research paper defines the approved study objectives, core modules, scope, and limitations.
2. The two five-module process-documentation files define additional detailed workflows submitted by the team. These must be mapped to the approved paper before treating them as mandatory system scope.
3. Existing repository code, schema, tests, and setup notes determine what is actually implemented.
4. Never invent official barangay fees, Purok names, certificate wording, or SMS-provider settings.

## Core paper requirements

| Paper requirement | Evidence checked | Initial finding | Required action |
|---|---|---|---|
| User authentication and role-based access | `app/permissions.php`, `public/index.php`, XAMPP guide | Login and server-side permission gates exist. Roles are explicitly marked **PROVISIONAL** and use four roles, while the research paper's scope describes Admin/Staff levels. | Validate the final role matrix with the paper/panel and barangay before changing permissions. Keep server-side denial tests for every role and protected route. |
| Resident profiling, editing, search, demographic data | Resident forms/profile, migration 007, `app/reports_lib.php`, reports screen | Reporting branch adds employment-status input, voter-status maintenance, and demographic breakdowns by age, sex, civil status, employment, voter status, and Purok. | Run B3/B7 tests and verify that migration 007 is applied on a disposable database; confirm the employment-status list with the barangay. |
| Certificate requests and generation (clearance, indigency, residency) | Menu, `app/requests_lib.php`, `app/certificates_lib.php`, setup guide | Request and certificate flows are present in the B1–B6 package. Official settings are intentionally blank until supplied by an authorized official. | Verify each required certificate type, data population, preview/print, unique references, issuance logging, and status transitions. Do not fabricate official text or fee rules. |
| Blotter and incident tracking | Menu, `app/blotters_lib.php` | Blotter statuses and hearing outcomes/rules exist in shared code. | Verify incident details, complainant/respondent, date/place, hearings/summons, status transitions, and case history end to end. |
| Report generation and statistical summaries | `app/reports_lib.php`, `public/reports/index.php`, `tests/mod_b7.php` | Implemented on the review branch: current demographic summaries; monthly, quarterly, annual issuance and blotter summaries; status and incident type/Purok groups; CSV export. Values are queried from the database rather than hard-coded. | Run the new B7 regression tests and verify the reports against the target database. Confirm configured age brackets/category lists and inspect CSV output. |
| Functional usability, data accuracy, retrieval speed evaluation | Research paper objective 6; current setup notes | Static code review alone cannot establish these outcomes. | Run repeatable functional tests and user acceptance tests; record measured retrieval/generation results rather than claiming performance without evidence. |

## Team process-documentation workflows

| Workflow | Initial status based on available repository evidence | Compliance work still needed |
|---|---|---|
| Visitor Logbook & Form Filing | Visitor Logbook menu entry is marked built. | Confirm timestamping, purpose, classification, form/file reference, validation, update, search, and filing confirmation. |
| Automated Certificate Generation | Certificate issue/history routes are represented in the menu and shared library. | Verify all documented input-to-output steps and actual issuance history. |
| Multi-Tier Fee Matrix & Billing | `public/admin/settings.php`, request and issuance flows | Existing code supports a fee value per document type and stores the configured issuance fee. A complete multi-tier fee matrix/effective-dated billing ledger is not confirmed by this change. | This remains partially verified. Check process-documentation requirements and approved rates with the barangay; do not call recorded issuance fees “cash collected” without a reconciled payment ledger. |
| Document Request Queue & Processing Tracker | Request Tracker menu entry is marked built; status transitions are defined in `app/requests_lib.php`. | Verify assignment, requirements checks, pending visibility, status history, completion/follow-up, and all documented statuses. The repository uses Pending/Review/Ready/Claimed/Rejected/Cancelled; reconcile terminology with the approved paper and process documents without silently changing existing records. |
| Document Issuance History | Issuance History menu entry is marked built. | Verify each issued document is linked to resident/request, reference number, issue timestamp, issuing personnel, searchable history, and authorized reporting. |
| User Authentication & RBAC | Shared authentication and permissions code exists. | Role map remains provisional. Confirm each role's intended access against approved paper and role decisions; test direct URL/POST access, not just hidden menus. |
| Automated Monthly & Quarterly Report Generator | `public/reports/index.php`, `app/reports_lib.php` | Monthly, quarterly, and annual ranges are implemented for issuance and blotter summaries, with CSV export. | Execute the B7 integration tests on a disposable test database and compare values with independent SQL counts. |
| System Audit Trail & Action Logger | Audit screen and audit helpers are referenced by menu/dashboard. | Verify all defined create/update/archive/print/status/security actions are logged with actor, timestamp, module/record reference, and outcome. |
| Executive Overview Dashboard | `public/dashboard.php` | Adds current resident/active request/document issuance/open blotter summaries and population by Purok. Fee figures are labelled recorded issuance fees, not verified receipts. | Execute the B7 dashboard test and compare figures against database queries. |
| Cloud Backup & Data Export | `public/admin/backup.php`, audit logger | Adds an Administrator-only downloadable SQL export and audit event. It does not upload to cloud, schedule backups, or verify a restoration. | Verify access, SQL download integrity and a successful restore into a separate scratch database. Cloud/scheduled backup remains unimplemented. |

## Scope conflicts to resolve before changing behavior

- The research paper's core scope names five modules: Authentication, Resident Management, Certificate Generation, Blotter Tracking, and Report Generation. The process documents add visitor logs, fee matrix/billing, request queue, issuance history, audit trail, dashboard, and backup/export. Treat these as detailed or supplemental workflows and map them to the approved study scope; do not rewrite the paper's scope by assumption.
- The paper describes Admin/Staff access in its scope, while the implementation has Administrator, Captain, Secretary, and Clerk. The Administrator is now mapped to all defined module permissions to match the intended highest-privilege system role, but the overall matrix remains provisional and should be approved by the Barangay Secretary / system owner.
- The paper requires reports; implementation was added on this branch, but it is not verified against the live/office database or approved output templates yet.
- The paper excludes online payment gateways, resident self-service accounts, native mobile apps, and external national-LGU database integrations. Do not add these as part of this compliance pass.
- The second submitted paper contains an unresolved `{specify}` placeholder for the documents covered by communication/hearing notices. Do not infer a new feature from that placeholder.
- SMS is currently described in the repository setup notes as **log-only**. The paper's later objectives and panel recommendations request automated SMS. This is a documented gap until a real gateway is configured, tested, and authorized; do not claim messages are sent when they are only logged.

## Safe implementation and verification rules

1. Work on this branch and open a pull request; do not modify the original database or commit local credentials.
2. Inspect migrations and actual table/column names before writing report queries or changing schema.
3. Keep automated tests restricted to a database whose name ends in `_work` or `_test`.
4. Add regression tests for each corrected/implemented workflow, including role-denial cases and invalid/missing input.
5. Run PHP syntax checks, `php tests/run_tests.php`, and `php tests/run_modules.php` against a configured working database. `run_modules.php` now includes B7 reporting/backup checks; record the real outputs.
6. Browser/XAMPP verification remains outstanding until run on the user's machine.
7. Mark each requirement **Implemented and tested**, **Partially implemented**, **Missing**, or **Blocked by an unresolved decision**. Never mark a feature complete based only on a menu item or code file existing.

## Current audit conclusion

The review branch now adds the missing Reports module and database-derived demographic, issuance, and blotter summaries; dashboard indicators; configurable employment/incident classification fields; resident voter-status maintenance; and an Administrator-only local SQL export page. Migration 007 and B7 integration tests were added, and existing hard-coded RBAC test expectations were adjusted for the highest-privilege Administrator. **These changes have not yet been executed against a configured test database or the target XAMPP installation.** The role matrix still needs owner approval, billing beyond a fee-per-document issuance amount is not verified, cloud/scheduled backup is not implemented, SMS is log-only, and browser/UAT evidence is outstanding.

This report records the initial audit findings; it is not a substitute for end-to-end execution against a configured XAMPP working copy.
