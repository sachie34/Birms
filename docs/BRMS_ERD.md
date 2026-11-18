# BRMS Entity Relationship Diagram

**Repository baseline:** `main` database migrations 001–012  
**Purpose:** Show the schema that is actually created by the checked-in migrations, then identify the additional entities required by the revised SRS but not yet represented in those migrations.

> **Important:** This is not a claim that the current database fully implements the revised SRS. The current schema still contains the legacy `households` and `visitor_log` tables. The revised SRS changes resident grouping to Purok/address and replaces visitor logging with queueing. Queueing, venue and reservation tables were not found in migrations 001–012.

## 1. Existing database ERD (implemented migrations)

The diagram below reflects the tables and foreign-key relationships in `database/migrations/001_core.sql` through `012_sms_provider.sql`. Columns are intentionally limited to primary keys, foreign keys and the main business attributes so the diagram remains readable.

```mermaid
erDiagram
    USERS {
        int user_id PK
        varchar username UK
        varchar full_name
        enum role
        enum status
        varchar email
        varchar contact_number
    }
    LOGIN_FAILURES {
        bigint id PK
        varchar username
        varchar ip_address
        datetime attempted_at
    }
    AUDIT_LOG {
        bigint audit_id PK
        int user_id FK
        varchar username_snapshot
        varchar action
        varchar module
        varchar entity_type
        int entity_id
        datetime occurred_at
    }
    SETTINGS {
        varchar setting_key PK
        text setting_value
        int updated_by FK
        datetime updated_at
    }
    LOOKUPS {
        int lookup_id PK
        varchar list_key
        varchar label
        int sort_order
        boolean is_active
    }
    DOCUMENT_TYPES {
        int type_id PK
        varchar name UK
        decimal fee_amount
        text template_body
        boolean is_active
    }
    COUNTERS {
        varchar counter_name PK
        smallint counter_year PK
        int last_value
    }
    HOUSEHOLDS {
        int household_id PK
        varchar household_code UK
        int purok_id FK
        varchar street_address
    }
    RESIDENTS {
        int resident_id PK
        int household_id FK
        varchar first_name
        varchar last_name
        date birthdate
        enum civil_status
        int category_id FK
        int employment_status_id FK
        enum residency_status
        enum voter_status
        int created_by FK
        datetime archived_at
    }
    VISITOR_LOG {
        int visit_id PK
        datetime visited_at
        varchar visitor_name
        varchar purpose
        int class_id FK
        int logged_by FK
    }
    DOCUMENT_REQUESTS {
        int request_id PK
        varchar reference_no UK
        int visit_id FK
        int resident_id FK
        int type_id FK
        int assigned_to FK
        int created_by FK
        enum status
        enum requirements_status
        int fee_rule_id FK
        decimal quoted_fee_amount
        datetime requested_at
    }
    SMS_OUTBOX {
        int sms_id PK
        int request_id FK
        varchar recipient_number
        varchar trigger_event
        varchar provider_name
        varchar provider_message_id
        enum provider_status
        int attempt_count
    }
    ISSUANCES {
        int issuance_id PK
        varchar reference_no UK
        int request_id FK
        int resident_id FK
        int type_id FK
        int issued_by FK
        int fee_rule_id FK
        decimal fee_amount
        varchar or_number
        datetime issued_at
        varchar signature_text
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
        int created_by FK
    }
    BLOTTERS {
        int blotter_id PK
        varchar case_no UK
        date incident_date
        varchar incident_place
        int purok_id FK
        int incident_type_id FK
        enum status
        int recorded_by FK
        datetime archived_at
    }
    BLOTTER_PARTIES {
        int party_id PK
        int blotter_id FK
        enum party_role
        varchar full_name
        varchar address
        varchar contact_number
    }
    HEARINGS {
        int hearing_id PK
        int blotter_id FK
        datetime scheduled_at
        varchar venue
        enum status
        int created_by FK
    }
    RESIDENT_VERIFICATIONS {
        int verification_id PK
        int resident_id FK
        enum verification_subject
        enum verification_method
        enum outcome
        int verified_by FK
        datetime verified_at
    }
    DOCUMENT_ATTACHMENTS {
        int attachment_id PK
        int visit_id FK
        int request_id FK
        int uploaded_by FK
        varchar original_name
        varchar storage_path
        varchar mime_type
        char sha256
    }
    CASH_COLLECTION_LEDGER {
        int ledger_id PK
        int issuance_id FK
        int collector_id FK
        int reconciled_by FK
        decimal fee_amount
        enum payment_method
        enum reconciliation_status
        datetime transaction_date
    }

    USERS ||--o{ AUDIT_LOG : performs
    USERS ||--o{ SETTINGS : updates
    USERS ||--o{ RESIDENTS : creates
    USERS ||--o{ VISITOR_LOG : records
    USERS ||--o{ DOCUMENT_REQUESTS : assigns_or_creates
    USERS ||--o{ ISSUANCES : issues
    USERS ||--o{ BLOTTERS : records
    USERS ||--o{ HEARINGS : schedules
    USERS ||--o{ RESIDENT_VERIFICATIONS : verifies
    USERS ||--o{ DOCUMENT_ATTACHMENTS : uploads
    USERS ||--o{ FEE_MATRIX : configures
    USERS ||--o{ CASH_COLLECTION_LEDGER : collects_or_reconciles

    LOOKUPS ||--o{ HOUSEHOLDS : classifies_purok
    LOOKUPS ||--o{ RESIDENTS : classifies_category
    LOOKUPS ||--o{ RESIDENTS : classifies_employment
    LOOKUPS ||--o{ VISITOR_LOG : classifies_visit
    LOOKUPS ||--o{ BLOTTERS : classifies_purok
    LOOKUPS ||--o{ BLOTTERS : classifies_incident

    HOUSEHOLDS ||--o{ RESIDENTS : groups
    VISITOR_LOG o|--o{ DOCUMENT_REQUESTS : may_start
    RESIDENTS ||--o{ DOCUMENT_REQUESTS : submits
    DOCUMENT_TYPES ||--o{ DOCUMENT_REQUESTS : requested_as
    DOCUMENT_REQUESTS ||--o| ISSUANCES : may_result_in
    RESIDENTS ||--o{ ISSUANCES : receives
    DOCUMENT_TYPES ||--o{ ISSUANCES : issued_as
    DOCUMENT_TYPES ||--o{ FEE_MATRIX : has_rates
    FEE_MATRIX o|--o{ DOCUMENT_REQUESTS : quoted_by
    FEE_MATRIX o|--o{ ISSUANCES : snapshots_rule
    DOCUMENT_REQUESTS ||--o{ SMS_OUTBOX : triggers
    VISITOR_LOG o|--o{ DOCUMENT_ATTACHMENTS : may_have
    DOCUMENT_REQUESTS o|--o{ DOCUMENT_ATTACHMENTS : may_have
    ISSUANCES ||--o| CASH_COLLECTION_LEDGER : has_collection_record

    BLOTTERS ||--|{ BLOTTER_PARTIES : has_parties
    BLOTTERS ||--o{ HEARINGS : schedules
    RESIDENTS ||--o{ RESIDENT_VERIFICATIONS : has_history
```

