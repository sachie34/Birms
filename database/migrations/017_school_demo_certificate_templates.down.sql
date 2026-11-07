-- Remove only untouched school-demo defaults; preserve wording edited after migration.
UPDATE document_types
SET template_body = NULL
WHERE name IN ('Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Business Clearance')
  AND template_body LIKE 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL CERTIFICATE.%';
