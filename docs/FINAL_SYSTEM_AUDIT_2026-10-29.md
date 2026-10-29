# BIRMS Professional System Audit — 29 October 2026

**Repository:** [sachie34/Birms](https://github.com/sachie34/Birms)  
**Main snapshot at report preparation:** `e66ca645e41396ba02d84660629c3ea8a37783a9`  
**Audit fixes merged:** [PR #24](https://github.com/sachie34/Birms/pull/24), merge commit `6aef2c4f9b7ccce5cde50d39ea61742d2b7edf12`  
**Automated evidence:** [GitHub Actions run #40817002749](https://github.com/sachie34/Birms/actions/runs/40817002749) on the exact final-audit code branch head before merge.

## Executive conclusion

**Code readiness: strong for a controlled school demonstration with fictitious records. Official deployment: not signed off yet.**

The final audit run passed **39/39 authentication, security and audit checks** and **187/187 module integration checks**: **226 checks, zero failures, zero untested checks**. It includes PHP syntax checks, repository include-path checks, setup preflight, disposable MariaDB migrations, access control, module workflows, CSP, CSV exports, page help, request-review integrity, certificate-print permissions, and protection against running module tests with a real SMS provider enabled.

This is evidence from automated tests in an isolated CI environment. It is not evidence that the owner's local XAMPP, browser, printer, real SMS account or live backup destination has passed acceptance testing. This was a repository/source review plus automated testing, not an independent external penetration test.

## Small defects found and corrected

| Finding | Correction | Regression evidence |
|---|---|---|
| Page-help panel could disappear when `SCRIPT_NAME` did not include `/public/`. | Fixed route detection for both public-folder and nested-route URL shapes. | B9-5 checks role-specific Request Tracker help for Administrator, Captain, Secretary and Clerk. |
| New help UI and other generated HTML had inline styles blocked by the strict Content Security Policy. | Moved styling to CSS and expanded the CSP scan to shared `app/*.php` helpers. | S2 passes. |
| CSV formula protection could be bypassed by ASCII whitespace/control characters before `=`, `+`, `-` or `@`. | Added shared `csv_safe_cell()` and applied it to resident, issuance, audit, report and import-error CSV exports. | U7 and audit-export regression checks pass. |
| A request could be edited while under review, potentially leaving its previous requirements check marked Complete after details or attachments changed. | Restricted review-stage edits to Secretary/Admin; formal review starts unchecked; material changes reset requirements; final approve/reject SQL rechecks the Complete state when writing. | B4-20 passes; B7-12 was adjusted to follow the actual review sequence and passes. |
| Captain's certificate preview page exposed print/download controls even though the Captain lacks print permission. | Kept preview access but removed print/PDF controls; direct PDF route now requires print permission and history actions are labeled as preview for non-print roles. | B5-21 passes. |
| The module integration suite could move synthetic requests to Ready and trigger a real paid SMS if a provider were enabled in the local configuration. | Test runner now refuses to start while the selected SMS provider is enabled. | U8 proves the refusal occurs before the module suite starts. |

## Status of the 20 functions

The five core functional areas and the 15 supporting functions have their primary application flows and automated coverage. The checklist's individual function rows remain the source of detail: [Final 20-Function Verification](FINAL_20_FUNCTION_VERIFICATION.md).

Key limitations that must stay visible in presentations and documentation:

- Resident registration, household/Purok organization, demographics, current address/status, request queues, issuance history, case tracking, local case-party lookup, audit trail, and data-backed dashboard/report summaries are implemented and covered by tests.
- Voter/ID verification records a staff-performed check; it is **not** an online COMELEC verification.
- Certificate PDF/print output is still marked **DRAFT** until official wording, letterhead, signatory policy and fee rules are approved.
- Fee rules and cash-ledger workflow are implemented, but rates must be supplied/approved by the barangay. Ledger records are staff-entered and are not independent proof of a bank deposit or an online payment.
- Hearing/summons logic is implemented; official summons wording and printed layout still require approval. The version does not include a distinct PNP/LGU referral workflow.
- Reports are data-driven draft summaries. DILG/Mayor/PNP reporting templates and required fields must be confirmed by the receiving office. “Periodic” does not mean automatically scheduled/emailed.
- The role-permission matrix is **PROVISIONAL** pending barangay/system-owner approval. The UI now helps explain each relevant page, but the four accounts still need a real user walkthrough.
- SMS provider adapters have mocked CI coverage. A live SMS submission/delivery test is not claimed.
- Local SQL export exists. A live WebDAV upload and successful restoration have not been verified; scheduled backup/notifications are not included.

## Remaining acceptance checklist

Do these using a separate working database and fictitious records; do not run tests against the original `barangay_db`.

1. **Local XAMPP:** pull the latest `main`, use `barangay_db_work`, run both test runners with all real SMS providers disabled, and retain the complete output.
2. **Four roles:** sign in as Administrator, Captain, Secretary and Clerk. Check menu visibility, direct-page denials, page-help instructions, request queues, edit permissions, preview/print behavior and audit entries.
3. **Request lifecycle:** create a request; verify intake starts with requirements Not checked; start review; edit a material field/attachment and confirm the checklist resets; recheck requirements; approve/reject; verify issue and history.
4. **Artifacts:** test private upload/download, resident and history CSV exports, report CSVs, certificate PDF, print preview and the physical printer.
5. **Owner decisions:** approve the role matrix, data-collection fields, certificate and summons wording/layout/signatory, fee matrix/rates, request requirements checklist and any external report format.
6. **Optional live services:** perform an authorized live SMS send only when ready and funded; test WebDAV upload and restoration only to an approved destination and restore into a separate scratch database.
7. **Credential hygiene:** the historical root-level `Api` file was removed from current source; if its credential-looking value was ever a real secret, revoke/rotate it because deleting a file does not erase prior Git history. Avoid typing setup credentials where someone can view the terminal; the setup prompts currently echo password input.

## Sign-off statement

Use this status in the project report:

> **BIRMS automated code and integration verification passed (226 checks, zero failures or untested checks). Final local XAMPP/browser/PDF/printer acceptance, barangay approval of roles and official templates/rates, and live SMS/cloud-backup validation remain pending.**

Do not describe the software as officially deployed, fully accepted by the barangay, connected to COMELEC, or proven to send real SMS/restore cloud backups until the corresponding evidence is recorded.
