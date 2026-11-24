# MSIT BIRMS demonstration data

Use the MSIT-specific seeder for the revised SRS candidate [`john/phase2-kiosk-no-sms`](https://github.com/sachie34/Birms/archive/refs/heads/john/phase2-kiosk-no-sms.zip). The older B1–B12 seeder is not the right preparation path for this build.

## Safety

- CLI only; there is no public route that can seed records.
- Refuses to run unless the configured database name ends in _work or _test.
- Never point it at the original barangay_db or a production database.
- Uses fictitious resident data and .invalid email addresses. It does not create phone numbers or send email. Historical SMS tables are not populated.
- Does not create accounts or default passwords.
- Does not change official document types, official wording, fees, barangay settings, existing users or passwords.
- Reuses only old synthetic residents whose last name starts with DEMO- and whose resident number is blank. It assigns a DEMO resident number and demo-only address/contact settings. It never adopts a normal resident record.
- Existing document types are only updated when their exact name begins DEMO ONLY and matches one of the three reserved demo certificate types.
- A database transaction is used. The script is idempotent; a complete seed is detected and not duplicated. A partial reserved seed is refused for manual inspection.

## Run on XAMPP

Open Command Prompt and change into the MSIT BIRMS project folder:

~~~bat
cd /d C:\xampp\htdocs\Birms-main
C:\xampp\php\php.exe tools\seed_msit_demo_data.php
~~~

The first command is a read-only plan. Confirm it reports the isolated working database, normally barangay_db_work. Then apply:

~~~bat
C:\xampp\php\php.exe tools\seed_msit_demo_data.php --apply
~~~

Sign in using an account created by the administrator. The seeder intentionally does not create shared/default accounts. The six synthetic Resident IDs are `DEMO-RES-0001` through `DEMO-RES-0006`; use the birthdate shown on each seeded profile for the kiosk identity step. Their email addresses use `.invalid`, so they cannot receive OTP messages or resident notices. Do not expect live email delivery from the synthetic records.

## What is included

- 6 fictitious resident records with DEMO-RES identifiers;
- 3 DEMO ONLY certificate templates: Barangay Clearance, Certificate of Residency and Certificate of Indigency;
- 4 document requests covering Pending, Approved (with a saved unissued certificate draft), Rejected and Released (with one issuance-history record);
- 3 fictitious blotter cases;
- 2 sample venues and 2 reservations;
- 3 queue tickets: Documents, Blotter and Venue.

Every sample document prominently says DEMO ONLY — NOT AN OFFICIAL DOCUMENT. Demo templates use a zero demonstration fee and do not require the Chairman's signature image. This is not an approved fee schedule or official certificate wording.

## Edit templates

Open System Settings → Documents and fees as Admin or Secretary. Secretary can edit document wording, while fees, template availability, signature requirements and all other system settings remain Admin-only. The document editor supports bold, italic, underline, headings, bullet/numbered lists, dividers, resident/request placeholders and a live preview. Saved formatting is used by the certificate print and PDF views. The editor intentionally provides a focused document-template workflow, not a complete replacement for Microsoft Word.

An automatically generated certificate draft is a snapshot. Editing a template later does not rewrite a saved draft or an already issued certificate.

## Demo readiness checks

Before presenting, verify the following in the browser on the actual XAMPP machine:

1. Dashboard shows sample residents, requests, queues and cases.
2. Document Requests shows Pending, Approved, Rejected and Released examples.
3. Staff can preview the saved draft, then release it; the sample has no fee and no Chairman signature requirement.
4. Issuance History shows the clearly marked demo issue.
5. Blotter displays the three fictitious cases.
6. Venue Reservations displays the two sample reservations and availability.
7. The three service queues display their demo tickets.
8. The print/PDF output visibly retains the DEMO ONLY warning.

CI does not replace local XAMPP, browser, printer or role acceptance. Never present a demo-only document as a valid barangay certificate.
