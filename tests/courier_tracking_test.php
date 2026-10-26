<?php
/**
 * Facebook Pixel / Google Tag Manager and the Steadfast courier integration.
 * Needs the dev server on :8080 and a freshly seeded aalto_mart_dev.
 * Starts tests/fake_steadfast.php on 127.0.0.1:8091 as the Steadfast API.
 */
declare(strict_types=1);

const B = 'http://localhost:8080';
const FAKE = 'http://127.0.0.1:8091';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$ADMIN = __DIR__ . '/ct_admin.txt'; $SHOP = __DIR__ . '/ct_shop.txt';
@unlink($ADMIN); @unlink($SHOP); @unlink(__DIR__ . '/fixtures/fake_steadfast.json');

// Fake Steadfast API
$fake = proc_open([PHP_BINARY, '-S', '127.0.0.1:8091', __DIR__ . '/fake_steadfast.php'], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
usleep(600000);
register_shutdown_function(static function () use ($fake) { proc_terminate($fake); });

function t(bool $ok, string $label, string $detail = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n"; }
function req(string $path, array|string|null $post = null, ?string $jar = null, array $headers = []): array {
    global $SHOP; $jar ??= $SHOP;
    $ch = curl_init(str_starts_with($path, 'http') ? $path : B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'CourierTest', CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post)); }
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return ['code' => $info['http_code'], 'head' => substr((string) $raw, 0, $info['header_size']), 'body' => substr((string) $raw, $info['header_size']), 'location' => $info['redirect_url']];
}
function tok(string $path, ?string $jar = null): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', req($path, null, $jar)['body'], $m); return $m[1] ?? ''; }
function val(string $sql): mixed { global $pdo; return $pdo->query($sql)->fetchColumn(); }
function row(string $sql): array { global $pdo; return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: []; }
function flash(string $html): string { preg_match_all('/<div class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m); return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode(' ', $m[1] ?? []))))); }
function tracking(string $html): ?array { return preg_match('#<script type="application/json" id="am-tracking">(.*?)</script>#s', $html, $m) ? json_decode($m[1], true) : null; }
/** The page's events of one GA4 type. */
function evs(?array $cfg, string $ga): array { return array_values(array_filter($cfg['events'] ?? [], static fn ($e) => ($e['ga'] ?? '') === $ga)); }
function csp(array $r): string { return preg_match('/^Content-Security-Policy: (.*)$/mi', $r['head'], $m) ? trim($m[1]) : ''; }
function fake(string $path): array { return json_decode((string) file_get_contents(FAKE . $path), true) ?? []; }
function placeOrder(int $productId = 1, int $qty = 1, string $mobile = '01712345678'): int {
    global $SHOP; @unlink($SHOP);
    req('/cart/add', ['_csrf' => tok('/shop'), 'product_id' => $productId, 'quantity' => $qty]);
    $h = req('/checkout')['body'];
    preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $h, $fp);
    $d = (int) val("SELECT id FROM districts WHERE name='Dhaka'");
    $t = (int) val("SELECT id FROM thanas WHERE district_id=$d AND name='Dhanmondi'");
    req('/checkout', ['_csrf' => tok('/checkout'), 'cart_fingerprint' => $fp[1] ?? '', 'payment_method' => 'cod', 'name' => 'Courier Test',
        'mobile' => $mobile, 'address' => "House 4, Road 2\nNear the mosque", 'district_id' => (string) $d, 'thana_id' => (string) $t]);
    return (int) val('SELECT MAX(id) FROM orders');
}

$lt = tok('/admin/login', $ADMIN);
req('/admin/login', ['_csrf' => $lt, 'email' => 'admin@example.com', 'password' => 'Admin@12345'], $ADMIN);

/* ============================================================ Tracking */
echo "== Tracking off by default\n";
$home = req('/');
t(tracking($home['body']) === null && !str_contains($home['body'], 'tracking.js'), 'no tracking code until an ID is set');
t(!str_contains(csp($home), 'facebook') && !str_contains(csp($home), 'googletagmanager'), 'security policy stays strict');

echo "== Admin: marketing & tracking\n";
t(req('/admin/tracking', null, __DIR__ . '/ct_anon.txt')['location'] === B . '/admin/login', 'signed out -> login'); @unlink(__DIR__ . '/ct_anon.txt');
foreach ([['fb_pixel_id', '12ab'], ['fb_pixel_id', '<script>alert(1)</script>'], ['gtm_id', 'GTM-'], ['gtm_id', 'UA-12345-1']] as [$k, $bad]) {
    req('/admin/tracking', ['_csrf' => tok('/admin/tracking', $ADMIN), 'fb_pixel_id' => '', 'gtm_id' => '', $k => $bad], $ADMIN);
    t((string) val("SELECT COALESCE(MAX(setting_value),'') FROM settings WHERE setting_key='$k'") === '', "$k \"$bad\" rejected");
}
$snippet = "<!-- Meta Pixel Code -->\n<script>!function(f,b,e,v,n,t,s){}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');\nfbq('init', '1234567890123456');\nfbq('track', 'PageView');</script>";
req('/admin/tracking', ['_csrf' => tok('/admin/tracking', $ADMIN), 'fb_pixel_id' => $snippet, 'gtm_id' => " gtm-ab12cd3 "], $ADMIN);
t(val("SELECT setting_value FROM settings WHERE setting_key='fb_pixel_id'") === '1234567890123456', 'pasted Pixel snippet -> only the ID is saved');
t(val("SELECT setting_value FROM settings WHERE setting_key='gtm_id'") === 'GTM-AB12CD3', 'GTM ID cleaned and upper-cased');

echo "== Storefront tracking\n";
$home = req('/');
$cfg = tracking($home['body']);
t($cfg !== null && $cfg['pixel'] === '1234567890123456' && $cfg['gtm'] === 'GTM-AB12CD3', 'tracking JSON with both IDs');
t((bool) preg_match('#<script src="[^"]*/assets/js/tracking\.js\?v=\d+" async></script>#', $home['body']), 'tracking.js loaded (async, from this site)');
t(!preg_match('#<script>(?!</script>)#', $home['body']) && !str_contains($home['body'], 'fbq('), 'no inline JavaScript in the page');
t(str_contains($home['body'], 'googletagmanager.com/ns.html?id=GTM-AB12CD3'), 'GTM noscript iframe');
t(str_contains($home['body'], '<noscript><img height="1" width="1" style="display:none" alt="" loading="eager" src="https://www.facebook.com/tr?id=1234567890123456&amp;ev=PageView&amp;noscript=1"></noscript>'), 'Pixel noscript image (as in the Meta code)');
$c = csp($home);
t(str_contains($c, "script-src 'self' https:;") && str_contains($c, "connect-src 'self' https:") && str_contains($c, "form-action 'self' https://www.facebook.com") && str_contains($c, 'frame-src https://www.facebook.com https://www.googletagmanager.com'), 'GTM on: scripts/connections over https (any tag vendor), Facebook form + frame allowed');
t(!preg_match("/script-src[^;]*unsafe-inline/", $c), 'inline scripts still blocked');
t(!str_contains(csp(req('/admin/dashboard', null, $ADMIN)), 'facebook'), 'admin pages never load tracking hosts');
t(!str_contains(req('/admin/dashboard', null, $ADMIN)['body'], 'am-tracking'), 'no tracking on admin pages');
$js = (string) file_get_contents('D:/SDL-PROJECT/AALTO-ECOM/public/assets/js/tracking.js');
t(str_contains($js, "fbq('init', cfg.pixel)") || str_contains($js, "window.fbq('init', cfg.pixel)"), 'tracking.js initialises the Pixel from the JSON');

$p = tracking(req('/product/sample-wireless-earbuds')['body']);
$ev = $p['events'][0] ?? [];
t(($ev['fb'] ?? '') === 'ViewContent' && ($ev['ga'] ?? '') === 'view_item' && ($ev['fbData']['content_ids'][0] ?? '') === 'SMP-ELE-001' && ($ev['fbData']['value'] ?? 0) == 2499 && ($ev['fbData']['currency'] ?? '') === 'BDT', 'product page: ViewContent / view_item (SKU, 2499 BDT)');
@unlink($SHOP);
req('/cart/add', ['_csrf' => tok('/shop'), 'product_id' => 1, 'quantity' => 2, 'return' => '/shop']);
$after = tracking(req('/shop')['body']);
$ev = $after['events'][0] ?? [];
t(($ev['fb'] ?? '') === 'AddToCart' && ($ev['ecommerce']['items'][0]['quantity'] ?? 0) === 2 && ($ev['ecommerce']['value'] ?? 0) == 4998, 'after add to cart: AddToCart / add_to_cart (2 x 2499 = 4998)');
t(evs(tracking(req('/shop')['body']), 'add_to_cart') === [], 'add-to-cart event sent once');
req('/cart/add', ['_csrf' => tok('/shop'), 'product_id' => 999999, 'quantity' => 1, 'return' => '/shop']);
t(evs(tracking(req('/shop')['body']), 'add_to_cart') === [], 'failed add: no event');
$co = tracking(req('/checkout')['body']);
t(($co['events'][0]['fb'] ?? '') === 'InitiateCheckout' && ($co['events'][0]['ga'] ?? '') === 'begin_checkout' && ($co['events'][0]['fbData']['num_items'] ?? 0) === 2, 'checkout: InitiateCheckout / begin_checkout');
$oid = placeOrder(1, 1);
$num = val("SELECT order_number FROM orders WHERE id=$oid");
$s1 = tracking(req('/order-success')['body']);
$ev = evs($s1, 'purchase')[0] ?? [];
t(($ev['fb'] ?? '') === 'Purchase' && ($ev['eventId'] ?? '') === $num && ($ev['ecommerce']['transaction_id'] ?? '') === $num && ($ev['ecommerce']['value'] ?? 0) == 2569, "purchase: Purchase / purchase with order number $num, value 2569 (incl. delivery)");
t((tracking(req('/order-success')['body'])['events'] ?? []) === [], 'refreshing the confirmation does not count the purchase again');

echo "== More events (as WordPress pixel plugins send)\n";
$names = array_map(static fn ($e) => $e['ga'] . '/' . $e['fb'], $s1['events']);
t($names === ['add_shipping_info/AddShippingInfo', 'add_payment_info/AddPaymentInfo', 'purchase/Purchase'], 'order page: shipping info, payment info, then Purchase');
t(($s1['events'][1]['ecommerce']['payment_type'] ?? '') === 'Cash on Delivery' && ($s1['events'][1]['fbData']['payment_type'] ?? '') === 'Cash on Delivery' && empty($s1['events'][1]['fbCustom']), 'AddPaymentInfo (standard event): payment_type Cash on Delivery');
t(!empty($s1['events'][0]['fbCustom']) && ($s1['events'][0]['ecommerce']['shipping_tier'] ?? '') !== '', 'AddShippingInfo (custom event) with the delivery zone');
t(empty($s1['events'][2]['fbCustom']) && ($s1['events'][2]['eventId'] ?? '') === $num, 'Purchase unchanged: standard event, event ID = order number');
$home = tracking(req('/')['body']);
$list = evs($home, 'view_item_list')[0] ?? [];
t(($list['fb'] ?? '') === 'ViewCategory' && !empty($list['fbCustom']) && ($list['ecommerce']['item_list_name'] ?? '') === 'Home' && count($list['ecommerce']['items'] ?? []) > 0, 'home: ViewCategory / view_item_list (Home)');
t(($home['page']['pageType'] ?? '') === 'home' && ($home['page']['visitorType'] ?? '') === 'guest' && array_key_exists('cartItems', $home['page'] ?? []), 'dataLayer page details: pageType, visitorType, cartItems');
t((bool) preg_match('/^[a-f0-9]{16}$/', (string) ($list['eventId'] ?? '')), 'every event has its own event ID');
$cat = tracking(req('/category/electronics')['body']);
$list = evs($cat, 'view_item_list')[0] ?? [];
t(($list['ecommerce']['item_list_name'] ?? '') === 'Electronics' && ($list['fbData']['content_category'] ?? '') === 'Electronics' && ($cat['page']['pageType'] ?? '') === 'category', 'category: ViewCategory with the category name');
$sr = tracking(req('/shop?q=earbuds')['body']);
$se = evs($sr, 'search')[0] ?? [];
t(($se['fb'] ?? '') === 'Search' && empty($se['fbCustom']) && ($se['fbData']['search_string'] ?? '') === 'earbuds' && ($se['gaData']['search_term'] ?? '') === 'earbuds' && ($se['fbData']['content_ids'][0] ?? '') === 'SMP-ELE-001', 'search: Search / search with the term and results');
t(evs($sr, 'view_item_list') === [] && ($sr['page']['pageType'] ?? '') === 'search', 'search page: no ViewCategory, pageType search');
@unlink($SHOP);
req('/cart/add', ['_csrf' => tok('/shop'), 'product_id' => 1, 'quantity' => 2, 'return' => '/shop']);
req('/shop');
$cp = tracking(req('/cart')['body']);
$vc = evs($cp, 'view_cart')[0] ?? [];
t(($vc['fb'] ?? '') === 'ViewCart' && !empty($vc['fbCustom']) && ($vc['ecommerce']['value'] ?? 0) == 4998, 'cart page: ViewCart / view_cart (4998)');
t(!isset($cp['cartView']), 'no side-cart ViewCart on the cart page itself');
$sp = tracking(req('/shop')['body']);
t(($sp['cartView']['ga'] ?? '') === 'view_cart' && ($sp['cartView']['ecommerce']['items'][0]['quantity'] ?? 0) === 2, 'other pages: ViewCart ready for when the side cart opens');
req('/cart/update', ['_csrf' => tok('/shop'), 'line' => '1', 'step' => '1', 'return' => '/shop']);
$ev = evs(tracking(req('/shop')['body']), 'add_to_cart')[0] ?? [];
t(($ev['ecommerce']['items'][0]['quantity'] ?? 0) === 1 && ($ev['ecommerce']['value'] ?? 0) == 2499, 'stepper +: AddToCart for 1');
req('/cart/update', ['_csrf' => tok('/shop'), 'line' => '1', 'step' => '-1', 'return' => '/shop']);
$ev = evs(tracking(req('/shop')['body']), 'remove_from_cart')[0] ?? [];
t(($ev['fb'] ?? '') === 'RemoveFromCart' && !empty($ev['fbCustom']) && ($ev['ecommerce']['items'][0]['quantity'] ?? 0) === 1, 'stepper -: RemoveFromCart for 1');
req('/cart/update', ['_csrf' => tok('/shop'), 'line' => '1', 'quantity' => '5', 'return' => '/shop']);
$ev = evs(tracking(req('/shop')['body']), 'add_to_cart')[0] ?? [];
t(($ev['ecommerce']['items'][0]['quantity'] ?? 0) === 3, 'quantity typed 2 -> 5: AddToCart for 3');
req('/cart/remove', ['_csrf' => tok('/shop'), 'line' => '1', 'return' => '/shop']);
$ev = evs(tracking(req('/shop')['body']), 'remove_from_cart')[0] ?? [];
t(($ev['ecommerce']['items'][0]['quantity'] ?? 0) === 5 && ($ev['ecommerce']['value'] ?? 0) == 12495, 'remove line: RemoveFromCart for all 5');
req('/cart/remove', ['_csrf' => tok('/shop'), 'line' => '1', 'return' => '/shop']);
t(evs(tracking(req('/shop')['body']), 'remove_from_cart') === [], 'removing what is not in the cart: no event');
req('/cart/add', ['_csrf' => tok('/shop'), 'product_id' => 1, 'quantity' => 1, 'return' => '/shop']);
req('/cart/add', ['_csrf' => tok('/shop'), 'product_id' => 2, 'quantity' => 1, 'return' => '/shop']);
req('/shop');
req('/cart/clear', ['_csrf' => tok('/cart')]);
$ev = evs(tracking(req('/cart')['body']), 'remove_from_cart')[0] ?? [];
t(count($ev['ecommerce']['items'] ?? []) === 2, 'clear cart: one RemoveFromCart with both products');
req('/wishlist/toggle', ['_csrf' => tok('/shop'), 'product_id' => '1', 'return' => '/shop']);
$ev = evs(tracking(req('/shop')['body']), 'add_to_wishlist')[0] ?? [];
t(($ev['fb'] ?? '') === 'AddToWishlist' && empty($ev['fbCustom']) && ($ev['fbData']['content_ids'][0] ?? '') === 'SMP-ELE-001', 'wishlist save: AddToWishlist / add_to_wishlist');
req('/wishlist/toggle', ['_csrf' => tok('/shop'), 'product_id' => '1', 'return' => '/shop']);
t(evs(tracking(req('/shop')['body']), 'add_to_wishlist') === [], 'wishlist remove: no AddToWishlist');
$js = (string) file_get_contents('D:/SDL-PROJECT/AALTO-ECOM/public/assets/js/tracking.js');
t(str_contains($js, "'trackCustom'") && str_contains($js, "'Contact'") && str_contains($js, 'shown.bs.offcanvas') && str_contains($js, 'traffic_source') && str_contains($js, 'window.dataLayer.push(cfg.page)'), 'tracking.js: custom events, Contact clicks, side-cart ViewCart, traffic source, page details');
t(str_contains(req('/contact')['body'], 'data-contact="'), 'contact links are labelled for Contact tracking');

echo "== Marketer spec: content_name, content_category, Advanced Matching\n";
t(($p['events'][0]['fbData']['content_name'] ?? '') === 'Sample Wireless Earbuds' && ($p['events'][0]['fbData']['content_category'] ?? '') === 'Electronics', 'ViewContent: content_name + content_category');
t(($after['events'][0]['fbData']['content_name'] ?? '') === 'Sample Wireless Earbuds' && ($after['events'][0]['fbData']['content_category'] ?? '') === 'Electronics', 'AddToCart: content_name + content_category');
t(($co['events'][0]['fbData']['content_name'] ?? '') === 'Sample Wireless Earbuds' && ($co['events'][0]['fbData']['content_category'] ?? '') === 'Electronics', 'InitiateCheckout: content_name + content_category');
$pf = (evs($s1, 'purchase')[0] ?? [])['fbData'] ?? [];
t(($pf['content_name'] ?? '') === 'Sample Wireless Earbuds' && ($pf['content_category'] ?? '') === 'Electronics' && ($pf['num_items'] ?? 0) === 1
  && ($pf['contents'][0] ?? null) === ['id' => 'SMP-ELE-001', 'quantity' => 1, 'item_price' => 2499], 'Purchase: content_name, content_category, contents {id, quantity, item_price}, num_items');
t(((evs($s1, 'purchase')[0] ?? [])['ecommerce']['items'][0]['item_category'] ?? '') === 'Electronics', 'GTM purchase items now carry item_category too');
$u = $s1['user'] ?? [];
$h = static fn (string $v) => hash('sha256', $v);
t(($u['ph'] ?? '') === $h('8801712345678') && ($u['external_id'] ?? '') === $h('8801712345678'), 'Advanced Matching: phone 8801712345678 (country code), hashed; external_id from the phone');
t(($u['fn'] ?? '') === $h('courier') && ($u['ln'] ?? '') === $h('test'), 'Advanced Matching: first / last name lowercase, hashed');
t(($u['ct'] ?? '') === $h('dhaka') && ($u['country'] ?? '') === $h('bd'), 'Advanced Matching: city (district) and country "bd", hashed');
$rawJson = (string) (preg_match('#id="am-tracking">(.*?)</script>#s', req('/order-success')['body'], $mm) ? $mm[1] : '');
t(!isset(tracking(req('/order-success')['body'])['user']), 'refresh: customer details not sent again');
$oidB = placeOrder(2, 1, '01811111111');
$jsonB = (string) (preg_match('#id="am-tracking">(.*?)</script>#s', req('/order-success')['body'], $mm) ? $mm[1] : '');
t($jsonB !== '' && !str_contains($jsonB, '01811111111') && !str_contains($jsonB, '8801811111111') && !str_contains($jsonB, 'Courier Test') && str_contains($jsonB, '"user"'), 'no readable phone or name in the tracking data, only hashes');
t(!isset(tracking(req('/product/sample-wireless-earbuds')['body'])['user']) && !isset(tracking(req('/checkout')['body'] ?? '')['user']), 'customer details only on the Purchase page');
t(str_contains((string) file_get_contents('D:/SDL-PROJECT/AALTO-ECOM/public/assets/js/tracking.js'), "window.fbq('init', cfg.pixel, cfg.user)"), 'tracking.js passes them to fbq init');
req('/admin/tracking', ['_csrf' => tok('/admin/tracking', $ADMIN), 'fb_pixel_id' => '1234567890123456', 'gtm_id' => ''], $ADMIN);
$home = req('/');
t(!isset(tracking($home['body'])['gtm']) && str_contains(csp($home), "script-src 'self' https://connect.facebook.net;") && str_contains(csp($home), "connect-src 'self' https:"), 'Pixel only: scripts only from connect.facebook.net (its events may go to any https host)');
req('/admin/tracking', ['_csrf' => tok('/admin/tracking', $ADMIN), 'fb_pixel_id' => '', 'gtm_id' => ''], $ADMIN);
t(tracking(req('/')['body']) === null, 'both empty: tracking switched off');

/* ============================================================ Courier */
echo "== Admin: courier settings\n";
$page = req('/admin/courier', null, $ADMIN)['body'];
t(str_contains($page, 'name="api_key"') && str_contains($page, B . '/webhooks/steadfast') && (bool) preg_match('/id="wh-token" class="[^"]*" value="([a-f0-9]{48})"/', $page, $wt), 'settings page with webhook URL and generated token');
$token = $wt[1] ?? '';
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'api_key' => '', 'secret_key' => '', 'base_url' => FAKE . '/api/v1'], $ADMIN);
t(val("SELECT COALESCE(MAX(setting_value),'0') FROM settings WHERE setting_key='steadfast_enabled'") !== '1', 'switching on without keys refused');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'api_key' => 'bad key <x>', 'secret_key' => 'test-secret-key-654321', 'base_url' => FAKE . '/api/v1'], $ADMIN);
t(val("SELECT COALESCE(MAX(setting_value),'') FROM settings WHERE setting_key='steadfast_api_key'") === '', 'malformed key refused');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'api_key' => 'test-api-key-123456', 'secret_key' => 'test-secret-key-654321', 'base_url' => 'ftp://evil.example', 'mark_shipped' => '1', 'auto_deliver' => '1'], $ADMIN);
t(val("SELECT COALESCE(MAX(setting_value),'') FROM settings WHERE setting_key='steadfast_api_key'") === '', 'non-https API address refused');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'api_key' => 'test-api-key-123456', 'secret_key' => 'test-secret-key-654321', 'base_url' => FAKE . '/api/v1', 'default_note' => 'Call before delivery', 'mark_shipped' => '1', 'auto_deliver' => '1'], $ADMIN);
$page = req('/admin/courier', null, $ADMIN)['body'];
t(str_contains($page, 'Connected (on)') && !str_contains($page, 'test-api-key-123456') && !str_contains($page, 'test-secret-key-654321') && str_contains($page, 'test••••••3456'), 'keys saved, never shown again (masked)');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'api_key' => '', 'secret_key' => '', 'base_url' => FAKE . '/api/v1', 'default_note' => 'Call before delivery', 'mark_shipped' => '1', 'auto_deliver' => '1'], $ADMIN);
t(val("SELECT setting_value FROM settings WHERE setting_key='steadfast_api_key'") === 'test-api-key-123456', 'saving with empty key fields keeps the saved keys');
req('/admin/courier/test', ['_csrf' => tok('/admin/courier', $ADMIN)], $ADMIN);
t(str_contains(flash(req('/admin/courier', null, $ADMIN)['body']), 'Connected to Steadfast. Current balance: ৳1,520.00'), 'test connection shows the Steadfast balance');
$reqs = fake('/_state')['requests'];
$last = end($reqs);
t(($last['headers']['api-key'] ?? '') === 'test-api-key-123456' && ($last['headers']['secret-key'] ?? '') === 'test-secret-key-654321', 'api-key / secret-key headers sent');
t(in_array('Api-Key', $last['names'] ?? [], true) && in_array('Secret-Key', $last['names'] ?? [], true), 'headers named exactly Api-Key / Secret-Key (as in the Steadfast docs)');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'base_url' => 'http://127.0.0.1:8099/api/v1', 'mark_shipped' => '1', 'auto_deliver' => '1', 'default_note' => 'Call before delivery'], $ADMIN);
req('/admin/courier/test', ['_csrf' => tok('/admin/courier', $ADMIN)], $ADMIN);
t(str_contains(flash(req('/admin/courier', null, $ADMIN)['body']), 'Could not reach Steadfast'), 'test connection: unreachable address found by /ping (keys not tried)');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'base_url' => FAKE . '/api/v1', 'mark_shipped' => '1', 'auto_deliver' => '1', 'default_note' => 'Call before delivery'], $ADMIN);
file_get_contents(FAKE . '/_fail?next=429');
req('/admin/courier/test', ['_csrf' => tok('/admin/courier', $ADMIN)], $ADMIN);
t(str_contains(flash(req('/admin/courier', null, $ADMIN)['body']), '60 minutes'), '429 (locked): explains the 60-minute block');
file_get_contents(FAKE . '/_fail?next=403');
req('/admin/courier/test', ['_csrf' => tok('/admin/courier', $ADMIN)], $ADMIN);
t(str_contains(flash(req('/admin/courier', null, $ADMIN)['body']), 'KYC'), '403: inactive account / KYC message');

