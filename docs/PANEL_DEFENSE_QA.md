# BIRMS — Defense Questions and Suggested Answers

Use these answers as a guide, not as a script to memorize. Keep the explanation direct and describe only what the current MSIT candidate actually implements.

## Project purpose and design

### 1. What is BIRMS?

BIRMS is a browser-based Barangay Information and Records Management System. It organizes resident records and three service workflows—Documents, Blotter and Venue—through a shared resident kiosk, separate service queues, role-based staff processing and traceable records.

### 2. What problem does it address?

It reduces dependence on separate paper logbooks and disconnected forms. Staff can locate resident records, follow requests by reference number, process services according to assigned roles and review the history of changes.

### 3. Why did you use a QR kiosk?

The shared QR gives residents one starting point for the services. After opening the kiosk, the resident selects a service and verifies identity. The QR itself is not tied to a resident and must not contain personal information.

### 4. Why is the resident directory grouped by Purok?

The revised MSIT requirements use Purok as the resident grouping. Each resident also has an address field. This keeps the directory aligned with the revised SRS instead of relying on the older household-based design.

### 5. Why are there three queues?

Documents, Blotter and Venue have different processing steps and staff responsibilities. Separate queues make it easier to call and process the correct service without mixing unrelated requests.

## Roles and workflow

### 6. What does each account do?

- **Admin:** technical administrator for accounts, roles, settings, reference data, audit, reports and system backup.
- **Chairman:** read-only oversight of permitted records and reports.
- **Secretary:** intake and requirements review, permitted blotter/hearing maintenance, certificate wording edits and venue/room availability.
- **Staff:** final document decisions and release, resident blotter review, venue reservation processing and queue management.
- **Resident:** uses the kiosk to request services, receive a queue ticket and track only their own requests.

### 7. Why is the Chairman read-only?

The revised role design separates oversight from operational processing. The Chairman can review records and reports, while authorized staff perform day-to-day changes. The stored Chairman signature can be applied to eligible certificate output according to the configured rules.

### 8. Can the Secretary approve a certificate request?

No. The Secretary checks whether requirements are complete and prepares the request. Staff makes the final approve/reject decision. This separates intake from final processing.

### 9. What happens if the requirements are incomplete?

The Secretary records the missing requirements or keeps the request incomplete. The system must not allow Staff to complete the final approval until the requirements status is complete.

### 10. What happens after a request is approved?

The system saves a certificate draft and a PDF preview. The saved draft is marked **DRAFT — NOT ISSUED**. Staff reviews and releases it. Release creates the issuance record and any applicable cash-ledger entry.

### 11. How does the system prevent overlapping venue reservations?

The reservation workflow checks the venue and time range before saving and again during approval. Database transaction locking protects the decision from two users processing conflicting reservations at the same time.

### 12. How does a blotter report become an official case?

The resident first submits a report. Staff reviews it. Accepting the report creates the official blotter case. The case and hearing/status history are then maintained by the permitted staff roles.

## Security, data and technology

### 13. How do you protect resident information?

The system checks permissions on the server, protects state-changing requests, limits repeated kiosk identity attempts, uses short-lived kiosk sessions and restricts request tracking to the verified resident's own records. The public queue display shows ticket numbers rather than resident names.

### 14. What happens when another resident uses the kiosk?

The kiosk session expires after inactivity and is cleared when a new resident starts. The next resident should not see the previous resident's identity, receipt, ticket or private request status.

### 15. What happens if two staff members call the next ticket at the same time?

The queue number allocation is protected by a transaction and a locked counter row. This prevents duplicate daily queue numbers when simultaneous requests are processed.

### 16. What technology did you use?

The current implementation uses PHP, MySQL/MariaDB, JavaScript, Bootstrap 5, Apache and XAMPP. PHP handles server-side requests and permissions, MariaDB stores the records, and the browser interface provides the staff portal and resident kiosk.

### 17. How did you test the system?

The latest completed hosted CI run passed eight PHP/MariaDB combinations: PHP 8.3, 8.4, 8.5 and 8.6 against MariaDB 10.4 and 10.11. Each job reported 48/48 security/authentication/audit checks and 283/283 module checks, with zero failures and zero not-tested checks. The CI pipeline also checked setup preflight, migrations, schema and demo-data seed/reseed behavior.

Do not describe hosted CI as a completed test on the target XAMPP computer. Local browser, phone/LAN QR, email, printer and restore tests must be observed separately.

## Scope and limitations

### 18. Does the current candidate send SMS?

No. The current candidate uses email-only notification workflows. SMS sending and SMS administration were removed from this revised demonstration build. Do not promise SMS notifications.

### 19. Can residents pay online?

Online payment processing is not included in this candidate. The system can record the applicable document fee and maintain the cash-collection ledger workflow, but this is not an online payment gateway.

### 20. Does the system connect to COMELEC?

No COMELEC API integration is included. Do not claim automated COMELEC verification or lookup.

### 21. Are the sample certificates official?

No. The seeded templates are marked **DEMO ONLY — NOT AN OFFICIAL DOCUMENT**, use a zero demonstration fee and are intended to show the document workflow and layout. Official wording, fee schedules, signatory details and final certificate layout require approval by the barangay.

### 22. Does the system automatically back up every day?

The candidate includes protected backup and restore tools, but scheduled automatic backups are not implemented. A full-system restore rehearsal must be done separately in a scratch database and application folder before production use.

### 23. Is the system production-ready?

The candidate has passed the hosted automated test matrix, but production readiness requires more than CI. The actual XAMPP installation, role journeys, LAN/kiosk operation, real email delivery, printer output, backup/restore, performance and official barangay policies must be accepted on the target environment first.

## A good answer when you do not know

Say: “That item is not part of the current verified scope. I do not want to claim that it works without evidence. I can identify it as a limitation or a future improvement and explain how it would be tested.”

This is better than promising an unimplemented feature or inventing a test result.
