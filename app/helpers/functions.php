<?php

declare(strict_types=1);

/**
 * Global helper functions.
 */

use App\Core\Config;

/* ------------------------------------------------------------
 | Config & paths
 * ---------------------------------------------------------- */

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/**
 * Base path of the app on the host, e.g. "" or "/AALTO-ECOM/public".
 * Taken from APP_URL; falls back to the front controller's directory.
 */
function base_path_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }

    $fromUrl = (string) parse_url((string) config('app.url'), PHP_URL_PATH);
    if ($fromUrl !== '') {
        return $prefix = rtrim($fromUrl, '/');
    }

    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return $prefix = rtrim($dir, '/.');
}

/** Current request path relative to the app base, always starting with "/". */
function request_path(): string
{
    $path   = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $prefix = base_path_prefix();

    if ($prefix !== '' && str_starts_with($path, $prefix)) {
        $path = substr($path, strlen($prefix));
    }
    if (str_starts_with($path, '/index.php')) {
        $path = substr($path, strlen('/index.php'));
    }

    return '/' . ltrim($path, '/');
}

function url(string $path = ''): string
{
    $base = config('app.url') ?: base_path_prefix();
    return rtrim((string) $base, '/') . '/' . ltrim($path, '/');
}

/** URL to a file in public/assets with a cache-busting version. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = PUBLIC_PATH . '/assets/' . $path;
    $ver  = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . $path) . $ver;
}

/**
 * Public URL for a stored image path. Paths in the database are relative
 * to /public (e.g. "uploads/products/x.jpg"); empty falls back to a placeholder.
 */
function image_url(?string $path): string
{
    if ($path === null || $path === '') {
        return asset('images/placeholder-product.svg');
    }
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    return url(ltrim($path, '/'));
}

/**
 * <img> for a stored product/category image with srcset when a small
 * variant exists ("-sm" list copy + main image), lazy by default.
 * $attrs are extra attributes; pass loading => 'eager' for the LCP image.
 * The browser picks from srcset; src is the fallback (small unless $fullSrc).
 */
function responsive_img(?string $path, string $alt, string $sizes, array $attrs = [], bool $fullSrc = false): string
{
    $full = image_url($path);
    $thumbPath = App\Core\ImageUpload::thumbPath($path);
    $hasThumb = $path !== null && $thumbPath !== $path;

    $attrs += ['loading' => 'lazy', 'decoding' => 'async'];
    $attrs = ['src' => $hasThumb && !$fullSrc ? image_url($thumbPath) : $full] + $attrs;
    if ($hasThumb) {
        // "w" descriptors must be the real pixel widths (photos are limited on
        // their long side, so a portrait "1200" image is 960 wide)
        $attrs['srcset'] = image_url($thumbPath) . ' ' . image_width($thumbPath, 400) . 'w, '
            . $full . ' ' . image_width((string) $path, 1200) . 'w';
        $attrs['sizes'] = $sizes;
    }
    $attrs['alt'] = $alt;

    $html = '<img';
    foreach ($attrs as $name => $value) {
        if ($value !== null && $value !== false) {
            $html .= ' ' . $name . '="' . e((string) $value) . '"';
        }
    }
    return $html . '>';
}

/**
 * An image slot's standard (app/config/images.php) as JSON for the admin's
 * live preview (data-spec). The server repeats every check on upload.
 */
function image_spec_json(string $type): string
{
    $s = App\Core\ImageUpload::spec($type);
    return (string) json_encode([
        'label'     => $s['label'],
        'width'     => $s['width'],
        'height'    => $s['height'],
        'ratio'     => $s['ratio_value'],
        'ratioLabel' => $s['ratio_label'],
        'minWidth'  => $s['min_width'],
        'minHeight' => $s['min_height'],
        'targetKb'  => $s['target_kb'],
        'maxBytes'  => $s['max_bytes'],
        'maxPixels' => (int) config('images.max_pixels', 16_000_000),
        'tolerance' => $s['tolerance'],
        'fit'       => $s['fit'],
    ], JSON_UNESCAPED_UNICODE);
}

