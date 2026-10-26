<?php
/**
 * Phase 6 - shopping cart test suite (HTTP, fresh session per scenario).
 * Needs the dev server on :8080 and a freshly seeded aalto_mart_dev.
 */
declare(strict_types=1);

const B = 'http://localhost:8080';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$jar = __DIR__ . '/cartjar.txt';

function t(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
function fresh(): void { global $jar; @unlink($jar); }
function req(string $path, ?array $post = null): array {
    global $jar;
    $ch = curl_init(B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'CartTest']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post)); }
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return ['code' => $info['http_code'], 'body' => substr($raw, $info['header_size']), 'location' => $info['redirect_url']];
}
function rawPost(string $path, string $body): array {
    global $jar;
    $ch = curl_init(B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'CartTest', CURLOPT_POSTFIELDS => $body]);
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return ['code' => $info['http_code'], 'location' => $info['redirect_url']];
}
function token(): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', req('/shop')['body'], $m); return $m[1]; }
function id(PDO $pdo, string $sku): int { return (int) $pdo->query("SELECT id FROM products WHERE sku=" . $pdo->quote($sku))->fetchColumn(); }
function add(int|string $id, int|string $qty = 1, array $extra = []): array { return req('/cart/add', ['_csrf' => token(), 'product_id' => $id, 'quantity' => $qty] + $extra); }
function upd(int $id, array $fields): array { return req('/cart/update', ['_csrf' => token(), 'product_id' => $id] + $fields); }

/** Parse the cart page into a small structure. */
function cart(): array {
    $html = req('/cart')['body'];
    $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8" ?>' . $html); libxml_clear_errors();
    $x = new DOMXPath($d);
    $lines = [];
    foreach ($x->query('//div[contains(@class,"cart-line") and @role="listitem"]') as $l) {
        $name = trim($x->query('.//a[contains(@class,"cart-line__name")]', $l)->item(0)->textContent);
        $lines[$name] = [
            'qty'   => (int) $x->query('.//input[@name="quantity"]', $l)->item(0)->getAttribute('value'),
            'max'   => (int) $x->query('.//input[@name="quantity"]', $l)->item(0)->getAttribute('max'),
            'unit'  => trim(preg_replace('/\s+/u', ' ', $x->query('.//div[contains(@class,"cart-line__price")]/span[last()]', $l)->item(0)->firstChild->textContent)),
            'total' => trim($x->query('.//div[contains(@class,"cart-line__total")]/strong', $l)->item(0)->textContent),
            'minus_disabled' => $x->query('.//button[@name="step" and @value="-1" and @disabled]', $l)->length === 1,
            'plus_disabled'  => $x->query('.//button[@name="step" and @value="1" and @disabled]', $l)->length === 1,
        ];
    }
    $dd = fn (string $dt) => trim(($x->query("//dl[contains(@class,'cart-summary__rows')]//dt[starts-with(normalize-space(),'$dt')]/following-sibling::dd")->item(0))?->textContent ?? '');
    $badge = $x->query('//span[contains(@class,"cart-badge")]')->item(0);
    $flash = $x->query('//main//div[contains(@class,"alert")]');
    return [
        'lines' => $lines, 'subtotal' => $dd('Subtotal'), 'delivery' => $dd('Delivery'), 'total' => $dd('Grand total') ?: $dd('Total before delivery'),
        'hint' => trim(preg_replace('/\s+/u', ' ', ($x->query('//aside[contains(@class,"cart-summary")]//p[contains(.,"Choose your district")]')->item(0))?->textContent ?? '')),
        'badge' => $badge ? trim($badge->textContent) : null,
        'cartLabel' => $x->query('//header//a[contains(@class,"cart-link")]')->item(0)->getAttribute('aria-label'),
        'alerts' => array_map(fn ($n) => trim(preg_replace('/\s+/u', ' ', $n->textContent)), iterator_to_array($flash)),
        'empty' => str_contains($html, 'Your cart is empty'),
        'nudge' => trim(preg_replace('/\s+/u', ' ', ($x->query('//div[contains(@class,"delivery-nudge")]/p')->item(0))?->textContent ?? '')),
        'robots' => $x->query('//meta[@name="robots"]')->item(0)->getAttribute('content'),
        'html' => $html,
    ];
}
function alertHas(array $c, string $s): bool { foreach ($c['alerts'] as $a) { if (str_contains($a, $s)) return true; } return false; }

