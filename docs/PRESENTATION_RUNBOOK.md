# Historical BIRMS Presentation Runbook — Superseded B1–B12 Workflow

> **Do not use this legacy runbook for the revised MSIT SRS build.** It describes the older B1–B12 `main` application, including workflows and labels that were changed by the revised MSIT requirements. The B1–B12 workflow is historical; `main` now contains the revised MSIT build.

For the current MSIT `main` release, use:

- [Panel Demo Status and Sequence](PANEL_DEMO_STATUS.md)
- [MSIT XAMPP and Role Acceptance Runbook](MSIT_XAMPP_ACCEPTANCE_RUNBOOK.md)
- [MSIT SRS Compliance Plan](MSIT_SRS_COMPLIANCE_PLAN.md)
- [PR #39 — merged MSIT release](https://github.com/sachie34/Birms/pull/39)

Download the current MSIT demonstration build from the [main branch ZIP](https://github.com/sachie34/Birms/archive/refs/heads/main.zip).

Do not present the old visitor-logbook, household-grouping, or legacy role workflows as MSIT-compliant. The current requirements use Purok-based resident grouping, resident kiosk/QR requests, separate Blotter/Documents/Venue queues, revised statuses and a Chairman read-only role.

Merged main commit `735a5404f71f24ca1d697d61919704274d3b1e07` passed GitHub Actions run `45382522696` across eight PHP/MariaDB jobs (48 security checks and 283 module checks per job). Resident notifications in this candidate are email-only; there is no active SMS workflow. Automated CI results are not the same as target-PC acceptance. The current release checklist requires local XAMPP/Apache PHP verification, separate Admin/Chairman/Secretary/Staff/Resident journeys, phone QR/LAN tests, a physical kiosk, actual email delivery (only when authorized), certificate/PDF/print approval, and a backup-restore rehearsal. Mark each item PASS only after observing it on the target setup; otherwise report NOT RUN.
