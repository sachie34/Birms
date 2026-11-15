-- 015_msit_srs_foundation.sql
-- Additive foundation for the revised MSIT SRS.
-- Legacy household, request and blotter columns are retained during the application transition.
-- No resident, request, hearing or blotter rows are deleted by this migration.

ALTER TABLE residents
    ADD COLUMN resident_no VARCHAR(24) NULL AFTER resident_id,
    ADD COLUMN address VARCHAR(255) NULL AFTER resident_no,
    ADD COLUMN purok_id INT UNSIGNED NULL AFTER address,
    ADD COLUMN resident_status_id INT UNSIGNED NULL AFTER purok_id,
    ADD COLUMN marital_status VARCHAR(30) NULL AFTER civil_status,
    ADD COLUMN resident_type_id INT UNSIGNED NULL AFTER resident_status_id,
    ADD COLUMN email_address VARCHAR(100) NULL AFTER contact_number,
    ADD COLUMN photo_path VARCHAR(255) NULL AFTER email_address,
    ADD COLUMN notification_preference ENUM('email','sms','both') NOT NULL DEFAULT 'email' AFTER photo_path;

INSERT IGNORE INTO lookups (list_key, label, sort_order, is_active) VALUES
    ('resident_status', 'Active', 1, 1),
    ('resident_status', 'Transferred', 2, 1),
    ('resident_status', 'Deceased', 3, 1),
    ('resident_status', 'Non-resident', 4, 1),
    ('resident_type', 'PWD', 1, 1),
    ('resident_type', 'Senior Citizen', 2, 1),
    ('resident_type', 'Solo Parent', 3, 1),
    ('resident_type', '4Ps Beneficiary', 4, 1),
    ('resident_type', 'Indigent', 5, 1),
    ('resident_type', 'None', 6, 1);

-- Preserve the former household address and Purok on every resident record.
UPDATE residents r
LEFT JOIN households h ON h.household_id = r.household_id
SET r.address = COALESCE(NULLIF(r.address, ''), h.street_address),
    r.purok_id = COALESCE(r.purok_id, h.purok_id);

-- Give existing residents a stable, unique public-facing Resident ID.
UPDATE residents
SET resident_no = CONCAT('RES-', LPAD(resident_id, 8, '0'))
WHERE resident_no IS NULL OR resident_no = '';

-- Keep the previous civil_status field for compatibility and copy it to the SRS marital_status field.
UPDATE residents
SET marital_status = COALESCE(NULLIF(marital_status, ''), civil_status)
WHERE marital_status IS NULL OR marital_status = '';

-- Relocated was the previous equivalent of Transferred. Other old Active records remain Active.
UPDATE residents r
JOIN lookups s
  ON s.list_key = 'resident_status'
 AND s.label = CASE WHEN r.residency_status = 'Relocated' THEN 'Transferred' ELSE 'Active' END
SET r.resident_status_id = s.lookup_id
WHERE r.resident_status_id IS NULL;

-- Match existing resident categories to the revised type list by label.
UPDATE residents r
JOIN lookups old_type ON old_type.lookup_id = r.category_id AND old_type.list_key = 'resident_category'
JOIN lookups new_type ON new_type.list_key = 'resident_type' AND LOWER(new_type.label) = LOWER(old_type.label)
SET r.resident_type_id = new_type.lookup_id
WHERE r.resident_type_id IS NULL;

-- Preserve an explicit "None" classification for rows with no matching previous category.
UPDATE residents r
JOIN lookups no_type ON no_type.list_key = 'resident_type' AND no_type.label = 'None'
SET r.resident_type_id = no_type.lookup_id
WHERE r.resident_type_id IS NULL;

ALTER TABLE residents
    MODIFY COLUMN resident_no VARCHAR(24) NOT NULL,
    MODIFY COLUMN resident_status_id INT UNSIGNED NOT NULL,
    MODIFY COLUMN resident_type_id INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_resident_no (resident_no),
    ADD KEY idx_resident_purok_status_type (purok_id, resident_status_id, resident_type_id, archived_at),
    ADD KEY idx_resident_type_srs (resident_type_id),
    ADD CONSTRAINT fk_resident_purok_srs FOREIGN KEY (purok_id) REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_resident_status_srs FOREIGN KEY (resident_status_id) REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_resident_type_srs FOREIGN KEY (resident_type_id) REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Keep the revised document status alongside the previous workflow until all pages use it.
ALTER TABLE document_requests
    ADD COLUMN msit_status ENUM('Pending','Approved','Rejected','Released') NOT NULL DEFAULT 'Pending' AFTER status_note;

UPDATE document_requests
SET msit_status = CASE status
    WHEN 'Pending' THEN 'Pending'
    WHEN 'Review' THEN 'Pending'
    WHEN 'Ready' THEN 'Approved'
    WHEN 'Claimed' THEN 'Released'
    WHEN 'Rejected' THEN 'Rejected'
    WHEN 'Cancelled' THEN 'Rejected'
    ELSE 'Pending'
END;

ALTER TABLE document_requests
    ADD KEY idx_request_msit_status (msit_status, requested_at);

-- Retain a revised blotter state without destroying the legacy status/history fields.
ALTER TABLE blotters
    ADD COLUMN msit_status ENUM('Ongoing','Unresolved','Resolved','Dismissed','Archived') NOT NULL DEFAULT 'Ongoing' AFTER status,
    ADD COLUMN archived_from_status ENUM('Resolved','Dismissed') NULL AFTER msit_status;

UPDATE blotters
SET msit_status = 'Archived',
    archived_from_status = 'Resolved',
    archived_at = COALESCE(archived_at, NOW())
