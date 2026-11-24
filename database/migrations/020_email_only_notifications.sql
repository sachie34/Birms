-- 020_email_only_notifications.sql
-- The current BIRMS deployment uses email notifications only.
-- Preserve historical SMS outbox rows for audit; never send or retry them.
-- Normalize old resident/ticket channel choices and invalidate outstanding OTP challenges
-- that may have been delivered through the retired SMS workflow.

UPDATE residents
SET notification_preference = 'email'
WHERE notification_preference <> 'email';

UPDATE queue_entries
SET notification_preference = 'email'
WHERE notification_preference <> 'email';

UPDATE resident_verification_challenges
SET consumed_at = COALESCE(consumed_at, NOW())
WHERE consumed_at IS NULL;
