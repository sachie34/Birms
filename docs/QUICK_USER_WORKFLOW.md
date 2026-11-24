# BIRMS Quick User Workflow

This guide follows the current `john/msit-srs-compliance` branch. The revised MSIT SRS is the controlling requirements baseline. The role mapping and official office procedures still require barangay/adviser approval before production use.

## 1. Resident self-service: start here

Residents use the shared **Resident Request QR** or the touchscreen kiosk. The QR is a shared entry point; it must not contain a resident's personal information.

1. Scan the shared QR code or open the kiosk. Choose the service you need from the menu before verifying your identity.
2. Verify your identity:
   - Preferred: enter your **Resident ID** and **birthdate**.
   - If you do not know your Resident ID: enter your first name, last name and birthdate. BIRMS sends a one-time code to the email address registered on your resident record.
   - If you do not have a registered email address for the one-time code, use your Resident ID and birthdate or ask Staff to verify you at the counter. Do not keep guessing.
3. After verification, choose the service you need:
   - **Request a document**
   - **File a blotter report**
   - **Reserve a venue**
   - **Get a queue number**
   - **Check request status**
4. Resident notifications use **Email only**. If no valid email address is recorded, the request is still saved and the resident can use the printed reference number and public status tracker.
5. Keep the printed or displayed **reference number** and queue ticket. Use the reference number to check progress later.

The kiosk is for residents only. It is separate from the staff/admin portal. End the kiosk session when finished; the kiosk also has an inactivity reset.

## 2. Document request: submission to release

The document workflow has visible handoffs. The resident keeps the reference number while the responsible office role changes the request.

| Step | Responsible role | What happens | Resident-facing status |
|---|---|---|---|
| 1. Submit | Resident, or Staff at the counter | Select an active document type, state the purpose and submit. BIRMS creates a reference number, records the request and issues a Documents queue ticket. | Pending |
| 2. Requirements review | Secretary or Admin | Check the current document requirements and supporting details. Record missing items or mark requirements Complete only after checking them. If request details change, requirements must be reviewed again. | Pending |
| 3. Final decision | Staff or Admin | After requirements are Complete, approve or reject. A rejection needs a reason. The server blocks a final decision while requirements are incomplete. | Approved or Rejected |
| 4. Review generated draft | Staff or Admin | Approval automatically saves the certificate draft and PDF preview. Review the saved resident details, wording, fee and Chairman signature. The draft remains unissued. | Approved |
| 5. Release certificate | Staff or Admin | Release the saved draft. The issuance and any payment-ledger entry are recorded at release; the same certificate reference and saved content are retained. | Released |
| 5. Track and retain | Resident and authorized office users | The resident checks the reference tracker. Authorized users can inspect request history and audit records. | Current status and history |

**Important:** the system has internal processing states for staff (such as Review and Ready), while the resident-facing SRS statuses are Pending, Approved, Rejected and Released. A resident should use the public status, not infer the internal staff step.

### Resident steps for a document

1. Tap **Request a document**.
2. Choose the document type.
3. Enter the purpose clearly.
4. Confirm the email-only notification option.
5. Submit once. Wait for the receipt; do not submit repeatedly if the page takes time.
6. Keep the reference number and Documents queue ticket.
7. Use **Check request status** to see the latest public status. If the office needs more information, follow the instruction given by Staff.

### Staff steps for a document request

1. Open **Document Requests** and find the reference number.
2. Secretary/Admin: open the request, inspect the document type, purpose and attachments, then record the requirements review.
3. Staff/Admin: open the request after requirements are Complete. Approve it or reject it with a clear reason. Approval automatically creates a saved certificate draft; approval is rolled back if required generation data or a required Chairman signature is unavailable.
4. Staff/Admin: open Certificate Generation, review the saved draft and its PDF preview. The preview is marked DRAFT — NOT ISSUED. Secretary and Chairman cannot open this draft PDF.
5. Staff/Admin: release the saved draft when ready. Only release creates the issuance and any cash-ledger entry; an O.R. number is recorded at this stage for paid documents. The certificate reference, wording, fee and Chairman signature snapshot remain the ones saved at approval.
6. Confirm the Released status, issuance record and final PDF, then give the resident the released document.

The app's server-side permission checks control each handoff. Do not use another person's account to perform a step.

## 3. Three service queues

BIRMS has three separate queues: **Blotter**, **Documents** and **Venue**. Each service has its own daily numbering sequence.

- Residents can get a ticket at the kiosk or request a number from Staff at the counter.
- Staff opens **Service Queue**, selects the service and uses **Call next**.
- When the resident is served, mark the ticket **Served**. If the resident is not present, use **Skip**. Use **Cancel** only when the ticket should no longer be served.
- Residents watch the public queue display. It shows the current number and waiting count, not resident names or personal details.

A queue ticket is not the same as approval of a document, blotter report or venue reservation. The request still follows its own review and decision process.

## 4. Blotter report lifecycle

1. Resident verifies identity at the kiosk and chooses **File a blotter report**.
2. Enter the incident date/time, place and factual description. Add respondent details only when known; do not guess.
3. Submit once and keep the blotter reference and queue ticket.
4. Staff/Admin reviews the submitted report. An accepted report becomes an official blotter case; a rejected submission remains in request history.
5. Authorized case handlers update the official case using the required statuses: **Ongoing**, **Unresolved**, **Resolved**, **Dismissed** or **Archived**. Status changes are recorded in history/audit records. Blotter cases are not deleted as a shortcut; resolved/dismissed records are retained and archived according to the SRS.
6. The resident can check the reference number for the public status. The public tracker must not reveal the resident's identity, address or incident narrative.

