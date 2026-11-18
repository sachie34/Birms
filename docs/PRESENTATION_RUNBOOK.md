# Legacy BIRMS Presentation Runbook — Main Branch (B1–B12)

> **Do not use this legacy runbook for the revised MSIT SRS build.** It describes the older B1–B12 `main` application, including workflows and labels that were changed by the revised MSIT requirements.

For the current MSIT branch, use:

- [MSIT XAMPP and Role Acceptance Runbook](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md)
- [MSIT SRS Compliance Plan](MSIT_SRS_COMPLIANCE_PLAN.md)
- [Pull request #34 — MSIT SRS Compliance](https://github.com/sachie34/Birms/pull/34)

Download the current MSIT working copy from the [`john/msit-srs-compliance` branch ZIP](https://github.com/sachie34/Birms/archive/refs/heads/john/msit-srs-compliance.zip), not the `main` ZIP.

Do not present the old visitor-logbook, household-grouping, or legacy role workflows as MSIT-compliant. The current requirements use Purok-based resident grouping, resident kiosk/QR requests, separate Blotter/Documents/Venue queues, revised statuses and a Chairman read-only role.

Automated CI results are not the same as target-PC acceptance. The current release checklist requires local XAMPP/Apache PHP verification, separate Admin/Chairman/Secretary/Staff/Resident journeys, phone QR/LAN tests, a physical kiosk, actual mail/SMS delivery (only when authorized), certificate/PDF/print approval, and a backup-restore rehearsal. Mark each item PASS only after observing it on the target setup; otherwise report NOT RUN.
