#!/bin/bash
# Admin authentication HTTP test suite (curl)
B=http://localhost:8080
UA="Mozilla/5.0 AaltoTest"
MYSQL="/c/Program Files/MySQL/MySQL Server 8.0/bin/mysql.exe"
DIR="$(dirname "$0")"
J="$DIR/jar.txt"; OLDJ="$DIR/jar_old.txt"; rm -f "$J" "$OLDJ"
pass=0; fail=0

sql()  { "$MYSQL" -uroot -p1234 aalto_mart_dev -N -e "$1" 2>/dev/null; }
t()    { if [ "$1" = "$2" ]; then echo "PASS $3"; pass=$((pass+1)); else echo "FAIL $3 (got '$1', want '$2')"; fail=$((fail+1)); fi; }
ok()   { if [ "$1" = 1 ]; then t 1 1 "$2"; else t 0 1 "$2"; fi; }
has()  { if printf '%s' "$1" | grep -qF -- "$2"; then echo 1; else echo 0; fi; }
code() { curl -s -A "$UA" -b "$J" -c "$J" -o /dev/null -w "%{http_code} %{redirect_url}" "$@"; }
page() { curl -s -A "$UA" -b "$J" -c "$J" "$@"; }
tok()  { printf '%s' "$1" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
csrf() { tok "$(page "$B/admin/login")"; }
sid()  { grep 'aalto_session' "$J" | awk '{print $7}'; }
login(){ T=$(csrf); code -X POST --data-urlencode "_csrf=$T" --data-urlencode "email=$1" --data-urlencode "password=$2" "$B/admin/login"; }

sql "DELETE FROM login_attempts"

echo "== Direct access without login"
for p in dashboard products categories orders; do
    t "$(code $B/admin/$p)" "302 $B/admin/login" "/admin/$p redirects to login"
done
t "$(code $B/admin)" "302 $B/admin/login" "/admin redirects to login"
t "$(code $B/admin/logout)" "302 $B/admin/login" "GET /admin/logout redirects to login"
H=$(curl -s -D - -o /dev/null -A "$UA" $B/admin/login | tr -d '\r')
ok "$(has "$H" 'Cache-Control: no-store')" "login page sends Cache-Control no-store"
ok "$(has "$H" 'X-Frame-Options: DENY')" "login page sends X-Frame-Options DENY"
ok "$(printf '%s' "$H" | grep -ci 'Set-Cookie:.*HttpOnly.*SameSite=Lax')" "session cookie is HttpOnly + SameSite=Lax"

echo "== Empty / invalid fields"
t "$(login '' '')" "302 $B/admin/login" "empty POST redirects back"
P=$(page $B/admin/login)
ok "$(has "$P" 'Enter your email address.')" "email required message"
ok "$(has "$P" 'Enter your password.')" "password required message"
login 'not-an-email' 'x' >/dev/null
P=$(page $B/admin/login)
ok "$(has "$P" 'Enter a valid email address.')" "invalid email format message"
ok "$(has "$P" 'value="not-an-email"')" "email value kept after error"
login 'admin@example.com' '' >/dev/null
ok "$(has "$(page $B/admin/login)" 'Enter your password.')" "empty password alone is caught"
P=$(page $B/admin/login)
ok "$(( $(has "$P" 'Enter your password.') == 0 ))" "errors shown only once (cleared on next request)"
t "$(sql 'SELECT COUNT(*) FROM login_attempts')" "0" "validation errors are not counted as failed logins"

echo "== CSRF"
t "$(code -X POST -d 'email=admin@example.com&password=Admin@12345' $B/admin/login)" "302 $B/admin/login" "no token: rejected"
ok "$(has "$(page $B/admin/login)" 'form was out of date')" "no token: error message shown"
t "$(code -X POST -d '_csrf=deadbeef&email=admin@example.com&password=Admin@12345' $B/admin/login)" "302 $B/admin/login" "wrong token: rejected"
t "$(code $B/admin/dashboard)" "302 $B/admin/login" "still signed out after CSRF failures"
T=$(csrf)
t "$(curl -s -A "$UA" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST --data-urlencode "_csrf=$T" -d 'email=admin@example.com&password=Admin@12345' $B/admin/login)" "302 $B/admin/login" "valid token used from a different session: rejected"

echo "== Incorrect password"
t "$(login 'admin@example.com' 'WrongPass1')" "302 $B/admin/login" "wrong password redirects back"
ok "$(has "$(page $B/admin/login)" 'Incorrect email or password.')" "generic error message"
login 'nobody@example.com' 'WrongPass1' >/dev/null
ok "$(has "$(page $B/admin/login)" 'Incorrect email or password.')" "unknown email gives the same message"
t "$(code $B/admin/dashboard)" "302 $B/admin/login" "not signed in after wrong password"
t "$(sql 'SELECT COUNT(*) FROM login_attempts')" "2" "failed attempts recorded"
sql "DELETE FROM login_attempts"

echo "== Correct login"
code $B/admin/orders >/dev/null   # remember the originally requested page
BEFORE=$(sid)
t "$(login 'admin@example.com' 'Admin@12345')" "302 $B/admin/orders" "login redirects to the page originally requested"
AFTER=$(sid)
ok "$([ -n "$AFTER" ] && [ "$BEFORE" != "$AFTER" ] && echo 1 || echo 0)" "session id regenerated on login"
D=$(page $B/admin/dashboard)
ok "$(has "$D" 'Welcome back, Store Admin.')" "welcome flash shown"
t "$(code $B/admin/dashboard)" "200 " "dashboard accessible"
for p in products categories orders; do t "$(code $B/admin/$p)" "200 " "/admin/$p accessible"; done
t "$(code $B/admin/login)" "302 $B/admin/dashboard" "login page redirects when already signed in"
t "$(code $B/admin)" "302 $B/admin/dashboard" "/admin redirects to dashboard"
ok "$(( $(has "$D" '$2y$') == 0 ))" "no password hash anywhere in the HTML"
stat() { printf '%s' "$D" | tr -d '\n' | grep -o "data-stat=\"$1\".\{0,600\}" | grep -o 'stat-card__value mb-1">[^<]*' | head -1 | sed 's/.*>//'; }
GOT="$(stat products) $(stat categories) $(stat orders) "
WANT="$(sql 'SELECT COUNT(*) FROM products') $(sql 'SELECT COUNT(*) FROM categories') $(sql 'SELECT COUNT(*) FROM orders') "
t "$GOT" "$WANT" "dashboard counts match DB (products categories orders)"
t "$(sql 'SELECT last_login_at IS NOT NULL FROM users WHERE id=1')" "1" "last_login_at updated"

echo "== Session handling"
t "$(curl -s -A 'OtherBrowser/1.0' -b "$J" -o /dev/null -w '%{http_code}' $B/admin/dashboard)" "302" "cookie reused with a different User-Agent is rejected"
rm -f "$J"; login 'admin@example.com' 'Admin@12345' >/dev/null
t "$(code $B/admin/dashboard)" "200 " "fresh login works"
sql "UPDATE users SET is_active=0 WHERE id=1"
t "$(code $B/admin/dashboard)" "302 $B/admin/login" "deactivated admin is signed out on next request"
sql "UPDATE users SET is_active=1 WHERE id=1"
rm -f "$J"; login 'admin@example.com' 'Admin@12345' >/dev/null

echo "== Logout"
t "$(code $B/admin/logout)" "200 " "GET /admin/logout only shows a confirmation"
t "$(code $B/admin/dashboard)" "200 " "still signed in after GET /admin/logout"
t "$(code -X POST -d '_csrf=bad' $B/admin/logout)" "302 $B/admin/dashboard" "logout with bad CSRF refused"
t "$(code $B/admin/dashboard)" "200 " "still signed in after bad-CSRF logout"
cp "$J" "$OLDJ"
T=$(tok "$(page $B/admin/dashboard)")
t "$(code -X POST --data-urlencode "_csrf=$T" $B/admin/logout)" "302 $B/admin/login" "logout with valid CSRF"
ok "$(has "$(page $B/admin/login)" 'You have been signed out.')" "signed-out message"
t "$(code $B/admin/dashboard)" "302 $B/admin/login" "dashboard blocked after logout"
t "$(curl -s -A "$UA" -b "$OLDJ" -o /dev/null -w '%{http_code}' $B/admin/dashboard)" "302" "replaying the pre-logout session cookie fails"

echo "== Brute-force lockout"
sql "DELETE FROM login_attempts"; rm -f "$J"
for i in 1 2 3 4 5; do login 'admin@example.com' "Wrong$i" >/dev/null; done
login 'admin@example.com' 'Admin@12345' >/dev/null
ok "$(has "$(page $B/admin/login)" 'Too many failed sign-in attempts')" "6th attempt locked, even with the correct password"
t "$(code $B/admin/dashboard)" "302 $B/admin/login" "not signed in while locked"
sql "DELETE FROM login_attempts"
t "$(login 'admin@example.com' 'Admin@12345')" "302 $B/admin/dashboard" "login works once the lock expires/clears"

echo "== Output escaping"
rm -f "$J"
login '"><script>alert(1)</script>@x.com' 'x' >/dev/null
P=$(page $B/admin/login)
ok "$(( $(has "$P" '<script>alert(1)') == 0 ))" "echoed email is HTML-escaped"
ok "$(has "$P" '&quot;&gt;&lt;script&gt;')" "escaped form present in value attribute"

sql "DELETE FROM login_attempts"
rm -f "$J" "$OLDJ"
echo; echo "TOTAL: $pass passed, $fail failed"
