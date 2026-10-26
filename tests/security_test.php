<?php
// Phase 11 - security audit: live attack payloads against the dev server + DB checks.
const B = 'http://localhost:8080';
const FX = __DIR__ . '/fixtures';
const PUB = 'D:/SDL-PROJECT/AALTO-ECOM/public';
$pass = 0; $fail = 0;
function t(bool $ok, string $label): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; }
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function q(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

/** A browser: own cookie jar + User-Agent. */
final class Client {
    public string $jar;
    public function __construct(public string $ua = 'Mozilla/5.0 SecTest') { $this->jar = tempnam(sys_get_temp_dir(), 'sec'); }
    public function req(string $method, string $path, array|string|null $data = null, array $headers = []): array {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : B . $path);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
              CURLOPT_USERAGENT => $this->ua, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_PATH_AS_IS => true];
        if ($data !== null) $o[CURLOPT_POSTFIELDS] = is_array($data) && !array_filter($data, fn ($v) => $v instanceof CURLFile) ? http_build_query($data) : $data;
        curl_setopt_array($ch, $o);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        preg_match('/^location: (.*)$/mi', substr($raw, 0, $hs), $m);
        return ['code' => $code, 'head' => substr($raw, 0, $hs), 'body' => substr($raw, $hs), 'loc' => trim($m[1] ?? '')];
    }
    public function get(string $p): array { return $this->req('GET', $p); }
    public function post(string $p, array $d = []): array { return $this->req('POST', $p, $d); }
    public function token(string $page): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->get($page)['body'], $m); return $m[1] ?? ''; }
    public function cookie(): string { preg_match('/aalto_session\s+(\S+)/', (string) @file_get_contents($this->jar), $m); return $m[1] ?? ''; }
    public function login(string $email = 'admin@example.com', string $pw = 'Admin@12345'): array {
        return $this->post('/admin/login', ['_csrf' => $this->token('/admin/login'), 'email' => $email, 'password' => $pw]);
    }
}
$noSqlError = fn (string $b) => !preg_match('/SQLSTATE|PDOException|syntax error|mysql|You have an error in your SQL/i', $b);
$pdo->exec('DELETE FROM login_attempts');

echo "== SQL injection\n";
$c = new Client();
$payloads = ["' OR '1'='1", "' OR 1=1-- -", "1' UNION SELECT password_hash,2,3 FROM users-- -", "\" OR \"\"=\"", "'; DROP TABLE products; --", "%' AND SLEEP(3) AND '%'='", "\\' OR 1=1 #"];
foreach ($payloads as $p) {
    $t0 = microtime(true); $r = $c->get('/shop?q=' . rawurlencode($p)); $ms = (microtime(true) - $t0) * 1000;
    t($r['code'] === 200 && $noSqlError($r['body']) && str_contains($r['body'], 'No products') && $ms < 2500 && !str_contains($r['body'], '$2y$'), "search: " . $p);
}
foreach (['/shop?sort=' . rawurlencode('price_asc, (SELECT SLEEP(3))'), '/shop?category=' . rawurlencode("x' OR '1'='1"), '/shop?page=' . rawurlencode('1 OR 1=1'),
          '/shop?sale=' . rawurlencode("1' OR '1"), '/category/' . rawurlencode("electronics' OR '1'='1"), '/product/' . rawurlencode("x' OR '1'='1"),
          '/product/' . rawurlencode("sample-wireless-earbuds' -- -"), '/category/' . rawurlencode('electronics" UNION SELECT 1-- ')] as $path) {
    $t0 = microtime(true); $r = $c->get($path); $ms = (microtime(true) - $t0) * 1000;
    t(in_array($r['code'], [200, 301, 302, 404], true) && $noSqlError($r['body']) && $ms < 2500, "no injection via " . urldecode($path) . " ({$r['code']})");
}
t(q('SELECT COUNT(*) FROM products') == 25 && q("SELECT COUNT(*) FROM users") == 1, 'database intact after payloads');
$r = (new Client())->login("admin@example.com' OR '1'='1", "' OR '1'='1");
t(str_contains((new Client())->get('/admin/dashboard')['loc'], '/admin/login'), 'login SQLi: no access');
$c2 = new Client(); $c2->login("' OR 1=1 -- ", 'x');
t(str_contains($c2->get('/admin/dashboard')['loc'], '/admin/login'), 'login with SQLi email: rejected');
$pdo->exec('DELETE FROM login_attempts');

