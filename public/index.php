<?php

declare(strict_types=1);

/**
 * Aalto Mart - front controller.
 * Every web request (except real static files) enters here.
 */

// PHP built-in dev server: let it serve static assets directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // Hidden files (.htaccess, .user.ini ...) are not served, same as on Apache
    if ($file !== __DIR__ && is_file($file) && !str_starts_with(basename($file), '.')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$query  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
$query  = $query !== '' ? '?' . $query : '';

if ($method === 'GET' || $method === 'HEAD') {
    // App-relative path as sent (still URL-encoded), for redirect targets
    $rawPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $relPath = base_path_prefix() !== '' && str_starts_with($rawPath, base_path_prefix())
        ? substr($rawPath, strlen(base_path_prefix())) : $rawPath;
    $relPath = preg_replace('#^/index\.php(?=/|$)#', '', $relPath);
    $cleanPath = trim($relPath, '/'); // no trailing slash, no /index.php

    // Production: one canonical origin. Redirect http -> https and other
    // hostnames (e.g. www vs bare domain) to the APP_URL scheme and host.
    $canonical = parse_url((string) config('app.url'));
    if (config('app.env') === 'production' && !empty($canonical['host'])) {
        $wantHost  = strtolower($canonical['host'] . (isset($canonical['port']) ? ':' . $canonical['port'] : ''));
        $wantHttps = ($canonical['scheme'] ?? 'http') === 'https';
        if (strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== $wantHost || ($wantHttps && !is_https())) {
            redirect($cleanPath . $query, 301);
        }
    }

    // One URL per page: "/shop/" and "/index.php/shop" -> "/shop"
    if (request_path() !== '/' && (str_ends_with($rawPath, '/') || str_contains($rawPath, '/index.php/'))) {
        redirect($cleanPath . $query, 301);
    }
}

// Defence in depth: everything under /admin needs a signed-in admin, except
// the sign-in page itself (each admin handler also checks for itself)
$path = request_path();
if (($path === '/admin' || str_starts_with($path, '/admin/')) && !in_array($path, ['/admin', '/admin/login'], true)) {
    require_admin();
}

$router = new App\Core\Router();
require APP_PATH . '/routes.php';

ob_start();
$router->dispatch($method, request_path());
send_body((string) ob_get_clean());
