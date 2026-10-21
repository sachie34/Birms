-- 009_fee_matrix_billing.sql
-- Configurable, effective-dated fee rules. No rates are seeded; only officially approved rates should be added.
CREATE TABLE fee_matrix (
    fee_rule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_id INT UNSIGNED NOT NULL,
    fee_category VARCHAR(100) NOT NULL,
    fee_tier VARCHAR(100) NOT NULL,
    unit_rate DECIMAL(10,2) NOT NULL,
    min_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    max_quantity INT UNSIGNED NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fee_rule_id),
    UNIQUE KEY uq_fee_matrix_effective (type_id, fee_category, fee_tier, effective_from),
    KEY idx_fee_matrix_active (type_id, is_active, effective_from, effective_to),
    KEY idx_fee_matrix_type (type_id),
    KEY idx_fee_matrix_creator (created_by),
    CONSTRAINT fk_fee_matrix_type FOREIGN KEY (type_id) REFERENCES document_types (type_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fee_matrix_creator FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE document_requests
    ADD COLUMN fee_rule_id INT UNSIGNED NULL AFTER requirements_note,
    ADD COLUMN fee_category_snapshot VARCHAR(100) NULL AFTER fee_rule_id,
    ADD COLUMN fee_tier_snapshot VARCHAR(100) NULL AFTER fee_category_snapshot,
    ADD COLUMN fee_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER fee_tier_snapshot,
    ADD COLUMN fee_unit_rate DECIMAL(10,2) NULL AFTER fee_quantity,
    ADD COLUMN quoted_fee_amount DECIMAL(12,2) NULL AFTER fee_unit_rate,
    ADD KEY idx_request_fee_rule (fee_rule_id),
    ADD CONSTRAINT fk_request_fee_rule FOREIGN KEY (fee_rule_id) REFERENCES fee_matrix (fee_rule_id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE issuances
    ADD COLUMN fee_rule_id INT UNSIGNED NULL AFTER fee_amount,
    ADD COLUMN fee_category_snapshot VARCHAR(100) NULL AFTER fee_rule_id,
    ADD COLUMN fee_tier_snapshot VARCHAR(100) NULL AFTER fee_category_snapshot,
    ADD COLUMN fee_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER fee_tier_snapshot,
    ADD COLUMN fee_unit_rate DECIMAL(10,2) NULL AFTER fee_quantity,
    ADD KEY idx_issuance_fee_rule (fee_rule_id),
    ADD CONSTRAINT fk_issuance_fee_rule FOREIGN KEY (fee_rule_id) REFERENCES fee_matrix (fee_rule_id) ON DELETE SET NULL ON UPDATE CASCADE;
