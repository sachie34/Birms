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
