-- 007_reporting_fields.sql
-- Reporting fields required for demographic employment-status statistics and common-incident summaries.
-- Categories are configured by authorized personnel in Settings; no official categories are invented here.

ALTER TABLE residents
    ADD COLUMN employment_status_id INT UNSIGNED NULL AFTER category_id,
    ADD KEY idx_res_employment_status (employment_status_id),
    ADD CONSTRAINT fk_res_employment_status FOREIGN KEY (employment_status_id)
        REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE blotters
    ADD COLUMN incident_type_id INT UNSIGNED NULL AFTER purok_id,
    ADD KEY idx_blotter_incident_type (incident_type_id),
    ADD CONSTRAINT fk_blotter_incident_type FOREIGN KEY (incident_type_id)
        REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE;
