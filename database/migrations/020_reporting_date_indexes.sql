-- 020_reporting_date_indexes.sql
-- Add leading indexes for the date-range predicates used by request, venue and queue reports.
-- Existing indexes begin with status/service/venue/resident columns and cannot efficiently
-- serve these report queries when those leading columns are not constrained.

ALTER TABLE document_requests
    ADD KEY idx_request_requested_at (requested_at);

ALTER TABLE venue_reservations
    ADD KEY idx_venue_reservation_date (reservation_date);

ALTER TABLE queue_entries
    ADD KEY idx_queue_issued_at (issued_at),
    ADD KEY idx_queue_date_status (queue_date, status);
