-- Remove actor identity snapshots added by migration 018.
ALTER TABLE audit_log
    DROP COLUMN role_snapshot,
    DROP COLUMN full_name_snapshot;
