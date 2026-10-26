<?php

declare(strict_types=1);

/**
 * Application bootstrap: paths, autoloading, environment, config,
 * error handling and session. Included by public/index.php and CLI tools.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', BASE_PATH . '/storage');

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Aalto Mart requires PHP 8.2 or newer (8.3+ recommended).');
}

/*
 * Autoloader: App\Core\Router -> app/core/Router.php
 * (namespace folders are lowercase, class file keeps its case)
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $parts = explode('\\', substr($class, 4));
    $name  = array_pop($parts);
    $dir   = $parts ? strtolower(implode('/', $parts)) . '/' : '';
    $file  = APP_PATH . '/' . $dir . $name . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers/functions.php';
require APP_PATH . '/helpers/database.php';

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(APP_PATH . '/config');

date_default_timezone_set((string) config('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

/*
 * Error handling
 */
error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

/** Throw away half-rendered output and mark the response as an uncacheable 500. */
$startErrorPage = static function (): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
    }
};

set_exception_handler(static function (Throwable $e) use ($startErrorPage): void {
    log_message('error', $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e . PHP_EOL);
        exit(1);
    }
    $startErrorPage();
    try {
        echo view('errors/500', [
            'exception' => config('app.debug') ? $e : null,
            'meta'      => ['title' => 'Something went wrong', 'robots' => 'noindex, nofollow'],
        ], 'main');
    } catch (Throwable) {
        echo fallback_error_page(); // e.g. a broken view: still answer with a clean page
    }
});

// Fatal errors (out of memory, time limit, parse errors) skip the exception handler
register_shutdown_function(static function () use ($startErrorPage): void {
    $error = error_get_last();
    if ($error === null || PHP_SAPI === 'cli'
        || !($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) {
        return;
    }
    log_message('error', "Fatal: {$error['message']} in {$error['file']}:{$error['line']}");
    $startErrorPage();
    echo fallback_error_page();
});

/*
 * Session (web requests only)
 */
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    send_security_headers();
    send_cache_headers(request_path());
    // Cache-Control is set above; stop PHP's session module overriding it
    session_cache_limiter('');
    session_name((string) config('app.session.name', 'aalto_session'));
    session_start([
        'cookie_httponly'  => true,
        'cookie_secure'    => (bool) config('app.session.secure', false) || is_https(),
        'cookie_samesite'  => 'Lax',
        'cookie_lifetime'  => 0,
        'use_strict_mode'  => true,
        'use_only_cookies' => true,
        'use_trans_sid'    => false,
        'gc_maxlifetime'   => max(1440, (int) config('app.admin.session_lifetime', 28800)),
    ]);

    // Snapshot one-time form input/errors from the previous request
    form_state('old');
}
