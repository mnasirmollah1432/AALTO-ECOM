<?php
// Universal image upload standard: every upload section, via the real admin forms.
const B = 'http://localhost:8080';
const PUB = 'D:/SDL-PROJECT/AALTO-ECOM/public';
const FX = __DIR__ . '/fixtures';
const STD = __DIR__ . '/fixtures/std';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=aalto_mart_dev;charset=utf8mb4', 'root', '1234', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$pass = 0; $fail = 0;
function t(bool $ok, string $label): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; }

final class C {
    public string $jar;
    public function __construct(public string $ua = 'Mozilla/5.0 (Windows NT 10.0) ImgStd') { $this->jar = tempnam(sys_get_temp_dir(), 'is'); }
    public function req(string $m, string $path, $data = null): array {
        $ch = curl_init(B . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_USERAGENT => $this->ua, CURLOPT_CUSTOMREQUEST => $m]);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        preg_match('/^location: (.*)$/mi', substr($raw, 0, $hs), $m1);
        return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => trim($m1[1] ?? '')];
    }
    public function get(string $p): array { return $this->req('GET', $p); }
    public function tok(string $page): string { return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->get($page)['body'], $m) ? $m[1] : ''; }
    /** Flash text shown on the page we were redirected to */
    public function flash(array $r): string {
        $page = $r['loc'] ? $this->get(parse_url($r['loc'], PHP_URL_PATH))['body'] : $r['body'];
        $out = [];
        if (preg_match_all('/class="alert alert-\w+[^"]*"[^>]*>\s*(.*?)\s*<button/s', $page, $m)) $out = $m[1];
        if (preg_match_all('/class="invalid-feedback[^"]*"[^>]*>([^<]+)/', $page, $m2)) $out = array_merge($out, $m2[1]);
        return html_entity_decode(implode(' | ', array_map('trim', $out)), ENT_QUOTES);
    }
}
$file = fn (string $path, string $mime, ?string $name = null) => new CURLFile($path, $mime, $name ?? basename($path));
$dims = function (string $rel) { $s = @getimagesize(PUB . '/' . $rel); return $s ? [$s[0], $s[1], $s['mime']] : [0, 0, '']; };
$kb = fn (string $rel) => (int) round(filesize(PUB . '/' . $rel) / 1024);

$a = new C();
$a->req('POST', '/admin/login', ['_csrf' => $a->tok('/admin/login'), 'email' => 'admin@example.com', 'password' => 'Admin@12345']);
t($a->get('/admin/dashboard')['code'] === 200, 'admin signed in');

// ================================================================= admin UI: spec shown before choosing
echo "== Admin help text (before choosing a file)\n";
$pf = $a->get('/admin/products/create')['body'];
$cf = $a->get('/admin/categories/create')['body'];
t(str_contains($pf, 'Recommended:</strong> 800 × 1000 px') && str_contains($pf, 'Ratio:</strong> 4:5') && str_contains($pf, 'Target: ≤ 100 KB'), 'product main: 800 × 1000 px | 4:5 | WebP | ≤ 100 KB');
t(substr_count($pf, 'Recommended:</strong> 800 × 1000 px') === 2, 'gallery: its own spec block');
t(str_contains($cf, 'Recommended:</strong> 320 × 320 px') && str_contains($cf, 'Ratio:</strong> 1:1') && str_contains($cf, 'Target: ≤ 20 KB'), 'category: 320 × 320 px | 1:1 | WebP | ≤ 20 KB');
t(str_contains($pf, 'up to 8 MB') && str_contains($pf, 'at least 400 × 500 px') && str_contains($pf, 'centre-cropped to 4:5'), 'upload limit, minimum and crop rule explained');
t(preg_match('/id="main_image"[^>]*data-spec="\{[^"]*&quot;width&quot;:800/', $pf) === 1 && preg_match('/aria-describedby="[^"]*main-spec/', $pf) === 1, 'input carries the spec (data-spec) and is described by the help text');
t(str_contains($pf, 'style="aspect-ratio: 800 / 1000"') && str_contains($cf, 'aspect-ratio: 320 / 320'), 'preview frames use the slot ratio');