echo "== Send an order\n";
$oid = placeOrder(2, 1); // watch 4999 + 70
$view = req("/admin/orders/view/$oid", null, $ADMIN)['body'];
t(str_contains($view, 'Send to Steadfast') && str_contains($view, 'value="5069.00"') && str_contains($view, 'House 4, Road 2, Near the mosque, Dhanmondi, Dhaka'), 'order page: send form, COD = total, address with thana + district');
req("/admin/orders/courier/$oid", ['_csrf' => tok("/admin/orders/view/$oid", $ADMIN), 'action' => 'send', 'cod_amount' => '6000', 'courier_note' => 'x'], $ADMIN);
t(val("SELECT courier FROM orders WHERE id=$oid") === null && str_contains(flash(req("/admin/orders/view/$oid", null, $ADMIN)['body']), 'COD amount'), 'COD above the total refused');
req("/admin/orders/courier/$oid", ['_csrf' => tok("/admin/orders/view/$oid", $ADMIN), 'action' => 'send', 'cod_amount' => '-5'], $ADMIN);
t(val("SELECT courier FROM orders WHERE id=$oid") === null, 'negative COD refused');
req("/admin/orders/courier/$oid", ['_csrf' => 'forged', 'action' => 'send', 'cod_amount' => '5069'], $ADMIN);
t(val("SELECT courier FROM orders WHERE id=$oid") === null, 'forged CSRF: nothing sent');
req("/admin/orders/courier/$oid", ['_csrf' => tok("/admin/orders/view/$oid", $ADMIN), 'action' => 'send', 'cod_amount' => '5069.00', 'courier_note' => 'Call before delivery', 'mark_shipped' => '1'], $ADMIN);
$o = row("SELECT * FROM orders WHERE id=$oid");
t($o['courier'] === 'steadfast' && $o['courier_consignment_id'] !== null && $o['courier_status'] === 'in_review' && $o['courier_cod_amount'] === '5069.00', "consignment {$o['courier_consignment_id']} saved (in_review, COD 5069.00)");
t($o['status'] === 'shipped', 'order marked Shipped');
$sent = fake('/_state')['orders'][$o['courier_consignment_id']]['payload'] ?? [];
t(($sent['invoice'] ?? '') === $o['order_number'] && ($sent['recipient_phone'] ?? '') === '01712345678' && ($sent['cod_amount'] ?? null) === 5069 && ($sent['note'] ?? '') === 'Call before delivery' && str_contains($sent['item_description'] ?? '', 'Sample Smart Watch x1'), 'Steadfast got invoice = order number, phone, COD 5069, note, items');
$view = req("/admin/orders/view/$oid", null, $ADMIN)['body'];
t(str_contains($view, 'steadfast.com.bd/t/' . $o['courier_tracking_code']) && str_contains($view, 'Check status now') && str_contains($view, 'Sent to Steadfast: consignment'), 'order page: tracking link, status button, history note');
req("/admin/orders/courier/$oid", ['_csrf' => tok("/admin/orders/view/$oid", $ADMIN), 'action' => 'send', 'cod_amount' => '5069'], $ADMIN);
t(count(fake('/_state')['orders']) === 1 && str_contains(flash(req("/admin/orders/view/$oid", null, $ADMIN)['body']), 'already sent'), 'second send refused (no duplicate parcel)');

