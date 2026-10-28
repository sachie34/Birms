# BIRMS Quick User Workflow

This guide follows the current repository code. Dashboard shortcuts are filtered by the signed-in account's permissions. The role matrix is **provisional** and must be approved by the barangay before production use.

## Everyday document workflow

1. **Find the resident first.** Open **Residents** and search by name or household code. Check the profile before creating another record. If the resident is missing, an authorized user can register the profile and assign its household/Purok.
2. **Create the request.** Open **Request Tracker → New request**, choose an existing active resident and document type, enter the purpose, and save. A visitor entry is optional; after saving a visitor log, use **Start a document request** to continue.
3. **Review requirements.** Open the request and check the barangay-approved checklist. Add follow-up notes and permitted attachments as needed. Mark requirements **Complete** only when they have actually been checked. An incomplete request cannot move to Ready for Pickup or be issued.
4. **Approve or reject.** In the current code permission map, only **Administrator** and **Barangay Captain** can do final approval/rejection. Secretary/Clerk users can intake and edit requests but cannot approve. Approval moves a request directly to **Ready for Pickup**; there is no separate saved **Approved** status.
5. **Issue and print.** An authorized issuer opens **Certificate Generation**, selects a ready request, checks the resident, approved template/fee/signatory details, and enters the O.R. number when a fee is charged. Issuing saves the certificate, creates issuance history and changes the request's stored status to `Claimed`, displayed to users as **Issued**. PDF output remains draft until official wording/layout and signatory rules are approved.
6. **Find the result later.** Use **Issuance History** to search by resident name, reference number or O.R. number. Use the audit trail, when permitted, to review recorded actions.

## Other common tasks

### Residents
Use global **Search records** or the Residents list to find a person first. Filter by Purok/category/residency where needed. Voter/ID verification is a manual record; the system does **not** contact COMELEC or another external database.

### Blotter and hearings
1. Select **Blotter → File a case**. Enter the date, narrative and complainant; add the respondent when known.
2. Open the saved case to review parties, update the permitted status, schedule a hearing and record its outcome.
3. Use **Case Look-up** for a name or case check. A possible name match is a lead for staff review, not proof of identity.
4. Current stored case states are **Pending**, **Active**, **Scheduled** and **Resolved**. There is no separate PNP/LGU referral workflow in this version.

### Reports
- The demographic report is a current active-resident snapshot, not historical period-end population.
- Choose the report type and period that fit the question. Check totals before printing/exporting CSV.
- The monthly/quarterly administrative output is a draft until the intended agency's template and mandatory fields have been approved.

### Administrator setup
Before using official records, the system owner must confirm the barangay details, Puroks, active document types, certificate wording, fees, Captain/signatory details, summons wording and role permissions.
- **User Accounts:** manage access.
- **Fees and Puroks:** configure approved settings/rates.
- **Audit Trail:** inspect recorded actions.
- **Backup:** download the SQL export to a private location. Live cloud transfer and a successful restore are not verified by automated tests; test restores only in a separate scratch database.

## Current role access

| Role | Typical functions available in the current code |
|---|---|
| Administrator | All currently defined permissions, including accounts, settings, approvals, issuance, reports, audit and backup. |
| Barangay Captain | View residents/requests, approve/reject requests, view issuance history, review/edit blotter/hearing items where permitted, view reports and audit. |
| Barangay Secretary | Register/edit residents, intake/edit requests, issue eligible ready certificates, manage current settings, work on blotter/hearing records and reports. No final request-approval permission in this map. |
| Barangay Staff / Clerk | Register/edit residents, intake/edit requests, issue eligible ready certificates, file blotter cases and view hearings. Other menus are hidden when permission is absent. |

This table describes the current code, not an approved barangay policy. Confirm the access matrix against the final paper with the system owner.

## When a step is blocked

- **Resident not found:** clear filters and check if the record is archived. Add it only if authorized.
- **No certificate is ready:** check the Request Tracker for pending review and incomplete requirements.
- **Issuance is blocked:** read the on-screen blockers. Common causes include missing certificate wording, missing fee or Captain/signatory settings, incomplete requirements, or a request that is not Ready.
- **A menu item is missing/access is denied:** the account likely lacks that permission. Ask the Administrator; do not share accounts to bypass role controls.
- **SMS says queued/pending:** that is not proof the message reached the phone. Likewise, a backup button alone does not prove a cloud copy can be restored.

## Before presentation or real use

Use fictitious demo records on `barangay_db_work` or another isolated test database. Test the actual browser, PDF reader, upload/download behavior, printer and each role on the target XAMPP computer. Automated CI results do not replace local acceptance testing.
