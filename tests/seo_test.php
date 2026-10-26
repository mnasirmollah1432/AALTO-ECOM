<?php
// Phase 10: SEO + performance + technical checks against the running dev server.
const B = 'http://localhost:8080';
const ROOT = 'D:/SDL-PROJECT/AALTO-ECOM';
$pass = 0; $fail = 0;
function t(bool $ok, string $label): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; }
function get(string $path, array $headers = []): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_PATH_AS_IS => true]);
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    $head = substr($raw, 0, $info['header_size']); $body = substr($raw, $info['header_size']);
    $h = [];
    foreach (explode("\r\n", $head) as $line) if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
    return ['code' => $info['http_code'], 'h' => $h, 'body' => $body, 'ms' => (int) round($info['total_time'] * 1000)];
}
function dom(string $html): DOMXPath { $d = new DOMDocument(); @$d->loadHTML('<?xml encoding="utf-8">' . $html); return new DOMXPath($d); }
function attr(DOMXPath $x, string $q, string $a): ?string { $n = $x->query($q)->item(0); return $n ? $n->getAttribute($a) : null; }
function ld(string $path): array { $x = dom(get($path)['body']); $o = [];
    foreach ($x->query('//script[@type="application/ld+json"]') as $s) { $j = json_decode($s->textContent, true); $o[$j['@type']] = $j; } return $o; }

echo "== Sitemap\n";
$sm = get('/sitemap.xml');
t($sm['code'] === 200 && str_starts_with($sm['h']['content-type'] ?? '', 'application/xml'), 'sitemap.xml 200 xml');
$xml = simplexml_load_string($sm['body']);
t($xml !== false, 'sitemap parses as XML');
$locs = []; $lastmods = 0;
foreach ($xml->url as $u) { $locs[] = (string) $u->loc; if ((string) $u->lastmod !== '') $lastmods++; }
t(count($locs) === 3 + 5 + 25, 'sitemap has home, shop, contact, 5 categories, 25 products (' . count($locs) . ')');
t($lastmods === count($locs), 'every sitemap URL has lastmod');
t(str_contains($sm['body'], 'xmlns:image='), 'sitemap declares image namespace');
t(!array_filter($locs, fn ($l) => !str_starts_with($l, B . '/')), 'sitemap URLs absolute on APP_URL');
t(!array_filter($locs, fn ($l) => preg_match('#/(cart|checkout|admin)#', $l)), 'sitemap excludes cart/checkout/admin');
t(($sm['h']['cache-control'] ?? '') === 'public, max-age=3600', 'sitemap cacheable 1h');

echo "== Every indexable page\n";
$titles = []; $descs = [];
foreach (array_merge($locs, [B . '/shop?page=2']) as $url) {
    $r = get($url); $x = dom($r['body']); $path = substr($url, strlen(B)) ?: '/';
    $title = trim($x->query('//title')->item(0)?->textContent ?? '');
    $desc = attr($x, '//meta[@name="description"]', 'content') ?? '';
    $canon = attr($x, '//link[@rel="canonical"]', 'href') ?? '';
    $ok = $r['code'] === 200 && $title !== '' && mb_strlen($title) <= 70 && $desc !== '' && mb_strlen($desc) <= 160
        && $canon === $url && $x->query('//h1')->length === 1
        && attr($x, '//meta[@property="og:url"]', 'content') === $url
        && str_starts_with((string) attr($x, '//meta[@property="og:image"]', 'content'), 'http')
        && attr($x, '//meta[@property="og:title"]', 'content') && attr($x, '//meta[@name="twitter:card"]', 'content')
        && attr($x, '//meta[@name="robots"]', 'content') === 'index, follow'
        && attr($x, '//html', 'lang') === 'en';
    if (!$ok) echo "   -> $path: code {$r['code']} title(" . mb_strlen($title) . ") desc(" . mb_strlen($desc) . ") canon=$canon h1=" . $x->query('//h1')->length . "\n";
    t($ok, "title, description, canonical, OG, one h1: $path");
    $titles[$title][] = $path; $descs[$desc][] = $path;
    foreach ($x->query('//script[@type="application/ld+json"]') as $s) {
        if (json_decode($s->textContent) === null) t(false, "JSON-LD parses on $path");
    }
}
t(!array_filter($titles, fn ($p) => count($p) > 1), 'titles unique across indexable pages');
t(!array_filter($descs, fn ($p) => count($p) > 1), 'meta descriptions unique across indexable pages');
foreach (array_filter($titles, fn ($p) => count($p) > 1) as $tt => $p) echo "   -> dup title '$tt': " . implode(', ', $p) . "\n";
foreach (array_filter($descs, fn ($p) => count($p) > 1) as $tt => $p) echo "   -> dup desc: " . implode(', ', $p) . "\n";
$x = dom(get('/')['body']);
t(trim($x->query('//title')->item(0)->textContent) === 'Aalto Mart - Everyday Style, Thoughtfully Priced', 'home title');
t(attr($x, '//meta[@property="og:image:width"]', 'content') === '1200' && attr($x, '//meta[@property="og:image:height"]', 'content') === '630', 'default OG image has width/height');
t(get(parse_url(attr($x, '//meta[@property="og:image"]', 'content'), PHP_URL_PATH))['code'] === 200, 'OG image served');
t(attr($x, '//link[@rel="apple-touch-icon"]', 'href') !== null, 'apple-touch-icon linked');

