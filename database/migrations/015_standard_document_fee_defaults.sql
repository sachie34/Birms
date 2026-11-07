-- The professor's required standard document rate is PHP 50.
-- Preserve any fee that an existing barangay database has already configured.
UPDATE document_types
SET fee_amount = 50.00
WHERE name IN ('Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency')
  AND fee_amount IS NULL;
