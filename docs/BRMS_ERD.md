# BRMS Entity Relationship Diagram (ERD)

**Repository checked:** `sachie34/Birms`  
**Baseline:** `main`, database migrations `001_core.sql` through `012_sms_provider.sql`, plus the PHP pages and libraries that read/write these tables.  
**Purpose:** Document the database that the current application actually uses. This is an implementation ERD, not a proposed future schema.

> **Scope note:** The current code uses household-based resident addresses and includes a Visitor Logbook. It does not implement the revised-SRS queue/kiosk or venue-reservation modules. Those are intentionally not added to this ERD.

## 1. ERD diagram

```mermaid
erDiagram
    USERS {
      int user_id PK
      varchar username UK
      varchar password_hash
      varchar full_name
      enum role
      enum status
      bool must_change_password
      varchar email
      varchar contact_number
      datetime last_login_at
      timestamp created_at
      timestamp updated_at
    }
    LOGIN_FAILURES {
      bigint id PK
      varchar username
      varchar ip_address
      datetime attempted_at
    }
    AUDIT_LOG {
      bigint audit_id PK
      datetime occurred_at
      int user_id FK
      varchar username_snapshot
      varchar action
      varchar module
      varchar entity_type
      int entity_id
      enum status
      varchar remarks
      varchar ip_address
    }
    SETTINGS {
      varchar setting_key PK
      text setting_value
      int updated_by FK
      timestamp updated_at
    }
    LOOKUPS {
      int lookup_id PK
      varchar list_key
      varchar label
      int sort_order
      bool is_active
      timestamp created_at
    }
    DOCUMENT_TYPES {
      int type_id PK
      varchar name UK
      decimal fee_amount
      text template_body
      bool is_active
      int sort_order
    }
    COUNTERS {
      varchar counter_name PK
      int counter_year PK
      int last_value
    }
    HOUSEHOLDS {
      int household_id PK
      varchar household_code UK
      int purok_id FK
      varchar street_address
      timestamp created_at
      timestamp updated_at
    }
    RESIDENTS {
      int resident_id PK
      int household_id FK
      varchar last_name
      varchar first_name
      varchar middle_name
      varchar suffix
      date birthdate
      enum sex
      enum civil_status
      varchar contact_number
      int category_id FK
      int employment_status_id FK
      enum residency_status
      enum voter_status
      bool is_household_head
      int created_by FK
      timestamp created_at
      timestamp updated_at
      datetime archived_at
    }
    VISITOR_LOG {
      int visit_id PK
      datetime visited_at
      varchar visitor_name
      varchar purpose
      int class_id FK
      int logged_by FK
      timestamp created_at
    }
    DOCUMENT_REQUESTS {
      int request_id PK
      varchar reference_no UK
      int visit_id FK
      int resident_id FK
      int type_id FK
      varchar purpose
      enum status
      varchar status_note
      enum requirements_status
      varchar requirements_note
      int fee_rule_id FK
      varchar fee_category_snapshot
      varchar fee_tier_snapshot
      int fee_quantity
      decimal fee_unit_rate
      decimal quoted_fee_amount
      int assigned_to FK
      datetime requested_at
      int created_by FK
      timestamp updated_at
    }
    SMS_OUTBOX {
      int sms_id PK
      datetime created_at
      varchar recipient_number
      varchar message
      varchar trigger_event
      int request_id FK
      int hearing_id
      enum delivery
      varchar provider_name
      varchar provider_message_id
      enum provider_status
      varchar provider_error
      int attempt_count
      datetime last_attempt_at
      timestamp updated_at
    }
    ISSUANCES {
      int issuance_id PK
      varchar reference_no UK
      int request_id FK,UK
      int resident_id FK
      int type_id FK
      varchar resident_name
      varchar type_name
      varchar purpose
      decimal fee_amount
      int fee_rule_id FK
      varchar fee_category_snapshot
      varchar fee_tier_snapshot
      int fee_quantity
      decimal fee_unit_rate
      varchar or_number UK
      text body_text
      varchar captain_name
      varchar signature_text
      int issued_by FK
      varchar issued_by_name
      datetime issued_at
      int print_count
      datetime last_printed_at
    }
    FEE_MATRIX {
      int fee_rule_id PK
      int type_id FK
      varchar fee_category
      varchar fee_tier
      decimal unit_rate
      int min_quantity
      int max_quantity
      date effective_from
      date effective_to
      bool is_active
      int created_by FK
      timestamp created_at
    }
    RESIDENT_VERIFICATIONS {
      int verification_id PK
      int resident_id FK
      enum verification_subject
      enum verification_method
      enum outcome
      varchar reference_note
      varchar remarks
      int verified_by FK
      timestamp verified_at
    }
    DOCUMENT_ATTACHMENTS {
      int attachment_id PK
      int visit_id FK
      int request_id FK
      varchar original_name
      varchar storage_path
      varchar mime_type
      int byte_size
      char sha256
      int uploaded_by FK
      datetime uploaded_at
    }
    CASH_COLLECTION_LEDGER {
      int ledger_id PK
      int issuance_id FK,UK
      datetime transaction_date
      varchar requester_name
      decimal fee_amount
      enum payment_method
      int collector_id FK
      varchar collector_name
      enum reconciliation_status
      int reconciled_by FK
      datetime reconciled_at
      varchar notes
      timestamp created_at
      timestamp updated_at
    }
    BLOTTERS {
      int blotter_id PK
      varchar case_no UK
      date incident_date
      varchar incident_place
      int purok_id FK
      int incident_type_id FK
      text narrative
      enum status
      varchar resolution_note
      datetime resolved_at
      datetime archived_at
      datetime recorded_at
      int recorded_by FK
      timestamp updated_at
    }
    BLOTTER_PARTIES {
      int party_id PK
      int blotter_id FK
      enum party_role
      varchar full_name
      int age
      varchar address
      varchar contact_number
      timestamp created_at
    }
    HEARINGS {
      int hearing_id PK
      int blotter_id FK
      datetime scheduled_at
      varchar venue
      varchar notes
      enum status
      varchar status_note
      int summons_count
      int created_by FK
      timestamp created_at
      timestamp updated_at
    }

    USERS o|--o{ AUDIT_LOG : "performed"
    USERS o|--o{ SETTINGS : "updated"
    USERS o|--o{ RESIDENTS : "created"
    USERS o|--o{ VISITOR_LOG : "logged"
    USERS o|--o{ DOCUMENT_REQUESTS : "created"
    USERS o|--o{ DOCUMENT_REQUESTS : "assigned"
    USERS o|--o{ ISSUANCES : "issued"
    USERS o|--o{ FEE_MATRIX : "created"
    USERS o|--o{ RESIDENT_VERIFICATIONS : "verified"
    USERS o|--o{ DOCUMENT_ATTACHMENTS : "uploaded"
    USERS o|--o{ CASH_COLLECTION_LEDGER : "collected"
    USERS o|--o{ CASH_COLLECTION_LEDGER : "reconciled"
    USERS o|--o{ BLOTTERS : "recorded"
    USERS o|--o{ HEARINGS : "created"

    LOOKUPS o|--o{ HOUSEHOLDS : "purok"
    LOOKUPS o|--o{ RESIDENTS : "resident category"
    LOOKUPS o|--o{ RESIDENTS : "employment status"
    LOOKUPS o|--o{ VISITOR_LOG : "visit class"
    LOOKUPS o|--o{ BLOTTERS : "purok"
    LOOKUPS o|--o{ BLOTTERS : "incident type"

    HOUSEHOLDS o|--o{ RESIDENTS : "contains"
    RESIDENTS ||--o{ DOCUMENT_REQUESTS : "requests"
    VISITOR_LOG o|--o{ DOCUMENT_REQUESTS : "may originate"
    DOCUMENT_TYPES ||--o{ DOCUMENT_REQUESTS : "requested type"
    DOCUMENT_TYPES ||--o{ ISSUANCES : "issued type"
    DOCUMENT_TYPES ||--o{ FEE_MATRIX : "has fee rules"
    FEE_MATRIX o|--o{ DOCUMENT_REQUESTS : "quoted rule"
    FEE_MATRIX o|--o{ ISSUANCES : "snapshot source"
    DOCUMENT_REQUESTS ||--o| ISSUANCES : "may produce one issuance"
    RESIDENTS ||--o{ ISSUANCES : "named resident"
    ISSUANCES ||--o| CASH_COLLECTION_LEDGER : "may have one ledger row"
    RESIDENTS ||--o{ RESIDENT_VERIFICATIONS : "has verification history"
    VISITOR_LOG o|--o{ DOCUMENT_ATTACHMENTS : "may have files"
    DOCUMENT_REQUESTS o|--o{ DOCUMENT_ATTACHMENTS : "may have files"
    DOCUMENT_REQUESTS o|--o{ SMS_OUTBOX : "may trigger SMS"
    BLOTTERS ||--o{ BLOTTER_PARTIES : "has parties"
    BLOTTERS ||--o{ HEARINGS : "has hearings"
```

