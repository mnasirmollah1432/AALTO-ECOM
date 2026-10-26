<?php
/**
 * Phase 5 - public storefront test suite.
 * Run: php storefront_test.php   (dev server on localhost:8080)
 */
declare(strict_types=1);

const B = 'http://localhost:8080';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$jar = __DIR__ . '/sjar.txt'; @unlink($jar);

function t(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n";
}
function req(string $path, array $post = null, bool $follow = false, array $headers = []): array {
    global $jar;
    $ch = curl_init(str_starts_with($path, 'http') ? $path : B . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'StorefrontTest',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $head = substr($raw, 0, $info['header_size']);
    return ['code' => $info['http_code'], 'body' => substr($raw, $info['header_size']), 'location' => $info['redirect_url'], 'head' => $head, 'time' => $info['total_time']];
}
function dom(string $html): DOMXPath {
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($d);
}
function q(DOMXPath $x, string $expr): DOMNodeList { return $x->query($expr); }
function attr(DOMXPath $x, string $expr, string $a): ?string { $n = $x->query($expr)->item(0); return $n instanceof DOMElement ? $n->getAttribute($a) : null; }
function text(DOMXPath $x, string $expr): string { $n = $x->query($expr)->item(0); return $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : ''; }
function cardNames(DOMXPath $x): array { return array_map(fn ($n) => trim($n->textContent), iterator_to_array(q($x, '//article[contains(@class,"product-card")]//h3/a'))); }
function csrf(string $html): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m); return $m[1] ?? ''; }
function val(PDO $pdo, string $sql): mixed { return $pdo->query($sql)->fetchColumn(); }

/* Structural checks on every page */
function pageChecks(string $label, string $html, string $expectCanonical, string $robots = 'index, follow'): DOMXPath {
    $x = dom($html);
    t(q($x, '//h1')->length === 1, "$label: exactly one <h1>", (string) q($x, '//h1')->length);
    $title = text($x, '//title');
    t($title !== '' && str_contains($title, 'Aalto Mart'), "$label: <title> ($title)");
    $desc = attr($x, '//meta[@name="description"]', 'content');
    t($desc !== null && strlen($desc) > 40 && mb_strlen($desc) <= 160, "$label: meta description (" . mb_strlen((string) $desc) . ' chars)');
    t(attr($x, '//link[@rel="canonical"]', 'href') === $expectCanonical, "$label: canonical $expectCanonical", (string) attr($x, '//link[@rel="canonical"]', 'href'));
    foreach (['og:title', 'og:description', 'og:url', 'og:image', 'og:type', 'og:site_name'] as $p) {
        $v = attr($x, "//meta[@property='$p']", 'content');
        if ($v === null || $v === '') { t(false, "$label: $p present"); return $x; }
    }
    t(str_starts_with((string) attr($x, '//meta[@property="og:image"]', 'content'), 'http'), "$label: Open Graph tags complete, og:image absolute");
    t(attr($x, '//meta[@name="robots"]', 'content') === $robots, "$label: robots = $robots", (string) attr($x, '//meta[@name="robots"]', 'content'));
    $ids = array_map(fn ($n) => $n->getAttribute('id'), iterator_to_array(q($x, '//*[@id]')));
    $dupes = array_keys(array_filter(array_count_values($ids), fn ($c) => $c > 1));
    t(!$dupes, "$label: no duplicate ids", implode(',', $dupes));
    t(q($x, '//img[not(@alt)]')->length === 0, "$label: every <img> has alt");
    return $x;
}

echo "== Clean URLs & status codes\n";
foreach (['/' => 200, '/shop' => 200, '/category/electronics' => 200, '/product/sample-wireless-earbuds' => 200,
          '/category/does-not-exist' => 404, '/product/does-not-exist' => 404, '/category/%2e%2e%2fadmin' => 404, '/product/%3Cscript%3E' => 404] as $p => $code) {
    t(req($p)['code'] === $code, "GET $p -> $code");
}
t(req('/shop?category=fashion')['location'] === B . '/category/fashion', '/shop?category=fashion 301 -> /category/fashion');
t(req('/category/FASHION')['location'] === B . '/category/fashion', '/category/FASHION 301 -> lowercase');
t(req('/product/Sample-Smart-Watch')['location'] === B . '/product/sample-smart-watch', '/product/Mixed-Case 301 -> canonical slug');

