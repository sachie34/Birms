-- 011_document_attachments_cash_ledger_signature.sql
-- Private file archive, cash-collection reconciliation, and typed certificate signature snapshot.
ALTER TABLE issuances
    ADD COLUMN signature_text VARCHAR(150) NULL AFTER captain_name;

CREATE TABLE document_attachments (
    attachment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NULL,
    request_id INT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attachment_id),
    KEY idx_attachment_visit (visit_id),
    KEY idx_attachment_request (request_id),
    KEY idx_attachment_uploader (uploaded_by),
    CONSTRAINT fk_attachment_visit FOREIGN KEY (visit_id) REFERENCES visitor_log (visit_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attachment_request FOREIGN KEY (request_id) REFERENCES document_requests (request_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attachment_uploader FOREIGN KEY (uploaded_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
    -- The application deliberately inserts exactly one owner column; MariaDB 10.11 does not permit this FK-column CHECK.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_collection_ledger (
    ledger_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    issuance_id INT UNSIGNED NOT NULL,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requester_name VARCHAR(200) NOT NULL,
    fee_amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash','bank_transfer','other') NOT NULL DEFAULT 'cash',
    collector_id INT UNSIGNED NULL,
    collector_name VARCHAR(100) NOT NULL,
    reconciliation_status ENUM('pending','reconciled') NOT NULL DEFAULT 'pending',
    reconciled_by INT UNSIGNED NULL,
    reconciled_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ledger_id),
    UNIQUE KEY uq_cash_ledger_issuance (issuance_id),
    KEY idx_cash_ledger_date (transaction_date),
    KEY idx_cash_ledger_status (reconciliation_status, transaction_date),
    KEY idx_cash_ledger_collector (collector_id),
    KEY idx_cash_ledger_reconciler (reconciled_by),
    CONSTRAINT fk_cash_ledger_issuance FOREIGN KEY (issuance_id) REFERENCES issuances (issuance_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_cash_ledger_collector FOREIGN KEY (collector_id) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_cash_ledger_reconciler FOREIGN KEY (reconciled_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_cash_ledger_amount CHECK (fee_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
