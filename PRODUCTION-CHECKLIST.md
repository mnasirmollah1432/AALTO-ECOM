# Aalto Mart: Production Checklist

A tick-list for launch day. Every item links to the detailed steps in **[DEPLOYMENT.md](DEPLOYMENT.md)** (the § numbers below). Work top to bottom.

Placeholders: `CPUSER` = your cPanel username, `yourdomain.com` = your domain.

---

## 1. PHP version (DEPLOYMENT.md §1)
- [ ] cPanel → **MultiPHP Manager** → `yourdomain.com` → **PHP 8.3** (`ea-php83`) → Apply. On CloudLinux hosts: **Select PHP Version** → 8.3.

## 2. Required extensions (§2)
- [ ] **Required:** `pdo_mysql` (on CloudLinux: `nd_pdo_mysql`), `mbstring`, `gd` with JPEG + PNG + **WebP**, `fileinfo`. Also `ctype`, `filter`, `json`, `session` and `hash`, which are built into PHP 8.3.
- [ ] **Recommended:** `exif` (phone photo rotation), `iconv` (accents in URLs), `opcache` (speed).
- [ ] Not needed: Composer, Node, `intl`, `zip`, `curl`, `imagick`.

## 3. Database setup (§3.1)
- [ ] cPanel → **MySQL® Database Wizard**: database `CPUSER_aalto`, user `CPUSER_aaltoapp` with a **generated** password (write both down). Give the user **ALL PRIVILEGES** for now.
- [ ] phpMyAdmin → the database → **Operations** → collation `utf8mb4_unicode_ci`.

## 4. cPanel upload (§0, §4)
- [ ] On your computer: `php tools/build-release.php` → `aalto-mart-release-….zip`. It never contains `.env`, logs or local images.
- [ ] File Manager → **Settings** → tick **Show Hidden Files**.
- [ ] **Recommended layout:** create `/home/CPUSER/aalto-mart/`, upload the zip there and **Extract**. (Alternative: extract into `public_html/`.)
- [ ] **Delete the zip** from the server.
- [ ] Check these exist: `public/.htaccess`, `public/.user.ini`, `public/uploads/.htaccess`.
- [ ] Domain → **Document Root** = `aalto-mart/public` (§6). Skip this step if you extracted into `public_html`.

## 5. Database import (§3.2, §3.3)
- [ ] phpMyAdmin → `CPUSER_aalto` → **Import** → `database/schema.sql` → 9 tables appear.
- [ ] **Do not import `seed.sql`** on the live store. It contains sample products and a published admin password.
- [ ] cPanel → MySQL Databases → user → privileges: only **SELECT, INSERT, UPDATE, DELETE** (§3.4).

## 6. Environment configuration (§5)
- [ ] Copy `.env.example` to **`.env`** (in `aalto-mart/`, not in `public/`), then edit it:
  - [ ] `APP_ENV=production`, `APP_DEBUG=false`
  - [ ] `APP_URL=https://www.yourdomain.com`: https, **no trailing slash**, one host only (with or without `www.`)
  - [ ] `DB_HOST=localhost`, `DB_DATABASE=CPUSER_aalto`, `DB_USERNAME=CPUSER_aaltoapp`, `DB_PASSWORD="…"` (always in quotes)
  - [ ] `STORE_EMAIL`, `STORE_PHONE`, `STORE_RETURN_DAYS` (delivery charges are set in Admin → Delivery)
  - [ ] `SESSION_SECURE=true`
- [ ] `https://yourdomain.com/health` → `{"status":"ok","database":"connected"}` (use `http://` until SSL is active).

## 7. Admin creation (§3.3)
- [ ] **With Terminal:** `php tools/hash-password.php --create --email=you@yourdomain.com --name="Your Name"`. The password needs at least 10 characters (use more).
- [ ] **Without Terminal:** run `php tools/hash-password.php` on your computer, then in phpMyAdmin → SQL:
  `INSERT INTO users (name, email, password_hash, role, is_active) VALUES ('Your Name', 'you@yourdomain.com', '<hash>', 'admin', 1);`
- [ ] Sign in at `https://yourdomain.com/admin`. The dashboard shows **no** red "sample password" warning.

## 8. SSL (§8)
- [ ] cPanel → **SSL/TLS Status** → **Run AutoSSL** for `yourdomain.com` **and** `www.yourdomain.com`, and wait for the padlocks.
- [ ] **Only then** set `APP_URL=https://…` (step 6).
- [ ] `http://yourdomain.com` → **301** → `https://www.yourdomain.com` (your `APP_URL`).
- [ ] **Cloudflare (if used):** SSL **Full (strict)**; **Rocket Loader off**; **Email Obfuscation off**; no "Cache Everything" rule for pages; the admin log shows real visitor IPs.

