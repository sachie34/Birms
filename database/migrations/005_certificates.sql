-- 005_certificates.sql  Batch B5: issued certificates. Each row is a permanent record: names, wording and
-- signatories are SNAPSHOTS taken at issue time, so later edits to settings never change an issued document.

CREATE TABLE IF NOT EXISTS issuances (
    issuance_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no     VARCHAR(20)  NOT NULL,
    request_id       INT UNSIGNED NOT NULL,
    resident_id      INT UNSIGNED NOT NULL,
    type_id          INT UNSIGNED NOT NULL,
    resident_name    VARCHAR(200) NOT NULL,
    type_name        VARCHAR(100) NOT NULL,
    purpose          VARCHAR(255) NOT NULL,
    fee_amount       DECIMAL(8,2) NOT NULL,
    or_number        VARCHAR(30)  NULL,
    body_text        TEXT NOT NULL,
    captain_name     VARCHAR(100) NULL,
    issued_by        INT UNSIGNED NULL,
    issued_by_name   VARCHAR(100) NOT NULL,
    issued_at        DATETIME NOT NULL,
    print_count      INT UNSIGNED NOT NULL DEFAULT 0,
    last_printed_at  DATETIME NULL,
    PRIMARY KEY (issuance_id),
    UNIQUE KEY uq_issuance_ref (reference_no),
    UNIQUE KEY uq_issuance_request (request_id),
    UNIQUE KEY uq_issuance_or (or_number),
    KEY idx_issuance_resident (resident_id),
    KEY idx_issuance_time (issued_at),
    CONSTRAINT fk_iss_request  FOREIGN KEY (request_id)  REFERENCES document_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_iss_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id)         ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_iss_type     FOREIGN KEY (type_id)     REFERENCES document_types (type_id)        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_iss_user     FOREIGN KEY (issued_by)   REFERENCES users (user_id)                 ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