echo "== Errors from Steadfast\n";
$oid2 = placeOrder(1, 1);
file_get_contents(FAKE . '/_fail?next=dup');
req("/admin/orders/courier/$oid2", ['_csrf' => tok("/admin/orders/view/$oid2", $ADMIN), 'action' => 'send', 'cod_amount' => '2569'], $ADMIN);
t(val("SELECT courier FROM orders WHERE id=$oid2") === null && str_contains(flash(req("/admin/orders/view/$oid2", null, $ADMIN)['body']), 'invoice has already been taken'), 'validation error shown, order not marked as sent');
file_get_contents(FAKE . '/_fail?next=500');
req("/admin/orders/courier/$oid2", ['_csrf' => tok("/admin/orders/view/$oid2", $ADMIN), 'action' => 'send', 'cod_amount' => '2569'], $ADMIN);
t(val("SELECT courier FROM orders WHERE id=$oid2") === null && str_contains(flash(req("/admin/orders/view/$oid2", null, $ADMIN)['body']), 'unexpected reply'), 'server error shown, can be retried');
t((int) val("SELECT COUNT(*) FROM courier_events WHERE order_id=$oid2 AND source='send'") === 2, 'failed attempts logged');
t(!str_contains((string) val("SELECT GROUP_CONCAT(payload) FROM courier_events"), 'test-secret-key'), 'keys never stored in the courier log');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'api_key' => 'wrong-key-000000', 'secret_key' => '', 'base_url' => FAKE . '/api/v1', 'mark_shipped' => '1', 'auto_deliver' => '1'], $ADMIN);
req("/admin/orders/courier/$oid2", ['_csrf' => tok("/admin/orders/view/$oid2", $ADMIN), 'action' => 'send', 'cod_amount' => '2569'], $ADMIN);
t(str_contains(flash(req("/admin/orders/view/$oid2", null, $ADMIN)['body']), 'refused the API key'), 'wrong key: clear message');
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'enabled' => '1', 'api_key' => 'test-api-key-123456', 'secret_key' => '', 'base_url' => FAKE . '/api/v1', 'mark_shipped' => '', 'auto_deliver' => '1'], $ADMIN);
req("/admin/orders/courier/$oid2", ['_csrf' => tok("/admin/orders/view/$oid2", $ADMIN), 'action' => 'send', 'cod_amount' => '0'], $ADMIN);
t(val("SELECT courier_cod_amount FROM orders WHERE id=$oid2") === '0.00' && val("SELECT status FROM orders WHERE id=$oid2") === 'pending', 'COD 0 allowed; "mark shipped" unticked keeps the status');

