# XAMPP setup guide moved

This file is retained for compatibility with older links.

**Do not use the installation instructions below from older revisions.** The maintained XAMPP installation guide is now at the repository root:

- [README_XAMPP.md](../README_XAMPP.md)

For an office or production deployment, use the [Production Deployment Runbook](PRODUCTION_DEPLOYMENT_RUNBOOK.md) as well. It separates a local demo/working database from production and lists the manual acceptance gates that must pass before go-live.

The current release candidate is maintained on the `release/deploy-ready` branch and is reviewed through [PR #46](https://github.com/sachie34/Birms/pull/46). Download the exact reviewed branch or approved release tag; do not assume that `main` contains these changes until the pull request is approved and merged.

Never run test suites or demo seeders against a live barangay database. Use an isolated `_work` or `_test` database.
