-- 014_rate_limits.sql
-- Fixed-window counters for request throttling. Only SHA-256 bucket keys are stored; raw user/IP identifiers are not persisted.
CREATE TABLE rate_limit_buckets (
    bucket_hash       CHAR(64)     NOT NULL,
    window_started_at DATETIME     NOT NULL,
    hit_count         INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket_hash, window_started_at),
    KEY idx_rate_limit_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
