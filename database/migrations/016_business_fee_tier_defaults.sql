-- Seed the professor-required PHP 100-300 business fee range.
-- Tier labels are placeholders until the barangay approves the criteria for assigning a business to a tier.
-- Preserve any fee rule that the barangay has already configured for the same category/tier.
INSERT INTO fee_matrix
    (type_id, fee_category, fee_tier, unit_rate, min_quantity, max_quantity, effective_from, effective_to, is_active, created_by)
SELECT dt.type_id, 'Business', tier.fee_tier, tier.unit_rate, 1, 1, CURRENT_DATE, NULL, 1, NULL
FROM document_types dt
JOIN (
    SELECT 'Tier 1' AS fee_tier, 100.00 AS unit_rate
    UNION ALL SELECT 'Tier 2', 200.00
    UNION ALL SELECT 'Tier 3', 300.00
) AS tier
WHERE dt.name = 'Business Clearance'
  AND dt.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM fee_matrix fm
      WHERE fm.type_id = dt.type_id
        AND fm.fee_category = 'Business'
        AND fm.fee_tier = tier.fee_tier
  );
