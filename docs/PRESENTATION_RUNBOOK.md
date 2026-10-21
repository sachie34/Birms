# BIRMS Presentation Runbook (October 23, 2026)

## Which version to present

Use the `john/paper-code-compliance` branch for the expanded demo. It contains the current B1–B8 application, reports/dashboard, visitor/request attachments, PDF generation, configurable fee matrix, and cash-collection ledger. These additions are not yet merged to `main`.

The [latest completed application-code CI run](https://github.com/sachie34/Birms/actions/runs/39650720352) before the final documentation changes passed PHP syntax, migrations 001-011 on disposable MariaDB 10.11, **37/37 authentication/security/audit checks**, and **142/142 module integration checks** (zero failures/untested). The dashboard and visible request-status label changes were included in that passing run.

CI is not the same as running the app on the target office PC. Target-PC XAMPP/browser UAT remains required. Also check the latest [GitHub Actions runs](https://github.com/sachie34/Birms/actions) for the setup-wizard preflight run triggered by the final workflow update.

## 1. Safety and setup

1. Download the [paper-compliance branch ZIP](https://github.com/sachie34/Birms/archive/refs/heads/john/paper-code-compliance.zip) and extract the full folder under `C:\xampp\htdocs\`.
2. Keep the original `barangay_db` untouched. The setup wizard targets a separate `barangay_db_work` database only.
3. Start Apache and the intended local MySQL/MariaDB server. The wizard expects `127.0.0.1:3306` by default; avoid starting two database services on the same port.
4. Double-click `SETUP_XAMPP.bat`. Enter the local MySQL root password and create a non-default administrator account/password when prompted. The wizard creates the private `app/config.local.php`, applies migrations, and sets up the limited application DB user.
5. Open the localhost URL printed by the installer (it includes your extracted folder name). Read [README_XAMPP.md](README_XAMPP.md) if the installer reports a setup error. Back up the working project and database before subsequent changes.
6. Create synthetic demo residents, households, visitors, requests, and blotter incidents. Do not use real residents' personal data for a classroom demo.
7. In Settings, configure test Puroks, required document wording and a test Captain/Secretary name. Configure demo fee rules only if you need to show the fee workflow. Use clearly marked draft/demo values; do not issue or present draft output as an official barangay certificate.

## 2. Run automated tests on the same working copy

Open XAMPP Shell or Command Prompt:

```bat
cd C:\xampp\htdocs\birms_work
C:\xampp\php\php.exe tools\migrate.php status
C:\xampp\php\php.exe tools\migrate.php up
C:\xampp\php\php.exe tests\run_tests.php http://localhost/birms_work/public --root=http://localhost/birms_work
C:\xampp\php\php.exe tests\run_modules.php http://localhost/birms_work/public
C:\xampp\php\php.exe tools\schema_check.php --db=barangay_db_work
```

Run migration commands only after confirming that `app/config.local.php` points to the separate working database. Both test runners refuse database names that do not end in `_work` or `_test`; respect that safeguard. The schema checker should show no differences after migrations. Expect zero failures and zero untested checks. If the test runners fail or report untested checks, save the complete output and fix those results before stating local tests passed.

The `--root` private-folder check must be run through Apache/XAMPP to verify `.htaccess` behavior. PHP's built-in development server does not enforce Apache `.htaccess` rules.

## 3. Recommended live demo sequence

Keep the browser ready and use a dedicated demo account for each role.

1. **Login and role access:** sign in, show the Operations Overview and role-filtered sidebar. Try one action that the chosen role should not be allowed to perform and show that server-side authorization denies it.
2. **Resident records and households:** register a synthetic resident, assign a household/Purok, search by name or household code, and show the saved profile.
3. **Visitor logbook and form filing:** record a synthetic visitor, attach a small sample PDF/JPG/PNG, and download the stored file through the authenticated link.
4. **Document request queue:** create a request for the demo resident, show Pending/Review, assign or review it, demonstrate that incomplete requirements block approval, then mark the requirements Complete and advance it through the permitted status flow.
5. **Certificate generation:** set test wording and a draft/demo signatory, issue a ready request, download the server-generated PDF, and open the print view. Check the resident name, reference number, date, fee label, draft warning, and typed-signature disclaimer.
6. **Issuance history and cash ledger:** find the issued certificate by name/reference/O.R. number. For a paid demo issuance, show its ledger entry and how Pending and Reconciled totals differ. Do not label a staff-entered ledger amount as independently verified cash unless actual receipt evidence has been checked under an approved office process.
7. **Blotter and hearings:** file a synthetic incident with complainant/respondent details, search the parties, schedule a future hearing, record a hearing outcome, and preview/print a summons only after its approved test wording is configured.
8. **Reports and dashboard:** open demographic, issuance, request, and blotter summaries; select a period and export a CSV. Compare at least one total with the same working database. Explain that demographic figures are a current active-resident snapshot, not a historical period-end count.
9. **Audit trail:** open the audit viewer and show the actions just performed, including any denied action if it was logged.
10. **Optional local backup:** as Administrator, download a SQL backup to a safe local folder. Do not attempt a cloud-backup claim unless a real approved HTTPS destination has been configured and upload plus restore have actually been tested.

## 4. Before the examiner arrives

- Start Apache and MySQL and sign in once before the presentation.
- Re-open the dashboard, resident search, request queue, certificate print/PDF, history, blotter/hearing, and report export in the browser.
- Confirm sample files can be uploaded and downloaded, the PDF opens in a PDF reader, and the physical printer/page layout is acceptable if printing is part of the demonstration.
- Check every demo account can only access its intended functions. The current role matrix is **provisional**, so describe it as the implemented access matrix, not as final barangay-approved permissions.
- Confirm the demo settings are already entered. Certificate issuance is intentionally blocked when required wording or fees are unset, required request checks are incomplete, a Captain name is missing, or the selected resident/request is ineligible.
- Remove or avoid displaying real personal information, private credentials, SQL backups, error logs, and browser/devtools data containing secrets.
- Keep a second copy of the working project and demo database backup. Do not put backups in the public web folder.

## 5. Be accurate about current limits

Do not claim any of the following as complete unless you have separately configured, tested, and obtained the required approval:

- **Official certificates or summons:** current output is prominently marked draft pending official layout/wording approval. The typed signatory is not a cryptographically verified digital signature.
- **Official DILG/Mayor/PNP template compliance:** reports are data-backed draft summaries; official agency formats and exact required fields were not supplied/approved.
- **SMS sending:** SMS is log-only; the app does not send real messages to a phone.
- **COMELEC integration:** resident identity/voter checks are staff-recorded manual checks; the app does not query COMELEC or an external database.
- **Cloud backup/restore:** local SQL download is implemented; optional WebDAV upload needs a private approved HTTPS destination. Live upload and successful restoration are not verified by CI.
- **Final office workflow/policy:** official Purok list, fee tier mapping, requirement checklists, payment reconciliation policy, and the Captain/Secretary/Clerk role matrix still require barangay/system-owner confirmation.
- **Measured accuracy or speed improvement:** the paper's accuracy and retrieval-time values are targets, not measured results. Use the UAT worksheet to record real task timings before making performance claims.

For exact requirement differences across the compiled thesis, SOE2 and the earlier paper, see [PAPER_CODE_ALIGNMENT.md](PAPER_CODE_ALIGNMENT.md). It documents that SMS is currently log-only and that an additional independently stored Approved stage is not part of the current request state model.

## Final sign-off

Mark the system “presentation-tested on this PC” only after the local test commands pass and you personally complete the important browser/print/download steps above. Until then, the correct status is: **automated CI passed; target-PC XAMPP/browser UAT not yet verified**.
