# Integration Import Notes

## Import source

Imported from `/home/ubuntu/upload/Claudecodebirms.zip`, using the latest self-contained nested package:

- `birms_work_B1-B6.zip`

The import was staged and inspected before copying. The existing `/home/ubuntu/birms-ui-prototype` project was not modified.

## Why B1–B6 was selected

The outer bundle contained earlier B1 and B1–B5 packages in addition to B1–B6. B1–B6 is the latest package by the bundle's naming and contents, and it includes the complete application tree, module libraries, migrations, tests, and documentation needed for a repository starting point.

## Files deliberately not imported

The following were not mixed into the repository:

- Earlier nested work packages (`birms_work_B1.zip`, `birms_work_B1-B5.zip`)
- Outer duplicate SQL files such as `database.sql`, `birms_db_schema.sql`, and `001_core.sql`
- Outer duplicate PHP files and XAMPP readmes
- Runtime log files
- Local configuration files containing environment-specific values

The selected B1–B6 package already contains its own `database/` migrations and expected schema snapshot, so duplicate outer schemas were excluded to avoid an ambiguous database source of truth.

## Safety checks completed

- Created a new Git repository rather than writing into an existing repository.
- Created branch `integration/import-b6`.
- Preserved the existing UI/UX prototype as a separate project.
- Confirmed the imported package contains no `.env`, PEM, SSH private key, or committed local configuration file.
- Kept `app/config.local.php` ignored by Git.
- Kept runtime logs and backups ignored by Git.
- Did not run migrations against any database.
- Did not commit secrets.

## Pending review before merge

- Run PHP syntax checks and the package test suite in an isolated environment.
- Confirm the intended target repository and remote before pushing.
- Review the B1–B6 package against the finalized BPMN and ERD before production implementation.
- Resolve any differences between the PHP package's broader module scope and the approved visual prototype.
