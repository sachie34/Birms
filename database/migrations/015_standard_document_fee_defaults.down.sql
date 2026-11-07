-- Restore NULL only when the default has not been used by any saved request or issuance.
-- Existing/custom rates and fee snapshots are preserved.
UPDATE document_types AS dt
SET fee_amount = NULL
WHERE dt.name IN ('Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency')
  AND dt.fee_amount = 50.00
  AND NOT EXISTS (SELECT 1 FROM document_requests dr WHERE dr.type_id = dt.type_id)
  AND NOT EXISTS (SELECT 1 FROM issuances i WHERE i.type_id = dt.type_id);
