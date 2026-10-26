<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

/**
 * Session shopping cart.
 *
 * The session stores ONLY line key => quantity. A line key is the product
 * id (int) or, for a product sold in sizes/colours, "productId:variantId".
 * Names, prices, stock and availability are always read from the database
 * when the cart is built, so nothing the browser sends can change what is
 * charged. All money maths is done in integer cents (see App\Core\Money).
 */
final class Cart
{
    private const SESSION_KEY = 'cart';

    /** Most different lines in one cart (keeps the session small). */
    public const MAX_LINES = 50;

    /** Most units of one product (or one size/colour) per cart line. */
    public const MAX_QTY = Product::MAX_CART_QTY;

    /** Result of the last build, reused for the rest of the request. */
    private static ?array $built = null;

    /* --------------------------------------------------------
     | Reading
     * ------------------------------------------------------ */

    /** Raw session contents, cleaned to valid line key => positive int. */
    public static function raw(): array
    {
        $raw = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $key => $qty) {
            if (self::parseKey($key) !== null && is_int($qty) && $qty > 0) {
                $clean[$key] = $qty;
            }
        }
        return $clean;
    }

    /**
     * Line key from untrusted input: "12" or "12:34" (product:variant).
     *
     * @return array{0: int, 1: ?int, 2: int|string}|null [product id, variant id, session key]
     */
    public static function parseKey(mixed $value): ?array
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 4294967295 ? [$value, null, $value] : null;
        }
        if (!is_string($value) || !preg_match('/^([1-9]\d{0,9})(?::([1-9]\d{0,9}))?$/', $value, $m)) {
            return null;
        }
        $pid = (int) $m[1];
        $vid = isset($m[2]) ? (int) $m[2] : null;
        if ($pid > 4294967295 || ($vid !== null && $vid > 4294967295)) {
            return null;
        }
        return [$pid, $vid, self::key($pid, $vid)];
    }

    /** Session key of a line (int for plain products, keeps old carts working). */
    public static function key(int $productId, ?int $variantId): int|string
    {
        return $variantId === null ? $productId : $productId . ':' . $variantId;
    }

    public static function quantityOf(int|string $key): int
    {
        return self::raw()[$key] ?? 0;
    }

    /**
     * Build the cart from the database: current prices, stock and status.
     * Lines that are no longer valid are fixed in the session and reported
     * in 'notices' (product removed, quantity reduced to stock).
     *
     * Delivery depends on the district chosen at checkout, so it is not part
     * of the cart; 'free_delivery' says whether the subtotal already earns it.
     *
     * @return array{fingerprint: string, lines: list<array>, notices: list<string>, count: int,
     *               subtotal: int, free_delivery: bool, free_delivery_from: int, free_delivery_gap: int}
     */
    public static function build(): array
    {
        if (self::$built !== null) {
            return self::$built;
        }

        $raw = self::raw();
        $lines = [];
        $notices = [];

        if ($raw) {
            $productIds = $variantIds = [];
            foreach (array_keys($raw) as $key) {
                [$pid, $vid] = self::parseKey($key);
                $productIds[$pid] = $pid;
                if ($vid !== null) {
                    $variantIds[$vid] = $vid;
                }
            }
            $marks = implode(',', array_fill(0, count($productIds), '?'));
            $byId = array_column(db_all(
                "SELECT p.id, p.name, p.slug, p.sku, p.regular_price, p.sale_price, p.price,
                        p.stock_quantity, p.has_sizes, p.has_colours, c.name AS category_name,
                        (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id
                          ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image_path
                   FROM products p
                   JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                  WHERE p.is_active = 1 AND p.id IN ({$marks})",
                array_values($productIds)
            ), null, 'id');
            $variants = [];
            if ($variantIds) {
                $marks = implode(',', array_fill(0, count($variantIds), '?'));
                $variants = array_column(db_all(
                    "SELECT id, product_id, size, colour, stock_quantity FROM product_variants WHERE id IN ({$marks})",
                    array_values($variantIds)
                ), null, 'id');
            }

            foreach ($raw as $key => $qty) {
                [$pid, $vid] = self::parseKey($key);
                $product = $byId[$pid] ?? null;

                if ($product === null) {
                    self::drop($key);
                    $notices[] = 'An item in your cart is no longer available and was removed.';
                    continue;
                }

                // Lines must match how the product is sold now (options may have changed)
                $variant = $vid !== null ? ($variants[$vid] ?? null) : null;
                $usesOptions = $product['has_sizes'] || $product['has_colours'];
                if ($usesOptions !== ($vid !== null) || ($vid !== null && ($variant === null || (int) $variant['product_id'] !== $pid))) {
                    self::drop($key);
                    $notices[] = sprintf('The options for "%s" changed. Please choose a size/colour again.', $product['name']);
                    continue;
                }

                $label = $variant ? Product::variantLabel($variant['size'], $variant['colour']) : '';
                $display = $product['name'] . ($variant ? ' (' . self::shortLabel($variant) . ')' : '');
                $stock = (int) ($variant['stock_quantity'] ?? $product['stock_quantity']);
                if ($stock <= 0) {
                    self::drop($key);
                    $notices[] = sprintf('"%s" is now out of stock and was removed from your cart.', $display);
                    continue;
                }

                $max = min($stock, self::MAX_QTY);
                if ($qty > $max) {
                    $qty = $max;
                    $_SESSION[self::SESSION_KEY][$key] = $qty;
                    $notices[] = sprintf('Only %d of "%s" available. We updated the quantity in your cart.', $max, $display);
                }

                // Unit price = the DB-generated selling price (sale if valid, else regular)
                $unit = Money::toCents($product['price']);
                $lines[] = $product + [
                    'key'           => (string) $key,
                    'variant_id'    => $vid,
                    'variant_label' => $label,
                    'quantity'      => $qty,
                    'max_qty'       => $max,
                    'unit_cents'    => $unit,
                    'line_cents'    => $unit * $qty,
                    'on_sale'       => Money::isOnSale($product['regular_price'], $product['sale_price']),
                ];
            }
        }

        // Keep adjustment notices until the cart page shows them, even if
        // they happened while rendering the header on another page
        if ($notices) {
            $_SESSION['_cart_notices'] = array_values(array_unique(array_merge($_SESSION['_cart_notices'] ?? [], $notices)));
        }

        $subtotal = array_sum(array_column($lines, 'line_cents'));
        $freeFrom = self::freeDeliveryFrom();
        $free = $lines && $freeFrom > 0 && $subtotal >= $freeFrom;

        return self::$built = [
            'fingerprint'        => self::fingerprint(array_map(static fn ($l) => [$l['key'], $l['quantity'], $l['unit_cents']], $lines)),
            'lines'              => $lines,
            'notices'            => array_values(array_unique($notices)),
            'count'              => array_sum(array_column($lines, 'quantity')),
            'subtotal'           => $subtotal,
            'free_delivery'      => $free,
            'free_delivery_from' => $freeFrom,
            'free_delivery_gap'  => $lines && $freeFrom > 0 && !$free ? $freeFrom - $subtotal : 0,
        ];
    }

    /** Order subtotal (cents) from which delivery is free; 0 = never free (Admin -> Delivery). */
    public static function freeDeliveryFrom(): int
    {
        return Money::toCents(Setting::freeDeliveryFrom());
    }

    /**
     * Delivery charge in cents: the zone charge of the chosen district,
     * or 0 when the subtotal reaches the free-delivery amount. Shared with checkout.
     */
    public static function deliveryFor(int $subtotalCents, int $zoneChargeCents): int
    {
        $freeFrom = self::freeDeliveryFrom();
        return $freeFrom > 0 && $subtotalCents >= $freeFrom ? 0 : $zoneChargeCents;
    }

    /**
     * Short hash of exactly what the shopper was shown: lines, quantities and
     * unit prices. Checkout compares it with a fresh calculation so an order
     * is never placed at different amounts than the page displayed (the
     * delivery charge is checked separately, it depends on the district).
     *
     * @param list<array{0: string, 1: int, 2: int}> $lines [line key, qty, unit cents]
     */
    public static function fingerprint(array $lines): string
    {
        usort($lines, static fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
        return hash('sha256', json_encode($lines));
    }

    /** Adjustment notices collected since the cart page was last shown. */
    public static function pullNotices(): array
    {
        $notices = $_SESSION['_cart_notices'] ?? [];
        unset($_SESSION['_cart_notices']);
        return is_array($notices) ? $notices : [];
    }

    /** Total units in the cart (validated against the database). */
    public static function count(): int
    {
        return self::raw() ? self::build()['count'] : 0;
    }

    /* --------------------------------------------------------
     | Writing - each returns [type, message] for a flash
     * ------------------------------------------------------ */

    /**
     * Add a product; products sold in sizes/colours need the chosen size
     * and/or colour, which must exist for that product.
     *
     * @return array{0: string, 1: string}
     */
    public static function add(mixed $productId, mixed $quantity, mixed $size = null, mixed $colour = null): array
    {
        $parsed = is_string($productId) || is_int($productId) ? self::parseKey($productId) : null;
        $product = $parsed !== null && $parsed[1] === null ? self::loadProduct($parsed[0]) : null;
        if ($product === null) {
            return ['error', 'Sorry, that product is not available.'];
        }
        $id = (int) $product['id'];

        $qty = self::parseQuantity($quantity);
        if ($qty === null || $qty < 1) {
            return ['error', 'Please choose a quantity of 1 or more.'];
        }

        $variant = null;
        if ($product['has_sizes'] || $product['has_colours']) {
            $size = $product['has_sizes'] ? self::optionValue($size) : '';
            $colour = $product['has_colours'] ? self::optionValue($colour) : '';
            if ($size === null) {
                return ['error', sprintf('Please choose a size for "%s".', $product['name'])];
            }
            if ($colour === null) {
                return ['error', sprintf('Please choose a colour for "%s".', $product['name'])];
            }
            $variant = Product::findVariant($id, $size, $colour);
            if ($variant === null) {
                return ['error', sprintf('Sorry, that option of "%s" is not available.', $product['name'])];
            }
        }

        $name = $product['name'] . ($variant ? ' (' . self::shortLabel($variant) . ')' : '');
        $stock = (int) ($variant['stock_quantity'] ?? $product['stock_quantity']);
        if ($stock <= 0) {
            return ['error', sprintf('Sorry, "%s" is out of stock.', $name)];
        }

        $key = self::key($id, $variant ? (int) $variant['id'] : null);
        $inCart = self::quantityOf($key);
        if ($inCart === 0 && count(self::raw()) >= self::MAX_LINES) {
            return ['error', sprintf('Your cart can hold up to %d different items.', self::MAX_LINES)];
        }

        $max = min($stock, self::MAX_QTY);
        if ($inCart >= $max) {
            return ['warning', sprintf('You already have the maximum available (%d) of "%s" in your cart.', $max, $name)];
        }

        $new = min($inCart + $qty, $max);
        self::store($key, $new);
        $added = $new - $inCart;

        if ($added < $qty) {
            return ['warning', sprintf('Only %d of "%s" available, so we added %d. Your cart now has %d.', $max, $name, $added, $new)];
        }
        return ['success', sprintf('Added %d × "%s" to your cart.', $added, $name)];
    }

    /**
     * Set a line's quantity. 0 removes the line.
     *
     * @return array{0: string, 1: string}
     */
    public static function update(mixed $line, mixed $quantity): array
    {
        $parsed = self::parseKey($line);
        if ($parsed === null || self::quantityOf($parsed[2]) === 0) {
            return ['error', 'That item is not in your cart.'];
        }
        [$id, $vid, $key] = $parsed;

        $qty = self::parseQuantity($quantity);
        if ($qty === null) {
            return ['error', 'Please enter a whole number of 0 or more.'];
        }

        $product = self::loadProduct($id);
        $variant = $vid !== null ? db_one('SELECT id, size, colour, stock_quantity FROM product_variants WHERE id = ? AND product_id = ?', [$vid, $id]) : null;
        if ($product === null || ($vid !== null && $variant === null)) {
            self::forget($key);
            return ['error', 'That item is no longer available and was removed from your cart.'];
        }
        $name = $product['name'] . ($variant ? ' (' . self::shortLabel($variant) . ')' : '');
        if ($qty === 0) {
            self::forget($key);
            return ['success', sprintf('Removed "%s" from your cart.', $name)];
        }

        $stock = (int) ($variant['stock_quantity'] ?? $product['stock_quantity']);
        if ($stock <= 0) {
            self::forget($key);
            return ['error', sprintf('"%s" is out of stock and was removed from your cart.', $name)];
        }

        $max = min($stock, self::MAX_QTY);
        if ($qty > $max) {
            self::store($key, $max);
            return ['warning', sprintf('Only %d of "%s" available. Quantity set to %d.', $max, $name, $max)];
        }

        self::store($key, $qty);
        return ['success', sprintf('Updated "%s" to %d.', $name, $qty)];
    }

    /** @return array{0: string, 1: string} */
    public static function remove(mixed $line): array
    {
        $parsed = self::parseKey($line);
        if ($parsed === null || self::quantityOf($parsed[2]) === 0) {
            return ['info', 'That item was not in your cart.'];
        }
        $name = db_value('SELECT name FROM products WHERE id = ?', [$parsed[0]]);
        self::forget($parsed[2]);
        return ['success', $name ? sprintf('Removed "%s" from your cart.', $name) : 'Item removed from your cart.'];
    }

    /** @return array{0: string, 1: string} */
    public static function clear(): array
    {
        $_SESSION[self::SESSION_KEY] = [];
        self::$built = null;
        return ['success', 'Your cart is now empty.'];
    }

    /* --------------------------------------------------------
     | Helpers
     * ------------------------------------------------------ */

    /** "M / Red" for messages. */
    private static function shortLabel(array $variant): string
    {
        return implode(' / ', array_filter([$variant['size'], $variant['colour']], 'strlen'));
    }

    /** Active product in an active category, or null. */
    private static function loadProduct(int $id): ?array
    {
        return db_one(
            'SELECT p.id, p.name, p.stock_quantity, p.has_sizes, p.has_colours
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.id = ? AND p.is_active = 1',
            [$id]
        );
    }

    /** A chosen size/colour from untrusted input, or null when missing. */
    private static function optionValue(mixed $value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return $value !== '' && mb_strlen($value) <= Product::OPTION_MAX ? $value : null;
    }

    /** Whole number >= 0 from untrusted input (capped to avoid overflow), else null. */
    private static function parseQuantity(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? min($value, 1_000_000) : null;
        }
        if (!is_string($value) || !preg_match('/^\d{1,9}$/', trim($value))) {
            return null;
        }
        return min((int) trim($value), 1_000_000);
    }

    private static function store(int|string $key, int $qty): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $_SESSION[self::SESSION_KEY][$key] = $qty;
        self::$built = null;
    }

    private static function forget(int|string $key): void
    {
        unset($_SESSION[self::SESSION_KEY][$key]);
        self::$built = null;
    }

    /** Remove a line while building (the build result is being made). */
    private static function drop(int|string $key): void
    {
        unset($_SESSION[self::SESSION_KEY][$key]);
    }
}
