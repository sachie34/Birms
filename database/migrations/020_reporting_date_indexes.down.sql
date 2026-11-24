-- 020_reporting_date_indexes.down.sql
-- Remove only the indexes introduced by migration 020.

ALTER TABLE queue_entries
    DROP INDEX idx_queue_issued_at;

ALTER TABLE venue_reservations
    DROP INDEX idx_venue_reservation_date;

ALTER TABLE document_requests
    DROP INDEX idx_request_requested_at;
