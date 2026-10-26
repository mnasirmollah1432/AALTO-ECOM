<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Money;
use App\Models\Tracking;
use App\Models\Wishlist;

/**
 * Guest wishlist (session). CSRF-protected POST + redirect, like the cart.
 */
final class WishlistController
{
    /** GET /wishlist */
    public function show(): string
    {
        return view('pages/wishlist', [
            'products' => Wishlist::products(),
            'meta'     => ['title' => 'Your wishlist', 'robots' => 'noindex, nofollow'],
        ], 'main');
    }

    /** POST /wishlist/toggle  (product_id, return) */
    public function toggle(): never
    {
        $back = safe_return_path($_POST['return'] ?? null, 'wishlist');
        if (!csrf_valid()) {
            flash('error', 'Your session expired. Please try again.');
            redirect($back);
        }
        $id = is_string($_POST['product_id'] ?? null) && ctype_digit($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $wasSaved = $id > 0 && Wishlist::has($id);
        [$type, $message] = Wishlist::toggle($_POST['product_id'] ?? null);
        if (!$wasSaved && $id > 0 && Wishlist::has($id)) {
            self::trackSaved($id);
        }
        flash($type, $message);
        redirect($back);
    }

    /** Facebook Pixel AddToWishlist / GTM add_to_wishlist, shown on the page the shopper returns to. */
    private static function trackSaved(int $id): void
    {
        $p = Tracking::enabled() ? db_one('SELECT p.sku, p.name, p.price, c.name AS category FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = ?', [$id]) : null;
        if ($p === null) {
            return;
        }
        $unit = Money::toCents($p['price']);
        Tracking::queue(Tracking::event('add_to_wishlist', [[
            'id' => $p['sku'], 'name' => $p['name'], 'price_cents' => $unit, 'quantity' => 1, 'category' => $p['category'],
        ]], $unit));
    }
}
