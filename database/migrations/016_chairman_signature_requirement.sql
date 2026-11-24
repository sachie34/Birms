-- Per-document switch for the Chairman's stored signature image.
-- Default existing and new document types to require the signature.
ALTER TABLE document_types
    ADD COLUMN requires_chairman_signature TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;