echo "== Status updates\n";
$cid = (string) val("SELECT courier_consignment_id FROM orders WHERE id=$oid");
file_get_contents(FAKE . "/_set?cid=$cid&status=hold");
req("/admin/orders/courier/$oid", ['_csrf' => tok("/admin/orders/view/$oid", $ADMIN), 'action' => 'refresh'], $ADMIN);
t(val("SELECT courier_status FROM orders WHERE id=$oid") === 'hold' && str_contains(flash(req("/admin/orders/view/$oid", null, $ADMIN)['body']), 'On hold'), '"Check status now" updates the status (On hold)');
file_get_contents(FAKE . "/_set?cid=$cid&status=delivered");
req("/admin/orders/courier/$oid", ['_csrf' => tok("/admin/orders/view/$oid", $ADMIN), 'action' => 'refresh'], $ADMIN);
t(row("SELECT status, payment_status FROM orders WHERE id=$oid") === ['status' => 'delivered', 'payment_status' => 'paid'], 'Steadfast "delivered" -> order Delivered + Paid automatically');
$list = req('/admin/orders', null, $ADMIN)['body'];
t(str_contains($list, 'data-bulk-button') && str_contains($list, 'title="Steadfast consignment ' . $cid . '"'), 'order list: bulk button and courier status');