echo "== Structured data\n";
$home = ld('/');
t(isset($home['WebSite'], $home['OnlineStore']), 'home: WebSite + OnlineStore JSON-LD');
t(str_starts_with($home['OnlineStore']['logo'] ?? '', B . '/assets/images/logo-512.png?v='), 'home: logo absolute + versioned');
t(get(parse_url($home['OnlineStore']['logo'], PHP_URL_PATH))['code'] === 200, 'home: logo file served');
$p = ld('/product/sample-wireless-earbuds');
$prod = $p['Product'] ?? [];
t(($prod['offers']['price'] ?? '') === '2499.00' && ($prod['offers']['priceCurrency'] ?? '') === 'BDT', 'product: offer price/currency from DB');
t(($prod['offers']['priceSpecification']['priceType'] ?? '') === 'https://schema.org/StrikethroughPrice' && ($prod['offers']['priceSpecification']['price'] ?? '') === '2999.00', 'product on sale: strikethrough regular price');
t(($prod['offers']['availability'] ?? '') === 'https://schema.org/InStock', 'product: availability');
t(($prod['offers']['shippingDetails']['shippingRate']['value'] ?? '') === '70.00', 'product shipping rate: cheapest zone charge (70.00, no free delivery set)');
t(($prod['offers']['hasMerchantReturnPolicy']['merchantReturnDays'] ?? 0) === 7, 'product: 7-day return policy');
t(!array_key_exists('image', $prod), 'product without photos: image omitted (not [])');
t(($prod['url'] ?? '') === B . '/product/sample-wireless-earbuds', 'product: url');
t(count($p['BreadcrumbList']['itemListElement'] ?? []) === 3 && $p['BreadcrumbList']['itemListElement'][1]['item'] === B . '/category/electronics', 'product: breadcrumb Home > Category > Product');
$cheapSlug = null;
foreach ($locs as $l) { if (str_contains($l, '/product/')) { $o = ld(substr($l, strlen(B)))['Product']['offers'] ?? []; if ((float) $o['price'] < 2000 && !isset($o['priceSpecification'])) { $cheapSlug = $l; break; } } }
t($cheapSlug !== null && (ld(substr($cheapSlug, strlen(B)))['Product']['offers']['shippingDetails']['shippingRate']['value'] ?? '') === '70.00', 'product not on sale: shipping 70.00, no strikethrough');
$cat = ld('/category/electronics');
t(array_column($cat['BreadcrumbList']['itemListElement'] ?? [], 'name') === ['Home', 'Shop', 'Electronics'], 'category: breadcrumb Home > Shop > Electronics');
t(array_column(ld('/shop')['BreadcrumbList']['itemListElement'] ?? [], 'name') === ['Home', 'Shop'], 'shop: breadcrumb Home > Shop');
$out = null;
foreach ($locs as $l) { if (str_contains($l, '/product/')) { $o = ld(substr($l, strlen(B)))['Product']['offers'] ?? []; if (($o['availability'] ?? '') === 'https://schema.org/OutOfStock') { $out = $l; break; } } }
t($out !== null, 'out-of-stock product reports OutOfStock');

echo "== Not indexed\n";
foreach (['/cart', '/shop?q=sample', '/shop?sort=price_asc', '/category/electronics?sale=1', '/nope', '/about'] as $path) {
    $x = dom(get($path)['body']);
    t(str_starts_with((string) attr($x, '//meta[@name="robots"]', 'content'), 'noindex'), "noindex: $path");
}
$r = get('/nope'); t($r['code'] === 404 && dom($r['body'])->query('//h1')->length === 1, '404 status with a page');
t(get('/account')['code'] === 404, '/account placeholder removed (no customer accounts)');
$ch = curl_init(B . '/checkout'); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$raw = curl_exec($ch); t(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 405 && preg_match('/^Allow: GET, HEAD, POST/mi', $raw) && str_contains($raw, 'noindex'), '405 with Allow header + noindex');

