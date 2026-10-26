#!/bin/bash
# Phase 4 - product management HTTP test suite
B=http://localhost:8080
UA="Mozilla/5.0 AaltoTest"
MYSQL="/c/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe"
DIR="$(cygpath -m "$(cd "$(dirname "$0")" && pwd)")"
FX="$DIR/fixtures"
PUB="/d/SDL-PROJECT/AALTO-ECOM/public"
UP="$PUB/uploads/products"
J="$DIR/pjar.txt"; rm -f "$J"
pass=0; fail=0

sql()  { "$MYSQL" -uroot -p1234 aalto_mart_dev -N --default-character-set=utf8mb4 -e "$1" 2>/dev/null | sed 's/[[:cntrl:]]*$//'; }
t()    { if [ "$1" = "$2" ]; then echo "PASS $3"; pass=$((pass+1)); else echo "FAIL $3 (got '$1', want '$2')"; fail=$((fail+1)); fi; }
ok()   { if [ "$1" = 1 ]; then t 1 1 "$2"; else t 0 1 "$2"; fi; }
has()  { if printf '%s' "$1" | grep -qF -- "$2"; then echo 1; else echo 0; fi; }
code() { curl -s -A "$UA" -b "$J" -c "$J" -o /dev/null -w "%{http_code} %{redirect_url}" "$@"; }
page() { curl -s -A "$UA" -b "$J" -c "$J" "$@"; }
tok()  { printf '%s' "$1" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
files(){ ls "$UP" 2>/dev/null | wc -l | tr -d ' '; }
pid()  { sql "SELECT id FROM products WHERE name = '$1'"; }
row()  { sql "SELECT CONCAT_WS('|', regular_price, IFNULL(sale_price,'NULL'), price, stock_quantity) FROM products WHERE name = '$1'"; }
lasterr() { page $B/admin/products/create | grep -o 'class="invalid-feedback">[^<]*' | sed 's/.*>//' | head -3 | tr '\n' ' '; }
T=""
# create NAME [extra -F args]: sensible defaults, override by passing the same field again (last wins)
create() { local n="$1"; shift; code -F "_csrf=$T" -F "name=$n" -F "category_id=1" -F "regular_price=1000" -F "stock_quantity=5" -F "is_active=1" "$@" "$B/admin/products/create"; }
edit()   { local id="$1"; shift; code -F "_csrf=$T" "$@" "$B/admin/products/edit/$id"; }
editbase() { local id="$1"; shift; edit "$id" -F "name=$(sql "SELECT name FROM products WHERE id=$id")" -F "category_id=1" -F "regular_price=1000" -F "stock_quantity=5" -F "is_active=1" "$@"; }

cleanup() {
    sql "SELECT pi.image_path FROM product_images pi JOIN products p ON p.id=pi.product_id WHERE p.name LIKE 'QA %'" |
        while IFS= read -r p; do rm -f "$PUB/$p" "$PUB/${p%.webp}-sm.webp"; done
    sql "DELETE oi FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.order_number LIKE 'QA-%'"
    sql "DELETE FROM orders WHERE order_number LIKE 'QA-%'"
    sql "DELETE FROM products WHERE name LIKE 'QA %' OR sku LIKE 'QA-%'"
    sql "DELETE FROM login_attempts"
}
cleanup
FILES0=$(files)

echo "== Unauthorized access"
for p in products products/create products/edit/1 products/edit; do
    t "$(code $B/admin/$p)" "302 $B/admin/login" "GET /admin/$p signed out -> login"
done
t "$(code -F name='QA Anon' -F category_id=1 -F regular_price=1 -F stock_quantity=1 $B/admin/products/create)" "302 $B/admin/login" "POST create signed out"
t "$(code -F name='QA Anon' $B/admin/products/edit/1)" "302 $B/admin/login" "POST edit signed out"
t "$(code -F flag=featured $B/admin/products/toggle/1)" "302 $B/admin/login" "POST toggle signed out"
t "$(code -X POST $B/admin/products/delete/1)" "302 $B/admin/login" "POST delete signed out"
t "$(pid 'QA Anon')|$(sql 'SELECT CONCAT(name,is_featured) FROM products WHERE id=1')" "|Sample Wireless Earbuds1" "nothing changed by signed-out requests"

LT=$(tok "$(page $B/admin/login)")
code -X POST --data-urlencode "_csrf=$LT" -d "email=admin@example.com&password=Admin@12345" $B/admin/login >/dev/null
t "$(code $B/admin/products)" "200 " "signed in: product list loads"
T=$(tok "$(page $B/admin/products/create)")

echo "== CSRF"
t "$(code -F name='QA NoToken' -F category_id=1 -F regular_price=1 -F stock_quantity=1 $B/admin/products/create)" "302 $B/admin/products/create" "create without token refused"
t "$(code -F _csrf=bad -F name='QA Edit' $B/admin/products/edit/1)" "302 $B/admin/products/edit/1" "edit with bad token refused"
t "$(code -F _csrf=bad -F flag=featured $B/admin/products/toggle/1)" "302 $B/admin/products" "toggle with bad token refused"
t "$(curl -s -A "$UA" -b "$J" -H 'Accept: application/json' -o /dev/null -w '%{http_code}' -F _csrf=bad -F flag=featured $B/admin/products/toggle/1)" "403" "JSON toggle with bad token -> 403"
t "$(code -F _csrf=bad $B/admin/products/delete/2)" "302 $B/admin/products" "delete with bad token refused"
t "$(pid 'QA NoToken')|$(sql 'SELECT CONCAT(name,is_featured) FROM products WHERE id=1')|$(sql 'SELECT COUNT(*) FROM products WHERE id=2')" "|Sample Wireless Earbuds1|1" "no changes after CSRF failures"

echo "== Create product"
R=$(create 'QA Basic' -F 'short_description=Short text' -F 'description=Line one
Line two')
ID=$(pid 'QA Basic')
t "$R" "302 $B/admin/products/edit/$ID" "create redirects to edit page"
t "$(sql "SELECT CONCAT_WS('|', slug, category_id, short_description, is_active, is_featured, is_new) FROM products WHERE id=$ID")" "qa-basic|1|Short text|1|0|0" "fields and flags saved"
ok "$(sql "SELECT sku REGEXP '^AM-[2-9A-HJ-NP-Z]{8}$' FROM products WHERE id=$ID")" "SKU auto-generated ($(sql "SELECT sku FROM products WHERE id=$ID"))"
t "$(sql "SELECT REPLACE(description, CHAR(10), '<NL>') FROM products WHERE id=$ID")" "Line one<NL>Line two" "multi-line description kept"
create 'QA Flags' -F 'is_featured=1' -F 'is_new=1' >/dev/null
t "$(sql "SELECT CONCAT(is_active,is_featured,is_new) FROM products WHERE name='QA Flags'")" "111" "featured + new saved"
create 'QA Inactive' -F 'is_active=' >/dev/null
t "$(sql "SELECT is_active FROM products WHERE name='QA Inactive'")" "0" "created inactive when unchecked"
create 'QA Custom Sku' -F 'sku=QA-SKU-001' -F 'slug=QA Custom Slug' >/dev/null
t "$(sql "SELECT CONCAT(sku,'|',slug) FROM products WHERE name='QA Custom Sku'")" "QA-SKU-001|qa-custom-slug" "custom SKU and normalised slug"

echo "== Prices (DECIMAL, no floats)"
create 'QA Price Sale' -F 'regular_price=1299' -F 'sale_price=999.50' >/dev/null
t "$(row 'QA Price Sale')" "1299.00|999.50|999.50|5" "sale price used when lower"
create 'QA Price Zero' -F 'regular_price=1299' -F 'sale_price=0' >/dev/null
t "$(row 'QA Price Zero')" "1299.00|NULL|1299.00|5" "sale 0 -> no sale, regular price shown"
create 'QA Price Empty' -F 'regular_price=1299' -F 'sale_price=' >/dev/null
t "$(row 'QA Price Empty')" "1299.00|NULL|1299.00|5" "empty sale -> regular price"
create 'QA Price Commas' -F 'regular_price=1,234,567.8' >/dev/null
t "$(row 'QA Price Commas')" "1234567.80|NULL|1234567.80|5" "1,234,567.8 stored exactly"
create 'QA Price Cents' -F 'regular_price=0.30' -F 'sale_price=0.10' >/dev/null
t "$(row 'QA Price Cents')" "0.30|0.10|0.10|5" "cent amounts exact (0.30 / 0.10)"
create 'QA Price Max' -F 'regular_price=9999999.99' >/dev/null
t "$(row 'QA Price Max')" "9999999.99|NULL|9999999.99|5" "maximum 9,999,999.99 accepted"
sql "UPDATE products SET is_new=1 WHERE name IN ('QA Price Sale','QA Price Zero')"
L=$(curl -s $B/)
CARD=$(printf '%s' "$L" | tr -d '\n' | sed 's#</article>#</article>\n#g' | grep 'QA Price Sale')
ok "$([ "$(has "$CARD" 'Sale price </span>৳999.50')" = 1 ] && [ "$(has "$CARD" 'Regular price </span>৳1,299.00</del>')" = 1 ] && [ "$(has "$CARD" '-23%')" = 1 ] && echo 1 || echo 0)" "card: sale ৳999.50, regular ৳1,299.00 struck, -23%"
CARD=$(printf '%s' "$L" | tr -d '\n' | sed 's#</article>#</article>\n#g' | grep 'QA Price Zero')
ok "$([ "$(has "$CARD" '<span class="product-card__price">৳1,299.00')" = 1 ] && [ "$(has "$CARD" '<del')" = 0 ] && echo 1 || echo 0)" "card: sale 0 shows regular ৳1,299.00, nothing struck"

echo "== Stock"
create 'QA Stock Zero' -F 'stock_quantity=0' >/dev/null
t "$(sql "SELECT stock_quantity FROM products WHERE name='QA Stock Zero'")" "0" "stock 0 allowed"
create 'QA Stock Max' -F 'stock_quantity=1000000' >/dev/null
t "$(sql "SELECT stock_quantity FROM products WHERE name='QA Stock Max'")" "1000000" "stock 1,000,000 allowed"
create 'QA Stock Lead' -F 'stock_quantity=007' >/dev/null
t "$(sql "SELECT stock_quantity FROM products WHERE name='QA Stock Lead'")" "7" "leading zeros -> 7"

echo "== Invalid data (nothing saved)"
BEFORE=$(sql "SELECT COUNT(*) FROM products")
check_invalid() { # label, expected message, curl args...
    local label="$1" msg="$2"; shift 2
    local r; r=$(create "QA Bad $label" "$@")
    local p; p=$(page $B/admin/products/create)
    ok "$([ "$r" = "302 $B/admin/products/create" ] && [ "$(has "$p" "$msg")" = 1 ] && [ -z "$(pid "QA Bad $label")" ] && echo 1 || echo 0)" "$label -> \"$msg\""
}
code -F "_csrf=$T" -F 'name=' -F category_id=1 -F regular_price=1 -F stock_quantity=1 $B/admin/products/create >/dev/null
ok "$(has "$(page $B/admin/products/create)" 'Enter a product name.')" "empty name -> \"Enter a product name.\""
check_invalid "no category"      "Choose a category."          -F 'category_id='
check_invalid "unknown category" "Choose a category."          -F 'category_id=99999'
check_invalid "text category"    "Choose a category."          -F 'category_id=1 OR 1=1'
check_invalid "no price"         "Enter the regular price."    -F 'regular_price='
check_invalid "text price"       "Enter a price like"          -F 'regular_price=abc'
check_invalid "negative price"   "Price cannot be negative."   -F 'regular_price=-5'
check_invalid "zero price"       "must be more than 0"         -F 'regular_price=0'
check_invalid "3 decimals"       "Enter a price like"          -F 'regular_price=10.999'
check_invalid "exponent"         "Enter a price like"          -F 'regular_price=1e3'
check_invalid "too big"          "Enter a price like"          -F 'regular_price=10000000'
check_invalid "sale equal"       "Sale price must be lower"    -F 'regular_price=100' -F 'sale_price=100'
check_invalid "sale higher"      "Sale price must be lower"    -F 'regular_price=100' -F 'sale_price=150.00'
check_invalid "sale negative"    "Sale price cannot be negative." -F 'sale_price=-1'
check_invalid "sale text"        "Enter a sale price like"     -F 'sale_price=free'
check_invalid "no stock"         "Enter the stock quantity"    -F 'stock_quantity='
check_invalid "negative stock"   "Stock cannot be negative."   -F 'stock_quantity=-1'
check_invalid "decimal stock"    "whole number"                -F 'stock_quantity=1.5'
check_invalid "text stock"       "whole number"                -F 'stock_quantity=ten'
check_invalid "huge stock"       "cannot be more than 1,000,000" -F 'stock_quantity=1000001'
check_invalid "giant stock"      "cannot be more than 1,000,000" -F 'stock_quantity=99999999999999999999999'
check_invalid "long short desc"  "Short description must be 500" -F "short_description=$(printf 'x%.0s' $(seq 1 501))"
check_invalid "bad sku"          "SKU may use letters"         -F 'sku=bad sku!'
check_invalid "dup sku"          "is already used by another product" -F 'sku=qa-sku-001'
check_invalid "dup slug"         "is already used by another product" -F 'slug=sample-smart-watch'
check_invalid "long name $(printf 'n%.0s' $(seq 1 200))" "200 characters or fewer"
printf 'QA Bad \xe9\xff' > "$DIR/bad.txt"
code -F "_csrf=$T" -F "name=<$DIR/bad.txt" -F category_id=1 -F regular_price=1 -F stock_quantity=1 $B/admin/products/create >/dev/null
ok "$(has "$(page $B/admin/products/create)" 'invalid characters')" "malformed UTF-8 name rejected"
t "$(sql "SELECT COUNT(*) FROM products")" "$BEFORE" "no invalid product saved"
create 'QA Bad Keep' -F 'regular_price=abc' -F 'short_description=Keep me' -F 'is_featured=1' >/dev/null
P=$(page $B/admin/products/create)
ok "$([ "$(has "$P" 'value="QA Bad Keep"')" = 1 ] && [ "$(has "$P" '>Keep me</textarea>')" = 1 ] && [ "$(has "$P" 'id="is_featured" name="is_featured" value="1" checked')" = 1 ] && echo 1 || echo 0)" "typed values and switches kept after error"
create 'QA Dup Name' >/dev/null; create 'QA Dup Name' >/dev/null
t "$(sql "SELECT GROUP_CONCAT(slug ORDER BY id) FROM products WHERE name='QA Dup Name'")" "qa-dup-name,qa-dup-name-2" "same name twice -> unique slugs"

echo "== Images"
create 'QA Img' -F "main_image=@$FX/valid.jpg;type=image/jpeg" -F "images[]=@$FX/std/photo-1000x1000.png;type=image/png" -F "images[]=@$FX/valid.webp;type=image/webp" >/dev/null
IID=$(pid 'QA Img')
t "$(sql "SELECT COUNT(*) FROM product_images WHERE product_id=$IID")" "3" "main + 2 additional images saved"
MAIN=$(sql "SELECT image_path FROM product_images WHERE product_id=$IID AND is_primary=1")
t "$(sql "SELECT COUNT(*) FROM product_images WHERE product_id=$IID AND is_primary=1")" "1" "exactly one main image"
ok "$(printf '%s' "$MAIN" | grep -cE '^uploads/products/product-qa-img-main-[a-f0-9]{8}\.webp$')" "main stored as random-named WebP"
t "$(/c/xampp/php/php.exe -r '$a=getimagesize($argv[1]);$b=getimagesize($argv[2]);echo $a["mime"]," ",$a[0],"x",$a[1]," / ",$b[0],"x",$b[1];' "$PUB/$MAIN" "$PUB/${MAIN%.webp}-sm.webp")" "image/webp 800x1000 / 480x600" "main: centre-cropped 4:5, 800x1000 + 480x600 listing copy (product standard)"
t "$(sql "SELECT alt_text FROM product_images WHERE product_id=$IID AND is_primary=1")" "QA Img" "alt text = product name"
create 'QA Extras Only' -F "images[]=@$FX/std/photo-1000x1000.png;type=image/png" -F "images[]=@$FX/valid.webp;type=image/webp" >/dev/null
EID=$(pid 'QA Extras Only')
t "$(sql "SELECT COUNT(*) FROM product_images WHERE product_id=$EID AND is_primary=1")" "1" "without a main image the first extra becomes main"
F0=$(files)
create 'QA Img Fake' -F "main_image=@$FX/valid.jpg;type=image/jpeg" -F "images[]=@$FX/std/photo-1000x1000.png;type=image/png" -F "images[]=@$FX/fake.jpg;type=image/jpeg" >/dev/null
ok "$([ -z "$(pid 'QA Img Fake')" ] && [ "$(has "$(page $B/admin/products/create)" 'fake.jpg')" = 1 ] && echo 1 || echo 0)" "fake image in extras rejects the whole save"
t "$(files)" "$F0" "...and the valid images from that request were cleaned up"
for f in "png-named.jpg:image/jpeg:does not match" "image.gif:image/gif:Only JPG" "image.svg:image/svg+xml:Only JPG" "big.jpg:image/jpeg:too large" "corrupt.jpg:image/jpeg:could not be read" "shell.php:image/png:Only JPG"; do
    IFS=: read -r file mime msg <<< "$f"
    create "QA Main $file" -F "main_image=@$FX/$file;type=$mime" >/dev/null
    ok "$([ -z "$(pid "QA Main $file")" ] && [ "$(has "$(page $B/admin/products/create)" "$msg")" = 1 ] && echo 1 || echo 0)" "main image $file rejected ($msg)"
done
NINE=(); for i in 1 2 3 4 5 6 7 8 9; do NINE+=(-F "images[]=@$FX/valid.webp;type=image/webp"); done
create 'QA Nine' "${NINE[@]}" >/dev/null
ok "$([ -z "$(pid 'QA Nine')" ] && [ "$(has "$(page $B/admin/products/create)" 'up to 8 additional images')" = 1 ] && echo 1 || echo 0)" "9 additional images rejected (limit 8)"
t "$(files)" "$F0" "no files left behind by rejected uploads"
ok "$(has "$(curl -s $B/admin/products -b "$J" -A "$UA")" 'loading="lazy"')" "list thumbnails lazy-loaded"
ok "$(has "$(curl -s $B/)" 'loading="lazy" decoding="async"')" "storefront product images lazy-loaded"

echo "== Edit product"
OLD=$(sql "SELECT updated_at FROM products WHERE id=$ID"); SKU=$(sql "SELECT sku FROM products WHERE id=$ID"); sleep 1
R=$(edit $ID -F 'name=QA Basic Edited' -F 'category_id=2' -F 'regular_price=2500' -F 'sale_price=1999.99' -F 'stock_quantity=42' -F 'sku=' -F 'is_featured=1' -F 'short_description=New short')
t "$R" "302 $B/admin/products/edit/$ID" "update redirects back"
t "$(sql "SELECT CONCAT_WS('|', name, category_id, regular_price, sale_price, price, stock_quantity, is_active, is_featured, sku) FROM products WHERE id=$ID")" "QA Basic Edited|2|2500.00|1999.99|1999.99|42|0|1|$SKU" "fields updated; blank SKU kept; unchecked Active -> inactive"
ok "$([ "$(sql "SELECT updated_at FROM products WHERE id=$ID")" != "$OLD" ] && echo 1 || echo 0)" "updated_at changed"
edit $ID -F 'name=QA Basic Edited' -F 'category_id=2' -F 'regular_price=2500' -F 'sale_price=' -F 'stock_quantity=0' -F 'is_active=1' >/dev/null
t "$(sql "SELECT CONCAT_WS('|', IFNULL(sale_price,'NULL'), price, stock_quantity) FROM products WHERE id=$ID")" "NULL|2500.00|0" "sale removed -> regular price; stock set to 0"
edit $ID -F 'name=QA Basic Edited' -F 'category_id=2' -F 'regular_price=2500' -F 'stock_quantity=-3' -F 'is_active=1' >/dev/null
t "$(sql "SELECT stock_quantity FROM products WHERE id=$ID")" "0" "negative stock on edit rejected"
edit $ID -F 'name=QA Basic Edited' -F 'category_id=2' -F 'regular_price=2500' -F 'sale_price=2600' -F 'stock_quantity=1' -F 'is_active=1' >/dev/null
ok "$(has "$(page $B/admin/products/edit/$ID)" 'Sale price must be lower')" "sale above regular rejected on edit"
t "$(code $B/admin/products/edit/999999)" "302 $B/admin/products" "edit unknown id -> list"
t "$(code "$B/admin/products/edit/1%27%20OR%201=1")" "302 $B/admin/products" "edit injection-style id -> list"

echo "== Edit images"
IMGS=$(sql "SELECT GROUP_CONCAT(id ORDER BY is_primary DESC, sort_order) FROM product_images WHERE product_id=$IID")
M1=$(echo $IMGS | cut -d, -f1); X1=$(echo $IMGS | cut -d, -f2); X2=$(echo $IMGS | cut -d, -f3)
X1P=$(sql "SELECT image_path FROM product_images WHERE id=$X1")
editbase $IID -F "primary_image=$X1" >/dev/null
t "$(sql "SELECT id FROM product_images WHERE product_id=$IID AND is_primary=1")" "$X1" "set an additional image as main"
editbase $IID -F "primary_image=$M1" >/dev/null
MAINP=$(sql "SELECT image_path FROM product_images WHERE id=$M1")
editbase $IID -F "main_image=@$FX/valid-upper.JPEG;type=image/jpeg" >/dev/null
NEWMAIN=$(sql "SELECT image_path FROM product_images WHERE product_id=$IID AND is_primary=1")
ok "$([ "$NEWMAIN" != "$MAINP" ] && [ ! -f "$PUB/$MAINP" ] && [ ! -f "$PUB/${MAINP%.webp}-sm.webp" ] && [ -f "$PUB/$NEWMAIN" ] && echo 1 || echo 0)" "replacing main deletes old file + thumbnail"
t "$(sql "SELECT COUNT(*) FROM product_images WHERE product_id=$IID")" "3" "image count unchanged after replacing main"
editbase $IID -F "remove_images[]=$X1" >/dev/null
ok "$([ -z "$(sql "SELECT id FROM product_images WHERE id=$X1")" ] && [ ! -f "$PUB/$X1P" ] && echo 1 || echo 0)" "remove additional image (row + file)"
MID=$(sql "SELECT id FROM product_images WHERE product_id=$IID AND is_primary=1")
editbase $IID -F "remove_images[]=$MID" >/dev/null
t "$(sql "SELECT id FROM product_images WHERE product_id=$IID AND is_primary=1")" "$X2" "removing main promotes the remaining image"
editbase $IID -F "remove_images[]=999999" -F "primary_image=999999" >/dev/null
t "$(sql "SELECT CONCAT(COUNT(*),'/',SUM(is_primary)) FROM product_images WHERE product_id=$IID")" "1/1" "ids of other products' images are ignored"
OTHER=$(sql "SELECT id FROM product_images WHERE product_id=$EID LIMIT 1")
editbase $IID -F "remove_images[]=$OTHER" >/dev/null
t "$(sql "SELECT COUNT(*) FROM product_images WHERE id=$OTHER")" "1" "cannot remove another product's image"
EIGHT=(); for i in 1 2 3 4 5 6 7 8; do EIGHT+=(-F "images[]=@$FX/valid.webp;type=image/webp"); done
editbase $IID "${EIGHT[@]}" >/dev/null
t "$(sql "SELECT COUNT(*) FROM product_images WHERE product_id=$IID")" "9" "8 additional images added on edit (1 main + 8)"
ok "$(has "$(page $B/admin/products/edit/$IID)" 'Image limit reached')" "form shows image limit reached"
editbase $IID -F "images[]=@$FX/valid.webp;type=image/webp" >/dev/null
ok "$([ "$(sql "SELECT COUNT(*) FROM product_images WHERE product_id=$IID")" = 9 ] && [ "$(has "$(page $B/admin/products/edit/$IID)" 'You can add 0 more')" = 1 ] && echo 1 || echo 0)" "9th additional image refused"

echo "== Featured / New / Active toggles"
R=$(code -F "_csrf=$T" -F flag=featured -F 'return=q=QA&category=&sort=newest&evil=x' $B/admin/products/toggle/$EID)
t "$R" "302 $B/admin/products?q=QA" "toggle keeps list filters (unknown params dropped)"
t "$(sql "SELECT is_featured FROM products WHERE id=$EID")" "1" "featured on"
J2=$(curl -s -A "$UA" -b "$J" -H 'Accept: application/json' -F "_csrf=$T" -F flag=new $B/admin/products/toggle/$EID)
t "$J2" "{\"ok\":true,\"flag\":\"new\",\"value\":true,\"message\":\"\\\"QA Extras Only\\\" is now marked as new.\"}" "JSON toggle for New"
t "$(sql "SELECT is_new FROM products WHERE id=$EID")" "1" "new on"
ok "$(curl -s $B/ | grep -A20 'QA Extras Only' | grep -c 'product-card__badge--new' | grep -qv '^0$' && echo 1 || echo 0)" "storefront shows New badge"
ok "$(curl -s $B/ | sed -n '/Featured products/,/New arrivals/p' | grep -c 'QA Extras Only' | grep -qv '^0$' && echo 1 || echo 0)" "featured product appears in Featured section"
curl -s -A "$UA" -b "$J" -H 'Accept: application/json' -F "_csrf=$T" -F flag=active $B/admin/products/toggle/$EID >/dev/null
t "$(sql "SELECT is_active FROM products WHERE id=$EID")" "0" "deactivated"
ok "$(( $(has "$(curl -s $B/)" 'QA Extras Only') == 0 ))" "inactive product hidden from storefront"
t "$(curl -s -A "$UA" -b "$J" -H 'Accept: application/json' -o /dev/null -w '%{http_code}' -F "_csrf=$T" -F flag=is_active $B/admin/products/toggle/$EID)" "422" "unknown flag rejected (422)"
t "$(curl -s -A "$UA" -b "$J" -H 'Accept: application/json' -o /dev/null -w '%{http_code}' -F "_csrf=$T" -F flag=new $B/admin/products/toggle/999999)" "404" "toggle unknown product (404)"

echo "== Search & filters"
L=$(page "$B/admin/products?q=QA%20Price")
ok "$([ "$(has "$L" 'QA Price Sale')" = 1 ] && [ "$(has "$L" 'Sample Smart Watch')" = 0 ] && echo 1 || echo 0)" "search by name"
ok "$(has "$(page "$B/admin/products?q=QA-SKU-001")" 'QA Custom Sku')" "search by SKU"
L=$(page "$B/admin/products?q=%25")
ok "$([ "$(has "$L" 'Sample Dark Chocolate Bar')" = 1 ] && [ "$(has "$L" 'Showing 1–1 of 1')" = 1 ] && echo 1 || echo 0)" "'%' is literal (only '70% cocoa' matches)"
L=$(page "$B/admin/products?category=2")
ok "$([ "$(has "$L" 'Sample Denim Jacket')" = 1 ] && [ "$(has "$L" 'Sample Smart Watch')" = 0 ] && [ "$(has "$L" 'QA Basic Edited')" = 1 ] && echo 1 || echo 0)" "category filter"
t "$(page "$B/admin/products?category=2&q=zz" | grep -c 'No products match')" "1" "category + search combined"
L=$(page "$B/admin/products?stock=out")
ok "$([ "$(has "$L" 'Sample Bluetooth Speaker')" = 1 ] && [ "$(has "$L" 'Sample Smart Watch')" = 0 ] && echo 1 || echo 0)" "stock filter: out of stock"
L=$(page "$B/admin/products?stock=low&q=QA")
ok "$([ "$(has "$L" 'QA Flags')" = 1 ] && [ "$(has "$L" 'QA Stock Lead')" = 0 ] && [ "$(has "$L" 'QA Stock Zero')" = 0 ] && echo 1 || echo 0)" "stock filter: low (1-5): stock 5 in, 7 and 0 out"
L=$(page "$B/admin/products?flag=sale&q=QA")
ok "$([ "$(has "$L" 'QA Price Sale')" = 1 ] && [ "$(has "$L" 'QA Price Zero')" = 0 ] && echo 1 || echo 0)" "filter: on sale (sale 0 not counted)"
ok "$(has "$(page "$B/admin/products?status=inactive&q=QA")" 'QA Inactive')" "filter: inactive"
FIRST=$(page "$B/admin/products?sort=price_asc&q=QA%20Price" | grep -o 'fw-semibold text-body text-decoration-none">[^<]*' | head -1 | sed 's/.*>//')
t "$FIRST" "QA Price Cents" "sort by price (effective) ascending"
t "$(code "$B/admin/products?sort=price;DROP%20TABLE%20products&category=abc&page=-1&stock=x")" "200 " "bad filter values ignored"
t "$(sql 'SELECT COUNT(*) > 0 FROM products')" "1" "products table intact"

echo "== Delete"
OID=$(sql "INSERT INTO orders (order_number, customer_name, customer_phone, shipping_address, subtotal, grand_total) VALUES ('QA-1','QA','01700000000','x',10,10); SELECT LAST_INSERT_ID();")
sql "INSERT INTO order_items (order_id, product_id, product_name, product_sku, unit_price, quantity, line_total) VALUES ($OID, $ID, 'QA', 'QA', 10, 1, 10)"
t "$(code -F "_csrf=$T" $B/admin/products/delete/$ID)" "302 $B/admin/products" "delete ordered product..."
t "$(sql "SELECT COUNT(*) FROM products WHERE id=$ID")" "1" "...refused (it has orders)"
ok "$(has "$(page $B/admin/products)" 'appears in 1 order line')" "clear reason given"
DP=$(sql "SELECT GROUP_CONCAT(image_path) FROM product_images WHERE product_id=$IID")
t "$(code -F "_csrf=$T" -F 'return=q=QA' $B/admin/products/delete/$IID)" "302 $B/admin/products?q=QA" "delete product with images"
ok "$([ -z "$(pid 'QA Img')" ] && [ "$(sql "SELECT COUNT(*) FROM product_images WHERE product_id=$IID")" = 0 ] && echo 1 || echo 0)" "product + image rows removed"
ok "$(for p in ${DP//,/ }; do [ -f "$PUB/$p" ] && echo x; done | grep -c x | grep -q '^0$' && echo 1 || echo 0)" "image files removed"
t "$(code -F "_csrf=$T" $B/admin/products/delete/$IID)" "302 $B/admin/products" "deleting again handled"

echo "== Escaping"
create 'QA <img src=x onerror=alert(1)>' >/dev/null
L=$(page "$B/admin/products?q=onerror")
ok "$([ "$(has "$L" '<img src=x onerror')" = 0 ] && [ "$(has "$L" 'QA &lt;img src=x onerror=alert(1)&gt;')" = 1 ] && echo 1 || echo 0)" "HTML in product name escaped"

cleanup
t "$(files)" "$FILES0" "cleanup: no orphan files"
rm -f "$J"
echo; echo "TOTAL: $pass passed, $fail failed"