echo "== Webhook\n";
$hook = static fn (array $body, ?string $tok) => req('/webhooks/steadfast', json_encode($body), __DIR__ . '/ct_hook.txt', array_filter(['Content-Type: application/json', $tok !== null ? "Authorization: Bearer $tok" : null]));
$cid2 = (string) val("SELECT courier_consignment_id FROM orders WHERE id=$oid2");
$num2 = (string) val("SELECT order_number FROM orders WHERE id=$oid2");
$r = $hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $cid2, 'invoice' => $num2, 'status' => 'delivered', 'cod_amount' => 0], null);
t($r['code'] === 401 && val("SELECT courier_status FROM orders WHERE id=$oid2") === 'in_review', 'no token -> 401, nothing changed');
t($hook(['consignment_id' => (int) $cid2, 'status' => 'delivered'], 'wrong-token')['code'] === 401, 'wrong token -> 401');
$r = $hook(['notification_type' => 'delivery_status', 'consignment_id' => 99999999, 'invoice' => 'NOPE', 'status' => 'delivered'], $token);
t($r['code'] === 404, 'unknown consignment -> 404');
t($hook([], $token)['code'] === 400, 'empty body -> 400');
$r = $hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $cid2, 'invoice' => $num2, 'status' => 'Cancelled', 'cod_amount' => 0, 'delivery_charge' => 70, 'tracking_message' => 'Customer refused', 'updated_at' => date('Y-m-d H:i:s')], $token);
t($r['code'] === 200 && json_decode($r['body'], true)['status'] === 'success' && val("SELECT courier_status FROM orders WHERE id=$oid2") === 'cancelled', 'valid webhook -> 200, status cancelled');
t(val("SELECT status FROM orders WHERE id=$oid2") === 'pending' && str_contains(req("/admin/orders/view/$oid2", null, $ADMIN)['body'], 'reports the parcel as cancelled'), 'courier cancel only warns; the admin decides (stock not touched)');
t(str_contains((string) val("SELECT message FROM courier_events WHERE order_id=$oid2 AND source='webhook' ORDER BY id DESC LIMIT 1"), 'Customer refused'), 'tracking message logged');
$r = $hook(['notification_type' => 'tracking_update', 'invoice' => $num2, 'tracking_message' => 'Parcel at Dhaka hub'], $token);
t($r['code'] === 200 && val("SELECT courier_status FROM orders WHERE id=$oid2") === 'cancelled', 'tracking update without status: logged, status unchanged');
$r = $hook(['notification_type' => 'payment', 'payment_id' => 77, 'amount' => 5000], $token);
t($r['code'] === 200, 'payout notice (no parcel) accepted with 200');
$r = $hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $cid2, 'status' => 'exceptional'], $token);
t($r['code'] === 200 && str_contains(req("/admin/orders/view/$oid2", null, $ADMIN)['body'], 'Exceptional (lost / damaged)'), 'exceptional status shown');
t(str_contains(req('/robots.txt')['body'], 'Disallow: /webhooks/'), 'robots.txt blocks /webhooks/');
t(req('/webhooks/steadfast')['code'] === 405, 'GET /webhooks/steadfast not allowed');

