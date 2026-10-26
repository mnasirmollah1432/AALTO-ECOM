<?php
// Phase 12: "first day live" walk-through on a fresh schema-only install (no seed),
// following the DEPLOYMENT.md final-testing list. DB checks via root on the test server.
$B = $argv[1] ?? 'http://localhost:8080';
[$dsn, $dbUser, $dbPass] = [$argv[2] ?? 'mysql:host=127.0.0.1;port=3307;dbname=aalto_mart_dev', $argv[3] ?? 'root', $argv[4] ?? 'maria-test'];
$pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = fn (string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();
$pass = 0; $fail = 0;
$t = function (bool $ok, string $label) use (&$pass, &$fail) { $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; };
$jar = tempnam(sys_get_temp_dir(), 'gl'); $cjar = tempnam(sys_get_temp_dir(), 'gc');
$req = function (string $method, string $path, $data = null, string $j = '', array $h = []) use ($B, &$jar) {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : $B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $j ?: $jar, CURLOPT_COOKIEFILE => $j ?: $jar,
        CURLOPT_USERAGENT => 'Mozilla/5.0 GoLive', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^location: (.*)$/mi', substr($raw, 0, $hs), $m);
    return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => trim($m[1] ?? ''), 'head' => substr($raw, 0, $hs)];
};
$tok = fn (string $body) => preg_match('/name="_csrf" value="([a-f0-9]+)"/', $body, $m) ? $m[1] : '';
$FX = __DIR__ . '/fixtures';

echo "== Empty store (schema only)\n";
foreach (['/' => 200, '/shop' => 200, '/cart' => 200, '/sitemap.xml' => 200, '/robots.txt' => 200, '/admin/login' => 200] as $p => $c) {
    $r = $req('GET', $p); $t($r['code'] === $c && !preg_match('/Fatal|Warning:|Notice:|Something went wrong/', $r['body']), "empty store $p -> $c");
}

echo "== Admin sign-in (real account created with a hash, no seed)\n";
$r = $req('POST', '/admin/login', ['_csrf' => $tok($req('GET', '/admin/login')['body']), 'email' => 'owner@aalto-mart.test', 'password' => 'Real-Admin-Pass-2026!']);
$dash = $req('GET', '/admin/dashboard');
$t($dash['code'] === 200 && str_contains($dash['body'], 'Shop Owner'), 'owner signs in, dashboard loads');
$t(!str_contains($dash['body'], 'sample admin password'), 'no sample-password warning for a real password');
$old = $req('POST', '/admin/login', ['_csrf' => $tok($req('GET', '/admin/login', null, $cjar)['body']), 'email' => 'admin@example.com', 'password' => 'Admin@12345'], $cjar);
$t(str_contains($req('GET', '/admin/dashboard', null, $cjar)['loc'], '/admin/login'), 'sample admin@example.com / Admin@12345 does not exist');

echo "== Category management\n";
$tk = $tok($req('GET', '/admin/categories/create')['body']);
$req('POST', '/admin/categories/create', ['_csrf' => $tk, 'name' => 'Men\'s Shirts', 'status' => 'active', 'description' => 'Cotton and linen shirts.',
    'image' => new CURLFile("$FX/valid.jpg", 'image/jpeg', 'shirts.jpg')]);
$cid = (int) $q("SELECT id FROM categories WHERE name = 'Men''s Shirts'");
$cimg = (string) $q('SELECT image_path FROM categories WHERE id = ?', [$cid]);
$t($cid > 0 && preg_match('#^uploads/categories/[a-f0-9]{32}\.webp$#', $cimg) === 1 && is_file("D:/SDL-PROJECT/AALTO-ECOM/public/$cimg"), 'category created with image (WebP in uploads/categories)');
$t($req('GET', '/category/mens-shirts')['code'] === 200, 'category page /category/mens-shirts');
$t(str_contains($req('GET', '/')['body'], 'Men&#039;s Shirts'), 'category on homepage');

