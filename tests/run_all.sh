#!/bin/bash
# Runs every test suite against the dev server (http://localhost:8080) and a fresh aalto_mart_dev.
# Usage: bash tests/run_all.sh            (all suites)
#        bash tests/run_all.sh cart_test  (one suite)
cd "$(dirname "$0")"
PHP=/c/xampp/php/php.exe
[ -d fixtures/std ] || { $PHP make_fixtures.php >/dev/null; $PHP make_std_fixtures.php >/dev/null; $PHP make_banners.php >/dev/null; }
SUITES="${*:-storefront_test cart_test checkout_test orders_test seo_test security_test variants_test image_standard_test home_features_test courier_tracking_test auth_test category_test product_test image_seo_test timeout_test}"
for t in $SUITES; do
  $PHP reseed.php >/dev/null
  if [ -f "$t.sh" ]; then out=$(bash "$t.sh" 2>&1); else out=$($PHP "$t.php" 2>&1); fi
  total=$(printf '%s\n' "$out" | grep "TOTAL" | tail -1)
  [ -z "$total" ] && total="$(printf '%s\n' "$out" | grep -c '^PASS') passed, $(printf '%s\n' "$out" | grep -c '^FAIL') failed"
  printf "%-24s %s\n" "$t" "$total"
  printf '%s\n' "$out" | grep -E "^FAIL|Fatal" | head -8 | sed 's/^/    /'
done
