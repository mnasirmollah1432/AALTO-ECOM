-- =============================================================
-- 008 - Homepage slider and side banner (Admin -> Home slider).
--
-- For an EXISTING database (e.g. the live site). Run ONCE in
-- phpMyAdmin -> your database -> Import (or SQL).
-- The database user needs CREATE while this runs (cPanel -> MySQL
-- Databases -> tick ALL PRIVILEGES, then set it back afterwards).
-- Contact details, social links and the chat button are stored in
-- the existing `settings` table (no change needed).
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE `home_slides` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `placement`   ENUM('slider','side') NOT NULL DEFAULT 'slider' COMMENT 'slider = rotating banner; side = banner beside it (desktop)',
    `image_path`  VARCHAR(255)     NOT NULL,
    `title`       VARCHAR(120)     NULL COMMENT 'alt text / accessible name',
    `link_url`    VARCHAR(255)     NULL COMMENT '/path on this site or https:// address',
    `sort_order`  INT              NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_home_slides_show` (`is_active`, `placement`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