echo "== Header\n";
$home = req('/')['body'];
$x = dom($home);
$brandImg = $x->query('//header//a[contains(@class,"navbar-brand")]//img')->item(0);
t($brandImg && $brandImg->getAttribute('alt') === 'Aalto Mart' && str_contains($brandImg->getAttribute('src'), 'brand/logo-wordmark.webp'), 'branding: logo image with alt "Aalto Mart"');
$nav = array_map(fn ($n) => trim($n->textContent), iterator_to_array(q($x, '//header//ul[contains(@class,"navbar-nav")]/li/a')));
t($nav === ['Home', 'Shop', 'Categories', 'Deals', 'Contact us'], 'nav: Home, Shop, Categories, Deals, Contact us', implode('|', $nav));
t(q($x, '//header//ul[contains(@class,"dropdown-menu")]//a[contains(@href,"/category/")]')->length === 5, 'categories dropdown lists 5 categories');
t(q($x, '//header//a[contains(@href,"/cart") and contains(@aria-label,"Shopping cart")]')->length === 1, 'cart link with accessible label');
t(q($x, '//header//button[contains(@class,"navbar-toggler") and @aria-controls="mainNav"]')->length === 1, 'mobile menu toggle');
t(q($x, '//header//form[@role="search"]//input[@name="q"]')->length === 1, 'header search box');
t(q($x, '//span[contains(@class,"cart-badge")]')->length === 0, 'no fake cart count while cart is empty');

echo "== Homepage\n";
$x = pageChecks('home', $home, B . '/');
t(text($x, '//h1') === 'Everyday style, thoughtfully priced.', 'hero heading');
t(q($x, '//section[@id="categories"]//a[contains(@class,"category-card")]')->length === 5, 'category section: 5 categories');
$featured = (int) val($pdo, 'SELECT COUNT(*) FROM products WHERE is_active=1 AND is_featured=1');
t(q($x, '//section[@aria-labelledby="featured-title"]//article')->length === min(8, $featured), "featured section: " . min(8, $featured) . ' cards');
$new = (int) val($pdo, 'SELECT COUNT(*) FROM products WHERE is_active=1 AND is_new=1');
t(q($x, '//section[@aria-labelledby="new-title"]//article')->length === min(8, $new), "new products section: " . min(8, $new) . ' cards');
$maxDisc = (int) val($pdo, 'SELECT MAX(FLOOR((regular_price-price)*100/regular_price)) FROM products WHERE price<regular_price AND is_active=1');
t(str_contains(text($x, '//section[@aria-labelledby="promo-title"]'), "Save up to {$maxDisc}%"), "promotional section: save up to {$maxDisc}%");
t(q($x, '//footer[contains(@class,"site-footer")]')->length === 1, 'footer');

echo "== Product cards\n";
$card = null;
foreach (q($x, '//article[contains(@class,"product-card")]') as $a) { if (str_contains($a->textContent, 'Sample Wireless Earbuds')) { $card = $a; break; } }
t($card !== null, 'earbuds card present');
$c = trim(preg_replace('/\s+/u', ' ', $card->textContent));
t(str_contains($c, '-17%'), 'card: discount badge -17%');
t(str_contains($c, 'Sale price ৳2,499.00') && str_contains($c, 'Regular price ৳2,999.00'), 'card: sale + struck regular price (with screen-reader labels)');
t(str_contains($c, 'In stock'), 'card: stock status');
t($card->getElementsByTagName('img')->item(0)->getAttribute('loading') === 'lazy', 'card image lazy-loaded');
$shopX = dom(req('/shop?q=speaker')['body']);
$sc = text($shopX, '//article[contains(@class,"product-card")]');
t(str_contains($sc, 'Out of stock') && q($shopX, '//article//button[contains(@class,"product-card__add")]')->length === 0, 'out-of-stock card: label, no add button');
$plain = text(dom(req('/shop?q=smart+watch')['body']), '//article[contains(@class,"product-card")]');
t(str_contains($plain, '৳4,999.00') && !str_contains($plain, '%'), 'no-sale card: regular price only, no badge');

