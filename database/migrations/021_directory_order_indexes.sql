-- 021_directory_order_indexes.sql
-- Match the primary sort order used by the blotter and venue directory screens.
-- Each composite index also retains the existing leading date column for date-range filters.

ALTER TABLE blotters
    DROP INDEX idx_blotter_incident,
    ADD KEY idx_blotter_incident_order (incident_date, incident_time, blotter_id);

ALTER TABLE venue_reservations
    DROP INDEX idx_venue_reservation_date,
    ADD KEY idx_venue_reservation_order (reservation_date, start_time, reservation_id);
