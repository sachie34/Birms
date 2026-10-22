# BIRMS Master Checklist

Barangay Wawa, Balagtas, Bulacan. Updated 17 Oct 2026. Working copy, batches B1 to B6.
**Rule:** nothing is "verified" until its test passes on the owner's XAMPP. "Sandbox" below means my test environment
(PHP 8.3.6, MariaDB 10.11, PHP built-in server), which is **not** the owner's XAMPP.

## 1. Batches
| Batch | Content | Status |
|---|---|---|
| B1 | Accounts, sign-in lockout, roles (provisional), audit trail, user management, layout | Built. Sandbox-verified. XAMPP retest pending |
| B2 | Settings: barangay details, Puroks and lists, document types with fee and wording, age brackets, SMS text | Built. Sandbox-verified. XAMPP retest pending |
| B3 | Residents and households: register, edit, search, archive/restore, household head, CSV export | Built. Sandbox-verified. XAMPP retest pending |
| B4 | Visitor log, document requests, request tracker and SMS outbox with optional Semaphore/Infobip adapters | Built and mocked-provider tested. Real XAMPP/SMS delivery check pending |
| B5 | Certificate issue, print, issuance history and export, unique O.R. numbers | Built. Sandbox-verified. XAMPP retest pending |
| B6 | Blotter: cases, parties, status, hearings with printable summons, case look-up, clearance background check | Built. Sandbox-verified. XAMPP retest pending |
| B7 | Reports (monthly, quarterly), real dashboard figures, incident counts by Purok, demographic breakdown | NOT STARTED |
| B8 | Backup and export page | NOT STARTED |
| B9 | Voter verification (method pending), integration and regression, final delivery | NOT STARTED |

## 2. The 21 documented items
| # | Item | Status | Evidence |
|---|---|---|---|
| 1 | Resident profile registration | Built (sandbox) | B3-4 to B3-9b |
| 2 | Purok masterlist | Partial | Purok list and search by Purok (B2-6, B3-12). Grouped Purok report waits for B7 |
| 3 | Voter and ID verification | Field only | `voter_status` column exists. The method is undecided |
| 4 | Demographic breakdown | NOT STARTED | Age-bracket setting exists (B2-4). Report is B7 |
| 5 | Address and status tracking | Built (sandbox) | Active / relocated with audit (B3-7, B3-9b) |
| 6 | Visitors logbook | Built (sandbox) | B4-2, B4-3 |
| 7 | Automated certificate generation | Built (sandbox) | B5-3 to B5-13. Refuses to issue until fee, wording, Captain and Purok are set |
| 8 | Fee matrix and billing | Partial | One fee per document, snapshot at issue, O.R. number (B2-10 to B2-12, B5-5, B5-9). Tiers and processing fee are not modeled, amounts are pending |
| 9 | Request queue tracker | Built (sandbox) | B4-5 to B4-15 |
| 10 | Issuance history | Built (sandbox) | B5-14, B5-15 |
| 11 | Incident and complaint logging | Built (sandbox) | B6-2, B6-3, B6-4, B6-7, B6-19 |
| 12 | Case status tracker | Built (sandbox) | B6-5, B6-6, B6-10, B6-11 |
| 13 | Complainant / respondent look-up | Built (sandbox) | B6-12, B6-13 (clearance background check) |
| 14 | Hearing and summons scheduler | Built (sandbox) | B6-8, B6-9, B6-14, B6-15, B6-16. Summons wording is blank until the Secretary enters it |
| 15 | Incident summary and safety metrics | NOT STARTED | Counts by Purok belong to B7 |
| 16 | Authentication and RBAC | Built (sandbox), permissions PROVISIONAL | U1, U2, H8, role checks in every batch |
| 17 | Periodic report generator | NOT STARTED | B7 |
| 18 | Audit trail | Built (sandbox) | A1 to A6, B2-16, B3-18, B4-18, B5-8 |
| 19 | Executive dashboard | Partial | Shell and recent activity only. Real figures in B7 |
| 20 | Backup and export | NOT STARTED | CSV exports exist for audit, residents and history |
| 21 | SMS notifications | Optional provider integration; disabled by default | B12-01–B12-10 cover mocked provider behavior; real SMS submission/delivery remains unverified |

## 3. Test evidence (sandbox, 16 Oct 2026)
| Suite | PASS | FAIL | NOT TESTED |
|---|---|---|---|
| `tests/run_tests.php` (B1: security, accounts, audit) | 36 | 0 | 1 (E1 needs Apache) |
| `tests/run_modules.php` (B2 18, B3 21, B4 20, B5 20, B6 23) | 102 | 0 | 0 |
Also run once: five B1 **mutation tests** and eight B6 mutation tests (CSRF, scheduling permission, hearing-due rule, resolve rule, look-up wildcard escaping, summons wording check, print counting, clearance hint; each made its matching test fail) (CSRF, role check, status re-check, lockout, cookie flags deliberately broken; each made the matching tests fail);
idle timeout (3-second limit); from-scratch install; migration guards on existing-looking databases; schema check on a demo database.

## 4. Defects found and fixed in the latest passes
- Certificate history CSV export returned HTTP 500 for Captain and Secretary (a page loaded `bootstrap.php` twice). Fixed by loading it with `require_once` on all 26 pages. Regression: B5-1, B5-15.
- Without the mbstring extension, text was cut by bytes and could split a letter. Fixed. Test U6.
- B6 test flaw: one assertion counted every audit row in the database, so it passed only on a first run. Now relative; the suite passes three runs in a row.
- Test-only fixes: timezone (tests used UTC, app uses Asia/Manila), a stale counter, a dropdown matched as results, a missing include. New test H19 crawls every sidebar link for all roles.

## 5. The 20 review findings
Sandbox-verified, needing XAMPP retest: H1, H2, H3, H4, M1, M2, M3, M4, M5, M6, M9, M10, L1, L2, L3, L4 (16).
Fix applied, awaiting retest: M7 (needs Apache), L5 and L6 (need a browser) (3). Open: M8 (needs the owner's real database comparison) (1).
**None is "Fixed" until verified on the owner's XAMPP.**

## 6. Pending (not invented)
Official Purok list, fees and tiers, certificate wording and letterhead, Captain and Secretary names, resident categories, government report formats,
SMS provider, voter-check method, HTTPS setup, the Barangay Secretary's validation of permissions, the owner's real `barangay_db` structure.

## 7. How to continue
1. Run both test runners on your XAMPP (README_XAMPP.md section 6) and send the full output.
2. Run `tools\schema_check.php --db=barangay_db` (read-only) and send the output.
3. Enter the official details in Settings and open every page in a browser; report anything odd.
4. Barangay Secretary reviews the provisional role matrix.
5. Then B7 (Reports and dashboard), B8 (Backup), B9 (final delivery).

## 8. Not verified
Browser appearance and the strict Content-Security-Policy; printing certificates and summons on paper; your exact XAMPP versions; Apache access rules (E1); HTTPS;
performance with thousands of records; any code path no test covers. A snapshot of the earlier checklist is in `MASTER_CHECKLIST_snapshot_after_B1.md`.
