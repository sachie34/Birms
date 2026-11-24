# MSIT BIRMS demonstration data

Use the MSIT-specific seeders included in the combined revised-SRS candidate on [`release/msit-demo-ready`](https://github.com/sachie34/Birms/archive/refs/heads/release/msit-demo-ready.zip) (draft PR #48). The older B1–B12 household/visitor seeder is not the right preparation path for this build.

## Safety

- CLI only; there is no public route that can seed records.
- Refuses to run unless the configured database name ends in _work or _test.
- Never point it at the original barangay_db or a production database.
- Uses fictitious resident data and .invalid email addresses. It does not create phone numbers or send email. Historical SMS tables are not populated.
- Does not create accounts or default passwords.
- Does not change official document types, approved fees, existing users or passwords. It fills the Captain name, Secretary name and summons wording only when those specific settings are blank; configured values are preserved.
- Reuses only old synthetic residents whose last name starts with DEMO- and whose resident number is blank. It assigns a DEMO resident number and demo-only address/contact settings. It never adopts a normal resident record.
- Existing document types are only updated when their exact name begins DEMO ONLY and matches one of the three reserved demo certificate types.
- A database transaction is used. The script is idempotent; a complete seed is detected and not duplicated. A partial reserved seed is refused for manual inspection.

## Role workflow represented by the seed

- **Secretary:** owns the wording for certificate templates and the hearing summons, checks document requirements in the live workflow, maintains permitted blotter/hearing records, and controls room availability. The demo certificate template includes a conspicuous “Prepared by” label and a Captain name field.
- **Staff:** makes final document decisions after requirements are complete, releases the sample draft, reviews resident blotter submissions, handles venue reservations and calls queue numbers aloud.
- **Chairman / Captain:** is read-only under the revised SRS. The Chairman does not create, edit, approve or issue documents in the application. The Captain name is represented as the signatory field on watermarked examples; the seed does not invent or upload a real signature image.
- **Admin:** manages technical settings, accounts, and the stored signature asset. The seed deliberately creates no shared/default accounts or passwords.

The Secretary-maintained summons wording and hearing row are marked DEMO ONLY and have no legal effect. The seeder fills Captain/Secretary name and summons settings only when those values are blank; it never overwrites names or wording already configured by the Barangay.

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

Sign in using an account created by the administrator. The seeder intentionally does not create shared/default accounts. The six base synthetic Resident IDs are `DEMO-RES-0001` through `DEMO-RES-0006`; use the birthdate shown on each seeded profile for the kiosk identity step. Their email addresses use `.invalid`, so they cannot receive OTP messages or resident notices. Do not expect live email delivery from the synthetic records. The optional extended seed adds 12 more residents with IDs beginning `DEMO-RES-EXT-`, for 18 synthetic residents total. The base verifier deliberately excludes those 12 and checks only the six base records; the extended verifier checks the 12 additional records separately.

## What is included

- 6 fictitious resident records with DEMO-RES identifiers;
- 3 DEMO ONLY certificate templates: Barangay Clearance, Certificate of Residency and Certificate of Indigency;
- 4 document requests covering Pending, Approved (with a saved unissued certificate draft), Rejected and Released (with one issuance-history record);
- 3 fictitious official blotter cases;
- 3 resident-submitted blotter intake examples: Pending, Accepted (linked to the official demo case) and Rejected;
- 1 upcoming hearing linked to an open demo case, plus DEMO ONLY summons wording with Captain and Secretary placeholders;
- 2 sample venues and 2 reservations;
- 3 queue tickets: Documents, Blotter and Venue. Each ticket updates the matching daily counter so Staff can issue the next number without duplicate-key errors.

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


## Extended presentation data (optional)

After the base MSIT demo seeder and its verifier have passed, the optional extended seeder adds 12 residents plus examples for directory filters, private file handling, historical queues, archived blotter cases and audit/history views. The combined candidate's CI runs base seed → base verifier → extended seed → extended verifier → base verifier again to confirm the two seeders remain compatible and idempotent.

Run the read-only plan first:

~~~bat
C:\xampp837\php\php.exe tools\seed_msit_extended_demo_data.php
~~~

Confirm the target is barangay_db_work (or another isolated *_work / *_test database). Then apply the extended dataset:

~~~bat
C:\xampp837\php\php.exe tools\seed_msit_extended_demo_data.php --apply
C:\xampp837\php\php.exe tests\verify_msit_extended_demo_seed.php
~~~

The extended seed adds:

- **12 synthetic residents** with individual addresses and Purok assignments; collectively they cover all four SRS resident statuses and all six SRS resident types. Every email uses example.invalid, no mobile number is set, and each resident receives the same generic silhouette placeholder stored privately under storage/resident_photos/.
- **2 archived blotter cases**, one resolved and one dismissed, with complainant/respondent party rows and explicit status-history records. Both remain in Archived; no case is deleted.
- **2 further venue outcomes:** one Rejected and one Completed, with their status history.
- **4 historical queue tickets** across the three services, covering Called, Served, Skipped and Cancelled with example timestamps. They use the previous day's queue date so they do not clutter today's waiting display.
- **2 private PDF request attachments**, each visibly marked DEMO ONLY and linked to the existing pending/approved sample document requests. Their size, MIME type and SHA-256 are verified.
- **4 audit examples** attributed to DEMO DATA, plus the actual seed-operation audit event. These records do not pretend that a real Secretary, Staff member or Chairman performed the seeded actions.
- **11 request-status history rows** to provide additional Documents, Venue and Blotter examples.

The extended seeder is additive, CLI-only, restricted to *_work / *_test, uses a database lock and transaction, refuses partial marker sets, and is idempotent. Private files are written without overwriting an existing different file. On database failure, files newly created by that run are removed.

### Items deliberately not fabricated

- The revised SRS removes the Visitor Logbook and replaces it with the three service queues. No visitor-logbook entries are added.
- The seeder does not create user accounts or passwords. Use Admin → User Accounts to create separate Chairman, Secretary and Staff accounts. This follows the application's account-creation and password policy rather than adding shared demo credentials.
- No cash-ledger transaction, O.R. number, payment method, collection amount or paid certificate is invented. The seeded certificate history remains zero-fee demonstration data. To demonstrate a real ledger entry, the barangay must approve a fee and payment/receipt procedure first; do not invent these values for a panel presentation.
- No Chairman signature image is created or substituted. Upload only the authorized signature image after approval.
- No real contact information or email delivery is used. The placeholder email domains cannot receive messages.

The verifier is read-only. A successful automated verification does not replace opening the resident profile/photo, request attachment, audit history, archived blotter, reservation, queue history and reports in the local XAMPP browser.
