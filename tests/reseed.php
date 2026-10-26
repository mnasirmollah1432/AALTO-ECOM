<?php
// Reload aalto_mart_dev from database/schema.sql + seed.sql (tests need a fresh store).
$m = new mysqli('127.0.0.1', 'root', '1234');
$m->set_charset('utf8mb4');
$m->query('DROP DATABASE IF EXISTS aalto_mart_dev');
$m->query('CREATE DATABASE aalto_mart_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$m->select_db('aalto_mart_dev');
foreach (['schema.sql', 'seed.sql'] as $file) {
    $sql = file_get_contents('D:/SDL-PROJECT/AALTO-ECOM/database/' . $file);
    if (!$m->multi_query($sql)) {
        exit("$file: " . $m->error . "\n");
    }
    do {
        if ($r = $m->store_result()) { $r->free(); }
        if ($m->errno) { exit("$file: " . $m->error . "\n"); }
    } while ($m->more_results() && $m->next_result());
    if ($m->errno) { exit("$file: " . $m->error . "\n"); }
}
$c = $m->query('SELECT (SELECT COUNT(*) FROM products), (SELECT COUNT(*) FROM districts), (SELECT COUNT(*) FROM thanas)')->fetch_row();
echo "reseeded: products {$c[0]}, districts {$c[1]}, thanas {$c[2]}\n";
// Clear PHP sessions of earlier test runs is not needed: each suite uses its own cookie jar.