## 2. Relationship and cardinality notes

| Parent | Child | Cardinality | Meaning in this application |
|---|---|---:|---|
| HOUSEHOLDS | RESIDENTS | 1 to 0..many | A household may contain multiple resident records; a resident may have no household assigned. |
| RESIDENTS | DOCUMENT_REQUESTS | 1 to many | Each request belongs to one resident. |
| VISITOR_LOG | DOCUMENT_REQUESTS | 1 to 0..many | A request may optionally be linked to a visitor entry. |
| DOCUMENT_TYPES | DOCUMENT_REQUESTS | 1 to many | Each request selects one configured document type. |
| DOCUMENT_REQUESTS | ISSUANCES | 1 to 0..1 | A request can produce at most one issuance; `issuances.request_id` is unique. |
| ISSUANCES | CASH_COLLECTION_LEDGER | 1 to 0..1 | An issuance can have at most one ledger row; the application creates one when the fee is greater than zero. |
| BLOTTERS | BLOTTER_PARTIES | 1 to many | A case has complainant/respondent party records. |
| BLOTTERS | HEARINGS | 1 to many | A case may have multiple scheduled or completed hearings. |
| RESIDENTS | RESIDENT_VERIFICATIONS | 1 to many | Manual identity/voter-list verification history is retained. |
| VISITOR_LOG / DOCUMENT_REQUESTS | DOCUMENT_ATTACHMENTS | 1 to many each | Files are attached to either a visitor entry or a request; application code inserts one owner column per attachment. |
| DOCUMENT_REQUESTS | SMS_OUTBOX | 1 to many | A request can have notification log entries; the current ready event writes an outbox record. |

