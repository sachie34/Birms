# BRMS Business Process Model and Notation (BPMN) — Revised SRS Branch

**Repository:** `sachie34/Birms`  
**Code baseline:** `john/msit-srs-compliance`  
**Editable BPMN source:** `BRMS_BPMN.bpmn` (BPMN 2.0 XML with diagram layout)  
**Application source checked:** kiosk, queue, documents, resident-blotter intake, blotter/hearing, venue, tracker, notification and certificate routes/libraries.

> These workflows are based on the newer MSIT-SRS-compliance branch. They include kiosk/QR identity verification, three service queues, venue reservations and the shared status tracker. Do not use the older `main`-branch diagrams for this version of the system.

## 1. Resident kiosk identity verification and service selection

```mermaid
flowchart TD
    A([Resident opens kiosk or QR request link]) --> B{Identity method}
    B -- Resident ID --> C[Enter Resident ID and birthdate]
    C --> D{Exactly one active resident matches?}
    D -- Yes --> G[Create verified kiosk session]
    D -- No --> E[Show verification error and ask Staff for help]
    B -- Name fallback --> F[Enter first name, last name and birthdate]
    F --> H{Exactly one active resident matches?}
    H -- No / multiple --> E
    H -- Yes --> I[Send one-time code to registered email or mobile]
    I --> J[Resident enters six-digit code]
    J --> K{Code valid, unused and not expired?}
    K -- No --> E
    K -- Yes --> G
    G --> L[Show resident service menu]
    L --> M[Documents / Blotter / Venue / Queue / Status lookup]
    M --> N([Continue to selected service])
```

- Direct verification uses Resident ID plus birthdate.
- Fallback verification uses first name, last name and birthdate, then a one-time code sent to the registered contact channel.
- Kiosk identity attempts are rate-limited. The verified kiosk session expires after 90 seconds of inactivity.
- A resident can only use the self-service flow when the resident record is active and not archived.

## 2. Document request and certificate issuance

```mermaid
flowchart TD
    A([Verified resident selects Request a Document]) --> B[Choose active document type and enter purpose]
    B --> C[Create document request and reference number]
    C --> D[Set internal status Pending and SRS status Pending]
    D --> E[Issue Documents queue ticket]
    E --> F[Secretary reviews requirements]
    F --> G{Requirements complete?}
    G -- No --> H[Record incomplete items / note; continue review]
    H --> F
    G -- Yes --> I[Mark requirements Complete]
    I --> J[Staff makes final approval decision]
    J --> K{Approved?}
    K -- No --> L[Set Rejected; save reason and status history]
    L --> M[Queue resident status notification]
    M --> N([End: Rejected])
    K -- Yes --> O[Set internal Ready and SRS Approved]
    O --> P[Queue email/SMS notification using resident preference]
    P --> Q[Staff checks fee, template, resident data and signature requirement]
    Q --> R{All issuance checks pass?}
    R -- No --> S[Admin/Secretary correct missing setup or record data]
    S --> Q
    R -- Yes --> T[Issue certificate and snapshot content/signature]
    T --> U[Set internal Claimed and SRS Released]
    U --> V{Fee greater than zero?}
    V -- Yes --> W[Require O.R.; create pending cash ledger row]
    V -- No --> X[No cash ledger row]
    W --> Y[Print or download certificate PDF]
    X --> Y
    Y --> Z[Update status history and issuance history]
    Z --> AA([End: Released])
```

**Important status distinction:** The application keeps internal workflow statuses (`Pending`, `Review`, `Ready`, `Claimed`, `Rejected`, `Cancelled`) and a separate revised-SRS public status (`Pending`, `Approved`, `Rejected`, `Released`). The unified tracker and resident status lookup use the revised-SRS status.

- Secretary/Admin performs the requirements review; Staff/Admin performs the final request decision and certificate issuance according to the permission map.
- Certificate issuance is blocked if requirements are not complete, the fee or wording is not configured, required resident details are missing, or a required Chairman signature image is unavailable.
- The Admin-managed Chairman signature image is applied automatically when the document type requires it and is snapshotted with the issuance.
- Paid issuance requires an O.R. number and creates a pending cash-ledger row. A zero-fee issuance does not create a ledger row.
- The certificate layout is still marked as a draft pending official layout approval; printing a PDF does not mean the official barangay format has been approved.

## 3. Resident-submitted blotter report and official case workflow

```mermaid
flowchart TD
    A([Verified resident selects File a Blotter Report]) --> B[Enter incident date/time, place, Purok/type, narrative and optional respondent]
    B --> C[Create resident blotter request with reference]
    C --> D[Set review Pending and resident status Ongoing]
    D --> E[Issue Blotter queue ticket and show receipt]
    E --> F[Staff/Admin reviews report]
    F --> G{Accept report?}
    G -- No --> H[Record rejection reason; review Rejected; status Dismissed]
    H --> I[Queue resident notification and status history]
    I --> J([End: Dismissed intake])
    G -- Yes --> K[Create official blotter case and complainant/respondent party rows]
    K --> L[Link intake request to official case; review Accepted]
    L --> M[Official case status Ongoing]
    M --> N[Staff updates Ongoing / Unresolved]
    N --> O{Resolve or dismiss?}
    O -- No --> N
    O -- Yes --> P{Any Scheduled hearings?}
    P -- Yes --> Q[Record each hearing outcome: Held / Postponed / Cancelled]
    Q --> P
    P -- No --> R[Record reason; set Resolved or Dismissed]
    R --> S[Authorized Staff/Admin archives the case]
    S --> T[Update resident request status, shared history and notification]
    T --> U([End: Archived record retained])
```

