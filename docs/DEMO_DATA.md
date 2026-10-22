# Demo data for presentations

The demo seeder creates a small, clearly labelled, fictitious dataset so the BIRMS dashboard, residents list, request tracker, certificate history, visitors, blotter and reports are not empty during a presentation.

## Safety first

- Run the commands only against the dedicated `barangay_db_work` or another disposable database whose name ends in `_work` or `_test`. The seeder refuses any other database name, including the original `barangay_db`.
- It is a **CLI-only** tool; there is no public web route or button that can seed data.
- The first command is read-only. Data is inserted only when you explicitly pass `--apply`.
- The seed data has clear `DEMO` identifiers. Demo certificate types and saved certificate bodies say **DEMO ONLY - NOT AN OFFICIAL CERTIFICATE**.
- It does not edit official document types, their wording, official fees, barangay settings, staff accounts or passwords. Demo document types have a `0.00` demonstration fee, but the seeder creates **no** cash-collection ledger entries, O.R. numbers, real phone numbers or SMS messages.
- Existing active Purok/category/employment/visitor/incident lookup values are reused. A `DEMO ONLY - ...` lookup value is added only when that lookup list has no active items; those unused demo-only values are removed by the cleanup command.
- The tool uses a database transaction, is safe to run again without duplicates, and writes audit events. Audit history is intentionally retained when demo records are removed.

## Commands on XAMPP

Open Command Prompt or XAMPP Shell and change into the project folder:

```bat
cd C:\xampp\htdocs\birms_work
C:\xampp\php\php.exe tools\seed_demo_data.php
```

That shows the database name and a read-only plan. If the plan points to `barangay_db_work`, add the sample dataset:

```bat
C:\xampp\php\php.exe tools\seed_demo_data.php --apply
```

Then sign in to BIRMS and review the Residents, Requests, Certificate History, Visitor Logbook, Blotter, Dashboard and Reports pages.

To remove the reserved seed dataset after making a backup (or when you want a clean demo database):

```bat
C:\xampp\php\php.exe tools\seed_demo_data.php --remove --confirm-demo-only
```

Both flags are required for removal. If the seed rows have been linked to other records (for example, you attached a real uploaded file or created another request using a demo resident), the seeder refuses removal instead of deleting that related work. Review the reported dependency before making a cleanup decision.

## Dataset

The seed package adds **8 households, 24 residents, 12 document requests across the supported statuses, 4 demo-only issuance-history records, 8 visitor entries, 4 fictitious blotter cases, and 1 future hearing**. It uses no actual resident information and no contact phone numbers. The certificate bodies visibly state that they are fictitious and not valid for official use.

This is test/presentation data, not a data migration from physical barangay records. For real records, use the Residents CSV import template, assess the source spreadsheet first, correct errors, preview the import, and confirm only after reviewing the results.
