<?php

declare(strict_types=1);

namespace App\Models;

final class Product
{
    public const NAME_MAX = 200;
    public const SLUG_MAX = 220;
    public const SKU_MAX = 64;
    public const SHORT_MAX = 500;
    public const DESCRIPTION_MAX = 10000;
    public const STOCK_MAX = 1_000_000;
    public const LOW_STOCK = 5;
    public const MAX_EXTRA_IMAGES = 8;
    /** Most units of one product a shopper can add at a time. */
    public const MAX_CART_QTY = 99;

    /** Admin list sort options: key => [label, ORDER BY] (whitelist). */
    public const SORTS = [
        'newest'     => ['Newest first', 'p.created_at DESC, p.id DESC'],
        'updated'    => ['Recently updated', 'p.updated_at DESC, p.id DESC'],
        'name'       => ['Name (A–Z)', 'p.name ASC, p.id ASC'],
        'price_asc'  => ['Price: low to high', 'p.price ASC, p.id ASC'],
        'price_desc' => ['Price: high to low', 'p.price DESC, p.id DESC'],
        'stock_asc'  => ['Stock: lowest first', 'p.stock_quantity ASC, p.id ASC'],
    ];

    /** Flags the admin can toggle from the list: key => column. */
    public const FLAGS = [
        'active'   => 'is_active',
        'featured' => 'is_featured',
        'new'      => 'is_new',
    ];

