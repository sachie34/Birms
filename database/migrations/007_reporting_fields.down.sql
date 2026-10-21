-- Reverse 007_reporting_fields.sql.
ALTER TABLE blotters DROP FOREIGN KEY fk_blotter_incident_type, DROP INDEX idx_blotter_incident_type, DROP COLUMN incident_type_id;
ALTER TABLE residents DROP FOREIGN KEY fk_res_employment_status, DROP INDEX idx_res_employment_status, DROP COLUMN employment_status_id;
