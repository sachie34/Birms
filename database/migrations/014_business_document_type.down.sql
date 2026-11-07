-- Remove the optional Business Clearance type only when no saved visitor/request/issuance uses it.
-- Foreign keys also protect any unexpected reference from deletion.
DELETE FROM document_types
WHERE name = 'Business Clearance'
  AND NOT EXISTS (SELECT 1 FROM visitor_log WHERE visitor_log.document_type_id = document_types.type_id)
  AND NOT EXISTS (SELECT 1 FROM document_requests WHERE document_requests.type_id = document_types.type_id)
  AND NOT EXISTS (SELECT 1 FROM issuances WHERE issuances.type_id = document_types.type_id);
