<?php
/**
 * Size/colour options, per-option stock, delivery zones, districts/thanas
 * and Admin -> Delivery. Needs the dev server on :8080 and a freshly seeded
 * aalto_mart_dev (run reseed.php first).
 */
declare(strict_types=1);

const B = 'http://localhost:8080';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$ADMIN = __DIR__ . '/v_admin.txt'; $SHOP = __DIR__ . '/v_shop.txt';
@unlink($ADMIN); @unlink($SHOP);

function t(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
function req(string $path, array|string|null $post = null, ?string $jar = null): array {
    global $SHOP; $jar ??= $SHOP;
    $ch = curl_init(B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'VariantsTest']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post)); }
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return ['code' => $info['http_code'], 'body' => substr($raw, $info['header_size']), 'location' => $info['redirect_url']];
}
function tok(string $path, ?string $jar = null): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', req($path, null, $jar)['body'], $m); return $m[1] ?? ''; }
function val(string $sql): mixed { global $pdo; return $pdo->query($sql)->fetchColumn(); }
function flash(string $html): string { preg_match_all('/<div class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m); return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode(' ', $m[1] ?? []))))); }
function k(string $v): string { return $v === '' ? '_' : bin2hex($v); }
function variants(int $pid): array { global $pdo; return $pdo->query("SELECT size, colour, colour_hex, stock_quantity FROM product_variants WHERE product_id = $pid ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC); }
function stockOf(int $pid, string $size, string $colour): int { global $pdo; $s = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE product_id = ? AND size = ? AND colour = ?'); $s->execute([$pid, $size, $colour]); return (int) $s->fetchColumn(); }
function vid(int $pid, string $size, string $colour): int { global $pdo; $s = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? AND size = ? AND colour = ?'); $s->execute([$pid, $size, $colour]); return (int) $s->fetchColumn(); }

/** Admin product form fields for $pid (current values), plus option overrides. */
function productPost(int $pid, array $extra): array {
    global $pdo, $ADMIN;
    $p = $pdo->query("SELECT * FROM products WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
    return $extra + ['_csrf' => tok("/admin/products/edit/$pid", $ADMIN), 'name' => $p['name'], 'slug' => $p['slug'], 'sku' => $p['sku'],
        'category_id' => $p['category_id'], 'short_description' => (string) $p['short_description'], 'description' => (string) $p['description'],
        'regular_price' => $p['regular_price'], 'sale_price' => (string) $p['sale_price'], 'stock_quantity' => $p['stock_quantity'], 'is_active' => '1'];
}
function saveProduct(int $pid, array $extra): array { global $ADMIN; $r = req("/admin/products/edit/$pid", productPost($pid, $extra), $ADMIN); $r['page'] = req("/admin/products/edit/$pid", null, $ADMIN)['body']; return $r; }
function add(int $pid, array $opts, int $qty = 1, ?string $jar = null): string {
    $r = req('/cart/add', ['_csrf' => tok('/shop', $jar), 'product_id' => $pid, 'quantity' => $qty, 'return' => '/cart'] + $opts, $jar);
    return flash(req('/cart', null, $jar)['body']);
}

// Sign in (seed test admin, local dev server only)
$lt = tok('/admin/login', $ADMIN);
req('/admin/login', ['_csrf' => $lt, 'email' => 'admin@example.com', 'password' => 'Admin@12345'], $ADMIN);
t(req('/admin/products', null, $ADMIN)['code'] === 200, 'admin signed in');

$TEE = (int) val("SELECT id FROM products WHERE category_id = (SELECT id FROM categories WHERE slug='fashion') ORDER BY id LIMIT 1");
$teeSlug = val("SELECT slug FROM products WHERE id = $TEE");
$price = val("SELECT price FROM products WHERE id = $TEE");

echo "== Admin: size & colour options\n";
$form = req("/admin/products/edit/$TEE", null, $ADMIN)['body'];
t(str_contains($form, 'name="has_sizes"') && str_contains($form, 'name="has_colours"') && str_contains($form, 'data-stock-grid'), 'product form has size/colour switches and stock grid');
$grid = [k('S') => [k('Red') => '3', k('Navy "Blue"') => '0'], k('M') => [k('Red') => '0', k('Navy "Blue"') => '5'], k('L') => [k('Red') => '0', k('Navy "Blue"') => '0']];
$r = saveProduct($TEE, ['has_sizes' => '1', 'sizes' => ' S , M,L, m ', 'has_colours' => '1', 'colour_names' => ['Red', 'Navy "Blue"', ''], 'colour_hexes' => ['#C0392B', '#1f3a93', '#000000'], 'variant_stock' => $grid, 'stock_quantity' => '999']);
$v = variants($TEE);
t(count($v) === 6 && array_column($v, 'size') === ['S', 'S', 'M', 'M', 'L', 'L'], 'sizes S, M, L saved in order, duplicate "m" ignored (6 combinations)', json_encode(array_column($v, 'size')));
t(stockOf($TEE, 'S', 'Red') === 3 && stockOf($TEE, 'M', 'Navy "Blue"') === 5 && stockOf($TEE, 'L', 'Red') === 0, 'stock per size + colour saved');
t($v[0]['colour_hex'] === '#c0392b', 'colour swatch saved (lower-case hex)');
t((int) val("SELECT stock_quantity FROM products WHERE id = $TEE") === 8 && (int) val("SELECT has_sizes + has_colours FROM products WHERE id = $TEE") === 2, 'product stock = total of options (8), posted stock 999 ignored');
t(str_contains($r['page'], 'value="S, M, L"') && str_contains($r['page'], 'Navy &quot;Blue&quot;'), 'form shows saved sizes/colours (escaped)');

$r = saveProduct($TEE, ['has_sizes' => '1', 'sizes' => '', 'has_colours' => '0', 'variant_stock' => []]);
t(str_contains($r['page'], 'Add at least one size') && count(variants($TEE)) === 6, 'sizes on but none entered -> error, nothing changed');
$r = saveProduct($TEE, ['has_sizes' => '1', 'sizes' => 'S, <b>XL</b>', 'variant_stock' => []]);
t(str_contains($r['page'], 'not a valid size') && count(variants($TEE)) === 6, 'size with < > rejected');
$r = saveProduct($TEE, ['has_sizes' => '1', 'sizes' => str_repeat('X', 41), 'variant_stock' => []]);
t(str_contains($r['page'], 'up to 40 characters'), 'size longer than 40 characters rejected');
$r = saveProduct($TEE, ['has_sizes' => '1', 'sizes' => implode(',', range(1, 21)), 'variant_stock' => []]);
t(str_contains($r['page'], 'up to 20 sizes'), 'more than 20 sizes rejected');
$r = saveProduct($TEE, ['has_sizes' => '1', 'sizes' => 'S', 'variant_stock' => [k('S') => ['_' => '-2']]]);
t(str_contains($r['page'], 'Stock for &quot;S&quot; must be a whole number') && count(variants($TEE)) === 6, 'negative option stock rejected');
t(str_contains($r['page'], 'value="S"') && str_contains($r['page'], 'value="-2"'), 'typed options kept after an error');
$r = saveProduct($TEE, ['has_colours' => '1', 'colour_names' => [''], 'colour_hexes' => ['#000000']]);
t(str_contains($r['page'], 'Add at least one colour'), 'colours on but none entered -> error');

// Reorder + drop L: ids of kept combinations stay, stock follows its name
$idSRed = vid($TEE, 'S', 'Red');
saveProduct($TEE, ['has_sizes' => '1', 'sizes' => 'M, S', 'has_colours' => '1', 'colour_names' => ['Navy "Blue"', 'Red'], 'colour_hexes' => ['#1f3a93', '#c0392b'], 'variant_stock' => $grid]);
$v = variants($TEE);
t(count($v) === 4 && $v[0]['size'] === 'M' && $v[0]['colour'] === 'Navy "Blue"' && stockOf($TEE, 'S', 'Red') === 3 && vid($TEE, 'S', 'Red') === $idSRed, 'reordered + L removed: stock follows its size/colour, row kept');

echo "== Storefront product page\n";
$page = req("/product/$teeSlug")['body'];
t(str_contains($page, 'name="size"') && str_contains($page, 'name="colour"') && str_contains($page, 'data-variants='), 'size and colour choices shown');
t((bool) preg_match('/name="size" id="size-\d+" value="S"\s+required\s*>/', $page), 'size S (in stock) can be chosen');
t(str_contains($page, 'option-chip__swatch" style="background-color: #c0392b"'), 'colour swatch shown');
t(str_contains($page, 'data-line-total') && str_contains($page, 'Total: <strong data-line-total>' . '৳'), 'live "Total" line (price × quantity)');
t(str_contains($page, 'data-lightbox') && str_contains($page, 'data-lightbox-open'), 'image zoom viewer present');
saveProduct($TEE, ['has_sizes' => '1', 'sizes' => 'M, S, XL', 'has_colours' => '1', 'colour_names' => ['Navy "Blue"', 'Red'], 'colour_hexes' => ['#1f3a93', '#c0392b'], 'variant_stock' => $grid]);
$page = req("/product/$teeSlug")['body'];
t((bool) preg_match('/value="XL"\s+required disabled/', $page) && (bool) preg_match('/class="option-chip is-out"[^>]*>XL<span class="visually-hidden"> \(out of stock\)/', $page), 'sold-out size XL: crossed out and disabled');
$card = req('/category/fashion')['body'];
t(str_contains($card, 'data-quick-add data-product-id="' . $TEE . '"') && str_contains($card, '&quot;stock&quot;:{') && !preg_match('/<form[^>]+cart\/add[^>]*>\s*<input[^>]+>\s*<input type="hidden" name="product_id" value="' . $TEE . '"/', $card), 'card: "Add To Cart" opens the quick-add popup with this product\'s options (no direct add without a size)');
t(str_contains($card, 'id="quickAdd"') && str_contains($card, 'data-quick-add-form') && !str_contains(req('/product/' . $teeSlug)['body'], 'id="quickAdd"'), 'popup included only on pages with such a card');

echo "== Cart with options\n";
@unlink($SHOP);
t(str_contains(add($TEE, []), 'Please choose a size'), 'no size -> "Please choose a size"');
t(str_contains(add($TEE, ['size' => 'S']), 'Please choose a colour'), 'no colour -> "Please choose a colour"');
t(str_contains(add($TEE, ['size' => 'XXXL', 'colour' => 'Red']), 'not available'), 'unknown size -> not available');
t(str_contains(add($TEE, ['size' => 'XL', 'colour' => 'Red']), 'out of stock'), 'sold-out option -> out of stock');
t(str_contains(add($TEE, ['size' => 'S', 'colour' => 'Blue']), 'not available'), 'colour not offered -> not available');
t(str_contains(add($TEE, ['size' => 'S', 'colour' => 'Red'], 2), 'Added 2'), 'S / Red added');
$pp = req("/product/$teeSlug")['body'];
t((bool) preg_match('/name="size" id="size-\d+" value="S"\s+required\s+checked/', $pp) && (bool) preg_match('/name="colour" id="colour-\d+" value="Red"\s+required\s+checked/', $pp), 'back on the product page the added size/colour is selected again');
t(str_contains($pp, '&quot;in_cart&quot;:2') && str_contains($pp, '&quot;line&quot;:&quot;' . $TEE . ':'), 'each option carries its cart line and count (for the script)');
$vline = $TEE . ':' . (int) $pdo->query("SELECT id FROM product_variants WHERE product_id = $TEE AND size = 'S' AND colour = 'Red'")->fetchColumn();
$r = req('/cart/update', ['_csrf' => tok('/cart'), 'line' => $vline, 'step' => '-1', 'return' => "/product/$teeSlug"]);
$pp = req("/product/$teeSlug")['body'];
t($r['location'] === B . "/product/$teeSlug" && (bool) preg_match('/value="S"\s+required\s+checked/', $pp) && str_contains($pp, '&quot;in_cart&quot;:1'), 'stepper - on an option: back on the page with that size/colour still selected (1 left)');
req('/cart/update', ['_csrf' => tok('/cart'), 'line' => $vline, 'quantity' => '2']);
t(!preg_match('/value="S"\s+required\s+checked/', req("/product/$teeSlug")['body']), 'the re-selection happens once (next visit starts fresh)');
t(str_contains(add($TEE, ['size' => 'S', 'colour' => 'Red'], 5), 'Only 3'), 'quantity capped at that option\'s stock (3)');
t(str_contains(add($TEE, ['size' => 'M', 'colour' => 'Navy "Blue"'], 1), 'Added 1'), 'M / Navy "Blue" added as a separate line');
$cart = req('/cart')['body'];
t(substr_count($cart, 'class="cart-line"') === 2 && str_contains($cart, 'Size: S · Colour: Red') && str_contains($cart, 'Size: M · Colour: Navy &quot;Blue&quot;'), 'cart shows 2 lines with their size/colour');
$line = $TEE . ':' . vid($TEE, 'S', 'Red');
t(str_contains($cart, 'name="line" value="' . $line . '"'), 'cart line key = product:option');
req('/cart/update', ['_csrf' => tok('/cart'), 'line' => $line, 'quantity' => '9']);
t(str_contains(flash(req('/cart')['body']), 'Only 3'), 'update capped at option stock');
req('/cart/update', ['_csrf' => tok('/cart'), 'line' => $TEE . ':999999', 'quantity' => '1']);
t(str_contains(flash(req('/cart')['body']), 'not in your cart'), 'unknown line key refused');
req('/cart/add', ['_csrf' => tok('/shop'), 'product_id' => $line, 'quantity' => '1']);
t(str_contains(flash(req('/cart')['body']), 'not available'), 'product_id with an option key refused on add');

echo "== Checkout with options\n";
$DHAKA = (int) val("SELECT id FROM districts WHERE name='Dhaka'");
$DHANMONDI = (int) val("SELECT id FROM thanas WHERE district_id=$DHAKA AND name='Dhanmondi'");
$before = [stockOf($TEE, 'S', 'Red'), stockOf($TEE, 'M', 'Navy "Blue"'), (int) val("SELECT stock_quantity FROM products WHERE id = $TEE")];
$h = req('/checkout')['body'];
t(str_contains($h, 'Size: S · Colour: Red'), 'checkout shows the chosen size/colour');
preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $h, $fp);
$r = req('/checkout', ['_csrf' => tok('/checkout'), 'cart_fingerprint' => $fp[1], 'payment_method' => 'cod', 'name' => 'Variant Test', 'mobile' => '01711223344',
    'address' => 'House#4', 'district_id' => (string) $DHAKA, 'thana_id' => (string) $DHANMONDI, 'delivery_shown' => '70.00']);
t($r['location'] === B . '/order-success', 'order placed (address "House#4" is enough with district + thana)');
$oid = (int) val('SELECT MAX(id) FROM orders');
$items = $pdo->query("SELECT variant_id, variant_label, quantity FROM order_items WHERE order_id = $oid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
t(count($items) === 2 && $items[0]['variant_label'] === 'Size: S · Colour: Red' && (int) $items[0]['quantity'] === 3 && (int) $items[0]['variant_id'] === vid($TEE, 'S', 'Red'), 'order items store option id + label');
$after = [stockOf($TEE, 'S', 'Red'), stockOf($TEE, 'M', 'Navy "Blue"'), (int) val("SELECT stock_quantity FROM products WHERE id = $TEE")];
t($after === [$before[0] - 3, $before[1] - 1, $before[2] - 4], 'option stock and product total reduced (' . json_encode($before) . ' -> ' . json_encode($after) . ')');
$success = req('/order-success')['body'];
t(str_contains($success, 'Size: S · Colour: Red') && str_contains($success, 'Dhanmondi, Dhaka'), 'confirmation shows size/colour and thana, district');
$view = req("/admin/orders/view/$oid", null, $ADMIN)['body'];
t(str_contains($view, 'Size: S · Colour: Red') && str_contains($view, 'Dhanmondi, Dhaka') && str_contains($view, 'Inside Dhaka'), 'admin order shows size/colour, thana, district, zone');
t(stockOf($TEE, 'S', 'Red') === 0 && (bool) preg_match('/value="S"\s+required disabled/', req("/product/$teeSlug")['body']), 'S now sold out in every colour -> crossed out on the product page');

preg_match('/name="_csrf" value="([a-f0-9]+)"/', $view, $vt);
req("/admin/orders/status/$oid", ['_csrf' => $vt[1], 'current_status' => 'pending', 'status' => 'cancelled', 'note' => ''], $ADMIN);
t(val("SELECT status FROM orders WHERE id = $oid") === 'cancelled' && [stockOf($TEE, 'S', 'Red'), stockOf($TEE, 'M', 'Navy "Blue"'), (int) val("SELECT stock_quantity FROM products WHERE id = $TEE")] === $before, 'cancel returns stock to each option and the product total');

echo "== Options changed while in a cart\n";
@unlink($SHOP);
add($TEE, ['size' => 'M', 'colour' => 'Red'], 1);   // stock 0 -> refused
add($TEE, ['size' => 'S', 'colour' => 'Red'], 1);
saveProduct($TEE, ['has_sizes' => '1', 'sizes' => 'M, XL', 'has_colours' => '1', 'colour_names' => ['Navy "Blue"', 'Red'], 'colour_hexes' => ['#1f3a93', '#c0392b'], 'variant_stock' => $grid]);
$cart = req('/cart')['body'];
t(str_contains($cart, 'Your cart is empty') && str_contains($cart, 'options for'), 'size removed by admin -> line dropped with a notice');

echo "== Concurrency on one option: 6 shoppers, 2 in stock\n";
saveProduct($TEE, ['has_sizes' => '1', 'sizes' => 'M', 'has_colours' => '0', 'variant_stock' => [k('M') => ['_' => '2']]]);
t(count(variants($TEE)) === 1 && (int) val("SELECT stock_quantity FROM products WHERE id = $TEE") === 2, 'sizes only: M with 2 in stock');
$jars = []; $posts = [];
for ($i = 0; $i < 6; $i++) {
    $jars[$i] = __DIR__ . "/vc$i.txt"; @unlink($jars[$i]);
    add($TEE, ['size' => 'M'], 1, $jars[$i]);
    $h = req('/checkout', null, $jars[$i])['body'];
    preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $h, $fp); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $h, $tk);
    $posts[$i] = ['_csrf' => $tk[1], 'cart_fingerprint' => $fp[1], 'payment_method' => 'cod', 'name' => 'Race Test', 'mobile' => '01711000009', 'address' => 'Road 9', 'district_id' => (string) $DHAKA, 'thana_id' => (string) $DHANMONDI];
}
$ordersBefore = (int) val('SELECT COUNT(*) FROM orders');
$mh = curl_multi_init(); $hs = [];
foreach ($jars as $i => $j) {
    $ch = curl_init(B . '/checkout');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $j, CURLOPT_COOKIEFILE => $j, CURLOPT_USERAGENT => 'VariantsTest', CURLOPT_POSTFIELDS => http_build_query($posts[$i])]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
$ok = 0; foreach ($hs as $ch) { if (curl_getinfo($ch, CURLINFO_REDIRECT_URL) === B . '/order-success') $ok++; curl_multi_remove_handle($mh, $ch); }
curl_multi_close($mh);
t($ok === 2 && (int) val('SELECT COUNT(*) FROM orders') - $ordersBefore === 2, "exactly 2 of 6 simultaneous orders succeeded ($ok)");
t(stockOf($TEE, 'M', '') === 0 && (int) val("SELECT stock_quantity FROM products WHERE id = $TEE") === 0, 'option and product stock ended at 0, never negative');
foreach ($jars as $j) { @unlink($j); }

echo "== Switching options off\n";
saveProduct($TEE, ['has_sizes' => '0', 'has_colours' => '0', 'stock_quantity' => '12']);
t(count(variants($TEE)) === 0 && (int) val("SELECT stock_quantity FROM products WHERE id = $TEE") === 12, 'options off: option rows removed, single stock field used (12)');
t(!str_contains(req("/product/$teeSlug")['body'], 'name="size"'), 'product page back to plain quantity');
t(str_contains(add($TEE, [], 1), 'Added 1'), 'plain add to cart works again');

echo "== Admin -> Delivery\n";
t(req('/admin/delivery', null, __DIR__ . '/v_anon.txt')['location'] === B . '/admin/login', 'signed out: /admin/delivery -> login');
@unlink(__DIR__ . '/v_anon.txt');
$d = req('/admin/delivery', null, $ADMIN)['body'];
t(str_contains($d, 'Inside Dhaka') && str_contains($d, 'value="70.00"') && str_contains($d, 'value="130.00"') && substr_count($d, 'data-filter-text=') === 64, 'zones (70 / 130) and 64 districts listed');
t(str_contains($d, 'href="' . B . '/admin/delivery"') || str_contains($d, 'admin/delivery'), 'sidebar has Delivery');
req('/admin/delivery/zones', ['_csrf' => 'forged', 'zone_name' => [1 => 'Inside Dhaka', 2 => 'Outside Dhaka'], 'zone_charge' => [1 => '1', 2 => '1']], $ADMIN);
t(val('SELECT charge FROM delivery_zones WHERE id = 1') === '70.00', 'forged CSRF: charges unchanged');
$t = tok('/admin/delivery', $ADMIN);
req('/admin/delivery/zones', ['_csrf' => $t, 'zone_name' => [1 => 'Inside Dhaka', 2 => 'Outside Dhaka'], 'zone_charge' => [1 => '-5', 2 => '130']], $ADMIN);
t(val('SELECT charge FROM delivery_zones WHERE id = 1') === '70.00' && str_contains(flash(req('/admin/delivery', null, $ADMIN)['body']), 'Enter a delivery charge'), 'negative charge rejected');
$t = tok('/admin/delivery', $ADMIN);
req('/admin/delivery/zones', ['_csrf' => $t, 'zone_name' => [1 => 'Inside Dhaka', 2 => 'Outside Dhaka'], 'zone_charge' => [1 => '80', 2 => '150.50'], 'new_zone_name' => 'Dhaka suburbs', 'new_zone_charge' => '100'], $ADMIN);
t(val('SELECT charge FROM delivery_zones WHERE id = 1') === '80.00' && val('SELECT charge FROM delivery_zones WHERE id = 2') === '150.50' && val("SELECT charge FROM delivery_zones WHERE name='Dhaka suburbs'") === '100.00', 'charges saved (80 / 150.50) and zone "Dhaka suburbs" (100) added');
$t = tok('/admin/delivery', $ADMIN);
req('/admin/delivery/zones', ['_csrf' => $t, 'zone_name' => [1 => 'Outside Dhaka', 2 => 'Outside Dhaka'], 'zone_charge' => [1 => '80', 2 => '150']], $ADMIN);
t(val('SELECT name FROM delivery_zones WHERE id = 1') === 'Inside Dhaka', 'duplicate zone name rejected');

$SUB = (int) val("SELECT id FROM delivery_zones WHERE name='Dhaka suburbs'");
$GAZ = (int) val("SELECT id FROM districts WHERE name='Gazipur'");
req("/admin/delivery/districts/$GAZ", ['_csrf' => tok('/admin/delivery', $ADMIN), 'zone_id' => (string) $SUB, 'return' => 'list'], $ADMIN);
t((int) val("SELECT zone_id FROM districts WHERE id = $GAZ") === $SUB, 'Gazipur moved to "Dhaka suburbs"');
req('/admin/delivery/districts/create', ['_csrf' => tok('/admin/delivery', $ADMIN), 'name' => 'Test District', 'zone_id' => '2'], $ADMIN);
$TD = (int) val("SELECT id FROM districts WHERE name='Test District'");
t($TD > 0, 'district "Test District" added');
req('/admin/delivery/districts/create', ['_csrf' => tok('/admin/delivery', $ADMIN), 'name' => 'test district', 'zone_id' => '2'], $ADMIN);
t((int) val("SELECT COUNT(*) FROM districts WHERE name='Test District'") === 1, 'duplicate district rejected (case-insensitive)');
req("/admin/delivery/districts/$TD/thanas", ['_csrf' => tok("/admin/delivery/districts/$TD", $ADMIN), 'names' => "Alpha Thana\nBeta Thana, Alpha Thana"], $ADMIN);
t((int) val("SELECT COUNT(*) FROM thanas WHERE district_id = $TD") === 2, 'two thanas added in one go (duplicate skipped)');
$ALPHA = (int) val("SELECT id FROM thanas WHERE district_id = $TD AND name='Alpha Thana'");
$BETA = (int) val("SELECT id FROM thanas WHERE district_id = $TD AND name='Beta Thana'");
req("/admin/delivery/thanas/$BETA", ['_csrf' => tok("/admin/delivery/districts/$TD", $ADMIN), 'action' => 'toggle'], $ADMIN);
req("/admin/delivery/thanas/$ALPHA", ['_csrf' => tok("/admin/delivery/districts/$TD", $ADMIN), 'action' => 'save', 'name' => 'Alpha <b>Thana</b>'], $ADMIN);
t(val("SELECT name FROM thanas WHERE id = $ALPHA") === 'Alpha Thana', 'thana name with < > rejected');
req("/admin/delivery/thanas/$ALPHA", ['_csrf' => tok("/admin/delivery/districts/$TD", $ADMIN), 'action' => 'save', 'name' => 'Alpha Bazar'], $ADMIN);
t(val("SELECT name FROM thanas WHERE id = $ALPHA") === 'Alpha Bazar' && (int) val("SELECT is_active FROM thanas WHERE id = $BETA") === 0, 'thana renamed; Beta hidden');
req('/admin/delivery/settings', ['_csrf' => tok('/admin/delivery', $ADMIN), 'free_delivery_from' => '5000'], $ADMIN);
t(val("SELECT setting_value FROM settings WHERE setting_key='free_delivery_from'") === '5000.00', 'free delivery from ৳5,000 saved');

// What checkout sees now
@unlink($SHOP);
add($TEE, [], 1);
$co = req('/checkout')['body'];
t(str_contains($co, '>Test District</option>') && str_contains($co, '>Alpha Bazar</option>') && !str_contains($co, '>Beta Thana</option>'), 'checkout lists the new district and its visible thana only');
t((bool) preg_match('/data-charge="100.00"[^>]*>Gazipur</', $co) && (bool) preg_match('/data-charge="80.00"[^>]*>Dhaka</', $co), 'checkout uses the new charges (Dhaka 80, Gazipur 100)');
t(str_contains($co, 'data-free-from="500000"'), 'checkout knows the free-delivery amount (cents)');
t(str_contains(req('/')['body'], 'Free delivery on orders over ৳5,000.00'), 'announcement bar shows the free-delivery amount');
preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $co, $fp);
$r = req('/checkout', ['_csrf' => tok('/checkout'), 'cart_fingerprint' => $fp[1], 'payment_method' => 'cod', 'name' => 'Beta Test', 'mobile' => '01711223355',
    'address' => 'House 1', 'district_id' => (string) $TD, 'thana_id' => (string) $BETA]);
t($r['location'] === B . '/checkout' && str_contains(req('/checkout')['body'], 'Choose your thana'), 'hidden thana refused at checkout');
req('/checkout', ['_csrf' => tok('/checkout'), 'cart_fingerprint' => $fp[1], 'payment_method' => 'cod', 'name' => 'Gazi Test', 'mobile' => '01711223366',
    'address' => 'House 2', 'district_id' => (string) $GAZ, 'thana_id' => (string) val("SELECT id FROM thanas WHERE district_id = $GAZ ORDER BY id LIMIT 1")]);
t(val('SELECT shipping_fee FROM orders ORDER BY id DESC LIMIT 1') === '100.00' && val('SELECT delivery_zone FROM orders ORDER BY id DESC LIMIT 1') === 'Dhaka suburbs', 'Gazipur order charged the "Dhaka suburbs" 100.00');

// Deleting
req("/admin/delivery/thanas/$ALPHA", ['_csrf' => tok("/admin/delivery/districts/$TD", $ADMIN), 'action' => 'delete'], $ADMIN);
t((int) val("SELECT COUNT(*) FROM thanas WHERE id = $ALPHA") === 0, 'thana deleted');
req("/admin/delivery/zones/delete/$SUB", ['_csrf' => tok('/admin/delivery', $ADMIN)], $ADMIN);
t((int) val("SELECT COUNT(*) FROM delivery_zones WHERE id = $SUB") === 1, 'zone with districts cannot be deleted');
req("/admin/delivery/districts/delete/$GAZ", ['_csrf' => tok("/admin/delivery/districts/$GAZ", $ADMIN)], $ADMIN);
t((int) val("SELECT COUNT(*) FROM districts WHERE id = $GAZ") === 1, 'district with orders cannot be deleted');
req("/admin/delivery/districts/delete/$TD", ['_csrf' => tok("/admin/delivery/districts/$TD", $ADMIN)], $ADMIN);
t((int) val("SELECT COUNT(*) FROM districts WHERE id = $TD") === 0 && (int) val("SELECT COUNT(*) FROM thanas WHERE district_id = $TD") === 0, 'district without orders deleted with its thanas');
t(req('/admin/delivery/districts/999999', null, $ADMIN)['location'] === B . '/admin/delivery', 'unknown district -> back to Delivery');

echo "\nTOTAL: $pass passed, $fail failed\n";
@unlink($ADMIN); @unlink($SHOP);
