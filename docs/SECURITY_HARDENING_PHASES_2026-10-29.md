# BIRMS Security Hardening — Phase-by-Phase Record

**Date:** 29 October 2026  
**Repository:** [sachie34/Birms](https://github.com/sachie34/Birms)  
**Review branch:** `john/fix-clerk-global-search-privacy`  
**Pull request:** [#25 — Phase-based security hardening](https://github.com/sachie34/Birms/pull/25)  
**Status:** Changes are committed to the review branch and are not merged into `main`.

## Validation rule

After each phase, the change set was rechecked using the repository's GitHub Actions workflow on a disposable MariaDB 10.11 test database, with PHP syntax checks, include-path checks, setup preflight, security/authentication/audit tests, and module integration tests. No real SMS provider was enabled or contacted.

| Phase | Main work | CI result |
|---|---|---|
| 1 — Record privacy | Restricted Clerk global request search to requests they created or that are assigned to them; added B9-6 regression coverage. | [Run #40899014066](https://github.com/sachie34/Birms/actions/runs/40899014066): 39/39 security checks, 188/188 module checks, zero failures. |
| 2 — Concurrency and print audit | Serialized administrator role/status changes; locked and rechecked hearing scheduling; added database uniqueness guard migration 013; print counts now track explicit CSRF-protected print actions rather than GET previews. | [Run #40900472957](https://github.com/sachie34/Birms/actions/runs/40900472957): 39/39 security checks, 189/189 module checks, zero failures. |
| 3 — Abuse throttling | Added database-backed fixed-window rate limits with hashed identifiers, HTTP 429 and Retry-After. Covered search, blotter look-up, resident CSV import, attachments, exports, reports, backups, SMS retries/refresh, account creation/admin actions and password changes. Added migration 014 and a limiter regression test. | [Run #40901551482](https://github.com/sachie34/Birms/actions/runs/40901551482): 40/40 security checks, 189/189 module checks, zero failures. |
| 4 — Runtime, credentials and bundled assets | Added patched-PHP guard and preflight, hidden CLI password input, admin password-reset/account-action limits, refreshed Bootstrap to 5.3.8 and Bootstrap Icons to 1.13.1 with their local fonts and licenses, and added runtime/prompt/asset tests. | [Run #40903065126](https://github.com/sachie34/Birms/actions/runs/40903065126): 43/43 security checks, 189/189 module checks, zero failures. |

## Phase 4 runtime requirements

The application and setup preflight require one of the security-patched stable PHP branches:
- PHP 8.3.36 or later within 8.3
- PHP 8.4.27 or later within 8.4
- PHP 8.5.12 or later within 8.5
- PHP 8.2.35 or later within 8.2, temporarily accepted only while that branch remains supported. PHP 8.2 reaches end of security support on 31 December 2026; upgrading to 8.3+ is recommended.

These minimum patch levels address the critical upstream vulnerabilities announced by the PHP team on 21 October 2026. See the [official PHP release/security notice](https://www.php.net/archive/2026.php) and [supported versions](https://www.php.net/supported-versions.php). CI confirmed the setup preflight ran under PHP 8.3.36. **The owner still needs to verify both the CLI PHP and the PHP version Apache actually loads in their local XAMPP installation.** The source guard cannot substitute for updating the PHP binary or patch a runtime exploit that can execute before application code.

## Important deployment limits

- Rate limiting happens after PHP receives a request. Configure appropriate `upload_max_filesize` and `post_max_size` values in `php.ini` and retain web-server ingress limits.
- The visible tests verify that password scripts use the secure-input helper, but the actual Windows PowerShell prompt must still be exercised manually on the target Windows/XAMPP machine.
- No Composer or npm lockfile exists in this repository; a full locked dependency scan was not possible. The separately vendored Bootstrap assets were updated from official upstream release files, but the entire host/XAMPP installation is not thereby certified CVE-free.
- The active role matrix remains provisional pending barangay owner approval. Certificate/summons wording, signatories, letterhead and fee rules require approval. Real browser, PDF reader/printer, live SMS sending and cloud-backup restoration remain manual acceptance tasks.
- The historical root `Api` file was removed from current source; if its old credential-like value was real, revoke/rotate it because deleting a tracked file does not erase Git history.

## Final branch test summary

At Phase 4, GitHub Actions passed **43/43 security/authentication/audit checks and 189/189 module checks: 232 checks total, zero failures, zero untested checks**. This demonstrates regression results in CI, not official production approval. PR #25 remains open and awaits review/merge.
