<?php

declare(strict_types=1);

namespace App\Models;

final class Category
{
    public const NAME_MAX = 120;
    public const SLUG_MAX = 140;
    public const DESCRIPTION_MAX = 1000;

    /** Sort options for the admin list: key => ORDER BY clause (whitelist). */
    public const SORTS = [
        'name'     => 'c.name ASC',
        'newest'   => 'c.created_at DESC, c.id DESC',
        'updated'  => 'c.updated_at DESC, c.id DESC',
        'products' => 'product_count DESC, c.name ASC',
    ];

    /* --------------------------------------------------------
     | Storefront
     * ------------------------------------------------------ */

    /**
     * Active categories with their active product counts.
     * Cached for the request: the header, footer and homepage all use it.
     */
    public static function allActive(): array
    {
        static $cache = null;
        return $cache ??= db_all(
            'SELECT c.id, c.name, c.slug, c.description, c.icon, c.image_path,
                    COUNT(p.id) AS product_count
               FROM categories c
               LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
              WHERE c.is_active = 1
              GROUP BY c.id, c.name, c.slug, c.description, c.icon, c.image_path, c.sort_order
              ORDER BY c.sort_order, c.name'
        );
    }

    /** An active category by slug, with its active product count, or null. */
    public static function findActiveBySlug(string $slug): ?array
    {
        foreach (self::allActive() as $category) {
            if ($category['slug'] === $slug) {
                return $category;
            }
        }
        return null;
    }

    /* --------------------------------------------------------
     | Admin
     * ------------------------------------------------------ */

    /**
     * Filtered, sorted, paginated list for the admin.
     *
     * @param array{q?: string, status?: string, sort?: string} $filters
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page = 1, int $perPage = 15): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // "!" escapes LIKE wildcards so "%" or "_" in a search are literal
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $where[] = "(c.name LIKE ? ESCAPE '!' OR c.slug LIKE ? ESCAPE '!' OR c.description LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }

        $status = $filters['status'] ?? '';
        if ($status === 'active') {
            $where[] = 'c.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'c.is_active = 0';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderSql = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['name'];

        $total = (int) db_value("SELECT COUNT(*) FROM categories c {$whereSql}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $items = db_all(
            "SELECT c.id, c.name, c.slug, c.description, c.image_path, c.icon, c.is_active,
                    c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count,
                    (SELECT COUNT(*) FROM categories ch WHERE ch.parent_id = c.id) AS child_count
               FROM categories c
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
            'SELECT c.*,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count,
                    (SELECT COUNT(*) FROM categories ch WHERE ch.parent_id = c.id) AS child_count
               FROM categories c
              WHERE c.id = ?',
            [$id]
        );
    }

    /** Case-insensitive (collation) name check, optionally ignoring one id. */
    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        return (bool) db_value(
            'SELECT 1 FROM categories WHERE name = ? AND id <> ? LIMIT 1',
            [$name, $exceptId ?? 0]
        );
    }

    public static function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) db_value(
            'SELECT 1 FROM categories WHERE slug = ? AND id <> ? LIMIT 1',
            [$slug, $exceptId ?? 0]
        );
    }

    /** $base, or $base-2, $base-3 ... whichever is free. */
    public static function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $base = $base !== '' ? $base : 'category';
        $slug = $base;
        for ($i = 2; self::slugExists($slug, $exceptId); $i++) {
            $suffix = '-' . $i;
            $slug = rtrim(substr($base, 0, self::SLUG_MAX - strlen($suffix)), '-') . $suffix;
        }
        return $slug;
    }

    public static function create(array $data): int
    {
        $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories');
        return db_insert('categories', self::only($data));
    }

    public static function update(int $id, array $data): void
    {
        db_update('categories', self::only($data), ['id' => $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        db_update('categories', ['is_active' => $active ? 1 : 0], ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        db_query('DELETE FROM categories WHERE id = ?', [$id]);
    }

    /** Columns the admin form may write. */
    private static function only(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'slug', 'description', 'image_path', 'is_active', 'sort_order',
        ]));
    }
}
