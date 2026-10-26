<?php
/**
 * Phase 7 - checkout & order creation test suite.
 * Needs the dev server on :8080 and a freshly seeded aalto_mart_dev.
 */
declare(strict_types=1);

const B = 'http://localhost:8080';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$jar = __DIR__ . '/cojar.txt';

function t(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
function fresh(?string $j = null): void { global $jar; @unlink($j ?? $jar); }
function req(string $path, array|string|null $post = null, ?string $j = null): array {
    global $jar; $j ??= $jar;
    $ch = curl_init(B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $j, CURLOPT_COOKIEFILE => $j, CURLOPT_USERAGENT => 'CheckoutTest']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($post) ? $post : http_build_query($post)); }
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return ['code' => $info['http_code'], 'head' => substr($raw, 0, $info['header_size']), 'body' => substr($raw, $info['header_size']), 'location' => $info['redirect_url']];
}
function field(string $html, string $name): string { preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m); return html_entity_decode($m[1] ?? ''); }
function addToCart(int $id, int $qty, ?string $j = null): void { $tok = field(req('/shop', null, $j)['body'], '_csrf'); req('/cart/add', ['_csrf' => $tok, 'product_id' => $id, 'quantity' => $qty], $j); }
/** Load /checkout and return its hidden fields. */
function checkoutForm(?string $j = null): array { $h = req('/checkout', null, $j)['body']; return ['_csrf' => field($h, '_csrf'), 'cart_fingerprint' => field($h, 'cart_fingerprint'), 'html' => $h]; }
function place(array $fields, ?array $form = null, ?string $j = null): array {
    $form ??= checkoutForm($j);
    return req('/checkout', $fields + ['_csrf' => $form['_csrf'], 'cart_fingerprint' => $form['cart_fingerprint'], 'payment_method' => 'cod'], $j);
}
function val(string $sql): mixed { global $pdo; return $pdo->query($sql)->fetchColumn(); }
function stock(int $id): int { return (int) val("SELECT stock_quantity FROM products WHERE id = $id"); }
function orders(): int { return (int) val('SELECT COUNT(*) FROM orders'); }
function items(): int { return (int) val('SELECT COUNT(*) FROM order_items'); }
function seqValue(): int { return (int) val("SELECT COALESCE(MAX(seq_value),0) FROM order_sequences WHERE seq_date = CURDATE()"); }
function cartCount(?string $j = null): int { preg_match('/class="cart-badge" aria-hidden="true">(\d+)</', req('/cart', null, $j)['body'], $m); return (int) ($m[1] ?? 0); }
function flashText(string $html): string { preg_match_all('/<div class="alert[^"]*"[^>]*>(.*?)<button/s', $html, $m); return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode(' ', $m[1] ?? []))))); }

// District/thana from the seeded Bangladesh locations (Dhaka = Inside Dhaka 70, Chattogram = Outside Dhaka 130)
$DHAKA = (int) $pdo->query("SELECT id FROM districts WHERE name='Dhaka'")->fetchColumn();
$DHANMONDI = (int) $pdo->query("SELECT id FROM thanas WHERE name='Dhanmondi' AND district_id=$DHAKA")->fetchColumn();
$CTG = (int) $pdo->query("SELECT id FROM districts WHERE name='Chattogram'")->fetchColumn();
$PANCHLAISH = (int) $pdo->query("SELECT id FROM thanas WHERE name='Panchlaish' AND district_id=$CTG")->fetchColumn();
$GOOD = ['name' => 'Rahim Uddin', 'mobile' => '01712345678', 'address' => "House 12, Road 5\nDhanmondi, Dhaka 1205", 'district_id' => (string) $DHAKA, 'thana_id' => (string) $DHANMONDI];
$EAR = 1; $WATCH = 2; $CHOC = (int) val("SELECT id FROM products WHERE sku='SMP-GRO-005'"); $MUG = (int) val("SELECT id FROM products WHERE sku='SMP-HOM-002'");