/** "800 × 1000 px · 4:5 · 78 KB · WEBP" for a stored upload, or '' (placeholder / missing file). */
function image_meta_line(?string $path): string
{
    $i = App\Core\ImageUpload::info($path);
    return $i ? sprintf('%d × %d px · %s · %s · %s', $i['width'], $i['height'], $i['ratio'],
        App\Core\ImageUpload::formatBytes($i['bytes']), $i['format']) : '';
}

/** Pixel width of an image under /public (reads the header only), or $fallback. */
function image_width(string $path, int $fallback): int
{
    $size = @getimagesize(PUBLIC_PATH . '/' . ltrim($path, '/'));
    return $size && $size[0] > 0 ? (int) $size[0] : $fallback;
}

/**
 * Decorative storefront icon from the SVG sprite (public/assets/images/icons.svg),
 * e.g. icon('bag', 'me-1'). Accepts "bi-bag" too. Unknown names fall back to "tag".
 * The sprite replaces the Bootstrap Icons web font on the storefront.
 */
function icon(string $name, string $class = ''): string
{
    static $sprite = null, $ids = null;
    if ($ids === null) {
        $sprite = asset('images/icons.svg');
        preg_match_all('/<symbol id="([a-z0-9-]+)"/', (string) @file_get_contents(PUBLIC_PATH . '/assets/images/icons.svg'), $m);
        $ids = array_flip($m[1]);
    }
    $name = str_starts_with($name, 'bi-') ? substr($name, 3) : $name;
    $name = isset($ids[$name]) ? $name : 'tag';

    return '<svg class="bi' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false">'
        . '<use href="' . e($sprite) . '#' . $name . '"></use></svg>';
}

/** Stock label + tone for the storefront: [text, 'in'|'low'|'out']. */
function stock_status(int $quantity): array
{
    return match (true) {
        $quantity <= 0 => ['Out of stock', 'out'],
        $quantity <= App\Models\Product::LOW_STOCK => ["Only {$quantity} left", 'low'],
        default => ['In stock', 'in'],
    };
}

/**
 * Page metadata for the storefront layout (title, description,
 * canonical URL, Open Graph). Views pass overrides as $meta.
 */
function page_meta(array $meta = []): array
{
    $site = (string) config('app.name', 'Aalto Mart');
    $title = $meta['title'] ?? null;

    $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($meta['description']
        ?? $site . ' - quality everyday products at honest prices, delivered to your door.')));

    $image = $meta['image'] ?? null;

    return [
        'site'        => $site,
        // 'full_title' replaces the whole <title> (homepage); 'title' gets " | Site" appended
        'title'       => $meta['full_title'] ?? ($title ? $title . ' | ' . $site : $site . ' - Online Store'),
        'og_title'    => $title ?: $site,
        'description' => str_limit($description, 160),
        // Canonical and og:url must be absolute
        'canonical'   => absolute_url($meta['canonical'] ?? url(request_path() === '/' ? '' : ltrim(request_path(), '/'))),
        'image'       => $image ? absolute_url($image) : absolute_url(asset('images/og-default.png')),
        // Size/alt are only known for the default share image
        'image_size'  => $image ? null : [1200, 630],
        'image_alt'   => $meta['image_alt'] ?? ($title ?: $site),
        'type'        => $meta['type'] ?? 'website',
        'robots'      => $meta['robots'] ?? 'index, follow',
        'json_ld'     => $meta['json_ld'] ?? [],
    ];
}

/**
 * Make an app URL absolute (canonical, OG tags and JSON-LD need absolute URLs).
 * Uses APP_URL when it is absolute (recommended in production), else the request host.
 */
function absolute_url(string $url): string
{
    if (preg_match('#^https?://#', $url)) {
        return $url;
    }
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . ltrim($url, '/');
}