$EAR = id($pdo, 'SMP-ELE-001');   // 2999 regular / 2499 sale, stock 40
$WATCH = id($pdo, 'SMP-ELE-002'); // 4999, stock 25
$CHOC = id($pdo, 'SMP-GRO-005');  // 250, stock 120
$SPEAKER = id($pdo, 'SMP-ELE-005'); // out of stock
$MUG = id($pdo, 'SMP-HOM-002');   // 799, stock 45

echo "== Empty cart\n";
fresh();
$c = cart();
t($c['empty'] && !$c['lines'], 'empty cart message');
t($c['badge'] === null && str_contains($c['cartLabel'], 'empty'), 'header: no badge, label says empty');
t($c['robots'] === 'noindex, nofollow', 'cart page is noindex');
t(req('/cart')['code'] === 200, 'GET /cart 200');

echo "== Add\n";
fresh();
$r = add($EAR, 2, ['return' => '/product/sample-wireless-earbuds']);
t($r['code'] === 302 && $r['location'] === B . '/product/sample-wireless-earbuds', 'add redirects back to the page it came from');
$page = req('/product/sample-wireless-earbuds')['body'];
t(str_contains($page, 'Added 2 × &quot;Sample Wireless Earbuds&quot; to your cart.'), 'success message');
t(str_contains($page, 'class="cart-badge" aria-hidden="true">2<'), 'header badge shows 2');
t(str_contains($page, 'You have <strong>2</strong> in your cart.') && str_contains($page, '<strong data-line-total>৳4,998.00</strong>'), 'product page: "You have 2 in your cart", total ৳4,998.00');
t(!str_contains($page, 'data-buy-button') && !str_contains($page, 'data-cart-stepper') && str_contains($page, 'name="buy_now" value="1"'), 'in cart: product page still shows only Order Now');
t(str_contains($page, 'name="line" value="' . $EAR . '"') && str_contains($page, 'cart-stepper--sm'), 'the side cart has the - N + stepper for this line');
$r = req('/cart/update', ['_csrf' => token(), 'line' => (string) $EAR, 'step' => '1', 'return' => '/product/sample-wireless-earbuds']);
t($r['location'] === B . '/product/sample-wireless-earbuds' && cart()['lines']['Sample Wireless Earbuds']['qty'] === 3, 'stepper +: back on the product page, 3 in the cart');
$r = req('/cart/update', ['_csrf' => token(), 'line' => (string) $EAR, 'step' => '-1', 'return' => '//evil.example']);
t($r['location'] === B . '/cart' && cart()['lines']['Sample Wireless Earbuds']['qty'] === 2, 'stepper -: 2 left; unsafe return path ignored');
$r = req('/cart/add', ['_csrf' => token(), 'product_id' => $EAR, 'quantity' => 1, 'buy_now' => '1', 'return' => '/product/sample-wireless-earbuds']);
t($r['location'] === B . '/checkout' && cart()['lines']['Sample Wireless Earbuds']['qty'] === 2, 'Order Now when already in the cart: straight to checkout, nothing added');
$r = req('/cart/add', ['_csrf' => token(), 'product_id' => $MUG, 'quantity' => 1, 'buy_now' => '1', 'return' => '/product/sample-ceramic-mug-set']);
t($r['location'] === B . '/checkout' && (cart()['lines']['Sample Ceramic Mug Set']['qty'] ?? 0) === 1, 'Order Now when not in the cart: adds 1, then checkout');
req('/cart/update', ['_csrf' => token(), 'line' => (string) $MUG, 'step' => '-1', 'return' => '/product/sample-ceramic-mug-set']);
t(!isset(cart()['lines']['Sample Ceramic Mug Set']) && !preg_match('#data-buy-button hidden>#', req('/product/sample-ceramic-mug-set')['body']), 'stepper - to 0 removes it; "Add to Cart" is back');
$c = cart();
t(($c['lines']['Sample Wireless Earbuds']['qty'] ?? 0) === 2, 'cart line qty 2');
t($c['lines']['Sample Wireless Earbuds']['unit'] === '৳2,499.00', 'unit price = current sale price ৳2,499.00');
t($c['lines']['Sample Wireless Earbuds']['total'] === '৳4,998.00', 'line subtotal ৳4,998.00');
t($c['subtotal'] === '৳4,998.00' && $c['delivery'] === 'At checkout' && $c['total'] === '৳4,998.00', 'subtotal ৳4,998.00, delivery at checkout, total before delivery ৳4,998.00');
add($EAR, 1);
t(cart()['lines']['Sample Wireless Earbuds']['qty'] === 3, 'adding the same product again merges (qty 3)');
add($CHOC, 1);
$c = cart();
t(count($c['lines']) === 2 && $c['badge'] === '4', 'second product added; badge 4');
t($c['subtotal'] === '৳7,747.00' && $c['total'] === '৳7,747.00', 'subtotal 3×2,499 + 250 = ৳7,747.00');
t(add($EAR, 1, ['return' => '/'])['location'] === B . '/', 'return "/" goes home');

