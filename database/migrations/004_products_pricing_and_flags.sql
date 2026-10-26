-- =============================================================
-- Phase 4 migration: product pricing, "new" flag, safety checks.
-- Only needed for databases created before Phase 4.
-- Requires MySQL 8.0.16+ or MariaDB 10.4+ (CHECK constraints and
-- stored generated columns).
--
-- Before: price (selling price) + compare_at_price (old price)
-- After:  regular_price + sale_price (optional)
--         price = generated: sale price when set, above 0 and below
--                 regular; otherwise regular. Read-only, indexed.
--
-- Existing data is converted:
--   compare_at_price > price  ->  regular = compare_at_price, sale = price
--   otherwise                 ->  regular = price, sale = NULL
-- =============================================================

ALTER TABLE `products`
    ADD COLUMN `regular_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `description`,
    ADD COLUMN `sale_price`    DECIMAL(12,2) NULL AFTER `regular_price`,
    ADD COLUMN `is_new`        TINYINT(1)    NOT NULL DEFAULT 0 AFTER `is_featured`;

UPDATE `products`
   SET `regular_price` = IF(`compare_at_price` IS NOT NULL AND `compare_at_price` > `price`, `compare_at_price`, `price`),
       `sale_price`    = IF(`compare_at_price` IS NOT NULL AND `compare_at_price` > `price`, `price`, NULL);

-- Any negative stock left over from earlier data becomes 0
UPDATE `products` SET `stock_quantity` = 0 WHERE `stock_quantity` < 0;

ALTER TABLE `products`
    DROP INDEX `idx_products_price`,
    DROP COLUMN `price`,
    DROP COLUMN `compare_at_price`;

ALTER TABLE `products`
    ADD COLUMN `price` DECIMAL(12,2)
        AS (IF(`sale_price` IS NOT NULL AND `sale_price` > 0 AND `sale_price` < `regular_price`, `sale_price`, `regular_price`)) STORED
        COMMENT 'effective selling price - generated, never written directly'
        AFTER `sale_price`,
    ADD KEY `idx_products_price` (`price`),
    ADD KEY `idx_products_new` (`is_active`, `is_new`, `created_at`),
    ADD CONSTRAINT `chk_products_stock` CHECK (`stock_quantity` >= 0),
    ADD CONSTRAINT `chk_products_regular_price` CHECK (`regular_price` >= 0),
    ADD CONSTRAINT `chk_products_sale_price` CHECK (`sale_price` IS NULL OR `sale_price` >= 0);