## 9. `.htaccess` (§7)
- [ ] Nothing to write, the files ship in the package. `/shop`, `/category/<name>` and `/product/<name>` open, and `/shop/` redirects to `/shop`.
- [ ] If you see "… not allowed here" in cPanel → Errors, follow the troubleshooting note in §7.
- [ ] Optional: cPanel → **Optimize Website** → compress all content.

## 10. File permissions (§9)
- [ ] Folders **755**, files **644**, `.env` **600**. **Never 777.**
- [ ] `storage/logs/`, `public/uploads/products/` and `public/uploads/categories/` are writable (755).
- [ ] cPanel → **MultiPHP INI Editor** (§10): `upload_max_filesize` 10M, `post_max_size` 80M, `max_file_uploads` 20, `memory_limit` 256M, `display_errors` **Off**, `log_errors` **On**.

## 11. Server check (§13.1)
- [ ] cPanel → **Terminal**: `cd ~/aalto-mart && php tools/check-requirements.php` ends with **"No failures"**.
- [ ] Every URL in the §12 list returns 403/404: `/.env`, `/.user.ini`, `/error_log`, `/database/schema.sql`, `/storage/logs/`, `/README.md`, `/backup.zip` and the rest.

## 12. Category setup (admin → Categories)
Create categories **before** products; each product must belong to one.
- [ ] **Add category** for each section of the shop (for example Men, Women, Kids, Accessories).
  - **Name**: shown in the menu and on the homepage. The **slug** (URL) is created automatically and can be edited.
  - **Description** (optional): shown on the category page and used as its search-engine description, so write one or two useful sentences.
  - **Image** (optional): shown on the homepage category cards. **Recommended: 320 × 320 px, ratio 1:1** (at least 160 × 160). JPG/PNG/WebP up to 8 MB; it is cropped to a square, converted to WebP and compressed to about 20 KB automatically. The form shows the standard and a live preview.
  - **Status**: Active. Hidden categories and their products disappear from the shop.
- [ ] Check each category page `/category/<slug>` opens (it's empty until products are added).

## 13. Product upload (admin → Products)
- [ ] **Add product** for each item:
  - **Name**, **Category**, **Short description** (shown on the product page and in search results), **Description** (full details, sizes, fabric, care).
  - **SKU**: leave empty to generate one automatically, or use your own stock code.
  - **Regular price**. **Sale price** is optional: it's used only when it's lower than the regular price, and the old price is then shown crossed out with the % saving.
  - **Stock quantity**: the real number available. It goes down automatically with each order and comes back when an order is cancelled. At 0 the product shows "Out of stock" and can't be ordered.
  - **Main image** and up to **8 gallery images**. **Recommended: 800 × 1000 px, ratio 4:5** (at least 400 × 500). JPG/PNG/WebP up to 8 MB each (phone photos are fine). Other ratios are centre-cropped to 4:5; every image is resized, converted to WebP and compressed to about 100 KB. The preview shows the crop before you save, and **Replace** / **Delete** work on each image later. Images are converted to WebP and resized automatically.
  - **Active** (visible in the shop), **Featured** (homepage "Featured"), **New** (a "New" badge; first in New arrivals).
- [ ] Open a few products on a phone: photos, prices and stock look right.
- [ ] A product that has orders **cannot be deleted** (order history is kept). Untick **Active** to hide it instead.

## 14. Final testing (§13)
Use a private browser window and a real phone.
- [ ] **Storefront:** homepage → shop → category → product → **search** (a real word, then nonsense text)
- [ ] **Cart:** add, change quantity, remove; delivery is free over the threshold
- [ ] **Checkout:** name, mobile, address → **Place order** → confirmation with order number `AM-YYYYMMDD-00001`
- [ ] **Admin → Orders:** the order is listed; the product's **stock went down**
- [ ] **Order status:** Confirmed → Processing → Shipped → Delivered (cash on delivery becomes Paid); cancel a second test order → **stock comes back**
- [ ] **Admin:** login; a wrong password shows an error; 5 wrong attempts lock the login for 15 minutes; logout works
- [ ] **Mobile:** no sideways scrolling; the menu opens; sticky Add to cart and Checkout bars
- [ ] **HTTPS:** padlock on every page; `http://` redirects
- [ ] **SEO:** `/robots.txt` and `/sitemap.xml` show your https domain; add the sitemap in **Google Search Console**
- [ ] **Clean up:** cancel or delete test orders; deactivate test products

## 15. Backup
- [ ] **Before launch:** take a first backup. Database via phpMyAdmin → **Export** (SQL), plus the folder `public/uploads/` (all product and category photos).
- [ ] **Every week, and before every update:** cPanel → **Backup** / **Backup Wizard** (database + home directory), or the two items above.
- [ ] Keep backups **off the website folder**: download them, or leave them in cPanel's backup area. Never store `*.sql` or `*.zip` in `public_html` or `public/`.
- [ ] Once, try restoring a backup to a test database, so you know the backup works.
- [ ] Look at `storage/logs/` now and then (errors, failed admin logins) and delete old log files.