/** schema.org BreadcrumbList from [name, url] pairs (URLs made absolute). */
function breadcrumb_json_ld(array $crumbs): array
{
    $items = [];
    foreach (array_values($crumbs) as $i => [$name, $link]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => absolute_url($link)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** Side cart on this page? Not on the cart, checkout and confirmation pages. */
function cart_drawer_enabled(): bool
{
    $path = request_path();
    foreach (['/cart', '/checkout', '/order-success'] as $p) {
        if ($path === $p || str_starts_with($path, $p . '/')) {
            return false;
        }
    }
    return true;
}

/**
 * Quick-add popup needed on this page? A product card with sizes/colours calls
 * quick_add_used(true); the layout (rendered after the page) then includes it.
 */
function quick_add_used(bool $used = false): bool
{
    static $any = false;
    return $any = $any || $used;
}

/** Units in the visitor's cart, checked against current stock/availability. */
function cart_count(): int
{
    try {
        return App\Models\Cart::count();
    } catch (Throwable) {
        return 0; // never break the header (e.g. on the DB error page)
    }
}

/**
 * Same-site path to send the shopper back to after a cart action.
 * Only app-relative paths are accepted (no scheme, host or "//").
 */
function safe_return_path(mixed $value, string $fallback = 'cart'): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 500) {
        return $fallback;
    }
    if (!preg_match('#^/(?![/\\\\])[A-Za-z0-9/_\-.~%?=&+]*$#', $value) || str_contains($value, '..')) {
        return $fallback;
    }
    return ltrim($value, '/') ?: '/';
}

/** The current app-relative path + query, for "return" fields. */
function current_return_path(): string
{
    $query = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    return request_path() . ($query !== '' ? '?' . $query : '');
}

/**
 * True for a real product/category photo (an upload), false for the bundled
 * placeholder. Search engines and social previews only get real photos.
 */
function is_photo(?string $path): bool
{
    return $path !== null && (str_starts_with($path, 'uploads/') || preg_match('#^https?://#', $path) === 1);
}

/** Small variant of an uploaded image when one exists (lists, cards). */
function image_thumb_url(?string $path): string
{
    return image_url(App\Core\ImageUpload::thumbPath($path));
}

function is_active_path(string $path): bool
{
    $path = '/' . trim($path, '/');
    $current = request_path();
    return $path === '/' ? $current === '/' : str_starts_with($current, $path);
}

/* ------------------------------------------------------------
 | Output & views
 * ---------------------------------------------------------- */

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render a view from app/views, optionally inside a layout.
 * The layout receives the rendered page as $content.
 */
function view(string $template, array $data = [], ?string $layout = null): string
{
    $render = static function (string $__file, array $__data): string {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    };

    $file = APP_PATH . '/views/' . $template . '.php';
    if (!is_file($file)) {
        throw new RuntimeException("View not found: {$template}");
    }

    $content = $render($file, $data);

    if ($layout === null) {
        return $content;
    }

    return $render(APP_PATH . '/views/layouts/' . $layout . '.php', $data + ['content' => $content]);
}

function partial(string $name, array $data = []): void
{
    echo view('partials/' . $name, $data);
}

function abort(int $code = 404, string $message = ''): never
{
    http_response_code($code);
    $template = $code === 404 ? 'errors/404' : 'errors/500';
    echo view($template, [
        'title' => $message ?: null,
        'meta'  => ['title' => $code === 404 ? 'Page not found' : 'Error', 'robots' => 'noindex, follow'],
    ], 'main');
    exit;
}

function redirect(string $path, int $status = 302): never
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $target, true, $status);
    exit;
}

/**
 * Categories for the header menu. Never throws, so the layout can
 * still render (e.g. on the error page) when the database is down.
 */
function nav_categories(): array
{
    static $items = null;
    if ($items === null) {
        try {
            $items = App\Models\Category::allActive();
        } catch (Throwable) {
            $items = [];
        }
    }
    return $items;
}

/* ------------------------------------------------------------
 | Session, flash & CSRF
 * ---------------------------------------------------------- */

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

/**
 * Form input and validation errors kept for exactly one request
 * (Post/Redirect/Get). Snapshotted and cleared at bootstrap.
 */
