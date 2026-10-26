<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Guest wishlist, kept in the visitor's session (no customer accounts).
 * The session holds only product ids; products are read from the database.
 */
final class Wishlist
{
    private const SESSION_KEY = 'wishlist';
    public const MAX_ITEMS = 100;

    /** @return list<int> product ids, newest first */
    public static function ids(): array
    {
        $raw = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter($raw, static fn ($id) => is_int($id) && $id > 0)));
    }

    public static function has(int $productId): bool
    {
        return in_array($productId, self::ids(), true);
    }

    /** Active products still in the wishlist (inactive ones are left out). */
    public static function products(): array
    {
        $ids = self::ids();
        if (!$ids) {
            return [];
        }
        $products = Product::byIds($ids);
        // keep newest-first order
        $byId = array_column($products, null, 'id');
        return array_values(array_filter(array_map(static fn ($id) => $byId[$id] ?? null, $ids)));
    }

    public static function count(): int
    {
        return count(self::ids());
    }

    /**
     * Add or remove a product (from untrusted input).
     *
     * @return array{0: string, 1: string} flash [type, message]
     */
    public static function toggle(mixed $productId): array
    {
        $id = is_string($productId) && preg_match('/^[1-9]\d{0,9}$/', $productId) ? (int) $productId : 0;
        $name = $id > 0 ? db_value('SELECT p.name FROM products p JOIN categories c ON c.id = p.category_id AND c.is_active = 1 WHERE p.id = ? AND p.is_active = 1', [$id]) : null;
        $ids = self::ids();
        if (in_array($id, $ids, true)) {
            $_SESSION[self::SESSION_KEY] = array_values(array_diff($ids, [$id]));
            return ['success', $name ? sprintf('Removed "%s" from your wishlist.', $name) : 'Removed from your wishlist.'];
        }
        if ($name === null) {
            return ['error', 'Sorry, that product is not available.'];
        }
        if (count($ids) >= self::MAX_ITEMS) {
            return ['error', sprintf('Your wishlist can hold up to %d products.', self::MAX_ITEMS)];
        }
        array_unshift($ids, $id);
        $_SESSION[self::SESSION_KEY] = $ids;
        return ['success', sprintf('Saved "%s" to your wishlist.', $name)];
    }
}