echo "== Shop: grid, pagination\n";
$active = (int) val($pdo, 'SELECT COUNT(*) FROM products p JOIN categories c ON c.id=p.category_id AND c.is_active=1 WHERE p.is_active=1');
$r = req('/shop');
$x = pageChecks('shop', $r['body'], B . '/shop');
t(text($x, '//p[contains(@class,"page-head__count")]') === "$active products", "shows $active products");
t(count(cardNames($x)) === 12, 'page 1: 12 cards');
t(q($x, '//ul[contains(@class,"pagination")]//a[normalize-space()="3"]')->length === 1, 'pagination to page 3');
$p2 = dom(req('/shop?page=2')['body']);
t(count(cardNames($p2)) === 12 && !array_intersect(cardNames($p2), cardNames($x)), 'page 2: 12 different cards');
$p3 = dom(req('/shop?page=3')['body']);
t(count(cardNames($p3)) === $active - 24, 'page 3: remaining ' . ($active - 24));
t(attr($p3, '//link[@rel="canonical"]', 'href') === B . '/shop?page=3', 'page 3 canonical includes page');
t(str_contains(text($p3, '//title'), 'Page 3'), 'page 3 title says Page 3');
t(req('/shop?page=99')['location'] === B . '/shop?page=3', 'page past the end redirects to last page');
t(req('/shop?page=abc')['code'] === 200 && req('/shop?page=-1')['code'] === 200, 'junk page numbers fall back to page 1');
t(q($x, '//article//img[@loading="eager"]')->length === 4, 'first row (4 cards) eager, rest lazy');

echo "== Shop: search\n";
t(cardNames(dom(req('/shop?q=watch')['body'])) === ['Sample Smart Watch'], 'search "watch"');
t(cardNames(dom(req('/shop?q=usb+cable')['body'])) === ['Sample USB-C Cable 1m'], 'multi-word search "usb cable" (all words must match)');
t(cardNames(dom(req('/shop?q=SMP-HOM-004')['body'])) === ['Sample Table Lamp'], 'search by SKU');
t(cardNames(dom(req('/shop?q=chocolate')['body'])) === ['Sample Dark Chocolate Bar'], 'search matches short description');
$r = req('/shop?q=' . urlencode('"><script>alert(1)</script>'));
t(!str_contains($r['body'], '<script>alert(1)') && str_contains($r['body'], '&quot;&gt;&lt;script&gt;'), 'search term HTML-escaped');
$r = req('/shop?q=' . urlencode("' OR 1=1 -- "));
t($r['code'] === 200 && str_contains($r['body'], 'No products found'), 'SQL-injection-style search returns nothing, no error');
t(str_contains(req('/shop?q=%25')['body'], 'Sample Dark Chocolate Bar'), '"%" is literal (matches "70% cocoa")');
$x = dom(req('/shop?q=watch')['body']);
t(attr($x, '//meta[@name="robots"]', 'content') === 'noindex, follow', 'search results are noindex');
t(str_contains(text($x, '//h1'), 'Results for "watch"'), 'search heading');
t(q($x, '//a[contains(@class,"filter-chip") and contains(.,"watch")]')->length === 1, 'active search shown as removable chip');

