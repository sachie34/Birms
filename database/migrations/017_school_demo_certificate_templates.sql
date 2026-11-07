-- 017_school_demo_certificate_templates.sql
-- Provide usable, explicitly non-official sample wording for the school project demo.
-- Existing Barangay wording is preserved; these defaults only fill blank templates.
UPDATE document_types
SET template_body = CASE name
    WHEN 'Barangay Clearance' THEN 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL CERTIFICATE. This sample demonstrates a Barangay Clearance layout for {{full_name}}. Stated purpose: {{purpose}}. Generated {{date_issued}}. This document is not valid for official use.'
    WHEN 'Certificate of Residency' THEN 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL CERTIFICATE. This sample demonstrates a Residency Certificate layout for {{full_name}}. Generated {{date_issued}}. It does not certify actual residency and is not valid for official use.'
    WHEN 'Certificate of Indigency' THEN 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL CERTIFICATE. This sample demonstrates an Indigency Certificate layout for {{full_name}}. Generated {{date_issued}}. It does not certify actual indigency and is not valid for official use.'
    WHEN 'Business Clearance' THEN 'SCHOOL PROJECT DEMO - NOT AN OFFICIAL CERTIFICATE. This sample demonstrates a Business Clearance layout for {{full_name}}. Stated purpose: {{purpose}}. Generated {{date_issued}}. It does not certify an actual permit and is not valid for official use.'
END
WHERE name IN ('Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Business Clearance')
  AND (template_body IS NULL OR TRIM(template_body) = '');
