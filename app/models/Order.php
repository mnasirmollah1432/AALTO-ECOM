<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;
use Throwable;

/**
 * Order creation (cash on delivery) and lookup.
 *
 * place() is the only way an order is created. Everything it uses -
 * product status, stock (per size/colour), names, prices and the
 * district's delivery charge - is read from the database inside one
 * transaction with the product and option rows locked, so concurrent
 * checkouts can't oversell and a failure leaves no partial order.
 */
final class Order
{
    public const NUMBER_PREFIX = 'AM';
    public const PAYMENT_COD = 'cod';

    /** Status => [label, Bootstrap badge class] in workflow order. */
    public const STATUSES = [
        'pending'    => ['Pending', 'text-bg-warning'],
        'confirmed'  => ['Confirmed', 'text-bg-info'],
        'processing' => ['Processing', 'text-bg-primary'],
        'shipped'    => ['Shipped', 'badge-shipped'],
        'delivered'  => ['Delivered', 'text-bg-success'],
        'cancelled'  => ['Cancelled', 'text-bg-secondary'],
    ];

    /**
     * Allowed status changes. Orders only move forward (steps may be
     * skipped, e.g. pending -> shipped), any open order can be cancelled,
     * and delivered / cancelled are final.
     */
    public const TRANSITIONS = [
        'pending'    => ['confirmed', 'processing', 'shipped', 'delivered', 'cancelled'],
        'confirmed'  => ['processing', 'shipped', 'delivered', 'cancelled'],
        'processing' => ['shipped', 'delivered', 'cancelled'],
        'shipped'    => ['delivered', 'cancelled'],
        'delivered'  => [],
        'cancelled'  => [],
    ];

    public const NOTE_MAX = 500;

    public const NAME_MIN = 2;
    public const NAME_MAX = 100;
    public const ADDRESS_MIN = 5;
    public const ADDRESS_MAX = 500;

    /* --------------------------------------------------------
     | Customer details
     * ------------------------------------------------------ */

    /**
     * Validate and normalise the checkout fields: name, mobile, district,
     * thana (must belong to the district) and address (house, road, area).
     *
     * @return array{0: array{name: string, mobile: string, address: string, district_id: int, thana_id: int}, 1: array<string, string>}
     */
    public static function validateCustomer(array $input): array
    {
        $errors = [];
        $text = static function (string $key) use ($input, &$errors): string {
            $value = $input[$key] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                $errors[$key] = 'This field contains invalid characters.';
                return '';
            }
            return $value;
        };

        // Name: letters (any language), spaces, . ' -
        $name = trim((string) preg_replace('/\s+/u', ' ', $text('name')));
        if (!isset($errors['name'])) {
            if ($name === '') {
                $errors['name'] = 'Enter your name.';
            } elseif (mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX) {
                $errors['name'] = sprintf('Name must be %d to %d characters.', self::NAME_MIN, self::NAME_MAX);
            } elseif (!preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u", $name)) {
                $errors['name'] = 'Use letters only (spaces, dots, apostrophes and hyphens are fine).';
            }
        }

        // Mobile: Bangladeshi mobile number, stored as 01XXXXXXXXX
        $mobileInput = trim($text('mobile'));
        $mobile = '';
        if (!isset($errors['mobile'])) {
            $digits = preg_replace('/[\s\-().]/', '', $mobileInput);
            if ($mobileInput === '') {
                $errors['mobile'] = 'Enter your mobile number.';
            } elseif (preg_match('/^(?:\+?88)?(01[3-9]\d{8})$/', (string) $digits, $m)) {
                $mobile = $m[1];
            } else {
                $errors['mobile'] = 'Enter a valid Bangladeshi mobile number, e.g. 01712-345678.';
            }
        }

