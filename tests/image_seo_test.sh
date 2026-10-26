#!/bin/bash
# Phase 10 - real photo upload: WebP, dimensions, srcset, lazy loading, SEO image fields
B=http://localhost:8080
UA="Mozilla/5.0 AaltoTest"
MYSQL="/c/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe"
PHP=/c/xampp/php/php.exe
DIR="$(cygpath -m "$(cd "$(dirname "$0")" && pwd)")"
PUB="/d/SDL-PROJECT/AALTO-ECOM/public"
J="$DIR/ijar.txt"; rm -f "$J"
pass=0; fail=0
sql()  { "$MYSQL" -uroot -p1234 aalto_mart_dev -N --default-character-set=utf8mb4 -e "$1" 2>/dev/null | sed 's/[[:cntrl:]]*$//'; }
t()    { if [ "$1" = "$2" ]; then echo "PASS $3"; pass=$((pass+1)); else echo "FAIL $3 (got '$1', want '$2')"; fail=$((fail+1)); fi; }
code() { curl -s -A "$UA" -b "$J" -c "$J" -o /dev/null -w "%{http_code} %{redirect_url}" "$@"; }
page() { curl -s -A "$UA" -b "$J" -c "$J" "$@"; }
tok()  { printf '%s' "$1" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

# A photo-like 1800x2250 JPEG (gradient + noise, ~1 MB) - bigger than the 800x1000 product standard
PHOTO="$DIR/fixtures/photo-1800x2250.jpg"
$PHP -r '$w=1800;$h=2250;$im=imagecreatetruecolor($w,$h);mt_srand(7);
for($y=0;$y<$h;$y+=2)for($x=0;$x<$w;$x+=2){$n=mt_rand(-18,18);$c=imagecolorallocate($im,max(0,min(255,200-$y/15+$n)),max(0,min(255,170-$x/20+$n)),max(0,min(255,140+$n)));imagefilledrectangle($im,$x,$y,$x+1,$y+1,$c);}
imagejpeg($im,$argv[1],88);' "$(cygpath -w "$PHOTO")"
echo "fixture: $(wc -c < "$PHOTO") bytes"

LT=$(tok "$(page $B/admin/login)")
code -X POST --data-urlencode "_csrf=$LT" -d "email=admin@example.com&password=Admin@12345" $B/admin/login >/dev/null
T=$(tok "$(page $B/admin/products/create)")
R=$(code -F "_csrf=$T" -F "name=QA Photo Tee" -F "category_id=2" -F "regular_price=1500" -F "stock_quantity=5" -F "is_active=1" \
     -F "main_image=@$PHOTO;type=image/jpeg" $B/admin/products/create)
ID=$(sql "SELECT id FROM products WHERE name='QA Photo Tee'")
SLUG=$(sql "SELECT slug FROM products WHERE id=$ID")
IMG=$(sql "SELECT image_path FROM product_images WHERE product_id=$ID AND is_primary=1")
t "$([ -n "$ID" ] && echo 1)" 1 "product with photo created ($R)"
t "$(printf '%s' "$IMG" | grep -cE '^uploads/products/product-qa-photo-tee-main-[a-f0-9]{8}\.webp$')" 1 "stored as WebP with a safe generated name"
DIMS=$($PHP -r '$s=getimagesize($argv[1]);echo $s[0],"x",$s[1]," ",$s["mime"];' "$(cygpath -w "$PUB/$IMG")")
t "$DIMS" "800x1000 image/webp" "main image: product standard 800x1000 (4:5)"
SM="${IMG%.webp}-sm.webp"
t "$($PHP -r '$s=getimagesize($argv[1]);echo $s[0],"x",$s[1];' "$(cygpath -w "$PUB/$SM")")" "480x600" "list thumbnail 480x600 generated"
BIG=$(wc -c < "$PUB/$IMG"); SMALL=$(wc -c < "$PUB/$SM")
echo "   -> original $(wc -c < "$PHOTO") B, main WebP $BIG B, thumb WebP $SMALL B"
t "$([ "$BIG" -lt 400000 ] && echo 1)" 1 "main WebP well under the upload (< 400 KB)"
t "$([ "$SMALL" -lt 80000 ] && echo 1)" 1 "thumbnail < 80 KB"

P=$(page "$B/product/$SLUG")
t "$(printf '%s' "$P" | grep -c "srcset=\"$B/$SM 480w, $B/$IMG 800w\"")" 1 "product page: srcset uses real widths (480w, 800w)"
t "$(printf '%s' "$P" | grep -o '<img[^>]*fetchpriority="high"[^>]*>' | grep -c 'loading="eager"')" 1 "product page: main image eager"
t "$(printf '%s' "$P" | grep -o '"image":\["[^"]*"\]' | head -1)" "\"image\":[\"$B/$IMG\"]" "JSON-LD image = uploaded photo"
t "$(printf '%s' "$P" | grep -o '<meta property="og:image" content="[^"]*"' | sed 's/.*content="//;s/"$//')" "$B/$IMG" "og:image = uploaded photo"
t "$(printf '%s' "$P" | grep -c 'og:image:width')" 0 "no fixed size claimed for product photos"
S=$(page "$B/category/fashion")
t "$(printf '%s' "$S" | grep -o "<img[^>]*$SM[^>]*>" | grep -c 'alt="QA Photo Tee"')" 1 "card: thumbnail src, alt = name"
t "$(printf '%s' "$S" | grep -o "<img[^>]*$SM[^>]*>" | grep -c 'sizes="(min-width: 1400px) 300px')" 1 "card: sizes attribute"
t "$(page $B/sitemap.xml | grep -c "<image:loc>$B/$IMG</image:loc>")" 1 "sitemap lists the photo"
t "$(page $B/sitemap.xml | grep -c 'placeholder-product.svg')" 0 "sitemap omits placeholders"
H=$(curl -s -D - -o /dev/null "$B/$IMG" | tr -d '\r' | grep -i '^content-type' | sed 's/.*: //')
t "$H" "image/webp" "photo served as image/webp"

# category image: gets a list thumbnail used by the homepage card
T=$(tok "$(page $B/admin/categories/create)")
code -F "_csrf=$T" -F "name=QA Photo Cat" -F "status=active" -F "image=@$PHOTO;type=image/jpeg" $B/admin/categories/create >/dev/null
CID=$(sql "SELECT id FROM categories WHERE name='QA Photo Cat'")
CIMG=$(sql "SELECT image_path FROM categories WHERE id=$CID"); CSM="${CIMG%.webp}-sm.webp"
t "$($PHP -r '$s=getimagesize($argv[1]);echo $s[0],"x",$s[1];' "$(cygpath -w "$PUB/$CIMG")")" "320x320" "category image: 320x320 (category standard, no extra copy needed)"
t "$(page $B/ | grep -o "<img[^>]*$CIMG[^>]*>" | grep -c 'width="320" height="320"')" 1 "home category card: 320x320 image, 1:1"
T=$(tok "$(page $B/admin/categories)")
code -F "_csrf=$T" $B/admin/categories/delete/$CID >/dev/null
t "$(ls "$PUB/$CIMG" "$PUB/$CSM" 2>/dev/null | wc -l | tr -d " ")" 0 "category delete removes both files"

# cleanup: delete the product (removes its files)
T=$(tok "$(page $B/admin/products)")
code -F "_csrf=$T" $B/admin/products/delete/$ID >/dev/null
t "$(ls "$PUB/$IMG" "$PUB/$SM" 2>/dev/null | wc -l | tr -d ' ')" 0 "cleanup: files removed with the product"
sql "DELETE FROM products WHERE name LIKE 'QA %'"; sql "DELETE FROM login_attempts"
rm -f "$J" "$PHOTO"
echo; echo "TOTAL: $pass passed, $fail failed"
