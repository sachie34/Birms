-- Remove only unused default rows. A fee rule referenced by a saved request or issuance is retained.
DELETE FROM fee_matrix
WHERE fee_category = 'Business'
  AND fee_tier IN ('Tier 1', 'Tier 2', 'Tier 3')
  AND unit_rate IN (100.00, 200.00, 300.00)
  AND created_by IS NULL
  AND NOT EXISTS (SELECT 1 FROM document_requests dr WHERE dr.fee_rule_id = fee_matrix.fee_rule_id)
  AND NOT EXISTS (SELECT 1 FROM issuances i WHERE i.fee_rule_id = fee_matrix.fee_rule_id);