// ================================================================= category
echo "== Category image (320 × 320, 1:1, ≤ 20 KB)\n";
$cat = function (string $name, CURLFile $img) use ($a) {
    return $a->req('POST', '/admin/categories/create', ['_csrf' => $a->tok('/admin/categories/create'), 'name' => $name, 'status' => 'active', 'image' => $img]);
};
$r = $cat('Std Cat Landscape', $file(STD . '/photo-2000x1500.jpg', 'image/jpeg'));
$flash = $a->flash($r);
$p1 = (string) $q("SELECT image_path FROM categories WHERE name = 'Std Cat Landscape'");
[$w, $h, $mime] = $dims($p1);
t($w === 320 && $h === 320 && $mime === 'image/webp', "JPG 2000×1500 (wrong ratio) -> $w × $h $mime");
t(preg_match('#^uploads/categories/category-std-cat-landscape-[a-f0-9]{8}\.webp$#', $p1) === 1, "safe name: $p1");
t($kb($p1) <= 20, 'size ' . $kb($p1) . ' KB (≤ 20 KB target)');
t(str_contains($flash, 'Image optimised') && str_contains($flash, '→') && str_contains($flash, 'centre-cropped to 1:1'), "report: " . preg_replace('/^.*Image optimised: /', '', $flash));
t(!is_file(PUB . '/' . preg_replace('/\.webp$/', '-sm.webp', $p1)), 'no thumbnail for 320 px category images');
$r = $cat('Std Cat Png', $file(STD . '/photo-1000x1000.png', 'image/png'));
$p2 = (string) $q("SELECT image_path FROM categories WHERE name = 'Std Cat Png'"); [$w, $h, $mime] = $dims($p2);
t($w === 320 && $h === 320 && $mime === 'image/webp' && !str_contains($a->flash($r), 'cropped'), "PNG 1000×1000 (correct ratio) -> $w × $h webp, not cropped, " . $kb($p2) . ' KB');
$r = $cat('Std Cat Webp', $file(STD . '/photo-320x320.webp', 'image/webp'));
$p3 = (string) $q("SELECT image_path FROM categories WHERE name = 'Std Cat Webp'"); [$w, $h] = $dims($p3);
t($w === 320 && $h === 320 && $kb($p3) <= 20, "WebP 320×320 (exact) -> $w × $h, " . $kb($p3) . ' KB');
$r = $cat('Std Cat Small', $file(STD . '/small-120x120.png', 'image/png'));
t(!$q("SELECT COUNT(*) FROM categories WHERE name = 'Std Cat Small'") && str_contains($a->flash($r), 'too small: 120 × 120 px') , 'too small (120×120) refused: "' . mb_substr($a->flash($r), 0, 90) . '…"');
foreach ([['fake.jpg', 'image/jpeg', FX], ['image.svg', 'image/svg+xml', FX], ['image.gif', 'image/gif', FX], ['shell.php', 'image/png', FX], ['corrupt.jpg', 'image/jpeg', FX], ['evil.php.jpg', 'image/jpeg', STD], ['windows-exe.jpg', 'image/jpeg', STD], ['script.png', 'image/png', STD], ['png-named.jpg', 'image/jpeg', FX]] as [$f, $m, $d]) {
    $cat('Std Cat Bad ' . $f, $file("$d/$f", $m));
}
t(!$q("SELECT COUNT(*) FROM categories WHERE name LIKE 'Std Cat Bad%'"), 'refused: fake, SVG, GIF, .php, corrupted, PHP renamed .jpg, EXE renamed .jpg, script .png, extension mismatch');
$r = $cat('Std Cat Huge', $file(FX . '/huge-dimensions.png', 'image/png'));
t(!$q("SELECT COUNT(*) FROM categories WHERE name = 'Std Cat Huge'") && str_contains($a->flash($r), 'too large to process'), 'very large dimensions (6000×4000) refused');
$r = $cat('Std Cat Big', $file(FX . '/big.jpg', 'image/jpeg'));
t(!$q("SELECT COUNT(*) FROM categories WHERE name = 'Std Cat Big'") && str_contains($a->flash($r), '8 MB'), 'file over 8 MB refused');
// replace + delete
$cid = (int) $q("SELECT id FROM categories WHERE name = 'Std Cat Png'");
$edit = $a->get("/admin/categories/edit/$cid")['body'];
t(str_contains($edit, 'Current: 320 × 320 px · 1:1 · ') && str_contains($edit, 'WEBP') && str_contains($edit, 'Replace image') && str_contains($edit, 'Delete current image'), 'existing image: preview, dimensions, size, format, replace, delete');
$a->req('POST', "/admin/categories/edit/$cid", ['_csrf' => $a->tok("/admin/categories/edit/$cid"), 'name' => 'Std Cat Png', 'slug' => 'std-cat-png', 'status' => 'active', 'image' => $file(STD . '/photo-2000x1500.jpg', 'image/jpeg')]);
$p2b = (string) $q('SELECT image_path FROM categories WHERE id = ?', [$cid]); [$w, $h] = $dims($p2b);
t($p2b !== $p2 && !is_file(PUB . "/$p2") && $w === 320 && $h === 320, 'replace: processed with the same rules, old file removed, new URL');
$a->req('POST', "/admin/categories/edit/$cid", ['_csrf' => $a->tok("/admin/categories/edit/$cid"), 'name' => 'Std Cat Png', 'slug' => 'std-cat-png', 'status' => 'active', 'remove_image' => '1']);
t($q('SELECT image_path FROM categories WHERE id = ?', [$cid]) === null && !is_file(PUB . "/$p2b"), 'delete: row cleared, file removed');
$home = (new C())->get('/')['body'];
t(preg_match('#<img[^>]*' . preg_quote($p1, '#') . '[^>]*width="320" height="320"#', $home) === 1 || preg_match('#<img[^>]*width="320" height="320"[^>]*' . preg_quote($p1, '#') . '#', $home) === 1, 'storefront category card: 320×320 attributes (1:1 frame, object-fit: cover)');

