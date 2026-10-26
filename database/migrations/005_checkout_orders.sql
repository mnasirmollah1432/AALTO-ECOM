-- =============================================================
-- Phase 7 migration: checkout with name, mobile and address only.
-- Only needed for databases created before Phase 7.
--
-- - customer_email / shipping_city become optional (not collected)
-- - customer_phone becomes required; shipping_address up to 500 chars
-- - payment_method defaults to 'cod' (cash on delivery)
-- - money / quantity sanity CHECKs on orders and order_items
-- - order_sequences: one counter row per day for order numbers
--   like AM-20260924-00001 (incremented atomically inside the
--   order transaction, so numbers are unique even under load)
-- =============================================================

UPDATE `orders` SET `customer_phone` = '' WHERE `customer_phone` IS NULL;
UPDATE `orders` SET `payment_method` = 'cod' WHERE `payment_method` IS NULL;

ALTER TABLE `orders`
    MODIFY `customer_email`   VARCHAR(190) NULL,
    MODIFY `customer_phone`   VARCHAR(20)  NOT NULL,
    MODIFY `shipping_address` VARCHAR(500) NOT NULL,
    MODIFY `shipping_city`    VARCHAR(100) NULL,
    MODIFY `payment_method`   VARCHAR(40)  NOT NULL DEFAULT 'cod' COMMENT 'cod = cash on delivery',
    ADD KEY `idx_orders_phone` (`customer_phone`),
    ADD CONSTRAINT `chk_orders_amounts`
        CHECK (`subtotal` >= 0 AND `shipping_fee` >= 0 AND `discount_total` >= 0 AND `tax_total` >= 0 AND `grand_total` >= 0);

ALTER TABLE `order_items`
    ADD CONSTRAINT `chk_order_items_values`
        CHECK (`quantity` > 0 AND `unit_price` >= 0 AND `line_total` >= 0);

CREATE TABLE IF NOT EXISTS `order_sequences` (
    `seq_date`   DATE          NOT NULL,
    `seq_value`  INT UNSIGNED  NOT NULL,
    PRIMARY KEY (`seq_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
