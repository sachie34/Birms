-- Add the baseline Certified True Copy counter service while preserving the four assigned intake types.
-- The source summary lists it in current operations; the assigned visitor module lists Indigency,
-- Residency, Clearance, and Business. Keep this sample clearly non-official and preserve staff edits.
INSERT INTO document_types (name, sort_order)
SELECT 'Certified True Copy', 5
WHERE NOT EXISTS (
    SELECT 1 FROM document_types WHERE name = 'Certified True Copy'
);

UPDATE document_types
SET fee_amount = 50.00
WHERE name = 'Certified True Copy'
  AND fee_amount IS NULL;

UPDATE document_types
SET template_body = 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL CERTIFICATE. This sample demonstrates a Certified True Copy layout for {{full_name}}. Generated {{date_issued}}. No source document has been authenticated; this output is not valid for official use.'
WHERE name = 'Certified True Copy'
  AND (template_body IS NULL OR TRIM(template_body) = '');