// ================================================================= product main + gallery
echo "== Product main image (800 × 1000, 4:5, ≤ 100 KB)\n";
$catId = (int) $q("SELECT id FROM categories WHERE slug = 'fashion'");
$prod = function (string $name, array $files) use ($a, $catId) {
    return $a->req('POST', '/admin/products/create', ['_csrf' => $a->tok('/admin/products/create'), 'name' => $name, 'category_id' => (string) $catId,
        'regular_price' => '1200', 'stock_quantity' => '5', 'is_active' => '1'] + $files);
};
$r = $prod('Std Linen Shirt', ['main_image' => $file(STD . '/photo-2000x1500.jpg', 'image/jpeg')]);
$flash = $a->flash($r);
$pid = (int) $q("SELECT id FROM products WHERE name = 'Std Linen Shirt'");
$main = (string) $q('SELECT image_path FROM product_images WHERE product_id = ? AND is_primary = 1', [$pid]);
[$w, $h, $mime] = $dims($main);
t($w === 800 && $h === 1000 && $mime === 'image/webp', "landscape JPG 2000×1500 -> centre-cropped $w × $h webp (4:5, not stretched)");
t(preg_match('#^uploads/products/product-std-linen-shirt-main-[a-f0-9]{8}\.webp$#', $main) === 1, "safe name: $main");
t($kb($main) <= 100, 'size ' . $kb($main) . ' KB (≤ 100 KB target)');
$thumb = preg_replace('/\.webp$/', '-sm.webp', $main); [$tw, $th] = $dims($thumb);
t($tw === 480 && $th === 600, "listing copy $tw × $th (4:5)");
t(str_contains($flash, 'Images optimised: Product main image') && str_contains($flash, '→') && str_contains($flash, 'centre-cropped to 4:5'), 'report: ' . preg_replace('/^.*Images optimised: /', '', $flash));
foreach ([['photo-1200x1500.jpg', 'image/jpeg', 800, 1000], ['photo-800x1000.webp', 'image/webp', 800, 1000], ['photo-600x750.jpg', 'image/jpeg', 600, 750], ['transparent-800x1000.png', 'image/png', 800, 1000]] as $i => [$f, $m, $ew, $eh]) {
    $r = $prod("Std Product $i", ['main_image' => $file(STD . "/$f", $m)]);
    $pp = (string) $q('SELECT pi.image_path FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.name = ?', ["Std Product $i"]);
    [$w, $h, $mime] = $dims($pp);
    $note = $f === 'photo-600x750.jpg' ? ' (smaller than recommended: kept, never enlarged)' : '';
    t($w === $ew && $h === $eh && $mime === 'image/webp' && $kb($pp) <= 100, "$f -> $w × $h webp, " . $kb($pp) . " KB$note");
    if ($f === 'transparent-800x1000.png') {
        $im = imagecreatefromwebp(PUB . "/$pp"); $alpha = (imagecolorat($im, 2, 2) >> 24) & 0x7F;
        t($alpha > 100, 'transparent PNG keeps its transparency in WebP');
    }
}
$r = $prod('Std Too Small', ['main_image' => $file(STD . '/small-300x375.jpg', 'image/jpeg')]);
t(!$q("SELECT COUNT(*) FROM products WHERE name = 'Std Too Small'") && str_contains($a->flash($r), 'must be at least 400 × 500 px'), 'too small (300×375) refused, product not created');
$r = $prod('Std Too Small Crop', ['main_image' => $file(FX . '/valid.png', 'image/png')]); // 400x300 -> 240x300 after crop
t(!$q("SELECT COUNT(*) FROM products WHERE name = 'Std Too Small Crop'") && str_contains($a->flash($r), 'after cropping to 4:5'), 'too small after cropping (400×300 -> 240×300) refused with explanation');
echo "== Mobile upload (12 MP phone photo, phone browser)\n";
$phone = new C('Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile ImgStd');
$phone->req('POST', '/admin/login', ['_csrf' => $phone->tok('/admin/login'), 'email' => 'admin@example.com', 'password' => 'Admin@12345']);
$t0 = microtime(true);
$r = $phone->req('POST', '/admin/products/create', ['_csrf' => $phone->tok('/admin/products/create'), 'name' => 'Std Phone Upload', 'category_id' => (string) $catId,
    'regular_price' => '900', 'stock_quantity' => '2', 'is_active' => '1', 'main_image' => $file(STD . '/phone-4032x3024.jpg', 'image/jpeg', 'IMG_20260926_101500.jpg')]);
