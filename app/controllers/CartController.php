<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Money;
use App\Models\Cart;
use App\Models\Tracking;

/**
 * Shopping cart (session based).
 *
 * Every action is a CSRF-protected POST that redirects afterwards
 * (Post/Redirect/Get), so refreshing never repeats an action.
 * Only the line (product id, or product:option) and quantity - plus the
 * chosen size/colour when adding - are read from the request; prices,
 * names and stock always come from the database (see App\Models\Cart).
 */
final class CartController
{
    /** GET /cart */
    public function show(): string
    {
        $cart = Cart::build();
        if ($cart['lines']) { // Facebook Pixel ViewCart / GTM view_cart
            Tracking::add(Tracking::event('view_cart', Tracking::cartItems($cart['lines']), $cart['subtotal']));
        }

        return view('pages/cart', [
            'cart'    => $cart,
            'notices' => Cart::pullNotices(),
            'meta'    => [
                'title'  => 'Your cart',
                'description' => 'Review the items in your ' . config('app.name') . ' shopping cart.',
                'robots' => 'noindex, nofollow',
            ],
        ], 'main');
    }

    /**
     * POST /cart/add  (product_id, quantity, size, colour, return [, buy_now])
     * buy_now ("Order Now"): add the item unless that product/option is already
     * in the cart, then go straight to checkout.
     */
    public function add(): never
    {
        $back = safe_return_path($_POST['return'] ?? null, 'cart');
        self::guard($back);
        $buyNow = ($_POST['buy_now'] ?? '') === '1';

        if ($buyNow && ($key = self::chosenLine()) !== null && Cart::quantityOf($key) > 0) {
            redirect('checkout');
        }

        [$type, $message] = Cart::add($_POST['product_id'] ?? null, $_POST['quantity'] ?? '1', $_POST['size'] ?? null, $_POST['colour'] ?? null);
        if ($type !== 'error') {
            self::trackAdd();
            // The product page re-selects this size/colour so its - N + stepper shows
            $_SESSION['_last_added'] = [
                'product_id' => is_string($_POST['product_id'] ?? null) ? (int) $_POST['product_id'] : 0,
                'size'       => is_string($_POST['size'] ?? null) ? mb_substr($_POST['size'], 0, 40) : '',
                'colour'     => is_string($_POST['colour'] ?? null) ? mb_substr($_POST['colour'], 0, 40) : '',
            ];
            if ($buyNow) {
                if ($type !== 'success') {
                    flash($type, $message); // e.g. "only 3 available, so we added 3"
                }
                redirect('checkout');
            }
            if (!in_array(strtok($back, '?'), ['cart', 'checkout'], true)) {
                $_SESSION['_open_cart'] = true; // show the side cart on the page we return to
            }
        }
        flash($type, $message);
        redirect($back);
    }

