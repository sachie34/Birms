-- Roll back the visitor service selector. Existing visitor selections are intentionally discarded.
ALTER TABLE visitor_log
    DROP FOREIGN KEY fk_visit_document_type,
    DROP INDEX idx_visit_document_type,
    DROP COLUMN document_type_id;