echo "== Shop: category filter & sorting\n";
$x = dom(req('/shop?category=fashion&sale=1')['body']);
$fashionSale = (int) val($pdo, "SELECT COUNT(*) FROM products p JOIN categories c ON c.id=p.category_id WHERE c.slug='fashion' AND p.price<p.regular_price AND p.is_active=1");
t(count(cardNames($x)) === $fashionSale, "category + sale filter: $fashionSale fashion items on sale");
$cheap = val($pdo, 'SELECT name FROM products WHERE is_active=1 ORDER BY price ASC, id LIMIT 1');
$dear = val($pdo, 'SELECT name FROM products WHERE is_active=1 ORDER BY price DESC, id DESC LIMIT 1');
t(cardNames(dom(req('/shop?sort=price_asc')['body']))[0] === $cheap, "sort price low-high: $cheap first");
t(cardNames(dom(req('/shop?sort=price_desc')['body']))[0] === $dear, "sort price high-low: $dear first");
t(cardNames(dom(req('/shop?sort=name')['body']))[0] === val($pdo, 'SELECT name FROM products WHERE is_active=1 ORDER BY name LIMIT 1'), 'sort by name');
$prices = [];
foreach ([1, 2, 3] as $pg) {
    foreach (q(dom(req("/shop?sort=price_asc&page=$pg")['body']), '//article//span[contains(@class,"product-card__price")]') as $n) {
        $prices[] = (int) preg_replace('/\D/', '', preg_replace('/^.*?৳/u', '', $n->textContent));
    }
}
$sorted = $prices; sort($sorted);
t($prices === $sorted && count($prices) === $active, 'price sort holds across all pages (sale prices used)');
t(req('/shop?sort=DROP%20TABLE')['code'] === 200, 'unknown sort ignored');
t(count(cardNames(dom(req('/shop?sale=1')['body']))) === min(12, (int) val($pdo, 'SELECT COUNT(*) FROM products WHERE price<regular_price AND is_active=1')), 'on-sale filter');
t(!in_array('Sample Bluetooth Speaker', cardNames(dom(req('/shop?in_stock=1&q=speaker')['body'])), true), 'in-stock filter hides sold-out');
t(count(cardNames(dom(req('/shop?new=1')['body']))) === min(12, $new), 'new filter');
$x = dom(req('/shop?sort=price_asc&page=2')['body']);
t(str_contains((string) attr($x, '//ul[contains(@class,"pagination")]//a[normalize-space()="1"]', 'href'), 'sort=price_asc'), 'pagination keeps sort');

echo "== Category page\n";
$r = req('/category/electronics');
$x = pageChecks('category', $r['body'], B . '/category/electronics');
t(text($x, '//h1') === 'Electronics', 'category name');
t(str_contains(text($x, '//p[contains(@class,"page-head__desc")]'), 'gadgets, audio and accessories'), 'category description');
t(text($x, '//p[contains(@class,"page-head__count")]') === '5 products', 'product count');
t(count(cardNames($x)) === 5, '5 product cards');
t(str_contains(text($x, '//ol[contains(@class,"breadcrumb")]'), 'Home Shop Electronics'), 'breadcrumb');
t(q($x, '//ul[contains(@class,"category-nav")]//a[@aria-current="page" and contains(.,"Electronics")]')->length === 1, 'current category highlighted in sidebar');
$x = dom(req('/category/electronics?q=watch')['body']);
t(cardNames($x) === ['Sample Smart Watch'] && str_contains(text($x, '//p[contains(@class,"page-head__count")]'), 'of 5 in this category'), 'search within category');
$pdo->exec("UPDATE categories SET is_active=0 WHERE slug='groceries'");
t(req('/category/groceries')['code'] === 404, 'inactive category -> 404');
t(req('/product/sample-olive-oil-1l')['code'] === 404, 'product in inactive category -> 404');
t(!str_contains(req('/shop?q=olive')['body'], 'Sample Olive Oil'), 'inactive category products hidden from shop');
$pdo->exec("UPDATE categories SET is_active=1 WHERE slug='groceries'");

