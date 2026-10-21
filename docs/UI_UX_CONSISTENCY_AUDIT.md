# BIRMS UI/UX Consistency Audit

**Status: CONSISTENT ACROSS INTERACTIVE TABS — automated layout check added.**  
**Reference reviewed:** user-provided `ff(1).pptx` (six UI screens).  
**Repository branch:** `john/paper-code-compliance`  
**Review date:** 2026-10-22.

## What must remain the same between tabs

The interactive BIRMS pages use a single shared layout in `app/layout.php` and a single global stylesheet in `public/assets/css/birms.css`. They share:

- The BIRMS brand and navy-blue sidebar with role-filtered navigation and active-page state.
- The top breadcrumb bar and signed-in user/role menu.
- A common global “Search records” field that opens the permission-aware global search page.
- The same font family, blue primary action buttons, light page background, white rounded cards, form-control treatment, data tables, empty states and status pills.
- Reusable page title, card, form and button helpers from `app/ui.php`.
- Permission checks on the server; the sidebar hides links the current role cannot open.

The Operations Overview/dashboard uses the same `page-head`, `card-b`, `card-h`, `card-body-b` and `table-b` patterns. The new test `B9-1` visits each sidebar route allowed by each role and verifies the shared application shell, sidebar, top bar, global search and content container are present.

## Reference-to-implementation comparison

| Reference element in the PowerPoint | Implementation status |
|---|---|
| Navy sidebar and clear active navigation item | Shared in the authenticated app layout |
| White top bar with breadcrumb and user profile/role | Shared in the authenticated app layout |
| Global “Search records” field | Added; it searches permitted residents, requests, issued certificates and blotter records |
| Blue actions, light backgrounds, bordered white cards and readable tables | Shared stylesheet and reusable UI helpers |
| Dashboard overview and workspace navigation | Data-backed dashboard uses the same shared card/layout style; its numbers are computed from saved application records |
| Page-specific form/table/preview contents | Intentionally differ by module because each tab performs a different task |
| Notification bell and prototype sample cards | Not represented as live functionality just to mimic the image. Only controls linked to real functionality are shown |
| Exact pixel-for-pixel copy of every PowerPoint screenshot | **Not claimed.** The slides are design references/mockups; live pages contain working controls, current database results and safety warnings that the mockups do not show |

## Verification

The code test run for the shared-layout change includes `B9-1` through `B9-4`. `B9-1` checks the layout on every permission-accessible sidebar route for Administrator, Captain, Secretary and Clerk and the search page for each role. `B9-2` checks that hostile HTML in a search query is escaped; `B9-3` checks a real stored working-copy resident can be found; `B9-4` checks search usability for staff.

See the [latest GitHub Actions run list](https://github.com/sachie34/Birms/actions) for the current status and the [presentation runbook](PRESENTATION_RUNBOOK.md) for target-PC checks.

## Target-PC visual sign-off

Automated code tests can prove that the same layout markup is used, but they cannot prove the exact appearance at a particular screen resolution. Before marking the office PC as visually tested, open the dashboard and all permitted navigation tabs at the presentation screen size and check:

1. Sidebar labels, selected state, collapse behavior and scrolling.
2. Header search, breadcrumb and user menu do not overlap at desktop and narrow widths.
3. Page title, action buttons, cards, forms, tables, empty states and validation errors use the shared styles without clipping.
4. Certificate print/PDF pages remain deliberately separate print layouts and open correctly; they are not expected to have the regular sidebar.
5. No sample personal data from the PowerPoint is treated as live office data; use synthetic presentation records.

**Final status:** the code uses a consistent shared UI shell across interactive tabs, with the layout consistency covered by integration tests. A pixel-level sign-off on the presentation computer remains pending until it is visually inspected there.
