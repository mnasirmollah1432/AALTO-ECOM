<?php
/**
 * Home slider, Contact & social, chat button, wishlist, Add To Cart buttons.
 * Needs the dev server on :8080 and a freshly seeded aalto_mart_dev.
 */
declare(strict_types=1);

const B = 'http://localhost:8080';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = 0; $fail = 0;
$ADMIN = __DIR__ . '/h_admin.txt'; $SHOP = __DIR__ . '/h_shop.txt';
@unlink($ADMIN); @unlink($SHOP);
$FIX = __DIR__ . '/fixtures/banners/';

function t(bool $ok, string $label, string $detail = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "  [$detail]") . "\n"; }
function req(string $path, array|null $post = null, ?string $jar = null, bool $multipart = false): array {
    global $SHOP; $jar ??= $SHOP;
    $ch = curl_init(B . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'HomeTest']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post)); }
    $raw = curl_exec($ch); $info = curl_getinfo($ch); curl_close($ch);
    return ['code' => $info['http_code'], 'head' => substr($raw, 0, $info['header_size']), 'body' => substr($raw, $info['header_size']), 'location' => $info['redirect_url']];
}
function tok(string $path, ?string $jar = null): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', req($path, null, $jar)['body'], $m); return $m[1] ?? ''; }
function val(string $sql): mixed { global $pdo; return $pdo->query($sql)->fetchColumn(); }
function flash(string $html): string { preg_match_all('/<div class="alert[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m); return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode(' ', $m[1] ?? []))))); }

$lt = tok('/admin/login', $ADMIN);
req('/admin/login', ['_csrf' => $lt, 'email' => 'admin@example.com', 'password' => 'Admin@12345'], $ADMIN);

echo "== Before any slides\n";
$home = req('/')['body'];
t(str_contains($home, 'class="hero"') && !str_contains($home, 'data-slider'), 'no slides: standard hero shown');
t(!str_contains($home, 'data-chat-widget'), 'chat button hidden until switched on');

echo "== Admin: home slider\n";
t(req('/admin/slides', null, __DIR__ . '/h_anon.txt')['location'] === B . '/admin/login', 'signed out: /admin/slides -> login'); @unlink(__DIR__ . '/h_anon.txt');
$page = req('/admin/slides', null, $ADMIN)['body'];
t(str_contains($page, 'Add slide') && str_contains($page, 'Add side banner') && str_contains($page, '1500 × 600'), 'slider page with add buttons and image size');
$up = function (string $file, array $fields, string $path = '/admin/slides/create') use ($ADMIN, $FIX) {
    $f = ['_csrf' => tok($path === '/admin/slides/create' ? '/admin/slides/create' : $path, $ADMIN)] + $fields;
    if ($file !== '') { $f['image'] = new CURLFile($FIX . $file, 'image/png', $file); }
    return req($path, $f, $ADMIN, true);
};
$r = $up('', ['placement' => 'slider', 'title' => 'No image', 'is_active' => '1']);
t(str_contains(flash(req('/admin/slides/create', null, $ADMIN)['body']), 'fix the errors') && (int) val('SELECT COUNT(*) FROM home_slides') === 0, 'slide without image refused');
$r = $up('slide1.png', ['placement' => 'slider', 'title' => 'Eid collection', 'link_url' => 'javascript:alert(1)', 'is_active' => '1']);
t((int) val('SELECT COUNT(*) FROM home_slides') === 0, 'javascript: link refused');
$up('slide1.png', ['placement' => 'slider', 'title' => 'Eid collection', 'link_url' => '//evil.example', 'is_active' => '1']);
t((int) val('SELECT COUNT(*) FROM home_slides') === 0, '//host link refused');
$up('slide1.png', ['placement' => 'slider', 'title' => 'Eid collection', 'link_url' => '/shop?sale=1', 'is_active' => '1']);
$up('slide2.png', ['placement' => 'slider', 'title' => 'New arrivals', 'link_url' => 'https://example.com/new', 'is_active' => '1']);
$up('slide3.png', ['placement' => 'slider', 'title' => 'Hidden one', 'link_url' => '', 'is_active' => '0']);
$up('side.png', ['placement' => 'side', 'title' => 'Side offer', 'link_url' => '/category/fashion', 'is_active' => '1']);
t((int) val("SELECT COUNT(*) FROM home_slides WHERE placement='slider'") === 3 && (int) val("SELECT COUNT(*) FROM home_slides WHERE placement='side'") === 1, '3 slides + 1 side banner saved');
$img = val("SELECT image_path FROM home_slides WHERE title='Eid collection'");
t((bool) preg_match('#^uploads/banners/home-slide-[a-f0-9]{8}\.webp$#', $img) && is_file('D:/SDL-PROJECT/AALTO-ECOM/public/' . $img), "image optimised to WebP in uploads/banners ($img)");
[$w, $h] = getimagesize('D:/SDL-PROJECT/AALTO-ECOM/public/' . $img);
t($w === 1500 && $h === 600, "slide image 1500x600 ({$w}x{$h})");
[$w, $h] = getimagesize('D:/SDL-PROJECT/AALTO-ECOM/public/' . val("SELECT image_path FROM home_slides WHERE placement='side'"));
t($w === 750 && $h === 600, "side banner 750x600 ({$w}x{$h})");

echo "== Homepage slider\n";
$home = req('/')['body'];
t(str_contains($home, 'data-slider') && !str_contains($home, 'class="hero"'), 'slider replaces the hero');
t(substr_count($home, 'data-slide ') === 2 && !str_contains($home, 'Hidden one'), 'only the 2 active slides shown');
t(str_contains($home, 'href="' . B . '/shop?sale=1" aria-label="Eid collection"'), 'site link kept on this site');
t(str_contains($home, 'href="https://example.com/new" aria-label="New arrivals" target="_blank" rel="noopener noreferrer"'), 'external link opens in a new tab with noopener');
t((bool) preg_match('/<img[^>]+alt="Eid collection"[^>]+loading="eager"[^>]+fetchpriority="high"/', $home) || (bool) preg_match('/<img[^>]+fetchpriority="high"[^>]+alt="Eid collection"/', $home) || str_contains($home, 'loading="eager" decoding="async" fetchpriority="high"'), 'first slide loads eagerly (fast LCP)');
t(str_contains($home, 'class="side-banner"') && str_contains($home, 'alt="Side offer"'), 'side banner shown');
t(str_contains($home, 'data-slider-prev') && str_contains($home, 'data-autoplay="5000"'), 'arrows + autoplay for 2+ slides');
t((bool) preg_match('/<h1 class="visually-hidden">/', $home), 'page still has an h1');
$sid = (int) val("SELECT id FROM home_slides WHERE title='New arrivals'");
req("/admin/slides/move/$sid", ['_csrf' => tok('/admin/slides', $ADMIN), 'direction' => 'up'], $ADMIN);
$home = req('/')['body'];
t(strpos($home, 'alt="New arrivals"') < strpos($home, 'alt="Eid collection"'), 'move up reorders the slider');
req("/admin/slides/toggle/$sid", ['_csrf' => tok('/admin/slides', $ADMIN)], $ADMIN);
$home = req('/')['body'];
t(!str_contains($home, 'alt="New arrivals"') && !str_contains($home, 'data-slider-prev'), 'hidden slide removed; one slide = no arrows');
$old = val("SELECT image_path FROM home_slides WHERE id=$sid");
$up('slide3.png', ['title' => 'New arrivals 2', 'link_url' => '/shop', 'is_active' => '1'], "/admin/slides/edit/$sid");
t(val("SELECT title FROM home_slides WHERE id=$sid") === 'New arrivals 2' && val("SELECT image_path FROM home_slides WHERE id=$sid") !== $old && !is_file('D:/SDL-PROJECT/AALTO-ECOM/public/' . $old), 'edit replaces the image and deletes the old file');
$del = val("SELECT image_path FROM home_slides WHERE id=$sid");
req("/admin/slides/delete/$sid", ['_csrf' => tok('/admin/slides', $ADMIN)], $ADMIN);
t((int) val("SELECT COUNT(*) FROM home_slides WHERE id=$sid") === 0 && !is_file('D:/SDL-PROJECT/AALTO-ECOM/public/' . $del), 'delete removes the slide and its file');
req('/admin/slides/delete/1', ['_csrf' => 'forged'], $ADMIN);
t((int) val('SELECT COUNT(*) FROM home_slides WHERE id=1') === 1, 'forged CSRF: nothing deleted');

echo "== Admin: contact & social\n";
$c = req('/admin/contact', null, $ADMIN)['body'];
t(str_contains($c, 'name="contact_whatsapp"') && str_contains($c, 'name="contact_imo"') && str_contains($c, 'name="contact_messenger"') && str_contains($c, 'name="chat_enabled"'), 'form has WhatsApp, imo, Messenger, chat button');
$good = ['contact_phone' => '+880 1712-345678', 'contact_whatsapp' => '01712-345678', 'contact_imo' => '01812345678', 'contact_messenger' => 'https://m.me/aaltomart',
    'contact_email' => 'hello@aaltomart.test', 'contact_facebook' => 'https://www.facebook.com/aaltomart', 'contact_instagram' => 'https://instagram.com/aaltomart',
    'contact_youtube' => '', 'contact_tiktok' => 'https://www.tiktok.com/@aaltomart', 'contact_address' => "House 4, Road 2\nDhanmondi, Dhaka", 'contact_hours' => 'Sat-Thu 10am-8pm',
    'contact_map' => 'https://maps.app.goo.gl/abc123', 'chat_enabled' => '1', 'chat_whatsapp' => '1', 'chat_messenger' => '1', 'chat_greeting' => 'Hi Aalto Mart!'];
foreach ([['contact_facebook', 'https://evil.example/aaltomart'], ['contact_whatsapp', 'call me'], ['contact_email', 'nope'], ['contact_messenger', 'bad name!'], ['contact_address', '<script>x</script>'], ['contact_instagram', 'javascript:alert(1)']] as [$k, $bad]) {
    req('/admin/contact', ['_csrf' => tok('/admin/contact', $ADMIN), $k => $bad] + $good, $ADMIN);
    t(val("SELECT COUNT(*) FROM settings WHERE setting_key='$k'") === 0, "$k \"$bad\" rejected");
}
req('/admin/contact', ['_csrf' => tok('/admin/contact', $ADMIN), 'contact_whatsapp' => '', 'contact_messenger' => ''] + $good, $ADMIN);
t(str_contains(flash(req('/admin/contact', null, $ADMIN)['body']), 'fix the highlighted') , 'chat on with no WhatsApp/Messenger -> explained');
req('/admin/contact', ['_csrf' => tok('/admin/contact', $ADMIN)] + $good, $ADMIN);
t(val("SELECT setting_value FROM settings WHERE setting_key='contact_whatsapp'") === '01712-345678' && val("SELECT setting_value FROM settings WHERE setting_key='chat_enabled'") === '1', 'contact details saved');

echo "== Contact page\n";
$p = req('/contact');
$b = $p['body'];
t($p['code'] === 200 && str_contains($b, '<h1 class="page-head__title">Contact us</h1>'), '/contact loads');
t(str_contains($b, 'href="https://wa.me/8801712345678"') && str_contains($b, 'href="https://m.me/aaltomart"'), 'WhatsApp (wa.me with 880) and Messenger (m.me) links');
t(str_contains($b, 'contact-card--imo') && str_contains($b, 'data-copy="01812345678"'), 'imo number with copy button');
t(str_contains($b, 'href="tel:+8801712345678"') && str_contains($b, 'href="mailto:hello@aaltomart.test"'), 'call and email links');
t(str_contains($b, 'href="https://www.facebook.com/aaltomart"') && str_contains($b, 'href="https://www.tiktok.com/@aaltomart"') && !str_contains($b, 'contact-card--youtube'), 'filled social links shown, empty YouTube hidden');
t(str_contains($b, 'House 4, Road 2<br />') && str_contains($b, 'Open in Google Maps'), 'address and map link');
t(substr_count($b, 'target="_blank" rel="noopener noreferrer"') >= 5, 'external links open safely in new tabs');
t(str_contains($b, '"@type":"Organization"') && str_contains($b, '"sameAs"'), 'Organization structured data with social links');
t(str_contains(req('/')['body'], 'href="' . B . '/contact"'), 'Contact us in the top menu');
t(str_contains(req('/sitemap.xml')['body'], '<loc>' . B . '/contact</loc>'), 'contact page in sitemap');

echo "== Chat button\n";
$home = req('/')['body'];
t(str_contains($home, 'data-chat-widget') && str_contains($home, 'href="https://wa.me/8801712345678?text=Hi%20Aalto%20Mart%21"') && str_contains($home, 'chat-widget__link--messenger'), 'chat button with WhatsApp (greeting) and Messenger');
t(str_contains($home, 'class="footer-social"') && str_contains($home, 'aria-label="Facebook"'), 'social icons in the footer');
req('/admin/contact', ['_csrf' => tok('/admin/contact', $ADMIN), 'chat_messenger' => '0'] + $good, $ADMIN);
t(!str_contains(req('/')['body'], 'chat-widget__link--messenger'), 'Messenger unticked -> only WhatsApp in the chat button');
req('/admin/contact', ['_csrf' => tok('/admin/contact', $ADMIN), 'chat_enabled' => '0'] + $good, $ADMIN);
t(!str_contains(req('/')['body'], 'data-chat-widget'), 'chat button switched off');
req('/admin/contact', ['_csrf' => tok('/admin/contact', $ADMIN)] + $good, $ADMIN);

echo "== Header, Add To Cart and wishlist\n";
$shop = req('/shop')['body'];
t(str_contains($shop, 'class="btn-icon wish-link') && str_contains($shop, 'class="btn-icon cart-link'), 'header: wishlist heart and cart on the right');
t(str_contains($shop, '<span>Add To Cart</span>') && str_contains($shop, 'class="product-card__btn"'), 'cards have the full-width "Add To Cart" button');
t(str_contains($shop, '>Out of stock</span>'), 'sold-out card shows "Out of stock"');
$ear = (int) val("SELECT id FROM products WHERE sku='SMP-ELE-001'");
req('/wishlist/toggle', ['_csrf' => tok('/shop'), 'product_id' => (string) $ear, 'return' => '/shop'])['location'];
$shop = req('/shop')['body'];
t(str_contains(flash($shop), 'Saved "Sample Wireless Earbuds" to your wishlist') && str_contains($shop, 'class="cart-badge wish-badge" aria-hidden="true">1<'), 'saved to wishlist, header badge 1');
t(str_contains($shop, 'aria-label="Remove from wishlist: Sample Wireless Earbuds"'), 'card heart shows "saved"');
$w = req('/wishlist');
t($w['code'] === 200 && str_contains($w['body'], 'Sample Wireless Earbuds') && str_contains($w['body'], 'noindex, nofollow'), 'wishlist page lists it (noindex)');
req('/wishlist/toggle', ['_csrf' => 'forged', 'product_id' => (string) ($ear + 1)]);
t(!str_contains(req('/wishlist')['body'], 'Sample Smart Watch'), 'forged CSRF: nothing added');
req('/wishlist/toggle', ['_csrf' => tok('/shop'), 'product_id' => '999999']);
t(str_contains(flash(req('/wishlist')['body']), 'not available'), 'unknown product refused');
$pdo->exec("UPDATE products SET is_active = 0 WHERE id = $ear");
t(str_contains(req('/wishlist')['body'], 'Your wishlist is empty'), 'deactivated product disappears from the wishlist');
$pdo->exec("UPDATE products SET is_active = 1 WHERE id = $ear");
req('/wishlist/toggle', ['_csrf' => tok('/shop'), 'product_id' => (string) $ear]);
t(str_contains(flash(req('/wishlist')['body']), 'Removed'), 'second tap removes it');
t(str_contains(req('/robots.txt')['body'], 'Disallow: /wishlist'), 'robots.txt blocks /wishlist');
$prod = req('/product/sample-wireless-earbuds')['body'];
t(str_contains($prod, 'class="product-wish"') && str_contains($prod, 'Add to wishlist'), 'product page has "Add to wishlist"');

require __DIR__ . '/appearance_tests.php';

echo "\nTOTAL: $pass passed, $fail failed\n";
@unlink($ADMIN); @unlink($SHOP);
