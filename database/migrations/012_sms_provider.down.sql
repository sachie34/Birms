-- 012_sms_provider.down.sql
-- Use only on a disposable working/test database after reviewing the consequences.
ALTER TABLE sms_outbox
    DROP INDEX idx_sms_provider_status_time,
    DROP INDEX idx_sms_provider_message,
    DROP COLUMN updated_at,
    DROP COLUMN last_attempt_at,
    DROP COLUMN attempt_count,
    DROP COLUMN provider_error,
    DROP COLUMN provider_status,
    DROP COLUMN provider_message_id,
    DROP COLUMN provider_name;
