# BIRMS master checklist

Updated: 13 Oct 2026, after batch B1. Rule: nothing is "verified" until the matching test passes on YOUR XAMPP.

## Batches
| Batch | Contents | Status |
|---|---|---|
| **B1** | Foundation, sign-in, roles, user accounts, audit trail, migration + admin tools, test runner | **DONE in sandbox**; XAMPP retest pending |
| B2 | Settings: Puroks, fee schedule, officials' names, certificate settings (all configurable, empty until you enter official values). Comparison of the proposed schema with your real `barangay_db` | NEXT. Needs your structure export for the comparison |
| B3 | Residents, households, Purok list, search and filters, residency status | Not started. Shape depends on D4/D5 (your export) |
| B4 | Visitor logbook, document requests, request tracker | Not started |
| B5 | Fee matrix and billing, certificate generation (printable), issuance history | Not started |
| B6 | Blotter: incidents, parties, case status, look-up, hearings and summons | Not started |
| B7 | Dashboard with real statistics, monthly/quarterly reports, demographics, incident metrics | Not started |
| B8 | Administrator backup/export page; SMS log-only outbox | Not started |
| B9 | Voter/ID verification (method pending), integration and regression, HTTPS guide, UAT script, final delivery | Not started |

## The 21 items
| Item | Batch | Status |
|---|---|---|
| User authentication and role-based access | B1 | Built; sandbox-verified |
| Audit trail and action logger (with viewer and CSV export) | B1 | Built; sandbox-verified |
| Executive dashboard | B1 (shell), B7 (statistics) | Shell only; shows real data only |
| Resident profile registration, Purok masterlist, address/status tracking | B3 | Not started |
| Demographic breakdown | B7 | Not started |
| COMELEC voter and ID verification | B9 | Not started; method pending |
| Visitors logbook, request queue tracker | B4 | Not started |
| Automated certificate generation, fee matrix and billing, issuance history | B5 | Not started |
| Incident logging, case status, respondent/complainant look-up, hearings, incident metrics | B6 / B7 | Not started |
| Periodic report generator | B7 | Not started; official formats pending |
| Backup and data export | B8 | Not started |
| SMS notifications (log-only) | B8 | Not started |

## B1 test results (sandbox: PHP 8.3.6, MariaDB 10.11, PHP built-in server)
Runner `tests/run_tests.php`: **35 PASS, 0 FAIL, 1 NOT TESTED** (E1: private folders not downloadable; needs Apache).
Other checks run once, not in the runner: mutation tests (CSRF, role check, status re-check, lockout, cookie flags each broken on purpose; the matching tests failed as expected), idle timeout (3-second limit), from-scratch install, two "existing database" refusals by `tools/migrate.php`, helpers with no PHP extensions (`php -n`).
**Not tested at all:** your XAMPP versions, Apache `.htaccess` behavior, any browser (appearance, Content-Security-Policy effects, the confirmation dialog, client-side validation), HTTPS.

## Provisional / pending (not invented)
Role permissions (Barangay Secretary to validate) | HTTPS approach | Purok names | fees | certificate wording | resident categories | report formats | SMS provider | voter-check method | middle name / suffix | required scope (D2).

## Known limitations of B1
Only accounts, roles, audit and dashboard exist. No residents or documents yet. Plain HTTP by default. The last-active-admin rule is a safeguard that cannot trigger today (the acting admin is always active), so it is untested.

## How to continue
Run the install steps in `README_XAMPP.md` and send me the test output. Send the structure-only export of `barangay_db` so B2 can compare it. Say "go" for B2.
