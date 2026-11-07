-- 013_visitor_intake_document_type.sql
-- Capture the requested document/service at the front counter and preserve it on the visitor log.
-- Existing visitor records remain valid; their document_type_id is NULL until the barangay updates them.
ALTER TABLE visitor_log
    ADD COLUMN document_type_id INT UNSIGNED NULL AFTER class_id,
    ADD KEY idx_visit_document_type (document_type_id),
    ADD CONSTRAINT fk_visit_document_type
        FOREIGN KEY (document_type_id) REFERENCES document_types (type_id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
