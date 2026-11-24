-- 021_directory_order_indexes.down.sql
-- Restore the original single-column date indexes.

ALTER TABLE venue_reservations
    DROP INDEX idx_venue_reservation_order,
    ADD KEY idx_venue_reservation_date (reservation_date);

ALTER TABLE blotters
    DROP INDEX idx_blotter_incident_order,
    ADD KEY idx_blotter_incident (incident_date);
