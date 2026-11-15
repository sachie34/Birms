-- 013_hearing_concurrency.sql
-- Prevent duplicate hearing entries with the same case, time and state at the database layer.
-- Before applying to existing data, inspect duplicate slots with:
-- SELECT blotter_id, scheduled_at, status, COUNT(*) AS total
-- FROM hearings GROUP BY blotter_id, scheduled_at, status HAVING COUNT(*) > 1;
-- Resolve any existing duplicates after human review; this migration never deletes hearing history.
ALTER TABLE hearings
    ADD UNIQUE KEY uq_hearing_case_time_status (blotter_id, scheduled_at, status);
