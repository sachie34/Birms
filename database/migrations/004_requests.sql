-- 004_requests.sql  Batch B4: visitor logbook, document requests, SMS log-only outbox.
-- Statuses follow the Project Summary (Pending, Review, Ready, Claimed) plus Rejected and Cancelled.

CREATE TABLE IF NOT EXISTS visitor_log (
    visit_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visited_at    DATETIME NOT NULL,
    visitor_name  VARCHAR(100) NOT NULL,
    purpose       VARCHAR(150) NOT NULL,
    class_id      INT UNSIGNED NULL,
    logged_by     INT UNSIGNED NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (visit_id),
    KEY idx_visit_time (visited_at),
    KEY idx_visit_name (visitor_name),
    CONSTRAINT fk_visit_class FOREIGN KEY (class_id)  REFERENCES lookups (lookup_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_visit_user  FOREIGN KEY (logged_by) REFERENCES users (user_id)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_requests (
    request_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no  VARCHAR(20)  NOT NULL,
    visit_id      INT UNSIGNED NULL,
    resident_id   INT UNSIGNED NOT NULL,
    type_id       INT UNSIGNED NOT NULL,
    purpose       VARCHAR(255) NOT NULL,
    status        ENUM('Pending','Review','Ready','Claimed','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    status_note   VARCHAR(255) NULL,
    assigned_to   INT UNSIGNED NULL,
    requested_at  DATETIME NOT NULL,
    created_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    UNIQUE KEY uq_request_ref (reference_no),
    KEY idx_request_status (status, requested_at),
    KEY idx_request_resident (resident_id),
    CONSTRAINT fk_req_visit    FOREIGN KEY (visit_id)    REFERENCES visitor_log (visit_id)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_req_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id)    ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_req_type     FOREIGN KEY (type_id)     REFERENCES document_types (type_id)   ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_req_assigned FOREIGN KEY (assigned_to) REFERENCES users (user_id)            ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_req_creator  FOREIGN KEY (created_by)  REFERENCES users (user_id)            ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS is LOG-ONLY (decision D7): messages are recorded here and never sent.
CREATE TABLE IF NOT EXISTS sms_outbox (
    sms_id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipient_number  VARCHAR(20) NULL,
    message           VARCHAR(480) NOT NULL,
    trigger_event     VARCHAR(40)  NOT NULL,
    request_id        INT UNSIGNED NULL,
    hearing_id        INT UNSIGNED NULL,
    delivery          ENUM('Logged only','No number') NOT NULL DEFAULT 'Logged only',
    PRIMARY KEY (sms_id),
    KEY idx_sms_time (created_at),
    KEY idx_sms_request (request_id),
    CONSTRAINT fk_sms_request FOREIGN KEY (request_id) REFERENCES document_requests (request_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