echo "== Delivery fee & totals\n";
// Delivery depends on the district (checkout); the cart shows the zone charges
fresh(); add($CHOC, 1);
$c = cart();
t($c['subtotal'] === '৳250.00' && $c['delivery'] === 'At checkout' && $c['total'] === '৳250.00', 'no free delivery set: delivery "At checkout", total before delivery ৳250.00');
t(str_contains($c['hint'], 'Inside Dhaka ৳70.00') && str_contains($c['hint'], 'Outside Dhaka ৳130.00'), 'zone charges shown (Inside Dhaka ৳70 / Outside Dhaka ৳130)', $c['hint']);
t($c['nudge'] === '', 'no free-delivery nudge when free delivery is off');
// Admin sets free delivery from 2,000
$pdo->exec("UPDATE settings SET setting_value = '2000.00' WHERE setting_key = 'free_delivery_from'");
$c = cart();
t(str_contains($c['nudge'], 'Add ৳1,750.00 more for free delivery'), 'free delivery from ৳2,000: nudge "add ৳1,750.00 more"', $c['nudge']);
upd($CHOC, ['quantity' => 8]);
$c = cart();
t($c['subtotal'] === '৳2,000.00' && $c['delivery'] === 'Free' && $c['total'] === '৳2,000.00', 'exactly ৳2,000.00 -> free delivery, grand total ৳2,000.00');
$pdo->exec("UPDATE settings SET setting_value = '0.00' WHERE setting_key = 'free_delivery_from'");
$pdo->exec("UPDATE products SET sale_price = 333.33 WHERE id = $MUG");
fresh(); add($MUG, 3);
$c = cart();
t($c['lines']['Sample Ceramic Mug Set']['total'] === '৳999.99' && $c['total'] === '৳999.99', '3 × ৳333.33 = ৳999.99 exactly (integer cents)');
$pdo->exec("UPDATE products SET sale_price = NULL WHERE id = $MUG");

echo "== Increase / decrease quantity\n";
fresh(); add($WATCH, 2);
upd($WATCH, ['step' => '1']);
t(cart()['lines']['Sample Smart Watch']['qty'] === 3, '+ button: 2 -> 3');
upd($WATCH, ['step' => '-1']); upd($WATCH, ['step' => '-1']);
$c = cart();
t($c['lines']['Sample Smart Watch']['qty'] === 1 && $c['lines']['Sample Smart Watch']['minus_disabled'], '- button: 3 -> 1, and "-" is disabled at 1');
upd($WATCH, ['quantity' => '7']);
t(cart()['lines']['Sample Smart Watch']['qty'] === 7, 'typed quantity 7');
upd($WATCH, ['quantity' => '4', 'step' => '1']);
t(cart()['lines']['Sample Smart Watch']['qty'] === 8, 'step uses the stored qty (7+1), not the typed/page value');
upd($WATCH, ['step' => '5']);
t(cart()['lines']['Sample Smart Watch']['qty'] === 8, 'step other than ±1 is ignored');
foreach (['-1' => 'negative', '2.5' => 'decimal', 'abc' => 'text', '' => 'empty', ' ' => 'blank', '1e2' => 'exponent', '0x10' => 'hex'] as $bad => $label) {
    upd($WATCH, ['quantity' => $bad]);
    $c = cart();
    t($c['lines']['Sample Smart Watch']['qty'] === 8 && alertHas($c, 'whole number of 0 or more'), "quantity \"$bad\" ($label) rejected, qty unchanged");
}
rawPost('/cart/update', '_csrf=' . token() . "&product_id=$WATCH&quantity[]=3");
t(cart()['lines']['Sample Smart Watch']['qty'] === 8, 'array quantity rejected');
upd($WATCH, ['quantity' => '0']);
$c = cart();
t(!isset($c['lines']['Sample Smart Watch']) && alertHas($c, 'Removed "Sample Smart Watch"'), 'quantity 0 removes the line');
add($WATCH, 1); upd($WATCH, ['step' => '-1']);
t(!isset(cart()['lines']['Sample Smart Watch']), '"-" at quantity 1 (forced via POST) removes, never goes negative');

