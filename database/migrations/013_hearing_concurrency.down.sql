-- 013_hearing_concurrency.down.sql
-- Use only on a disposable working/test database after reviewing the consequences.
ALTER TABLE hearings DROP INDEX uq_hearing_case_time_status;