WHERE status = 'Resolved';

UPDATE blotters
SET msit_status = 'Ongoing'
WHERE status IN ('Pending','Active','Scheduled');

ALTER TABLE blotters
    ADD KEY idx_blotter_msit_status (msit_status, incident_date);

-- Venue availability is automatic by default; Staff may later set an explicit override.
CREATE TABLE venues (
    venue_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    availability_override ENUM('automatic','available','in_use') NOT NULL DEFAULT 'automatic',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (venue_id),
    UNIQUE KEY uq_venue_name (name),
    KEY idx_venue_active (is_active, availability_override),
    CONSTRAINT fk_venue_created_by FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venue_reservations (
    reservation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(24) NOT NULL,
    venue_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    reservation_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
    status_note VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reservation_id),
    UNIQUE KEY uq_venue_reservation_ref (reference_no),
    KEY idx_venue_reservation_slot (venue_id, reservation_date, start_time, end_time, status),
    KEY idx_venue_reservation_resident (resident_id, created_at),
    CONSTRAINT fk_venue_reservation_venue FOREIGN KEY (venue_id) REFERENCES venues (venue_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_venue_reservation_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_venue_reservation_creator FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each service has its own daily counter and numbering sequence.
CREATE TABLE queue_counters (
    service ENUM('Blotter','Documents','Venue') NOT NULL,
    queue_date DATE NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (service, queue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE queue_entries (
    queue_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service ENUM('Blotter','Documents','Venue') NOT NULL,
    queue_date DATE NOT NULL,
    queue_number INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NULL,
    reference_no VARCHAR(24) NULL,
    status ENUM('Waiting','Called','Served','Skipped','Cancelled') NOT NULL DEFAULT 'Waiting',
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    called_at DATETIME NULL,
    served_at DATETIME NULL,
    issued_via ENUM('kiosk','qr','staff') NOT NULL DEFAULT 'kiosk',
    called_by INT UNSIGNED NULL,
    served_by INT UNSIGNED NULL,
    notification_preference ENUM('email','sms','both') NOT NULL DEFAULT 'email',
    PRIMARY KEY (queue_id),
    UNIQUE KEY uq_queue_service_day_number (service, queue_date, queue_number),
    KEY idx_queue_current (service, queue_date, status, queue_number),
    KEY idx_queue_resident (resident_id, issued_at),
    CONSTRAINT fk_queue_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_queue_called_by FOREIGN KEY (called_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_queue_served_by FOREIGN KEY (served_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared history for document, blotter and venue request status changes.
CREATE TABLE request_status_history (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service ENUM('Documents','Blotter','Venue') NOT NULL,
    reference_no VARCHAR(24) NOT NULL,
    previous_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NOT NULL,
    changed_by INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(1000) NULL,
    request_id INT UNSIGNED NULL,
    blotter_id INT UNSIGNED NULL,
    reservation_id INT UNSIGNED NULL,
    PRIMARY KEY (history_id),
    KEY idx_request_history_ref (service, reference_no, changed_at),
    KEY idx_request_history_actor (changed_by, changed_at),
    CONSTRAINT fk_request_history_user FOREIGN KEY (changed_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_request_history_document FOREIGN KEY (request_id) REFERENCES document_requests (request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_request_history_blotter FOREIGN KEY (blotter_id) REFERENCES blotters (blotter_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_request_history_reservation FOREIGN KEY (reservation_id) REFERENCES venue_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed an auditable starting point for old records; earlier transitions are not recoverable from the old tables.
INSERT INTO request_status_history (service, reference_no, previous_status, new_status, changed_by, changed_at, note, request_id)
SELECT 'Documents', reference_no, NULL, msit_status, created_by, requested_at,
       'Initial SRS status copied from the previous workflow; earlier transitions are unavailable.', request_id
FROM document_requests;

INSERT INTO request_status_history (service, reference_no, previous_status, new_status, changed_by, changed_at, note, blotter_id)
SELECT 'Blotter', case_no, NULL, msit_status, recorded_by, recorded_at,
       'Initial SRS status copied from the previous workflow; earlier transitions are unavailable.', blotter_id
FROM blotters;

-- Store short-lived hashed one-time codes for resident identity fallback.
CREATE TABLE resident_verification_challenges (
    challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenge_token_hash CHAR(64) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    channel ENUM('sms','email') NOT NULL,
    destination_snapshot VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (challenge_id),
    UNIQUE KEY uq_resident_challenge_token (challenge_token_hash),
    KEY idx_resident_challenge_expiry (expires_at, consumed_at),
    KEY idx_resident_challenge_resident (resident_id, created_at),
    CONSTRAINT fk_resident_challenge_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email is the default channel; SMS remains optional and can be selected per resident.
CREATE TABLE notification_outbox (
    notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resident_id INT UNSIGNED NULL,
    channel ENUM('email','sms') NOT NULL DEFAULT 'email',
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(200) NULL,
    message TEXT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    reference_no VARCHAR(24) NULL,
    delivery_status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(1000) NULL,
    provider_message_id VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (notification_id),
    KEY idx_notification_delivery (delivery_status, created_at),
    KEY idx_notification_resident (resident_id, created_at),
    KEY idx_notification_reference (reference_no),
    CONSTRAINT fk_notification_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the signature used at issuance time so a later upload does not silently change old certificates.
ALTER TABLE issuances
    ADD COLUMN chairman_signature_path VARCHAR(255) NULL AFTER signature_text;

INSERT INTO settings (setting_key, setting_value) VALUES ('chairman_signature_path', NULL)
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