function form_state(string $bucket): array
{
    static $state = null;
    if ($state === null) {
        $state = [
            'old'    => $_SESSION['_old'] ?? [],
            'errors' => $_SESSION['_errors'] ?? [],
        ];
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }
    return $state[$bucket] ?? [];
}

/** Keep input (never passwords) and errors for the next request. */
function with_input(array $input, array $errors = []): void
{
    $_SESSION['_old'] = $input;
    $_SESSION['_errors'] = $errors;
}

function old(string $key, string $default = ''): string
{
    return (string) (form_state('old')[$key] ?? $default);
}

/** Validation errors from the previous request: field => message. */
function errors(): array
{
    return form_state('errors');
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Issue a new token (after login/logout, so an old one can't be reused). */
function csrf_regenerate(): void
{
    unset($_SESSION['_csrf']);
    csrf_token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** True when the request carries the session's CSRF token. */
function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);
}

/* ------------------------------------------------------------
 | Admin auth
 * ---------------------------------------------------------- */

/** Stop here unless an admin is signed in; remembers the page for after login. */
function require_admin(): array
{
    $user = App\Core\Auth::user();
    if ($user === null) {
        $path = request_path();
        // Only plain admin paths are remembered (the value ends up in a Location header)
        if (preg_match('#^/admin/[A-Za-z0-9/_-]{1,200}$#', $path) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['_admin_intended'] = $path;
        }
        redirect('admin/login');
    }
    return $user;
}

/** Headers for private admin pages: no caching, no framing, no indexing. */
function admin_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Frame-Options: DENY');
    header('Content-Security-Policy: ' . content_security_policy("'none'", false));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
}

/* ------------------------------------------------------------
 | HTTP: HTTPS, security headers, caching
 * ---------------------------------------------------------- */

/** True when the visitor is on HTTPS, including behind a TLS-terminating proxy/CDN. */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
}

/**
 * Content-Security-Policy. Every script, stylesheet and font is served from
 * this site (no CDNs, no inline scripts); JSON-LD blocks are data, not scripts.
 * Inline style attributes are allowed (progress bars, a few table widths).
 * When a Facebook Pixel / Google Tag Manager ID is set (Admin -> Marketing &
 * tracking), the storefront also allows those services' hosts; admin pages never do.
 */
function content_security_policy(string $frameAncestors = "'self'", bool $tracking = true): string
{
    $extra = ['script' => [], 'img' => [], 'connect' => [], 'frame' => [], 'form' => []];
    if ($tracking) {
        try {
            $extra = App\Models\Tracking::cspSources();
        } catch (Throwable) {
            // settings unavailable (e.g. database down): strict policy
        }
    }
    $list = static fn (string $base, array $hosts): string => trim($base . ' ' . implode(' ', $hosts));

    return implode('; ', array_filter([
        "default-src 'self'",
        $list("script-src 'self'", $extra['script']),
        "style-src 'self' 'unsafe-inline'",
        $list("img-src 'self' data: blob:", $extra['img']), // blob: = local preview of a file chosen for upload
        "font-src 'self'",
        $list("connect-src 'self'", $extra['connect']),
        $extra['frame'] ? $list('frame-src', $extra['frame']) : null,
        "object-src 'none'",
        "base-uri 'self'",
        $list("form-action 'self'", $extra['form']), // the Meta Pixel can post through a hidden form
        "frame-ancestors {$frameAncestors}",
        is_https() ? 'upgrade-insecure-requests' : null,
    ]));
}

/** Security headers for every web response (admin pages tighten framing further). */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Content-Security-Policy: ' . content_security_policy());
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/**
 * Default caching for dynamic pages. Catalogue pages may be kept by the
 * browser but must be revalidated (see send_body()); pages with personal
 * data (cart, checkout, order confirmation) are never stored.
 */
function send_cache_headers(string $path): void
{
    if (headers_sent()) {
        return;
    }
    $private = preg_match('#^/(cart|checkout|order-success)(/|$)#', $path) === 1;
    header('Cache-Control: ' . ($private ? 'no-store, private' : 'private, no-cache'));
}