**Optional foreign keys:** A nullable user reference means a record can remain if the related user is later removed; the foreign key uses `ON DELETE SET NULL`. Lookup references are shared by `list_key` (for example, `purok`, `resident_category`, `employment_status`, `visit_class`, and `incident_type`).

**Special cases:** `AUDIT_LOG.entity_type/entity_id` is a polymorphic audit reference, not a database foreign key to every business table. `COUNTERS` uses the composite primary key `(counter_name, counter_year)`. `LOGIN_FAILURES` is an independent sign-in security log. `SETTINGS` is a key/value table.

## 3. Actual application modules represented

- Accounts, login protection, role-based access and audit trail.
- Barangay settings, configured document types, lookup lists and running counters.
- Resident and household registration, resident search/filter, CSV import/export, archive/restore and manual identity/voter-registration verification.
- Visitor Logbook with visit classification and private attachments.
- Document request intake, assignment, requirements review, final decision, status tracking and request attachments.
- Configurable fee matrix and saved fee quotes.
- Certificate generation, print/PDF preview, issuance history and O.R. tracking.
- Cash collection ledger and reconciliation.
- Blotter case intake, complainant/respondent records, status changes, case archive/restore, hearings and summons.
- SMS outbox and optional provider delivery tracking.

## 4. Deliberate exclusions (not implemented in this repository baseline)

The following entities are **not** present in migrations 001–012 and are not represented as working modules in the current PHP routes: queue entries/call-next/served/skip, public kiosk/QR queue flow, venue inventory, venue reservations and reservation conflict handling, and email notification delivery. Do not add them to the submitted implementation ERD unless those features are implemented and tested in the application.

## 5. Source files reviewed

- `app/menu.php`, `app/permissions.php`
- `app/residents_lib.php`, `app/requests_lib.php`, `app/certificates_lib.php`, `app/blotters_lib.php`, `app/attachments_lib.php`
- `public/residents/*`, `public/visitors/index.php`, `public/requests/*`, `public/certificates/*`, `public/blotters/*`, `public/admin/ledger.php`, `public/admin/sms.php`
- `database/migrations/001_core.sql` through `012_sms_provider.sql`

**Verification boundary:** This ERD is based on the checked-in schema and the application SQL paths. A live database export from the user's XAMPP instance was not available in this review, so local-only schema changes are not included.
