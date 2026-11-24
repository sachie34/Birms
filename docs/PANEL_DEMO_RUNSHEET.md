# BIRMS — Panel Demonstration Run Sheet

**Use this with:** [Panel Demo Status](PANEL_DEMO_STATUS.md) and the [MSIT XAMPP Acceptance Runbook](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md)  
**Build:** main at commit `9c01fe27bc76e488c78ec94300bba3a85c245deb` (post-merge CI #1355 passed)  
**Suggested duration:** 7–8 minutes  
**Data rule:** Use only fictitious DEMO ONLY records. Never show real resident information.

## Before the panel enters

- [ ] Start Apache and MySQL in XAMPP; open the BIRMS sign-in page.
- [ ] Confirm the database is barangay_db_work or another isolated _test database. Never use barangay_db.
- [ ] Open separate browser profiles for Admin, Secretary, Staff and Chairman. Keep the resident kiosk in a separate browser/device.
- [ ] Confirm the demo data is present: six DEMO-RES residents, three document templates, four sample requests, three blotter cases, two venues/reservations and three service queues.
- [ ] Keep the Staff, Secretary and Chairman accounts logged in and ready. The seeder creates no accounts or passwords.
- [ ] Open the public queue display in a clean window. Confirm it displays ticket numbers only.
- [ ] Keep the panel guide and this run sheet available offline.
- [ ] Do not rely on email OTP or SMS: the seeded email addresses end in .invalid, and this build has no SMS sending.
- [ ] Use the on-screen kiosk URL unless a real phone/LAN QR scan has already passed on the actual network.

## Live sequence

| Time | Screen / role | Show | Say |
|---|---|---|---|
| 0:00–0:40 | Opening | BIRMS home/sign-in | “BIRMS brings resident records and three barangay services into one role-based workflow: Documents, Blotter and Venue.” |
| 0:40–1:20 | Admin | Dashboard, user/role controls, settings, audit | “Admin is the technical administrator. Account and system settings are separated from operational service processing.” |
| 1:20–2:25 | Resident kiosk | Shared QR entry, resident identity, service choices, queue ticket and tracker | “Residents choose a service, verify with Resident ID and birthdate, and receive a reference. Tracking is limited to their own records.” |
| 2:25–3:25 | Secretary | Pending document request, requirements review, certificate-template wording, hearing-summons wording, venue availability | “The Secretary checks requirements, maintains approved document wording and hearing summons, and controls room availability. Final document and venue decisions remain with Staff.” |
| 3:25–5:15 | Staff | Final approve/reject action, saved certificate draft/PDF preview, release, blotter review, venue reservation, queue controls | “Staff performs the final operational processing. A draft is not an issued certificate; release creates the issuance history.” |
| 5:15–6:00 | Chairman | Read-only records, reports and cash-ledger view | “The Chairman has oversight access. Operational changes remain with the assigned staff roles.” |
| 6:00–7:10 | Queue / history | Documents, Blotter and Venue queues; issuance history; status/audit trail | “Each service has its own queue, while the reference and history make the request traceable.” |
| 7:10–8:00 | Closing | Return to dashboard or panel guide | “The hosted automated suite passes. Local XAMPP, phone/LAN, email delivery, printer and restore checks are reported separately and only marked passed after real testing.” |

## Recommended seeded examples

- **Kiosk identity:** use DEMO-RES-0001 through DEMO-RES-0006. Read the date of birth from the matching seeded resident profile; the safe seeder may reuse an older synthetic profile, so do not assume one universal birthdate.
- **Document request states:** DEMO-DOC-PENDING-01, DEMO-DOC-READY-01, DEMO-DOC-REJECT-01, and DEMO-DOC-ISSUED-01.
- **Saved draft:** DEMO-DOC-READY-01 has a saved unissued draft for Staff review.
- **Sample venue reservations:** DEMO-VEN-0001 and DEMO-VEN-0002.
- **Demo templates:** Barangay Clearance, Certificate of Residency and Certificate of Indigency. Each is clearly labelled DEMO ONLY — NOT AN OFFICIAL DOCUMENT, has a zero demonstration fee and does not require the Chairman's signature.

## Recovery plan if something goes wrong

1. **Kiosk identity does not work:** use another seeded Resident ID and copy the date of birth from that resident's profile. Do not use the .invalid email OTP path.
2. **The phone QR does not load:** use the kiosk URL on the server PC and explain that phone/LAN access is a separate network acceptance item. Do not waste panel time debugging Wi-Fi or certificates.
3. **A sample request has already been processed:** use another seeded example with the needed status. Do not reseed into a partially modified dataset; the seeder intentionally refuses partial reserved demo data.
4. **PDF preview or printer has trouble:** show the protected browser preview only if it renders correctly. Do not claim the physical printer is verified.
5. **A role button is unavailable:** confirm the active role and use the correct Staff/Secretary/Chairman account. Do not borrow an administrator session to make an operational action look successful.
6. **A page errors:** capture the URL and visible error, return to the prepared dashboard, and continue with another verified sample. Do not edit the live database during the panel.

## Claims to avoid

- Do not say SMS is supported; this candidate is email-only.
- Do not say a DEMO ONLY PDF is an official barangay certificate.
- Do not say the system was tested on the target XAMPP PC, phone, kiosk hardware or printer unless that test was actually performed.
- Do not claim online payments, a COMELEC API integration or scheduled automatic backups.
- Do not call the system production-ready based on CI alone.

## After the demonstration

Preserve the database and screenshots/logs used as evidence. Keep demo records isolated from real barangay data. Before any real deployment, complete the full role, network, email, backup/restore, performance, print-layout and policy sign-off tests in the acceptance and production runbooks.
