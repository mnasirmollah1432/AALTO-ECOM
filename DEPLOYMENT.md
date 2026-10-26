# Aalto Mart: Production Deployment Guide (cPanel)

Target: cPanel shared hosting · Apache · PHP 8.3 · MySQL 8 or MariaDB 10.4+ · SSL (AutoSSL) · optional Cloudflare.

This guide takes you from the project folder on your computer to a live, secure store. Work through the sections in order; each ends with a check. The **[checklist](#aalto-mart-production-deployment-checklist)** at the bottom repeats every step in short form so you can tick them off.

**What was tested before this guide was written:**
- **MySQL 8.0 and MariaDB 10.4:** both SQL files import cleanly, and the whole application runs with a database user that has only `SELECT, INSERT, UPDATE, DELETE`.
- **Apache 2.4 with this project's `.htaccess` files:** both folder layouts from section 4 were tested. Clean URLs, compression, caching and file blocking all work.
- **A fresh install with no sample data:** creating the first category and product, placing an order, stock reduction and order management all work.
- **PHP:** the code is checked for PHP 8.3 compatibility (tested on 8.2).

Throughout this guide:

| Placeholder | Meaning | Example |
|---|---|---|
| `CPUSER` | your cPanel username | `aaltoshop` |
| `yourdomain.com` | your domain | `aaltomart.com.bd` |
| `CPUSER_aalto` | database name (cPanel adds the `CPUSER_` prefix) | `aaltoshop_aalto` |
| `CPUSER_aaltoapp` | database user | `aaltoshop_aaltoapp` |

---

## 0. Before you start: build the upload package

On your computer, in the project folder:

```bash
php tools/build-release.php
```

This creates `aalto-mart-release-YYYYMMDD-HHMM.zip` **next to** the project folder. The package:
- **includes** all code, `database/schema.sql` and `seed.sql`, the tools, and every `.htaccess` / `.user.ini` security file (these are easy to lose when you zip by hand, because they start with a dot);
- **leaves out** your local `.env` (it holds your local database password), your log files, and your local uploaded images.

No PHP on your computer? Zip the folder with 7-Zip instead. Include: `.htaccess`, `.env.example`, `app/`, `database/`, `public/`, `storage/`, `tools/`, `README.md`, `DEPLOYMENT.md`. Leave out `.env`, `storage/logs/*.log` and the images inside `public/uploads/products/` and `public/uploads/categories/`.

> **Never upload your local `.env`.** You create a new one on the server in step 5.

---

## 1. Select PHP 8.3

cPanel hosts offer one of two tools. Use whichever your cPanel shows.

**A. MultiPHP Manager** (standard cPanel)
1. cPanel → **Software → MultiPHP Manager**.
2. Tick the checkbox next to `yourdomain.com`.
3. In **PHP Version**, choose **`ea-php83` (PHP 8.3)** and click **Apply**.

**B. Select PHP Version** (CloudLinux hosts)
1. cPanel → **Software → Select PHP Version**.
2. Set **Current PHP version** to **8.3** and click **Set as current**.
3. In MultiPHP Manager, leave the domain on the default ("inherit"), so the CloudLinux setting applies.

**Check:** section 13 runs `tools/check-requirements.php`. Its first line shows the PHP version. The website version is the one set above. If the Terminal's `php` command reports a different version, run the tool with `/opt/cpanel/ea-php83/root/usr/bin/php` instead.

---

## 2. PHP extensions

| Extension | Required? | Used for |
|---|---|---|
| `pdo_mysql` (with `mysqlnd`) | **Required** | Database. On CloudLinux the name is **`nd_pdo_mysql`**: tick that one and untick `pdo_mysql`. |
| `mbstring` | **Required** | UTF-8 text (names, addresses, search) |
| `gd` | **Required** | Image uploads: resize and convert to WebP. Needs **JPEG, PNG and WebP support** (normal in cPanel builds). |
| `fileinfo` | **Required** | Detects the real type of uploaded files |
| `ctype`, `filter`, `json`, `session`, `hash` | **Required** | Built into PHP 8.3; they are normally always on |
| `exif` | Recommended | Rotates phone photos the right way up |
| `iconv` | Recommended | Turns accented letters into URL slugs ("Café" → `cafe`) |
| `opcache` | Recommended | Much faster PHP (caches compiled code) |

Not needed: `intl`, `zip`, `curl`, `openssl`, `imagick`, `redis`, Composer.

**How to enable them**
- **MultiPHP Manager hosts:** extensions are chosen by the host in WHM → EasyApache 4. The package names are `ea-php83-php-pdo`, `-php-mysqlnd`, `-php-mbstring`, `-php-gd`, `-php-fileinfo`, `-php-exif`, `-php-iconv` and `-php-opcache`. If one is missing, ask your host to enable it.
- **Select PHP Version hosts:** open the **Extensions** tab, tick each one and save.

---

## 3. MySQL / MariaDB

### 3.1 Create the database, the user and privileges
cPanel → **Databases → MySQL® Database Wizard**:

1. **Step 1, create the database:** type `aalto` → **Next Step**. The full name becomes `CPUSER_aalto`; write it down.
2. **Step 2, create the user:** type `aaltoapp`, click **Password Generator** and use a long random password. Write down the full user name `CPUSER_aaltoapp` and the password. Click **Create User**.
3. **Step 3, privileges:** tick **ALL PRIVILEGES** → **Next Step**. This is needed for the import; you reduce it in 3.4.

**Collation:** open **phpMyAdmin**, select `CPUSER_aalto` → **Operations** → **Collation** = `utf8mb4_unicode_ci` → **Go**. The tables set utf8mb4 themselves; this only fixes the database default.

### 3.2 Import `schema.sql` (always)
1. Upload the files first (section 4), or have `database/schema.sql` on your computer.
2. cPanel → **Databases → phpMyAdmin**. Click `CPUSER_aalto` in the left column.
3. **Import** tab → **Choose File** → `database/schema.sql` → Format **SQL** → **Import**.
4. The left column now lists 9 tables: `categories, login_attempts, order_items, order_sequences, order_status_history, orders, product_images, products, users`.

> ⚠️ `schema.sql` **deletes and recreates all store tables.** Run it once, on an empty database. Never run it on a live store with orders.

### 3.3 `seed.sql` (sample data): only for a demo or staging site
`seed.sql` adds 5 sample categories, 25 sample products (SKU `SMP-…`, placeholder images) and a **sample admin whose password is published in the README** (`admin@example.com` / `Admin@12345`).

**For the real store, do NOT import `seed.sql`.** Instead, create your own admin account:

- **With Terminal/SSH:** cPanel → **Advanced → Terminal**:
  ```bash
  cd ~/aalto-mart            # or ~/public_html for layout B (section 4)
  php tools/hash-password.php --create --email=you@yourdomain.com --name="Your Name"
  ```
  Type the password twice (hidden, at least 10 characters).
- **Without Terminal:** on your own computer, in the project folder, run `php tools/hash-password.php`, type the password and copy the hash it prints (it starts with `$2y$`). Then in phpMyAdmin → `CPUSER_aalto` → **SQL** tab:
  ```sql
  INSERT INTO users (name, email, password_hash, role, is_active)
  VALUES ('Your Name', 'you@yourdomain.com', '<paste the $2y$... hash here>', 'admin', 1);
  ```

**For a demo site** that should show sample products:
1. Import `database/seed.sql` exactly like 3.2, **after** `schema.sql`.
2. Immediately create your own admin as above. Then remove the sample admin: `DELETE FROM users WHERE email = 'admin@example.com';`
3. Before going live for real, remove the sample catalogue:
   ```sql
   DELETE FROM products   WHERE sku LIKE 'SMP-%';
   DELETE FROM categories WHERE description LIKE 'Sample category%';
   ```

### 3.4 Reduce the privileges (after importing)
The website only reads and writes rows; it never changes tables.

1. cPanel → **Databases → MySQL® Databases** → **Current Databases** → click the user `CPUSER_aaltoapp`. This opens **Manage User Privileges**.
2. Untick **ALL PRIVILEGES**, then tick only **SELECT, INSERT, UPDATE, DELETE** → **Make Changes**.

Tested: the whole store, including checkout and stock locking, works with only these four privileges. For a future database upgrade (`database/migrations/`), temporarily tick ALL PRIVILEGES again, then remove it afterwards.

---

## 4. Files: where to upload

Choose **one** layout.

### Layout A (recommended): project outside `public_html`
Only the `public/` folder can be reached from the web. `.env`, the code and the SQL files sit outside it and can never be downloaded.

```text
/home/CPUSER/
├── public_html/            (leave as it is, or unused)
└── aalto-mart/             ← extract the package here
    ├── .env                ← you create it (section 5)
    ├── .htaccess
    ├── app/  database/  storage/  tools/
    └── public/             ← the domain's document root (section 6)
        ├── index.php  .htaccess  .user.ini
        ├── assets/
        └── uploads/
```

1. cPanel → **Files → File Manager**. Open **Settings** (top right), tick **Show Hidden Files (dotfiles)** and save. Without this you cannot see `.htaccess` or `.env`.
2. In `/home/CPUSER/`, click **+ Folder** and create `aalto-mart`.
3. Open `aalto-mart` → **Upload** → choose the release `.zip` → wait for 100 % → go back.
4. Right-click the zip → **Extract** → extract to `/home/CPUSER/aalto-mart`.
5. **Delete the zip** after extracting. A zip of the site left on the server is a leak waiting to happen.
6. **Check:** `aalto-mart/public/.htaccess`, `aalto-mart/public/.user.ini` and `aalto-mart/public/uploads/.htaccess` exist.

### Layout B: everything inside `public_html`
Use this only if you cannot change the domain's document root (sometimes the case for a primary domain).

Extract the same package **directly into `/home/CPUSER/public_html/`**. The root `.htaccess` sends every request into `public/` and refuses `.env`, `app/`, `database/`, `storage/`, `tools/`, `*.sql`, `*.md`, logs and backups. Both layouts were tested on Apache. Layout A is still safer, because the protection does not depend on `.htaccess` at all.

---

## 5. Configuration (`.env`)

1. In File Manager, open the project folder (`aalto-mart/` or `public_html/`).
2. Right-click **`.env.example`** → **Copy** → name the copy **`.env`**. Right-click `.env` → **Edit**.
3. Set these values:

| Key | Production value | Notes |
|---|---|---|
| `APP_NAME` | `"Aalto Mart"` | Shop name (titles, emails, structured data) |
| `APP_ENV` | **`production`** | Hides all error details. Redirects http → https and to the one canonical host. |
| `APP_DEBUG` | **`false`** | Ignored in production anyway, but keep it false |
| `APP_URL` | **`https://www.yourdomain.com`** | Exact public address: `https://`, **no trailing slash**. Choose **either** `www.` **or** the bare domain; the other one redirects to it. Canonical links, the sitemap and social tags use this value. |
| `APP_TIMEZONE` | `Asia/Dhaka` | Order times and dates |
| `STORE_CURRENCY` / `STORE_CURRENCY_SYMBOL` | `BDT` / `"৳"` | |
| `STORE_EMAIL` | your real address | Shown in the footer |
| `STORE_PHONE` | `"+880 1XXX-XXXXXX"` | Shown in the header and footer |
| `STORE_COUNTRY` / `STORE_RETURN_DAYS` | `BD` / `7` | Delivery country and return window |
| `DB_HOST` | **`localhost`** | cPanel's database server is local |
| `DB_PORT` | `3306` | |
| `DB_DATABASE` | **`CPUSER_aalto`** | Full name with the prefix |
| `DB_USERNAME` | **`CPUSER_aaltoapp`** | Never `root` or your cPanel login |
| `DB_PASSWORD` | **`"the generated password"`** | **Always in double quotes.** An unquoted password containing ` #` would be cut off. If the password itself contains `"`, use single quotes. |
| `DB_CHARSET` | `utf8mb4` | |
| `SESSION_SECURE` | `true` | The cookie is only sent over HTTPS (also switched on automatically on HTTPS) |
| `ADMIN_*`, `UPLOAD_IMAGE_MAX_KB` | defaults | Admin session timeouts and lockout; largest image file accepted (8192 KB). Image sizes and ratios are in `app/config/images.php` |

4. **Save**. Then right-click `.env` → **Change Permissions** → **600** (owner read/write only; see section 9).
5. **Check:** `https://yourdomain.com/health` should show `{"status":"ok","database":"connected"}`. That needs SSL (section 8); before SSL is active, test with `http://`.

Nothing in `app/config/` needs editing: all settings come from `.env`.

---

## 6. Document root

**Layout A:**
- **Domain already added:** cPanel → **Domains → Domains** → **Manage** next to `yourdomain.com` → set **Document Root** to `aalto-mart/public` (full path `/home/CPUSER/aalto-mart/public`) → **Update**. Newer cPanel versions show this field for addon and sub domains. If it is locked for your primary domain, use Layout B or ask your host to point the domain at that folder.
- **Adding a new domain:** cPanel → **Domains → Create A New Domain** → untick "Share document root" → Document Root `aalto-mart/public` → **Submit**.
- **`www`:** the `www.` host uses the same document root automatically.

**Layout B:** nothing to change; the document root stays `public_html`.

**Subfolder install** (e.g. `yourdomain.com/shop`): extract to `public_html/shop/` (Layout B inside the folder) and set `APP_URL=https://yourdomain.com/shop`. The app reads the base path from `APP_URL`.

**Check:** `https://yourdomain.com/` shows the Aalto Mart homepage, not a folder listing or cPanel's default page.

---

## 7. `.htaccess`: clean URLs

Nothing to write. The package already contains:

| File | Does |
|---|---|
| `public/.htaccess` | Clean URLs (everything that isn't a real file goes to `index.php`); 1-year caching for versioned CSS/JS/images; Brotli/gzip compression; security headers for static files; hides PHP errors on mod_php hosts; refuses hidden files, `error_log`, SQL dumps, archives and backups |
| `.htaccess` (project root) | Layout B only: forwards everything into `public/` and refuses sensitive files |
| `public/uploads/.htaccess` | Uploaded images only: no PHP or scripts can run there |
| `app/`, `database/`, `storage/`, `tools/` `.htaccess` | Deny all web access (backup protection) |

Requirements are standard on cPanel: `mod_rewrite` and `AllowOverride All`. Compression needs `mod_deflate` or `mod_brotli`; if it's off, turn it on in cPanel → **Software → Optimize Website** → "Compress all content".

**Check:** `https://yourdomain.com/shop`, `/category/<a-category>` and `/product/<a-product>` open. `https://yourdomain.com/shop/` redirects to `/shop`.

**If something is wrong:**
- **404 on `/shop` while `/` works:** `.htaccess` is missing (hidden files weren't uploaded) or `mod_rewrite` is off.
- **500 on every page:** see cPanel → **Metrics → Errors**. Usually a host that disallows a directive; send the error line to your host.
- **"… not allowed here" in the error log** (e.g. `Options not allowed here`, `<IfModule not allowed here`): the host restricts `AllowOverride`. Apache reads `.htaccess` files in **parent** folders too, so in Layout A the project-root `.htaccess` in `aalto-mart/` is also read. Fix: delete the `Options -Indexes` line named in the error (directory listing is usually already off on cPanel), or ask the host for `AllowOverride All`. In Layout A you may also delete `aalto-mart/.htaccess` completely, because that folder is not reachable from the web anyway.

---

## 8. SSL / HTTPS

1. cPanel → **Security → SSL/TLS Status** → select `yourdomain.com` **and** `www.yourdomain.com` → **Run AutoSSL**. Wait until both show a valid certificate (a padlock).
2. Only **after** the certificate works: make sure `.env` has `APP_URL=https://…` and `APP_ENV=production` (section 5). The app then:
   - redirects `http://` → `https://` and the other host (`www`/bare) → your `APP_URL` host (301);
   - sends `Strict-Transport-Security` (HSTS, 1 year) and `upgrade-insecure-requests`;
   - marks the session cookie `Secure`.

   Do not set an `https://` `APP_URL` before the certificate exists, or visitors are sent to a broken https page.
3. Optional: also force HTTPS at server level by removing the `#` from the 3 commented `RewriteCond/RewriteRule` lines at the top of `public/.htaccess`.

**Check (in a browser, or with `curl -I` from any computer):**
- `http://yourdomain.com/` returns **301** → `https://www.yourdomain.com/` (or your chosen host).
- `https://www.yourdomain.com/` shows the padlock, with no "mixed content" warnings (the site loads nothing from other domains).
- The response headers include `strict-transport-security` and `content-security-policy` (browser DevTools → Network → the page → Headers).

### Optional: Cloudflare
- **SSL/TLS mode: Full (strict).** Never "Flexible". Keep AutoSSL on the server, or install a Cloudflare Origin Certificate in cPanel → SSL/TLS.
- **Caching:** the defaults are fine (static files only). **Do not** create a "Cache Everything" rule for HTML. Cart, checkout, order confirmation and admin pages are personal and must never be shared from a cache.
- **Speed → Optimization:** turn **off Rocket Loader**. It injects scripts that the site's security policy blocks.
- **Scrape Shield:** turn **off Email Address Obfuscation**. Its script is blocked, so the footer email would show as "[email protected]".
- **Real visitor IPs:** admin login lockout counts failures per IP address. Behind Cloudflare the server must see the visitor's IP, not Cloudflare's. Most cPanel hosts have `mod_remoteip` set up for this. To check: make one failed admin login on purpose, then look at `storage/logs/app-YYYY-MM-DD.log`. The line "Failed admin login … from **IP**" must show **your** IP (compare with a "what is my IP" website). If it shows a Cloudflare address (e.g. `172.64.…`, `104.…`, `162.158.…`), ask your host to enable `mod_remoteip` for Cloudflare. Otherwise lockouts could block every admin at once.

---

## 9. Permissions

cPanel runs PHP as your own user (PHP-FPM/suPHP), so the owner permissions are what matter. **Never use 777.**

| Path | Permission |
|---|---|
| All folders | **755** |
| All files | **644** |
| `.env` | **600** (use **640** only if the site then can't read it, on rare hosts where PHP runs as a different user) |
| `storage/logs/` | **755**, writable by your user (the app writes logs here) |
| `public/uploads/`, `public/uploads/products/`, `public/uploads/categories/` | **755**, writable by your user |

**File Manager:** right-click → **Change Permissions**. **Terminal:**
```bash
cd ~/aalto-mart            # or ~/public_html for layout B
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 600 .env
```

---

## 10. Upload directory (product and category images)

The upload folders are `public/uploads/products/` and `public/uploads/categories/`. They are created automatically if missing and must be writable (section 9).

**What keeps them safe (already built in, nothing to configure):**
- Only JPG, PNG and WebP are accepted. The type is detected from the file content, and the extension must match.
- Files up to `UPLOAD_IMAGE_MAX_KB` (8 MB, enough for phone photos) are accepted. Images over 16 megapixels, or below the section's minimum size, are refused.
- Each section has a standard (`app/config/images.php`): **products 800 × 1000 (4:5), categories 320 × 320 (1:1)**. Other ratios are centre-cropped, then images are resized, converted to WebP and compressed to a size target (products ≤ 100 KB, categories ≤ 20 KB).
- Every image is re-drawn by GD and saved as WebP under a **random name**, which removes hidden payloads and metadata. The original file name is never used.
- `public/uploads/.htaccess` stops PHP or any script running there and serves only image files.

**PHP limits to allow uploads:** cPanel → **Software → MultiPHP INI Editor** → **Basic Mode** → choose the domain:

| Setting | Value | Why |
|---|---|---|
| `upload_max_filesize` | **10M** (min 8M) | One image up to 8 MB (a phone photo) |
| `post_max_size` | **80M** (min 40M) | A product form can send 1 main + 8 gallery images |
| `max_file_uploads` | **20** (min 9) | Same |
| `memory_limit` | **256M** (min 128M) | Decoding and resizing large photos |
| `max_execution_time` | **60** | Converting several images |
| `display_errors` | **Off** | See section 11 |
| `log_errors` | **On** | See section 11 |

> ⚠️ **MultiPHP INI Editor note (Layout A):** the editor saves its settings as a `.user.ini` file in the domain's document root, which **replaces** the project's `public/.user.ini`. That is fine as long as `display_errors` is Off and `log_errors` is On in the editor. After saving, open `public/.user.ini` to confirm those two lines are there.

**Backups:** product photos exist only in `public/uploads/`. Include this folder in your backups (section 12).

**Check:** in the admin, create a category with an image and a product with a main image and an extra image. Both appear on the site. Then open `https://yourdomain.com/uploads/products/anything.php`: it must **not** run (403 or 404).

---

## 11. Production errors: hidden from visitors, logged for you

Everything is already in place; you only need `APP_ENV=production` (section 5).

| Layer | What it does |
|---|---|
| `APP_ENV=production` | The app never shows error details, even if `APP_DEBUG` was left on. Crashes (including fatal errors like out-of-memory) show a clean "Something went wrong" page with status **500** and `noindex`. |
| `public/.user.ini` (PHP-FPM/CGI) and `public/.htaccess` (mod_php) | `display_errors = Off` **before** the app starts, so even a start-up or syntax error prints nothing |
| MultiPHP INI Editor (section 10) | `display_errors` Off, `log_errors` On, at host level |

**Where errors are logged:**
- `storage/logs/app-YYYY-MM-DD.log`: application errors, failed admin logins, placed orders.
- `storage/logs/php-error.log`: PHP warnings and errors.
- `public/error_log` (or `public_html/error_log`): cPanel's own file, for errors before the app starts. It is **blocked from download** by `.htaccess`.
- cPanel → **Metrics → Errors**: Apache errors.

Look at these regularly and delete old log files now and then; they are not rotated automatically.

**Check:** `https://yourdomain.com/this-page-does-not-exist` shows the store's 404 page. No PHP warnings, paths or "Fatal error" text appear anywhere on the site.

---

## 12. Security: nothing sensitive can be downloaded

Protected by the design (Layout A) and the `.htaccess` rules (both layouts). Tested on Apache:

| What | Protection |
|---|---|
| `.env` (database password) | Outside the web root (A) · refused by `.htaccess` (B) · permission 600 |
| Configuration: `app/config/*.php`, `app/`, `.user.ini`, `.htaccess` | Outside the web root (A) · deny-all `.htaccess` (B) · dotfiles refused |
| SQL files: `database/*.sql` | Outside the web root (A) · `.sql` refused anywhere |
| Database backups and archives | `*.sql`, `*.gz`, `*.zip`, `*.tar`, `*.tgz`, `*.rar`, `*.7z`, `*.bak`, `*.old`, `*.orig`, `*.swp` are refused anywhere in the site |
| Logs: `storage/logs/`, `error_log`, `*.log` | Outside the web root (A) · refused (B) · `error_log` refused in `public/` |
| Tools: `tools/*.php` | Outside the web root (A) · deny-all (B) · they also refuse to run from a browser |
| Docs: `README.md`, `DEPLOYMENT.md` | Refused (`.md`); the README lists the sample password |
| Admin | Login lockout, session timeouts, CSRF on every form, never cached or indexed |

**Rules for yourself:**
- Keep database backups in cPanel → **Files → Backup** / **Backup Wizard**, or download them. **Never leave backups inside `public_html` or `public/`**, even though the extensions above are blocked.
- Delete the release zip after extracting (section 4).
- Change the sample password if `seed.sql` was ever imported (the dashboard shows a red warning while it is in use).
- Keep `.env` out of any Git repository (`.gitignore` already excludes it).

**Check:** each of these must return **403 or 404** and never show file contents. Replace `yourdomain.com`:
```text
https://yourdomain.com/.env
https://yourdomain.com/.env.example
https://yourdomain.com/.user.ini
https://yourdomain.com/.htaccess
https://yourdomain.com/error_log
https://yourdomain.com/database/schema.sql
https://yourdomain.com/database/seed.sql
https://yourdomain.com/app/config/database.php
https://yourdomain.com/storage/logs/
https://yourdomain.com/tools/hash-password.php
https://yourdomain.com/README.md
https://yourdomain.com/backup.zip          (any archive name)
https://yourdomain.com/uploads/.htaccess
```

---

## 13. Final testing

### 13.1 Server check (Terminal)
```bash
cd ~/aalto-mart            # or ~/public_html
php tools/check-requirements.php
```
It checks the PHP version and extensions, WebP support, writable folders, the security files, the `.env` production values, the database connection and tables, the database user's privileges, and that no sample password or sample admin is in use. **Fix every `FAIL`**, and read every `WARN`. The command ends with "No failures" when everything is right.

No Terminal? Then check the same points by hand: sections 2, 5, 9 and 12.

### 13.2 In the browser: storefront
Use a private window and a real phone. After each step, confirm the expected result.

| # | Test | Expected |
|---|---|---|
| 1 | **Homepage** `https://www.yourdomain.com/` | Loads with a padlock; categories and featured/new products (once added); no errors |
| 2 | **Shop** `/shop` | Product grid; filters and sort work; page 2 works if there are more than 12 products |
| 3 | **Categories** menu → a category | `/category/<name>` lists only that category's products |
| 4 | **Product** → open a product | `/product/<name>`; images (click one: full-screen zoom), price (sale price with the old price crossed out), stock; changing the quantity changes "Total"; Add to cart. A product with sizes/colours asks for them and crosses out sold-out ones |
| 5 | **Search** a word from a product name; then nonsense text | Matching products; then "No products found" |
| 6 | **Cart**: add 2, change the quantity, remove, add again | Badge count and subtotal are correct; the zone charges (Inside Dhaka / Outside Dhaka) are listed |
| 7 | **Checkout**: name, mobile (e.g. `01712345678`), district, thana, address → Place order | Choosing Dhaka adds ৳70, another district ৳130; "Your Address: …" appears above the address. Confirmation page with the order number (`AM-YYYYMMDD-00001`); cart empty |
| 8 | **Order creation**: admin → Orders | The order is there, status Pending, correct total and items |
| 9 | **Stock reduction**: admin → Products | The product's stock went down by the ordered quantity |
| 10 | Cancel that test order (admin → order → Cancelled) | Stock goes back up |

### 13.3 In the browser: admin (`/admin`)
| # | Test | Expected |
|---|---|---|
| 11 | **Admin login** with your real account | Dashboard; **no** red "sample password" warning. A wrong password shows "Incorrect email or password"; 5 wrong tries lock the login for 15 minutes. |
| 12 | **Category management**: add (with image), edit, hide, show | Changes appear on the site; the image is shown |
| 13 | **Product management**: add (main + extra images, sale price), edit, toggle Active/Featured/New | Shown correctly on home, shop and product page; a hidden product returns 404 in the store |
| 13b | **Delivery** (admin → Delivery): change a charge, add a thana to a district | Checkout shows the new charge and the thana |
| 14 | **Order management**: open the test order → Confirmed → Processing → Shipped → Delivered | Each step is saved with the time; Delivered marks cash-on-delivery as Paid; the dashboard figures update |
| 15 | **Sign out** | Back to the login page; `/admin/dashboard` asks for login again |

### 13.4 Mobile, HTTPS, SEO
| # | Test | Expected |
|---|---|---|
| 16 | **Mobile** (real phone, and DevTools at 360 / 390 / 414 px) | No sideways scrolling; the menu opens as a side panel; sticky "Add to cart" and "Checkout" bars; the admin is usable |
| 17 | **HTTPS**: open `http://yourdomain.com` | Redirects to `https://www.yourdomain.com` (your `APP_URL`); padlock; no mixed-content warnings |
| 18 | **SEO**: `/robots.txt`, `/sitemap.xml` | Both load and use your `https://` domain; submit the sitemap in Google Search Console |
| 19 | **Errors**: `/no-such-page` | The store's 404 page, no technical details |
| 20 | **Security URLs** from section 12 | All 403/404 |

**After testing:** delete or cancel the test orders (cancel returns the stock), and remove any test products and categories. Products that already have orders cannot be deleted; deactivate them instead.

---

## Steadfast courier webhook

1. In **Admin → Courier**, enter the API key and secret key, tick "Use Steadfast for orders", save, then click **Test connection**.
2. Copy the **Callback URL** (`https://yourdomain.com/webhooks/steadfast`) and the **Auth token** into the Steadfast merchant panel's webhook settings.
3. **If Steadfast shows 401 (Unauthorized):** the server is not passing the `Authorization` header to PHP. Add these two lines right after `RewriteEngine On` in `public_html/.htaccess`. Edit the file in File Manager; don't replace it, because cPanel keeps its PHP version lines there.
   ```apache
   RewriteCond %{HTTP:Authorization} .
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```
4. The `curl` PHP extension must be on (`php tools/check-requirements.php` shows it).

---

## Ongoing: updates and backups

- **Update the code:** build a new package (section 0), upload it and extract it over the existing folder. `.env`, logs and uploaded images are never in the package, so they are kept. Then run `php tools/check-requirements.php`. If an update includes a file in `database/migrations/`, run it in phpMyAdmin (with temporary ALL PRIVILEGES) **before** extracting. In Layout B, extracting overwrites `public_html/.htaccess`; if cPanel had added PHP-version lines to it, select PHP 8.3 again afterwards (section 1), or upload only the changed files instead of the whole package.
- **Back up:** at least weekly, and before every update, back up the **database** (phpMyAdmin → Export, or cPanel Backup) **and** `public/uploads/`. Keep backups off the public web folder.
- **Monitor:** `storage/logs/` for errors and failed admin logins; the admin order list for suspicious orders (see "Order spam" in README → Security audit).

---

## AALTO MART PRODUCTION DEPLOYMENT CHECKLIST

**Before upload**
- [ ] Release package built (`php tools/build-release.php`), or zipped by hand without `.env`, logs or local uploads
- [ ] Domain DNS points to the cPanel server

**1–2. PHP**
- [ ] PHP **8.3** selected for the domain (MultiPHP Manager `ea-php83`, or Select PHP Version 8.3)
- [ ] Extensions on: `pdo_mysql`/`nd_pdo_mysql`, `mbstring`, `gd` (JPEG+PNG+WebP), `fileinfo`; recommended `exif`, `iconv`, `opcache`

**3. Database**
- [ ] Database `CPUSER_aalto` created (collation `utf8mb4_unicode_ci`)
- [ ] User `CPUSER_aaltoapp` created with a generated password; ALL PRIVILEGES for the import
- [ ] `database/schema.sql` imported → 9 tables
- [ ] `seed.sql` **not** imported on the live store (or, on demo/staging: imported, sample admin replaced, and sample data removed before go-live)
- [ ] Own admin account created (`tools/hash-password.php --create`, or INSERT with a hash)
- [ ] Privileges reduced to **SELECT, INSERT, UPDATE, DELETE**

**4. Files**
- [ ] Hidden files visible in File Manager
- [ ] Package extracted to `/home/CPUSER/aalto-mart/` (Layout A) or `public_html/` (Layout B)
- [ ] `public/.htaccess`, `public/.user.ini`, `public/uploads/.htaccess` present
- [ ] Release zip deleted from the server

**5. Configuration**
- [ ] `.env` created from `.env.example`
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_URL=https://www.yourdomain.com` (one host, https, no trailing slash)
- [ ] `DB_HOST=localhost`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (in quotes) set
- [ ] `STORE_EMAIL`, `STORE_PHONE`, delivery fee and free-delivery threshold set
- [ ] `SESSION_SECURE=true`

**6–7. Document root and URLs**
- [ ] Document root = `aalto-mart/public` (A) or `public_html` (B)
- [ ] Homepage loads; `/shop`, `/category/…` and `/product/…` work; `/shop/` redirects to `/shop`

**8. SSL**
- [ ] AutoSSL certificate valid for `yourdomain.com` and `www.yourdomain.com`
- [ ] `http://` → 301 → `https://` + canonical host; padlock; HSTS header present
- [ ] (Cloudflare) SSL Full (strict), Rocket Loader off, Email Obfuscation off, no HTML "Cache Everything", real visitor IP in the log

**9–10. Permissions and uploads**
- [ ] Folders 755, files 644, `.env` 600, nothing 777
- [ ] `storage/logs/` and `public/uploads/{products,categories}/` writable
- [ ] MultiPHP INI: `upload_max_filesize` 10M (min 8M), `post_max_size` 80M (min 40M), `max_file_uploads` 20 (min 9), `memory_limit` 256M (min 128M)
- [ ] Image upload works for a category and a product; a `.php` URL inside `/uploads/` does not run

**11. Errors**
- [ ] `display_errors` Off (`.user.ini` / `.htaccess` / MultiPHP INI Editor), `log_errors` On
- [ ] The 404 page is clean; no PHP messages anywhere; `storage/logs/` receives logs

**12. Security**
- [ ] Every URL in the section 12 list returns 403/404
- [ ] No backups, zips or SQL dumps inside the web folder
- [ ] No account uses the sample password; the sample admin is removed

**13. Final testing**
- [ ] `php tools/check-requirements.php` → "No failures"
- [ ] Homepage · Shop · Categories · Product · Search
- [ ] Cart · Checkout · Order created · Stock reduced (and restored on cancel)
- [ ] Admin login (and lockout) · Product management · Category management · Order management (full status flow)
- [ ] Mobile (real phone + 360/390/414 px) · HTTPS
- [ ] `robots.txt` + `sitemap.xml` on the https domain; sitemap submitted to Google Search Console
- [ ] Test orders, products and categories cleaned up

**After go-live**
- [ ] Database and `public/uploads/` backup scheduled (weekly and before each update)
- [ ] Log files checked regularly