echo "== Product page\n";
$r = req('/product/sample-wireless-earbuds');
$x = pageChecks('product', $r['body'], B . '/product/sample-wireless-earbuds');
t(text($x, '//h1') === 'Sample Wireless Earbuds', 'product name');
t(attr($x, '//meta[@property="og:type"]', 'content') === 'product', 'og:type = product');
t(attr($x, '//meta[@property="product:price:amount"]', 'content') === '2499.00', 'product:price:amount 2499.00');
$prices = text($x, '//div[contains(@class,"product-info__prices")]');
t(str_contains($prices, 'Sale price: ৳2,499.00') && str_contains($prices, 'Regular price: ৳2,999.00') && str_contains($prices, 'Save ৳500.00 (17%)'), 'regular, sale, discount (Save ৳500.00, 17%)');
t(text($x, '//span[contains(@class,"product-gallery__badge")]') === '-17%', 'discount badge on image');
t(str_contains(text($x, '//section[@aria-labelledby="desc-title"]'), 'Sample product for development'), 'description');
t(text($x, '//span[contains(@class,"stock-pill")]') === 'In stock', 'stock status: In stock');
t(q($x, '//input[@name="quantity"]')->length === 0 && q($x, '//*[@data-qty-picker or @data-cart-stepper or @data-buy-button]')->length === 0 && q($x, '//div[contains(@class,"product-info")]//button[contains(.,"Add to Cart")]')->length === 0, 'no Quantity picker, Add to Cart or stepper on the product page');
t(q($x, '//div[contains(@class,"product-buy")]/button[@form="buy-form" and @name="buy_now" and contains(.,"Order Now")]')->length === 1, 'Order Now is the only buy button');
t(q($x, '//form[@id="buy-form"]//input[@name="_csrf"]')->length === 1, 'add-to-cart form has CSRF token');
$main = q($x, '//img[@data-gallery-main]')->item(0);
t($main->getAttribute('loading') === 'eager' && $main->getAttribute('fetchpriority') === 'high', 'main image eager + fetchpriority=high (LCP)');
$rel = q($x, '//section[@aria-labelledby="related-title"]//article');
t($rel->length === 4 && !str_contains(text($x, '//section[@aria-labelledby="related-title"]'), 'Sample Wireless Earbuds'), '4 related products, not itself');
$ld = []; foreach (q($x, '//script[@type="application/ld+json"]') as $s) { $ld[] = json_decode($s->textContent, true); }
t(count($ld) === 2 && $ld[0]['@type'] === 'Product' && $ld[0]['offers']['price'] === '2499.00' && $ld[0]['offers']['availability'] === 'https://schema.org/InStock', 'JSON-LD Product (price 2499.00, InStock)');
t($ld[1]['@type'] === 'BreadcrumbList' && count($ld[1]['itemListElement']) === 3, 'JSON-LD BreadcrumbList');
$x = dom(req('/product/sample-bluetooth-speaker')['body']);
t(text($x, '//span[contains(@class,"stock-pill")]') === 'Out of stock' && q($x, '//div[contains(@class,"product-info")]//input[@name="quantity"]')->length === 0 && q($x, '//button[@disabled and contains(.,"Out of stock")]')->length === 1, 'out of stock: no quantity, disabled button');
$pdo->exec("UPDATE products SET stock_quantity=3 WHERE sku='SMP-HOM-002'");
$x = dom(req('/product/sample-ceramic-mug-set')['body']);
t(text($x, '//span[contains(@class,"stock-pill")]') === 'Only 3 left' && str_contains(text($x, '//p[contains(.,"Selling fast")]'), 'only 3 left'), 'low stock: "Only 3 left" + "Selling fast"');
$pdo->exec("UPDATE products SET stock_quantity=45 WHERE sku='SMP-HOM-002'");
$x = dom(req('/product/sample-smart-watch')['body']);
t(text($x, '//div[contains(@class,"product-info__prices")]') === '৳4,999.00' && q($x, '//div[contains(@class,"product-info")]//del')->length === 0, 'no sale: regular price only');
$pdo->exec("UPDATE products SET is_active=0 WHERE sku='SMP-ELE-003'");
t(req('/product/sample-power-bank-10000mah')['code'] === 404, 'inactive product -> 404');
$pdo->exec("UPDATE products SET is_active=1 WHERE sku='SMP-ELE-003'");
$x = dom(req('/product/does-not-exist')['body']);
t(attr($x, '//meta[@name="robots"]', 'content') === 'noindex, follow', '404 page is noindex');

