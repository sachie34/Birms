# Resident CSV import

## Before importing

1. Back up the working BIRMS database using the approved office process. Do not experiment on the original **barangay_db**.
2. Go to **Residents → CSV template** and download **birms_resident_import_template.csv**. Do not use the normal Residents **Export CSV** file as an import template: export includes derived columns and uses display labels.
3. Open the template in Excel or another spreadsheet program, enter one resident per row, and save as **CSV UTF-8**. Keep the column names and order unchanged. A date must be a real date written as **YYYY-MM-DD** (for example, **2004-12-29**).

## Template columns

The template uses the database-backed names in this exact order:

**last_name, first_name, middle_name, suffix, birthdate, sex, civil_status, contact_number, household_code, purok, category, employment_status, voter_status, residency_status, is_household_head**

- Required: **last_name**, **first_name**, **birthdate**, **sex**, and **civil_status**.
- **sex** accepts **Male** or **Female**. **civil_status** accepts **Single**, **Married**, **Widowed**, **Separated**, or **Divorced**.
- **household_code** must match a household that already exists in BIRMS. Leave it blank only when the resident is not linked to a household.
- When **household_code** is supplied, **purok** is optional; when supplied it must match that household's configured Purok. A row cannot create or edit a household.
- **category** and **employment_status** are optional configured labels, not numeric IDs. If used, the value must match an active item under Settings.
- **voter_status** accepts **Unverified**, **Registered**, or **Not registered**. Blank defaults to **Unverified**.
- **residency_status** accepts **Active** or **Relocated**. Blank defaults to **Active**.
- **is_household_head** accepts **1/yes** or **0/no**. Blank is treated as **0**. A household with an existing recorded head cannot be assigned a second head by import.

## Import through the BIRMS interface

1. Open **Residents → Import CSV** and choose the saved .csv file.
2. Click **Preview and validate**. The preview reports each data row as **Ready**, **Skipped**, or **Rejected** and shows the reasons before saving.
3. Download **validation details** when rows are skipped/rejected. Fix those rows in a new copy of the CSV if needed, then preview again.
4. Click **Import ready rows** only after reviewing the preview. Ready rows are inserted; duplicates are skipped; invalid rows are rejected. Existing residents are never updated by CSV import.
5. Review the final imported/skipped/rejected counts and download the result CSV if needed. Confirm the new residents in the Residents page.

## Safety and behavior

- Files must have a .csv extension, be no larger than **2 MiB**, and contain no more than **2,000 non-empty data rows**. Split larger files before uploading.
- The importer validates row values against BIRMS validators, the real database schema, existing household relationships, and active lookup values.
- Duplicate detection uses first name + last name + birthdate. A duplicate of an existing record (including an archived resident) or an earlier valid row in the same file is **Skipped** with a reason. It never overwrites an existing resident.
- Invalid rows do not save. Ready rows are inserted in one database transaction. If a database error interrupts the transaction, the entire batch is rolled back; no partial import is left behind.
- Preview state and downloadable error reports are tied to the signed-in user and expire after 30 minutes. Re-upload and preview again if the session expires.
- The feature requires no schema migration. The audit trail records template downloads, previews, successful resident inserts, summary counts, error-report downloads, rejected upload attempts, and batch rollbacks.
- CI testing uses the disposable **barangay_db_work** database. Local XAMPP/browser verification is still required before importing official resident information.
