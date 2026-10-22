-- 012_sms_provider.sql
-- Adds delivery audit fields for the optional, explicitly enabled Semaphore or Infobip SMS provider.
-- Existing log-only rows and their legacy delivery values are preserved.

ALTER TABLE sms_outbox
    ADD COLUMN provider_name VARCHAR(30) NULL AFTER delivery,
    ADD COLUMN provider_message_id VARCHAR(80) NULL AFTER provider_name,
    ADD COLUMN provider_status ENUM('not_configured','no_number','not_attempted','sending','queued','pending','sent','failed','refunded','unknown') NOT NULL DEFAULT 'not_configured' AFTER provider_message_id,
    ADD COLUMN provider_error VARCHAR(1000) NULL AFTER provider_status,
    ADD COLUMN attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER provider_error,
    ADD COLUMN last_attempt_at DATETIME NULL AFTER attempt_count,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_attempt_at,
    ADD KEY idx_sms_provider_status_time (provider_status, created_at),
    ADD KEY idx_sms_provider_message (provider_message_id);
