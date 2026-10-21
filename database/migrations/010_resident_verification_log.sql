-- 010_resident_verification_log.sql
-- Manual resident ID / voter-list checks only. This table does not call COMELEC or any external service.
CREATE TABLE resident_verifications (
    verification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resident_id INT UNSIGNED NOT NULL,
    verification_subject ENUM('identity','voter_registration') NOT NULL,
    verification_method ENUM('government_id','barangay_voter_list','other_document') NOT NULL,
    outcome ENUM('verified','unable_to_verify','registered','not_registered') NOT NULL,
    reference_note VARCHAR(150) NULL,
    remarks VARCHAR(500) NULL,
    verified_by INT UNSIGNED NULL,
    verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (verification_id),
    KEY idx_res_verification_history (resident_id, verified_at),
    KEY idx_res_verification_actor (verified_by),
    CONSTRAINT fk_res_verification_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_res_verification_user FOREIGN KEY (verified_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