echo "== Product management\n";
$tk = $tok($req('GET', '/admin/products/create')['body']);
$req('POST', '/admin/products/create', ['_csrf' => $tk, 'name' => 'Linen Shirt', 'category_id' => (string) $cid, 'regular_price' => '1850', 'sale_price' => '1499',
    'stock_quantity' => '3', 'is_active' => '1', 'is_featured' => '1', 'short_description' => 'Breathable linen, relaxed fit.',
    'main_image' => new CURLFile("$FX/valid.jpg", 'image/jpeg', 'linen.jpg'), 'images[]' => new CURLFile("$FX/valid.png", 'image/png', 'back.png')]);
$pid = (int) $q("SELECT id FROM products WHERE name = 'Linen Shirt'");
$t($pid > 0 && $q('SELECT price FROM products WHERE id = ?', [$pid]) === '1499.00', 'product created, sale price active (1499.00)');
$t((int) $q('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$pid]) === 2, 'main + extra image stored');
$tk = $tok($req('GET', "/admin/products/edit/$pid")['body']);
$r = $req('POST', "/admin/products/toggle/$pid", ['_csrf' => $tk, 'flag' => 'new'], '', ['Accept: application/json']);
$t($r['code'] === 200 && (int) $q('SELECT is_new FROM products WHERE id = ?', [$pid]) === 1, 'toggle "new" flag (JSON)');

echo "== Storefront\n";
$home = $req('GET', '/')['body'];
$t(str_contains($home, 'Linen Shirt'), 'homepage shows the featured product');
$t(str_contains($req('GET', '/shop')['body'], 'Linen Shirt'), 'shop lists it');
$t(str_contains($req('GET', '/shop?q=linen')['body'], 'Linen Shirt') && str_contains($req('GET', '/shop?q=zzzz')['body'], 'No products'), 'search finds it / empty search');
$pp = $req('GET', '/product/linen-shirt');
$t($pp['code'] === 200 && str_contains($pp['body'], '1,499.00') && str_contains($pp['body'], '"@type":"Product"'), 'product page with price + structured data');
$t(str_contains($req('GET', '/sitemap.xml')['body'], '/product/linen-shirt'), 'sitemap lists the product');

echo "== Cart, checkout, order creation, stock reduction\n";
$shopper = tempnam(sys_get_temp_dir(), 'sh');
$req('POST', '/cart/add', ['_csrf' => $tok($req('GET', '/product/linen-shirt', null, $shopper)['body']), 'product_id' => (string) $pid, 'quantity' => '2'], $shopper);
$cart = $req('GET', '/cart', null, $shopper)['body'];
$t(str_contains($cart, 'Linen Shirt') && str_contains($cart, '2,998.00'), 'cart: 2 x 1,499.00 = 2,998.00');
$co = $req('GET', '/checkout', null, $shopper)['body'];
preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $co, $fp);
$r = $req('POST', '/checkout', ['_csrf' => $tok($co), 'cart_fingerprint' => $fp[1] ?? '', 'name' => 'Rahim Uddin', 'mobile' => '01711-222333',
    'address' => 'House 12, Road 5, Dhanmondi, Dhaka', 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14'], $shopper);
$ok = $req('GET', '/order-success', null, $shopper)['body'];
$oid = (int) $q("SELECT id FROM orders WHERE customer_phone = '01711222333'");
$t($oid > 0 && str_contains($ok, 'Rahim Uddin'), 'order placed, confirmation shown');
$t($q('SELECT grand_total FROM orders WHERE id = ?', [$oid]) === '3068.00' && $q('SELECT shipping_fee FROM orders WHERE id = ?', [$oid]) === '70.00', 'total 2,998.00 + Inside Dhaka 70.00 = 3,068.00');
$t((int) $q('SELECT stock_quantity FROM products WHERE id = ?', [$pid]) === 1, 'stock reduced 3 -> 1');
$t(str_contains($req('GET', '/cart', null, $shopper)['body'], 'Your cart is empty'), 'cart emptied after order');

echo "== Order management\n";
$list = $req('GET', '/admin/orders')['body'];
$num = (string) $q('SELECT order_number FROM orders WHERE id = ?', [$oid]);
$t(str_contains($list, $num) && str_contains($req('GET', '/admin/dashboard')['body'], '2,998.00') === false || str_contains($list, $num), "order $num in admin list");
foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $to) {
    $v = $req('GET', "/admin/orders/view/$oid")['body'];
    $from = (string) $q('SELECT status FROM orders WHERE id = ?', [$oid]);
    $req('POST', "/admin/orders/status/$oid", ['_csrf' => $tok($v), 'status' => $to, 'current_status' => $from, 'note' => "Now $to"]);
}
$t($q('SELECT status FROM orders WHERE id = ?', [$oid]) === 'delivered' && $q('SELECT payment_status FROM orders WHERE id = ?', [$oid]) === 'paid', 'pending -> ... -> delivered; COD marked paid');
$t((int) $q('SELECT COUNT(*) FROM order_status_history WHERE order_id = ?', [$oid]) === 5, 'status history recorded (5 entries)');
// A second order, cancelled: stock comes back
$s2 = tempnam(sys_get_temp_dir(), 's2');
$req('POST', '/cart/add', ['_csrf' => $tok($req('GET', '/product/linen-shirt', null, $s2)['body']), 'product_id' => (string) $pid, 'quantity' => '1'], $s2);
$co = $req('GET', '/checkout', null, $s2)['body']; preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $co, $fp);
$req('POST', '/checkout', ['_csrf' => $tok($co), 'cart_fingerprint' => $fp[1] ?? '', 'name' => 'Karim Test', 'mobile' => '01811-444555', 'address' => 'Road 1, Uttara, Dhaka', 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14'], $s2);
$o2 = (int) $q("SELECT id FROM orders WHERE customer_phone = '01811444555'");
$t($o2 > 0 && (int) $q('SELECT stock_quantity FROM products WHERE id = ?', [$pid]) === 0, 'last unit sold -> stock 0, shown as sold out');
$t(str_contains($req('GET', '/product/linen-shirt')['body'], 'Out of stock'), 'product page says Out of stock');
$v = $req('GET', "/admin/orders/view/$o2")['body'];
$req('POST', "/admin/orders/status/$o2", ['_csrf' => $tok($v), 'status' => 'cancelled', 'current_status' => 'pending', 'note' => 'Customer cancelled']);
$t($q('SELECT status FROM orders WHERE id = ?', [$o2]) === 'cancelled' && (int) $q('SELECT stock_quantity FROM products WHERE id = ?', [$pid]) === 1, 'cancel returns stock (0 -> 1)');

echo "== Delete rules: sold products and used categories are kept (deactivate instead)\n";
$req('POST', "/admin/products/delete/$pid", ['_csrf' => $tok($req('GET', '/admin/products')['body'])]);
$t((bool) $q('SELECT COUNT(*) FROM products WHERE id = ?', [$pid]), 'product with orders cannot be deleted (order history stays intact)');
$req('POST', "/admin/categories/delete/$cid", ['_csrf' => $tok($req('GET', '/admin/categories')['body'])]);
$t((bool) $q('SELECT COUNT(*) FROM categories WHERE id = ?', [$cid]), 'category with products cannot be deleted');
$req('POST', "/admin/products/toggle/$pid", ['_csrf' => $tok($req('GET', '/admin/products')['body']), 'flag' => 'active'], '', ['Accept: application/json']);
$t((int) $q('SELECT is_active FROM products WHERE id = ?', [$pid]) === 0 && $req('GET', '/product/linen-shirt')['code'] === 404, 'deactivated product disappears from the store (404)');
$t((string) $q('SELECT product_name FROM order_items WHERE order_id = ?', [$oid]) === 'Linen Shirt', 'past order still shows "Linen Shirt"');
echo "\nTOTAL: {$pass} passed, {$fail} failed\n";
