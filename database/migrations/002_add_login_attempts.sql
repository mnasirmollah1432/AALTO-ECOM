-- =============================================================
-- Phase 2 migration: admin login brute-force protection.
-- Only needed for databases created from the Phase 1 schema.sql.
-- New installs get this table from schema.sql already.
-- Safe to run more than once.
-- =============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(190)     NOT NULL,
    `ip_address`    VARCHAR(45)      NOT NULL,
    `attempted_at`  DATETIME         NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`),
    KEY `idx_login_attempts_email_ip` (`email`, `ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
