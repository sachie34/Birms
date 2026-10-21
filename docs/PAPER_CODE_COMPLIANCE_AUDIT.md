# Paper-to-Code Compliance Audit — BIRMS

**Auditor:** John — BIRMS Paper-to-Code Compliance & Functionality Auditor  
**Repository:** `sachie34/Birms`  
**Audit branch:** `john/paper-code-compliance`  
**Audit date:** 2026-10-21  
**Status:** Initial static audit; not a claim that the system has passed runtime/UAT testing.

## Source-of-truth order

1. The submitted research paper defines the approved study objectives, core modules, scope, and limitations.
2. The two five-module process-documentation files define additional detailed workflows submitted by the team. These must be mapped to the approved paper before treating them as mandatory system scope.
3. Existing repository code, schema, tests, and setup notes determine what is actually implemented.
4. Never invent official barangay fees, Purok names, certificate wording, or SMS-provider settings.

## Core paper requirements

| Paper requirement | Evidence checked | Initial finding | Required action |
|---|---|---|---|
| User authentication and role-based access | `app/permissions.php`, `public/index.php`, XAMPP guide | Login and server-side permission gates exist. Roles are explicitly marked **PROVISIONAL** and use four roles, while the research paper's scope describes Admin/Staff levels. | Validate the final role matrix with the paper/panel and barangay before changing permissions. Keep server-side denial tests for every role and protected route. |
| Resident profiling, editing, search, demographic data | Menu and shared resident helpers; README/setup guide | Resident and household screens are marked built. The setup guide explicitly lists demographic breakdown as not built yet. | Verify create/read/update/archive, household linking, advanced search by name/Purok, validation, and demographic data fields against the paper. |
| Certificate requests and generation (clearance, indigency, residency) | Menu, `app/requests_lib.php`, `app/certificates_lib.php`, setup guide | Request and certificate flows are present in the B1–B6 package. Official settings are intentionally blank until supplied by an authorized official. | Verify each required certificate type, data population, preview/print, unique references, issuance logging, and status transitions. Do not fabricate official text or fee rules. |
| Blotter and incident tracking | Menu, `app/blotters_lib.php` | Blotter statuses and hearing outcomes/rules exist in shared code. | Verify incident details, complainant/respondent, date/place, hearings/summons, status transitions, and case history end to end. |
| Report generation and statistical summaries | `app/menu.php`, `docs/README_XAMPP.md` | **Missing.** Reports menu item has `built = false`; setup notes say reports, dashboard figures, incident counts by Purok, and demographic breakdown are not built. | Implement monthly/quarterly and demographic, issuance/fees, and blotter summary reports from real database records. Include authorized filters and print/export; never use hard-coded sample totals. |
| Functional usability, data accuracy, retrieval speed evaluation | Research paper objective 6; current setup notes | Static code review alone cannot establish these outcomes. | Run repeatable functional tests and user acceptance tests; record measured retrieval/generation results rather than claiming performance without evidence. |

## Team process-documentation workflows

| Workflow | Initial status based on available repository evidence | Compliance work still needed |
|---|---|---|
| Visitor Logbook & Form Filing | Visitor Logbook menu entry is marked built. | Confirm timestamping, purpose, classification, form/file reference, validation, update, search, and filing confirmation. |
| Automated Certificate Generation | Certificate issue/history routes are represented in the menu and shared library. | Verify all documented input-to-output steps and actual issuance history. |
| Multi-Tier Fee Matrix & Billing | Settings menu is labelled “Fees and Puroks”; no standalone billing screen was confirmed in this initial pass. | Confirm approved fee categories/tiers, effective rates, calculation breakdown, validation, and stored billing records. Keep payment over-the-counter; the paper excludes online payment processing. |
| Document Request Queue & Processing Tracker | Request Tracker menu entry is marked built; status transitions are defined in `app/requests_lib.php`. | Verify assignment, requirements checks, pending visibility, status history, completion/follow-up, and all documented statuses. The repository uses Pending/Review/Ready/Claimed/Rejected/Cancelled; reconcile terminology with the approved paper and process documents without silently changing existing records. |
| Document Issuance History | Issuance History menu entry is marked built. | Verify each issued document is linked to resident/request, reference number, issue timestamp, issuing personnel, searchable history, and authorized reporting. |
| User Authentication & RBAC | Shared authentication and permissions code exists. | Role map remains provisional. Confirm each role's intended access against approved paper and role decisions; test direct URL/POST access, not just hidden menus. |
| Automated Monthly & Quarterly Report Generator | **Not built**, according to setup guide and menu configuration. | Implement after confirming actual schema/data sources and supported report types. |
| System Audit Trail & Action Logger | Audit screen and audit helpers are referenced by menu/dashboard. | Verify all defined create/update/archive/print/status/security actions are logged with actor, timestamp, module/record reference, and outcome. |
| Executive Overview Dashboard | Dashboard exists, but the setup guide says dashboard figures are not built. | Replace/complete any absent indicators with SQL aggregates based on real validated records; include population by Purok, active requests, collected fees where recorded, and blotter summary. |
| Cloud Backup & Data Export | Backup menu entry has `built = false`; setup guide lists Backup page as not built. | Implement safe administrator-only export/backup, status verification, and audit logging. Cloud storage/scheduled backups must not be claimed unless actually configured. |

## Scope conflicts to resolve before changing behavior

- The research paper's core scope names five modules: Authentication, Resident Management, Certificate Generation, Blotter Tracking, and Report Generation. The process documents add visitor logs, fee matrix/billing, request queue, issuance history, audit trail, dashboard, and backup/export. Treat these as detailed or supplemental workflows and map them to the approved study scope; do not rewrite the paper's scope by assumption.
- The paper describes Admin/Staff access in its scope, while the repository implements Administrator, Captain, Secretary, and Clerk with a provisional matrix. Confirm which role design was approved.
- The paper requires reports, while the repository's own setup guide explicitly says report generation, dashboard figures, and demographic breakdown are not yet built.
- The paper excludes online payment gateways, resident self-service accounts, native mobile apps, and external national-LGU database integrations. Do not add these as part of this compliance pass.
- The second submitted paper contains an unresolved `{specify}` placeholder for the documents covered by communication/hearing notices. Do not infer a new feature from that placeholder.
- SMS is currently described in the repository setup notes as **log-only**. The paper's later objectives and panel recommendations request automated SMS. This is a documented gap until a real gateway is configured, tested, and authorized; do not claim messages are sent when they are only logged.

## Safe implementation and verification rules

1. Work on this branch and open a pull request; do not modify the original database or commit local credentials.
2. Inspect migrations and actual table/column names before writing report queries or changing schema.
3. Keep automated tests restricted to a database whose name ends in `_work` or `_test`.
4. Add regression tests for each corrected/implemented workflow, including role-denial cases and invalid/missing input.
5. Run PHP syntax checks, `php tests/run_tests.php`, and `php tests/run_modules.php` against a configured working database; record the real outputs.
6. Browser/XAMPP verification remains outstanding until run on the user's machine.
7. Mark each requirement **Implemented and tested**, **Partially implemented**, **Missing**, or **Blocked by an unresolved decision**. Never mark a feature complete based only on a menu item or code file existing.

## Current audit conclusion

The repository contains substantial B1–B6 application code, but the available repository documentation itself identifies a material mismatch with the research paper: **the required Reports module, demographic/report calculations, dashboard figures, and Backup page are not yet built**. The role matrix is provisional, and SMS is log-only. Those gaps must be resolved and tested before claiming full paper-to-code compliance.

This report records the initial audit findings; it is not a substitute for end-to-end execution against a configured XAMPP working copy.