echo "== Remove & clear\n";
fresh(); add($EAR, 1); add($CHOC, 2);
req('/cart/remove', ['_csrf' => token(), 'product_id' => $CHOC]);
$c = cart();
t(count($c['lines']) === 1 && !isset($c['lines']['Sample Dark Chocolate Bar']) && alertHas($c, 'Removed "Sample Dark Chocolate Bar"'), 'remove one line');
req('/cart/remove', ['_csrf' => token(), 'product_id' => $CHOC]);
t(alertHas(cart(), 'was not in your cart'), 'removing something not in the cart is harmless');
add($CHOC, 2);
$r = req('/cart/clear', ['_csrf' => token()]);
$c = cart();
t($r['location'] === B . '/cart' && $c['empty'] && $c['badge'] === null && alertHas($c, 'Your cart is now empty'), 'clear cart');

echo "== Stock limits\n";
fresh();
add($EAR, 45);
$c = cart();
t($c['lines']['Sample Wireless Earbuds']['qty'] === 40 && alertHas($c, 'Only 40 of "Sample Wireless Earbuds" available, so we added 40'), 'add 45 with stock 40 -> 40 + warning');
t($c['lines']['Sample Wireless Earbuds']['plus_disabled'] && $c['lines']['Sample Wireless Earbuds']['max'] === 40, '"+" disabled and input max=40 at the limit');
add($EAR, 1);
t(alertHas(cart(), 'already have the maximum available (40)'), 'adding more at the limit -> warning, still 40');
upd($EAR, ['quantity' => '500']);
$c = cart();
t($c['lines']['Sample Wireless Earbuds']['qty'] === 40 && alertHas($c, 'Only 40 of "Sample Wireless Earbuds" available. Quantity set to 40'), 'update to 500 -> capped at 40');
upd($EAR, ['step' => '1']);
t(cart()['lines']['Sample Wireless Earbuds']['qty'] === 40, '"+" at the limit (forced) stays 40');
$page = req('/product/sample-wireless-earbuds')['body'];
t(str_contains($page, 'Order Now') && !str_contains($page, 'id="quantity"') && !str_contains($page, 'Change quantity'), 'product page: all stock in cart -> Order Now, no add form');
upd($EAR, ['quantity' => '999999999999']);
t(cart()['lines']['Sample Wireless Earbuds']['qty'] === 40, 'absurd quantity rejected/capped safely');
fresh(); add($CHOC, 150);
t(cart()['lines']['Sample Dark Chocolate Bar']['qty'] === 99, 'per-line maximum 99 even when stock is 120');
t(str_contains(add($SPEAKER, 1)['location'], '/cart') && alertHas(cart(), 'is out of stock'), 'out-of-stock product cannot be added');
t(!isset(cart()['lines']['Sample Bluetooth Speaker']), '...and is not in the cart');

