<?php
/**
 * Phase 8 - admin order management test suite.
 * Needs the dev server on :8080 and a freshly seeded aalto_mart_dev.
 */
declare(strict_types=1);

const B = 'http://localhost:8080';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$ADMIN = __DIR__ . '/adminjar.txt'; @unlink($ADMIN);
const STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
const ALLOWED = [
    'pending'    => ['confirmed', 'processing', 'shipped', 'delivered', 'cancelled'],
    'confirmed'  => ['processing', 'shipped', 'delivered', 'cancelled'],
    'processing' => ['shipped', 'delivered', 'cancelled'],
    'shipped'    => ['delivered', 'cancelled'],
    'delivered'  => [],
    'cancelled'  => [],
];

function t(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
function req(string $path, array|string|null $post = null, ?string $jar = null): array {
    global $ADMIN; $jar ??= $ADMIN;
    $ch = curl_init(B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'OrdersTest']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post)); }
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return ['code' => $info['http_code'], 'body' => substr($raw, $info['header_size']), 'location' => $info['redirect_url']];
}
function field(string $html, string $name): string { preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m); return html_entity_decode($m[1] ?? ''); }
function val(string $sql): mixed { global $pdo; return $pdo->query($sql)->fetchColumn(); }
function stock(int $id): int { return (int) val("SELECT stock_quantity FROM products WHERE id = $id"); }
function flashText(string $html): string { preg_match_all('/<div class="alert[^"]*"[^>]*>(.*?)<button/s', $html, $m); return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode(' ', $m[1] ?? []))))); }
function text(string $html): string { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</dt>', '</dd>', '</td>', '</th>'], [' </dt>', ' </dd>', ' </td>', ' </th>'], $html))))); }

/** Place a real order through the storefront checkout. Returns the order id. */
function checkout(array $items, string $name = 'Rahim Uddin', string $mobile = '01712345678', string $address = "House 12, Road 5\nDhanmondi, Dhaka"): int {
    $jar = __DIR__ . '/shopper.txt'; @unlink($jar);
    foreach ($items as [$pid, $qty]) {
        req('/cart/add', ['_csrf' => field(req('/shop', null, $jar)['body'], '_csrf'), 'product_id' => $pid, 'quantity' => $qty], $jar);
    }
    $h = req('/checkout', null, $jar)['body'];
    $r = req('/checkout', ['_csrf' => field($h, '_csrf'), 'cart_fingerprint' => field($h, 'cart_fingerprint'), 'payment_method' => 'cod', 'district_id' => '1', 'thana_id' => '14',
                           'name' => $name, 'mobile' => $mobile, 'address' => $address], $jar);
    @unlink($jar);
    if ($r['location'] !== B . '/order-success') { throw new RuntimeException('checkout failed: ' . $r['location']); }
    return (int) val('SELECT MAX(id) FROM orders');
}
/** Quick order straight in the DB (for the transition matrix). */
function quickOrder(string $status, string $name = 'Matrix Test'): int {
    global $pdo;
    static $n = 0; $n++;
    $pdo->exec("INSERT INTO orders (order_number, status, customer_name, customer_phone, shipping_address, subtotal, grand_total, placed_at)
                VALUES ('QA-M-$n', '$status', '$name', '01800000000', 'Road 1, Dhaka', 100, 100, NOW())");
    $id = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO order_items (order_id, product_id, product_name, product_sku, unit_price, quantity, line_total) VALUES ($id, NULL, 'Gone', 'X', 100, 1, 100)");
    return $id;
}
/** Change status through the admin UI form. */
function setStatus(int $id, string $to, ?string $expected = null, string $note = '', array $extra = []): array {
    $h = req("/admin/orders/view/$id")['body'];
    $post = ['_csrf' => field($h, '_csrf'), 'current_status' => $expected ?? field($h, 'current_status'), 'status' => $to, 'note' => $note] + $extra;
    $r = req("/admin/orders/status/$id", $extra['__raw'] ?? $post);
    $r['flash'] = flashText(req("/admin/orders/view/$id")['body']);
    return $r;
}
function status(int $id): string { return (string) val("SELECT status FROM orders WHERE id = $id"); }
function histCount(int $id): int { return (int) val("SELECT COUNT(*) FROM order_status_history WHERE order_id = $id"); }

echo "== Access control\n";
$signedOut = __DIR__ . '/anon.txt'; @unlink($signedOut);
$oid = checkout([[1, 1]]);
foreach (['/admin/orders', '/admin/orders/view', "/admin/orders/view/$oid", '/admin/dashboard'] as $p) {
    t(req($p, null, $signedOut)['location'] === B . '/admin/login', "GET $p signed out -> login");
}
$r = req("/admin/orders/status/$oid", ['status' => 'confirmed', 'current_status' => 'pending'], $signedOut);
t($r['location'] === B . '/admin/login' && status($oid) === 'pending', 'POST status signed out -> login, unchanged');
@unlink($signedOut);
$lt = field(req('/admin/login')['body'], '_csrf');
req('/admin/login', ['_csrf' => $lt, 'email' => 'admin@example.com', 'password' => 'Admin@12345']);
t(req('/admin/orders')['code'] === 200, 'signed in: order list loads');

echo "== Order list display\n";
$o2 = checkout([[2, 1], [20, 3]], "Mary-Jane O'Brien", '01987654321', "<script>alert(1)</script> Flat 3\nGulshan, Dhaka");
$list = req('/admin/orders')['body'];
$num2 = val("SELECT order_number FROM orders WHERE id = $o2");
t(str_contains($list, $num2) && str_contains($list, 'Mary-Jane O&#039;Brien') && str_contains($list, '01987654321'), 'shows order number, customer name, mobile');
t(str_contains($list, '৳5,819.00') && str_contains($list, '>Pending<') && str_contains($list, date('j M Y')), 'shows total, status and date');
t(strpos($list, $num2) < strpos($list, (string) val("SELECT order_number FROM orders WHERE id = $oid")), 'newest first');
t(!str_contains($list, '<script>alert(1)'), 'customer data escaped in list');

echo "== Search\n";
t(str_contains(req('/admin/orders?q=' . urlencode(substr($num2, -5)))['body'], $num2), 'search by order number (last digits)');
t(str_contains(req('/admin/orders?q=mary-jane')['body'], $num2), 'search by name (case-insensitive)');
foreach (['01987654321', '+880 1987-654321', '8801987654321', '654321'] as $m) {
    $b = req('/admin/orders?q=' . urlencode($m))['body'];
    t(str_contains($b, $num2) && !str_contains($b, (string) val("SELECT order_number FROM orders WHERE id = $oid")), "search by mobile \"$m\"");
}
t(str_contains(req('/admin/orders?q=zzzz')['body'], 'No orders match'), 'no match message');
t(str_contains(req('/admin/orders?q=%25')['body'], 'No orders match'), '"%" is literal');

echo "== Status filter & dates\n";
setStatus($o2, 'confirmed');
$b = req('/admin/orders?status=confirmed')['body'];
t(str_contains($b, $num2) && !str_contains($b, (string) val("SELECT order_number FROM orders WHERE id = $oid")), 'status filter: confirmed');
preg_match('/status-tab--confirmed active"[^>]*>\s*Confirmed <span class="status-tab__count">(\d+)/', $b, $m);
t(($m[1] ?? '') === '1', 'status tab shows count (Confirmed 1)');
t(req('/admin/orders?status=bogus')['code'] === 200, 'unknown status ignored');
$today = date('Y-m-d');
t(str_contains(req("/admin/orders?from=$today&to=$today")['body'], $num2), 'date filter: today includes today\'s orders');
t(str_contains(req('/admin/orders?from=2020-01-01&to=2020-12-31')['body'], 'No orders match'), 'date filter: past range excludes them');
t(str_contains(req("/admin/orders?from=$today&to=2020-01-01")['body'], $num2), 'reversed range is swapped');
$b = req('/admin/orders?from=2026-02-31&to=junk')['body'];
t(str_contains($b, 'was not valid') && str_contains($b, $num2), 'invalid dates ignored with a warning');
$pdo->exec("UPDATE orders SET placed_at = '2026-01-15 10:00:00' WHERE id = $oid");
$b = req('/admin/orders?from=2026-01-15&to=2026-01-15')['body'];
t(str_contains($b, (string) val("SELECT order_number FROM orders WHERE id = $oid")) && !str_contains($b, $num2), 'single day range (inclusive end date)');

echo "== Pagination\n";
for ($i = 0; $i < 20; $i++) { quickOrder('pending', "Page Test $i"); }
$total = (int) val('SELECT COUNT(*) FROM orders');
$p1 = req('/admin/orders')['body']; $p2 = req('/admin/orders?page=2')['body'];
t(substr_count($p1, 'admin/orders/view/') >= 20 && str_contains($p1, "Showing 1–20 of $total"), "page 1: 20 of $total");
t(str_contains($p2, 'Showing 21–' . $total . " of $total"), 'page 2: the rest');
t(str_contains(req('/admin/orders?status=pending&page=2')['body'], 'status=pending'), 'pagination keeps filters');

echo "== Order details display\n";
$d = req("/admin/orders/view/$o2")['body'];
$dt = text($d);
foreach (['order number' => $num2, 'customer name' => "Mary-Jane O'Brien", 'mobile' => '01987654321', 'address' => 'Flat 3 Gulshan, Dhaka',
          'product 1' => 'Sample Smart Watch', 'product 2' => 'Sample Dark Chocolate Bar', 'unit price' => '৳4,999.00', 'quantity 3' => '3 ৳250.00 ৳750.00',
          'subtotal' => 'Subtotal ৳5,749.00', 'grand total' => 'Grand total ৳5,819.00', 'delivery' => 'Delivery ৳70.00', 'thana, district' => 'Dhanmondi, Dhaka', 'zone' => 'Inside Dhaka', 'payment method' => 'Cash on Delivery', 'order date' => date('j M Y'),
          'status' => 'Confirmed', 'history (placed by customer)' => 'by customer'] as $what => $needle) {
    t(str_contains($dt, $needle), "details show $what");
}
t(str_contains($d, 'href="tel:01987654321"'), 'mobile is a tap-to-call link');
t(!str_contains($d, '<script>alert(1)') && str_contains($d, '&lt;script&gt;alert(1)&lt;/script&gt; Flat 3<br />'), 'address escaped (multi-line kept)');
t(req('/admin/orders/view/999999')['location'] === B . '/admin/orders' && req('/admin/orders/view/abc')['location'] === B . '/admin/orders', 'unknown / invalid id -> list');

echo "== Every status transition (6 x 6)\n";
foreach (STATUSES as $from) {
    foreach (STATUSES as $to) {
        $id = quickOrder($from);
        $h0 = histCount($id);
        $r = setStatus($id, $to, $from);
        $allowed = in_array($to, ALLOWED[$from], true);
        if ($allowed) {
            t(status($id) === $to && histCount($id) === $h0 + 1 && str_contains($r['flash'], 'is now'), "$from -> $to: allowed");
        } else {
            t(status($id) === $from && histCount($id) === $h0 && str_contains($r['flash'], 'cannot go from'), "$from -> $to: refused");
        }
    }
}
$id = quickOrder('pending');
foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $step) { setStatus($id, $step); }
t(status($id) === 'delivered' && histCount($id) === 4, 'full path pending -> confirmed -> processing -> shipped -> delivered');
$id = quickOrder('delivered');
$v = req("/admin/orders/view/$id")['body'];
t(str_contains($v, 'can no longer be changed') && !str_contains($v, 'name="status"'), 'final status: no status form shown');

echo "== Side effects\n";
$s1 = stock(1); $s20 = stock(20);
$c = checkout([[1, 2], [20, 5]]);
t(stock(1) === $s1 - 2 && stock(20) === $s20 - 5, 'checkout reduced stock');
setStatus($c, 'confirmed'); setStatus($c, 'shipped');
$r = setStatus($c, 'cancelled', null, 'Customer refused delivery');
t(stock(1) === $s1 && stock(20) === $s20, 'cancelling (from shipped) returns items to stock');
t(str_contains($r['flash'], 'returned to stock'), 'admin is told items were returned');
setStatus($c, 'pending');
t(stock(1) === $s1 && status($c) === 'cancelled', 'cancelled is final: no second restock');
$d = checkout([[2, 1]]);
t(val("SELECT payment_status FROM orders WHERE id = $d") === 'unpaid', 'new COD order is unpaid');
$r = setStatus($d, 'delivered');
t(val("SELECT payment_status FROM orders WHERE id = $d") === 'paid' && str_contains($r['flash'], 'Payment marked as received'), 'delivered COD order -> paid');
$g = checkout([[3, 1]]);
$pdo->exec("UPDATE order_items SET product_id = NULL WHERE order_id = $g");
setStatus($g, 'cancelled');
t(status($g) === 'cancelled', 'cancel works when a product was deleted (nothing to restock)');
$v = text(req("/admin/orders/view/$c")['body']);
t(str_contains($v, 'Customer refused delivery') && str_contains($v, 'by Store Admin'), 'history shows the note and which admin');

echo "== Validation & security\n";
$id = quickOrder('pending');
foreach (['refunded', '', 'DROP TABLE orders', 'Pending', 'confirmed '] as $bad) {
    $r = setStatus($id, $bad);
    t(status($id) === 'pending', 'invalid status "' . $bad . '" refused');
}
$h = req("/admin/orders/view/$id")['body'];
req("/admin/orders/status/$id", '_csrf=' . field($h, '_csrf') . '&current_status=pending&status[]=confirmed');
t(status($id) === 'pending', 'array status refused');
$r = setStatus($id, 'confirmed', null, str_repeat('n', 501));
t(status($id) === 'pending' && str_contains(req("/admin/orders/view/$id")['body'] . $r['flash'], 'check the note'), 'note over 500 characters refused');
$r = setStatus($id, 'confirmed', null, "bad\x07note");
t(status($id) === 'pending', 'note with control characters refused');
$r = setStatus($id, 'confirmed', null, '<b>Called</b> customer');
t(status($id) === 'confirmed' && str_contains(req("/admin/orders/view/$id")['body'], '&lt;b&gt;Called&lt;/b&gt; customer'), 'note saved and escaped');
$h = req("/admin/orders/view/$id")['body'];
req("/admin/orders/status/$id", ['_csrf' => 'forged', 'current_status' => 'confirmed', 'status' => 'processing']);
t(status($id) === 'confirmed', 'forged CSRF token refused');
t(req("/admin/orders/status/$id")['code'] === 405, 'GET on status endpoint -> 405');
// Stale form: the page was loaded while "confirmed", someone else moves it on
$h = req("/admin/orders/view/$id")['body'];
$pdo->exec("UPDATE orders SET status = 'shipped' WHERE id = $id");
req("/admin/orders/status/$id", ['_csrf' => field($h, '_csrf'), 'current_status' => 'confirmed', 'status' => 'cancelled']);
t(status($id) === 'shipped' && str_contains(flashText(req("/admin/orders/view/$id")['body']), 'changed to "Shipped" by someone else'), 'stale form refused (order changed meanwhile)');
t(req('/admin/orders/status/999999', ['_csrf' => field($h, '_csrf'), 'current_status' => 'pending', 'status' => 'confirmed'])['location'] === B . '/admin/orders', 'status change for unknown order -> list');

echo "== Dashboard\n";
$dash = text(req('/admin/dashboard')['body']);
$counts = []; foreach (STATUSES as $s) { $counts[$s] = (int) val("SELECT COUNT(*) FROM orders WHERE status = '$s'"); }
$totalOrders = (int) val('SELECT COUNT(*) FROM orders');
$sales = val("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE status='delivered'");
$open = val("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE status NOT IN ('delivered','cancelled')");
require 'D:/SDL-PROJECT/AALTO-ECOM/app/bootstrap.php';
t(str_contains($dash, 'Total orders ' . number_format($totalOrders)), "total orders = $totalOrders");
foreach (STATUSES as $s) {
    t((bool) preg_match('/' . number_format($counts[$s]) . ' ' . ucfirst($s) . '/', $dash), ucfirst($s) . " = {$counts[$s]}");
}
t(str_contains($dash, 'Total sales ' . format_price($sales)), 'total sales = ' . format_price($sales) . ' (delivered orders)');
t(str_contains($dash, 'Open order value ' . format_price($open)), 'open order value = ' . format_price($open));
t(str_contains(req('/admin/dashboard')['body'], 'admin/orders?status=pending'), 'status cards link to the filtered list');

echo "\nTOTAL: $pass passed, $fail failed\n";
@unlink($ADMIN);