echo "== Reflected XSS\n";
$xss = ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>', "'><svg onload=alert(1)>", 'javascript:alert(1)', '</title><script>alert(1)</script>'];
foreach ($xss as $p) {
    foreach (['/shop?q=', '/shop?sort=', '/shop?page=', '/category/electronics?q=', '/nope/'] as $base) {
        $r = $c->get($base . rawurlencode($p));
        $b = $r['body'];
        t(!str_contains($b, '<script>alert') && !preg_match('/<img[^>]+onerror=alert/i', $b) && !preg_match('/<svg[^>]+onload=alert/i', $b) && !str_contains($b, 'href="javascript:'), "escaped: {$base}" . $p);
    }
}
$r = $c->get('/product/' . rawurlencode('<script>alert(1)</script>'));
t($r['code'] === 404 && !str_contains($r['body'], '<script>alert'), 'product slug payload: 404, not reflected');
t(str_contains($c->get('/shop?q=' . rawurlencode('<b>x</b>'))['body'], '&lt;b&gt;x&lt;/b&gt;'), 'search term shown HTML-escaped');

echo "== Stored XSS (checkout -> admin)\n";
$shop = new Client();
$shop->post('/cart/add', ['_csrf' => $shop->token('/product/sample-wireless-earbuds'), 'product_id' => 1, 'quantity' => 1]);
$page = $shop->get('/checkout')['body'];
preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $page, $fp); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page, $tk);
$shop->post('/checkout', ['_csrf' => $tk[1], 'cart_fingerprint' => $fp[1], 'name' => "Eve O'Brien", 'mobile' => '01711000001',
    'address' => '<script>alert("a")</script><img src=x onerror=alert(1)> Road 1, Dhaka', 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14']);
$oid = (int) q("SELECT id FROM orders WHERE customer_phone = '01711000001' ORDER BY id DESC LIMIT 1");
t($oid > 0, 'order with payloads stored (as text)');
$succ = $shop->get('/order-success')['body'];
t(!str_contains($succ, '<script>alert') && !str_contains($succ, '<img src=x') && str_contains($succ, '&lt;script&gt;'), 'confirmation page escapes stored payload');
$admin = new Client(); $admin->login();
$list = $admin->get('/admin/orders')['body']; $view = $admin->get("/admin/orders/view/$oid")['body'];
t(!str_contains($list, '<script>alert') && !str_contains($view, '<script>alert') && !str_contains($view, '<img src=x'), 'admin order list/view escape stored payload');

echo "== CSRF (signed-in admin, requests without / with foreign token)\n";
$foreign = (new Client())->token('/admin/login');
$before = q('SELECT CONCAT(COUNT(*), SUM(is_featured), SUM(is_active)) FROM products') . q('SELECT COUNT(*) FROM categories') . q("SELECT status FROM orders WHERE id = $oid");
foreach ([['/admin/products/create', ['name' => 'CSRF Prod', 'category_id' => 1, 'regular_price' => 1, 'stock_quantity' => 1, 'is_active' => 1]],
          ['/admin/products/edit/1', ['name' => 'Hacked', 'category_id' => 1, 'regular_price' => 1, 'stock_quantity' => 1]],
          ['/admin/products/toggle/1', ['flag' => 'featured']], ['/admin/products/delete/2', []],
          ['/admin/categories/create', ['name' => 'CSRF Cat', 'status' => 'active']], ['/admin/categories/edit/1', ['name' => 'Hacked', 'status' => 'active']],
          ['/admin/categories/toggle/1', []], ['/admin/categories/delete/5', []],
          ["/admin/orders/status/$oid", ['status' => 'cancelled', 'current_status' => 'pending']]] as [$path, $data]) {
    $admin->post($path, $data);
    $admin->post($path, $data + ['_csrf' => $foreign]);
    $admin->req('POST', $path, http_build_query($data + ['_csrf' => $foreign]), ['Accept: application/json']);
}
$after = q('SELECT CONCAT(COUNT(*), SUM(is_featured), SUM(is_active)) FROM products') . q('SELECT COUNT(*) FROM categories') . q("SELECT status FROM orders WHERE id = $oid");
t($before === $after && !q("SELECT COUNT(*) FROM products WHERE name IN ('Hacked','CSRF Prod')"), 'no admin change without a valid token (9 routes x 3 ways)');
$admin->post('/admin/logout', []);
t(!str_contains($admin->get('/admin/dashboard')['loc'], '/admin/login'), 'logout without token does not sign out (no forced logout)');
$cart = new Client(); $ctok = $cart->token('/product/sample-wireless-earbuds');
$cart->post('/cart/add', ['product_id' => 1, 'quantity' => 1]);
$cart->post('/cart/add', ['_csrf' => $foreign, 'product_id' => 1, 'quantity' => 1]);
t(str_contains($cart->get('/cart')['body'], 'Your cart is empty'), 'cart add without valid token refused');
$cart->post('/cart/add', ['_csrf' => $ctok, 'product_id' => 1, 'quantity' => 1]);
foreach (['/cart/update' => ['product_id' => 1, 'quantity' => 5], '/cart/remove' => ['product_id' => 1], '/cart/clear' => []] as $p => $d) $cart->post($p, $d);
t(str_contains($cart->get('/cart')['body'], 'value="1" min="0"'), 'cart update/remove/clear without token refused');
$n = q('SELECT COUNT(*) FROM orders');
$page = $cart->get('/checkout')['body']; preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $page, $fp);
$cart->post('/checkout', ['cart_fingerprint' => $fp[1], 'name' => 'No Token', 'mobile' => '01711000002', 'address' => 'Road 2, Dhaka', 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14']);
t(q('SELECT COUNT(*) FROM orders') == $n, 'checkout without token: no order');
$ev = new Client(); $ev->login(); // login CSRF: token required
$lc = new Client(); $lc->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'Admin@12345']);
t(str_contains($lc->get('/admin/dashboard')['loc'], '/admin/login'), 'login without token refused (login CSRF)');

echo "== Authentication & sessions\n";
$hash = q('SELECT password_hash FROM users WHERE id = 1');
t(str_starts_with($hash, '$2y$') && strlen($hash) === 60 && !q("SELECT COUNT(*) FROM users WHERE password_hash LIKE '%Admin@12345%'"), 'password stored as bcrypt hash only');
$a = new Client(); $r1 = $a->login('admin@example.com', 'wrong-password'); $m1 = $a->get('/admin/login')['body'];
$b2 = new Client(); $b2->login('nobody@example.com', 'wrong-password'); $m2 = $b2->get('/admin/login')['body'];
t(str_contains($m1, 'Incorrect email or password') && str_contains($m2, 'Incorrect email or password'), 'same error for unknown email and wrong password');
$fix = new Client(); file_put_contents($fix->jar, "localhost\tFALSE\t/\tFALSE\t0\taalto_session\tattackerchosenid1234567890\n");
$fix->get('/'); t($fix->cookie() !== 'attackerchosenid1234567890', 'unknown session id rejected (strict mode, no fixation)');
$s = new Client(); $s->get('/admin/login'); $pre = $s->cookie(); $s->login(); $post = $s->cookie();
t($pre !== '' && $post !== '' && $pre !== $post, 'session id regenerated on login');
$head = $s->get('/admin/dashboard')['head'];
t($s->get('/admin/dashboard')['code'] === 200, 'signed-in admin reaches dashboard');
$thief = new Client('Mozilla/5.0 OtherBrowser'); file_put_contents($thief->jar, "localhost\tFALSE\t/\tFALSE\t0\taalto_session\t$post\n");
t(str_contains($thief->get('/admin/dashboard')['loc'], '/admin/login'), 'stolen cookie from another browser (User-Agent) rejected');
$s2 = new Client(); $s2->login(); $old = $s2->cookie(); $s2->post('/admin/logout', ['_csrf' => $s2->token('/admin/dashboard')]);
$replay = new Client(); file_put_contents($replay->jar, "localhost\tFALSE\t/\tFALSE\t0\taalto_session\t$old\n");
t(str_contains($s2->get('/admin/dashboard')['loc'], '/admin/login') && str_contains($replay->get('/admin/dashboard')['loc'], '/admin/login'), 'logout ends the session; old cookie is useless');
$sc = (new Client())->get('/')['head'];
t((bool) preg_match('/set-cookie: aalto_session=[^;]+;.*httponly/i', $sc) && stripos($sc, 'samesite=lax') !== false, 'session cookie HttpOnly + SameSite=Lax');
$pdo->exec("INSERT INTO users (name, email, password_hash, role, is_active) VALUES ('Cust', 'cust@example.com', '" . password_hash('Customer@12345', PASSWORD_DEFAULT) . "', 'customer', 1)");
$cu = new Client(); $cu->login('cust@example.com', 'Customer@12345');
t(str_contains($cu->get('/admin/dashboard')['loc'], '/admin/login'), 'customer-role user cannot sign in to admin');
$pdo->exec("DELETE FROM users WHERE email = 'cust@example.com'");
$d = new Client(); $d->login(); t($d->get('/admin/dashboard')['code'] === 200, 'admin signed in');
$pdo->exec('UPDATE users SET is_active = 0 WHERE id = 1');
t(str_contains($d->get('/admin/dashboard')['loc'], '/admin/login'), 'disabled admin loses access immediately');
$pdo->exec('UPDATE users SET is_active = 1 WHERE id = 1');
$pdo->exec('DELETE FROM login_attempts');
$lk = new Client(); for ($i = 0; $i < 5; $i++) $lk->login('admin@example.com', 'wrong' . $i);
$lk->login(); t(str_contains($lk->get('/admin/dashboard')['loc'], '/admin/login') && str_contains($lk->get('/admin/login')['body'], 'Too many'), 'locked after 5 failures, even with the right password');
$pdo->exec('DELETE FROM login_attempts');

echo "== Authorization (not signed in)\n";
$anon = new Client();
foreach (['/admin/dashboard', '/admin/products', '/admin/products/create', '/admin/products/edit/1', '/admin/categories', '/admin/categories/edit/1',
          '/admin/orders', "/admin/orders/view/$oid", '/admin/logout', '/admin/unknown-page', '/admin/products/edit'] as $p) {
    $r = $anon->get($p); t($r['code'] === 302 && str_ends_with($r['loc'], '/admin/login'), "GET $p -> login");
}
$tok = $anon->token('/admin/login');
$before = q('SELECT CONCAT(COUNT(*), SUM(is_featured)) FROM products') . q('SELECT COUNT(*) FROM categories') . q("SELECT status FROM orders WHERE id = $oid");
foreach (['/admin/products/create', '/admin/products/toggle/1', '/admin/products/delete/3', '/admin/categories/create', '/admin/categories/delete/4', "/admin/orders/status/$oid"] as $p) {
    $r = $anon->post($p, ['_csrf' => $tok, 'name' => 'Anon', 'flag' => 'featured', 'status' => 'cancelled', 'current_status' => 'pending', 'category_id' => 1, 'regular_price' => 1, 'stock_quantity' => 1]);
    t($r['code'] === 302 && str_ends_with($r['loc'], '/admin/login'), "POST $p (valid guest token) -> login");
}
t($before === q('SELECT CONCAT(COUNT(*), SUM(is_featured)) FROM products') . q('SELECT COUNT(*) FROM categories') . q("SELECT status FROM orders WHERE id = $oid"), 'nothing changed by guest admin requests');
$r = $anon->get('/admin/products/edit/1%0d%0aSet-Cookie:x=1'); t(!preg_match('/^set-cookie: x=1/mi', $r['head']), 'no header injection via admin path');
$other = new Client(); t(str_ends_with($other->get('/order-success')['loc'], '/'), "another visitor can't see someone's order confirmation");
t(!preg_match('/\b(order_id|orders\/view)\b/', $other->get('/order-success?id=' . $oid)['body']), 'order-success ignores ?id= (no IDOR)');

echo "== Checkout manipulation\n";
$pdo->exec('UPDATE products SET stock_quantity = 10 WHERE id = 7');
$price = q('SELECT price FROM products WHERE id = 7');
$m = new Client(); $m->post('/cart/add', ['_csrf' => $m->token('/product/' . q('SELECT slug FROM products WHERE id = 7')), 'product_id' => 7, 'quantity' => 2]);
$page = $m->get('/checkout')['body']; preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $page, $fp); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page, $tk);
$evil = ['_csrf' => $tk[1], 'cart_fingerprint' => $fp[1], 'name' => 'Mallory Test', 'mobile' => '01711000003', 'address' => 'Road 3, Dhaka', 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14',
    'price' => '1', 'unit_price' => '1', 'subtotal' => '1', 'total' => '1', 'grand_total' => '1', 'shipping_fee' => '0', 'status' => 'delivered',
    'payment_status' => 'paid', 'stock_quantity' => '999', 'items[7][price]' => '1', 'items[7][qty]' => '1', 'product_id' => '1', 'quantity' => '50', 'order_number' => 'HACK-1'];