        // Address: free text, several lines allowed, no control characters
        $address = str_replace(["\r\n", "\r"], "\n", $text('address'));
        $address = implode("\n", array_filter(array_map(
            static fn ($line) => trim((string) preg_replace('/[ \t]+/u', ' ', $line)),
            explode("\n", $address)
        ), static fn ($line) => $line !== ''));
        if (!isset($errors['address'])) {
            if ($address === '') {
                $errors['address'] = 'Enter your delivery address.';
            } elseif (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $address)) {
                $errors['address'] = 'The address contains invalid characters.';
            } elseif (mb_strlen($address) < self::ADDRESS_MIN) {
                $errors['address'] = 'Please enter your house, road and area, e.g. House 4, Road 2, Dhanmondi.';
            } elseif (mb_strlen($address) > self::ADDRESS_MAX) {
                $errors['address'] = sprintf('Address must be %d characters or fewer.', self::ADDRESS_MAX);
            } elseif (!preg_match('/\p{L}/u', $address)) {
                $errors['address'] = 'Please enter a real address.';
            }
        }

        // District and thana: active ones, and the thana must be in that district
        $id = static fn (string $key): int => is_string($input[$key] ?? null) && preg_match('/^[1-9]\d{0,9}$/', $input[$key]) ? (int) $input[$key] : 0;
        $districtId = $id('district_id');
        $thanaId = $id('thana_id');
        if ($districtId === 0 || Location::district($districtId) === null) {
            $errors['district_id'] = 'Choose your district.';
            $districtId = 0;
        } elseif ($thanaId === 0 || Location::resolve($districtId, $thanaId) === null) {
            $errors['thana_id'] = 'Choose your thana / area.';
            $thanaId = 0;
        }

        return [['name' => $name, 'mobile' => $mobile, 'address' => $address, 'district_id' => $districtId, 'thana_id' => $thanaId], $errors];
    }

    /* --------------------------------------------------------
     | Placing an order
     * ------------------------------------------------------ */

    /**
     * Create the order from the session cart.
     *
     * @param array{name: string, mobile: string, address: string, district_id: int, thana_id: int} $customer already validated
     * @param array<int|string, int> $cart          line key => quantity (Cart::raw())
     * @param string                 $fingerprint   Cart fingerprint the shopper saw on the checkout page
     * @param string|null            $deliveryShown delivery charge the page showed (DECIMAL), null when unknown (no JavaScript)
     * @return array{id: int, number: string}
     * @throws CheckoutException for problems the shopper can fix (nothing is written)
     */
    public static function place(array $customer, array $cart, string $fingerprint, ?string $deliveryShown = null): array
    {
        if (!$cart) {
            throw new CheckoutException(['Your cart is empty.']);
        }

        // Cart lines: [product id, variant id|null, key, qty]
        $wanted = [];
        foreach ($cart as $key => $qty) {
            $parsed = Cart::parseKey($key);
            if ($parsed === null) {
                throw new CheckoutException(['Your cart could not be read. Please review it and try again.']);
            }
            $wanted[] = [$parsed[0], $parsed[1], (string) $parsed[2], $qty];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $location = Location::resolve((int) $customer['district_id'], (int) $customer['thana_id']);
            if ($location === null) {
                throw new CheckoutException(['Please choose your district and thana again.']);
            }

            // Lock the product rows, then the option rows (id order avoids deadlocks)
            $ids = array_values(array_unique(array_column($wanted, 0)));
            sort($ids);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $products = array_column(db_all(
                "SELECT p.id, p.name, p.sku, p.price, p.stock_quantity, p.is_active, p.has_sizes, p.has_colours,
                        c.is_active AS category_active
                   FROM products p
                   JOIN categories c ON c.id = p.category_id
                  WHERE p.id IN ({$marks})
                  ORDER BY p.id
                    FOR UPDATE",
                $ids
            ), null, 'id');

            $variantIds = array_values(array_filter(array_unique(array_column($wanted, 1))));
            $variants = [];
            if ($variantIds) {
                sort($variantIds);
                $marks = implode(',', array_fill(0, count($variantIds), '?'));
                $variants = array_column(db_all(
                    "SELECT id, product_id, size, colour, stock_quantity FROM product_variants
                      WHERE id IN ({$marks}) ORDER BY id FOR UPDATE",
                    $variantIds
                ), null, 'id');
            }

            // Status and stock, from the locked rows
            $problems = [];
            $lines = [];
            foreach ($wanted as [$id, $vid, $key, $qty]) {
                $p = $products[$id] ?? null;
                if ($p === null || !$p['is_active'] || !$p['category_active']) {
                    $problems[] = sprintf('"%s" is no longer available.', $p['name'] ?? 'An item in your cart');
                    continue;
                }
                $v = $vid !== null ? ($variants[$vid] ?? null) : null;
                $usesOptions = $p['has_sizes'] || $p['has_colours'];
                if ($usesOptions !== ($vid !== null) || ($vid !== null && ($v === null || (int) $v['product_id'] !== $id))) {
                    $problems[] = sprintf('The options for "%s" changed. Please choose a size/colour again.', $p['name']);
                    continue;
                }
                $name = $p['name'] . ($v ? ' (' . implode(' / ', array_filter([$v['size'], $v['colour']], 'strlen')) . ')' : '');
                $stock = (int) ($v['stock_quantity'] ?? $p['stock_quantity']);
                if ($qty < 1 || $qty > Cart::MAX_QTY) {
                    $problems[] = sprintf('The quantity of "%s" is not valid.', $name);
                } elseif ($stock <= 0) {
                    $problems[] = sprintf('"%s" is out of stock.', $name);
                } elseif ($qty > $stock) {
                    $problems[] = sprintf('Only %d of "%s" left in stock (you asked for %d).', $stock, $name, $qty);
                }

                // Current DB price (generated column: valid sale price, else regular)
                $unit = Money::toCents($p['price']);
                $lines[] = [
                    'key'     => $key,
                    'product' => $p,
                    'variant' => $v,
                    'label'   => $v ? Product::variantLabel($v['size'], $v['colour']) : null,
                    'qty'     => $qty,
                    'unit'    => $unit,
                    'line'    => $unit * $qty,
                ];
            }
            if ($problems) {
                throw new CheckoutException($problems);
            }

            // Totals, server side, in integer cents; delivery from the district's zone
            $subtotal = array_sum(array_column($lines, 'line'));
            $delivery = Cart::deliveryFor($subtotal, Money::toCents($location['charge']));
            $total = $subtotal + $delivery;

            // Never charge different amounts than the checkout page showed
            $now = Cart::fingerprint(array_map(static fn ($l) => [$l['key'], $l['qty'], $l['unit']], $lines));
            if (!hash_equals($now, $fingerprint)) {
                throw new CheckoutException(['Prices or items in your cart changed. Please review your order and place it again.']);
            }
            if ($deliveryShown !== null && Money::toCents($deliveryShown) !== $delivery) {
                throw new CheckoutException([sprintf(
                    'The delivery charge to %s is %s. Please review the total and place your order again.',
                    $location['district'],
                    format_price($delivery)
                )]);
            }

            $number = self::nextNumber();
            $orderId = db_insert('orders', [
                'order_number'      => $number,
                'status'            => 'pending',
                'payment_status'    => 'unpaid',
                'payment_method'    => self::PAYMENT_COD,
                'customer_name'     => $customer['name'],
                'customer_phone'    => $customer['mobile'],
                'shipping_address'  => $customer['address'],
                'district_id'       => $location['district_id'],
                'thana_id'          => $location['thana_id'],
                'shipping_district' => $location['district'],
                'shipping_thana'    => $location['thana'],
                'delivery_zone'     => $location['zone'],
                'subtotal'          => Money::fromCents($subtotal),
                'shipping_fee'      => Money::fromCents($delivery),
                'grand_total'       => Money::fromCents($total),
                'currency'          => (string) config('app.store.currency', 'BDT'),
                'placed_at'         => date('Y-m-d H:i:s'),
            ]);

            foreach ($lines as $l) {
                $productId = (int) $l['product']['id'];
                db_insert('order_items', [
                    'order_id'      => $orderId,
                    'product_id'    => $productId,
                    'variant_id'    => $l['variant'] ? (int) $l['variant']['id'] : null,
                    'product_name'  => $l['product']['name'],
                    'variant_label' => $l['label'],
                    'product_sku'   => $l['product']['sku'],
                    'unit_price'    => Money::fromCents($l['unit']),
                    'quantity'      => $l['qty'],
                    'line_total'    => Money::fromCents($l['line']),
                ]);

                // The WHERE guards are a second line of defence behind the row locks
                if ($l['variant']) {
                    $updated = db_query(
                        'UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?',
                        [$l['qty'], $l['variant']['id'], $l['qty']]
                    )->rowCount();
                    if ($updated !== 1) {
                        throw new \RuntimeException('Stock changed while placing order for variant ' . $l['variant']['id']);
                    }
                }
                $updated = db_query(
                    'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?',
                    [$l['qty'], $productId, $l['qty']]
                )->rowCount();
                if ($updated !== 1) {
                    throw new \RuntimeException('Stock changed while placing order for product ' . $productId);
                }
            }

            self::logStatus($orderId, null, 'pending', 'Order placed', null);

            $pdo->commit();
            return ['id' => $orderId, 'number' => $number];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Next order number for today, e.g. AM-20260924-00001.
     * Must run inside the order transaction: the upsert locks today's
     * counter row until commit, so two checkouts can't get the same
     * number, and a rolled-back order gives its number back.
     */
    private static function nextNumber(): string
    {
        $date = date('Y-m-d');
        db_query(
            'INSERT INTO order_sequences (seq_date, seq_value) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE seq_value = seq_value + 1',
            [$date]
        );
        $n = (int) db_value('SELECT seq_value FROM order_sequences WHERE seq_date = ?', [$date]);

        return sprintf('%s-%s-%05d', self::NUMBER_PREFIX, date('Ymd'), $n);
    }

    /* --------------------------------------------------------
     | Reading
     * ------------------------------------------------------ */

    /** Order with its items, or null. */
    public static function findWithItems(int $id): ?array
    {
        $order = db_one('SELECT * FROM orders WHERE id = ?', [$id]);
        if ($order === null) {
            return null;
        }
        $order['items'] = db_all(
            'SELECT oi.product_id, oi.product_name, oi.variant_label, oi.product_sku, oi.unit_price, oi.quantity, oi.line_total,
                    p.slug,
                    (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = oi.product_id
                      ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image_path
               FROM order_items oi
               LEFT JOIN products p ON p.id = oi.product_id
              WHERE oi.order_id = ?
              ORDER BY oi.id',
            [$id]
        );
        return $order;
    }

    public static function paymentLabel(string $method): string
    {
        return $method === self::PAYMENT_COD ? 'Cash on Delivery' : ucfirst($method);
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status][0] ?? ucfirst($status);
    }

    public static function statusBadge(string $status): string
    {
        return self::STATUSES[$status][1] ?? 'text-bg-light';
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /* --------------------------------------------------------
     | Admin
     * ------------------------------------------------------ */

    /**
     * Admin order list.
     *
     * @param array{q?: string, status?: string, from?: string, to?: string} $filters dates as Y-m-d
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public static function adminPaginate(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$whereSql, $params] = self::adminWhere($filters);

        $total = (int) db_value("SELECT COUNT(*) FROM orders o {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $items = $total === 0 ? [] : db_all(
            "SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.grand_total,
                    o.status, o.payment_status, o.placed_at, o.created_at, o.courier, o.courier_consignment_id, o.courier_status,
                    (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
               FROM orders o
               {$whereSql}
              ORDER BY o.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return compact('items', 'total', 'page', 'pages') + ['per_page' => $perPage];
    }

    /** Order counts per status for the given filters (status filter ignored), plus 'all'. */
    public static function statusCounts(array $filters = []): array
    {
        [$whereSql, $params] = self::adminWhere(['status' => ''] + $filters);
        $rows = db_all("SELECT o.status, COUNT(*) AS n FROM orders o {$whereSql} GROUP BY o.status", $params);
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        return ['all' => array_sum($counts)] + $counts;
    }

    /**
     * Dashboard figures. Sales are DECIMAL sums done by MySQL.
     * "sales" = delivered orders (cash collected); "open_value" = orders
     * not yet delivered or cancelled.
     */
    public static function dashboardStats(): array
    {
        $row = db_one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status = 'delivered' THEN grand_total END), 0.00) AS sales,
                    COALESCE(SUM(CASE WHEN status NOT IN ('delivered','cancelled') THEN grand_total END), 0.00) AS open_value
               FROM orders"
        );
        return [
            'total'      => (int) $row['total'],
            'counts'     => self::statusCounts(),
            'sales'      => (string) $row['sales'],
            'open_value' => (string) $row['open_value'],
            'recent'     => db_all(
                'SELECT id, order_number, customer_name, grand_total, status, placed_at
                   FROM orders ORDER BY id DESC LIMIT 5'
            ),
        ];
    }

    /** Status history, oldest first, with the admin's name. */
    public static function history(int $orderId): array
    {
        return db_all(
            'SELECT h.from_status, h.to_status, h.note, h.created_at, u.name AS admin_name
               FROM order_status_history h
               LEFT JOIN users u ON u.id = h.admin_id
              WHERE h.order_id = ?
              ORDER BY h.id',
            [$orderId]
        );
    }

    /**
     * Change an order's status (admin).
     *
     * Runs in a transaction with the order row locked. $expectedFrom is
     * the status the admin saw; if someone else changed the order in the
     * meantime nothing happens. Cancelling puts the items back in stock;
     * delivering a cash-on-delivery order marks it paid.
     *
     * @throws CheckoutException with a message for the admin when refused
     */
    public static function changeStatus(int $orderId, string $to, string $expectedFrom, ?string $note, ?int $adminId): void
    {
        if (!isset(self::STATUSES[$to])) {
            throw new CheckoutException(['Choose a valid status.']);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $order = db_one('SELECT id, status, payment_method FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($order === null) {
                throw new CheckoutException(['That order no longer exists.']);
            }
            $from = $order['status'];
            if ($from !== $expectedFrom) {
                throw new CheckoutException([sprintf(
                    'This order was changed to "%s" by someone else while you were viewing it. Please review and try again.',
                    self::statusLabel($from)
                )]);
            }
            if (!self::canTransition($from, $to)) {
                throw new CheckoutException([sprintf(
                    'An order cannot go from "%s" to "%s".',
                    self::statusLabel($from),
                    self::statusLabel($to)
                )]);
            }

            $update = ['status' => $to];
            if ($to === 'delivered' && $order['payment_method'] === self::PAYMENT_COD) {
                $update['payment_status'] = 'paid'; // cash collected on delivery
            }
            db_update('orders', $update, ['id' => $orderId]);

            if ($to === 'cancelled') {
                self::restock($orderId);
            }

            self::logStatus($orderId, $from, $to, $note, $adminId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Return a cancelled order's items to stock. Sizes/colours go back to
     * their option (products with options then total their options again);
     * plain products get the quantity back. Deleted products/options have
     * NULL ids and are skipped.
     */
    private static function restock(int $orderId): void
    {
        db_query(
            'UPDATE product_variants v
               JOIN (SELECT variant_id, SUM(quantity) AS qty FROM order_items
                      WHERE order_id = ? AND variant_id IS NOT NULL GROUP BY variant_id) oi
                 ON oi.variant_id = v.id
                SET v.stock_quantity = v.stock_quantity + oi.qty',
            [$orderId]
        );
        db_query(
            'UPDATE products p
               JOIN (SELECT product_id, SUM(quantity) AS qty FROM order_items
                      WHERE order_id = ? AND product_id IS NOT NULL AND variant_id IS NULL GROUP BY product_id) oi
                 ON oi.product_id = p.id
                SET p.stock_quantity = p.stock_quantity + oi.qty
              WHERE p.has_sizes = 0 AND p.has_colours = 0',
            [$orderId]
        );
        $withOptions = db_all(
            'SELECT DISTINCT p.id FROM order_items oi JOIN products p ON p.id = oi.product_id
              WHERE oi.order_id = ? AND (p.has_sizes = 1 OR p.has_colours = 1)',
            [$orderId]
        );
        foreach ($withOptions as $row) {
            Product::syncStockFromVariants((int) $row['id']);
        }
    }

    /** A note in the order history without changing the status (e.g. sent to the courier). */
    public static function addNote(int $orderId, string $note, ?int $adminId): void
    {
        $status = (string) db_value('SELECT status FROM orders WHERE id = ?', [$orderId]);
        if ($status !== '') {
            self::logStatus($orderId, $status, $status, $note, $adminId);
        }
    }

    private static function logStatus(int $orderId, ?string $from, string $to, ?string $note, ?int $adminId): void
    {
        db_insert('order_status_history', [
            'order_id'    => $orderId,
            'from_status' => $from,
            'to_status'   => $to,
            'note'        => $note !== null && $note !== '' ? mb_substr($note, 0, self::NOTE_MAX) : null,
            'admin_id'    => $adminId,
        ]);
    }

    /** WHERE clause for admin filters. Dates must already be validated Y-m-d strings. */
    private static function adminWhere(array $filters): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $digits = preg_replace('/\D/', '', $q);
            $conds = ["o.order_number LIKE ? ESCAPE '!'", "o.customer_name LIKE ? ESCAPE '!'"];
            array_push($params, $like, $like);
            if (strlen($digits) >= 3) {
                // Mobile numbers are stored as 01XXXXXXXXX; match typed forms like +880 17...
                $digits = preg_replace('/^880?(?=1)/', '0', $digits);
                $conds[] = 'o.customer_phone LIKE ?';
                $params[] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }

        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[] = 'o.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'o.placed_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'o.placed_at < ? + INTERVAL 1 DAY';
            $params[] = $filters['to'];
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
