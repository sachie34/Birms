-- 008_request_requirements.sql
-- Add the request pre-check step from the documented workflow without inventing required documents.
ALTER TABLE document_requests
    ADD COLUMN requirements_status ENUM('Not checked','Incomplete','Complete') NOT NULL DEFAULT 'Not checked' AFTER status_note,
    ADD COLUMN requirements_note VARCHAR(1000) NULL AFTER requirements_status;
