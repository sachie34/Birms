# BIRMS Repository Instructions

These instructions apply to all work in this repository. They are specific to the Barangay Information and Records Management System (BIRMS) and take priority over generic coding preferences when working on this project.

## 1. Project context and source of truth

- Project: BIRMS, a PHP + MySQL/MariaDB + JavaScript + Bootstrap 5 application intended for local Windows/XAMPP and barangay-LAN deployment.
- Repository: `sachie34/Birms`.
- The owner-provided **`BRMS_SRS_Revised.docx` is the controlling MSIT requirements baseline**. It supersedes older paper drafts, earlier workflows, existing UI behavior, old database structures, and assistant-generated plans wherever they conflict.
- Before changing a feature, inspect the relevant SRS requirement, the current implementation, the migrations, permissions, routes, and tests.
- Read and maintain:
  - `docs/MSIT_SRS_COMPLIANCE_PLAN.md`
  - `docs/MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md`
- If the revised SRS itself is not available in the working context, do not invent a requirement. Ask for or retrieve the controlling document before making a decision that depends on its exact wording.
- Classify requirements honestly as **Implemented and verified**, **Partially implemented**, **Missing**, **Conflicting**, **Requires stakeholder decision**, or **Not run**. A screen, button, migration, or isolated unit test does not by itself prove an end-to-end requirement.

## 2. Revised MSIT scope

The in-scope services are:
1. Resident record management.
2. Blotter management.
3. Document/certificate management.
4. Venue and reservation management.
5. Resident queueing for Blotter, Documents, and Venue.

Resident-facing access uses the self-service kiosk and shared request QR entry point. Office users use the staff/admin portal.

Important revised-SRS constraints:
- Residents are grouped and filtered by **Purok**, with an **individual Address on each resident**. Do not reintroduce household-based grouping as a required workflow.
- The old visitor logbook is replaced by service queueing. Do not add it back as a mandatory module.
- Required resident statuses are Active, Transferred, Deceased, and Non-resident.
- Required resident types include PWD, Senior Citizen, Solo Parent, 4Ps Beneficiary, Indigent, and None.
- Blotter states include Ongoing, Unresolved, Resolved, Dismissed, and Archived. Official blotter cases must not be deleted; resolved/dismissed cases are archived.
- Public request tracking must not expose resident identity, contact details, address, or private incident narrative.
- Queue types and numbering are separate for Blotter, Documents, and Venue.
- Email is the default notification channel; SMS is optional. A queued notification is not proof of delivery.
- Chairman is read-only for operational records and approvals. Verify permissions in server-side authorization code; hiding a menu item is insufficient.
- Admin-managed Chairman signature images must be handled as private assets and applied only where required by the approved certificate workflow.
- Respect the SRS accessibility and performance requirements, including touch-target and text-size requirements. Do not claim compliance without evidence.

## 3. Role and permission safety

Target SRS roles are Admin, Chairman, Secretary, Staff, and Resident.

- Inspect `app/permissions.php`, authentication helpers, route-level guards, and the relevant test sessions before changing any role behavior.
- Existing database role identifiers may use legacy names (for example, Captain/Clerk). Never assume their permissions are equivalent to Chairman/Staff without checking the current permission map and regression tests.
- Enforce permissions on every server-side page, POST action, download, print, export, and other endpoint.
- Test allowed and denied actions using separate sessions and direct URL/POST requests.
- Do not elevate a user or weaken Chairman's read-only policy to make a workflow convenient.
- Preserve existing account IDs, password hashes, and audit history during role or account migrations.

## 4. Database and data-protection rules

- Work only on a dedicated feature/review branch. Do not commit directly to `main`.
- Automated test runners must use an isolated database whose name ends in `_work` or `_test`.
- Never run migrations, tests, data cleanup, restore, or destructive SQL against the original `barangay_db`, a live barangay database, or any database whose safety is uncertain.
- Do not request, print, commit, or expose real resident information, production credentials, provider keys, private configuration, or SQL backups.
- Use synthetic data for testing.
- Use new numbered migrations; do not rewrite migrations already applied to a database.
- Prefer staged/additive changes. Preserve legacy records and foreign-key history unless an approved migration explicitly addresses them.
- Before applying migration 013 to a working-copy database, inspect duplicate hearing slots using the documented pre-check. Do not delete historical hearings to make the migration pass.
- Preserve permanent blotter cases. Test backup/restore paths for stale snapshots, invalid references, rollback, and auditability.
- Treat bulk restore as a high-risk operation. Validate its complete input before mutation, protect against partial restore, preserve permanent blotter cases, and verify referential integrity before commit.
- SQL backups do not include private uploaded files unless a separate file-backup mechanism explicitly includes them. State this limitation and require a matching file restore; never imply a database-only restore is complete.
- Keep `storage/private_uploads`, resident photos, signature images, and other confidential files outside public web access. Verify authorized retrieval and unauthenticated denial.

## 5. Implementation discipline

Follow this workflow for non-trivial changes:

