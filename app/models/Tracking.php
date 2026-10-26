<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

/**
 * Facebook (Meta) Pixel and Google Tag Manager (Admin -> Marketing & tracking).
 *
 * Only the IDs are stored. The storefront prints them, with the page's
 * e-commerce events, as a JSON block that public/assets/js/tracking.js reads,
 * so no inline JavaScript is needed and the Content Security Policy only has
 * to allow the Facebook / Google hosts (and only when an ID is set).
 *
 * Events (Pixel name / GA4 dataLayer name):
 *   ViewContent / view_item, AddToCart / add_to_cart,
 *   InitiateCheckout / begin_checkout, Purchase / purchase (once per order),
 *   ViewCategory* / view_item_list (home, shop, category), Search / search,
 *   ViewCart* / view_cart (cart page, side cart), RemoveFromCart* / remove_from_cart,
 *   AddToWishlist / add_to_wishlist, AddShippingInfo* / add_shipping_info and
 *   AddPaymentInfo / add_payment_info (with the purchase).
 *   (* = Pixel custom event; tracking.js also sends Contact / contact_click
 *   for WhatsApp, Messenger, imo, phone and email links.)
 */
final class Tracking
{
    private const SESSION_QUEUE = '_track_events';

    /** GA4 name => Pixel standard event */
    private const PIXEL_STANDARD = [
        'view_item' => 'ViewContent', 'add_to_cart' => 'AddToCart', 'begin_checkout' => 'InitiateCheckout', 'purchase' => 'Purchase',
        'search' => 'Search', 'add_to_wishlist' => 'AddToWishlist', 'add_payment_info' => 'AddPaymentInfo',
    ];

    /** GA4 name => Pixel custom event (sent with fbq('trackCustom')) */
    private const PIXEL_CUSTOM = [
        'view_item_list' => 'ViewCategory', 'view_cart' => 'ViewCart', 'remove_from_cart' => 'RemoveFromCart', 'add_shipping_info' => 'AddShippingInfo',
    ];

    /** Most products listed in one view_item_list / search event */
    private const LIST_MAX = 20;

    /** Events for the current page, printed by the layout. */
    private static array $events = [];

    public static function pixelId(): string
    {
        $id = Setting::get('fb_pixel_id');
        return preg_match('/^\d{8,20}$/', $id) ? $id : '';
    }

    public static function gtmId(): string
    {
        $id = Setting::get('gtm_id');
        return preg_match('/^GTM-[A-Z0-9]{4,12}$/', $id) ? $id : '';
    }

    public static function enabled(): bool
    {
        return self::pixelId() !== '' || self::gtmId() !== '';
    }

    /**
     * Extra Content-Security-Policy sources for the tags that are switched on.
     *
     * Measured in a real browser: the Meta Pixel posts events to rotating cloud
     * hosts (*.run.app, *.on.aws) and to www.facebook.com through a hidden
     * form/iframe, and a GTM container can load tags from any vendor. So while
     * tracking is on, analytics may connect / send images / open frames to any
     * https host. Inline scripts stay blocked (the main protection against
     * injected scripts), and admin pages never get these sources.
     *
     * @return array{script: list<string>, img: list<string>, connect: list<string>, frame: list<string>, form: list<string>}
     */
    public static function cspSources(): array
    {
        $src = ['script' => [], 'img' => [], 'connect' => [], 'frame' => [], 'form' => []];
        $pixel = self::pixelId() !== '';
        $gtm = self::gtmId() !== '';
        if (!$pixel && !$gtm) {
            return $src;
        }
        $src['img'] = ['https:'];
        $src['connect'] = ['https:'];
        $src['frame'] = ['https://www.facebook.com', 'https://www.googletagmanager.com', 'https://td.doubleclick.net'];
        $src['form'] = ['https://www.facebook.com'];
        // Scripts: the Pixel's own host; with GTM any https host (tags come from many vendors)
        $src['script'] = $gtm ? ['https:'] : ['https://connect.facebook.net'];
        return $src;
    }

    /* --------------------------------------------------------
     | Events
     * ------------------------------------------------------ */

