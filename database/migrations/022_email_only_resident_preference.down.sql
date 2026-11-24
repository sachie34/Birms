-- 022_email_only_resident_preference.down.sql
-- Restore the legacy column's accepted values for rollback compatibility.
-- Existing resident values remain email; historical SMS records are preserved separately.
ALTER TABLE residents
    MODIFY COLUMN notification_preference ENUM('email','sms','both') NOT NULL DEFAULT 'email';