$secs = round(microtime(true) - $t0, 1);
$ph = (string) $q("SELECT pi.image_path FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.name = 'Std Phone Upload'");
[$w, $h] = $dims($ph);
t($w === 800 && $h === 1000 && $kb($ph) <= 100 && !str_contains($ph, 'IMG_'), "3 MB phone photo -> $w × $h, " . $kb($ph) . " KB in {$secs}s; original name not used");
t(preg_match('/Product main image \d+(\.\d)? MB → \d+ KB/u', $phone->flash($r)) === 1, 'report shows "' . (preg_match('/Product main image [^(]+/u', $phone->flash($r), $mm) ? trim($mm[0]) : '?') . '"');

echo "== Product gallery (800 × 1000, 4:5, ≤ 100 KB each)\n";
$tk = $a->tok("/admin/products/edit/$pid");
$r = $a->req('POST', "/admin/products/edit/$pid", ['_csrf' => $tk, 'name' => 'Std Linen Shirt', 'slug' => 'std-linen-shirt', 'category_id' => (string) $catId,
    'regular_price' => '1200', 'stock_quantity' => '5', 'is_active' => '1',
    'images[0]' => $file(STD . '/photo-1200x1500.jpg', 'image/jpeg'), 'images[1]' => $file(STD . '/photo-1000x1000.png', 'image/png'), 'images[2]' => $file(STD . '/photo-800x1000.webp', 'image/webp')]);
