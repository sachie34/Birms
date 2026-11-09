-- Preserve who performed each audit event, including the person's role at that time.
ALTER TABLE audit_log
    ADD COLUMN full_name_snapshot VARCHAR(100) NULL AFTER username_snapshot,
    ADD COLUMN role_snapshot VARCHAR(30) NULL AFTER full_name_snapshot;
