-- Remove only the untouched demo defaults and unused service row; preserve staff data.
UPDATE document_types
SET template_body = NULL
WHERE name = 'Certified True Copy'
  AND template_body LIKE 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL CERTIFICATE.%';

UPDATE document_types AS dt
SET fee_amount = NULL
WHERE dt.name = 'Certified True Copy'
  AND dt.fee_amount = 50.00
  AND NOT EXISTS (SELECT 1 FROM document_requests dr WHERE dr.type_id = dt.type_id)
  AND NOT EXISTS (SELECT 1 FROM issuances i WHERE i.type_id = dt.type_id);

DELETE FROM document_types
WHERE name = 'Certified True Copy'
  AND NOT EXISTS (SELECT 1 FROM visitor_log WHERE visitor_log.document_type_id = document_types.type_id)
  AND NOT EXISTS (SELECT 1 FROM document_requests WHERE document_requests.type_id = document_types.type_id)
  AND NOT EXISTS (SELECT 1 FROM issuances WHERE issuances.type_id = document_types.type_id)
  AND NOT EXISTS (SELECT 1 FROM fee_matrix WHERE fee_matrix.type_id = document_types.type_id);
