-- 015_msit_srs_foundation.down.sql
-- Destructive rollback: use only on a disposable *_work or *_test database.
-- This removes all data stored only in the revised foundation tables/columns.
DROP TABLE IF EXISTS notification_outbox;
DROP TABLE IF EXISTS resident_verification_challenges;
DROP TABLE IF EXISTS queue_entries;
DROP TABLE IF EXISTS queue_counters;
-- This history table has a foreign key to venue_reservations, so drop it first.
DROP TABLE IF EXISTS request_status_history;
DROP TABLE IF EXISTS venue_reservations;
DROP TABLE IF EXISTS venues;

ALTER TABLE issuances DROP COLUMN chairman_signature_path;
DELETE FROM settings WHERE setting_key = 'chairman_signature_path';

ALTER TABLE blotters
    DROP INDEX idx_blotter_msit_status,
    DROP COLUMN archived_from_status,
    DROP COLUMN msit_status;

ALTER TABLE document_requests
    DROP INDEX idx_request_msit_status,
    DROP COLUMN msit_status;

ALTER TABLE residents
    DROP FOREIGN KEY fk_resident_purok_srs,
    DROP FOREIGN KEY fk_resident_status_srs,
    DROP FOREIGN KEY fk_resident_type_srs,
    DROP INDEX uq_resident_no,
    DROP INDEX idx_resident_purok_status_type,
    DROP INDEX idx_resident_type_srs,
    DROP COLUMN resident_no,
    DROP COLUMN address,
    DROP COLUMN purok_id,
    DROP COLUMN resident_status_id,
    DROP COLUMN marital_status,
    DROP COLUMN resident_type_id,
    DROP COLUMN email_address,
    DROP COLUMN photo_path,
    DROP COLUMN notification_preference;

DELETE FROM lookups WHERE list_key IN ('resident_status','resident_type');
