# MSIT Revised SRS Compliance Plan

**Controlling requirement source:** `BRMS_SRS_Revised.docx` (user-provided, revised from the MSIT adviser's recommendations and system process diagram)
**Repository:** `sachie34/Birms`
**Working branch:** `john/msit-srs-compliance`
**Baseline:** current `main` branch
**Purpose:** Use the revised SRS as the acceptance baseline for all future BIRMS changes. This plan is not evidence that the listed features have already been implemented.

## 1. Source-of-truth rule

The revised MSIT SRS controls requirements and acceptance decisions for this work. Older thesis/paper alignment documents, earlier process documents, existing UI behavior, database schema, and prior assistant plans are subordinate wherever they conflict with the revised SRS.

Preserve useful existing behavior only when it does not contradict the revised SRS. Do not silently add old out-of-scope workflows as mandatory features. Do not silently delete records or tables merely because the revised interface no longer uses them.

Every requirement must be classified as:
- Implemented and verified
- Partially implemented
- Missing
- Conflicting with the revised SRS
- Requires stakeholder decision or manual acceptance

A page, button, migration, or passing unit test alone does not prove an end-to-end requirement is complete.

## 2. Revised SRS scope

The system scope is:
1. Record management
2. Blotter management
3. Documents management
4. Venue management
5. Resident queueing for blotter, documents, and venue

Resident-facing access is through a self-service kiosk and a shared request QR code. Office users access the admin portal. The visitor logbook is removed and replaced by queueing. Household is no longer the resident grouping unit; residents are grouped by Purok and each resident has an Address field.

Out-of-scope processes must not be retained as mandatory system workflows unless the MSIT adviser or system owner later approves a scope change.

## 3. Verified baseline conflicts in current main

The following were observed in checked-in source files on `main`:

| Area | Current checked-in implementation | Revised SRS target |
|---|---|---|
| Resident grouping/address | `database/migrations/003_residents.sql` defines `households`; resident address is obtained through the household's `street_address`. `app/residents_lib.php` joins residents to households for address/Purok/search. | Address stored on each resident; residents grouped and filtered directly by Purok; household grouping no longer used. |
| Resident status/type | Resident schema has `Active/Relocated`, `civil_status`, `category_id`, and separate voter status. | Status values Active, Transferred, Deceased, Non-resident; marital status; resident types PWD, Senior Citizen, Solo Parent, 4Ps Beneficiary, Indigent, None; admin-managed reference lists. |
| Resident contact | Current resident schema includes mobile number but the inspected migration does not include resident email, photo, or a unique public Resident ID number. | Resident ID, mobile, email, photo, birthdate, sex, address, Purok, resident status, marital status and resident type. |
| Visitor intake | `database/migrations/004_requests.sql` creates `visitor_log`; the README describes visitor log and attachments. | Visitor logbook removed; queueing records visits/service and timing. |
| Documents | Existing request schema uses Pending, Review, Ready, Claimed, Rejected, Cancelled; current roles split requirements review and final approval. | Public request statuses Pending, Approved, Rejected, Released; Staff approves/rejects and generated documents are returned to staff for release. |
| Blotter | `database/migrations/006_blotter.sql` and `app/blotters_lib.php` use Pending, Active, Scheduled, Resolved. | Ongoing, Unresolved, Resolved, Dismissed, Archived. Every change is logged. Blotter records are never deleted; resolved and dismissed cases are archived. |
| Roles | `database/migrations/001_core.sql` and `app/permissions.php` use admin, captain, secretary, clerk. | Admin, Chairman, Secretary, Staff, Resident. Chairman is view-only; staff process and approve/reject requests. |
| E-signature | Current certificate PDF code uses a typed signature text/name and explicitly says it is not a verified cryptographic digital signature. | Admin uploads the Chairman's signature image; it is applied automatically to generated certificates that require it. |
| Venue | No venue/reservation workflow was found in the inspected migrations and quick workflow guide. | Venue records, reservations, red/green availability, overlapping-booking prevention and staff override. |
| Queue | No queue-entry schema or queue workflow was found in the inspected migrations and quick workflow guide. | Separate Blotter, Documents and Venue queues; issue/call/serve/skip/cancel; display; timestamps; reports; notifications. |
| Kiosk / request QR | No resident kiosk or request-QR workflow was found in the inspected README and quick workflow guide. | Touchscreen resident-only kiosk, shared request QR code, resident identification and automatic inactivity reset. |
| Notifications | Existing README documents optional SMS adapters. | Email is the default; optional SMS; resident chooses SMS, email or both for queue notices; fallback behavior is specified. |
| Backups | Current README documents local SQL download and optional WebDAV upload, but says restore and scheduled backup are not included/verified. | Backup and restore must be supported. |
| Reports | Existing reports include data-backed summaries and CSV export. | Required reports include residents per Purok/type, requests, blotters by status, venue usage and queue statistics; Chairman and Secretary can view; PDF or Excel export. |
| UI accessibility | Current interface is Bootstrap-based; the inspected docs do not establish compliance with the revised 18px web text, 24px kiosk text and 48px touch-target requirements. | Explicit accessible text sizes, touch targets, contrast, labeled controls and senior-friendly interaction. |

These findings are a starting inventory from the named files, not a claim that every route or helper in the repository has been exhaustively inspected. Continue source-level inspection before implementing or declaring each requirement.

## 4. Role and account migration policy

The revised role names and responsibilities must be enforced on the server, not only in navigation.

Target roles:
- Admin: users, resident directory, reference data, system settings, backups, signature upload, and system data management.
- Chairman: read-only access to records and reports; no operational edits or request decisions. Signature is applied automatically to eligible certificates.
- Secretary: review records and requests; manage blotter and document records as specified.
- Staff: process requests, approve/reject, generate forms/certificates, and manage queues.
- Resident: self-service kiosk and QR request, blotter follow-up, venue reservation, and queue functions.

Do not assume the old Captain/Clerk roles have identical permissions to Chairman/Staff without mapping every permission. Existing accounts, password hashes and audit history must be preserved. Any role mapping must be explicit, reversible where practical, documented, and tested against direct URL/POST attempts. Do not leave an existing account with unintended elevated permissions.

## 5. Safe database migration principles

- Work only on the isolated working branch and a disposable `*_work` or `*_test` database.
- Use new numbered migrations; do not rewrite migrations that have already shipped.
- Back up the working database before migration testing.
- Prefer additive columns/tables and a staged transition.
- Backfill each resident's new address from the previous household address and Purok from the previous household's Purok; validate row counts and unmapped rows before changing reads/writes.
- Keep legacy household rows and foreign-key history intact during transition. Do not drop the table or delete household data as part of the first migration.
- Map old resident status values explicitly; review any unmappable values rather than guessing.
- Add resident ID uniqueness with collision checks before enabling the constraint.
- Add queue counters/entries with separate numbering per service and well-defined reset periods.
- Enforce venue overlap rules transactionally and with database protection where possible.
- Preserve audit logs and use transactions for sensitive state changes.
- Make migration rollback/forward-recovery instructions explicit. Do not run migrations against the user's original `barangay_db` or live barangay data.

## 6. Implementation phases

### Phase 0 — Baseline and traceability
- Keep current `main` protected.
- Maintain a requirement-to-code-to-test matrix using this SRS.
- Inspect all routes, shared libraries, permissions, schema, migrations and tests that touch each requirement.
- Mark existing legacy behavior and data dependencies before modifying them.

### Phase 1 — Data model and reference data
- Add resident Address, direct Purok, exact resident statuses, marital status support, resident type, unique Resident ID, email, photo metadata and notification preference.
- Backfill address/Purok safely from existing household records.
- Add admin-managed statuses, types, Puroks, document types and venues.
- Add the target role identifiers and a tested migration of existing accounts.
- Add venue, reservation, queue-entry, request-history/notification, and signature metadata tables as required by later phases.

### Phase 2 — Resident directory
- Update registration, edit, view, search, filters, import/export, profile and certificate data lookup to use resident Address and direct Purok.
- Enforce the four required resident statuses and six default resident types.
- Validate Resident ID uniqueness and birthdate-based identification.
- Confirm privacy rules for contact information, photo and personal details.

### Phase 3 — Documents, blotter and venue workflows
- Documents: request capture, Pending/Approved/Rejected/Released states, approval, generated output, release log and reference number.
- Blotter: required fields, five exact statuses, transition history, no-delete policy, and archive rules.
- Venue: venue list, reservation form, requester/purpose/date/start/end, overlap prevention, derived availability color and authorized manual override.
- Every request and status change must be auditable and visible to the requester through the tracker.

### Phase 4 — Queueing
- Replace visitor-logbook workflow with three independent service queues.
- Generate a unique number per service and day/defined numbering period.
- Provide staff actions: call next, mark served, skip, cancel.
- Provide resident-facing number issue and a display showing the current number for each service.
- Save issued/served timestamps, service, resident and status; build daily/weekly/monthly volume and average-wait reports.

### Phase 5 — Resident kiosk and QR
- Build a resident-only touchscreen flow for document requests, queue numbers and request-status checks.
- Use one shared request QR entry point; never encode personal resident data in the QR.
- Identify residents using Resident ID and birthdate; implement the exact fallback name/birthdate and one-time code flow where contact data exists, with staff counter verification otherwise.
- Enforce server-side separation from the admin portal and automatic inactivity reset to the home screen.
- Rate-limit identity lookup and public request endpoints and prevent disclosure of whether unrelated residents exist.

### Phase 6 — Roles, privacy and audit
- Enforce Chairman read-only policy server-side on every page, action, API-like endpoint, print/download and export.
- Ensure Staff can perform only the actions assigned by the revised SRS; Secretary and Admin access must also match the SRS.
- Audit status changes, approvals/rejections, releases, queue actions, reservations, signature use, resident record changes and administrative actions.
- Test direct URL, forged POST, CSRF, session and cross-resident access for every role.

### Phase 7 — Signatures and notifications
- Allow Admin to upload/manage the Chairman's signature image securely.
- Apply the stored signature only to certificate types requiring it; validate image type, size, path, access and PDF rendering.
- Email is the default notification channel; SMS remains optional.
- Implement resident choice of SMS/email/both for queue messages, SMS-to-email fallback, and visible queue display as the final fallback.
- Queue notifications safely when the local network/internet/provider is unavailable; do not claim delivery merely because a message was queued.

### Phase 8 — Reports, backup/restore and UI quality
- Add all FR-RP1 report categories and FR-RP2 Chairman/Secretary view and PDF/Excel export.
- Add a tested backup and restore procedure; restore only to a validated target and never overwrite the source database without explicit operator safeguards.
- Apply minimum text/touch-target sizes, contrast, visible labels and senior-friendly wording, especially on kiosk screens.
- Verify common-page performance against the 3-second target using repeatable measurements and representative data.
- Verify the application remains usable over the barangay local network without internet access.

### Phase 9 — Full regression and acceptance
- Run PHP syntax/static checks, migration tests, security/authentication/audit tests and all module integration tests after each phase.
- Add requirement-specific tests before marking a requirement complete.
- Test Admin, Chairman, Secretary, Staff and Resident journeys using separate demo accounts.
- Perform browser, touchscreen-size, PDF, image-upload, printer, email and optional SMS acceptance tests on the actual XAMPP/local-network setup.
- Record failures, fixes and retest evidence. Automated CI does not replace local UAT.

## 7. Release gates

No phase is complete until:
1. Its database migration succeeds on a fresh disposable database and an existing working-copy database.
2. Existing unrelated workflows and data remain intact.
3. Positive and negative role tests pass, including direct POST/URL access.
4. Relevant module tests and the full regression suite pass.
5. New functions are exercised end-to-end, not merely rendered.
6. Known manual acceptance items and limitations are recorded truthfully.

Do not merge this branch to `main` or label the system MSIT-compliant until the traceability matrix has evidence for every applicable SRS requirement and the target deployment UAT has been completed.


## 8. Existing unmerged security work that must be preserved

Pull request [#25 — Phase-based security hardening](https://github.com/sachie34/Birms/pull/25) is still open and is not part of `main`. Its head branch is `john/fix-clerk-global-search-privacy` at `067d3383c51776374b8cb20ef609d9635657c63a`. The branch contains six commits and compares as six commits ahead / one behind current `main`; it is diverged and GitHub currently reports it is not mergeable. Its latest observed workflow run completed successfully. The PR description records 43/43 security/authentication/audit checks and 189/189 module checks for its phase-4 validation, but these are the PR's recorded results, not a fresh run on this MSIT branch.

The MSIT branch was started from the current `main` so it includes the latest merged baseline. It does **not** yet include the unmerged PR #25 commits. Before changing security-sensitive code or claiming those improvements are present, inspect the divergence and port or reconcile the privacy, rate-limit, runtime, concurrency and certificate-print safeguards with the later `main` changes. Do not merge the old PR blindly, do not weaken either side's fixes, and rerun the full CI suite after reconciliation.


## 9. Implementation progress (2026-11-15)

### Phase 0 — baseline and traceability
- **Completed for the initial review:** created this dedicated working branch from current `main`; documented the controlling SRS, inspected the resident, request, blotter, role, certificate and migration baselines, and recorded known conflicts.
- **Not completed:** exhaustive route-by-route review and an adviser-approved role policy.

### Security migration preservation
- Ported the two database migrations from open PR #25 into this branch as migrations `013_hearing_concurrency` and `014_rate_limits`, including down scripts. The expected schema snapshot now includes the unique hearing slot guard and rate-limit bucket table.
- **Not yet ported:** the rate-limiting runtime helpers/endpoint hooks and PHP runtime-patch guard from PR #25. Do not claim those protections are active on this branch merely because the schema table exists. They still require careful reconciliation with the later `main` fixes and their tests.

### Phase 1 — additive MSIT data foundation
- Added migration `015_msit_srs_foundation` and a working-copy-only down script.
- It adds individual resident address/Purok, resident number, revised status/type lookups, marital status, email/photo metadata and notification preference; backfills address/Purok from the old household relation; and preserves old household columns/rows.
- It adds the revised document/blotter status columns without removing legacy columns; archives legacy Resolved blotters in the new status field while retaining the old status; and creates queue, venue/reservation, request-history, one-time verification challenge, and notification outbox tables.
- Added `tests/mod_b13.php` for revised resident fields/defaults, unique Resident IDs, three independent queue number sequences, venue default mode, and allowed blotter statuses.
- Updated `database/expected_schema.json` and added a CI step to compare the fully migrated database with that snapshot.
- **Not complete:** no resident pages/import/export/certificates/reports have been switched to these fields yet; no staff queue UI, kiosk, QR flow, reservation conflict logic, email dispatcher, signature image upload/rendering, or revised role enforcement has been implemented. The new schema is groundwork only until those workflows are connected and tested.

### Validation status
- No successful CI result is claimed yet for this branch. The new schema-check step and B13 module tests must run on a pull-request CI job.
- Existing working databases require a pre-migration duplicate-hearing check for migration 013. Inspect:
  `SELECT blotter_id, scheduled_at, status, COUNT(*) AS total FROM hearings GROUP BY blotter_id, scheduled_at, status HAVING COUNT(*) > 1;`
  Resolve any duplicates by human review before applying the migration; never delete historical hearings to force the migration through.
- The application still uses old resident pages and old role names, so do not deploy this branch as an MSIT-compliant build yet.

- Compatibility correction from CI: the new Resident ID/status/type columns remain nullable during the staged transition because existing application routes and legacy integration fixtures still create residents using the old columns. Existing records are backfilled where possible; the revised resident form will be required to validate and populate these fields before the migration can be tightened to NOT NULL. This is intentional transitional compatibility, not the final resident-registration rule.


## 10. Implementation and CI progress (2026-11-17)

### Implemented and integration-tested on this branch

- **Resident directory foundation and workflows:** individual Address and direct Purok fields, resident number, status/type/marital-status fields, photo/contact metadata, notification preference, updated resident registration/search/profile flows, and a staged backfill that preserves the legacy household rows and references.
- **Roles:** legacy account IDs remain in the database for compatibility, but the permission map maps `captain` to view-only Chairman and `clerk` to Staff. Chairman has no operational write permissions. Admin and Staff have the separate All Services Tracker permission; Chairman and Secretary do not.
- **Documents and certificates:** the public request states are mapped to Pending, Approved, Rejected and Released; resident kiosk submission creates a reference and document queue ticket; request status history and status notifications are recorded. Admin can upload a private Chairman JPEG signature, which is snapshotted on eligible certificate issuance and rendered in the certificate/PDF.
- **Blotter:** resident kiosk submission and follow-up, review/accept/reject workflow, revised MSIT case-status data, and audit/history handling are wired into the resident and staff workflows.
- **Venues:** Admin venue setup, resident/staff reservation submission, red/green availability, manual override, transactional overlap protection, approval/rejection/completion transitions and history.
- **Queueing and kiosk:** separate daily Blotter, Documents and Venue queues; counter issue/call/serve/skip/cancel; purpose-of-visit capture; request references; privacy-safe public queue display; resident identity verification and inactivity timeout.
- **Resident notification choice (FR-Q9):** the queue, document-request, blotter-report and venue-reservation kiosk forms let residents select Email, SMS or Both. The choice is saved to the resident record and each queue ticket; called/near alerts use the ticket's saved choice even if the resident later changes their profile preference. Unconfigured SMS fallbacks are recorded with an explicit email-fallback event type.
- **Resident QR:** a shared public document-request QR entry link, without personal resident information encoded in the QR.
- **Reports:** data-backed reports for residents/Purok/type, document requests, blotter statuses and queue statistics; added venue-usage reporting and Excel-compatible `.xls` export while retaining CSV. Chairman and Secretary can view and export reports; Staff is restricted according to the permission map.
- **FR-T6 unified tracker:** a separate Admin/Staff-only All Services Tracker combines Documents, resident Blotter requests and Venue reservations, filterable by service, current status, submission date and search term (including Resident ID). The existing Secretary document-review screen remains separate so its internal review stages are not lost.

### Latest automated validation

GitHub Actions run **#885** on commit `b0bca45ce154f7044f204c60cc8e9ee07afb30d4` completed successfully:

| Environment | Security/authentication/audit | Module integration |
|---|---:|---:|
| MariaDB 10.4 | 39/39 passed | 235/235 passed |
| MariaDB 10.11 | 39/39 passed | 235/235 passed |

The B20 report tests verify the venue-usage query, Chairman/Secretary view and Excel export permissions, Staff denial, and retained CSV output. The B21 tests verify that the All Services Tracker lists all three request types, enforces the Staff/Admin permission, filters service/status/date, searches by Resident ID, and links to the corresponding blotter request. The new B22 tests verify the resident's notification selector on all four kiosk forms, saving Email/SMS/Both choices, SMS-to-email fallback, per-ticket call/near preferences, and rejection of invalid choices.

These are automated integration results against disposable CI databases. They are not evidence of a completed barangay deployment or a successful local XAMPP/LAN acceptance test.

### Remaining acceptance work before MSIT-compliant release

1. Run the migrations on a fresh disposable XAMPP database and on a copy of the existing working database; compare row counts and resident address/Purok backfill results before any production migration.
2. Conduct complete browser-based role journeys on the actual XAMPP setup using separate Admin, Chairman, Secretary, Staff and Resident sessions, including direct URL/POST attempts and logout/session-reset checks.
3. Test the shared QR from an actual phone connected to the barangay LAN. Do not print/post a QR generated with `localhost`; use the LAN-reachable server address and verify it from the phone first.
4. Verify a physical touchscreen/kiosk at the target resolution, including the inactivity reset and that admin/staff routes cannot be reached from the resident kiosk flow.
5. Configure and test the barangay-approved email sender end-to-end. The CI tests verify queue/outbox and fallback logic, not delivery to a real resident mailbox. Configure SMS only if the barangay chooses a provider; test that provider separately and do not interpret queued as delivered.
6. Verify the Chairman signature image and generated certificate/PDF with the actual approved signature asset and official certificate wording/layout. The current draft certificate layout remains subject to barangay approval.
7. Test backup and restore on a separate scratch database and separately verify restoration of private attachments and the Chairman signature file. Do not test restore against the source database.
8. Measure common-page response times using representative barangay data to verify the 3-second target, and verify LAN operation when internet access is disconnected.
9. Reconcile the remaining open security-hardening PR #25 changes, especially any runtime hardening not already present on this branch, against the current code and CI before merge. Do not blindly merge the diverged PR.
10. Obtain adviser/office user acceptance and update the requirement traceability matrix with the signed/manual evidence before changing the draft PR to ready or merging.

**Release status:** implementation and automated integration coverage have advanced substantially, but the branch remains a draft and is **not yet declared fully MSIT-compliant or production-ready** until the manual acceptance steps above are complete.
