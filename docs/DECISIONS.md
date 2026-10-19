# Decisions and defaults (BIRMS)

## Confirmed by the owner (12 Oct 2026)
- **D1** The Project Summary wins when documents conflict. All SOE2 panel recommendations (SMS, status tracking, search, audit trail) are mandatory.
- **D2** The owner builds the whole system alone; no teammate code. Scope "not sure yet": provisionally all 21 items in three tiers.
- **D3** Adapt the code to the real `barangay_db`. Its contents are unknown, so treat it as real data.
- **D4** Keep the household structure the real database already uses (details after the export).
- **D5** Keep the name structure the real database already uses. Middle name and suffix are decided after the export.
- **D6** Four roles; the Administrator manages the system only. Permissions are **provisional** pending the Barangay Secretary; enforced on the server; least privilege; deny by default.
- **D7** LAN in the barangay office only; XAMPP/PHP/MySQL compatible; no cloud, external API or internet needed for core functions; separate test database; never on the public internet. SMS: log-only outbox, no real gateway, no paid services without approval. **HTTPS undecided** (preferred).

## Defaults chosen by me (change any of them)
| Topic | Default | Where |
|---|---|---|
| Password | 10 to 72 characters, a letter and a number, not containing the username | `core.php` |
| Lockout | 5 failures per username in 15 minutes (30 per IP) | `config` |
| Idle sign-out | 30 minutes | `config` |
| Temporary passwords | Random 12 characters, shown once, must be changed at first sign-in | `user_edit.php` |
| First administrator | Created from the command line; no default account | `tools/create_admin.php` |
| Account removal | Accounts are deactivated, never deleted; audit rows keep a username snapshot | schema |
| Audit export | CSV, up to 20,000 rows, spreadsheet formulas neutralized | `audit_export.php` |
| Database mode | Strict SQL mode on every connection | `core.php` |
| Time zone | Asia/Manila (UTC+8) | `config` |
| Fonts and icons | System fonts, local Bootstrap 5.3.3 and Bootstrap Icons 1.11.3 (no internet needed) | `public/assets` |
| Age brackets | 0-17, 18-59, 60 and over. **Not from your documents**; editable in Settings | `002_settings.sql` |
| Menu | A page appears in the sidebar only when it exists and the role may use it | `menu.php` |

## Blotter (B6): defaults I chose (none comes from your documents unless stated)
- Case statuses follow the Project Summary: Pending, Active, Scheduled, Resolved. Hearing outcomes: Held, Postponed, Cancelled.
- A case is filed with a date of incident, an optional place and Purok, a narrative (10-5000 characters), a complainant and an optional respondent. The incident date cannot be in the future or more than 10 years back. **The incident date, place and Purok fields are my additions; the Summary names only parties, ages, addresses and narrative.**
- Case numbers look like `BLT-2026-0001` (one counter per year).
- "Scheduled" is never set by hand: scheduling a hearing sets it, and recording the outcome of the last open hearing returns the case to Active.
- A hearing must be in the future and within one year; it can be marked Held only once its time has come; Postponed and Cancelled need a reason; an outcome is final.
- A case with an open hearing cannot be resolved. Resolving needs a note. A Resolved case is final: no edit, no new party or hearing, no reopening.
- Only a Resolved case can be archived (Secretary). Archived cases stay searchable and viewable. Nothing in the blotter is ever deleted, and the database refuses deletions that would orphan parties or hearings.
- Look-up matches every word typed against party names, in any order. A match is shown as a **possible** record. Every search is written to the audit trail.
- Clearance requests show possible blotter records for the resident's name. It is a hint only and never blocks the request.
- Summons wording is **not shipped**. A summons cannot be printed until the Secretary enters it; it also refuses to print without a respondent or when a placeholder it uses (such as the venue) is empty. Layout is provisional.
- Incident counts by Purok (module 15) are part of the Reports batch.

## Still pending (not invented)
Official Purok list, certificate fees and wording, resident categories, government report formats, signatory names,
SMS provider, voter-check method, HTTPS setup, Barangay Secretary's validation of permissions.