1. **Inspect:** identify the exact requirement, affected routes, helpers, migrations, permissions, UI, tests, and data dependencies.
2. **Plan:** state the defect or requirement gap, its root cause or evidence, affected files, risk, and smallest viable patch.
3. **Implement:** make a narrow change. Avoid unrelated redesigns, broad refactors, dependency upgrades, renaming, or file deletions.
4. **Test:** add or update meaningful regression coverage. Test positive paths, negative paths, role boundaries, invalid input, rollback, privacy, and concurrency where relevant.
5. **Review:** inspect the final diff for unintended changes, secrets, unsafe paths, schema incompatibilities, and altered test scope.
6. **Verify:** run syntax checks, relevant test modules, the full regression suites, migration/schema checks, and CI as applicable.
7. **Document:** record what changed, why, exact commit SHA, exact test evidence, known limitations, and manual acceptance still required.

For a simple question or small isolated task, use a proportionate version of this process rather than producing unnecessary ceremony.

## 6. Security and reliability requirements

- Keep CSRF protection on state-changing actions.
- Validate and normalize user-controlled input server-side; use prepared statements for data values.
- Escape output according to its HTML/attribute/URL/JavaScript context.
- Enforce authentication, authorization, ownership, and row-level privacy checks server-side.
- Apply rate limits to authentication, public resident lookup, OTP, and public request endpoints as designed.
- Use transactions for multi-step state changes and re-check concurrency-sensitive conditions inside the transaction.
- Retain audit records for important changes, approvals/rejections, releases, queue actions, role changes, backup/restore, and security-relevant refusals.
- A notification outbox entry or provider-accepted response must not be described as confirmed resident delivery.
- Do not expose exception traces, SQL details, credentials, private paths, or personal data in public responses or test output.
- Do not weaken validation or security controls merely to get a green test.

## 7. Testing and CI rules

- Preserve meaningful regression tests. Never delete, skip, downgrade, or rewrite a failing assertion solely to make CI pass.
- When a test is wrong, establish why, correct the expectation based on actual requirements and secure behavior, document the correction, and retain the regression intent.
- When an application defect is found, reproduce it, fix it minimally, add/retain a test that would fail before the fix, and rerun the relevant suites.
- Keep failures and NOT TESTED checks visible. Investigate exceptions and incomplete test modules; do not report an incomplete run as a pass.
- The repository's CI validates against disposable MariaDB versions. Report the exact run, commit SHA, database versions, counts, failures, and untested counts.
- Never treat hosted CI as proof of target Windows/XAMPP, Apache PHP, physical kiosk, phone QR/LAN, printer, real email/SMS, backup-file restoration, or office UAT.
- Do not state that a test passed unless its actual result/log supports the statement.

## 8. Release and manual acceptance gates

Keep the MSIT PR in draft until the documented target acceptance evidence is complete. Required manual checks include:
- Migration rehearsal on a fresh disposable database and a separate copy of the working database.
- Confirmation of CLI PHP and Apache's actual PHP version on the target XAMPP computer.
- Separate Admin, Chairman, Secretary, Staff, and Resident browser journeys, including denied direct URL/POST tests.
- Physical kiosk/touchscreen and timeout/session-reset tests.
- Shared QR scan from an actual phone on the barangay LAN.
- Real, authorized email delivery and optional approved SMS delivery tests.
- Approved Chairman signature, official certificate wording, PDF, and physical print checks.
- Database **and private-file** backup/restore into a separate scratch target.
- Performance measurements using representative synthetic data against the SRS target.
- LAN operation without internet and office/adviser UAT with evidence recorded in the traceability plan.

Use **PASS**, **FAIL**, **NOT RUN**, and **NOT TESTED** accurately. Do not mark a manual gate PASS based on CI.

## 9. Git and progress reporting

- Inspect the current branch, PR head, and latest CI before writing. Confirm the target branch before each commit.
- Keep review work on the dedicated branch. Never merge PRs, change the default branch, or deploy without explicit authorization and completed release gates.
- For each completed change, report:
  - What changed and why.
  - Files changed.
  - Commit SHA and branch.
  - Tests actually run and exact results.
  - What was not tested and remaining risks.
- Be clear whether a change is committed, pushed, in CI, passed, failed, or awaiting manual acceptance.
- Do not claim to be monitoring or working in the background. Do not promise five-minute messages or other unsupported schedules. Use only an actual configured automation when one exists; otherwise provide progress updates during the active session.

## 10. BIRMS-specific default decision rule

When several approaches are technically possible, prefer the one that:
1. Matches the revised MSIT SRS.
2. Preserves resident data, audit history, and permanent blotter cases.
3. Enforces least privilege and privacy on the server.
4. Is compatible with the existing PHP/MySQL/MariaDB/XAMPP deployment.
5. Can be tested with synthetic data and retained regression coverage.
6. Minimizes unrelated changes and manual operational burden.

If a requested feature conflicts with the revised SRS, risks data loss, cannot be safely verified, or depends on a stakeholder decision, stop before implementing the risky part. Explain the conflict and recommend the safest next step.