/**
 * Send a rendered response. Successful GET pages get an ETag; a browser
 * revalidating with a matching If-None-Match receives an empty 304.
 */
function send_body(string $body): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $cacheable = in_array($method, ['GET', 'HEAD'], true)
        && http_response_code() === 200
        && !headers_sent()
        && !preg_grep('/^(Location|Cache-Control:.*no-store)/i', headers_list());

    if ($cacheable && $body !== '') {
        $etag = '"' . hash('xxh128', $body) . '"';
        header('ETag: ' . $etag);
        // Apache mod_deflate may append "-gzip" to the tag it sends out
        $sent = str_replace(['-gzip', '-br', 'W/'], '', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if (in_array($etag, array_map('trim', explode(',', $sent)), true)) {
            http_response_code(304);
            return;
        }
    }
    echo $body;
}

/** Minimal error page that needs no database, views or session (last resort). */
function fallback_error_page(): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Something went wrong</title></head>'
        . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem;color:#16130f">'
        . '<h1>Something went wrong</h1><p>We could not complete your request. Please try again in a moment.</p>'
        . '<p><a href="' . e(url('')) . '">Back to home</a></p></body></html>';
}

/* ------------------------------------------------------------
 | Formatting & strings
 * ---------------------------------------------------------- */

/** Format a DECIMAL string or int cents for display (no float maths). */
function format_price(string|int|null $amount): string
{
    return App\Core\Money::format($amount);
}

/** "Dhanmondi, Dhaka" from an order's thana/district snapshot ('' for older orders). */
function order_area(array $order): string
{
    return implode(', ', array_filter([(string) ($order['shipping_thana'] ?? ''), (string) ($order['shipping_district'] ?? '')], 'strlen'));
}

/** Free-delivery amount (DECIMAL string) from Admin -> Delivery, or null when delivery is never free. */
function free_delivery_from(): ?string
{
    $amount = App\Models\Setting::freeDeliveryFrom();
    return App\Core\Money::toCents($amount) > 0 ? $amount : null;
}

/** "Inside Dhaka ৳70 · Outside Dhaka ৳130" from the delivery zones, or '' (tables missing). */
function delivery_zone_hint(): string
{
    try {
        $zones = App\Models\Location::zoneCharges();
    } catch (Throwable) {
        return '';
    }
    return implode(' · ', array_map(static fn ($z) => $z['name'] . ' ' . format_price($z['charge']), $zones));
}

/**
 * URL-safe slug: lowercase a-z, 0-9 and single hyphens only.
 * Accented letters are transliterated ("Café" -> "cafe"); anything
 * that can't be (e.g. non-Latin scripts) is dropped, so the result
 * can be empty - callers must supply a fallback.
 */
function slugify(string $text, int $maxLength = 140): string
{
    $text = trim($text);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false) {
            // some iconv builds render accents as separate marks: é -> 'e
            $text = str_replace(["'", '`', '"', '^', '~'], '', $ascii);
        }
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string) $text, '-');
    return rtrim(substr($text, 0, $maxLength), '-');
}

/** Letters, digits and single hyphens; no leading/trailing hyphen. */
function is_valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
}

function str_limit(string $text, int $limit = 100, string $end = '…'): string
{
    return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit)) . $end;
}

/** "24 Sep 2026, 6:57 PM" from a DB datetime; "—" when empty. */
function format_datetime(?string $value, string $format = 'j M Y, g:i A'): string
{
    $ts = $value ? strtotime($value) : false;
    return $ts ? date($format, $ts) : '—';
}

/** URL with a query string, dropping empty values. */
function url_with_query(string $path, array $params): string
{
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return url($path) . ($params ? '?' . http_build_query($params) : '');
}

/* ------------------------------------------------------------
 | Logging
 * ---------------------------------------------------------- */

function log_message(string $level, string $message): void
{
    // One entry per line: user-supplied text must not be able to forge extra log lines
    $message = (string) preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/', ' ', $message);
    $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    @file_put_contents(STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}