echo "== Stock or status changes after adding\n";
fresh(); add($MUG, 10);
$pdo->exec("UPDATE products SET stock_quantity = 4 WHERE id = $MUG");
$home = req('/')['body'];
t(str_contains($home, 'class="cart-badge" aria-hidden="true">4<'), 'header count follows reduced stock (4) on any page');
$c = cart();
t($c['lines']['Sample Ceramic Mug Set']['qty'] === 4 && alertHas($c, 'Only 4 of "Sample Ceramic Mug Set" available'), 'cart page shows the adjustment notice (even though the header fixed it first)');
t(!alertHas(cart(), 'Only 4 of'), 'notice shown once');
$pdo->exec("UPDATE products SET stock_quantity = 0 WHERE id = $MUG");
$c = cart();
t(!isset($c['lines']['Sample Ceramic Mug Set']) && alertHas($c, 'is now out of stock and was removed'), 'sold out after adding -> removed with notice');
$pdo->exec("UPDATE products SET stock_quantity = 45 WHERE id = $MUG");
fresh(); add($WATCH, 1);
$pdo->exec("UPDATE products SET regular_price = 5555.55 WHERE id = $WATCH");
t(cart()['lines']['Sample Smart Watch']['unit'] === '৳5,555.55', 'price change in DB shows immediately (price is never stored in the session)');
$pdo->exec("UPDATE products SET regular_price = 4999.00 WHERE id = $WATCH");

echo "== Inactive products\n";
fresh();
$pdo->exec("UPDATE products SET is_active = 0 WHERE id = $WATCH");
add($WATCH, 1);
$c = cart();
t($c['empty'] && alertHas($c, 'that product is not available'), 'inactive product cannot be added');
$pdo->exec("UPDATE products SET is_active = 1 WHERE id = $WATCH");
add($WATCH, 2); add($EAR, 1);
$pdo->exec("UPDATE products SET is_active = 0 WHERE id = $WATCH");
$c = cart();
t(!isset($c['lines']['Sample Smart Watch']) && isset($c['lines']['Sample Wireless Earbuds']) && alertHas($c, 'no longer available and was removed'), 'deactivated after adding -> removed with notice, others kept');
$pdo->exec("UPDATE products SET is_active = 1 WHERE id = $WATCH");
upd($WATCH, ['quantity' => '3']);
t(alertHas(cart(), 'not in your cart'), 'updating a removed line is refused');
$pdo->exec("UPDATE categories SET is_active = 0 WHERE slug = 'groceries'");
fresh(); add($CHOC, 1);
t(cart()['empty'], 'product in an inactive category cannot be added');
$pdo->exec("UPDATE categories SET is_active = 1 WHERE slug = 'groceries'");

echo "== Invalid product IDs\n";
fresh(); add($EAR, 1);
foreach (['0', '-1', 'abc', '99999', '1 OR 1=1', "1'--", '1e3', '99999999999', '', ' 1', '0x1', '1.0'] as $bad) {
    add($bad, 1);
    $c = cart();
    t(count($c['lines']) === 1 && $c['badge'] === '1', "product_id \"$bad\" rejected, cart unchanged");
}
rawPost('/cart/add', '_csrf=' . token() . '&product_id[]=' . $WATCH . '&quantity=1');
t(count(cart()['lines']) === 1, 'array product_id rejected');
rawPost('/cart/add', '_csrf=' . token() . '&quantity=1');
t(count(cart()['lines']) === 1, 'missing product_id rejected');
foreach (['0', '-5', 'x', '1.5'] as $bad) {
    add($WATCH, $bad);
    t(!isset(cart()['lines']['Sample Smart Watch']), "add with quantity \"$bad\" rejected");
}
upd(99999, ['quantity' => '1']);
t(alertHas(cart(), 'not in your cart'), 'update with unknown id refused');

echo "== Tampering: prices & totals from the browser are ignored\n";
fresh();
add($WATCH, 1, ['price' => '1.00', 'unit_price' => '0.01', 'total' => '0', 'sale_price' => '1', 'regular_price' => '1']);
$c = cart();
t($c['lines']['Sample Smart Watch']['unit'] === '৳4,999.00' && $c['total'] === '৳4,999.00', 'posted price/total fields ignored (still ৳4,999.00)');
upd($WATCH, ['quantity' => '2', 'price' => '1']);
t(cart()['total'] === '৳9,998.00', 'update ignores posted price');

