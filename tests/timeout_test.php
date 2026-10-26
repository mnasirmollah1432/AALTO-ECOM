<?php
// CLI test: idle timeout, absolute lifetime, periodic id rotation
ob_start();
require 'D:/SDL-PROJECT/AALTO-ECOM/app/bootstrap.php';

use App\Core\Auth;

$_SERVER['HTTP_USER_AGENT'] = 'CliTest';
$_SERVER['REMOTE_ADDR'] = '127.0.0.9';
ini_set('session.use_cookies', '0');
session_start();

$reset = function () {
    (function () { self::$resolved = false; self::$user = null; })->bindTo(null, Auth::class)();
};
$ok = fn($c, $m) => print(($c ? 'PASS ' : 'FAIL ') . $m . PHP_EOL);

[$status] = Auth::attempt('admin@example.com', 'Admin@12345');
$ok($status === Auth::OK, 'attempt() succeeds');
$ok(!isset($_SESSION['_admin']['password_hash']) && !str_contains(serialize($_SESSION), '$2y$'), 'session holds no password hash');

$reset();
$ok(Auth::check(), 'fresh session is valid');

$idle = config('app.admin.session_idle');
$_SESSION['_admin']['last_activity'] = time() - $idle - 5;
$reset();
$ok(!Auth::check(), "idle > {$idle}s signs out");
$ok(flash('warning') === 'Your session expired. Please sign in again.', 'expiry flash message set');

Auth::attempt('admin@example.com', 'Admin@12345');
$life = config('app.admin.session_lifetime');
$_SESSION['_admin']['login_at'] = time() - $life - 5;
$reset();
$ok(!Auth::check(), "session older than {$life}s signs out even if active");

Auth::attempt('admin@example.com', 'Admin@12345');
$old = session_id();
$_SESSION['_admin']['regenerated_at'] = time() - 1000;
$reset();
$ok(Auth::check() && session_id() !== $old, 'session id rotated after 15 minutes');

db_query('DELETE FROM login_attempts');
fwrite(STDOUT, ob_get_clean());
