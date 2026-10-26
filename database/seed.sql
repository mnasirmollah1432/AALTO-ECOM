-- =============================================================
-- Aalto Mart - sample seed data (development / demo only)
-- Run AFTER schema.sql on an empty database.
--
-- All data here is fictional. Emails use the reserved
-- example.com domain; SKUs start with "SMP-" so sample rows are
-- easy to find and delete later:
--   DELETE FROM products WHERE sku LIKE 'SMP-%';
-- =============================================================

SET NAMES utf8mb4;

-- -------------------------------------------------------------
-- Admin user
--   email:    admin@example.com
--   password: Admin@12345   <-- CHANGE THIS IMMEDIATELY
-- The value below is a bcrypt hash produced by PHP password_hash();
-- the plain-text password is not stored anywhere in the database.
-- See README.md > "Admin password" to set your own.
-- -------------------------------------------------------------
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `is_active`) VALUES
(1, 'Store Admin', 'admin@example.com',
 '$2y$10$fKUVmsUtBAe5q6LQ3FyTIuO6.9qKAoNdeX3nRcqwJmK/iWWcwkyNC',
 'admin', 1);

-- -------------------------------------------------------------
-- Categories (5)
-- -------------------------------------------------------------
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `icon`, `sort_order`) VALUES
(1, 'Electronics',     'electronics',     'Sample category: gadgets, audio and accessories.', 'bi-phone',        1),
(2, 'Fashion',         'fashion',         'Sample category: clothing, bags and footwear.',    'bi-bag-heart',    2),
(3, 'Home & Kitchen',  'home-kitchen',    'Sample category: cookware, decor and storage.',    'bi-house-heart',  3),
(4, 'Groceries',       'groceries',       'Sample category: pantry staples and snacks.',      'bi-basket',       4),
(5, 'Health & Beauty', 'health-beauty',   'Sample category: personal care and wellness.',     'bi-heart-pulse',  5);

-- -------------------------------------------------------------
-- Products (25 = 5 per category)
-- created_at is staggered so "new arrivals" ordering is stable.
-- regular_price is the normal price; sale_price (optional) is the
-- discounted price. The newest 6 products are flagged is_new.
-- -------------------------------------------------------------
INSERT INTO `products`
(`category_id`, `sku`, `name`, `slug`, `short_description`, `description`,
 `regular_price`, `sale_price`, `stock_quantity`, `is_featured`, `is_new`, `created_at`)