echo "== Side cart (drawer)\n";
fresh();
$shop = req('/shop')['body'];
t(str_contains($shop, 'class="cart-tab"') && str_contains($shop, '0 Items') && str_contains($shop, 'id="cartDrawer"') && str_contains($shop, 'Your cart is empty.'), 'floating "0 Items" tab and empty side cart on store pages');
t(str_contains($shop, 'data-bs-target="#cartDrawer"') && !preg_match('/data-cart-drawer\s+data-open="1"/', $shop), 'header cart icon opens it; closed by default');
add($EAR, 2, ['return' => '/shop']);
$shop = req('/shop')['body'];
t((bool) preg_match('/data-cart-drawer\s+data-open="1"/', $shop) && str_contains($shop, '2 Items') && str_contains($shop, 'class="cart-drawer__name">Sample Wireless Earbuds</a>'), 'after Add to Cart: side cart opens with the item, tab shows "2 Items"');
t(!preg_match('/data-cart-drawer\s+data-open="1"/', req('/shop')['body']), 'opens once (not again on the next page)');
t(str_contains($shop, '<strong class="fs-5">৳4,998.00</strong>') && str_contains($shop, 'href="' . B . '/checkout" class="btn btn-primary btn-lg"'), 'subtotal ৳4,998.00 and a Checkout button');
$r = req('/cart/update', ['_csrf' => token(), 'line' => (string) $EAR, 'step' => '1', 'return' => '/shop', 'drawer' => '1']);
$shop = req('/shop')['body'];
t($r['location'] === B . '/shop' && (bool) preg_match('/data-cart-drawer\s+data-open="1"/', $shop) && str_contains($shop, '3 Items'), 'side cart +: back on the same page, side cart open again, 3 items');
$r = req('/cart/remove', ['_csrf' => token(), 'line' => (string) $EAR, 'return' => '/shop', 'drawer' => '1']);
t($r['location'] === B . '/shop' && str_contains(req('/shop')['body'], 'Your cart is empty.'), 'side cart remove: back on the page, cart empty');
$cartPage = req('/cart')['body'];
t(!str_contains($cartPage, 'id="cartDrawer"') && !str_contains($cartPage, 'class="cart-tab"') && !str_contains($cartPage, 'data-bs-target="#cartDrawer"'), 'no side cart on the cart page (header icon goes to /cart)');
add($EAR, 1, ['return' => '/cart']);
t(!str_contains(req('/checkout')['body'], 'id="cartDrawer"'), 'no side cart on checkout');
t(!preg_match('/data-cart-drawer\s+data-open="1"/', req('/shop')['body']), 'adding from the cart page does not leave the side cart to pop up later');

echo "== CSRF\n";
fresh(); add($EAR, 1);
foreach ([['/cart/add', ['product_id' => $WATCH, 'quantity' => 1]], ['/cart/update', ['product_id' => $EAR, 'quantity' => 5]],
          ['/cart/remove', ['product_id' => $EAR]], ['/cart/clear', []]] as [$path, $data]) {
    req($path, $data + ['_csrf' => 'forged']);
    $c = cart();
    t(count($c['lines']) === 1 && $c['lines']['Sample Wireless Earbuds']['qty'] === 1 && alertHas($c, 'session expired'), "$path with forged token refused");
}
foreach (['/cart/add', '/cart/update', '/cart/remove', '/cart/clear'] as $p) {
    t(req($p)['code'] === 405, "GET $p -> 405");
}

echo "== Redirect safety\n";
fresh();
foreach (['https://evil.example/x', '//evil.example', '/\\evil.example', 'javascript:alert(1)', '/../admin', "/shop\r\nSet-Cookie: x=1"] as $bad) {
    $r = add($EAR, 1, ['return' => $bad]);
    t($r['location'] === B . '/cart', 'return "' . addcslashes($bad, "\r\n") . '" -> stays on /cart');
}
t(add($EAR, 1, ['return' => '/shop?q=watch&sort=name'])['location'] === B . '/shop?q=watch&sort=name', 'valid same-site return with query kept');

echo "== Escaping\n";
$pdo->exec("UPDATE products SET name = 'Sample <b>Bold</b> Watch' WHERE id = $WATCH");
fresh(); add($WATCH, 1);
$html = cart()['html'];
t(!str_contains($html, '<b>Bold</b>') && str_contains($html, 'Sample &lt;b&gt;Bold&lt;/b&gt; Watch'), 'product name escaped in cart and messages');
$pdo->exec("UPDATE products SET name = 'Sample Smart Watch' WHERE id = $WATCH");

echo "\nTOTAL: $pass passed, $fail failed\n";
@unlink($jar);