    private const PRIMARY_IMAGE = '(SELECT pi.image_path FROM product_images pi
           WHERE pi.product_id = p.id
           ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1)';

    private const LISTING_COLUMNS =
        'p.id, p.name, p.slug, p.sku, p.short_description,
         p.regular_price, p.sale_price, p.price,
         p.stock_quantity, p.is_featured, p.is_new, p.has_sizes, p.has_colours,
         c.name AS category_name, c.slug AS category_slug, '
        . self::PRIMARY_IMAGE . ' AS image_path';

    /* --------------------------------------------------------
     | Storefront
     * ------------------------------------------------------ */

    public static function featured(int $limit = 8): array
    {
        return db_all(
            'SELECT ' . self::LISTING_COLUMNS . '
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.is_active = 1 AND p.is_featured = 1
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ' . max(1, $limit)
        );
    }

    public static function latest(int $limit = 12): array
    {
        return db_all(
            'SELECT ' . self::LISTING_COLUMNS . '
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.is_active = 1
              ORDER BY p.is_new DESC, p.created_at DESC, p.id DESC
              LIMIT ' . max(1, $limit)
        );
    }

    /** Products flagged "new" (newest first); falls back to latest if none are flagged. */
    public static function newArrivals(int $limit = 8): array
    {
        $items = db_all(
            'SELECT ' . self::LISTING_COLUMNS . '
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.is_active = 1 AND p.is_new = 1
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ' . max(1, $limit)
        );
        return $items ?: self::latest($limit);
    }

    /** Storefront sort options: key => [label, ORDER BY]. */
    public const SHOP_SORTS = [
        'featured'   => ['Featured', 'p.is_featured DESC, p.is_new DESC, p.created_at DESC, p.id DESC'],
        'newest'     => ['Newest', 'p.created_at DESC, p.id DESC'],
        'price_asc'  => ['Price: low to high', 'p.price ASC, p.id ASC'],
        'price_desc' => ['Price: high to low', 'p.price DESC, p.id DESC'],
        'name'       => ['Name: A–Z', 'p.name ASC, p.id ASC'],
    ];

    /**
     * Storefront listing: active products in active categories.
     *
     * @param array{q?: string, category_id?: int, sale?: bool, in_stock?: bool, new?: bool, sort?: string} $filters
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public static function shop(array $filters, int $page = 1, int $perPage = 12): array
    {
        $where = ['p.is_active = 1'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Every word must match the name, short description or SKU
            foreach (array_slice(preg_split('/\s+/u', $q), 0, 5) as $word) {
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
                $where[] = "(p.name LIKE ? ESCAPE '!' OR p.short_description LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!')";
                array_push($params, $like, $like, $like);
            }
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['sale'])) {
            $where[] = 'p.price < p.regular_price';
        }
        if (!empty($filters['in_stock'])) {
            $where[] = 'p.stock_quantity > 0';
        }
        if (!empty($filters['new'])) {
            $where[] = 'p.is_new = 1';
        }

        $from = 'FROM products p JOIN categories c ON c.id = p.category_id AND c.is_active = 1
                 WHERE ' . implode(' AND ', $where);
        $order = (self::SHOP_SORTS[$filters['sort'] ?? ''] ?? self::SHOP_SORTS['featured'])[1];

        $total = (int) db_value("SELECT COUNT(*) {$from}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $items = $total === 0 ? [] : db_all(
            'SELECT ' . self::LISTING_COLUMNS . " {$from} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return compact('items', 'total', 'page', 'pages') + ['per_page' => $perPage];
    }

    /** An active product (in an active category) by slug, or null. */
    public static function findActiveBySlug(string $slug): ?array
    {
        return db_one(
            'SELECT p.id, p.category_id, p.sku, p.name, p.slug, p.short_description, p.description,
                    p.regular_price, p.sale_price, p.price, p.stock_quantity, p.is_featured, p.is_new,
                    p.has_sizes, p.has_colours, p.updated_at, c.name AS category_name, c.slug AS category_slug
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.slug = ? AND p.is_active = 1',
            [$slug]
        );
    }

    /** Active products (in active categories) with these ids, listing columns. */
    public static function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0));
        if (!$ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return db_all(
            'SELECT ' . self::LISTING_COLUMNS . '
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.is_active = 1 AND p.id IN (' . $marks . ')',
            $ids
        );
    }

    /** Other active products from the same category. */
    public static function related(int $productId, int $categoryId, int $limit = 4): array
    {
        return db_all(
            'SELECT ' . self::LISTING_COLUMNS . '
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.is_active = 1 AND p.category_id = ? AND p.id <> ?
              ORDER BY p.stock_quantity > 0 DESC, p.is_featured DESC, p.created_at DESC
              LIMIT ' . max(1, $limit),
            [$categoryId, $productId]
        );
    }

    /** Biggest whole-percent discount among active products (DECIMAL maths in SQL). */
    public static function maxDiscountPercent(): int
    {
        return (int) db_value(
            'SELECT COALESCE(MAX(FLOOR((p.regular_price - p.price) * 100 / p.regular_price)), 0)
               FROM products p
               JOIN categories c ON c.id = p.category_id AND c.is_active = 1
              WHERE p.is_active = 1 AND p.price < p.regular_price AND p.regular_price > 0'
        );
    }

    /* --------------------------------------------------------
     | Admin: reading
     * ------------------------------------------------------ */

    /**
     * @param array{q?: string, category?: int, status?: string, flag?: string, stock?: string, sort?: string} $filters
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $where[] = "(p.name LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!' OR p.slug LIKE ? ESCAPE '!'
                         OR p.short_description LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like);
        }

        if (!empty($filters['category'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int) $filters['category'];
        }

        match ($filters['status'] ?? '') {
            'active'   => $where[] = 'p.is_active = 1',
            'inactive' => $where[] = 'p.is_active = 0',
            default    => null,
        };

        match ($filters['flag'] ?? '') {
            'featured' => $where[] = 'p.is_featured = 1',
            'new'      => $where[] = 'p.is_new = 1',
            'sale'     => $where[] = 'p.price < p.regular_price',
            default    => null,
        };

        match ($filters['stock'] ?? '') {
            'in'  => $where[] = 'p.stock_quantity > ' . self::LOW_STOCK,
            'low' => $where[] = 'p.stock_quantity BETWEEN 1 AND ' . self::LOW_STOCK,
            'out' => $where[] = 'p.stock_quantity = 0',
            default => null,
        };

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderSql = (self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['newest'])[1];

        $total = (int) db_value("SELECT COUNT(*) FROM products p {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $items = db_all(
            "SELECT p.id, p.name, p.slug, p.sku, p.regular_price, p.sale_price, p.price,
                    p.stock_quantity, p.is_active, p.is_featured, p.is_new, p.has_sizes, p.has_colours, p.updated_at,
                    c.name AS category_name, c.is_active AS category_active,
                    " . self::PRIMARY_IMAGE . " AS image_path,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p.id) AS order_count
               FROM products p
               JOIN categories c ON c.id = p.category_id
               {$whereSql}
              ORDER BY {$orderSql}
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return compact('items', 'total', 'page', 'pages') + ['per_page' => $perPage];
    }

    public static function find(int $id): ?array
    {
        return db_one(
            'SELECT p.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p.id) AS order_count
               FROM products p
               JOIN categories c ON c.id = p.category_id
              WHERE p.id = ?',
            [$id]
        );
    }

    /** All images, main image first. */
    public static function images(int $productId): array
    {
        return db_all(
            'SELECT id, image_path, alt_text, sort_order, is_primary
               FROM product_images
              WHERE product_id = ?
              ORDER BY is_primary DESC, sort_order, id',
            [$productId]
        );
    }

    /** id => name for the category dropdown, inactive ones marked. */
    public static function categoryOptions(): array
    {
        return db_all('SELECT id, name, is_active FROM categories ORDER BY name');
    }

    public static function categoryExists(int $id): bool
    {
        return (bool) db_value('SELECT 1 FROM categories WHERE id = ?', [$id]);
    }

    public static function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) db_value('SELECT 1 FROM products WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $exceptId ?? 0]);
    }

    public static function skuExists(string $sku, ?int $exceptId = null): bool
    {
        return (bool) db_value('SELECT 1 FROM products WHERE sku = ? AND id <> ? LIMIT 1', [$sku, $exceptId ?? 0]);
    }

    public static function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $base = $base !== '' ? $base : 'product';
        $slug = $base;
        for ($i = 2; self::slugExists($slug, $exceptId); $i++) {
            $suffix = '-' . $i;
            $slug = rtrim(substr($base, 0, self::SLUG_MAX - strlen($suffix)), '-') . $suffix;
        }
        return $slug;
    }

    /** "AM-7F3K9Q2D" style SKU that isn't taken. */
    public static function generateSku(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O/1/I
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $sku = 'AM-' . $code;
        } while (self::skuExists($sku));
        return $sku;
    }

    /* --------------------------------------------------------
     | Admin: writing
     * ------------------------------------------------------ */

    public static function create(array $data): int
    {
        return db_insert('products', self::only($data));
    }

    public static function update(int $id, array $data): void
    {
        db_update('products', self::only($data), ['id' => $id]);
    }

    public static function setFlag(int $id, string $flag, bool $on): void
    {
        $column = self::FLAGS[$flag] ?? throw new \InvalidArgumentException("Unknown flag {$flag}");
        db_update('products', [$column => $on ? 1 : 0], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        // product_images rows go with it (ON DELETE CASCADE)
        db_query('DELETE FROM products WHERE id = ?', [$id]);
    }

    public static function addImage(int $productId, string $path, string $alt, bool $primary, int $sort): int
    {
        return db_insert('product_images', [
            'product_id' => $productId,
            'image_path' => $path,
            'alt_text'   => mb_substr($alt, 0, 200),
            'is_primary' => $primary ? 1 : 0,
            'sort_order' => $sort,
        ]);
    }

    public static function deleteImages(int $productId, array $imageIds): void
    {
        if (!$imageIds) {
            return;
        }
        $marks = implode(',', array_fill(0, count($imageIds), '?'));
        db_query(
            "DELETE FROM product_images WHERE product_id = ? AND id IN ({$marks})",
            array_merge([$productId], array_map('intval', $imageIds))
        );
    }

    /** Point an existing image row at a new (already processed) file; keeps its order and main flag. */
    public static function replaceImagePath(int $productId, int $imageId, string $path): void
    {
        db_query('UPDATE product_images SET image_path = ? WHERE product_id = ? AND id = ?', [$path, $productId, $imageId]);
    }

    /* --------------------------------------------------------
     | Size / colour options (product_variants)
     * ------------------------------------------------------ */

    public const OPTION_MAX = 40;       // characters in one size or colour name
    public const OPTION_VALUES_MAX = 20; // sizes (or colours) per product

    /** "Size: M · Colour: Red" (only the options in use). */
    public static function variantLabel(string $size, string $colour): string
    {
        return implode(' · ', array_filter([
            $size !== '' ? 'Size: ' . $size : '',
            $colour !== '' ? 'Colour: ' . $colour : '',
        ]));
    }

    /** All option rows of a product in admin order. */
    public static function variants(int $productId): array
    {
        return db_all(
            'SELECT id, size, colour, colour_hex, stock_quantity
               FROM product_variants WHERE product_id = ? ORDER BY sort_order, id',
            [$productId]
        );
    }

    /**
     * Options for the product page, or null when the product uses none:
     * sizes/colours in admin order (with swatch colours) and the stock of
     * every combination, keyed "size|colour".
     *
     * @return array{sizes: list<string>, colours: list<array{name: string, hex: ?string}>,
     *               stock: array<string, int>, has_sizes: bool, has_colours: bool}|null
     */
    public static function options(array $product): ?array
    {
        $hasSizes = !empty($product['has_sizes']);
        $hasColours = !empty($product['has_colours']);
        if (!$hasSizes && !$hasColours) {
            return null;
        }
        $sizes = $colours = $stock = [];
        foreach (self::variants((int) $product['id']) as $v) {
            if ($hasSizes && $v['size'] !== '' && !in_array($v['size'], $sizes, true)) {
                $sizes[] = $v['size'];
            }
            if ($hasColours && $v['colour'] !== '' && !isset($colours[$v['colour']])) {
                $colours[$v['colour']] = ['name' => $v['colour'], 'hex' => $v['colour_hex']];
            }
            $stock[$v['size'] . '|' . $v['colour']] = (int) $v['stock_quantity'];
        }
        return ['sizes' => $sizes, 'colours' => array_values($colours), 'stock' => $stock,
                'has_sizes' => $hasSizes, 'has_colours' => $hasColours];
    }

    /** The option row for a size/colour choice of a product, or null. */
    public static function findVariant(int $productId, string $size, string $colour): ?array
    {
        return db_one(
            'SELECT id, product_id, size, colour, stock_quantity FROM product_variants
              WHERE product_id = ? AND size = ? AND colour = ?',
            [$productId, $size, $colour]
        );
    }

    /**
     * Store the size/colour setup of a product (call inside a transaction).
     * Keeps one row per combination with the given stock, removes rows no
     * longer offered, switches the options on/off and sets the product's
     * stock to the sum of its options. With both options off nothing is kept.
     *
     * @param list<string>                                  $sizes
     * @param list<array{name: string, hex: ?string}>       $colours
     * @param array<string, array<string, int>>             $stock [size][colour] => qty ('' when unused)
     */
    public static function saveVariants(int $productId, bool $hasSizes, bool $hasColours, array $sizes, array $colours, array $stock): void
    {
        db_update('products', ['has_sizes' => $hasSizes ? 1 : 0, 'has_colours' => $hasColours ? 1 : 0], ['id' => $productId]);
        if (!$hasSizes && !$hasColours) {
            db_query('DELETE FROM product_variants WHERE product_id = ?', [$productId]);
            return;
        }
        $sizeList = $hasSizes ? $sizes : [''];
        $colourList = $hasColours ? $colours : [['name' => '', 'hex' => null]];
        $keep = [];
        $sort = 0;
        foreach ($sizeList as $size) {
            foreach ($colourList as $c) {
                $qty = max(0, (int) ($stock[$size][$c['name']] ?? 0));
                db_query(
                    'INSERT INTO product_variants (product_id, size, colour, colour_hex, stock_quantity, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE colour_hex = VALUES(colour_hex), stock_quantity = VALUES(stock_quantity), sort_order = VALUES(sort_order)',
                    [$productId, $size, $c['name'], $c['hex'], $qty, $sort++]
                );
                $keep[] = (int) db_value('SELECT id FROM product_variants WHERE product_id = ? AND size = ? AND colour = ?', [$productId, $size, $c['name']]);
            }
        }
        $marks = implode(',', array_fill(0, count($keep), '?'));
        db_query("DELETE FROM product_variants WHERE product_id = ? AND id NOT IN ({$marks})", array_merge([$productId], $keep));
        self::syncStockFromVariants($productId);
    }

    /** products.stock_quantity = sum of its option stock (products using options only). */
    public static function syncStockFromVariants(int $productId): void
    {
        db_query(
            'UPDATE products p
                SET p.stock_quantity = (SELECT COALESCE(SUM(v.stock_quantity), 0) FROM product_variants v WHERE v.product_id = p.id)
              WHERE p.id = ? AND (p.has_sizes = 1 OR p.has_colours = 1)',
            [$productId]
        );
    }

    /** Make $imageId the main image; with null, promote the first remaining one. */
    public static function setPrimaryImage(int $productId, ?int $imageId): void
    {
        db_query('UPDATE product_images SET is_primary = 0 WHERE product_id = ?', [$productId]);
        if ($imageId === null) {
            $imageId = (int) db_value(
                'SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT 1',
                [$productId]
            );
        }
        if ($imageId) {
            db_query('UPDATE product_images SET is_primary = 1 WHERE product_id = ? AND id = ?', [$productId, $imageId]);
        }
    }

    /** Columns the admin form may write. `price` is generated and never written. */
    private static function only(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'category_id', 'sku', 'name', 'slug', 'short_description', 'description',
            'regular_price', 'sale_price', 'stock_quantity',
            'is_active', 'is_featured', 'is_new',
        ]));
    }
}
