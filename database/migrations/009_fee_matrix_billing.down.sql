-- Reverse 009_fee_matrix_billing.sql.
ALTER TABLE issuances
    DROP FOREIGN KEY fk_issuance_fee_rule,
    DROP INDEX idx_issuance_fee_rule,
    DROP COLUMN fee_unit_rate,
    DROP COLUMN fee_quantity,
    DROP COLUMN fee_tier_snapshot,
    DROP COLUMN fee_category_snapshot,
    DROP COLUMN fee_rule_id;

ALTER TABLE document_requests
    DROP FOREIGN KEY fk_request_fee_rule,
    DROP INDEX idx_request_fee_rule,
    DROP COLUMN quoted_fee_amount,
    DROP COLUMN fee_unit_rate,
    DROP COLUMN fee_quantity,
    DROP COLUMN fee_tier_snapshot,
    DROP COLUMN fee_category_snapshot,
    DROP COLUMN fee_rule_id;

DROP TABLE fee_matrix;