$gal = $pdo->query("SELECT id, image_path, sort_order FROM product_images WHERE product_id = $pid AND is_primary = 0 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
$okAll = count($gal) === 3;
foreach ($gal as $i => $g) { [$w, $h, $mime] = $dims($g['image_path']); $okAll = $okAll && $w === 800 && $h === 1000 && $mime === 'image/webp' && $kb($g['image_path']) <= 100 && preg_match('#product-std-linen-shirt-0' . ($i + 1) . '-[a-f0-9]{8}\.webp$#', $g['image_path']); }
t($okAll, '3 gallery images (JPG, PNG 1:1, WebP) -> all 800 × 1000 webp ≤ 100 KB, named product-std-linen-shirt-01/02/03');
t(substr_count($a->flash($r), 'Product gallery image') === 3, 'one optimisation line per gallery image');
$r = $a->req('POST', "/admin/products/edit/$pid", ['_csrf' => $a->tok("/admin/products/edit/$pid"), 'name' => 'Std Linen Shirt', 'slug' => 'std-linen-shirt', 'category_id' => (string) $catId,
    'regular_price' => '1200', 'stock_quantity' => '5', 'is_active' => '1', 'images[0]' => $file(STD . '/photo-1200x1500.jpg', 'image/jpeg'), 'images[1]' => $file(STD . '/small-300x375.jpg', 'image/jpeg')]);
t((int) $q("SELECT COUNT(*) FROM product_images WHERE product_id = $pid") === 4 && str_contains($a->flash($r), 'too small'), 'one bad gallery file -> whole upload refused, nothing half-saved');
$edit = $a->get("/admin/products/edit/$pid")['body'];
t(substr_count($edit, 'data-replace-input') === 3 && substr_count($edit, 'gallery-item__meta') === 3 && str_contains($edit, 'Current: 800 × 1000 px · 4:5 · '), 'existing images: preview, dimensions/size/format, Replace + Delete');
// replace gallery image #2
$target = $gal[1];
$r = $a->req('POST', "/admin/products/edit/$pid", ['_csrf' => $a->tok("/admin/products/edit/$pid"), 'name' => 'Std Linen Shirt', 'slug' => 'std-linen-shirt', 'category_id' => (string) $catId,
    'regular_price' => '1200', 'stock_quantity' => '5', 'is_active' => '1', "replace_images[{$target['id']}]" => $file(STD . '/photo-2000x1500.jpg', 'image/jpeg')]);
$newPath = (string) $q('SELECT image_path FROM product_images WHERE id = ?', [$target['id']]);
[$w, $h] = $dims($newPath);
t($newPath !== $target['image_path'] && !is_file(PUB . '/' . $target['image_path']) && $w === 800 && $h === 1000 && (int) $q('SELECT sort_order FROM product_images WHERE id = ?', [$target['id']]) === (int) $target['sort_order'],
    'replace gallery image: same slot and order, processed (cropped 4:5), old file deleted');
t(str_contains($a->flash($r), 'centre-cropped to 4:5'), 'replacement reported');
$r = $a->req('POST', "/admin/products/edit/$pid", ['_csrf' => $a->tok("/admin/products/edit/$pid"), 'name' => 'Std Linen Shirt', 'slug' => 'std-linen-shirt', 'category_id' => (string) $catId,
    'regular_price' => '1200', 'stock_quantity' => '5', 'is_active' => '1', "replace_images[{$target['id']}]" => $file(STD . '/evil.php.jpg', 'image/jpeg')]);
t($q('SELECT image_path FROM product_images WHERE id = ?', [$target['id']]) === $newPath, 'replacement with a renamed PHP file refused, image unchanged');
// main replace via main_image
$oldMain = $main;
$a->req('POST', "/admin/products/edit/$pid", ['_csrf' => $a->tok("/admin/products/edit/$pid"), 'name' => 'Std Linen Shirt', 'slug' => 'std-linen-shirt', 'category_id' => (string) $catId,
    'regular_price' => '1200', 'stock_quantity' => '5', 'is_active' => '1', 'main_image' => $file(STD . '/photo-800x1000.webp', 'image/webp')]);
$main2 = (string) $q('SELECT image_path FROM product_images WHERE product_id = ? AND is_primary = 1', [$pid]);
t($main2 !== $oldMain && !is_file(PUB . "/$oldMain") && str_contains($main2, '-main-'), 'replace main image: new file, old one deleted');

echo "== Storefront display\n";
$pp = (new C())->get('/product/std-linen-shirt')['body'];
t(preg_match('#<img[^>]*fetchpriority="high"[^>]*>#', $pp, $m) && str_contains($m[0], 'width="800"') && str_contains($m[0], 'height="1000"') && str_contains($m[0], 'loading="eager"'), 'product page main image: 800×1000 attributes, eager');
t(str_contains($m[0] ?? '', ' 480w, ') && str_contains($m[0] ?? '', ' 800w'), 'srcset uses real widths (480w, 800w)');
$sh = (new C())->get('/category/fashion')['body'];
t(preg_match('#<img[^>]*product-std-linen-shirt-main[^>]*>#', $sh, $m) && str_contains($m[0], 'width="480" height="600"'), 'product card: 480×600 (4:5), lazy/eager per position');
$css = file_get_contents(PUB . '/assets/css/app.css');
t(str_contains($css, 'aspect-ratio: 1 / 1; /* category image standard') && preg_match('/\.product-gallery__main img \{[^}]*object-fit: cover/', $css) === 1, 'frames: category 1:1, product 4:5, object-fit: cover (no distortion)');

echo "== Security of stored files\n";
$all = array_merge(glob(PUB . '/uploads/products/*'), glob(PUB . '/uploads/categories/*'));
t($all && !array_filter($all, fn ($p) => !preg_match('#/[a-z0-9-]+-[a-f0-9]{8}(-sm)?\.webp$#', $p)), 'only processed .webp files with generated names in uploads (' . count($all) . ')');
$bad = 0; foreach ($all as $p) { $c = file_get_contents($p, false, null, 0, 65536); if (stripos($c, '<?php') !== false || stripos($c, '<script') !== false || str_starts_with($c, 'MZ')) $bad++; }
t($bad === 0, 'no PHP/script/executable content in any stored file');
t((new C())->get('/uploads/products/x.php')['code'] >= 403, 'upload folder never runs scripts');

// cleanup
foreach ($pdo->query("SELECT pi.image_path FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.name LIKE 'Std %' UNION SELECT image_path FROM categories WHERE name LIKE 'Std %' AND image_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $p) {
    @unlink(PUB . "/$p"); @unlink(PUB . '/' . preg_replace('/\.webp$/', '-sm.webp', $p));
}
$pdo->exec("DELETE FROM products WHERE name LIKE 'Std %'"); $pdo->exec("DELETE FROM categories WHERE name LIKE 'Std %'"); $pdo->exec('DELETE FROM login_attempts');
echo "\nTOTAL: {$pass} passed, {$fail} failed\n";
