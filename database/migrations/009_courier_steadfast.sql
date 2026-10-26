-- =============================================================
-- 009 - Courier (Steadfast) on orders + courier event log.
--
-- For an EXISTING database (e.g. the live site). Run ONCE in
-- phpMyAdmin -> your database -> SQL (or Import this file).
-- The database user needs ALTER/CREATE/REFERENCES while this runs
-- (cPanel -> MySQL Databases -> ALL PRIVILEGES, then set it back).
-- API keys and the webhook token are saved in `settings` from
-- Admin -> Courier (Steadfast); nothing to insert here.
-- =============================================================

SET NAMES utf8mb4;

ALTER TABLE `orders`
    ADD COLUMN `courier`                VARCHAR(30)   NULL COMMENT 'steadfast; "sending" while the request runs' AFTER `delivery_zone`,
    ADD COLUMN `courier_consignment_id` VARCHAR(40)   NULL AFTER `courier`,
    ADD COLUMN `courier_tracking_code`  VARCHAR(60)   NULL AFTER `courier_consignment_id`,
    ADD COLUMN `courier_status`         VARCHAR(60)   NULL COMMENT 'last status from the courier, e.g. in_review, delivered' AFTER `courier_tracking_code`,
    ADD COLUMN `courier_cod_amount`     DECIMAL(12,2) NULL COMMENT 'cash the courier collects' AFTER `courier_status`,
    ADD COLUMN `courier_sent_at`        DATETIME      NULL AFTER `courier_cod_amount`,
    ADD COLUMN `courier_updated_at`     DATETIME      NULL AFTER `courier_sent_at`,
    ADD UNIQUE KEY `uq_orders_consignment` (`courier`, `courier_consignment_id`),
    ADD KEY `idx_orders_courier_status` (`courier_status`);

-- -------------------------------------------------------------
-- courier_events: every send / status check / webhook, for support
-- -------------------------------------------------------------
CREATE TABLE `courier_events` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`       INT UNSIGNED  NULL,
    `courier`        VARCHAR(30)   NOT NULL DEFAULT 'steadfast',
    `source`         ENUM('send','check','webhook') NOT NULL,
    `consignment_id` VARCHAR(40)   NULL,
    `status`         VARCHAR(60)   NULL,
    `message`        VARCHAR(255)  NULL,
    `payload`        TEXT          NULL COMMENT 'response / webhook body (JSON), no API keys',
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_courier_events_order` (`order_id`, `id`),
    CONSTRAINT `fk_courier_events_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
