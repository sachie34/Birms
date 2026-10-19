# UI standards (from the ff.pptx reference)

Colors (sampled from the slides; confirm visually): sidebar gradient `#0c3366` → `#0d3b75`; primary `#1565c0`; page `#f6f8fb`;
card border `#e3e8ef`; heading ink `#0b2a55`; pills use light tints (blue, green, amber, grey, red).

Layout: navy sidebar with BIRMS block, `WORKSPACE` / `ADMINISTRATION` groups and a collapse button; top bar with breadcrumb and user chip
(initials, name, role, menu with Change password and Sign out); page header = title, one-line subtitle, primary button at the right.

Components: cards with 14px radius; forms with labels above and a red asterisk for required fields; tables with a light header and
status pills; filters above tables; 25 rows per page; flash alerts; a confirmation dialog for destructive or irreversible actions;
empty states with an icon and a sentence; no inline styles or scripts (strict Content-Security-Policy).

Rules: plain-language labels (no database field names); the global search box and bell from the reference are **not shown until they work**;
no fake figures; desktop first, usable on a tablet; the sidebar hides below 992px (mobile menu is a later improvement).
