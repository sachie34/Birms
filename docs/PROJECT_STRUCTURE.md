# BIRMS project structure and file organization

## Review result

The general recommendation to separate presentation files, JavaScript, images, documentation, and source code is sound. BIRMS already follows that principle, adapted for a PHP/MySQL application served by Apache in XAMPP.

Do **not** replace the PHP entry point with a root `index.html`. BIRMS sign-in is handled by `public/index.php`, which starts the application bootstrap and processes sessions, CSRF protection, and login. A static HTML file cannot replace those server-side responsibilities.

## Current structure

```text
Birms/
├── README.md                    # Project overview and quick setup
├── SETUP_XAMPP.bat              # Guided local setup
├── .gitignore                   # Excludes local config and runtime data
├── app/                         # PHP application logic, auth, DB, permissions, layout
├── database/                    # Versioned SQL migrations and schema references
├── public/                      # Web-accessible document root
│   ├── index.php                # PHP sign-in entry point
│   ├── dashboard.php            # Main dashboard after sign-in
│   ├── account/                 # Account pages
│   ├── admin/                   # Administrator pages
│   ├── residents/               # Resident module pages
│   ├── requests/                # Request module pages
│   ├── certificates/            # Certificate pages
│   ├── blotters/                # Blotter pages
│   ├── visitors/                # Visitor-log pages
│   └── assets/
│       ├── css/birms.css        # Shared visual design and layout
│       ├── js/app.js            # Shared browser-side behavior
│       └── vendor/              # Local Bootstrap and Bootstrap Icons assets
├── storage/                     # Runtime storage, including private files
├── tests/                       # Automated checks and module tests
├── tools/                       # Setup, migration, schema and maintenance tools
└── docs/                        # Setup guide, audits, runbook, and design notes
```

## How the recommendation maps to BIRMS

| Recommendation | BIRMS approach |
| --- | --- |
| Main `index.html` | Keep `public/index.php` as the main sign-in entry point because authentication and session handling run in PHP. |
| `images/` for media | Add `public/assets/images/` only when the application has image assets to store. Do not create unused folders just to satisfy a generic template. Bootstrap Icons currently provide the interface icons. |
| `styles/` for CSS | Keep the current `public/assets/css/birms.css` path. A dedicated CSS directory is already present; renaming it to `styles/` would add churn without a benefit. |
| `scripts/` for JavaScript | Keep the current `public/assets/js/app.js` path. The JavaScript is already separate from PHP templates. |
| Git | Keep using GitHub branches and pull requests for tracked changes. |
| README | Keep the root `README.md` for quick start instructions; use focused documents under `docs/` for setup, structure, compliance and presentation details. |

## Structure rules to keep

1. Put browser-accessible pages and static assets under `public/`; keep database helpers, authentication logic and application internals under `app/`.
2. Keep private configuration, credentials, logs, backups and uploaded attachments out of the public web root and out of Git.
3. Use one shared stylesheet and shared JavaScript file for common behavior unless a module genuinely needs its own isolated assets.
4. Reuse the existing vendor assets. Do not add duplicate copies of Bootstrap, Bootstrap Icons, or another library without a specific need.
5. Keep each module's PHP pages in its existing module folder and preserve its current URLs while the application is being presented and tested.
6. Run the setup guide and tests after structural changes. Avoid renaming or moving active files as a cosmetic exercise because relative paths, routing, and URL generation may depend on the current layout.

## Recommendation

**Keep the current BIRMS layout.** It already captures the useful part of the recommendation—separation of application code, public assets, tools, tests, storage, and documentation. Adapt the generic static-site example to BIRMS's PHP entry point instead of introducing a root `index.html` or moving active files.
