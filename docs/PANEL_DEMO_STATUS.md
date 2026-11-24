# BIRMS — Revised MSIT Panel Demonstration Guide

**Current demonstration candidate:** `john/phase2-kiosk-no-sms`  
**Pull request:** [#39 — QR kiosk and email-only workflow](https://github.com/sachie34/Birms/pull/39)  
**Candidate ZIP:** [Download the candidate branch ZIP](https://github.com/sachie34/Birms/archive/refs/heads/john/phase2-kiosk-no-sms.zip)  
**Controlling requirements:** `BRMS_SRS_Revised.docx`

> **Important:** The candidate is automated-test verified, but it is not yet accepted on the target XAMPP PC or approved for production. The default `main` branch is the older B1–B12 application. Do not use the `main` ZIP for the revised MSIT demonstration.

## 1. What the current candidate demonstrates

BIRMS is a browser-based Barangay Information and Records Management System. The revised candidate provides a resident QR/kiosk entry point and separate service workflows for Documents, Blotter and Venue, supported by individual service queues, staff processing, reports, audit history and protected backup/restore.

Resident notifications are **email-only**. Active SMS provider adapters, SMS sending and SMS administration are not available in this candidate. Historical SMS tables/records remain only for compatibility and audit preservation.

## 2. Current role responsibilities

The interface uses the revised operational role names. Existing database role IDs `captain` and `clerk` are mapped internally to **Chairman** and **Staff** for compatibility with existing accounts.

| Role | What to demonstrate | Actions that must remain unavailable |
|---|---|---|
| **Admin** | Technical administration: user accounts/roles, system settings, reference data, signature image, audit, reports and backup; also has the authorized administrative access needed to support all modules | No ordinary resident-facing activity is required for the Admin demonstration |
| **Chairman / Punong Barangay** | Read-only review of permitted resident, request, issuance-history, blotter, hearing, venue, queue, report and cash-ledger records. The stored Chairman signature is applied automatically to eligible certificates. | Cannot edit operational records, approve/reject requests, manage queues or venues, issue/print certificates as an operational action, or change system settings |
| **Secretary** | View resident records; intake/edit document requests and review requirements; maintain permitted blotter/hearing records; edit certificate wording; change room/venue availability for blotter and hearing arrangements; view/export reports | Cannot make final document decisions, issue certificates, approve venue reservations, manage service queues, or administer accounts/general settings |
| **Staff** | Process document requests and make final approve/reject decisions after requirements are complete; review generated certificate drafts and release eligible certificates; review resident blotter submissions; manage venue reservations and service queues | Cannot administer users/roles, general system settings, or unrestricted audit/backup configuration |
| **Resident** | Use the shared QR/kiosk to request documents, submit/follow up blotter reports, request venues, obtain a queue ticket and track only their own requests | Cannot access staff/admin pages or another resident's private records |

A hidden button is not sufficient protection. For a role-denial demonstration, use a separate account and verify that a direct URL or forged POST is rejected server-side without changing data.

## 3. Resident kiosk and service workflows

### Shared QR and identity

1. The resident scans the shared QR or opens the kiosk. The QR is a shared entry point and must not contain resident personal information.
2. The resident chooses a service, then verifies identity using Resident ID and birthdate.
3. The seeded resident records use reserved `.invalid` email addresses. For the standard seeded demo, use Resident ID (`DEMO-RES-0001` through `DEMO-RES-0006`) and the birthdate shown on that resident's profile. The synthetic email addresses cannot receive OTP messages or resident notices.
4. The name-and-birthdate fallback can send a one-time code to a real resident's registered email address when a mail transport is configured and tested. Use it only with a controlled test mailbox during acceptance; otherwise use Resident ID/birthdate or ask Staff for counter assistance.
5. The kiosk session is short-lived and resets after inactivity. A later resident must not see the previous resident's identity, receipt, ticket or request details.
6. The public queue display must not expose resident-identifying information. Request tracking is restricted to the verified resident's own records.

### Documents and certificates

1. Resident submits a document request and receives a reference number and Documents queue ticket.
2. Secretary checks the requirements and records missing items or marks them Complete only after verification.
3. Staff makes the final approve/reject decision. A rejection requires a reason.
4. Approval creates a saved certificate draft and PDF preview. The draft remains **DRAFT — NOT ISSUED**; it is not an issued barangay certificate.
5. Staff reviews and releases the saved draft. Release creates issuance history and any applicable cash-ledger entry. The saved reference, wording, fee and signature snapshot are retained.
6. The resident checks the request status using the verified resident flow.

### Blotter

A resident submits a blotter/complaint report through the kiosk. Staff reviews the submission; acceptance creates the official blotter case. Permitted staff maintain the case and hearing information through the configured status history. Official blotter records are retained; deletion is not used as a shortcut.

### Venue and hearing-room availability

Residents can request a venue through the kiosk. Staff handles reservation decisions and the system rejects overlapping bookings for the same venue/time. The Secretary can set the venue/room availability used for blotter and hearing arrangements. The Chairman can view but cannot alter the records.

### Service queues

Documents, Blotter and Venue have separate queues. Staff can call the next ticket and process the queue status. The public display shows the service and ticket number, not the resident's personal details.

## 4. Suggested panel demonstration sequence

For a timed rehearsal with role-by-role cues and recovery steps, use the [7–8 minute Panel Demo Run Sheet](PANEL_DEMO_RUNSHEET.md).

Use separate browser profiles for Admin, Secretary, Staff and Chairman. Use a separate resident browser/device for the kiosk.

1. **Admin:** sign in and show the dashboard, user/role administration, system settings, audit and technical controls. Explain that the Admin is the technical administrator.
2. **Resident:** open the shared kiosk QR; verify a synthetic resident; submit a demo document request; show the blotter and venue options; obtain a queue ticket; open request tracking.
3. **Secretary:** show the incoming document request, review/complete its requirements, demonstrate certificate-wording editing, then show the room-availability control. Do not demonstrate final approval or certificate issuance from this account.
4. **Staff:** make the final document decision; open the automatically saved draft PDF and show its DRAFT — NOT ISSUED marking; release the test certificate; review a blotter submission; manage a venue reservation and call a queue ticket.
5. **Chairman:** show read-only records and reports. Attempting an operational action must be denied. Explain that the stored signature image is applied by the system to eligible certificate output.
6. **Close:** show request status, issuance/history, audit and reports. State clearly which tests are automated and which target-PC checks are still awaiting evidence.

## 5. Local XAMPP preparation

Use the detailed [MSIT XAMPP and Role Acceptance Runbook](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md) for the full install and test procedure.

Before replacing the local project, back up the whole existing folder and export the working database. Extract this candidate into a clean folder rather than over the older project so removed files cannot remain behind. Restore only the necessary private local configuration and storage files from the backup. Confirm the private configuration points to `barangay_db_work` (or a separate `*_test` database), never the original `barangay_db`.

The sample-data seeder creates fictitious DEMO ONLY residents, document templates/requests, blotter cases, venues and queue tickets. It does not create users or default passwords. Create the four staff-role accounts separately and use separate browser profiles.

Example local URLs, after a successful XAMPP installation:

- Staff/admin sign-in: `http://localhost/Birms-main/public/index.php`
- Shared resident kiosk: `http://localhost/Birms-main/public/kiosk/index.php`
- Public queue display: `http://localhost/Birms-main/public/queue/display.php`

These localhost URLs work only on the server PC. A phone must use the server's LAN-reachable HTTPS address and must be tested on the actual barangay network.

## 6. Automated verification evidence

The candidate application code commit `6ab22365912262e3714f6504e8d93c274ff48da6` passed [GitHub Actions run #1269](https://github.com/sachie34/Birms/actions/runs/45368674668). All eight combinations of PHP 8.3/8.4/8.5/8.6 and MariaDB 10.4/10.11 succeeded. Each job reported:

- **48/48** security/authentication/audit checks passed;
- **283/283** module integration checks passed;
- **0 failures** and **0 not-tested checks**;
- PHP syntax/include checks, XAMPP preflight, migrations, schema verification and isolated demo-data seed/reseed checks completed.

The documentation follow-up at commit `ae6864961f0bdaca31c5eabb33465db46bb81c03` also passed exact-head [workflow run #1277](https://github.com/sachie34/Birms/actions/runs/45373724774) across all eight matrix jobs with the same results. This is hosted CI evidence; it does not test the actual school/office PC.

## 7. Items that must not be claimed as passed without local evidence

The following remain **NOT RUN** until someone performs and records them on the target equipment:

- The installed Windows/XAMPP versions, including the PHP version loaded by both CLI and Apache.
- A real QR scan from a phone connected to the barangay LAN.
- Physical touchscreen/kiosk operation and inactivity reset.
- Actual email delivery to a controlled mailbox.
- Certificate PDF layout and printing on the intended office printer.
- Full-system backup and restore into a separate scratch application/database/storage location.
- Performance with representative data, concurrent staff use, and LAN/power-outage recovery.
- Approval of official certificate/summons wording, layout, fee schedule, signature policy and agency report templates.

The system does not provide SMS sending, online payment processing, a COMELEC API lookup or scheduled backup jobs in this candidate. Do not present those as completed features.

**Acceptance rule:** the system is ready for a panel demonstration only after the candidate is installed on the actual demo PC, the core role and resident journeys are observed there, and the presentation uses synthetic records. Production readiness requires the additional security, policy, network, backup/restore and office sign-off gates in the acceptance and deployment runbooks.
