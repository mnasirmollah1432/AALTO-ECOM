<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Money;
use App\Models\Cart;
use App\Models\CheckoutException;
use App\Models\Location;
use App\Models\Order;
use App\Models\Tracking;
use Throwable;

/**
 * Guest checkout (name, mobile, district, thana, address) with Cash on
 * Delivery. The delivery charge comes from the district's zone.
 */
final class CheckoutController
{
    /** Seconds the order confirmation page stays viewable after ordering. */
    private const CONFIRMATION_TTL = 1800;

    /** GET /checkout */
    public function show(): string
    {
        self::noStore();
        $cart = Cart::build();
        if (!$cart['lines']) {
            flash('info', 'Your cart is empty. Add some products before checking out.');
            redirect('cart');
        }

        // Facebook Pixel InitiateCheckout / GTM begin_checkout
        Tracking::add(Tracking::event('begin_checkout', array_map(static fn ($l) => [
            'id' => (string) $l['sku'], 'name' => $l['name'], 'price_cents' => $l['unit_cents'], 'quantity' => $l['quantity'],
            'category' => $l['category_name'], 'variant' => $l['variant_label'],
        ], $cart['lines']), $cart['subtotal']));

        return view('pages/checkout', [
            'cart'    => $cart,
            'tree'    => Location::checkoutTree(),
            'notices' => Cart::pullNotices(),
            'errors'  => errors(),
            'meta'    => ['title' => 'Checkout', 'robots' => 'noindex, nofollow'],
        ], 'main');
    }

    /** POST /checkout */
    public function place(): never
    {
        self::noStore();

        // Empty cart: nothing can be created. A repeat click right after a
        // successful order (whose form token is now used up) simply goes to
        // that order's confirmation instead of a confusing error.
        if (!Cart::raw()) {
            if (isset($_SESSION['_last_order'])) {
                redirect('order-success');
            }
            flash('info', 'Your cart is empty.');
            redirect('cart');
        }

        if (!csrf_valid()) {
            flash('error', 'Your session expired. Please check your details and try again.');
            redirect('checkout');
        }

        [$customer, $errors] = Order::validateCustomer($_POST);
        if (($_POST['payment_method'] ?? '') !== Order::PAYMENT_COD) {
            $errors['payment_method'] = 'Please choose a payment method.';
        }
        if ($errors) {
            self::back($errors);
        }

        try {
            $shown = $_POST['delivery_shown'] ?? null;
            $shown = is_string($shown) && preg_match('/^\d{1,7}(\.\d{1,2})?$/', $shown) ? $shown : null; // empty without JavaScript
            $order = Order::place($customer, Cart::raw(), (string) ($_POST['cart_fingerprint'] ?? ''), $shown);
        } catch (CheckoutException $e) {
            // Something in the cart changed; nothing was saved. The checkout
            // page rebuilds the cart (adjusting stock/availability) for review.
            flash('error', 'Your order was not placed: ' . implode(' ', $e->messages()));
            self::back([], false);
        } catch (Throwable $e) {
            log_message('error', 'Order failed: ' . $e::class . ': ' . $e->getMessage());
            flash('error', 'Sorry, we could not place your order. Nothing was charged and your cart is unchanged - please try again.');
            self::back([], false);
        }

        Cart::clear();
        csrf_regenerate();
        $_SESSION['_last_order'] = $order['id'];
        $_SESSION['_last_order_at'] = time();
        log_message('info', sprintf('Order %s placed (id %d)', $order['number'], $order['id']));

        redirect('order-success');
    }

    /** GET /order-success - only shows the order placed in this browser session */
    public function success(): string
    {
        self::noStore();
        // The confirmation shows name, phone and address: keep it viewable only
        // shortly after ordering (shared or public computers)
        if (time() - (int) ($_SESSION['_last_order_at'] ?? 0) > self::CONFIRMATION_TTL) {
            unset($_SESSION['_last_order'], $_SESSION['_last_order_at']);
        }
        $id = $_SESSION['_last_order'] ?? null;
        $order = is_int($id) ? Order::findWithItems($id) : null;
        if ($order === null) {
            redirect('/');
        }

        // Facebook Pixel Purchase / GTM purchase - only once per order (not on refresh)
        if (Tracking::enabled() && Tracking::firstPurchaseReport($order['order_number'])) {
            // Current category of each product (order lines keep name/SKU/price, not the category)
            $ids = array_values(array_filter(array_map(static fn ($i) => (int) $i['product_id'], $order['items'])));
            $categories = $ids ? array_column(db_all(
                'SELECT p.id, c.name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            ), 'name', 'id') : [];
            $items = array_map(static fn ($i) => [
                'id' => (string) $i['product_sku'], 'name' => $i['product_name'], 'price_cents' => Money::toCents($i['unit_price']),
                'quantity' => (int) $i['quantity'], 'variant' => (string) ($i['variant_label'] ?? ''),
                'category' => (string) ($categories[(int) $i['product_id']] ?? ''),
            ], $order['items']);
            // The checkout steps before the purchase: delivery area, then payment (cash on delivery)
            Tracking::add(Tracking::event('add_shipping_info', $items, Money::toCents($order['grand_total']), null, [
                'ecommerce' => ['shipping_tier' => (string) ($order['delivery_zone'] ?? '')],
                'fbData'    => ['shipping_tier' => (string) ($order['delivery_zone'] ?? '')],
            ]));
            $payment = ($order['payment_method'] ?? 'cod') === 'cod' ? 'Cash on Delivery' : (string) $order['payment_method'];
            Tracking::add(Tracking::event('add_payment_info', $items, Money::toCents($order['grand_total']), null, [
                'ecommerce' => ['payment_type' => $payment],
                'fbData'    => ['payment_type' => $payment],
            ]));
            Tracking::add(Tracking::event('purchase', $items, Money::toCents($order['grand_total']), $order['order_number']));
            Tracking::setCustomer($order); // Advanced Matching (hashed) for this Purchase
        }

        return view('pages/order-success', [
            'order' => $order,
            'meta'  => ['title' => 'Order ' . $order['order_number'] . ' confirmed', 'robots' => 'noindex, nofollow'],
        ], 'main');
    }

    /** Back to the form with the typed details (never prices or totals). */
    private static function back(array $errors, bool $withMessage = true): never
    {
        with_input([
            'name'    => mb_substr(is_string($_POST['name'] ?? null) ? $_POST['name'] : '', 0, 200),
            'mobile'  => mb_substr(is_string($_POST['mobile'] ?? null) ? $_POST['mobile'] : '', 0, 40),
            'address' => mb_substr(is_string($_POST['address'] ?? null) ? $_POST['address'] : '', 0, 1000),
            'district_id' => is_string($_POST['district_id'] ?? null) && ctype_digit($_POST['district_id']) ? substr($_POST['district_id'], 0, 10) : '',
            'thana_id'    => is_string($_POST['thana_id'] ?? null) && ctype_digit($_POST['thana_id']) ? substr($_POST['thana_id'], 0, 10) : '',
        ], $errors);
        if ($withMessage) {
            flash('error', 'Please check the highlighted fields.');
        }
        redirect('checkout');
    }

    /** Pages with personal details must not be cached. */
    private static function noStore(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
        }
    }
}
