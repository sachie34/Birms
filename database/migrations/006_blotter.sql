-- 006_blotter.sql  Batch B6: blotter cases, parties and hearings.
-- Statuses follow the Project Summary (Pending, Active, Scheduled, Resolved). Cases are archived, never deleted:
-- every foreign key below is RESTRICT, so a case with parties or hearings cannot be removed by accident.
-- Summons wording is NOT seeded: the Secretary enters the official text (Settings, Hearing summons).

CREATE TABLE IF NOT EXISTS blotters (
    blotter_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_no          VARCHAR(20)  NOT NULL,
    incident_date    DATE         NOT NULL,
    incident_place   VARCHAR(150) NULL,
    purok_id         INT UNSIGNED NULL,
    narrative        TEXT         NOT NULL,
    status           ENUM('Pending','Active','Scheduled','Resolved') NOT NULL DEFAULT 'Pending',
    resolution_note  VARCHAR(500) NULL,
    resolved_at      DATETIME     NULL,
    archived_at      DATETIME     NULL,
    recorded_at      DATETIME     NOT NULL,
    recorded_by      INT UNSIGNED NULL,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (blotter_id),
    UNIQUE KEY uq_blotter_case (case_no),
    KEY idx_blotter_status (status, recorded_at),
    KEY idx_blotter_incident (incident_date),
    KEY idx_blotter_purok (purok_id),
    CONSTRAINT fk_blotter_purok FOREIGN KEY (purok_id)    REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_blotter_user  FOREIGN KEY (recorded_by) REFERENCES users (user_id)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blotter_parties (
    party_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    blotter_id      INT UNSIGNED NOT NULL,
    party_role      ENUM('Complainant','Respondent') NOT NULL,
    full_name       VARCHAR(100) NOT NULL,
    age             TINYINT UNSIGNED NULL,
    address         VARCHAR(255) NULL,
    contact_number  VARCHAR(20)  NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (party_id),
    KEY idx_party_case (blotter_id, party_role),
    KEY idx_party_name (full_name),
    CONSTRAINT fk_party_case FOREIGN KEY (blotter_id) REFERENCES blotters (blotter_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hearings (
    hearing_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    blotter_id     INT UNSIGNED NOT NULL,
    scheduled_at   DATETIME     NOT NULL,
    venue          VARCHAR(150) NULL,
    notes          VARCHAR(500) NULL,
    status         ENUM('Scheduled','Held','Postponed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    status_note    VARCHAR(255) NULL,
    summons_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (hearing_id),
    KEY idx_hearing_case (blotter_id, scheduled_at),
    KEY idx_hearing_status (status, scheduled_at),
    CONSTRAINT fk_hearing_case FOREIGN KEY (blotter_id) REFERENCES blotters (blotter_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_hearing_user FOREIGN KEY (created_by) REFERENCES users (user_id)       ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('summons_wording', NULL);
