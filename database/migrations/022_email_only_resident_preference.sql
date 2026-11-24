-- 022_email_only_resident_preference.sql
-- Active resident notification preference is email-only.
-- Historical queue preference snapshots, verification rows, SMS outbox rows and audit history remain unchanged.
UPDATE residents
SET notification_preference = 'email'
WHERE notification_preference <> 'email';

ALTER TABLE residents
    MODIFY COLUMN notification_preference ENUM('email') NOT NULL DEFAULT 'email';
