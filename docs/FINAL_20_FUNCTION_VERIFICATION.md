# BIRMS Final 20-Function Verification Checklist

**Review date:** 2026-10-28  
**Repository:** `sachie34/Birms`  
**Branch context:** the role-aware workflow changes and dashboard quick-workflow guide have been merged into `main`.

## How to read this checklist

“Implemented” means there is a corresponding application flow and code/test coverage in the repository; it is not a claim of production acceptance. “Partial” means a core code path exists but an external integration, official document/policy decision, or validation step remains incomplete. CI tests use synthetic data and a disposable database. They do not verify the target office's XAMPP installation, physical printer, official wording, live SMS, or live cloud backup.

## The 20 functions

| # | Function | Current code/test evidence | Status and remaining check |
|---:|---|---|---|
| 1 | Resident Profile Registration | Resident forms and resident library; `tests/mod_b3.php` | **Implemented.** Registration, editing, validation and audit behavior are covered. Confirm the final form fields and required-field rules with the approved data-collection form. |
| 2 | Purok-Based Masterlist Organization | Resident/household/Purok pages and filters; `tests/mod_b3.php`, report checks in `tests/mod_b7.php` | **Implemented.** Uses configured Purok values. Enter the authorized Purok list and reconcile sample totals in the working database. |
| 3 | COMELEC Voter & ID Verification | Manual verification fields/history; `tests/mod_b3.php` | **Partial by design.** Staff can record an in-person ID/local voter-list check. There is no COMELEC API or automated external identity proofing; do not present this as an online COMELEC lookup. |
| 4 | Demographic Breakdown | Data-derived demographic reporting; `tests/mod_b7.php` | **Implemented.** Results derive from saved current active-resident data and configured categories. Reconcile report figures against the working database. |
| 5 | Present Address & Status Tracking | Resident/household profile and status actions; `tests/mod_b3.php` | **Implemented for current state.** Tracks household address/Purok and Active/Relocated state. It is not a full chronological address-change history. |
| 6 | Visitor Logbook & Form Filing | Visitor log, request link and private attachments; `tests/mod_b4.php`, `tests/mod_b8.php` | **Implemented for intake/linking.** A visitor can continue to a linked request and attach supported files. The selected document/service type is captured in the linked request, not on the visitor row itself. Test private uploads/downloads through Apache/XAMPP. |
| 7 | Automated Certificate Generation | Request-to-issuance, print/PDF and issuance records; `tests/mod_b5.php`, `tests/mod_b8.php` | **Partially implemented.** Authorized issuance, print view, PDF output and history are tested. The template remains marked DRAFT until the barangay approves the final wording, letterhead/layout, fee rules and signatory policy. A typed name is not a cryptographic digital signature. |
| 8 | Multi-Tier Fee Matrix & Billing | Fee-rule configuration/quotes and cash ledger; `tests/mod_b7.php`, `tests/mod_b8.php` | **Implemented in code; official values pending.** Fee snapshots and ledger actions are tested. Enter only approved fee tiers/rates. A staff-entered or reconciled ledger state is not independent proof of a bank deposit. |
| 9 | Document Request Queue & Processing Tracker | Request status/requirements workflow and queues; `tests/mod_b4.php`, `tests/mod_b7.php` | **Implemented and improved.** Secretary/Admin handle requirements review; Captain/Admin make the final request decision; role-specific queues and a four-step progress tracker guide the user. Stored statuses remain Pending/Review/Ready/Claimed/Rejected/Cancelled, displayed with clearer labels. |
| 10 | Document Issuance History | History filters/search and CSV export; `tests/mod_b5.php`, `tests/mod_b8.php` | **Implemented.** History retains issued-document references, resident/document details, dates, O.R. information and ledger distinctions. Reconcile displayed figures against controlled test issuances. |
| 11 | Incident & Complaint Logging | Blotter case/party pages and libraries; `tests/mod_b6.php` | **Implemented.** Case and party entry/edit flows are covered in the blotter test suite. Confirm office-approved intake fields and data-retention handling. |
| 12 | Active/Pending Case Status Tracker | Blotter statuses and permitted transitions; `tests/mod_b6.php` | **Implemented.** The stored states are Pending, Active, Scheduled and Resolved. Run the full lifecycle in the target browser using fictitious cases. |
| 13 | Respondent/Complainant Lookup | Local case-party lookup and request hint; `tests/mod_b6.php`, request workflow checks | **Implemented as a local possible-match search.** A name match is only a lead for staff review; it does not prove identity and is not an automatic issuance block. |
| 14 | Hearing & Summons Scheduler | Hearing/summons routes and blotter integration tests; `tests/mod_b6.php` | **Implemented in code.** Validate official summons wording, date/venue fields and printed page layout with the barangay before using as an official form. |
| 15 | Incident Summary & Safety Metrics | Blotter reports; `tests/mod_b7.php` | **Implemented.** Period totals, status, configured incident types/Purok and resolution metrics are data-derived. Higher-agency report templates still need approval. |
| 16 | User Authentication & RBAC | Auth/permission checks; `tests/run_tests.php` plus role tests in module suites | **Implemented, role policy pending approval.** The current candidate matrix separates Secretary/Admin review, Captain/Admin final decisions, Clerk print-only access, Captain read-only ledger access, and Administrator-only resident restoration. The matrix correctly remains PROVISIONAL until the barangay/system owner confirms it. |
| 17 | Automated Periodic Report Generator | Periodic report and CSV output; `tests/mod_b7.php` | **Partially implemented.** The application generates data-backed draft reports/exports. Approved DILG/Mayor/PNP templates and their mandatory fields have not been supplied/approved, so do not present output as an officially accepted agency template. |
| 18 | System Audit Trail & Action Logger | Audit viewer/export, security and module actions; `tests/run_tests.php` and module suites | **Implemented for key tested flows.** Authentication, access-denial, resident, request, issuance/print, settings and other key actions are logged. Verify any additional office-specific event coverage during UAT. |
| 19 | Executive Overview Dashboard | Data-derived dashboard cards and shared UI/search checks; `tests/mod_b7.php`, `tests/mod_b9.php` | **Implemented.** Metrics derive from stored data and visible links are permission-aware. The dashboard also has role-aware quick-workflow shortcuts. Compare totals to database queries in UAT. |
| 20 | Cloud Backup & Data Export | Admin SQL export, WebDAV configuration/validation; `tests/mod_b7.php` | **Partially implemented.** Local SQL export and optional HTTPS WebDAV code/configuration checks exist. A live remote upload and successful restore have not been verified; scheduled backup/notifications are not included. Test restore only in a separate scratch database. |

