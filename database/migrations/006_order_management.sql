-- =============================================================
-- Phase 8 migration: admin order management.
-- Only needed for databases created before Phase 8.
--
-- - Order statuses become: pending, confirmed, processing, shipped,
--   delivered, cancelled ("refunded" was never used; any such rows
--   become "cancelled")
-- - order_status_history: who changed an order's status, when, and why
-- - index for the admin date filter
-- =============================================================

UPDATE `orders` SET `status` = 'cancelled' WHERE `status` = 'refunded';

ALTER TABLE `orders`
    MODIFY `status` ENUM('pending','confirmed','processing','shipped','delivered','cancelled')
        NOT NULL DEFAULT 'pending',
    ADD KEY `idx_orders_placed` (`placed_at`);

CREATE TABLE IF NOT EXISTS `order_status_history` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `order_id`     INT UNSIGNED  NOT NULL,
    `from_status`  VARCHAR(20)   NULL COMMENT 'NULL = order placed',
    `to_status`    VARCHAR(20)   NOT NULL,
    `note`         VARCHAR(500)  NULL,
    `admin_id`     INT UNSIGNED  NULL COMMENT 'NULL = customer / system',
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_status_history_order` (`order_id`, `created_at`),
    CONSTRAINT `fk_order_status_history_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_status_history_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing orders get a "placed" entry so every order has a history
INSERT INTO `order_status_history` (`order_id`, `from_status`, `to_status`, `note`, `created_at`)
SELECT o.`id`, NULL, 'pending', 'Order placed', COALESCE(o.`placed_at`, o.`created_at`)
  FROM `orders` o
 WHERE NOT EXISTS (SELECT 1 FROM `order_status_history` h WHERE h.`order_id` = o.`id`);
