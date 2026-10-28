# BIRMS Role-Clarity UX Decisions

**Review date:** 2026-10-28  
**Repository:** `sachie34/Birms`  
**Status:** Proposed UX improvements; the role-permission matrix remains **PROVISIONAL** until approved by the barangay/system owner.

## Goal

Help each user answer three questions quickly: **What can I do in this role? What needs my attention? What happens next?** Preserve the five core paper modules, current database statuses, and server-side authorization. Do not add workflows merely because they appear in a generic approval-system example.

## Recommended implementation order

### Phase 1 — Low-risk clarity improvements

1. Keep the role visible in the application header and on the Request Tracker.
2. Explain the next step for each role at the top of the Request Tracker.
3. Use role-specific work queues: Administrator — **Needs Action**; Captain — **Pending Final Approval**; Secretary — **Needs Review**; Clerk — **My Requests**. The server must enforce the same eligibility rules as the queue, including blocking approval and rejection before requirements are marked **Complete**.
4. Show the age since original submission. Label it accurately; do not imply it measures time at the current approval step unless stage-transition timestamps are stored.
5. Use empty states that explain there is no work in the selected view and provide a route to other requests/history.
6. Use explicit action labels and keep status names consistent across tabs, trackers, notifications and documents.
7. Keep status text and icons alongside color, preserve keyboard focus/navigation, and use accessible labels.
8. Hide actions the signed-in role cannot perform. Enforce every permission again on the server; UI visibility is not a security control. Scope Clerk request lists, detail pages, edits, and status-change POSTs to requests they filed or that are assigned to them.
9. Keep the existing role-aware dashboard shortcuts and quick user workflow guide as the main onboarding entry point.

### Phase 2 — After browser/UAT confirms Phase 1

- Add a queue summary bar and additional search/filter/sort controls only where the existing fields support accurate results.
- Add a visible, timestamped event timeline if every event can be read from reliable stored history.
- Improve structured rejection reasons and a safe resubmission path, preserving the reason and original request reference.
- Add in-app notifications with direct links only when the notification state and recipient permissions are reliable.
- Improve responsive layouts and keyboard/screen-reader behavior on the actual target XAMPP browser.

### Phase 3 — Requires a written business-policy decision

- Draft/autosave and clone-request behavior.
- Comments on pending requests, withdrawal rules, delegation and deputy access.
- Email notifications, quiet hours, nudges, SLA timers and automatic escalation.
- Urgent/routine priority rules.
- Bulk approval.
- Conditional or parallel approval, monetary thresholds, emergency bypasses and rule overrides.
- Admin impersonation / “view as”, role-union behavior for multi-role accounts, and automatic queue transfers after account deactivation.
- New audit retention periods, audit diffs, or new export formats.

Do not enable Phase 3 by assumption. These changes can affect legal accountability, privacy, auditability, or the approved business process.

## Current request workflow to explain to users

1. **Clerk:** submit a request and track it in **My Requests**.
2. **Secretary or Administrator:** check requirements and record whether they are complete.
3. **Captain or Administrator:** make the final request decision after requirements are complete.
4. **Secretary or Administrator:** issue an eligible request's certificate under the current candidate permission matrix.
5. **Clerk:** print/download an already-issued certificate, but cannot issue it.

The current stored statuses are `Pending`, `Review`, `Ready`, `Claimed`, `Rejected`, and `Cancelled`. User-facing labels can be clearer, but do not add a saved `Draft`, `Pending Secretary`, or `Pending Captain` status without a reviewed migration, transition logic, and regression tests. A request's original submission age is not the same as the age of its current review step.

## Specific decisions on the 22-point proposal

| # | Decision |
|---:|---|
| 1 | Persistent role context; hide unauthorized actions; give clear server-side denial messages. No animated role-change badge is needed. |
| 2 | One shared module navigation with role-specific queues and a role-specific home view. Avoid a separate tab set for every role. |
| 3 | Keep a clear progress tracker and queue separation. Show rejection reasons inline. Allow comments/withdrawal only after rules and audit behavior are defined. |
| 4 | Keep the current sequential, requirements-gated workflow. No parallel approval or monetary routing without policy approval. |
| 5 | Specific empty states and permission errors; no false promise of draft recovery unless autosave is implemented. |
| 6 | Use verb + object labels such as **Submit Request**, **Review Requirements**, **Approve Request**, and **Reject Request**. Use **Forward to Captain** only for a real action. |
| 7 | Keep a reopenable role guide rather than a recurring onboarding modal. Show “What’s new” only for verified permission changes. |
| 8 | Refresh permissions after changes and keep server checks authoritative. Multi-role and deactivated-user behavior require explicit policy. |
| 9 | Start with reliable in-app notifications and direct links. Email, quiet hours, nudges and escalation timers are later work. |
| 10 | Do not hardcode example amounts/categories or add a routing editor without a signed-off rule set. |
| 11 | Preserve event actor, action, timestamp and reason. Retention and new audit exports require owner approval. |
| 12 | Defer bulk actions to reduce accidental approvals and preserve accountability. |
| 13 | Add a checklist before implementing draft/autosave/clone features. |
| 14 | Require and display rejection reasons; structured categories and resubmission templates are a follow-up. |
| 15 | Do not implement impersonation now. Use dedicated test accounts for role demonstrations. |
| 16 | Prioritize responsive layout and keyboard access; do not implement swipe approval or offline approval. |
| 17 | Use consistent status text + icon + color; never use color alone. |
| 18 | Prioritize reference/name search and oldest-submission sorting. Add saved views only after filter behavior is tested. |
| 19 | Do not let submitters bypass the queue by choosing an ungoverned urgency level. |
| 20 | Defer delegation until deputies, effective dates, attribution and audit rules are approved. |
| 21 | Prioritize actionable Captain queues and accurate counts; add trend analytics after validating the underlying timestamps and definitions. |
| 22 | Ensure keyboard navigation, screen-reader labels, sensible focus after actions, and print-friendly audit output if required by the office. |

## Validation checklist

- [ ] Run repository PHP syntax checks and the automated workflow/module tests.
- [ ] On the target XAMPP PC, sign in separately as Administrator, Captain, Secretary and Clerk.
- [ ] Confirm each role sees the correct queue label, next-step guidance and count.
- [ ] Confirm the Captain's queue excludes requests whose requirements are not complete.
- [ ] Confirm the Administrator's queue includes both review-stage work and eligible final-decision work.
- [ ] Confirm Clerk cannot open/edit/cancel another person's request by direct URL or forged POST, cannot modify review fields, and cannot approve/issue.
- [ ] Confirm elapsed-time wording matches the original submission timestamp.
- [ ] Test empty states, search, pagination, mobile layout, keyboard navigation, PDF/print and access-denied behavior.
- [ ] Obtain approval of the candidate permission matrix and official templates before production use.

CI passing is not the same as local browser/printer acceptance or official policy approval.
