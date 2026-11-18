# BRMS Business Process Model and Notation (BPMN)

**Process:** Resident-facing service intake, queueing, review and completion  
**Requirements baseline:** Revised BRMS SRS  
**Diagram source:** `BRMS_BPMN.bpmn` (editable BPMN 2.0 XML)

## Process overview

1. A resident opens the kiosk or scans the barangay QR code.
2. The resident identifies themselves and chooses Documents, Blotter or Venue.
3. The system creates the relevant service request and a service-specific queue entry.
4. The resident receives and sees a queue number; optional SMS/email notification is sent according to configured contact preferences.
5. Staff calls the next number and handles the request.
6. The service path is followed:
   - **Documents:** validate request → approve/reject → if approved, generate the document → apply Chairman signatory where required → release document → mark Released.
   - **Blotter:** capture incident and parties → staff/Secretary reviews and updates case status → resident can follow the reference/status.
   - **Venue:** check requested date/time → reject if unavailable or approve reservation → update venue availability and reservation status.
7. The system records status changes and timestamps, updates the request tracker, and notifies the resident.
8. The process ends when the service is completed, rejected, or cancelled.

## BPMN participants and responsibility

| Participant / lane | Main responsibility |
|---|---|
| Resident | Starts a service request, provides identity/details, joins queue, checks status and receives result. |
| Kiosk / QR interface | Presents resident-facing options and collects request details; does not expose staff/admin functions. |
| BRMS system | Validates, creates references and queue entries, routes requests, updates statuses, records timestamps and sends configured notifications. |
| Staff | Calls queue numbers, checks submissions, processes service requests and records decisions. |
| Secretary | Reviews/maintains records and blotter/document processing as authorized. |
| Chairman | Provides the stored signatory for certificates that require it; the role is view-only for records/reports in the revised SRS. |
| Administrator | Maintains accounts, reference data, system settings and the Chairman signature asset. |

## Diagram

The editable BPMN 2.0 XML is in [BRMS_BPMN.bpmn](BRMS_BPMN.bpmn). Open it in [bpmn.io](https://bpmn.io/) or another BPMN 2.0 modeler to view, edit and export SVG/PNG/PDF.

## Important implementation boundary

This BPMN models the **revised-SRS target workflow**, not a claim that every step exists in the current `main` database. Migrations 001–012 currently contain document requests, blotters, hearings, issuances, visitor-log references and related records. They do not contain dedicated queue-entry, venue or venue-reservation tables. The current resident schema still references households. These are implementation gaps to resolve before marking the revised SRS fully implemented.

