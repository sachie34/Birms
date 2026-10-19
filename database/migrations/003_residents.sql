-- 003_residents.sql  Batch B3: households and residents. Residents are archived, never deleted.
-- Purok and category lists live in `lookups` (empty until the barangay enters them).

CREATE TABLE IF NOT EXISTS households (
    household_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_code  VARCHAR(20)  NOT NULL,
    purok_id        INT UNSIGNED NULL,
    street_address  VARCHAR(150) NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (household_id),
    UNIQUE KEY uq_household_code (household_code),
    KEY idx_household_purok (purok_id),
    CONSTRAINT fk_household_purok FOREIGN KEY (purok_id) REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS residents (
    resident_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    household_id       INT UNSIGNED NULL,
    last_name          VARCHAR(50)  NOT NULL,
    first_name         VARCHAR(60)  NOT NULL,
    middle_name        VARCHAR(50)  NULL,
    suffix             VARCHAR(10)  NULL,
    birthdate          DATE         NOT NULL,
    sex                ENUM('Male','Female') NOT NULL,
    civil_status       ENUM('Single','Married','Widowed','Separated','Divorced') NOT NULL,
    contact_number     VARCHAR(20)  NULL,
    category_id        INT UNSIGNED NULL,
    residency_status   ENUM('Active','Relocated') NOT NULL DEFAULT 'Active',
    voter_status       ENUM('Unverified','Registered','Not registered') NOT NULL DEFAULT 'Unverified',
    is_household_head  TINYINT(1)   NOT NULL DEFAULT 0,
    created_by         INT UNSIGNED NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at        DATETIME NULL,
    PRIMARY KEY (resident_id),
    KEY idx_res_name (last_name, first_name),
    KEY idx_res_household (household_id),
    KEY idx_res_status (residency_status, archived_at),
    KEY idx_res_birthdate (birthdate),
    CONSTRAINT fk_res_household FOREIGN KEY (household_id) REFERENCES households (household_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_res_category  FOREIGN KEY (category_id)  REFERENCES lookups (lookup_id)        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_res_created   FOREIGN KEY (created_by)   REFERENCES users (user_id)            ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