## 5. Venue reservation lifecycle

1. Resident verifies identity and chooses **Reserve a venue**.
2. Select the venue, date, start/end time and purpose. Email is the only notification channel.
3. Submit and keep the reservation reference and Venue queue ticket.
4. Staff/Admin reviews the reservation and approves or rejects it. A pending or approved reservation blocks overlapping requests for the same venue/time. The system rechecks conflicts when approving.
5. The venue board shows **Available** or **In Use**. The Secretary can change room availability for blotter/hearing arrangements; Staff/Admin handle reservation decisions.
6. An approved reservation can be marked Completed after use. Residents can check the reference status.

## 6. Staff workspace: where to go

| Task | Menu |
|---|---|
| Find a resident by name, Resident ID, address or email; filter by direct Purok, status or type | **Residents** |
| Review document requests and requirements | **Document Requests** |
| Monitor Documents, Blotter and Venue requests together (Admin/Staff) | **All Services Tracker** |
| Call, serve, skip or cancel queue tickets | **Service Queue** |
| Review resident-submitted blotter reports | **Resident Blotter Requests** |
| Manage official blotter cases and hearings | **Blotter** / **Hearings** |
| View venue availability and reservations | **Venue Reservations** |
| Generate/release approved certificates | **Certificate Generation** |
| Review completed issuance records | **Issuance History** |
| View required summaries and export reports | **Reports** |

Resident records are grouped directly by **Purok** and each resident has their own **Address**. Do not use household codes or household grouping as the resident directory workflow.

## 7. Current role access in the code

These are the permissions currently enforced by the branch, not a substitute for the final signed role matrix.

| SRS role | Current access summary |
|---|---|
| **Admin** | Full defined access: accounts, settings, resident management, requests/review/decisions, certificates, blotter, venues, queues, reports, audit and backup. |
| **Chairman** | Read-only access to permitted resident/request/case/venue/queue/report and history views. Cannot perform operational edits, approvals, queue actions or certificate issuance. The stored Chairman signature is applied by the system to eligible certificates; the Chairman does not need an operational login action to sign each request. |
| **Secretary** | View resident records; intake/edit document requests and complete requirements review; work on permitted blotter/hearing records; change venue-room availability for blotter/hearing arrangements; view/export reports. Cannot make the final document decision, issue certificates or manage service queues under the current permission map. |
| **Staff** | View resident records; intake/edit requests and make final decisions after requirements review; issue/print certificates; review resident blotter submissions; manage venue reservations and service queues; use the Admin/Staff All Services Tracker. Cannot manage accounts or system settings. |
| **Resident** | Resident-only kiosk and shared QR services: document requests, blotter submissions, venue reservations, queue tickets and reference-status checks. No access to the staff/admin portal. |

**Role-policy item to confirm before release:** the current permission map grants resident create/edit/archive functions to Admin, while Secretary and Staff have resident-directory view access. Confirm that this exactly matches the approved SRS and barangay operating policy before changing permissions. Do not broaden access simply to make a menu item appear.

## 8. Resident record and report guidance

- Use the resident's individual **Address** and direct **Purok** fields.
- The resident profile includes Resident ID, contact details, birthdate, sex, marital status, resident status and resident type.
- Required resident statuses are **Active**, **Transferred**, **Deceased** and **Non-resident**.
- Default resident types include **PWD**, **Senior Citizen**, **Solo Parent**, **4Ps Beneficiary**, **Indigent** and **None**.
- Reports include resident/Purok/type, document requests, blotter status, venue use and queue statistics. Check the selected period and totals before exporting.
- Chairman and Secretary have report-view/export access in the current permission map.

## 9. Admin setup and release checks

Before real use, Admin/system owner must configure and verify:
- Puroks, resident reference lists and active document types.
- Venue list, operating rules and authorized staff.
- Chairman signature image and approved certificate wording/layout.
- Official fees and receipt/OR procedures, where applicable.
- Email sender and server mail transport. Test delivery with an authorized test account.
- User accounts and the approved role-permission matrix.
- Backups and a safe restore procedure.

The repository currently documents local SQL backup and optional remote WebDAV upload, but does **not** establish a verified restore or a scheduled backup service. Test any restore only into a separate scratch database, and include private attachments and the Chairman signature file in the recovery test. Never restore over the source database during testing.

## 10. If something is blocked

- **Identity cannot be verified:** check Resident ID/birthdate; if using the name fallback, check first/last name and birthdate. If no registered contact is available or records are ambiguous, ask Staff at the counter.
- **No document type appears:** ask Admin to check whether the document type is active.
- **A request cannot be approved/rejected:** Secretary/Admin must complete the requirements review first.
- **A venue cannot be booked:** choose another time; a Pending or Approved booking may already reserve that slot.
- **A queue ticket is not called:** watch the public display and ask Staff; do not create repeated tickets.
- **A menu or action is unavailable:** the current account may not have that permission. Ask Admin; never share credentials or bypass access checks.
- **Notification says queued/pending:** queued is not the same as delivered. Confirm email delivery through the configured mail transport.
- **Reference number is not found:** recheck the printed number and ask Staff if it still cannot be found.

## 11. Test and deployment boundary

Use only fictitious demo records on a disposable `*_work` or `*_test` database for development and presentation testing. Do not test against the original or production barangay database.

Passing GitHub Actions validates automated checks against disposable CI databases. It does not prove that the target XAMPP PC, Apache PHP runtime, barangay LAN, phone QR scan, physical kiosk/touchscreen, printer, real email delivery, official signature/template, backup restoration or office user acceptance has passed. Record those manual results before declaring the system production-ready or MSIT-compliant.