VALUES
-- Electronics
(1, 'SMP-ELE-001', 'Sample Wireless Earbuds',      'sample-wireless-earbuds',      'Bluetooth earbuds with charging case. (Sample product)',        'Sample product for development and testing only.',  2999.00, 2499.00,  40, 1, 1, NOW() - INTERVAL 1 DAY),
(1, 'SMP-ELE-002', 'Sample Smart Watch',           'sample-smart-watch',           'Fitness tracking and notifications. (Sample product)',          'Sample product for development and testing only.',  4999.00,    NULL,  25, 1, 1, NOW() - INTERVAL 2 DAY),
(1, 'SMP-ELE-003', 'Sample Power Bank 10000mAh',   'sample-power-bank-10000mah',   'Compact fast-charging power bank. (Sample product)',            'Sample product for development and testing only.',  1899.00, 1599.00,  60, 0, 1, NOW() - INTERVAL 3 DAY),
(1, 'SMP-ELE-004', 'Sample USB-C Cable 1m',        'sample-usb-c-cable-1m',        'Braided USB-C charging cable. (Sample product)',                'Sample product for development and testing only.',   349.00,    NULL, 150, 0, 1, NOW() - INTERVAL 4 DAY),
(1, 'SMP-ELE-005', 'Sample Bluetooth Speaker',     'sample-bluetooth-speaker',     'Portable water-resistant speaker. (Sample product)',            'Sample product for development and testing only.',  3799.00, 3299.00,   0, 0, 1, NOW() - INTERVAL 5 DAY),
-- Fashion
(2, 'SMP-FAS-001', 'Sample Cotton T-Shirt',        'sample-cotton-t-shirt',        'Soft everyday crew-neck tee. (Sample product)',                 'Sample product for development and testing only.',   599.00,    NULL,  80, 1, 1, NOW() - INTERVAL 6 DAY),
(2, 'SMP-FAS-002', 'Sample Denim Jacket',          'sample-denim-jacket',          'Classic fit denim jacket. (Sample product)',                    'Sample product for development and testing only.',  3499.00, 2899.00,  20, 1, 0, NOW() - INTERVAL 7 DAY),
(2, 'SMP-FAS-003', 'Sample Canvas Backpack',       'sample-canvas-backpack',       'Durable backpack with laptop sleeve. (Sample product)',         'Sample product for development and testing only.',  1799.00,    NULL,  35, 0, 0, NOW() - INTERVAL 8 DAY),
(2, 'SMP-FAS-004', 'Sample Running Sneakers',      'sample-running-sneakers',      'Lightweight breathable sneakers. (Sample product)',             'Sample product for development and testing only.',  3999.00, 3499.00,  18, 0, 0, NOW() - INTERVAL 9 DAY),
(2, 'SMP-FAS-005', 'Sample Leather Wallet',        'sample-leather-wallet',        'Slim bifold wallet. (Sample product)',                          'Sample product for development and testing only.',   899.00,    NULL,  50, 0, 0, NOW() - INTERVAL 10 DAY),
-- Home & Kitchen
(3, 'SMP-HOM-001', 'Sample Non-Stick Frying Pan',  'sample-non-stick-frying-pan',  '26cm non-stick pan. (Sample product)',                          'Sample product for development and testing only.',  1499.00, 1299.00,  30, 1, 0, NOW() - INTERVAL 11 DAY),
(3, 'SMP-HOM-002', 'Sample Ceramic Mug Set',       'sample-ceramic-mug-set',       'Set of 4 ceramic mugs. (Sample product)',                       'Sample product for development and testing only.',   799.00,    NULL,  45, 0, 0, NOW() - INTERVAL 12 DAY),
(3, 'SMP-HOM-003', 'Sample Storage Containers',    'sample-storage-containers',    'Airtight food containers, 5 pcs. (Sample product)',             'Sample product for development and testing only.',   999.00,    NULL,  40, 0, 0, NOW() - INTERVAL 13 DAY),
(3, 'SMP-HOM-004', 'Sample Table Lamp',            'sample-table-lamp',            'Warm LED bedside lamp. (Sample product)',                       'Sample product for development and testing only.',  1799.00, 1499.00,  22, 1, 0, NOW() - INTERVAL 14 DAY),
(3, 'SMP-HOM-005', 'Sample Cotton Bedsheet',       'sample-cotton-bedsheet',       'Double bedsheet with 2 pillow covers. (Sample product)',        'Sample product for development and testing only.',  1899.00,    NULL,  28, 0, 0, NOW() - INTERVAL 15 DAY),
-- Groceries
(4, 'SMP-GRO-001', 'Sample Basmati Rice 5kg',      'sample-basmati-rice-5kg',      'Long-grain aromatic rice. (Sample product)',                    'Sample product for development and testing only.',   950.00,  899.00, 100, 1, 0, NOW() - INTERVAL 16 DAY),
(4, 'SMP-GRO-002', 'Sample Green Tea 100 Bags',    'sample-green-tea-100-bags',    'Refreshing green tea bags. (Sample product)',                   'Sample product for development and testing only.',   450.00,    NULL,  90, 0, 0, NOW() - INTERVAL 17 DAY),
(4, 'SMP-GRO-003', 'Sample Mixed Nuts 500g',       'sample-mixed-nuts-500g',       'Roasted and lightly salted. (Sample product)',                  'Sample product for development and testing only.',   750.00,    NULL,  70, 0, 0, NOW() - INTERVAL 18 DAY),
(4, 'SMP-GRO-004', 'Sample Olive Oil 1L',          'sample-olive-oil-1l',          'Extra virgin olive oil. (Sample product)',                      'Sample product for development and testing only.',  1300.00, 1150.00,  55, 0, 0, NOW() - INTERVAL 19 DAY),
(4, 'SMP-GRO-005', 'Sample Dark Chocolate Bar',    'sample-dark-chocolate-bar',    '70% cocoa chocolate, 100g. (Sample product)',                   'Sample product for development and testing only.',   250.00,    NULL, 120, 0, 0, NOW() - INTERVAL 20 DAY),
-- Health & Beauty
(5, 'SMP-HEA-001', 'Sample Face Wash 150ml',       'sample-face-wash-150ml',       'Gentle daily face cleanser. (Sample product)',                  'Sample product for development and testing only.',   399.00,    NULL,  85, 1, 0, NOW() - INTERVAL 21 DAY),
(5, 'SMP-HEA-002', 'Sample Moisturising Cream',    'sample-moisturising-cream',    'Lightweight hydrating cream. (Sample product)',                 'Sample product for development and testing only.',   749.00,  649.00,  60, 0, 0, NOW() - INTERVAL 22 DAY),
(5, 'SMP-HEA-003', 'Sample Electric Toothbrush',   'sample-electric-toothbrush',   'Rechargeable sonic toothbrush. (Sample product)',               'Sample product for development and testing only.',  2599.00, 2199.00,  15, 0, 0, NOW() - INTERVAL 23 DAY),
(5, 'SMP-HEA-004', 'Sample Herbal Shampoo',        'sample-herbal-shampoo',        'Sulphate-free shampoo, 300ml. (Sample product)',                'Sample product for development and testing only.',   499.00,    NULL,  75, 0, 0, NOW() - INTERVAL 24 DAY),
(5, 'SMP-HEA-005', 'Sample Yoga Mat',              'sample-yoga-mat',              'Non-slip 6mm exercise mat. (Sample product)',                   'Sample product for development and testing only.',  1399.00,    NULL,  26, 0, 0, NOW() - INTERVAL 25 DAY);

-- -------------------------------------------------------------
-- One primary placeholder image per product
-- -------------------------------------------------------------
INSERT INTO `product_images` (`product_id`, `image_path`, `alt_text`, `sort_order`, `is_primary`)
SELECT `id`, 'assets/images/placeholder-product.svg', `name`, 0, 1
  FROM `products`
 WHERE `sku` LIKE 'SMP-%';
