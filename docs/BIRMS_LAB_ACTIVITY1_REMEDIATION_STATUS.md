# BIRMS Lab Activity 1 Remediation Status and Office-PC UAT Record

**Last updated:** 2026-10-22  
**Repository:** `sachie34/Birms`  
**Current branch:** `main` (merged from `john/paper-code-compliance`)  
**Environment requested:** Target office PC, Windows, XAMPP/Apache/PHP, MySQL or MariaDB  
**Office-PC test status:** **NOT RUN — the target computer and its browser session were not available in this work session.**  
**Verified automated CI:** **PASSED** — [latest application-code CI run](https://github.com/sachie34/Birms/actions/runs/39845148883): PHP syntax passed; migrations 001–012 applied to disposable MariaDB; **37/37 authentication/security/audit checks** and **173/173 module integration checks** passed, with zero failures or untested checks. Office-PC/browser UAT is separate and remains **NOT RUN**.  
**Test data:** Use fictitious records on an isolated `barangay_db_work` or `barangay_db_test` database only. Never use the original `barangay_db` for test writes.

## 1. Current module status

| Module | Source-code status | Automated test coverage | Office-PC UAT | Remaining decision / gap |
|---|---|---|---|---|
| 1. Visitors Logbook & Form Filing | Implemented in branch: PDF/JPG/PNG upload with 10 MB limit, random filename, private disk path, DB metadata and checksum; download links in visitor logbook and request views. | Passed in CI #226: upload, view link, download and disallowed-extension rejection (`B8-1` to `B8-3`). | **NOT RUN** | Confirm office `upload_max_filesize` / `post_max_size`, folder write permissions, and whether the allowed file formats/size suit the office's records policy. |
| 2. Automated Certificate Generation | Implemented in branch: server-side PDF download, Dompdf integration when installed with offline PDF fallback, typed signatory snapshot, draft banner. | Passed in CI #226: issuance, typed-signatory snapshot and server-generated PDF response (`B8-5`). | **NOT RUN** | Official certificate template/layout/text, seal/letterhead, signatory policy and fee wording require stakeholder approval. Typed names are **not** cryptographic digital signatures. |
| 3. Multi-Tier Fee Matrix & Billing / Cash Ledger | Implemented in branch: separate `cash_collection_ledger`, Administrator screen, create/edit, pending/reconciled, transaction method/date/collector, audit trail and legacy-entry backfill. Paid issue creates a pending row. | Passed in CI #226: automatic ledger row, Administrator-only access, update, reconciliation, pending deletion and legacy backfill (`B8-6` to `B8-13`). | **NOT RUN** | Barangay must approve accepted payment methods, collector workflow, reconciliation evidence and policy for changing an amount. Ledger records are staff-entered; they do not independently prove bank deposits or cash counts. |
| 4. Document Request Queue & Processing Tracker | Core queue and requirement review already implemented; request form now supports private attachments and request view shows them. Ready/issuance gate requires `Complete`. | Passed in CI #226: existing request-transition suite plus request attachment upload/detail checks (`B8-4`). | **NOT RUN** | Obtain the barangay-approved document-by-document requirement checklist and role/status workflow. The test sheet below is a proposed verification checklist, not an approved policy. |
| 5. Document Issuance History | Implemented in branch: total uses ledger amounts; snapshot amount remains separately visible; missing ledger entries are explicitly labeled; reconciled/unreconciled filter is shared with CSV export. | Passed in CI #226: ledger amount and reconciliation filters, CSV export, and rejection of editing a reconciled item without first marking it Pending (`B8-9` to `B8-11`). | **NOT RUN** | Review/backfill eligible historic paid issuances (only where reliable receipt evidence exists). Missing ledger rows contribute zero to the ledger-based amount until reviewed. |

### Cross-cutting status

- Migration `011_document_attachments_cash_ledger_signature.sql` adds the attachment, ledger and typed-signature fields. The expected schema snapshot was updated.
- The dashboard's “Revenue collected” figure now uses reconciled cash-ledger entries, with pending reconciliation shown separately. A combined monthly/quarterly report can be generated for DILG, the Mayor’s Office, or PNP; the output remains a draft pending official templates.
- Administrator-only SQL backup now offers optional HTTPS WebDAV upload when the destination and app credentials are privately configured. Automated checks cover URL validation and the UI/config state, but real cloud transfer and restoration remain **NOT RUN** pending an approved cloud destination.
- Added `tests/mod_b8.php`; this extends rather than replaces the existing B7 reporting tests.
- PDF output is marked draft pending approval. Test with the exact office PHP version and verify that each downloaded PDF opens in a standard PDF reader.
- **No process target is claimed as measured:** no 99% accuracy, retrieval-time improvement, or processing-time target is reported as achieved by this remediation.

## 1A. Automated verification discrepancies found and closed

These are issues actually exposed by the automated CI runs during remediation. They are not observations from the target office PC.

| ID | Where detected | Discrepancy found | Corrective action | Verification |
|---|---|---|---|---|
| CI-01 | Migration 011 on MariaDB 10.11 | MariaDB rejected a CHECK constraint that referenced the attachment table's foreign-key owner columns. | Removed the incompatible CHECK constraint. The upload helper writes exactly one parent reference (visitor or request); ownership remains linked by foreign keys. | Migration 011 subsequently applied in the passing CI run. |
| CI-02 | Strict CSP test S2 | Inline style attributes in the ledger editor and certificate draft banner violated the application's no-inline-style policy. | Moved the styling into the shared BIRMS stylesheet and replaced inline style attributes with CSS classes. | All 37 authentication/security/audit checks passed. |
| CI-03 | Certificate issuance integration tests B5 | Issuance SQL did not include the new signature snapshot column consistently with its parameters, and a later ledger insert could overwrite PDO's last-insert ID. | Added the signature column and matching placeholder; captured the issuance ID immediately after its INSERT and before ledger insertion. | Existing certificate issuance/history tests passed. |
| CI-04 | B5/B7 integration cleanup | New ledger foreign keys prevented test cleanup from deleting issuance parents while ledger child rows still existed. | Updated test cleanup to delete ledger children before issuance rows. | Module suite passed through B7 cleanup. |
| CI-05 | New B8 upload integration test | The initial fixture cleanup removed the temporary PDF before upload tests started. | Kept the fixture until the final cleanup block. | B8-1 to B8-14 passed. |
| CI-06 | New B8 reconciliation-filter assertion | The test searched for a reference anywhere in the response, which also included the still-populated search input when no row matched. | Changed the assertion to verify the expected empty-results state for the unreconciled filter. | B8-9 passed; the final module suite reported 133 PASS, 0 FAIL, 0 NOT TESTED. |

The latest verified full-suite run before merge is [application-code CI run](https://github.com/sachie34/Birms/actions/runs/39845148883). No office-PC/browser behavior is inferred from these CI results.

## 2. Office-PC setup record — fill during the actual test

Do not fill in guessed versions or test outcomes.

| Field | Actual value |
|---|---|
| Tester name / role | ______________________________ |
| Test date and time | ______________________________ |
| Computer / Windows version | ______________________________ |
| XAMPP version | ______________________________ |
| PHP version (`php -v`) | ______________________________ |
| Apache status | ☐ Running ☐ Failed |
| MySQL/MariaDB version | ______________________________ |
| Working database name (`*_work` / `*_test`) | ______________________________ |
| Browser and version | ______________________________ |
| Application URL / base path | ______________________________ |
| Latest GitHub Actions run URL and conclusion | ______________________________ |
| Evidence location (screenshots/logs; do not include real resident data) | ______________________________ |

### Safety steps before testing

1. Back up the database and verify the database name is a separate `*_work` or `*_test` copy. Do not run migrations or test mutations against the original `barangay_db`.
2. Pull the reviewed branch into the intended working copy. Confirm migration 011 is applied via `php tools/migrate.php status`; use `php tools/migrate.php up` only after backup and verification.
3. Run `php tools/schema_check.php`, `php tests/run_tests.php http://localhost/birms/public --root=http://localhost/birms/public`, and `php tests/run_modules.php http://localhost/birms/public` as appropriate to the real installation. If the app is not in `/birms`, adjust the URL to its actual folder.
4. Use fictional names, receipts and supporting documents. Do not upload real identity documents into a demo/test system.

## 3. Functional browser UAT checklist

**All results below remain NOT RUN until a tester performs them on the target office computer.** Record actual result, defect/ticket, evidence reference and tester initials.

### Module 1 — Visitors Logbook & Form Filing

| ID | Test action | Expected result | Actual result / evidence |
|---|---|---|---|
| V-01 | Create a visitor entry without an attachment. | Entry saves and is searchable by visitor name/purpose. | **NOT RUN** — |
| V-02 | Create a visitor entry with a valid PDF under 10 MB. | Entry saves; attachment filename/link appears; download opens the same PDF. | **NOT RUN** — |
| V-03 | Repeat with a JPG and PNG. | Accepted if MIME content matches the file extension; links download correctly. | **NOT RUN** — |
| V-04 | Try a TXT, executable, or renamed non-PDF file with a `.pdf` extension. | Upload is rejected; visitor entry and file record are not created. | **NOT RUN** — |
| V-05 | Try a file over 10 MB, including one beyond the PHP upload limits. | Clear error is shown; no partial attachment record remains. Note PHP's own upload-limit message if it rejects before application validation. | **NOT RUN** — |
| V-06 | Download a private attachment while signed in with a role permitted to view requests, then try with a session lacking login. | Authorized download succeeds; unauthenticated download is refused. Check stored path is not a public URL. | **NOT RUN** — |

### Module 2 — Automated Certificate Generation

| ID | Test action | Expected result | Actual result / evidence |
|---|---|---|---|
| C-01 | Open a Ready request with complete requirements, configured fee and wording. | Preview is shown with clear “DRAFT — PENDING OFFICIAL LAYOUT APPROVAL” warning. | **NOT RUN** — |
| C-02 | Issue a free certificate with a typed signatory name. | Issuance saves once; signature text is snapshotted; print view shows draft and typed-signature disclaimer. | **NOT RUN** — |
| C-03 | Download the issued certificate PDF. | HTTP download is a valid PDF that opens in a PDF reader and contains correct issuance details. | **NOT RUN** — |
| C-04 | Try a request that is not Ready, or whose requirements are not Complete. | Issuance is blocked and request status is unchanged. | **NOT RUN** — |
| C-05 | Review printed/PDF layout against the proposed official form. | Record alignment, page breaks, characters, fields and disclaimer issues; do not call it official until approved. | **NOT RUN** — |

### Module 3 — Cash Collection Ledger

| ID | Test action | Expected result | Actual result / evidence |
|---|---|---|---|
| L-01 | Issue a paid certificate with an O.R. number. | One linked ledger entry is created with the issuance amount, requester, method, collector and Pending status. | **NOT RUN** — |
| L-02 | Open the ledger as Secretary/Clerk; then open as Administrator. | Non-Administrator access is denied; Administrator sees ledger list and controls. | **NOT RUN** — |
| L-03 | Edit transaction date, amount, method and notes on a test entry. | Values save and the action is audited. Confirm amount changes only in ledger and not in the issuance snapshot. | **NOT RUN** — |
| L-04 | Mark a pending entry Reconciled. | State, reconciler and reconciliation timestamp are stored and visible. | **NOT RUN** — |
| L-05 | Attempt deleting a reconciled entry. | Reconciled row cannot be deleted until returned to Pending; transition is audited. | **NOT RUN** — |
| L-06 | Use the legacy-entry action for a paid issuance with an O.R. but no ledger row. | One Pending row is added; duplicate attempt is rejected. | **NOT RUN** — |
| L-07 | Reconcile a ledger entry against the office's approved receipt/cash/deposit evidence. | Record the evidence/reference according to office policy; a status label alone is not proof that money was independently verified. | **NOT RUN** — |

### Module 4 — Request Queue and status transitions

| ID | Test action | Expected result | Actual result / evidence |
|---|---|---|---|
| R-01 | File a request with complete basic fields. | New request is Pending and has a unique reference number. | **NOT RUN** — |
| R-02 | Move Pending to Review. | Transition is allowed only to roles granted the action; audit/status history records the change. | **NOT RUN** — |
| R-03 | Mark requirements Incomplete without a note. | Save/transition is blocked with a validation message. | **NOT RUN** — |
| R-04 | Mark requirements Incomplete with an explanatory note. | The note and status persist; request is not allowed to move to Ready or be issued. | **NOT RUN** — |
| R-05 | Mark requirements Complete, then move the request to Ready. | Allowed for authorized role; status and history update; the issue action appears only where permitted. | **NOT RUN** — |
| R-06 | Attempt issuance with requirements not Complete or request not Ready. | Server blocks the operation; no issuance or ledger row is created. | **NOT RUN** — |
| R-07 | Reject a request without a reason, then with a reason. | Missing reason is blocked; reasoned rejection is persisted/audited. | **NOT RUN** — |
| R-08 | Cancel a request and inspect its history. | Authorized transition behaves as specified by current rules; reason/note appears where entered. | **NOT RUN** — |
| R-09 | File a request with a valid attachment and open its detail page. | Attachment is stored privately; permitted staff can download it. | **NOT RUN** — |
| R-10 | Try editing a Ready/Claimed request directly by URL. | Server denies editing; status cannot be bypassed by a hidden button. | **NOT RUN** — |
| R-11 | Compare each document type against the formally approved requirement checklist. | Record each required ID/form/attachment and exception. Until a checklist is approved, report this as **Blocked pending stakeholder input**, not “all checklist items satisfied.” | **NOT RUN** — |

### Module 5 — Issuance History and export

| ID | Test action | Expected result | Actual result / evidence |
|---|---|---|---|
| H-01 | Issue a paid test certificate and compare issuance fee snapshot to ledger entry. | Both show separately labeled amounts; initial pending ledger amount equals the recorded issuance quote unless deliberately corrected. | **NOT RUN** — |
| H-02 | Change the ledger amount to a different test amount. | History “Ledger amount” and ledger total reflect the changed ledger amount, while issuance snapshot remains separately visible and unchanged. | **NOT RUN** — |
| H-03 | Mark the row Reconciled and filter history. | Reconciled filter includes the record; Unreconciled filter excludes it. | **NOT RUN** — |
| H-04 | Mark it Pending and filter history. | Unreconciled filter includes it; Reconciled filter excludes it. | **NOT RUN** — |
| H-05 | Inspect an older paid issuance without a ledger entry. | History shows “No ledger entry”, ledger amount is zero, and totals do not silently treat the saved fee snapshot as collected. | **NOT RUN** — |
| H-06 | Export a filtered history CSV and open it in a spreadsheet. | Export follows the same filters and contains both snapshot and ledger columns, method, status, transaction date and collector. | **NOT RUN** — |

## 4. Defects and discrepancies log

| ID | Test / page | Actual behavior and evidence | Severity | Fix / PR reference | Retest status |
|---|---|---|---|---|---|
| — | No target-office execution available in this session. | There is no observed office-PC/browser result to enter honestly. Populate this table after the tests above are run. | Blocker for UAT sign-off | Target PC tester | **NOT RUN** |

## 5. Requirement checklist approval and sign-off

The official request requirements, certificate template, fees and ledger reconciliation evidence rules must be supplied or approved by the Barangay Secretary / authorized system owner. Tests in this document are proposed cases for verification and do not assert that unpublished requirements have been met.

| Approval item | Status | Approver / date / notes |
|---|---|---|
| Official certificate template and signatory policy | **PENDING** | ______________________________ |
| Document-by-document supporting-requirement checklist | **PENDING** | ______________________________ |
| Fee rates and accepted payment methods | **PENDING** | ______________________________ |
| Cash reconciliation policy and evidence | **PENDING** | ______________________________ |
| Target office PC browser UAT complete | **NOT RUN** | ______________________________ |
| Group leader sign-off | **DO NOT SIGN until the code and UAT have been reviewed** | ______________________________ |