echo "== robots.txt\n";
$rb = get('/robots.txt');
t($rb['code'] === 200 && str_contains($rb['body'], "Disallow: /admin") && str_contains($rb['body'], 'Sitemap: ' . B . '/sitemap.xml'), 'robots.txt rules + absolute sitemap');
t(!preg_match('#Disallow: /(assets|product|category|shop)\s*$#m', $rb['body']), 'robots.txt does not block catalogue or assets');

echo "== Clean URLs\n";
foreach (['/shop', '/category/electronics', '/product/sample-wireless-earbuds'] as $path) t(get($path)['code'] === 200, "clean URL $path");
foreach (['/shop/' => '/shop', '/category/electronics/' => '/category/electronics', '/index.php/shop' => '/shop', '/product/Sample-Wireless-Earbuds' => '/product/sample-wireless-earbuds', '/shop?category=electronics' => '/category/electronics'] as $from => $to) {
    $r = get($from); t($r['code'] === 301 && ($r['h']['location'] ?? '') === B . $to, "301 $from -> $to");
}
t((get('/shop/?sort=name&page=1')['h']['location'] ?? '') === B . '/shop?sort=name&page=1', 'trailing-slash redirect keeps query string');

echo "== Headers\n";
$r = get('/');
foreach (['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN', 'referrer-policy' => 'strict-origin-when-cross-origin', 'cross-origin-opener-policy' => 'same-origin'] as $k => $v) t(($r['h'][$k] ?? '') === $v, "header $k");
t(str_contains($r['h']['content-security-policy'] ?? '', "script-src 'self'") && str_contains($r['h']['content-security-policy'], "frame-ancestors 'self'"), 'CSP: self-only scripts, framing');
t(isset($r['h']['permissions-policy']), 'Permissions-Policy');
t(!isset($r['h']['x-powered-by']), 'no X-Powered-By');
t(!isset($r['h']['strict-transport-security']), 'no HSTS over plain http');
t(($r['h']['cache-control'] ?? '') === 'private, no-cache' && isset($r['h']['etag']), 'catalogue page: private, no-cache + ETag');
$https = get('/', ['X-Forwarded-Proto: https']);
t(str_contains($https['h']['strict-transport-security'] ?? '', 'max-age=31536000') && str_contains($https['h']['content-security-policy'] ?? '', 'upgrade-insecure-requests'), 'behind HTTPS proxy: HSTS + upgrade-insecure-requests');
t(str_contains(strtolower($https['h']['set-cookie'] ?? ''), 'secure'), 'behind HTTPS proxy: session cookie Secure');
t(!str_contains(strtolower($r['h']['set-cookie'] ?? ''), 'secure'), 'plain http: cookie not Secure (still works locally)');
foreach (['/cart', '/checkout', '/order-success'] as $path) { $h = get($path)['h']; t(str_contains($h['cache-control'] ?? '', 'no-store'), "no-store: $path"); }
t((get('/robots.txt')['h']['cache-control'] ?? '') === 'public, max-age=86400', 'robots.txt cacheable');
$a = get('/admin/login')['h'];
t(($a['x-frame-options'] ?? '') === 'DENY' && str_contains($a['content-security-policy'] ?? '', "frame-ancestors 'none'") && str_contains($a['cache-control'] ?? '', 'no-store'), "admin: DENY framing (CSP 'none') + no-store");

echo "== ETag / 304\n";
$jar = tempnam(sys_get_temp_dir(), 'j');
$cg = function (string $path, array $hd = []) use ($jar) { $ch = curl_init(B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $hd]);
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^etag: (.*)$/mi', $raw, $m); return [$code, trim($m[1] ?? ''), strlen($raw) - $hs]; };
$cg('/shop'); [, $etag] = $cg('/shop'); [$code, , $len] = $cg('/shop', ["If-None-Match: $etag"]);
t($etag !== '' && $code === 304 && $len === 0, 'revalidation: 304 without body');
[$code] = $cg('/shop', ['If-None-Match: "stale"']); t($code === 200, 'changed page: 200');
[$code] = $cg('/shop', ['If-None-Match: ' . substr($etag, 0, -1) . '-gzip"']); t($code === 304, 'Apache "-gzip" ETag suffix still matches');

