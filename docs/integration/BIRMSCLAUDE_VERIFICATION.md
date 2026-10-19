# birmsclaude.zip Verification

The uploaded `birmsclaude.zip` was compared with the previously staged `Claudecodebirms.zip` source bundle.

The embedded `birms_work_B1-B6.zip` files have identical SHA-256 checksums:

```text
5436762aca18bed511b5deb4b2618fc9351e1bf6fda3a76a1e89678c74f08011
```

The outer documentation files `MANIFEST.txt`, `DESIGN.md`, `DECISIONS.md`, `MASTER_CHECKLIST.md`, and `BIRMS_README_XAMPP_B6.md` also have identical checksums between the two uploads.

The B1–B6 application source already exists in this repository from commit `c051e62ce7b27e5475d520fdcb3fd83ee49bb6d7`. The new archive therefore required no duplicate code import and no overwrite. The only differences from the staged package are the repository's integration documentation and the intentionally excluded runtime log file.

Decision: treat `birmsclaude.zip` as verified duplicate source; preserve the existing canonical import and avoid a redundant code commit.