    /** Cart line key of the product (and chosen size/colour) in the add form, or null. */
    private static function chosenLine(): int|string|null
    {
        $id = is_string($_POST['product_id'] ?? null) && ctype_digit($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $p = $id ? db_one('SELECT has_sizes, has_colours FROM products WHERE id = ?', [$id]) : null;
        if ($p === null) {
            return null;
        }
        if (!$p['has_sizes'] && !$p['has_colours']) {
            return Cart::key($id, null);
        }
        $opt = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $_POST[$k])) : '';
        $v = \App\Models\Product::findVariant($id, $p['has_sizes'] ? $opt('size') : '', $p['has_colours'] ? $opt('colour') : '');
        return $v !== null ? Cart::key($id, (int) $v['id']) : null;
    }

    /** Facebook Pixel AddToCart / GTM add_to_cart, shown on the page the shopper returns to. */
    private static function trackAdd(): void
    {
        $id = is_string($_POST['product_id'] ?? null) && ctype_digit($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $p = $id ? db_one('SELECT p.sku, p.name, p.price, c.name AS category FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = ?', [$id]) : null;
        if ($p === null) {
            return;
        }
        $qty = is_string($_POST['quantity'] ?? null) && ctype_digit($_POST['quantity']) ? max(1, min(Cart::MAX_QTY, (int) $_POST['quantity'])) : 1;
        $variant = implode(' / ', array_filter([$_POST['size'] ?? '', $_POST['colour'] ?? ''], static fn ($v) => is_string($v) && $v !== ''));
        $unit = Money::toCents($p['price']);
        Tracking::queue(Tracking::event('add_to_cart', [[
            'id' => $p['sku'], 'name' => $p['name'], 'price_cents' => $unit, 'quantity' => $qty,
            'category' => $p['category'], 'variant' => mb_substr($variant, 0, 80),
        ]], $unit * $qty));
    }

    /**
     * POST /cart/update  (line, quantity)  or  (line, step = -1 | 1)
     * "line" is the cart line key; "product_id" is still accepted for plain products.
     * The +/- buttons send a step; the new quantity is worked out from the
     * quantity already stored in the session, not from the page.
     */
    public function update(): never
    {
        // The product page's - N + stepper sends its own page as "return"
        $back = safe_return_path($_POST['return'] ?? null, 'cart');
        self::guard($back);

        $line = self::line();
        $quantity = $_POST['quantity'] ?? null;
        $step = $_POST['step'] ?? null;
        if ($step === '1' || $step === '-1') {
            $parsed = Cart::parseKey($line);
            $current = $parsed ? Cart::quantityOf($parsed[2]) : 0;
            $quantity = $current > 0 ? (string) max(0, $current + (int) $step) : null;
        }

        $before = self::quantityOfLine($line);
        [$type, $message] = Cart::update($line, $quantity);
        // + is an AddToCart, - a RemoveFromCart, for the quantity that changed
        $change = self::quantityOfLine($line) - $before;
        if ($change !== 0) {
            self::trackLine($change > 0 ? 'add_to_cart' : 'remove_from_cart', $line, abs($change));
        }
        self::reopenDrawer();
        // Back on the product page, keep this size/colour selected so the stepper stays in view
        $parsed = Cart::parseKey($line);
        if ($parsed !== null && $parsed[1] !== null && $back !== 'cart'
            && ($v = db_one('SELECT size, colour FROM product_variants WHERE id = ? AND product_id = ?', [$parsed[1], $parsed[0]])) !== null) {
            $_SESSION['_last_added'] = ['product_id' => $parsed[0], 'size' => $v['size'], 'colour' => $v['colour']];
        }
        flash($type, $message);
        redirect($back);
    }

    /** POST /cart/remove  (line) */
    public function remove(): never
    {
        $back = safe_return_path($_POST['return'] ?? null, 'cart'); // the side cart sends its page
        self::guard($back);
        $before = self::quantityOfLine(self::line());
        [$type, $message] = Cart::remove(self::line());
        if ($before > 0 && self::quantityOfLine(self::line()) === 0) {
            self::trackLine('remove_from_cart', self::line(), $before);
        }
        self::reopenDrawer();
        flash($type, $message);
        redirect($back);
    }

    /** Changed from the side cart: open it again on the page it came from. */
    private static function reopenDrawer(): void
    {
        if (($_POST['drawer'] ?? '') === '1') {
            $_SESSION['_open_cart'] = true;
        }
    }

    /** POST /cart/clear */
    public function clear(): never
    {
        self::guard('cart');
        $cart = Cart::build();
        [$type, $message] = Cart::clear();
        if ($cart['lines'] && !Cart::raw()) {
            Tracking::queue(Tracking::event('remove_from_cart', Tracking::cartItems($cart['lines']), $cart['subtotal']));
        }
        flash($type, $message);
        redirect('cart');
    }

    /** Quantity in the cart of a (possibly invalid) line from the form. */
    private static function quantityOfLine(mixed $line): int
    {
        $parsed = Cart::parseKey($line);
        return $parsed ? Cart::quantityOf($parsed[2]) : 0;
    }

    /** AddToCart / RemoveFromCart for one cart line, shown on the page the shopper returns to. */
    private static function trackLine(string $type, mixed $line, int $quantity): void
    {
        $parsed = Tracking::enabled() ? Cart::parseKey($line) : null;
        $p = $parsed ? db_one('SELECT p.sku, p.name, p.price, c.name AS category FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id = ?', [$parsed[0]]) : null;
        if ($p === null) {
            return;
        }
        $v = $parsed[1] !== null ? db_one('SELECT size, colour FROM product_variants WHERE id = ? AND product_id = ?', [$parsed[1], $parsed[0]]) : null;
        $unit = Money::toCents($p['price']);
        Tracking::queue(Tracking::event($type, [[
            'id' => $p['sku'], 'name' => $p['name'], 'price_cents' => $unit, 'quantity' => $quantity,
            'category' => $p['category'], 'variant' => $v ? implode(' / ', array_filter([(string) $v['size'], (string) $v['colour']], 'strlen')) : '',
        ]], $unit * $quantity));
    }

    /** Cart line key from the form ("line", or "product_id" from older pages). */
    private static function line(): mixed
    {
        return $_POST['line'] ?? $_POST['product_id'] ?? null;
    }

    private static function guard(string $back): void
    {
        if (!csrf_valid()) {
            flash('error', 'Your session expired. Please try again.');
            redirect($back);
        }
    }
}
