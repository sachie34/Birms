# BIRMS Paper-to-Code Acceptance and Performance Worksheet

Use this sheet for the research paper's functional usability, data accuracy, and retrieval-speed objective. Do not fill in results until the tests have actually been run. Use only an approved demo/working database and fictitious demo records for classroom presentation.

## Safety and test setup

- Run on a separate `*_work` or `*_test` database, never the original `barangay_db`.
- Record the computer, PHP/XAMPP version, database engine/version, approximate number of residents, requests, certificates, and blotter records.
- Use the same sample residents and the same task steps for the digital and paper-based comparison.
- Do not copy real resident ID numbers into test notes or screenshots.
- Automated CI checks syntax, migrations, and integration behavior. It does not substitute for browser UAT on the actual presentation PC.

## Core paper requirements to demonstrate

| Area | Test to perform | Evidence to capture | Pass condition |
|---|---|---|---|
| Authentication | Sign in and sign out; try pages allowed and denied for each assigned role | Screenshot or test sheet with role and outcome | Authorized users can use assigned pages; restricted actions return access denied |
| Resident management | Register a fictitious resident, edit details, search by name/household code, filter by Purok and residency status | Before/after test record ID and search result | Saved values match input and searches find the correct record |
| Certificate generation | File request, inspect requirements, mark Complete, approve to Ready, issue and print using approved demo wording/fee | Request reference, certificate reference, rendered print page | Request cannot be issued before required review; printed fields and saved history match the test record |
| Blotter tracking | File a fictitious case, search its case/party, update status, add a hearing and view history | Case number, status and hearing output | Case data/status are saved correctly and the matching case is retrieved |
| Report generation | Compare demographic, issuance, request and blotter report totals with direct counts from the same test database | Exported CSV and manually checked total | Figures reconcile with the records and selected period |

## Retrieval-speed measurement

The script below measures system-side read operations on the configured working database and reports median, mean, and maximum milliseconds. It prints no resident names or personal details.

```text
C:\xampp\php\php.exe tools\benchmark.php --iterations=10
C:\xampp\php\php.exe tools\benchmark.php --iterations=10 --query=Demo
```

Use an actual representative search term in place of `Demo` for your test dataset. The tool refuses to run unless the database name ends in `_work` or `_test`. The results describe only the selected machine and database; they are not automatically a comparison with the paper-based process.

### Comparison worksheet

Use a stopwatch for each manual and system trial with the same task and data. Enter seconds; calculate a median after at least five trials.

| Retrieval task | Paper-based trial times (s) | BIRMS trial times (s) | Paper median (s) | BIRMS median (s) | Difference / observation |
|---|---|---|---:|---:|---|
| Find one resident record |  |  |  |  |  |
| Find a specific blotter case |  |  |  |  |  |
| Prepare a certificate from a known resident record |  |  |  |  |  |
| Compile a monthly summary |  |  |  |  |  |

Record the actual times. Do not write projected speed improvements as measured results.

## Final UAT sign-off

| Check | Status / notes |
|---|---|
| Resident create/edit/search and Purok filter |  |
| Role boundaries tested with real login sessions |  |
| Request requirements review blocks approval until Complete |  |
| Certificate wording, fee, receipt/O.R. and print output approved |  |
| Blotter case, party lookup, hearing and status tested |  |
| Reports reconciled against the working database |  |
| CSV export opens correctly and contains the expected totals |  |
| Local SQL backup downloaded and restored into a separate scratch database |  |
| If cloud backup is configured, remote SQL file appears in the approved HTTPS WebDAV folder and can be retrieved |  |
| Tester / date / XAMPP version |  |

## Known limits to mention accurately

- Staff-recorded voter status and manual verification do not mean that BIRMS queries COMELEC.
- Fee matrix rules are configurable; no official rates are preloaded. The certificate issuance history and its CSV use the separate cash-collection ledger for amounts and reconciliation filters; other issuance/report/dashboard fee figures may reflect saved issuance snapshots. Ledger entries are staff-recorded and are not independent proof of cash counts or bank deposits. Review missing historical ledger entries against reliable O.R./cash evidence before financial use. No online payment gateway exists.
- The backup feature downloads a local SQL export and supports optional one-click HTTPS WebDAV upload when an approved remote folder and dedicated app credentials are configured privately. CI checks URL validation and configuration behavior; a real cloud transfer has not been tested because no cloud account is bundled. Scheduled backups/notifications and verified restoration are not implemented. Test a downloaded or remotely uploaded SQL file by restoring it only into a separate scratch database.
- SMS is log-only unless a real provider has been authorized and configured.
- The final role matrix and official certificate/summons wording must be approved by the barangay/system owner.