- The resident-submitted intake is stored separately in `resident_blotter_requests`.
- Staff/Admin accepts or rejects the intake. Accepting it creates the official `blotters` record and party rows; rejecting it leaves the intake in history and marks its public status Dismissed.
- Official case statuses are `Ongoing`, `Unresolved`, `Resolved`, `Dismissed`, and `Archived`.
- Hearing scheduling is tracked separately. A hearing can be marked `Held`, `Postponed`, or `Cancelled`; this does not automatically change the five-state blotter case status.
- A case cannot be resolved, dismissed or archived while it has a Scheduled hearing. Resolved/Dismissed cases can then be archived; blotter records are protected from deletion.

## 4. Venue reservation workflow

```mermaid
flowchart TD
    A([Verified resident selects Reserve a Venue]) --> B[View venues and current availability]
    B --> C[Choose venue, purpose, date, start and end time]
    C --> D[Validate active venue, resident and time range]
    D --> E{Time overlaps a Pending or Approved reservation?}
    E -- Yes --> F[Reject submission and ask resident to choose another time]
    F --> C
    E -- No --> G[Create reservation with unique VEN reference and Pending status]
    G --> H[Issue Venue queue ticket]
    H --> I[Staff reviews reservation]
    I --> J{Approve?}
    J -- No --> K[Set Rejected and record reason]
    J -- Yes --> L[Lock venue and recheck overlapping reservations]
    L --> M{Slot still available?}
    M -- No --> K
    M -- Yes --> N[Set reservation Approved]
    N --> O[When reservation is finished, set Completed]
    K --> P[Update shared status history and notify resident]
    O --> P
    P --> Q([End])
```

- Pending and Approved reservations both block overlapping time slots.
- Approval performs a second overlap check while locking the venue to protect against simultaneous approvals.
- Live availability is green/Available or red/In Use. Staff can also set an explicit availability override.
- Reservation statuses are `Pending`, `Approved`, `Rejected`, and `Completed`.

## 5. Service queue workflow

```mermaid
flowchart TD
    A([Resident or Staff requests a queue ticket]) --> B[Select Blotter, Documents or Venue]
    B --> C[Lock daily service counter and assign next number]
    C --> D[Save queue entry as Waiting]
    D --> E[Queue email/SMS notice according to resident preference]
    E --> F[Display ticket and queue number]
    F --> G[Staff calls next Waiting number]
    G --> H{A number is already Called?}
    H -- Yes --> I[Finish current Called entry first]
    I --> G
    H -- No --> J[Set next entry Called and show number on public display]
    J --> K{Service result}
    K -- Served --> L[Set Served and record time/user]
    K -- Skip --> M[Set Skipped]
    K -- Cancel --> N[Set Cancelled]
    L --> O[Refresh current number and waiting count]
    M --> O
    N --> O
    O --> P([End / next queue cycle])
```

- Each of the three services has a separate daily counter and queue sequence.
- Only one queue entry per service/day may be in the Called state at a time.
- Staff can issue a ticket, call next, mark served, skip or cancel. The public display exposes queue numbers and waiting counts, not resident personal information.
- Queue notifications are placed in the email/SMS outboxes according to the resident's saved preference; outbox creation alone does not guarantee delivery.

## 6. Unified tracker and resident status lookup

The Staff/Admin All Services Tracker combines document requests, resident blotter reports/official cases and venue reservations. The kiosk status lookup accepts a reference number and displays the service, current SRS status, submitted time and available status history. It does not display resident personal details.

## 7. Role lanes / responsibility summary

| Internal role | Revised SRS role | Main workflow responsibilities |
|---|---|---|
| `admin` | Admin | System setup, users, settings, queue/venue controls, all service actions, signature image configuration and audit. |
| `clerk` | Staff | Resident-facing service processing, final document decisions and issuance, queue handling, venue decisions and blotter intake review. |
| `secretary` | Secretary | Resident/document/blotter record maintenance, document requirements review and hearing/case support. |
| `captain` | Chairman / Punong Barangay | Read-only access to records, tracker, hearings, queue, venues, reports and cash ledger. The stored signature is applied by Staff; Chairman does not approve or edit records in the current permission map. |

## 8. Notation and implementation boundary

The Mermaid diagrams in this file are readable workflow overviews. The editable BPMN 2.0 XML file contains the principal service processes for import into a BPMN editor. This documentation reflects source code and migrations on `john/msit-srs-compliance`; it does not by itself prove that the user's local XAMPP database has applied every migration or that external email/SMS delivery is configured and successful.