### Schema interpretation notes

- `LOOKUPS` is a shared reference table. Its `list_key` distinguishes values such as Purok, resident category, employment status, visit class and incident type.
- `HOUSEHOLDS` is still a real table in the current migrations, and `RESIDENTS.household_id` still references it. The revised SRS says to use address and Purok rather than household grouping. This is a schema/SRS mismatch that needs a migration and code update; do not simply relabel this table in the diagram.
- `VISITOR_LOG` still exists, and both document requests and attachments can reference it. The revised SRS removes the visitor logbook and replaces it with queueing. A safe transition needs to preserve historical visitor-linked requests/attachments while removing visitor intake from the new workflow.
- `BLOTTERS.purok_id` references `LOOKUPS.lookup_id`; there is no separate Purok table in the current schema.
- The current migrations do not define dedicated queue-entry, venue, or venue-reservation tables.
- `DOCUMENT_REQUESTS` stores the request status and requirements review; `ISSUANCES` is a separate permanent issuance snapshot.
- The cash ledger is one-to-one with an issuance because `cash_collection_ledger.issuance_id` is unique.
- The schema's current blotter status enum is not identical to the revised SRS status list. The revised status lifecycle must be reconciled with application code and migrations.

## 2. Revised-SRS conceptual additions (not yet present in migrations 001–012)

