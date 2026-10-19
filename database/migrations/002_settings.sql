-- 002_settings.sql  Batch B2: configurable settings. Official details stay EMPTY until you enter them.

CREATE TABLE IF NOT EXISTS settings (
    setting_key    VARCHAR(60) NOT NULL,
    setting_value  TEXT NULL,
    updated_by     INT UNSIGNED NULL,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One table for the admin-managed lists: purok, resident_category, visit_class, id_type.
CREATE TABLE IF NOT EXISTS lookups (
    lookup_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_key    VARCHAR(30)  NOT NULL,
    label       VARCHAR(100) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lookup_id),
    UNIQUE KEY uq_lookup (list_key, label),
    KEY idx_lookup_list (list_key, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fee_amount NULL = "fee not set yet" (pending official figure). template_body NULL = "wording not set yet".
CREATE TABLE IF NOT EXISTS document_types (
    type_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(100) NOT NULL,
    fee_amount     DECIMAL(8,2) NULL,
    template_body  TEXT NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order     INT NOT NULL DEFAULT 0,
    PRIMARY KEY (type_id),
    UNIQUE KEY uq_doctype_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Concurrency-safe running numbers (request, certificate, case, household).
CREATE TABLE IF NOT EXISTS counters (
    counter_name  VARCHAR(30) NOT NULL,
    counter_year  SMALLINT UNSIGNED NOT NULL,
    last_value    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (counter_name, counter_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document names appear in every source document and in ff.pptx. Fees and wording are NOT seeded.
INSERT INTO document_types (name, sort_order) VALUES
    ('Barangay Clearance', 1), ('Certificate of Residency', 2), ('Certificate of Indigency', 3);

-- Place and location come from the project title. Officials, wording and fees stay empty.
INSERT INTO settings (setting_key, setting_value) VALUES
    ('barangay_name', 'Wawa'), ('municipality', 'Balagtas'), ('province', 'Bulacan'),
    ('captain_name', NULL), ('secretary_name', NULL),
    ('age_brackets', '0-17,18-59,60-200'),
    ('sms_ready_text', NULL);