echo "== Valid order\n";
fresh();
addToCart($EAR, 2); addToCart($CHOC, 3);
$s0 = [stock($EAR), stock($CHOC)];
$form = checkoutForm();
t(str_contains($form['html'], 'name="name"') && str_contains($form['html'], 'name="mobile"') && str_contains($form['html'], 'name="address"'), 'checkout form has name, mobile, address');
t(!preg_match('/name="(email|password|city|postcode)"/', $form['html']), 'no email/password/other customer fields');
t(str_contains($form['html'], 'Cash on Delivery') && substr_count($form['html'], 'name="payment_method"') === 1, 'only payment option: Cash on Delivery');
t(str_contains($form['html'], 'data-total-out>৳5,748.00') && str_contains($form['html'], '>Select district<'), 'checkout shows ৳5,748.00 before a district is chosen');
t(str_contains($form['html'], 'name="district_id"') && str_contains($form['html'], 'name="thana_id"') && str_contains($form['html'], 'data-charge="70.00"') && str_contains($form['html'], 'data-charge="130.00"'), 'district and thana dropdowns with zone charges (70 / 130)');
t(substr_count($form['html'], '<optgroup') === 64 && str_contains($form['html'], '>Dhanmondi</option>'), 'thanas grouped by district (64 districts)');
$r = place(['name' => '  Rahim   Uddin ', 'mobile' => '+880 1712-345678', 'address' => $GOOD['address']] + $GOOD, $form);
t($r['code'] === 302 && $r['location'] === B . '/order-success', 'place order -> /order-success');
$o = $pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
t((bool) preg_match('/^AM-' . date('Ymd') . '-\d{5}$/', $o['order_number']), "order number format ({$o['order_number']})");
t($o['customer_name'] === 'Rahim Uddin' && $o['customer_phone'] === '01712345678', 'name trimmed/collapsed, mobile normalised to 01712345678');
t($o['shipping_address'] === "House 12, Road 5\nDhanmondi, Dhaka 1205" && $o['customer_email'] === null, 'address kept (2 lines), no email stored');
t([$o['subtotal'], $o['shipping_fee'], $o['grand_total']] === ['5748.00', '70.00', '5818.00'], 'totals server-side: 5748.00 + Inside Dhaka 70.00 = 5818.00');
t([(int) $o['district_id'], (int) $o['thana_id'], $o['shipping_district'], $o['shipping_thana'], $o['delivery_zone']] === [$DHAKA, $DHANMONDI, 'Dhaka', 'Dhanmondi', 'Inside Dhaka'], 'order stores district, thana and zone (ids + names)');
t([$o['status'], $o['payment_status'], $o['payment_method'], $o['currency']] === ['pending', 'unpaid', 'cod', 'BDT'], 'status pending, unpaid, cod, BDT');
$lines = $pdo->query("SELECT product_id, product_name, product_sku, unit_price, quantity, line_total FROM order_items WHERE order_id = {$o['id']} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
t(count($lines) === 2 && $lines[0]['unit_price'] === '2499.00' && $lines[0]['line_total'] === '4998.00' && $lines[1]['line_total'] === '750.00', 'order items: DB prices and line totals');
t($lines[0]['product_name'] === 'Sample Wireless Earbuds' && $lines[0]['product_sku'] === 'SMP-ELE-001', 'order items keep name + SKU snapshot');
t([stock($EAR), stock($CHOC)] === [$s0[0] - 2, $s0[1] - 3], 'stock reduced (' . implode('/', $s0) . ' -> ' . stock($EAR) . '/' . stock($CHOC) . ')');
t(cartCount() === 0 && str_contains(req('/cart')['body'], 'Your cart is empty'), 'cart cleared');
$s = req('/order-success');
foreach (['order number' => $o['order_number'], 'name' => 'Rahim Uddin', 'mobile' => '01712345678', 'address line 1' => 'House 12, Road 5', 'address line 2' => 'Dhanmondi, Dhaka 1205',
          'product' => 'Sample Wireless Earbuds', 'quantity' => '<td class="text-center">2</td>', 'unit price' => '৳2,499.00', 'line total' => '৳4,998.00', 'grand total' => '৳5,818.00', 'thana, district' => 'Dhanmondi, Dhaka', 'delivery' => '৳70.00',
          'payment method' => 'Cash on Delivery', 'status' => '>Pending<'] as $what => $needle) {
    t(str_contains($s['body'], $needle), "confirmation shows $what");
}
t(str_contains($s['head'], 'Cache-Control: no-store') && str_contains($s['body'], 'noindex, nofollow'), 'confirmation: no-store + noindex (personal data)');
t(str_contains(req('/order-success')['body'], $o['order_number']), 'refreshing the confirmation still works');
$before = orders();
$r = place($GOOD, $form);   // same form again (double submit / back button)
t(orders() === $before && $r['location'] === B . '/order-success', 'second submit with empty cart creates no duplicate order');
$other = __DIR__ . '/other.txt'; fresh($other);
t(req('/order-success', null, $other)['location'] === B . '/', 'another browser cannot see the confirmation');
fresh($other);

echo "== Empty cart\n";
fresh();
$r = req('/checkout');
t($r['code'] === 302 && $r['location'] === B . '/cart', 'GET /checkout with empty cart -> /cart');
t(str_contains(req('/cart')['body'], 'Your cart is empty. Add some products'), 'explains why');
$tok = field(req('/shop')['body'], '_csrf');
$before = orders();
$r = req('/checkout', ['_csrf' => $tok] + $GOOD + ['payment_method' => 'cod', 'cart_fingerprint' => 'x']);
t(orders() === $before && $r['location'] === B . '/cart', 'POST /checkout with empty cart creates nothing');

echo "== Field validation (nothing saved, cart kept)\n";
fresh(); addToCart($WATCH, 1);
$st = stock($WATCH); $before = orders();
$cases = [
    'mobile' => [['abc', 'valid Bangladeshi mobile'], ['0171234567', 'valid Bangladeshi'], ['017123456789', 'valid Bangladeshi'], ['01212345678', 'valid Bangladeshi'],
                 ['+15551234567', 'valid Bangladeshi'], ['0171234567a', 'valid Bangladeshi'], ['', 'Enter your mobile number'], ['   ', 'Enter your mobile number']],
    'name'   => [['', 'Enter your name'], ['   ', 'Enter your name'], ['A', 'must be 2 to 100'], [str_repeat('a', 101), 'must be 2 to 100'],
                 ['Rahim 007', 'Use letters only'], ['<script>x</script>', 'Use letters only'], ['Rahim@home', 'Use letters only']],
    'address'=> [['', 'Enter your delivery address'], ["  \n  ", 'Enter your delivery address'], ['Ab 1', 'house, road and area'], [str_repeat('a', 501), '500 characters or fewer'],
                 ['1234567890 12', 'real address']],
];
$cases['district_id'] = [['', 'Choose your district'], ['99999', 'Choose your district'], ['abc', 'Choose your district']];
$cases['thana_id'] = [['', 'Choose your thana'], [(string) $PANCHLAISH, 'Choose your thana'], ['99999', 'Choose your thana']];
foreach ($cases as $field => $list) {
    foreach ($list as [$value, $msg]) {
        $r = place([$field => $value] + $GOOD);
        $page = req('/checkout')['body'];
        $shown = (string) preg_replace('/\s+/', ' ', $value);
        t($r['location'] === B . '/checkout' && str_contains($page, $msg) && str_contains($page, "id=\"$field-error\""), "$field \"" . mb_strimwidth($shown, 0, 20, '…') . "\" rejected: $msg");
    }
}
t(orders() === $before && stock($WATCH) === $st && cartCount() === 1, 'no order created, stock and cart untouched');
place(['mobile' => 'bad'] + $GOOD);
$page = req('/checkout')['body'];
t(str_contains($page, 'value="Rahim Uddin"') && str_contains($page, 'Dhanmondi, Dhaka 1205</textarea>'), 'typed name/address kept after an error');
t((bool) preg_match('/<option value="' . $DHAKA . '"[^>]*selected>Dhaka</', $page) && (bool) preg_match('/<option value="' . $DHANMONDI . '" selected>Dhanmondi</', $page), 'chosen district and thana kept after an error');
t(str_contains($page, '>৳70.00</dd>') && str_contains($page, 'data-total-out>৳5,069.00'), 'kept district shows its delivery (৳70) and total');
require 'D:/SDL-PROJECT/AALTO-ECOM/app/bootstrap.php';
foreach ([['01712345678', '01712345678'], ['+8801712345678', '01712345678'], ['8801712345678', '01712345678'], ['01712-345678', '01712345678'],
          ['(017) 1234 5678', '01712345678'], ['019 1234 5678', '01912345678'], ['01312345678', '01312345678']] as [$in, $want]) {
    [$c, $e] = App\Models\Order::validateCustomer(['mobile' => $in] + $GOOD);
    t(!isset($e['mobile']) && $c['mobile'] === $want, "mobile \"$in\" accepted as $want");
}
[$c, $e] = App\Models\Order::validateCustomer(['name' => "মোঃ করিম", 'address' => 'বাড়ি ১২, রোড ৫, ধানমন্ডি, ঢাকা'] + $GOOD);
t(!$e, 'Bangla name and address accepted');
[$c, $e] = App\Models\Order::validateCustomer(['name' => "Mary-Jane O'Brien Jr."] + $GOOD);
t(!$e && $c['name'] === "Mary-Jane O'Brien Jr.", "name with - ' . accepted");
$r = place(['payment_method' => 'card'] + $GOOD);
t(orders() === $before && str_contains(req('/checkout')['body'], 'choose a payment method'), 'payment method other than cod rejected');
$r = req('/checkout', ['_csrf' => 'forged'] + $GOOD + ['payment_method' => 'cod', 'cart_fingerprint' => checkoutForm()['cart_fingerprint']]);
t(orders() === $before && str_contains(flashText(req('/checkout')['body']), 'session expired'), 'forged CSRF token rejected');
$raw = '_csrf=' . checkoutForm()['_csrf'] . '&payment_method=cod&cart_fingerprint=x&name[]=a&mobile=01712345678&address=' . urlencode($GOOD['address']);
req('/checkout', $raw);
t(orders() === $before, 'array field value rejected');

echo "== Insufficient stock\n";
fresh(); addToCart($MUG, 5);
$form = checkoutForm();
$pdo->exec("UPDATE products SET stock_quantity = 3 WHERE id = $MUG");
$before = [orders(), items(), seqValue()];
$r = place($GOOD, $form);
$page = req('/checkout')['body'];
t($r['location'] === B . '/checkout' && str_contains(flashText($page), 'Only 3 of "Sample Ceramic Mug Set" left in stock (you asked for 5)'), 'order refused: only 3 left (asked for 5)');
t([orders(), items(), seqValue()] === $before && stock($MUG) === 3, 'nothing written; stock still 3; order number not used');
t(str_contains($page, 'Only 3 of &quot;Sample Ceramic Mug Set&quot; available') && str_contains($page, '3 × ৳799.00'), 'checkout page now shows the adjusted cart (3) for review');
$r = place($GOOD);
t($r['location'] === B . '/order-success' && stock($MUG) === 0, 'placing again buys the last 3 -> stock 0 (never negative)');
fresh(); addToCart($MUG, 1);
t(cartCount() === 0, 'sold-out product cannot be added any more');
$pdo->exec("UPDATE products SET stock_quantity = 45 WHERE id = $MUG");

echo "== Product becoming inactive\n";
fresh(); addToCart($WATCH, 1); addToCart($EAR, 1);
$form = checkoutForm();
$pdo->exec("UPDATE products SET is_active = 0 WHERE id = $WATCH");
$before = [orders(), stock($EAR)];
$r = place($GOOD, $form);
t([orders(), stock($EAR)] === $before && str_contains(flashText(req('/checkout')['body']), '"Sample Smart Watch" is no longer available'), 'deactivated after checkout page loaded -> refused, nothing written');
$pdo->exec("UPDATE products SET is_active = 1 WHERE id = $WATCH");
fresh(); addToCart($CHOC, 1); $form = checkoutForm();
$pdo->exec("UPDATE categories SET is_active = 0 WHERE slug = 'groceries'");
$before = orders(); place($GOOD, $form);
t(orders() === $before, 'category deactivated -> refused');
$pdo->exec("UPDATE categories SET is_active = 1 WHERE slug = 'groceries'");

echo "== Browser can't set price, total, name, stock or status\n";
fresh(); addToCart($WATCH, 1);
$form = checkoutForm();
$pdo->exec("UPDATE products SET regular_price = 5555.00 WHERE id = $WATCH");
$before = orders();
place($GOOD, $form);
t(orders() === $before && str_contains(flashText(req('/checkout')['body']), 'Prices or items in your cart changed'), 'price changed after the page was shown -> asked to review, nothing written');
$form = checkoutForm();
t(str_contains($form['html'], 'data-total-out>৳5,555.00'), 'review page shows the new price');
$pdo->exec("UPDATE products SET regular_price = 4999.00 WHERE id = $WATCH");
fresh(); addToCart($WATCH, 1); $form = checkoutForm();
place($GOOD + ['price' => '1', 'unit_price' => '1', 'total' => '1', 'grand_total' => '1', 'subtotal' => '1', 'product_name' => 'Free stuff',
               'stock' => '999', 'is_active' => '1', 'status' => 'delivered', 'payment_status' => 'paid', 'order_number' => 'HACK-1', 'shipping_fee' => '0'], $form);
$o = $pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$li = $pdo->query("SELECT * FROM order_items WHERE order_id = {$o['id']}")->fetch(PDO::FETCH_ASSOC);
t($o['grand_total'] === '5069.00' && $o['shipping_fee'] === '70.00' && $li['unit_price'] === '4999.00' && $li['product_name'] === 'Sample Smart Watch', 'posted price/total/name ignored (DB values used)');
t($o['status'] === 'pending' && $o['payment_status'] === 'unpaid' && str_starts_with($o['order_number'], 'AM-'), 'posted status/payment_status/order_number ignored');
fresh(); addToCart($WATCH, 1); $form = checkoutForm();
$before = orders(); place($GOOD, ['cart_fingerprint' => str_repeat('0', 64)] + $form);
t(orders() === $before, 'forged cart fingerprint -> refused');
fresh(); addToCart($CHOC, 1); $form = checkoutForm();
t(str_contains($form['html'], 'Inside Dhaka ৳70.00 · Outside Dhaka ৳130.00'), 'checkout lists the zone charges');
place(['district_id' => (string) $CTG, 'thana_id' => (string) $PANCHLAISH, 'delivery_shown' => '130.00'] + $GOOD, $form);
t(val('SELECT grand_total FROM orders ORDER BY id DESC LIMIT 1') === '380.00' && val('SELECT shipping_fee FROM orders ORDER BY id DESC LIMIT 1') === '130.00'
  && val('SELECT delivery_zone FROM orders ORDER BY id DESC LIMIT 1') === 'Outside Dhaka', 'Chattogram: Outside Dhaka 130.00, grand_total 380.00');
fresh(); addToCart($CHOC, 1); $form = checkoutForm(); $before = orders();
place(['delivery_shown' => '0.00'] + $GOOD, $form);
t(orders() === $before && str_contains(flashText(req('/checkout')['body']), 'delivery charge to Dhaka is ৳70.00'), 'delivery shown on the page differs from the real charge -> refused, real charge explained');
$pdo->exec("UPDATE delivery_zones SET charge = 95.00 WHERE name = 'Inside Dhaka'");
$form = checkoutForm(); place(['delivery_shown' => '95.00'] + $GOOD, $form);
t(val('SELECT shipping_fee FROM orders ORDER BY id DESC LIMIT 1') === '95.00', 'admin-changed zone charge (95) used for new orders');
$pdo->exec("UPDATE delivery_zones SET charge = 70.00 WHERE name = 'Inside Dhaka'");
$pdo->exec("UPDATE settings SET setting_value = '200.00' WHERE setting_key = 'free_delivery_from'");
fresh(); addToCart($CHOC, 1); $form = checkoutForm();
place($GOOD, $form);
t(val('SELECT shipping_fee FROM orders ORDER BY id DESC LIMIT 1') === '0.00' && val('SELECT grand_total FROM orders ORDER BY id DESC LIMIT 1') === '250.00', 'free delivery from ৳200 (admin setting): shipping 0.00');
$pdo->exec("UPDATE settings SET setting_value = '0.00' WHERE setting_key = 'free_delivery_from'");
$pdo->exec("UPDATE districts SET is_active = 0 WHERE id = $CTG");
fresh(); addToCart($CHOC, 1); $before = orders();
place(['district_id' => (string) $CTG, 'thana_id' => (string) $PANCHLAISH] + $GOOD);
t(orders() === $before && !str_contains(checkoutForm()['html'], '>Chattogram</option>'), 'hidden district: not listed and refused');
$pdo->exec("UPDATE districts SET is_active = 1 WHERE id = $CTG");

echo "== Transaction rollback\n";
// 1) Fail while inserting the 2nd order item (after item 1 AND its stock update were written)
$pdo->exec('DROP TRIGGER IF EXISTS qa_fail_item');
$pdo->exec("CREATE TRIGGER qa_fail_item BEFORE INSERT ON order_items FOR EACH ROW
            BEGIN IF NEW.product_id = $CHOC THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA forced failure'; END IF; END");
fresh(); addToCart($EAR, 2); addToCart($CHOC, 1);   // EAR (id 1) is processed first, CHOC fails
$before = ['orders' => orders(), 'items' => items(), 'seq' => seqValue(), 'ear' => stock($EAR), 'choc' => stock($CHOC)];
$r = place($GOOD);
$after = ['orders' => orders(), 'items' => items(), 'seq' => seqValue(), 'ear' => stock($EAR), 'choc' => stock($CHOC)];
t($r['location'] === B . '/checkout', 'failure mid-order -> back to checkout');
t($after === $before, 'rolled back: no order, no items, order number unused, earlier stock reduction undone (' . json_encode($after) . ')');
t(str_contains(flashText(req('/checkout')['body']), 'could not place your order. Nothing was charged'), 'friendly error, no internals shown');
t(cartCount() === 3, 'cart kept so the shopper can try again');
t(str_contains(file_get_contents('D:/SDL-PROJECT/AALTO-ECOM/storage/logs/app-' . date('Y-m-d') . '.log'), 'QA forced failure'), 'real error logged for the admin');
$pdo->exec('DROP TRIGGER qa_fail_item');
// 2) Fail on the stock update itself
$pdo->exec('DROP TRIGGER IF EXISTS qa_fail_stock');
$pdo->exec("CREATE TRIGGER qa_fail_stock BEFORE UPDATE ON products FOR EACH ROW
            BEGIN IF NEW.id = $CHOC AND NEW.stock_quantity < OLD.stock_quantity THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QA stock failure'; END IF; END");
$before = [orders(), items(), seqValue(), stock($EAR), stock($CHOC)];
place($GOOD);
t([orders(), items(), seqValue(), stock($EAR), stock($CHOC)] === $before, 'failure during stock reduction -> everything rolled back');
$pdo->exec('DROP TRIGGER qa_fail_stock');
$r = place($GOOD);
t($r['location'] === B . '/order-success' && cartCount() === 0, 'after the fault is fixed the same cart orders fine');

echo "== Order number uniqueness\n";
$nums = [];
for ($i = 0; $i < 3; $i++) { fresh(); addToCart($CHOC, 1); place($GOOD); $nums[] = val('SELECT order_number FROM orders ORDER BY id DESC LIMIT 1'); }
$seq = array_map(fn ($n) => (int) substr($n, -5), $nums);
t($seq[1] === $seq[0] + 1 && $seq[2] === $seq[1] + 1, 'sequential numbers: ' . implode(', ', $nums));
$pdo->exec("UPDATE order_sequences SET seq_value = 41 WHERE seq_date = CURDATE()");
fresh(); addToCart($CHOC, 1); place($GOOD);
t(val('SELECT order_number FROM orders ORDER BY id DESC LIMIT 1') === 'AM-' . date('Ymd') . '-00042', 'continues from the stored daily counter (…-00042)');
$pdo->exec("INSERT INTO order_sequences VALUES (CURDATE() - INTERVAL 1 DAY, 900)");
fresh(); addToCart($CHOC, 1); place($GOOD);
t(val('SELECT order_number FROM orders ORDER BY id DESC LIMIT 1') === 'AM-' . date('Ymd') . '-00043', "yesterday's counter does not affect today's");
t((int) val('SELECT COUNT(*) - COUNT(DISTINCT order_number) FROM orders') === 0, 'all order numbers distinct (DB also has a UNIQUE key)');

echo "== Concurrency: 10 shoppers, 5 in stock\n";
$pdo->exec("UPDATE products SET stock_quantity = 5 WHERE id = $WATCH");
$jars = []; $forms = [];
for ($i = 0; $i < 10; $i++) { $jars[$i] = __DIR__ . "/cc$i.txt"; fresh($jars[$i]); addToCart($WATCH, 1, $jars[$i]); $forms[$i] = checkoutForm($jars[$i]); }
$before = orders(); $seqBefore = seqValue();
$mh = curl_multi_init(); $handles = [];
foreach ($jars as $i => $j) {
    $ch = curl_init(B . '/checkout');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $j, CURLOPT_COOKIEFILE => $j, CURLOPT_USERAGENT => 'CheckoutTest',
        CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $forms[$i]['_csrf'], 'cart_fingerprint' => $forms[$i]['cart_fingerprint'], 'payment_method' => 'cod'] + $GOOD)]);
    curl_multi_add_handle($mh, $ch); $handles[$i] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
