<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Order;

final class DashboardController
{
    /** bcrypt hash of the published sample password in database/seed.sql */
    private const SAMPLE_PASSWORD_HASH = '$2y$10$fKUVmsUtBAe5q6LQ3FyTIuO6.9qKAoNdeX3nRcqwJmK/iWWcwkyNC';

    public function index(): string
    {
        $admin = require_admin();
        admin_headers();

        $catalog = db_one(
            "SELECT
                (SELECT COUNT(*) FROM products)                              AS products,
                (SELECT COUNT(*) FROM products WHERE is_active = 1)          AS products_active,
                (SELECT COUNT(*) FROM products WHERE stock_quantity <= 0)    AS products_out_of_stock,
                (SELECT COUNT(*) FROM categories)                            AS categories,
                (SELECT COUNT(*) FROM categories WHERE is_active = 1)        AS categories_active"
        );

        return view('admin/dashboard', [
            'title'   => 'Dashboard',
            'admin'   => $admin,
            'orders'  => Order::dashboardStats(),
            'catalog' => array_map('intval', $catalog),
            // The sample login from seed.sql is public knowledge: nag until it is changed
            'samplePassword' => (bool) db_value(
                'SELECT 1 FROM users WHERE id = ? AND password_hash = ?',
                [(int) $admin['id'], self::SAMPLE_PASSWORD_HASH]
            ),
        ], 'admin');
    }
}
