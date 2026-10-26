<?php
// Upgrade check: schema from the previous release (before 009) + seed + an order, then migration 009.
// Usage: php tests/migration_009_check.php [port] [password]   (default MySQL 8 dev: 3306 / 1234)
mysqli_report(MYSQLI_REPORT_OFF);
$port = (int) ($argv[1] ?? 3306);
$pass = $argv[2] ?? '1234';
$zip = 'D:/SDL-PROJECT/aalto-mart-release-20260926-1340.zip';
$old = file_get_contents('phar://' . $zip . '/database/schema.sql');
$root = 'D:/SDL-PROJECT/AALTO-ECOM/database/';
$m = new mysqli('127.0.0.1', 'root', $pass, '', $port);
$m->set_charset('utf8mb4');
echo 'server: ' . $m->server_info . "\n";
$run = function (string $sql, string $label) use ($m): bool {
    if (!$m->multi_query($sql)) { echo "$label: ERROR {$m->error}\n"; return false; }
    do { if ($r = $m->store_result()) { $r->free(); } if ($m->errno) { echo "$label: ERROR {$m->error}\n"; return false; } } while ($m->more_results() && $m->next_result());
    if ($m->errno) { echo "$label: ERROR {$m->error}\n"; return false; }
    echo "$label: ok\n"; return true;
};
$m->query('DROP DATABASE IF EXISTS m9'); $m->query('CREATE DATABASE m9 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $m->select_db('m9');
$run($old, 'old schema (release 1340)');
$run(file_get_contents($root . 'seed.sql'), 'seed');
$m->query("INSERT INTO orders (order_number, status, customer_name, customer_phone, shipping_address, subtotal, shipping_fee, grand_total, placed_at) VALUES ('AM-20261001-00001','pending','Old Order','01700000000','Road 1, Dhaka',100,70,170,NOW())");
$oid = $m->insert_id;
$run(file_get_contents($root . 'migrations/009_courier_steadfast.sql'), 'migration 009');
$cols = $m->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='m9' AND table_name='orders' AND column_name LIKE 'courier%'")->fetch_row()[0];
echo "orders courier columns: $cols (want 7); old order kept: " . $m->query('SELECT COUNT(*) FROM orders')->fetch_row()[0] . "\n";
$a = $m->query("UPDATE orders SET courier='steadfast', courier_consignment_id='111' WHERE id=$oid");
$m->query("INSERT INTO orders (order_number, status, customer_name, customer_phone, shipping_address, subtotal, grand_total, courier, courier_consignment_id) VALUES ('AM-X2','pending','B','01700000001','x',1,1,'steadfast','111')");
echo 'duplicate consignment rejected: ' . ($m->errno ? 'yes' : 'NO') . "\n";
$m->query("INSERT INTO courier_events (order_id, source, consignment_id, status, message) VALUES ($oid, 'webhook', '111', 'delivered', 'ok')");
echo 'courier_events insert: ' . ($m->errno ? 'ERROR ' . $m->error : 'ok') . "\n";
$m->query("DELETE FROM orders WHERE id=$oid");
echo 'events removed with the order: ' . ($m->query('SELECT COUNT(*) FROM courier_events')->fetch_row()[0] === '0' ? 'yes' : 'NO') . "\n";
@$m->multi_query(file_get_contents($root . 'migrations/009_courier_steadfast.sql'));
while ($m->more_results() && @$m->next_result()) {}
echo 'second run: ' . ($m->errno ? 'stops safely (' . substr($m->error, 0, 40) . ')' : 'no error') . "\n";
$m->query('DROP DATABASE m9');
