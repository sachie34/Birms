-- Reverse migration 011. Run only on a disposable *_work or *_test database.
DROP TABLE cash_collection_ledger;
DROP TABLE document_attachments;
ALTER TABLE issuances DROP COLUMN signature_text;