echo "== Add to cart from the storefront (full cart suite: cart_test.php)\n";
@unlink($jar);
$page = req('/product/sample-wireless-earbuds')['body']; $tok = csrf($page);
$r = req('/cart/add', ['_csrf' => $tok, 'product_id' => 1, 'quantity' => 2, 'return' => '/product/sample-wireless-earbuds']);
t($r['code'] === 302 && $r['location'] === B . '/product/sample-wireless-earbuds', 'valid add redirects back to product');
$page = req('/product/sample-wireless-earbuds')['body'];
t(str_contains($page, 'Added 2 ×') && str_contains($page, 'class="cart-badge" aria-hidden="true">2<'), 'added message + header count 2');
t(str_contains($page, 'name="return" value="/product/sample-wireless-earbuds"'), 'product form sends its own page as return path');
t(str_contains(req('/shop?q=watch')['body'], 'name="return" value="/shop?q=watch"'), 'card form returns to the listing (with query)');
foreach ([['bad token', ['_csrf' => 'x', 'product_id' => 1, 'quantity' => 1], 'session expired'],
          ['qty 0', ['_csrf' => $tok, 'product_id' => 1, 'quantity' => 0], 'quantity of 1 or more'],
          ['qty text', ['_csrf' => $tok, 'product_id' => 1, 'quantity' => 'abc'], 'quantity of 1 or more'],
          ['out of stock', ['_csrf' => $tok, 'product_id' => (int) val($pdo, "SELECT id FROM products WHERE sku='SMP-ELE-005'"), 'quantity' => 1], 'out of stock'],
          ['unknown product', ['_csrf' => $tok, 'product_id' => 99999, 'quantity' => 1], 'not available']] as [$label, $post, $msg]) {
    $r = req('/cart/add', $post, true);
    t(str_contains($r['body'], $msg), "cart add rejected: $label", $msg);
}
t(req('/cart/add')['code'] === 405, 'GET /cart/add not allowed');
req('/cart/clear', ['_csrf' => $tok]);

echo "== SEO extras\n";
$r = req('/robots.txt');
t(str_contains($r['body'], 'Disallow: /admin') && str_contains($r['body'], 'Sitemap: ' . B . '/sitemap.xml'), 'robots.txt: disallow /admin, sitemap URL');
$r = req('/sitemap.xml');
$xml = @simplexml_load_string($r['body']);
t($xml !== false, 'sitemap.xml is valid XML');
$locs = array_map('strval', iterator_to_array($xml->url->loc ?? [], false));
$locs = []; foreach ($xml->url as $u) { $locs[] = (string) $u->loc; }
t(count(array_filter($locs, fn ($l) => str_contains($l, '/product/'))) === $active && count(array_filter($locs, fn ($l) => str_contains($l, '/category/'))) === 5, "sitemap: $active products + 5 categories");
t(!array_filter($locs, fn ($l) => str_contains($l, '/admin')), 'sitemap has no admin URLs');

echo "== Performance\n";
$js = file_get_contents('D:/SDL-PROJECT/AALTO-ECOM/public/assets/js/app.js');
t(!preg_match('/\b(fetch|XMLHttpRequest)\s*\(/', $js), 'storefront JS makes no API calls');
t(strlen(gzencode($js, 9)) < 8192, // options, live totals, checkout district/thana and zoom (was 3 KB before)
 'storefront JS is small (' . round(strlen($js) / 1024, 1) . ' KB raw, ' . round(strlen(gzencode($js, 9)) / 1024, 1) . ' KB gzipped)');
t(preg_match_all('/<script src="[^"]+"/', $home) === 2 && preg_match_all('/<script src="[^"]+" defer><\/script>/', $home) === 2 && str_contains($home, 'app.js'), 'every script loads with defer');
$times = [];
foreach (['/', '/shop', '/category/electronics', '/product/sample-wireless-earbuds', '/shop?q=sample&sort=price_desc'] as $p) {
    req($p); $best = 9; for ($i = 0; $i < 3; $i++) { $best = min($best, req($p)['time']); } $times[$p] = round($best * 1000);
}
t(max($times) < 250, 'server response times (ms): ' . json_encode($times));

echo "\nTOTAL: $pass passed, $fail failed\n";
@unlink($jar);