echo "== Customer record (fraud check)\n";
$fo = placeOrder(1, 1);
$v = req("/admin/orders/view/$fo", null, $ADMIN)['body'];
t(str_contains($v, 'id="customer-record"') && str_contains($v, 'Check customer record'), 'order page: "Check customer record" button');
req("/admin/orders/courier/$fo", ['_csrf' => tok("/admin/orders/view/$fo", $ADMIN), 'action' => 'fraud'], $ADMIN);
$v = req("/admin/orders/view/$fo", null, $ADMIN)['body'];
t(str_contains(flash($v), '8 of 10 delivered (80%), 2 returned') && str_contains($v, 'data-fraud-level>Good customer<') && str_contains($v, '80% delivered'), 'flat reply: 8 of 10 delivered, 80% -> Good customer');
$reqs = fake('/_state')['requests']; $last = end($reqs);
t(($last['path'] ?? '') === '/api/v1/fraud_check/score/01712345678' && ($last['headers']['api-key'] ?? '') === 'test-api-key-123456', 'asks /fraud_check/score/{phone} with the keys');
$fo2 = placeOrder(1, 1, '01811111111');
req("/admin/orders/courier/$fo2", ['_csrf' => tok("/admin/orders/view/$fo2", $ADMIN), 'action' => 'fraud'], $ADMIN);
$v = req("/admin/orders/view/$fo2", null, $ADMIN)['body'];
t(str_contains($v, 'data-fraud-level>High risk<') && str_contains(flash($v), '1 of 5 delivered (20%), 4 returned, 1 fraud report'), 'nested reply with a report: 1 of 5 delivered, 1 report -> High risk');
t(str_contains($v, 'Refused twice') && str_contains($v, 'Details from Steadfast'), 'details from Steadfast shown (escaped)');
$fo3 = placeOrder(1, 1, '01999999999');
req("/admin/orders/courier/$fo3", ['_csrf' => tok("/admin/orders/view/$fo3", $ADMIN), 'action' => 'fraud'], $ADMIN);
t(str_contains(req("/admin/orders/view/$fo3", null, $ADMIN)['body'], 'data-fraud-level>New customer<'), 'no earlier parcels -> New customer');
$fo4 = placeOrder(1, 1, '01700000000');
req("/admin/orders/courier/$fo4", ['_csrf' => tok("/admin/orders/view/$fo4", $ADMIN), 'action' => 'fraud'], $ADMIN);
$v = req("/admin/orders/view/$fo4", null, $ADMIN)['body'];
t(str_contains($v, 'data-fraud-level>See details<') && str_contains($v, 'Looks fine'), 'unknown reply shape: raw details shown, nothing lost');
t(val("SELECT status FROM orders WHERE id=$fo") === 'pending' && val("SELECT courier FROM orders WHERE id=$fo") === null, 'checking does not send or change the order');
req("/admin/orders/courier/$fo", ['_csrf' => 'forged', 'action' => 'fraud'], $ADMIN);
t((int) val("SELECT COUNT(*) FROM courier_events WHERE order_id=$fo AND status='fraud_check'") === 1, 'forged CSRF: no check made');

