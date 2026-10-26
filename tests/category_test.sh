#!/bin/bash
# Phase 3 - category management HTTP test suite
B=http://localhost:8080
UA="Mozilla/5.0 AaltoTest"
MYSQL="/c/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe"
DIR="$(cygpath -m "$(cd "$(dirname "$0")" && pwd)")"
FX="$DIR/fixtures"
UP="/d/SDL-PROJECT/AALTO-ECOM/public/uploads/categories"
J="$DIR/cjar.txt"; rm -f "$J"
pass=0; fail=0

sql()  { "$MYSQL" -uroot -p1234 aalto_mart_dev -N --default-character-set=utf8mb4 -e "$1" 2>/dev/null | sed 's/[[:cntrl:]]*$//'; }
t()    { if [ "$1" = "$2" ]; then echo "PASS $3"; pass=$((pass+1)); else echo "FAIL $3 (got '$1', want '$2')"; fail=$((fail+1)); fi; }
ok()   { if [ "$1" = 1 ]; then t 1 1 "$2"; else t 0 1 "$2"; fi; }
has()  { if printf '%s' "$1" | grep -qF -- "$2"; then echo 1; else echo 0; fi; }
code() { curl -s -A "$UA" -b "$J" -c "$J" -o /dev/null -w "%{http_code} %{redirect_url}" "$@"; }
page() { curl -s -A "$UA" -b "$J" -c "$J" "$@"; }
tok()  { printf '%s' "$1" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
T=""
refresh_token() { T=$(tok "$(page $B/admin/categories/create)"); }
# create NAME [extra curl -F args...]
create() { local n="$1"; shift; code -F "_csrf=$T" -F "name=$n" -F "status=active" "$@" "$B/admin/categories/create"; }
cid()  { sql "SELECT id FROM categories WHERE name = '$1'"; }
files(){ ls "$UP" 2>/dev/null | wc -l | tr -d ' '; }

cleanup() {
    sql "SELECT image_path FROM categories WHERE name LIKE 'QA %' AND image_path IS NOT NULL" | while IFS= read -r p; do rm -f "/d/SDL-PROJECT/AALTO-ECOM/public/$p" "/d/SDL-PROJECT/AALTO-ECOM/public/${p%.webp}-sm.webp"; done
    sql "DELETE FROM categories WHERE name LIKE 'QA %' OR name LIKE 'qa %' OR slug LIKE 'qa-%'"
    sql "DELETE FROM login_attempts"
}
cleanup
FILES0=$(files)

echo "== Unauthorized access"
for p in categories categories/create categories/edit/1 categories/edit; do
    t "$(code $B/admin/$p)" "302 $B/admin/login" "GET /admin/$p (signed out) -> login"
done
t "$(code -F name='QA Anon' -F status=active $B/admin/categories/create)" "302 $B/admin/login" "POST create signed out -> login"
t "$(code -F name='QA Anon' -F status=active $B/admin/categories/edit/1)" "302 $B/admin/login" "POST edit signed out -> login"
t "$(code -X POST $B/admin/categories/toggle/1)" "302 $B/admin/login" "POST toggle signed out -> login"
t "$(code -X POST $B/admin/categories/delete/5)" "302 $B/admin/login" "POST delete signed out -> login"
t "$(sql "SELECT COUNT(*) FROM categories WHERE name='QA Anon'")|$(sql 'SELECT is_active FROM categories WHERE id=1')|$(sql 'SELECT COUNT(*) FROM categories WHERE id=5')" "0|1|1" "nothing changed by signed-out requests"

# sign in
LT=$(tok "$(page $B/admin/login)")
code -X POST --data-urlencode "_csrf=$LT" -d "email=admin@example.com&password=Admin@12345" $B/admin/login >/dev/null
t "$(code $B/admin/categories)" "200 " "signed in: list loads"
refresh_token

echo "== CSRF"
t "$(code -F name='QA NoToken' -F status=active $B/admin/categories/create)" "302 $B/admin/categories/create" "create without token refused"
t "$(code -F _csrf=bad -F name='QA BadToken' -F status=active $B/admin/categories/create)" "302 $B/admin/categories/create" "create with bad token refused"
t "$(code -F _csrf=bad $B/admin/categories/toggle/1)" "302 $B/admin/categories" "toggle with bad token refused"
t "$(code -F _csrf=bad $B/admin/categories/delete/5)" "302 $B/admin/categories" "delete with bad token refused"
t "$(sql "SELECT COUNT(*) FROM categories WHERE name LIKE 'QA %'")|$(sql 'SELECT is_active FROM categories WHERE id=1')" "0|1" "no changes after CSRF failures"

echo "== Add"
R=$(create 'QA Books' -F 'description=Sample books category')
ID=$(cid 'QA Books')
t "$R" "302 $B/admin/categories/edit/$ID" "create redirects to edit page"
t "$(sql "SELECT CONCAT(slug,'|',description,'|',is_active,'|',IFNULL(image_path,'-')) FROM categories WHERE id=$ID")" "qa-books|Sample books category|1|-" "row saved with auto slug"
ok "$(has "$(page $B/admin/categories/edit/$ID)" 'Category &quot;QA Books&quot; was created.')" "success message shown"
create 'QA  Spaced   Name  ' >/dev/null
t "$(sql "SELECT slug FROM categories WHERE name='QA Spaced Name'")" "qa-spaced-name" "whitespace collapsed in name"
# UTF-8 sent from a file: Windows command-line args aren't UTF-8
printf 'QA Caf\xc3\xa9 Cr\xc3\xa8me' > "$DIR/utf8.txt"
code -F "_csrf=$T" -F "name=<$DIR/utf8.txt" -F status=active $B/admin/categories/create >/dev/null
t "$(sql "SELECT slug FROM categories WHERE slug LIKE 'qa-caf%'")" "qa-cafe-creme" "accents transliterated in slug (Café Crème)"
printf 'QA Bad \xe9\xff' > "$DIR/bad.txt"
code -F "_csrf=$T" -F "name=<$DIR/bad.txt" -F status=active $B/admin/categories/create >/dev/null
ok "$(has "$(page $B/admin/categories/create)" 'This field contains invalid characters.')" "malformed UTF-8 rejected with a clear message"
create 'QA Custom' -F 'slug=  QA My Custom Slug!! ' >/dev/null
t "$(sql "SELECT slug FROM categories WHERE name='QA Custom'")" "qa-my-custom-slug" "typed slug normalised"
create 'QA Hidden' -F 'status=inactive' >/dev/null
t "$(sql "SELECT is_active FROM categories WHERE name='QA Hidden'")" "0" "create as inactive"

echo "== Images"
create 'QA Jpg' -F "image=@$FX/valid.jpg;type=image/jpeg" >/dev/null
P=$(sql "SELECT image_path FROM categories WHERE name='QA Jpg'")
ok "$(printf '%s' "$P" | grep -cE '^uploads/categories/category-qa-jpg-[a-f0-9]{8}\.webp$')" "JPG stored as random-named .webp ($P)"
INFO=$(/c/xampp/php/php.exe -r '$i=getimagesize($argv[1]); echo $i["mime"]," ",$i[0],"x",$i[1];' "/d/SDL-PROJECT/AALTO-ECOM/public/$P")
t "$INFO" "image/webp 320x320" "converted to WebP, centre-cropped to 1:1 and scaled to 320 x 320 (category standard)"
t "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' $B/$P)" "200 image/webp" "uploaded image is served"
create 'QA Png' -F "image=@$FX/valid.png;type=image/png" >/dev/null
P=$(sql "SELECT image_path FROM categories WHERE name='QA Png'")
t "$(/c/xampp/php/php.exe -r '$im=imagecreatefromwebp($argv[1]); $c=imagecolorat($im,0,0); echo ($c>>24)&127;' "/d/SDL-PROJECT/AALTO-ECOM/public/$P")" "127" "PNG transparency kept in WebP"
create 'QA Webp' -F "image=@$FX/valid.webp;type=image/webp" >/dev/null
ok "$(sql "SELECT image_path LIKE 'uploads/categories/%.webp' FROM categories WHERE name='QA Webp'")" "WebP accepted"
create 'QA Upper' -F "image=@$FX/valid-upper.JPEG;type=image/jpeg" >/dev/null
ok "$(sql "SELECT image_path IS NOT NULL FROM categories WHERE name='QA Upper'")" "uppercase .JPEG accepted"
create 'QA Polyglot' -F "image=@$FX/polyglot.png;type=image/png" >/dev/null
P=$(sql "SELECT image_path FROM categories WHERE name='QA Polyglot'")
ok "$(( $(grep -c '<?php' "/d/SDL-PROJECT/AALTO-ECOM/public/$P") == 0 ))" "PHP code hidden in a PNG is stripped by re-encoding"
create 'QA DoubleExt' -F "image=@$FX/shell.php.png;type=image/png" >/dev/null
ok "$(sql "SELECT image_path NOT LIKE '%php%' FROM categories WHERE name='QA DoubleExt'")" "shell.php.png saved under a random name with no .php"

GOODFILES=$(files)
for f in "fake.jpg:image/jpeg:Only JPG, PNG or WebP" "png-named.jpg:image/jpeg:does not match" "shell.php:image/png:Only JPG, PNG or WebP" \
         "image.gif:image/gif:Only JPG, PNG or WebP" "image.svg:image/svg+xml:Only JPG, PNG or WebP" "big.jpg:image/jpeg:too large" \
         "huge-dimensions.png:image/png:too large to process" "corrupt.jpg:image/jpeg:could not be read"; do
    IFS=: read -r file mime msg <<< "$f"
    R=$(create "QA Bad $file" -F "image=@$FX/$file;type=$mime")
    P=$(page $B/admin/categories/create)
    ok "$([ "$R" = "302 $B/admin/categories/create" ] && [ "$(has "$P" "$msg")" = 1 ] && [ -z "$(cid "QA Bad $file")" ] && echo 1 || echo 0)" "rejected $file ($msg)"
done
t "$(files)" "$GOODFILES" "no files left behind by rejected uploads"

echo "== Duplicates"
R=$(create 'QA Books'); t "$R" "302 $B/admin/categories/create" "duplicate name rejected"
ok "$(has "$(page $B/admin/categories/create)" 'A category with this name already exists.')" "duplicate name message"
create 'qa BOOKS' >/dev/null
t "$(sql "SELECT COUNT(*) FROM categories WHERE name='QA Books'")" "1" "duplicate check is case-insensitive"
create 'QA Books!' >/dev/null
t "$(sql "SELECT slug FROM categories WHERE name='QA Books!'")" "qa-books-2" "auto slug made unique (-2)"
R=$(create 'QA Other' -F 'slug=qa-books')
ok "$(has "$(page $B/admin/categories/create)" 'The slug &quot;qa-books&quot; is already used')" "duplicate typed slug rejected"
t "$(cid 'QA Other')" "" "not saved"
create 'QA Elec' -F 'slug=electronics' >/dev/null
t "$(cid 'QA Elec')" "" "cannot take an existing seeded slug"

echo "== Invalid input"
R=$(create '')
P=$(page $B/admin/categories/create)
ok "$(has "$P" 'Enter a category name.')" "empty name rejected"
code -F "_csrf=$T" -F 'name=   ' -F status=active $B/admin/categories/create >/dev/null
ok "$(has "$(page $B/admin/categories/create)" 'Enter a category name.')" "whitespace-only name rejected"
create "QA $(printf 'x%.0s' $(seq 1 120))" >/dev/null
ok "$(has "$(page $B/admin/categories/create)" 'Name must be 120 characters or fewer.')" "121+ char name rejected"
create '!!!' >/dev/null
ok "$(has "$(page $B/admin/categories/create)" 'must contain letters or numbers')" "symbols-only name rejected"
create 'QA Long' -F "description=$(printf 'd%.0s' $(seq 1 1001))" >/dev/null
P=$(page $B/admin/categories/create)
ok "$(has "$P" 'Description must be 1000 characters or fewer (currently 1001).')" "1001-char description rejected"
ok "$(has "$P" 'value="QA Long"')" "typed name kept after error"
create 'QA Exact' -F "description=$(printf 'd%.0s' $(seq 1 1000))" >/dev/null
ok "$([ -n "$(cid 'QA Exact')" ] && echo 1 || echo 0)" "exactly 1000 chars accepted"
code -F "_csrf=$T" -F 'name=QA Status' -F 'status=deleted' $B/admin/categories/create >/dev/null
ok "$(has "$(page $B/admin/categories/create)" 'Choose a status.')" "invalid status rejected"
create 'QA Slug' -F 'slug=!!!' >/dev/null
ok "$(has "$(page $B/admin/categories/create)" 'Use only letters, numbers and hyphens')" "invalid slug rejected"
t "$(sql "SELECT COUNT(*) FROM categories WHERE name IN ('QA Long','QA Status','QA Slug','!!!')")" "0" "no invalid rows saved"
create 'QA <script>alert(1)</script>' >/dev/null
L=$(page "$B/admin/categories?q=script")
ok "$(( $(has "$L" '<script>alert(1)') == 0 ))" "name with HTML is escaped in the list"
ok "$(has "$L" 'QA &lt;script&gt;alert(1)&lt;/script&gt;')" "escaped name is shown"
t "$(sql "SELECT slug FROM categories WHERE name LIKE 'QA <script>%'")" "qa-script-alert-1-script" "slug from HTML name is safe"

echo "== Edit"
BEFORE=$(sql "SELECT updated_at FROM categories WHERE id=$ID"); sleep 1
R=$(code -F "_csrf=$T" -F 'name=QA Books Updated' -F 'slug=qa-books' -F 'description=New text' -F 'status=inactive' $B/admin/categories/edit/$ID)
t "$R" "302 $B/admin/categories/edit/$ID" "update redirects back to edit"
t "$(sql "SELECT CONCAT(name,'|',slug,'|',description,'|',is_active) FROM categories WHERE id=$ID")" "QA Books Updated|qa-books|New text|0" "fields updated (own slug kept)"
ok "$([ "$(sql "SELECT updated_at FROM categories WHERE id=$ID")" != "$BEFORE" ] && echo 1 || echo 0)" "updated_at changed"
code -F "_csrf=$T" -F 'name=QA Books Updated' -F 'slug=' -F 'status=active' $B/admin/categories/edit/$ID >/dev/null
t "$(sql "SELECT slug FROM categories WHERE id=$ID")" "qa-books-updated" "blank slug regenerated from name"
code -F "_csrf=$T" -F 'name=Electronics' -F 'status=active' $B/admin/categories/edit/$ID >/dev/null
ok "$(has "$(page $B/admin/categories/edit/$ID)" 'A category with this name already exists.')" "cannot rename to another category's name"
code -F "_csrf=$T" -F 'name=QA Books Updated' -F 'slug=fashion' -F 'status=active' $B/admin/categories/edit/$ID >/dev/null
t "$(sql "SELECT slug FROM categories WHERE id=$ID")" "qa-books-updated" "cannot take another category's slug"
code -F "_csrf=$T" -F 'name=QA Books Updated' -F 'status=active' -F "image=@$FX/valid.jpg;type=image/jpeg" $B/admin/categories/edit/$ID >/dev/null
IMG1=$(sql "SELECT image_path FROM categories WHERE id=$ID")
ok "$([ -f "/d/SDL-PROJECT/AALTO-ECOM/public/$IMG1" ] && echo 1 || echo 0)" "image added on edit"
code -F "_csrf=$T" -F 'name=QA Books Updated' -F 'status=active' -F "image=@$FX/valid.webp;type=image/webp" $B/admin/categories/edit/$ID >/dev/null
IMG2=$(sql "SELECT image_path FROM categories WHERE id=$ID")
ok "$([ "$IMG1" != "$IMG2" ] && [ ! -f "/d/SDL-PROJECT/AALTO-ECOM/public/$IMG1" ] && [ -f "/d/SDL-PROJECT/AALTO-ECOM/public/$IMG2" ] && echo 1 || echo 0)" "replacing image deletes the old file"
code -F "_csrf=$T" -F 'name=QA Books Updated' -F 'status=active' -F "image=@$FX/fake.jpg;type=image/jpeg" $B/admin/categories/edit/$ID >/dev/null
t "$(sql "SELECT image_path FROM categories WHERE id=$ID")" "$IMG2" "bad replacement image keeps the current one"
code -F "_csrf=$T" -F 'name=QA Books Updated' -F 'status=active' -F 'remove_image=1' $B/admin/categories/edit/$ID >/dev/null
ok "$([ "$(sql "SELECT IFNULL(image_path,'NULL') FROM categories WHERE id=$ID")" = NULL ] && [ ! -f "/d/SDL-PROJECT/AALTO-ECOM/public/$IMG2" ] && echo 1 || echo 0)" "remove image clears path and deletes file"
t "$(code $B/admin/categories/edit/999999)" "302 $B/admin/categories" "edit unknown id -> list"
t "$(code $B/admin/categories/edit/abc)" "302 $B/admin/categories" "edit non-numeric id -> list"
t "$(code "$B/admin/categories/edit/1%20OR%201=1")" "302 $B/admin/categories" "edit injection-style id -> list"

echo "== Search"
L=$(page "$B/admin/categories?q=QA%20Book")
ok "$([ "$(has "$L" 'QA Books Updated')" = 1 ] && [ "$(has "$L" '>Electronics<')" = 0 ] && echo 1 || echo 0)" "search by name"
ok "$(has "$(page "$B/admin/categories?q=cafe-creme")" '/qa-cafe-creme')" "search by slug"
ok "$(has "$(page "$B/admin/categories?q=gadgets")" '>Electronics<')" "search by description"
ok "$(has "$(page "$B/admin/categories?q=%25")" 'No categories match your search')" "'%' is literal, not a wildcard"
ok "$(has "$(page "$B/admin/categories?q=zzznomatch")" 'No categories match your search')" "no-results message"
L=$(page "$B/admin/categories?status=inactive")
ok "$([ "$(has "$L" 'QA Hidden')" = 1 ] && [ "$(has "$L" '>Electronics<')" = 0 ] && echo 1 || echo 0)" "status filter: inactive"
t "$(code "$B/admin/categories?sort=name;DROP%20TABLE%20categories&status=x&page=-5")" "200 " "bad sort/status/page ignored safely"
t "$(sql 'SELECT COUNT(*) > 0 FROM categories')" "1" "categories table intact"
ok "$(has "$(page "$B/admin/categories?q=%22%3E%3Cscript%3Ealert(1)%3C/script%3E")" 'value="&quot;&gt;&lt;script&gt;')" "search term escaped in the input"

echo "== Activate / deactivate"
EID=1
R=$(code -F "_csrf=$T" -F 'return=q=elec&status=&sort=name&page=1&evil=1' $B/admin/categories/toggle/$EID)
t "$R" "302 $B/admin/categories?q=elec" "toggle returns to the filtered list (unknown params dropped)"
t "$(sql "SELECT is_active FROM categories WHERE id=$EID")" "0" "Electronics deactivated"
ok "$(( $(has "$(curl -s $B/)" '/category/electronics') == 0 ))" "inactive category hidden from storefront"
ok "$(has "$(page $B/admin/categories)" 'is now inactive and hidden from the store')" "deactivation message"
code -F "_csrf=$T" $B/admin/categories/toggle/$EID >/dev/null
t "$(sql "SELECT is_active FROM categories WHERE id=$EID")" "1" "Electronics re-activated"
ok "$(has "$(curl -s $B/)" '/category/electronics')" "visible in storefront again"
t "$(code -F "_csrf=$T" $B/admin/categories/toggle/999999)" "302 $B/admin/categories" "toggle unknown id handled"

echo "== Delete"
t "$(code -F "_csrf=$T" $B/admin/categories/delete/1)" "302 $B/admin/categories" "delete category with products..."
t "$(sql 'SELECT COUNT(*) FROM categories WHERE id=1')" "1" "...is refused (products protected)"
ok "$(has "$(page $B/admin/categories)" 'cannot be deleted because it has 5 products')" "clear reason given"
DID=$(cid 'QA Jpg'); DIMG=$(sql "SELECT image_path FROM categories WHERE id=$DID")
t "$(code -F "_csrf=$T" -F 'return=q=QA' $B/admin/categories/delete/$DID)" "302 $B/admin/categories?q=QA" "delete empty category"
ok "$([ -z "$(sql "SELECT id FROM categories WHERE id=$DID")" ] && [ ! -f "/d/SDL-PROJECT/AALTO-ECOM/public/$DIMG" ] && echo 1 || echo 0)" "row and image file removed"
t "$(code -F "_csrf=$T" $B/admin/categories/delete/$DID)" "302 $B/admin/categories" "deleting it again handled"
ok "$(has "$(page $B/admin/categories)" 'That category no longer exists.')" "not-found message"

echo "== Oversized request (> post_max_size 40M)"
dd if=/dev/zero of="$DIR/huge.bin" bs=1M count=41 2>/dev/null
R=$(code -F "_csrf=$T" -F 'name=QA Huge' -F status=active -F "image=@$DIR/huge.bin;filename=huge.jpg;type=image/jpeg" $B/admin/categories/create)
t "$R" "302 $B/admin/categories/create" "41 MB request redirected back"
ok "$(has "$(page $B/admin/categories/create)" 'The upload was too large')" "clear too-large message (not a CSRF error)"
rm -f "$DIR/huge.bin"

echo "== Upload folder"
t "$(curl -s -L -o /dev/null -w '%{http_code}' $B/uploads/categories/)" "404" "no directory listing"

cleanup
t "$(files)" "$FILES0" "cleanup: no orphan files"
rm -f "$J"
echo; echo "TOTAL: $pass passed, $fail failed"