The following is a **proposed target model only** for the revised SRS requirements. It is intentionally separate from the implemented ERD above. These entities must not be presented as existing database tables until the team adds and tests their migrations.

```mermaid
erDiagram
    RESIDENT ||--o{ QUEUE_ENTRY : obtains
    SERVICE_TYPE ||--o{ QUEUE_ENTRY : categorizes
    RESIDENT ||--o{ VENUE_RESERVATION : requests
    VENUE ||--o{ VENUE_RESERVATION : is_reserved
    USER_ACCOUNT ||--o{ QUEUE_ENTRY : manages
    USER_ACCOUNT ||--o{ VENUE_RESERVATION : processes

    RESIDENT {
        int resident_id PK
        string resident_identifier
        string address
        int purok_lookup_id FK
    }
    SERVICE_TYPE {
        int service_type_id PK
        string service_code
        string service_name
        boolean is_active
    }
    QUEUE_ENTRY {
        int queue_entry_id PK
        string queue_reference
        int resident_id FK
        int service_type_id FK
        int related_request_id
        int queue_number
        string status
        datetime issued_at
        datetime called_at
        datetime served_at
    }
    VENUE {
        int venue_id PK
        string venue_name
        string description
        boolean is_active
    }
    VENUE_RESERVATION {
        int reservation_id PK
        int venue_id FK
        int resident_id FK
        string reference_no
        string purpose
        datetime start_at
        datetime end_at
        string status
    }
    USER_ACCOUNT {
        int user_id PK
        string username
        string role
    }
```

This target model is conceptual: field names and keys must be confirmed during detailed design. The SRS describes three separate service queues (Documents, Blotter, Venue), queue number lifecycle actions (call next, served, skip, cancel), and venue reservations with overlap prevention. The existing schema does not yet store those records.

## 3. Requirements-to-schema gap checklist

| Revised SRS requirement | Evidence in migrations 001–012 | ERD / implementation action |
|---|---|---|
| Resident grouped by Purok and address; no household grouping | `households` and `residents.household_id` remain | Design a backward-compatible migration to store address and Purok directly or through an approved address model; migrate existing resident data and preserve history. |
| Resident status values Active, Transferred, Deceased, Non-resident | Current `residency_status` is only Active/Relocated | Align enum/reference data and all forms, filters, reports and tests. |
| Resident marital status | `civil_status` exists | Verify exact accepted values against revised SRS. |
| Resident types PWD, Senior Citizen, Solo Parent, 4Ps Beneficiary, Indigent, None | `category_id` points to shared `lookups` | Configure and validate approved values; no labels are seeded as official without approval. |
| Visitor logbook replaced by service queue | `visitor_log` remains; no queue table | Add queue persistence and service-specific numbering; retain legacy records for audit/history. |
| Queue display and staff call/serve/skip/cancel | No queue table in migrations 001–012 | Add queue status, timestamps, service key, number and actor/audit relationships. |
| Venue management and reservations | No venue/reservation tables | Add venues and reservations, with server-side overlap protection and status handling. |
| Request tracker for document, blotter and venue services | Document requests exist; no unified tracker entity for all service types | Define whether one generalized request table or linked service-specific records are intended; preserve document request compatibility. |
| Chairman stored e-signature automatically applied | Issuance has `captain_name` and `signature_text` snapshots; migration 011 describes typed signature text | Confirm whether an uploaded signature image/file is stored and applied in code. A typed name is not an image-based or cryptographically verified digital signature. |
| SMS/email queue and request notifications | SMS outbox/provider tracking exists; email delivery is not evidenced by these migrations | Verify application-level email/SMS delivery, provider configuration and fallback behavior separately. |

## 4. Sources inspected

- `database/migrations/001_core.sql`
- `database/migrations/002_settings.sql`
- `database/migrations/003_residents.sql`
- `database/migrations/004_requests.sql`
- `database/migrations/005_certificates.sql`
- `database/migrations/006_blotter.sql`
- `database/migrations/007_reporting_fields.sql`
- `database/migrations/008_request_requirements.sql`
- `database/migrations/009_fee_matrix_billing.sql`
- `database/migrations/010_resident_verification_log.sql`
- `database/migrations/011_document_attachments_cash_ledger_signature.sql`
- `database/migrations/012_sms_provider.sql`