    /**
     * One e-commerce event.
     *
     * @param string $type  a key of PIXEL_STANDARD or PIXEL_CUSTOM (the GA4 event name)
     * @param list<array{id: string, name: string, price_cents: int, quantity: int, category?: string, variant?: string}> $items
     * @param array{ecommerce?: array, fbData?: array, ga?: array} $extra more GA4 ecommerce / Pixel fields
     *        (item_list_name, payment_type, search_string, ...) and top-level dataLayer keys (search_term)
     */
    public static function event(string $type, array $items, int $valueCents, ?string $eventId = null, array $extra = []): array
    {
        $pixel = self::PIXEL_STANDARD[$type] ?? self::PIXEL_CUSTOM[$type];
        $currency = (string) config('app.store.currency', 'BDT');
        $value = (float) Money::fromCents($valueCents); // display/analytics only - money is never calculated here
        $gaItems = [];
        $contents = [];
        foreach ($items as $i) {
            $gaItems[] = array_filter([
                'item_id'       => $i['id'],
                'item_name'     => $i['name'],
                'price'         => (float) Money::fromCents($i['price_cents']),
                'quantity'      => $i['quantity'],
                'item_category' => $i['category'] ?? null,
                'item_variant'  => $i['variant'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
            $contents[] = ['id' => $i['id'], 'quantity' => $i['quantity'], 'item_price' => (float) Money::fromCents($i['price_cents'])];
        }
        $ecommerce = ['currency' => $currency, 'value' => $value, 'items' => $gaItems];
        if ($type === 'purchase' && $eventId !== null) {
            $ecommerce['transaction_id'] = $eventId;
        }
        $ecommerce += $extra['ecommerce'] ?? [];
        // Several products: names / categories joined with ", " (as the marketer's spec does)
        $join = static fn (string $key): ?string => ($v = implode(', ', array_unique(array_filter(array_map(static fn ($i) => (string) ($i[$key] ?? ''), $items), 'strlen')))) !== '' ? $v : null;
        return array_filter([
            'ga'        => $type,
            'ecommerce' => $ecommerce,
            'fb'        => $pixel,
            'fbData'    => array_filter([
                'content_ids'      => array_column($contents, 'id'),
                'content_name'     => $join('name'),
                'content_category' => $join('category'),
                'contents'         => $contents,
                'content_type'     => 'product',
                'value'            => $value,
                'currency'         => $currency,
                'num_items'        => array_sum(array_column($contents, 'quantity')),
            ], static fn ($v) => $v !== null) + ($extra['fbData'] ?? []),
            'fbCustom'  => isset(self::PIXEL_CUSTOM[$type]) ? true : null,
            'gaData'    => $extra['ga'] ?? null,
            // Unique per event, so a server-side (Conversions API) copy can be matched later
            'eventId'   => $eventId ?? bin2hex(random_bytes(8)),
        ], static fn ($v) => $v !== null);
    }

    /** Event items from Cart::build() lines. */
    public static function cartItems(array $lines): array
    {
        return array_map(static fn ($l) => [
            'id' => (string) $l['sku'], 'name' => $l['name'], 'price_cents' => $l['unit_cents'], 'quantity' => $l['quantity'],
            'category' => $l['category_name'], 'variant' => (string) ($l['variant_label'] ?? ''),
        ], $lines);
    }

    /** Event items from product listing rows (one of each). */
    private static function listItems(array $products): array
    {
        return array_map(static fn ($p) => [
            'id' => (string) $p['sku'], 'name' => $p['name'], 'price_cents' => Money::toCents($p['price']), 'quantity' => 1,
            'category' => (string) ($p['category_name'] ?? ''),
        ], array_slice($products, 0, self::LIST_MAX));
    }

    /** ViewCategory / view_item_list for a list of products (home, shop, category). */
    public static function listView(string $listName, array $products): void
    {
        if (!self::enabled() || !$products) {
            return;
        }
        self::add(self::event('view_item_list', self::listItems($products), 0, null, [
            'ecommerce' => ['item_list_name' => $listName],
            'fbData'    => ['content_category' => $listName],
        ]));
    }

    /** Search / search: a shop search and its results. */
    public static function search(string $term, array $products): void
    {
        if (!self::enabled() || $term === '') {
            return;
        }
        self::add(self::event('search', self::listItems($products), 0, null, [
            'ecommerce' => ['item_list_name' => 'Search results'],
            'fbData'    => ['search_string' => $term],
            'ga'        => ['search_term' => $term],
        ]));
    }

    /* --------------------------------------------------------
     | Advanced Matching (customer details for the Pixel)
     * ------------------------------------------------------ */

    /** Hashed customer details for this page's Pixel init (Purchase page only). */
    private static ?array $user = null;

    /**
     * Customer details from an order, normalised as Meta requires and hashed
     * with SHA-256 here on the server, so readable details never appear in the
     * page's tracking data:
     *   ph  digits with country code (8801712345678)
     *   fn  first name, ln last name: lowercase, letters only
     *   ct  city = district, lowercase without spaces or punctuation
     *   country "bd"; external_id = the phone, so one customer matches across orders
     */
    public static function setCustomer(array $order): void
    {
        if (self::pixelId() === '') {
            return;
        }
        $digits = (string) preg_replace('/\D/', '', (string) ($order['customer_phone'] ?? ''));
        $phone = preg_match('/^01\d{9}$/', $digits) ? '88' . $digits : (preg_match('/^8801\d{9}$/', $digits) ? $digits : '');
        $clean = static fn (string $s): string => (string) preg_replace('/[^\p{L}\p{M}]+/u', '', mb_strtolower(trim($s)));
        $words = preg_split('/\s+/u', trim((string) ($order['customer_name'] ?? ''))) ?: [];
        $first = $clean((string) array_shift($words));
        $last = $clean(implode('', $words));
        $city = $clean((string) ($order['shipping_district'] ?? ''));
        $country = strtolower((string) config('app.store.country', 'BD'));

        $hash = static fn (string $v): ?string => $v !== '' ? hash('sha256', $v) : null;
        self::$user = array_filter([
            'ph'          => $hash($phone),
            'fn'          => $hash($first),
            'ln'          => $hash($last),
            'ct'          => $hash($city),
            'country'     => $hash($country),
            'external_id' => $hash($phone),
        ], static fn ($v) => $v !== null) ?: null;
    }

    /** Add an event to the current page. */
    public static function add(array $event): void
    {
        if (self::enabled()) {
            self::$events[] = $event;
        }
    }

    /** Remember an event for the next page (after a redirect, e.g. Add to cart). */
    public static function queue(array $event): void
    {
        if (!self::enabled()) {
            return;
        }
        $queue = is_array($_SESSION[self::SESSION_QUEUE] ?? null) ? $_SESSION[self::SESSION_QUEUE] : [];
        $queue[] = $event;
        $_SESSION[self::SESSION_QUEUE] = array_slice($queue, -5);
    }

    /** True the first time a purchase is reported in this session (no double counting on refresh). */
    public static function firstPurchaseReport(string $orderNumber): bool
    {
        $done = is_array($_SESSION['_tracked_orders'] ?? null) ? $_SESSION['_tracked_orders'] : [];
        if (in_array($orderNumber, $done, true)) {
            return false;
        }
        $done[] = $orderNumber;
        $_SESSION['_tracked_orders'] = array_slice($done, -10);
        return true;
    }

    /** JSON for public/assets/js/tracking.js, or null when tracking is off. */
    public static function payload(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $events = self::$events;
        if (is_array($_SESSION[self::SESSION_QUEUE] ?? null)) {
            $events = array_merge($_SESSION[self::SESSION_QUEUE], $events);
            unset($_SESSION[self::SESSION_QUEUE]);
        }
        $cart = Cart::build();
        // ViewCart / view_cart when the side cart is opened (tracking.js sends it once per page)
        $cartView = cart_drawer_enabled() && $cart['lines']
            ? self::event('view_cart', self::cartItems($cart['lines']), $cart['subtotal']) : null;
        return json_encode(array_filter([
            'pixel'    => self::pixelId() ?: null,
            'gtm'      => self::gtmId() ?: null,
            'user'     => self::$user, // hashed Advanced Matching data (Purchase page only)
            'page'     => self::pageInfo($cart),
            'events'   => $events,
            'cartView' => $cartView,
        ], static fn ($v) => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** Page details pushed to the GTM dataLayer before the container loads (for GTM triggers and variables). */
    private static function pageInfo(array $cart): array
    {
        $path = request_path();
        $type = match (true) {
            $path === '/'                        => 'home',
            str_starts_with($path, '/product/')  => 'product',
            str_starts_with($path, '/category/') => 'category',
            $path === '/shop'                    => is_string($_GET['q'] ?? null) && trim($_GET['q']) !== '' ? 'search' : 'shop',
            $path === '/cart'                    => 'cart',
            $path === '/checkout'                => 'checkout',
            $path === '/order-success'           => 'purchase',
            $path === '/wishlist'                => 'wishlist',
            $path === '/contact'                 => 'contact',
            default                              => 'other',
        };
        return [
            'pageType'    => $type,
            'visitorType' => 'guest',
            'cartItems'   => $cart['count'],
            'cartValue'   => (float) Money::fromCents($cart['subtotal']),
            'currency'    => (string) config('app.store.currency', 'BDT'),
        ];
    }
}