## Role workflow now in the code

1. **Clerk:** submits/edits an intake request and sees it under **My Requests**. The Clerk cannot set review fields by a forged POST, approve/reject, or issue a certificate. They can print/download an already-issued certificate.
2. **Secretary:** handles **Needs Review**, records requirements/notes, and marks the requirements complete when actually checked. Secretary can issue an eligible Ready request under the current candidate permission matrix, but cannot make the final request decision.
3. **Captain:** handles **Pending Final Approval** only for requests that have been reviewed with requirements marked Complete. The Captain can approve/reject requests and view the cash ledger, but cannot edit/reconcile/delete ledger rows.
4. **Administrator:** can review and make final decisions, issue certificates, manage the ledger, and restore archived residents. The Administrator also manages user accounts.
5. **Certificate issue:** an approved request becomes Ready for Pickup; an authorized Secretary/Administrator issues the certificate, which creates issuance history and moves the stored request state to `Claimed` (displayed as **Issued**).

All role restrictions must remain enforced server-side; hiding buttons alone is not a security control.

**Open security follow-up:** The request list, detail, edit, and status-change routes now enforce Clerk ownership/assignment. The private attachment download endpoint (`public/files/download.php`) still uses the broad `requests.view` permission and has not yet adopted the same row-level ownership check. Before claiming request attachments are isolated by Clerk ownership or using real private attachments, add and test the corresponding download check.

## Automated verification evidence

The successful GitHub Actions runs for the role-workflow PR and dashboard quick-workflow PR each completed:
- PHP syntax validation;
- repository-wide PHP include-path checks;
- XAMPP setup preflight and private-configuration checks;
- migrations on a disposable MariaDB 10.11 database;
- **37/37 authentication/security/audit checks**;
- **179/179 module integration checks**;
- zero failures and zero untested checks in each run.

After both PRs were merged into `main`, the combined source was tested together on [GitHub Actions run #477](https://github.com/sachie34/Birms/actions/runs/40642029005) from verification PR #18, based on the updated `main` snapshot. That combined run passed PHP syntax/include/setup checks, disposable MariaDB migrations, **37/37 authentication/security/audit checks** and **179/179 module integration checks** (216 total; zero failures and zero untested checks). PR #16 and PR #17 also had their own successful CI runs.

The most recent workflow verification for the code merged by PR #19 was run on its PR-head commit in [GitHub Actions run #488](https://github.com/sachie34/Birms/actions/runs/40644611339) on 2026-10-28. It passed **37/37 authentication/security/audit checks** and **180/180 module integration checks** (217 total; zero failures and zero untested checks). The additional module test covers Clerk attempts to view, edit, or cancel another user's request through changed URLs or POST requests. The tested PR was squash-merged into `main` as commit `a04c4f6ed6a0571d2d702be468c1e44742f88851`.

These remote tests do not verify the target office PC's browser, upload permissions, PDF reader or physical printer. No live SMS send or real WebDAV upload/restore is claimed. The combined CI passes are not a substitute for target-PC UAT or official policy/template approval.

## Defense sign-off blockers

- Get real system-owner approval for the four-role permission matrix; keep the PROVISIONAL label until confirmed.
- Obtain approved certificate/summons wording, layout, letterhead, signatory policy, fee schedule and document-requirement checklist.
- Validate official agency-report formats if the reports will be submitted externally.
- Perform target-PC XAMPP/browser/PDF/printer UAT with fictitious records.
- If cloud backup is required, run an approved live WebDAV upload and verify restoration in a separate scratch database.
- Keep COMELEC/API integrations, online payments, native mobile/offline approval and automated threshold routing out of scope unless the approved paper is formally changed.