echo "== Bulk send\n";
$a = placeOrder(1, 1); $b2 = placeOrder(3, 1);
$pdo->exec("UPDATE orders SET status='cancelled' WHERE id=$b2");
$c3 = placeOrder(4, 1);
req('/admin/orders/courier-bulk', ['_csrf' => tok('/admin/orders', $ADMIN), 'ids' => [(string) $a, (string) $b2, (string) $c3, (string) $oid], 'return' => '/admin/orders'], $ADMIN);
$f = flash(req('/admin/orders', null, $ADMIN)['body']);
t(val("SELECT courier FROM orders WHERE id=$a") === 'steadfast' && val("SELECT courier FROM orders WHERE id=$c3") === 'steadfast' && val("SELECT courier FROM orders WHERE id=$b2") === null, 'bulk: open orders sent, cancelled one skipped');
t(str_contains($f, 'Sent 2 orders') && str_contains($f, 'The order is cancelled') && str_contains($f, 'The order is delivered'), 'bulk: summary with reasons (cancelled, delivered)');
req('/admin/orders/courier-bulk', ['_csrf' => tok('/admin/orders', $ADMIN), 'ids' => array_map('strval', range(1, 25))], $ADMIN);
t(str_contains(flash(req('/admin/orders', null, $ADMIN)['body']), 'up to 20'), 'bulk limited to 20 orders');

echo "== Switching off\n";
req('/admin/courier', ['_csrf' => tok('/admin/courier', $ADMIN), 'clear_keys' => '1', 'base_url' => FAKE . '/api/v1'], $ADMIN);
$x = placeOrder(1, 1);
t(!str_contains(req("/admin/orders/view/$x", null, $ADMIN)['body'], 'Send to Steadfast</span>') && !str_contains(req('/admin/orders', null, $ADMIN)['body'], 'data-bulk-button'), 'keys removed: send buttons hidden');
t(val("SELECT setting_value FROM settings WHERE setting_key='steadfast_api_key'") === '', 'keys removed from the database');

echo "\nTOTAL: $pass passed, $fail failed\n";
@unlink($ADMIN); @unlink($SHOP); @unlink(__DIR__ . '/ct_hook.txt');