echo "== Assets\n";
foreach (['/', '/shop', '/product/sample-wireless-earbuds', '/cart', '/admin/login'] as $path) { $b = get($path)['body']; t(!preg_match('#https?://(?!localhost)[^"\']*\.(css|js|woff2?)#', $b) && !str_contains($b, 'jsdelivr'), "no third-party CSS/JS: $path"); }
$home = get('/')['body'];
t(!str_contains($home, 'bootstrap-icons') && !str_contains($home, 'class="bi bi-'), 'storefront: no icon font, SVG sprite icons');
preg_match_all('#<(?:link[^>]+href|script[^>]+src)="(' . preg_quote(B, '#') . '/assets/[^"]+\.(?:css|js)\?v=\d+)"#', $home, $m);
$total = 0; $gz = 0;
foreach (array_unique($m[1]) as $u) { $r = get($u); $total += strlen($r['body']); $gz += strlen(gzencode($r['body'], 6)); t($r['code'] === 200, 'asset served: ' . basename(parse_url($u, PHP_URL_PATH))); }
t(count(array_unique($m[1])) === 4, 'home loads exactly 2 CSS + 2 JS files');
$gzHtml = strlen(gzencode($home, 6));
echo "   -> home: HTML " . round(strlen($home) / 1024, 1) . " KB (gzip " . round($gzHtml / 1024, 1) . " KB), CSS+JS " . round($total / 1024) . " KB (gzip " . round($gz / 1024) . " KB)\n";
t($gz + $gzHtml < 90 * 1024, 'home HTML+CSS+JS < 90 KB gzipped');
$sprite = get('/assets/images/icons.svg')['body'];
t(preg_match_all('/<symbol /', $sprite) >= 30, 'icon sprite served');
preg_match_all('/<use href="([^"#]+)#([a-z0-9-]+)"/', $home . get('/cart')['body'] . get('/product/sample-wireless-earbuds')['body'] . get('/nope')['body'], $uses);
$missing = array_unique(array_filter($uses[2], fn ($id) => !str_contains($sprite, "id=\"$id\"")));
t($uses[2] && !$missing, 'every icon used exists in the sprite' . ($missing ? ' (missing: ' . implode(',', $missing) . ')' : ''));
t(get('/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2')['code'] === 200, 'admin icon font self-hosted');

echo "== Images\n";
foreach (['/', '/shop', '/product/sample-wireless-earbuds', '/category/fashion'] as $path) {
    $x = dom(get($path)['body']); $bad = [];
    foreach ($x->query('//img') as $img) {
        if (!$img->hasAttribute('alt') || !$img->getAttribute('width') || !$img->getAttribute('height') || !$img->getAttribute('loading')) $bad[] = $img->getAttribute('src');
    }
    t(!$bad, "images have alt, width, height, loading: $path");
}
$x = dom(get('/shop')['body']); $imgs = $x->query('//div[contains(@class,"product-grid")]//img');
t($imgs->item(0)->getAttribute('loading') === 'eager' && $imgs->item(3)->getAttribute('loading') === 'eager' && $imgs->item(4)->getAttribute('loading') === 'lazy', 'shop: first row eager, rest lazy');
t($imgs->item(0)->getAttribute('alt') !== '', 'product card image alt = product name');
$x = dom(get('/product/sample-wireless-earbuds')['body']);
$main = $x->query('//img[@fetchpriority="high"]');
t($main->length === 1 && $main->item(0)->getAttribute('loading') === 'eager', 'product page: main image eager + fetchpriority high');
t(strlen(file_get_contents(ROOT . '/public/assets/images/og-default.png')) < 40000, 'OG image < 40 KB');

echo "== Leftovers / exposure\n";
foreach (['/', '/product/sample-wireless-earbuds', '/shop', '/cart'] as $path) t(!preg_match('/Notice:|Warning:|Deprecated:|Fatal error|var_dump/i', get($path)['body']), "no PHP notices/debug output: $path");
t(!preg_match('/console\.(log|debug)/', file_get_contents(ROOT . '/public/assets/js/app.js') . file_get_contents(ROOT . '/public/assets/js/admin.js')), 'no console logging in JS');
foreach (['/.env', '/uploads', '/uploads/products', '/assets/vendor'] as $path) { $r = get($path); t($r['code'] === 404 || $r['code'] === 403, "not exposed: $path ({$r['code']})"); }
foreach (['app', 'database', 'storage', 'tools'] as $d) t(str_contains((string) @file_get_contents(ROOT . "/$d/.htaccess"), 'Require all denied'), "$d/.htaccess denies web access");
$ex = file_get_contents(ROOT . '/.env.example');
t(str_contains($ex, 'APP_DEBUG=false') && str_contains($ex, 'APP_ENV=production') && !preg_match('/^DB_USERNAME=root/m', $ex), '.env.example: production-safe defaults, no root DB user');

echo "== Speed\n";
$times = [];
foreach (['/', '/shop', '/category/electronics', '/product/sample-wireless-earbuds', '/sitemap.xml'] as $path) { $best = PHP_INT_MAX; for ($i = 0; $i < 3; $i++) $best = min($best, get($path)['ms']); $times[$path] = $best; }
echo '   -> server time (ms, best of 3): ' . json_encode($times, JSON_UNESCAPED_SLASHES) . "\n";
t(max($times) < 150, 'every page renders < 150 ms on the dev server');

echo "\nTOTAL: {$pass} passed, {$fail} failed\n";
