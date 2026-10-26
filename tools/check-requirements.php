<?php

declare(strict_types=1);

/**
 * Production pre-flight check. Run on the server after uploading and
 * configuring .env (cPanel -> Terminal, or SSH):
 *
 *     php tools/check-requirements.php
 *
 * On cPanel the "php" command may be a different version from the website.
 * If the version below is not the one chosen in MultiPHP Manager, run e.g.
 *     /opt/cpanel/ea-php83/root/usr/bin/php tools/check-requirements.php
 *
 * Prints PASS / WARN / FAIL per check; exits with 1 if anything FAILs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$fails = 0;
$warns = 0;
$line = static function (string $status, string $text) use (&$fails, &$warns): void {
    $status === 'FAIL' && $fails++;
    $status === 'WARN' && $warns++;
    echo str_pad($status, 5) . ' ' . $text . PHP_EOL;
};
$check = static fn (bool $ok, string $text, string $else = 'FAIL') => $line($ok ? 'PASS' : $else, $text);

echo "== PHP\n";
$check(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION . ' (needs 8.2+, 8.3 recommended)');
foreach (['pdo_mysql', 'mbstring', 'gd', 'fileinfo', 'ctype', 'filter', 'json', 'session', 'hash'] as $ext) {
    $check(extension_loaded($ext), "extension {$ext}");
}
foreach (['exif' => 'photo rotation from phone cameras', 'iconv' => 'accented letters in slugs', 'curl' => 'Steadfast courier API'] as $ext => $why) {
    $check(extension_loaded($ext), "extension {$ext} (optional: {$why})", 'WARN');
}
if (function_exists('gd_info')) {
    $gd = gd_info();
    $check(!empty($gd['JPEG Support']) && !empty($gd['PNG Support']), 'GD reads JPEG and PNG');
    $check(!empty($gd['WebP Support']), 'GD WebP support (without it uploads are stored as JPG/PNG)', 'WARN');
}
$check(in_array('xxh128', hash_algos(), true), 'hash xxh128 (page ETags)');

echo "== Files and folders\n";
foreach (['.htaccess' => 'root .htaccess (needed when the domain points at the project root)',
          'public/.htaccess' => 'clean URLs, caching, compression', 'public/.user.ini' => 'hide PHP errors (PHP-FPM)',
          'public/uploads/.htaccess' => 'no script execution in uploads', 'app/.htaccess' => 'deny web access',
          'database/.htaccess' => 'deny web access', 'storage/.htaccess' => 'deny web access', 'tools/.htaccess' => 'deny web access'] as $file => $why) {
    $check(is_file("{$root}/{$file}"), "{$file} present: {$why}");
}
foreach (['storage/logs', 'public/uploads', 'public/uploads/products', 'public/uploads/categories'] as $dir) {
    if (!is_dir("{$root}/{$dir}")) {
        @mkdir("{$root}/{$dir}", 0755, true);
    }
    $check(is_dir("{$root}/{$dir}") && is_writable("{$root}/{$dir}"), "{$dir} is writable by PHP");
}

echo "== .env\n";
$envFile = "{$root}/.env";
if (!is_file($envFile)) {
    $line('FAIL', '.env not found (copy .env.example to .env and fill it in)');
} else {
    if (DIRECTORY_SEPARATOR === '/') {
        $perms = fileperms($envFile) & 0777;
        $check(($perms & 0007) === 0, sprintf('.env permissions %o (other users must have no access: use 600 or 640)', $perms), 'WARN');
    }
    require $root . '/app/bootstrap.php';

    $env = (string) config('app.env');
    $url = (string) config('app.url');
    $check($env === 'production', "APP_ENV={$env} (must be production)");
    $check(!filter_var(App\Core\Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL) || $env === 'production', 'APP_DEBUG off (ignored in production anyway)', 'WARN');
    $check(str_starts_with($url, 'https://') && !str_ends_with($url, '/'), "APP_URL={$url} (https://, no trailing slash)");
    $check(!str_contains($url, 'example.com') && !str_contains($url, 'localhost'), 'APP_URL is the real domain');
    $check((string) config('database.username') !== 'root', 'DB_USERNAME is not root');
    $check((string) config('database.password') !== '', 'DB_PASSWORD is set');
    $check(config('app.store.email') !== 'hello@example.com', 'STORE_EMAIL changed from the example', 'WARN');
    $check((string) config('app.store.phone') !== '', 'STORE_PHONE set (shown in the footer and structured data)', 'WARN');

    echo "== Database\n";
    try {
        db_value('SELECT 1');
        $line('PASS', 'connected to ' . config('database.database') . ' on ' . config('database.host'));
        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $missingTables = array_diff(['users', 'categories', 'products', 'product_images', 'orders', 'order_items',
            'login_attempts', 'order_sequences', 'order_status_history'], $tables);
        $check(!$missingTables, 'all 9 tables exist' . ($missingTables ? ' (missing: ' . implode(', ', $missingTables) . ' - import schema.sql)' : ''));
        $check((int) db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1") > 0,
            'an active admin account exists (tools/hash-password.php --create)');

        // The bcrypt hash of the published sample password (database/seed.sql)
        preg_match('/\$2y\$10\$[A-Za-z0-9.\/]{53}/', (string) @file_get_contents($root . '/database/seed.sql'), $m);
        $sampleHash = $m[0] ?? '$2y$10$fKUVmsUtBAe5q6LQ3FyTIuO6.9qKAoNdeX3nRcqwJmK/iWWcwkyNC';
        $check(!db_value('SELECT 1 FROM users WHERE password_hash = ?', [$sampleHash]),
            'no account uses the sample password Admin@12345');
        $check(!db_value("SELECT 1 FROM users WHERE email = 'admin@example.com'"), 'sample admin@example.com account removed/renamed', 'WARN');
        $check(!db_value("SELECT 1 FROM products WHERE sku LIKE 'SMP-%'"), 'no sample (SMP-) products', 'WARN');

        $grants = implode(' ', db()->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
        $check(!preg_match('/ALL PRIVILEGES|\bDROP\b|\bALTER\b|\bSUPER\b|\bFILE\b/i', $grants),
            'database user limited to SELECT, INSERT, UPDATE, DELETE', 'WARN');
    } catch (Throwable $e) {
        $line('FAIL', 'database connection: ' . $e->getMessage());
    }
}

echo PHP_EOL . ($fails ? "{$fails} FAIL, {$warns} WARN - fix the FAIL items before going live." : "No failures ({$warns} warnings).") . PHP_EOL;
echo "Also check in a browser: see DEPLOYMENT.md, section 13 (final testing).\n";
exit($fails ? 1 : 0);
