-- 001_core.sql  Batch B1: accounts, sign-in lockout, audit trail.
-- Safe on a NEW empty database. tools/migrate.php refuses to run on a database that already has tables
-- but no schema_migrations record, so it can never overwrite an existing barangay_db.

CREATE TABLE IF NOT EXISTS users (
    user_id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username              VARCHAR(30)  NOT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    full_name             VARCHAR(100) NOT NULL,
    role                  ENUM('admin','captain','secretary','clerk') NOT NULL,
    status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
    must_change_password  TINYINT(1)   NOT NULL DEFAULT 0,
    email                 VARCHAR(100) NULL,
    contact_number        VARCHAR(20)  NULL,
    last_login_at         DATETIME     NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_failures (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50) NOT NULL,
    ip_address    VARCHAR(45) NOT NULL,
    attempted_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lf_user_time (username, attempted_at),
    KEY idx_lf_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit rows keep a username snapshot and are NEVER cascade-deleted (finding H4).
CREATE TABLE IF NOT EXISTS audit_log (
    audit_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id            INT UNSIGNED NULL,
    username_snapshot  VARCHAR(50)  NULL,
    action             VARCHAR(50)  NOT NULL,
    module             VARCHAR(50)  NOT NULL,
    entity_type        VARCHAR(40)  NULL,
    entity_id          INT UNSIGNED NULL,
    status             ENUM('Success','Failed') NOT NULL DEFAULT 'Success',
    remarks            VARCHAR(1000) NULL,
    ip_address         VARCHAR(45)  NULL,
    PRIMARY KEY (audit_id),
    KEY idx_audit_time (occurred_at),
    KEY idx_audit_user (user_id),
    KEY idx_audit_module_status (module, status),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