$m->post('/checkout', $evil);
$o = $pdo->query("SELECT * FROM orders WHERE customer_phone = '01711000003'")->fetch(PDO::FETCH_ASSOC);
$sub = number_format(2 * (float) $price, 2, '.', ''); $del = '70.00'; // Dhaka = Inside Dhaka zone
t($o && $o['subtotal'] === $sub && $o['shipping_fee'] === $del && $o['grand_total'] === number_format((float) $sub + (float) $del, 2, '.', ''), "totals from DB ({$o['grand_total']}), not the posted 1.00");
t($o && $o['status'] === 'pending' && $o['payment_status'] === 'unpaid' && $o['payment_method'] === 'cod' && $o['order_number'] !== 'HACK-1', 'status/payment/number set by server');
t((int) q('SELECT stock_quantity FROM products WHERE id = 7') === 8 && (int) q("SELECT SUM(quantity) FROM order_items WHERE order_id = ?", [$o['id']]) === 2, 'stock reduced by the cart quantity (2), posted stock ignored');
t(q('SELECT unit_price FROM order_items WHERE order_id = ?', [$o['id']]) === $price, 'line price = DB price');
$m2 = new Client(); $m2->post('/cart/add', ['_csrf' => $m2->token('/'), 'product_id' => 7, 'quantity' => 1]);
$page = $m2->get('/checkout')['body']; preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page, $tk);
$n = q('SELECT COUNT(*) FROM orders');
$m2->post('/checkout', ['_csrf' => $tk[1], 'cart_fingerprint' => str_repeat('0', 64), 'name' => 'Forged Fp', 'mobile' => '01711000004', 'address' => 'Road 4, Dhaka', 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14']);
t(q('SELECT COUNT(*) FROM orders') == $n, 'forged cart fingerprint: order refused');
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $m2->get('/checkout')['body'], $tk); preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $m2->get('/checkout')['body'], $fp);
$m2->post('/checkout', ['_csrf' => $tk[1], 'cart_fingerprint' => $fp[1], 'name' => 'Card Pay', 'mobile' => '01711000005', 'address' => 'Road 5, Dhaka', 'payment_method' => 'card']);
t(q('SELECT COUNT(*) FROM orders') == $n, 'unsupported payment method refused');
$pdo->exec('UPDATE products SET stock_quantity = 1 WHERE id = 7');
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $m2->get('/checkout')['body'], $tk);
$m3 = new Client(); $m3->post('/cart/add', ['_csrf' => $m3->token('/'), 'product_id' => 7, 'quantity' => 1]);
$place = function (Client $cl) { $pg = $cl->get('/checkout')['body']; preg_match('/name="_csrf" value="([a-f0-9]+)"/', $pg, $t); preg_match('/name="cart_fingerprint" value="([a-f0-9]+)"/', $pg, $f);
    return $cl->post('/checkout', ['_csrf' => $t[1] ?? '', 'cart_fingerprint' => $f[1] ?? '', 'name' => 'Race Test', 'mobile' => '01711000006', 'address' => 'Road 6, Dhaka', 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14']); };
$place($m2); $place($m3);
t((int) q('SELECT stock_quantity FROM products WHERE id = 7') === 0 && (int) q("SELECT COUNT(*) FROM orders WHERE customer_phone = '01711000006'") === 1, 'last unit sold once, stock never negative');

echo "== Session cart manipulation\n";
$cm = new Client(); $tk = $cm->token('/');
$pdo->exec('UPDATE products SET is_active = 0 WHERE id = 3');
foreach (['-1', '0', '99999999', 'abc', '1.5', '1e3', '0x1', ' 1', '4294967296', '1; DROP TABLE products', '3'] as $id) $cm->post('/cart/add', ['_csrf' => $tk, 'product_id' => $id, 'quantity' => 1]);
$cm->req('POST', '/cart/add', '_csrf=' . $tk . '&product_id[]=1&quantity=1', ['Content-Type: application/x-www-form-urlencoded']);
$cm->req('POST', '/cart/add', '_csrf=' . $tk . '&product_id=1&quantity[]=5', ['Content-Type: application/x-www-form-urlencoded']);
t(str_contains($cm->get('/cart')['body'], 'Your cart is empty'), 'invalid, unknown, inactive and array product ids never enter the cart');
foreach (['-5', '99999999999', 'abc', '2.5', '1e9'] as $qty) $cm->post('/cart/add', ['_csrf' => $tk, 'product_id' => 1, 'quantity' => $qty]);
$body = $cm->get('/cart')['body'];
t(str_contains($body, 'Your cart is empty') || (bool) preg_match('/name="quantity" value="(\d+)"/', $body, $mq) && (int) $mq[1] <= (int) q('SELECT LEAST(stock_quantity, 99) FROM products WHERE id = 1'), 'bad quantities rejected or capped at stock');
$cm->post('/cart/add', ['_csrf' => $tk, 'product_id' => 1, 'quantity' => 1]);
$cm->post('/cart/update', ['_csrf' => $tk, 'product_id' => 1, 'step' => '50']);
$cm->post('/cart/update', ['_csrf' => $tk, 'product_id' => 1, 'quantity' => '-3']);
preg_match('/name="quantity" value="(\d+)"/', $cm->get('/cart')['body'], $mq);
t(isset($mq[1]) && (int) $mq[1] >= 1 && (int) $mq[1] <= (int) q('SELECT stock_quantity FROM products WHERE id = 1'), 'step other than +/-1 and negative quantity ignored');
$cm->post('/cart/update', ['_csrf' => $tk, 'product_id' => 2, 'quantity' => 5]);
t(!str_contains($cm->get('/cart')['body'], q('SELECT name FROM products WHERE id = 2')), 'update cannot add a product that is not in the cart');
$r = $cm->post('/cart/add', ['_csrf' => $tk, 'product_id' => 1, 'quantity' => 1, 'return' => '//evil.example/steal']);
t(!str_contains($r['loc'], 'evil.example'), 'cart return URL: no open redirect');
$r = $cm->post('/cart/add', ['_csrf' => $tk, 'product_id' => 1, 'quantity' => 1, 'return' => "/\\evil.example"]);
t(!str_contains($r['loc'], 'evil.example') || str_starts_with($r['loc'], B . '/'), 'backslash return URL stays on site');
$pdo->exec('UPDATE products SET is_active = 1 WHERE id = 3');

echo "== File upload attacks\n";
$up = new Client(); $up->login(); $tk = $up->token('/admin/products/create');
$files = ['shell.php' => 'application/x-php', 'shell.php.png' => 'image/png', 'polyglot.png' => 'image/png', 'image.svg' => 'image/svg+xml', 'fake.jpg' => 'image/jpeg', 'image.gif' => 'image/gif', 'png-named.jpg' => 'image/jpeg'];
$before = count(glob(PUB . '/uploads/products/*'));
foreach ($files as $f => $mime) {
    $up->req('POST', '/admin/products/create', ['_csrf' => $tk, 'name' => 'Upload ' . $f, 'category_id' => '1', 'regular_price' => '100', 'stock_quantity' => '1', 'is_active' => '1',
        'main_image' => new CURLFile(FX . '/' . $f, $mime, $f)]);
}
$stored = glob(PUB . '/uploads/products/*');
t(!array_filter($stored, fn ($p) => !preg_match('#/[a-f0-9]{32}(-sm)?\.webp$#', $p)), 'only random-named .webp files ever stored (' . (count($stored) - $before) . ' new)');
$bad = 0; foreach ($stored as $p) { $h = file_get_contents($p, false, null, 0, 4096); if (stripos($h, '<?php') !== false || stripos($h, '<script') !== false) $bad++; }
t($bad === 0, 'no PHP/script content survives re-encoding (polyglot neutralised)');
t(!q("SELECT COUNT(*) FROM product_images WHERE image_path LIKE 'uploads/%' AND image_path NOT REGEXP '^uploads/products/[a-f0-9]{32}[.]webp$'"), 'uploaded image paths in DB are all random .webp names');
$bigTmp = tempnam(sys_get_temp_dir(), 'big'); file_put_contents($bigTmp, str_repeat('A', 3 * 1024 * 1024));
$up->req('POST', '/admin/products/create', ['_csrf' => $tk, 'name' => 'Upload big', 'category_id' => '1', 'regular_price' => '100', 'stock_quantity' => '1', 'main_image' => new CURLFile($bigTmp, 'image/jpeg', 'big.jpg')]);
t(!q("SELECT COUNT(*) FROM products WHERE name = 'Upload big'"), 'file over the 2 MB limit refused');
$traversal = $up->req('POST', '/admin/products/create', ['_csrf' => $tk, 'name' => 'Upload traversal', 'category_id' => '1', 'regular_price' => '100', 'stock_quantity' => '1',
    'main_image' => new CURLFile(FX . '/valid.jpg', 'image/jpeg', '../../../index.php.jpg')]);
t(!is_file(PUB . '/../index.php.jpg') && !array_filter(glob(PUB . '/uploads/products/*'), fn ($p) => str_contains($p, 'index')), 'client file name never used (no path traversal)');
foreach ($pdo->query("SELECT pi.image_path FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.name LIKE 'Upload %'")->fetchAll(PDO::FETCH_COLUMN) as $p) {
    @unlink(PUB . '/' . $p); @unlink(PUB . '/' . preg_replace('/\.webp$/', '-sm.webp', $p));
}
$pdo->exec("DELETE FROM products WHERE name LIKE 'Upload %'");

echo "== Configuration exposure (dev server)\n";
$anon = new Client();
foreach (['/.env', '/../.env', '/%2e%2e/.env', '/app/config/database.php', '/storage/logs', '/database/schema.sql', '/tools/hash-password.php', '/.user.ini'] as $p) {
    $r = $anon->get($p); t(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'DB_PASSWORD') && !str_contains($r['body'], '1234'), "not downloadable: $p ({$r['code']})");
}
$h = $anon->get('/health');
t(!preg_match('/1234|root|aalto_mart_dev|127\.0\.0\.1|password/i', $h['body']), '/health reveals no credentials or host details');
t(!preg_match('/^x-powered-by/mi', $anon->get('/')['head']), 'no X-Powered-By');

echo "\nTOTAL: {$pass} passed, {$fail} failed\n";