$ok = 0; foreach ($handles as $ch) { if (curl_getinfo($ch, CURLINFO_REDIRECT_URL) === B . '/order-success') { $ok++; } curl_multi_remove_handle($mh, $ch); }
curl_multi_close($mh);
$new = $pdo->query("SELECT order_number FROM orders ORDER BY id DESC LIMIT " . (orders() - $before))->fetchAll(PDO::FETCH_COLUMN);
t($ok === 5 && orders() - $before === 5, "exactly 5 of 10 simultaneous orders succeeded ($ok)");
t(stock($WATCH) === 0, 'stock ended at 0, never negative (' . stock($WATCH) . ')');
t(count(array_unique($new)) === 5 && seqValue() - $seqBefore === 5, 'the 5 orders got 5 unique, consecutive numbers: ' . implode(', ', array_reverse($new)));
foreach ($jars as $j) { @unlink($j); }
$pdo->exec("UPDATE products SET stock_quantity = 25 WHERE id = $WATCH");

echo "== Escaping on the confirmation page\n";
fresh(); addToCart($CHOC, 1);
place(['name' => "Mary-Jane O'Brien", 'address' => "<script>alert(1)</script> Road 1, Dhaka"] + $GOOD);
$b = req('/order-success')['body'];
t(!str_contains($b, '<script>alert(1)') && str_contains($b, '&lt;script&gt;alert(1)&lt;/script&gt; Road 1') && str_contains($b, 'Mary-Jane O&#039;Brien'), 'name/address HTML-escaped');

echo "\nTOTAL: $pass passed, $fail failed\n";
@unlink($jar);
