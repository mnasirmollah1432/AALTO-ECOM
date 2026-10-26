# Aalto Mart

A lightweight PHP e-commerce store. Plain PHP + PDO + MySQL, Bootstrap 5 and Vanilla JS — no frameworks, no build step, no Composer. Runs on standard Apache / cPanel shared hosting.

> **Status: Phase 8 (admin order management).** The storefront, session shopping cart, Cash-on-Delivery checkout, and the admin (dashboard, categories, products and orders) are all real.

## Requirements

- PHP 8.2+ (8.3+ recommended) with `pdo_mysql` and `mbstring`
- MySQL 8.0.16+ or MariaDB 10.4+ (CHECK constraints and generated columns are used)
- Apache with `mod_rewrite` (for pretty URLs)

## Project structure

```text
AALTO-ECOM/
├── app/
│   ├── bootstrap.php        paths, autoloader, .env, config, errors, session
│   ├── routes.php           all URL routes
│   ├── config/              app.php, database.php (values come from .env)
│   ├── core/                Env, Config, Database (PDO), Router, Auth
│   ├── controllers/         request handlers (admin/ = admin panel)
│   ├── models/              database queries
│   ├── helpers/             functions.php (general), database.php (PDO helpers)
│   └── views/               layouts/, partials/, pages/, errors/
├── public/                  ← web document root
│   ├── index.php            front controller
│   ├── .htaccess            rewrites, compression, browser caching
│   ├── assets/              css/, js/, images/ (incl. icons.svg sprite), vendor/ (Bootstrap)
│   └── uploads/             uploaded product/category images (WebP)
├── database/
│   ├── schema.sql           tables
│   ├── seed.sql             sample data + sample admin (development/demo only)
│   └── migrations/          upgrades for existing databases
├── storage/logs/            app + PHP error logs
├── tools/                   CLI only: hash-password.php (admin password / create admin),
│                            build-release.php (upload zip), check-requirements.php (server check)
├── .htaccess                forwards to /public if docroot is the project root
├── DEPLOYMENT.md            production deployment guide
├── PRODUCTION-CHECKLIST.md  launch-day checklist
└── .env.example
```

Only `public/` should be reachable from the web.

## Setup (local)

1. **Create a database**
   ```sql
   CREATE DATABASE aalto_mart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. **Import schema, then seed data** (in that order)
   ```bash
   mysql -u root -p aalto_mart < database/schema.sql
   mysql -u root -p aalto_mart < database/seed.sql
   ```
   Or in phpMyAdmin: select the database → *Import* → `schema.sql`, then `seed.sql`.
   `schema.sql` drops and recreates all store tables, so never run it on a live database.
3. **Configure the environment**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `APP_URL`.
4. **Run it**
   - PHP built-in server (quickest):
     ```bash
     php -S localhost:8080 -t public public/index.php
     ```
     With `APP_URL=http://localhost:8080`, open http://localhost:8080.
   - XAMPP/Apache: put the project in `htdocs`, set `APP_URL=http://localhost/AALTO-ECOM/public` (or point a virtual host at `public/`).
5. **Check it**: http://localhost:8080/health should return `{"status":"ok","database":"connected"}`.

## Deploying to cPanel

The complete step-by-step guide is in **[DEPLOYMENT.md](DEPLOYMENT.md)**; the launch-day tick-list (including category/product setup and backups) is **[PRODUCTION-CHECKLIST.md](PRODUCTION-CHECKLIST.md)**. It covers PHP 8.3, extensions, the database, upload layout, `.env`, document root, SSL/Cloudflare, permissions, uploads, error handling, security and final testing.

The short version:
1. `php tools/build-release.php`: builds the upload zip (includes the `.htaccess` files; leaves out `.env`, logs and local images).
2. Create the database and user, import **only `schema.sql`**, create your admin with `tools/hash-password.php --create`, then reduce the user to `SELECT, INSERT, UPDATE, DELETE`.
3. Extract the zip to `/home/CPUSER/aalto-mart/` and point the domain's document root at `aalto-mart/public` (or extract into `public_html`).
4. Create `.env` (`APP_ENV=production`, `APP_URL=https://…`, database credentials), chmod 600.
5. Run AutoSSL, then `php tools/check-requirements.php` on the server until it reports "No failures", then do the browser tests in DEPLOYMENT.md §13.
## Admin password

**New install (production):** after importing `schema.sql`, create the admin account from a shell:
```bash
php tools/hash-password.php --create --email=you@your-domain.com --name="Your Name"
```
You are prompted for the password twice (hidden, min. 10 characters). Without shell access, run `php tools/hash-password.php` on your own computer to get a hash, then in phpMyAdmin → SQL:
```sql
INSERT INTO users (name, email, password_hash, role, is_active)
VALUES ('Your Name', 'you@your-domain.com', '<paste hash here>', 'admin', 1);
```

**Development / demo:** `seed.sql` creates one admin account:

| Email | Password |
|---|---|
| `admin@example.com` | `Admin@12345` |

Only a bcrypt hash from PHP's `password_hash()` is stored in the database. **Change the password before the site goes anywhere public**: the dashboard shows a red warning for as long as the sample password is in use. Use one of these methods.

**Method A: CLI tool (updates the database in `.env` directly)**
```bash
php tools/hash-password.php --email=admin@example.com
```
You are prompted for the new password twice (input hidden, min. 10 characters).

**Method B: generate a hash, then run SQL / phpMyAdmin**
```bash
php tools/hash-password.php
```
or, if you have no shell access, run a one-off PHP snippet anywhere:
```php
<?php echo password_hash('YourNewStrongPassword', PASSWORD_DEFAULT);
```
Then, in phpMyAdmin → SQL:
```sql
UPDATE users SET password_hash = '<paste hash here>' WHERE email = 'admin@example.com';
```
Delete any temporary PHP file you used afterwards.

To change the admin email too: `UPDATE users SET email = 'you@yourdomain.com' WHERE id = 1;`

## Storefront

| URL | Page |
|---|---|
| `/` | Hero, categories, featured products, new products, promotions |
| `/shop` | All products: search, category, on sale / in stock / new filters, sort, 12 per page |
| `/category/{slug}` | Category name, description, product count and its products (same filters and sort) |
| `/product/{slug}` | Image gallery, prices and discount, stock status, quantity, add to cart, description, related products |
| `/robots.txt`, `/sitemap.xml` | Generated from `APP_URL`; the sitemap lists only visible categories and products |

- **Clean URLs:** `/shop?category=fashion` redirects (301) to `/category/fashion`, and upper-case slugs redirect to the lowercase URL. A page number past the end goes to the last page. Hidden products and categories return 404.
- **Search:** every word must match the product name, short description or SKU. `%` and `_` are treated as plain characters.
- **Product cards** (`app/views/partials/product-card.php`) show the image, name, price (sale price plus struck-through regular price), discount badge, "New" badge and stock status ("In stock", "Only 3 left", "Out of stock").

### Shopping cart

`/cart` shows each item's image, name, unit price, quantity (with − / + and a number box), line subtotal and a remove button, then the subtotal, delivery and grand total. On phones each item becomes a compact card and the summary moves below the items.

| Action | Endpoint (POST, CSRF-protected) |
|---|---|
| Add | `/cart/add` - `product_id`, `quantity`, optional `return` path |
| Change quantity | `/cart/update` - `product_id` + `quantity` (0 removes), or `step` = -1 / 1 |
| Remove | `/cart/remove` - `product_id` |
| Clear | `/cart/clear` |

**What the session stores and what it doesn't**
- **Stored:** only `line => quantity`. A line is the product id, or `productId:optionId` for a size/colour.
- **Never stored:** prices, names or totals. Each time the cart is built (cart page and header badge), every item is re-checked against the database, so price changes apply immediately. Items that became inactive or sold out are removed, and quantities above current stock are reduced. The shopper sees a notice on the cart page.
- **Browser input:** anything the browser sends (price fields, forged IDs, negative or huge quantities, arrays) is ignored or rejected. Money is calculated in integer cents.

**Rules**
- **Adding:** the product must exist, be active, and be in an active category. Quantity must be a whole number of 1 or more.
- **Limits:** a line can't exceed the stock or 99 per product; if you ask for more, the cart adds what's available and says so. A cart holds up to 50 different products.
- **Updating:** 0 removes the line. The −/+ buttons work from the quantity stored on the server, not from the page, and quantities can never go negative.
- **Return path:** after adding, the shopper goes back to the page they came from. Only same-site paths are accepted, so this can't be used as an open redirect.
- **Delivery:** depends on the customer's district, so the cart shows the zone charges (e.g. Inside Dhaka ৳70 · Outside Dhaka ৳130) and checkout adds the right one. When a free-delivery amount is set in Admin → Delivery, the cart shows how much more is needed.

### Checkout & orders

`/checkout` is guest checkout with name, mobile, district, thana and address, and **Cash on Delivery** as the only payment method. There is no email, account or login.

| Field | Rules |
|---|---|
| Name | Required, 2–100 characters, letters in any language (including Bangla) plus spaces and `. ' -` |
| Mobile | Required, Bangladeshi mobile number. Accepts `01712345678`, `01712-345678`, `+8801712345678`, `8801712345678` and spaced forms; stored as `01712345678` |
| District | Required, one of the active districts (Admin → Delivery). Sets the delivery charge |
| Thana / Area | Required, an active thana of the chosen district (the list only shows that district's thanas) |
| Address | Required, 5–500 characters (house, road, area), several lines allowed. A live line above it shows "Your Address: House#4, Dhanmondi, Dhaka" |

**How an order is placed** (`App\Models\Order::place`), all in one MySQL transaction:
1. **Check the request:** CSRF token, field validation, payment method = `cod`, cart not empty.
2. **Lock the rows:** `SELECT … FOR UPDATE` on the cart's products and size/colour options (in id order), so two shoppers can't buy the same last item.
3. **Re-check each product** from the locked rows: active, category active, the chosen size/colour still offered, enough stock of it. Any problem cancels the order and tells the shopper what changed.
4. **Price from the database:** totals are calculated server-side in integer cents from the current prices, and the delivery charge of the district's zone is added (0 when the free-delivery amount is reached). The items must match what the checkout page showed (the `cart_fingerprint`) and so must the delivery charge (`delivery_shown`); otherwise the shopper is asked to review the new amounts. Prices, totals, names, stock and status posted by the browser are never used.
5. **Number the order:** allocate an order number from `order_sequences`, one atomic counter per day. The result is `AM-YYYYMMDD-NNNNN`, e.g. `AM-20260924-00001`, backed by a UNIQUE key.
6. **Write everything:** insert the order (with the district, thana and zone names copied onto it) and its items (name, size/colour label, SKU and price are copied onto each item), then reduce stock (the option's and the product's) with `stock_quantity - n WHERE stock_quantity >= n`.
7. **Commit,** clear the cart, and show `/order-success`.

If anything fails, the whole transaction is rolled back: no order, no items, no stock change, and the order number isn't used. The shopper's cart is kept and a friendly message is shown, while the real error goes to `storage/logs/`.

**Order confirmation:** `/order-success` shows the order number, name, mobile, address, each product with quantity and price, subtotal, delivery, total, payment method and status (Pending). It only works in the browser session that placed the order, and it's sent with `no-store` and `noindex` because it contains personal details. A second click on "Place order" never creates a duplicate; it just shows the same confirmation.

### SEO

Every page has a `<title>`, a meta description (up to 160 characters), a canonical URL, Open Graph and Twitter tags, and a `robots` tag.
- **Search and filter pages** are `noindex, follow` and point their canonical URL at the clean listing, so filter combinations don't create duplicate pages.
- **Titles and descriptions** are unique per page: the homepage has its own title, descriptions are built from live data (category names, product counts, delivery terms), and page 2+ of a listing says which page it is.
- **Canonical and `og:url`** are always absolute URLs. Clean URLs are enforced with 301 redirects: `/shop/` → `/shop`, `/index.php/shop` → `/shop`, `/product/Name` → `/product/name`, `/shop?category=x` → `/category/x`.
- **Structured data (JSON-LD):**
  - Homepage: `WebSite` (site name) and `OnlineStore` (logo, email, phone).
  - Shop and category pages: `BreadcrumbList` (Home › Shop › Category), matching the visible breadcrumb.
  - Product pages: `Product` with the `Offer` (price and currency from the database, availability, condition), the regular price as a `StrikethroughPrice` when on sale, `shippingDetails` (the cheapest zone charge, or free from the free-delivery amount) and a `MerchantReturnPolicy` (`STORE_RETURN_DAYS`), plus `BreadcrumbList` (Home › Category › Product).
  - Only real uploaded photos are used as product/share images, never the placeholder.
- **sitemap.xml** lists home, shop, active categories and products, each with `<lastmod>`, and product photos via the image-sitemap extension. **robots.txt** blocks admin, cart, checkout, search results and re-sorted listings, and points to the sitemap.
- **Error pages** (404, 405, 500) and noindex pages are marked `noindex`.
- **Default share image:** `public/assets/images/og-default.png` (1200×630, with size and alt tags), used when a page has no photo of its own. It shows the Aalto logo and diamond mark on the brand orange; `logo-512.png` is the square logo for search engines.

Set `APP_URL` correctly in production: canonical URLs, `og:url`, structured data and the sitemap are built from it. Store settings used in structured data: `STORE_COUNTRY` (default `BD`) and `STORE_RETURN_DAYS` (default 7; also used in the returns text on the site).

### Performance

- **Images:** every upload follows the image standard in `app/config/images.php`: product images 800 × 1000 (4:5) plus a 480 × 600 listing copy, category images 320 × 320 (1:1), all WebP within a size target (see *Image upload standard* below). `srcset` declares each file's real pixel width, so browsers pick the smallest copy that is sharp enough. Every image has `width`/`height` (no layout shift) and alt text; below-the-fold images use `loading="lazy"`. The first row of a listing and the main product image load eagerly (the product image with `fetchpriority="high"`). Example: a 1.1 MB 1800×2250 JPEG becomes a 212 KB main image and a 31 KB list image.
- **No third-party requests:** Bootstrap 5.3.3 CSS/JS are served from `public/assets/vendor/`. These are the same files as the CDN build, checked against its SRI hashes. The storefront uses a 14 KB SVG icon sprite (`assets/images/icons.svg`, via the `icon()` helper) instead of the 86 KB icon stylesheet and 130 KB icon font. The admin keeps the self-hosted Bootstrap Icons font.
- **Transfer size (homepage, gzipped):** HTML about 5 KB, and CSS, JS and icons about 68 KB. Before Phase 10 the CSS, JS and icons were about 205 KB, including the 127 KB icon font, all loaded from a third-party CDN.
- **Caching:** CSS, JS and images are linked with `?v=<file time>` and cached for a year (`immutable`), and uploads get new random names when replaced. Catalogue pages are `private, no-cache` with an `ETag`, so a repeat visit gets an empty `304 Not Modified` when nothing changed. Cart, checkout and order confirmation are `no-store`, and so are all admin pages. robots.txt is cached for a day and sitemap.xml for an hour.
- **Compression:** `public/.htaccess` enables Brotli (if available) or gzip for HTML, CSS, JS, SVG, JSON and XML.
- **JavaScript:** about 5 KB, loaded with `defer`, and it makes no API calls. It only adds conveniences: auto-apply for filters and sort, the quantity stepper, gallery thumbnails, clean GET URLs, the sticky header and back-to-top. Without JavaScript every page still works.
- **SQL:** the category list is loaded once per request and shared by the header, footer and page. Listings use one `COUNT` plus one `SELECT` with a `LIMIT`. Product pages use about 4 queries in total. Money is compared in SQL `DECIMAL`.

### Design system

The storefront and admin share one palette. Tokens live at the top of `public/assets/css/app.css` and `public/assets/css/admin.css`:

| Token | Value | Use |
|---|---|---|
| **primary** | **`#fc5d07`** | brand orange: primary buttons (Add to cart, Checkout, Place order), sale/cart badges, progress bar, focus ring, admin active markers. Carries **ink text** (5.9:1): white on it is only 3.1:1 |
| primary hover | `#e04e00` | hover/active state of primary buttons (4.6:1 with ink text) |
| accent (text orange) | `#c04705` | orange **text** on light backgrounds: sale prices, Deals link, hero highlight (5.1:1 on white) |
| accent soft | `#fff1e8` | light orange panels |
| ink | `#16130f` | text, announcement bar, footer, admin sidebar and admin buttons |
| surface | `#faf8f5` | page background |
| sand | `#f2ede6` | image placeholders, soft panels |
| line | `#e7e1d9` | borders and dividers |

- **Logo and favicon** (`public/assets/images/brand/`, `public/favicon.ico`): generated from the owner's artwork (`mainLogo.png`, `fabicon.png`). The white background was made transparent without losing the smooth edges.
  - `logo-wordmark.webp` ("Aaltö" only): header and admin sidebar. The tagline would be unreadable at that size.
  - `logo.webp` (with "Style Made Simple"): footer and admin sign-in.
  - `logo.png` and `logo-512.png` (square, on white): structured data and e-mail.
  - Favicons: `favicon.ico` (16/32/48 px), `favicon-32.png`, `favicon-192.png`, and `apple-touch-icon.png` (180 px on white, because iOS can't show transparency).
  - The generator is a one-off; to change the logo, replace these files at the same sizes.

- **Fonts:** system fonts only, so there are no web-font downloads. Headings use a serif stack (Iowan Old Style, Palatino, Georgia); body text uses the system sans stack.
- **Product images:** a 4:5 portrait frame with `object-fit: cover`, which suits clothing photos. Recommended upload: 800 × 1000 px (other ratios are centre-cropped). Category cards use a 1:1 frame.
- **Grids:** 2 products per row on phones, 3 on tablets and 4 on desktops. Shop listings (which have a filter sidebar) show 3 per row, and 4 from 1400 px.
- **Mobile:** below 992 px the navigation opens as a slide-out panel. Phones get sticky bottom bars: *Add to cart* on product pages and *Total + Checkout* on the cart.
- **Motion:** only short hover and focus transitions; nothing runs automatically. `prefers-reduced-motion` turns these off.
- **Tested widths:** 360, 375, 390, 414, 768, 1024, 1366 and 1920 px, with no horizontal scrolling.

### Security headers and errors

- **Every response** sets `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` and a **Content-Security-Policy**. The policy allows scripts, styles and fonts from this site only, with no inline scripts. On HTTPS it adds `Strict-Transport-Security` and `upgrade-insecure-requests`. `X-Powered-By` is removed. Admin pages also deny all framing and are never cached or indexed.
- **Errors:** uncaught exceptions and fatal errors (out of memory, timeouts) are logged to `storage/logs/` and answered with a clean `500` page, never half-rendered output. If even the error view fails, a static fallback page is shown. Details appear only when `APP_DEBUG=true` and `APP_ENV` is not `production`. Unknown URLs get the `404` page, and wrong HTTP methods get `405` with an `Allow` header.
- `app/`, `database/`, `storage/` and `tools/` each contain a deny-all `.htaccess`, as a backup to the root `.htaccess`.
- **PHP settings before the app starts:** `public/.user.ini` (PHP-FPM/CGI, the usual cPanel setup) and a `<IfModule mod_php.c>` block in `public/.htaccess` (mod_php) turn off `display_errors`, so even a start-up or parse error is never printed. The runtime `APP_DEBUG` switch still works locally.
- **Admin area:** every `/admin/*` URL except the sign-in page requires a signed-in admin. The front controller checks this before routing, and every admin handler checks again.
- **Order confirmation** (which shows name, phone and address) can only be viewed from the same browser session, for 30 minutes after ordering, so the next person on a shared computer can't open it.

### Security audit (Phase 11)

Checked with live attack traffic on PHP's built-in server and Apache 2.4:
- **SQL injection:** all queries use PDO prepared statements with emulation off. Column names, sort orders and LIMITs come from code, never from input.
- **XSS:** reflected and stored XSS are covered. Output is escaped with `e()`, JSON-LD uses `JSON_HEX_*`, the admin JS writes user text with `textContent`, and the CSP blocks inline scripts.
- **CSRF:** all 16 POST routes, including sign-in and sign-out.
- **Sessions:** fixation, theft by another browser and logout replay are all handled.
- **Brute force:** the lockout works.
- **Access control:** customers and guests can't reach admin pages.
- **Tampering:** cart and checkout accept no prices, totals, status or stock from the browser.
- **Uploads:** PHP files, polyglots, SVG, oversized files and path traversal are all rejected. Planted scripts in `uploads/` are not executed.
- **File exposure:** `.env`, `.user.ini`, `.htaccess`, `app/`, `database/`, `storage/`, `tools/` and `*.md` are all refused (403).

Before going live:
- **Database user:** use one with only `SELECT, INSERT, UPDATE, DELETE` on the store database. It doesn't need `DROP`, `ALTER` or `FILE`, and it should never be `root`.
- **HTTPS:** serve the site over HTTPS only. `APP_ENV=production` and an `https://` `APP_URL` enable the redirect and HSTS.
- **Order spam:** orders reserve stock immediately and there is no CAPTCHA or rate limit. Watch for fake orders; cancelling an order returns its stock. A per-IP limit wasn't added because many mobile users in Bangladesh share one IP (carrier NAT).
- **Updates:** keep PHP 8.2+ on security updates, and replace the vendored Bootstrap 5.3.3 if an advisory appears.
## Admin panel

Sign in at **`/admin/login`** (for example http://localhost:8080/admin/login).

| URL | Access |
|---|---|
| `/admin` | Goes to the dashboard if signed in, otherwise to the sign-in page |
| `/admin/login` | Sign-in form (GET) and handler (POST) |
| `/admin/logout` | GET shows a confirmation; signing out itself is a POST with a CSRF token |
| `/admin/dashboard` | Totals for products, categories and orders |
| `/admin/categories` | Category list with search, status filter, sort and pagination |
| `/admin/categories/create` | Add a category |
| `/admin/categories/edit/{id}` | Edit a category (and delete it, when safe) |
| `/admin/products` | Product list with search, category/status/visibility/stock filters, sort and pagination |
| `/admin/products/create` | Add a product |
| `/admin/products/edit/{id}` | Edit a product and its images (and delete it, when safe) |
| `/admin/orders` | Order list: search, status tabs with counts, date range, pagination |
| `/admin/orders/view/{id}` | Order details, status change with note, status history |

If you open a protected page while signed out, you're sent to the sign-in page and then returned to that page after signing in.

**Security measures**
- The password is checked with `password_verify()`, and hashes are upgraded automatically with `password_needs_rehash()`. The hash is never put in the session or on any page.
- Every form has a CSRF token. The token is replaced after sign-in and sign-out.
- The session ID is regenerated on sign-in (to prevent session fixation) and every 15 minutes while signed in.
- Session cookies are `HttpOnly` and `SameSite=Lax`, use strict mode, and are cookie-only. They are marked `Secure` automatically on HTTPS (or always, with `SESSION_SECURE=true`).
- Sessions end after 30 minutes of inactivity (`ADMIN_SESSION_IDLE`) or 8 hours in total (`ADMIN_SESSION_LIFETIME`).
- A session only works in the browser that signed in (it is tied to the User-Agent). A stolen cookie used from another browser doesn't work.
- The account is re-checked on every request, so a deactivated or demoted admin is signed out immediately.
- After 5 failed sign-ins for one email from one IP, or 20 from one IP, sign-in is locked for 15 minutes. Failures are stored in `login_attempts` and logged to `storage/logs/`.
- An unknown email and a wrong password give the same message and take the same time, so attackers can't find out which emails exist.
- Admin pages send `Cache-Control: no-store`, `X-Frame-Options: DENY` and `noindex`, so after sign-out the Back button doesn't show cached admin pages.

To unlock an account early: `DELETE FROM login_attempts WHERE email = 'admin@example.com';`

## Category management

- **Fields:** name, slug, description, image and status (Active/Inactive). ID, created and updated dates are set automatically.
- **Name:** required, up to 120 characters, and unique regardless of case ("Fashion" = "fashion").
- **Slug:** optional. If left blank it's generated from the name ("Café & Co" → `cafe-co`), with `-2`, `-3`… added if taken. A typed slug is cleaned to lowercase letters, numbers and hyphens, and must be unique.
- **Description:** optional, up to 1000 characters.
- **Search** matches name, slug and description. You can also filter by status and sort by name, newest, recently updated or most products.
- **Activate/deactivate** from the list. Inactive categories and their products are hidden from the store.
- **Delete** is only allowed when no products (and no sub-categories) use the category. Otherwise the button is disabled with the reason, and the server refuses too. Deleting also removes the image file.

### Image uploads

- **Accepted:** JPG/JPEG, PNG and WebP, up to 8 MB (`UPLOAD_IMAGE_MAX_KB`).
- **Type checks:** the real file type is read from the content (`finfo` + `getimagesize`) and must match the extension. GIF, SVG, renamed files, fake images and corrupt images are rejected.
- **Re-encoding:** every image is re-encoded with GD and saved as **WebP** (JPG/PNG fallback if the server lacks WebP support). This removes metadata and any hidden code.
- **Size limits:** each image slot has its own standard (next section). Anything over 16 megapixels is rejected to protect server memory; anything below the slot's minimum is rejected because it would look blurred.
- **File names:** random 32-character names; the original name is never used. Files go in `public/uploads/categories/` and `public/uploads/products/`.
- **Thumbnails:** product images also get a 480 × 600 copy (`…-sm.webp`) for cards and listings; deleting or replacing an image removes both files.

### Image upload standard

All sizes live in **one place**, `app/config/images.php`. The upload code, the admin help text, the live preview and the storefront `<img>` sizes all read from it.

| Section | Dimensions | Ratio | Format | Target | Minimum | Upload field |
|---|---:|---:|---|---:|---:|---|
| Category image | 320 × 320 | 1:1 | WebP | ≤ 20 KB | 160 × 160 | Admin → Categories |
| Product main image | 800 × 1000 | 4:5 | WebP | ≤ 100 KB | 400 × 500 | Admin → Products |
| Product gallery | 800 × 1000 | 4:5 | WebP | ≤ 100 KB each | 400 × 500 | Admin → Products |
| Homepage hero / banner | 1920 × 700 | 2.74:1 | WebP | ≤ 150 KB | 1280 × 467 | none yet (reserved) |
| Promotional banner | 1200 × 500 | 12:5 | WebP | ≤ 100 KB | 800 × 333 | none yet (reserved) |
| Logo | 400 × 120 | 10:3 | WebP or PNG | ≤ 50 KB | 200 × 60 | none yet (reserved) |

- **Before choosing a file,** every upload field shows its standard. After choosing, the admin sees an instant preview in the real frame (showing how it will be cropped), with the dimensions, ratio, file size, format and a result: ✓ valid, ⚠ will be centre-cropped, or ✕ refused (wrong type, over 8 MB, over 16 MP, or too small).
- **On the server** every check is repeated. Then the image is:
  1. rotated upright (camera EXIF);
  2. **centre-cropped** to the slot's ratio (never stretched; logos use *contain* instead);
  3. **scaled down** to the standard size (never enlarged);
  4. encoded as **WebP**, lowering quality in small steps until the size target is met, but never below the slot's minimum quality (products 70, categories 62), so fabric detail and colours survive;
  5. saved under a generated name (`product-<slug>-main-3f9a2c1d.webp`, `product-<slug>-01-…`, `category-<slug>-…`).

  The original file and its metadata are never stored.
- **After saving,** the admin sees what happened, e.g. *Product main image 2.9 MB → 27 KB (800 × 1000, centre-cropped to 4:5)*.
- **Existing images** show their dimensions, ratio, size and format, and have **Replace** and **Delete**. A replacement goes through the same processing.
- **Upload folder protection:** `public/uploads/.htaccess` stops scripts from running there and serves only image files.
- **Cleanup:** replacing or removing an image deletes the old file, and rejected uploads leave nothing behind.

Requires the PHP `gd` (with WebP) and `fileinfo` extensions. Both are standard on cPanel.

## Order management

- **List** (`/admin/orders`): shows order number, customer, mobile, total, status and date, newest first, 20 per page.
  - **Search** matches the order number, customer name or mobile. Mobile search accepts any typed form (`01712…`, `+880 1712-…`, or the last few digits).
  - **Filters:** status tabs (with counts) and a From/To date range with "Today / Last 7 days / This month" shortcuts. Invalid dates are ignored with a warning, and a reversed range is swapped.
- **Details** (`/admin/orders/view/{id}`):
  - **Order:** number, date, status, payment method and payment status.
  - **Customer:** name, a tap-to-call mobile link, and the delivery address.
  - **Money:** products with quantity, unit price and line subtotal, then subtotal, delivery and grand total.
  - **History:** every status change with the admin's name and an optional note.

### Statuses

`Pending → Confirmed → Processing → Shipped → Delivered`, plus `Cancelled`.

| From | Can move to |
|---|---|
| Pending | Confirmed, Processing, Shipped, Delivered, Cancelled |
| Confirmed | Processing, Shipped, Delivered, Cancelled |
| Processing | Shipped, Delivered, Cancelled |
| Shipped | Delivered, Cancelled (e.g. customer refused the delivery) |
| Delivered / Cancelled | nothing (final) |

- **Only allowed moves:** the form lists only the statuses allowed from the current one, and the server enforces the same rules for any request.
- **Safe changes:** each change runs in a transaction with the order row locked. The form sends the status the admin was looking at; if another admin changed the order meanwhile, the change is refused.
- **Cancelling** returns the order's items to stock. It asks for confirmation first and can't be undone.
- **Delivering** a Cash-on-Delivery order sets its payment status to **paid**.

### Dashboard

Shows:
- **Total sales:** the sum of **delivered** orders, i.e. cash collected.
- **Total orders.**
- **Open order value:** orders not yet delivered or cancelled.
- **Status cards:** Pending, Confirmed, Processing, Shipped, Delivered and Cancelled, each linking to the filtered list.
- **Also:** recent orders and the catalogue totals.

## Product management

- **Fields:** category, name, slug, SKU, short description (500 characters), full description (10,000 characters), regular price, sale price, stock, main image, up to 8 additional images, and Active / Featured / New switches. ID, created and updated dates are set automatically.
- **SKU:** optional. If left blank a unique one like `AM-7F3K9Q2D` is generated. On edit, a blank SKU keeps the current one.
- **Slug:** same rules as categories (generated from the name, made unique automatically).
- **List:** built for desktop. You can search by name, SKU or slug; filter by category, status, featured/new/on sale and stock (in / low 1–5 / out); and sort by newest, updated, name, price or stock. The **Active**, **Featured** and **New** switches update in place without reloading the page.
- **Delete** is only allowed when the product is not in any order. Otherwise deactivate it; past orders keep their own copy of the name, SKU and price. Deleting removes all its image files.

### Prices

- **Two fields:** a **regular price** (required, more than 0) and an optional **sale price**.
- **Which price applies:** if the sale price is empty or 0 the regular price is used, and a sale price must be lower than the regular price.
- **Selling price:** the price customers pay is stored in `products.price`, a column MySQL calculates itself: the sale price when it is set, above 0 and below regular, otherwise the regular price. It is never written directly, so it can't get out of sync.
- **No floating point:** amounts are `DECIMAL(12,2)` in the database. In PHP they are handled as exact decimal strings and integer cents (`App\Core\Money`), never floats. Input like `1,299.50` is accepted; negatives, exponents and more than 2 decimals are rejected. The maximum is 9,999,999.99.

### Stock

- Stock must be a whole number from 0 to 1,000,000. Negative numbers, decimals and text are rejected with a clear message.
- The database also has a `CHECK (stock_quantity >= 0)` constraint, so stock can't go negative even outside the admin, for example in future checkout code.

### Size & colour options

Each product can be sold in sizes, colours, or both. Switch them on in the product form under **Size & colour options**.
- **Sizes only / colours only / both:** each option has its own on/off switch. With both on, stock is kept for every size + colour pair (e.g. M / Red).
- **Sizes:** typed in display order, separated by commas (e.g. `S, M, L, XL`). The "Quick fill" buttons add common sets (clothing, panjabi, kids, shoes). Up to 20, each up to 40 characters.
- **Colours:** a name and a swatch colour each; "Add colour" adds a row. Up to 20.
- **Stock per option:** the table under the options is rebuilt as you type, and what you already entered stays with its size/colour. 0 = sold out. The product's own stock becomes the total of its options and the single stock field is switched off.
- **On the product page** customers must pick the options (the browser and the server both check). A size that is sold out in every colour is crossed out and can't be chosen. After a size is picked, colours sold out in that size are crossed out. The quantity is limited to that option's stock.
- **Cart and orders:** each size/colour is its own cart line. Orders store the option and a label such as `Size: M · Colour: Red`, and only that option's stock is reduced. Cancelling returns it.
- **Product cards** for products with options link to the product page ("Choose options") instead of adding straight to the cart.
- Switching both options off removes the option rows and uses the single stock field again.

## Delivery (districts, thanas and charges)

**Admin → Delivery** controls what checkout charges. Every district belongs to a **delivery zone**, and each zone has one charge.
- **Zones:** seeded as *Inside Dhaka* ৳70 (Dhaka district) and *Outside Dhaka* ৳130 (the other 63 districts). Change a charge and save; new orders use it straight away. You can add zones (e.g. "Dhaka suburbs" ৳100) and move districts into them. A zone can only be deleted when no district uses it.
- **Free delivery:** orders of this amount or more pay no delivery charge. 0 = off (the default). The announcement bar, footer, home page and product page show it when it's on, and the zone charges when it's off.
- **Districts:** all 64 districts of Bangladesh are seeded (Dhaka first, then A–Z, with Bengali names). Add a district, rename it, change its zone, or hide it from checkout. A district can only be deleted when no order uses it (its thanas go with it).
- **Thanas:** 559 thanas/upazilas are seeded, including the 50 Dhaka Metropolitan and 15 Chattogram Metropolitan police thanas. On a district's page you can add several at once (one per line or comma separated), rename, hide or delete them. Deleting a thana doesn't change past orders: they keep the thana and district names.
- **At checkout** the customer picks a district, then a thana from that district only. The delivery charge and total update straight away, and a line above the address shows the full address as it will be used, e.g. "Your Address: House#4, Dhanmondi, Dhaka". Without JavaScript the thana list is grouped by district.
- **Server checks:** the thana must be active and belong to the chosen (active) district. The charge is taken from the database inside the order transaction. If it differs from the charge the page showed, the order is not placed and the shopper sees the real charge.
- Location data: `database/data/bd_locations.sql` (from the open-source `nuhil/bangladesh-geocode` data plus the metropolitan thanas). It is included in `schema.sql` and migration 007.

## Home slider, Contact us, chat button and wishlist

- **Home slider** (Admin → Home slider): images at the top of the homepage that rotate every 5 seconds, with dots and arrows. They swipe on phones and pause on hover or when the visitor prefers reduced motion.
  - Slider images are 1500 × 600 (5:2).
  - An optional **side banner** (750 × 600) shows beside the slider on large screens, like Ghorer Bazar.
  - Each image can link to a page on this site (`/shop?sale=1`) or an https:// address (opens in a new tab).
  - Reorder with the arrows, hide or delete. With no active slides the homepage shows its standard welcome section.
  - Table: `home_slides` (migration 008).
- **Contact us** (`/contact`, in the top menu) shows only the filled-in details:
  - WhatsApp (wa.me link), Messenger (m.me), and imo (number with a copy button);
  - phone and email;
  - Facebook, Instagram, YouTube and TikTok;
  - address, opening hours and a Google Maps link.

  They are set in **Admin → Contact & social** and stored in `settings`. Social links must point to their own site (facebook.com, instagram.com and so on). The footer shows the social icons too.
- **Chat button:** a round button in the bottom-right corner of every store page. It opens WhatsApp (with an optional first message) or Messenger.
  - Switch the button, and each app, on or off in Admin → Contact & social.
  - It works without JavaScript (`<details>`).
- **Wishlist:** the heart on each product card and on the product page saves the product to a guest wishlist.
  - The wishlist is kept in the visitor's session, so there are no customer accounts.
  - The header shows the heart with a count next to the cart.
  - `/wishlist` lists the saved products (noindex).
- **Add To Cart:** product cards have a full-width outlined button:
  - "Add To Cart" normally;
  - "Select Options" for products with sizes or colours;
  - "Out of stock" when sold out.

## Facebook Pixel and Google Tag Manager

**Admin → Marketing & tracking** takes the **Pixel ID** and the **GTM container ID** (`GTM-XXXXXXX`).
- Only IDs are accepted. If you paste the whole Meta snippet, the ID is picked out of it, so no one can inject scripts through this page.
- The store prints the IDs and the page's events as a JSON block. `public/assets/js/tracking.js` loads the Pixel and GTM, so pages still have no inline JavaScript.
- **Content-Security-Policy:** while an ID is set, the storefront lets analytics connect, send images and open frames to any https host. This was measured in a real browser: the Meta Pixel posts to rotating cloud hosts and through a hidden form to facebook.com, and GTM tags come from many vendors.
  - With GTM on, scripts may also load from any https host. With the Pixel only, scripts load only from connect.facebook.net.
  - Inline scripts stay blocked, and admin pages never get these sources.
- **Events:**

  | When | Pixel | GA4 dataLayer |
  |---|---|---|
  | Every page | PageView | gtm.js |
  | Product page | ViewContent | view_item |
  | After Add to cart | AddToCart | add_to_cart |
  | Checkout page | InitiateCheckout | begin_checkout |
  | Order placed | Purchase | purchase |

  - Values are in BDT and use the product SKU as the content/item ID. Every Pixel event carries `content_ids`, `content_name`, `content_category`, `content_type` (product), `contents` [{id, quantity, item_price}], `value`, `currency` and `num_items`.
  - **Advanced Matching (Purchase):** the customer details are normalised as Meta requires and hashed with SHA-256 on the server, so readable details never appear in the page:
    - phone `8801…`, first and last name, city = district, country `bd`;
    - `external_id` = the hashed phone.

    Email, gender, birth date and postcode are not collected at checkout, so they are not sent.
  - Purchase is reported once per order (not again when the confirmation page is refreshed). The order number is its event ID / `transaction_id`.
- **GTM limitation:** "Custom HTML" tags that contain inline scripts are blocked by the security policy. Google's built-in tags (GA4, Google Ads) and community templates work.

## Courier: Steadfast

**Admin → Courier** holds the Steadfast **API key** and **secret key**.
- Get them from the Steadfast merchant panel → API. They are stored in `settings` and never shown again, only masked.
- **Test connection** reads the balance to confirm the keys work.
- The API address defaults to `https://portal.packzy.com/api/v1`.

**Sending orders:**
- **One order:** the order page has a Steadfast card. Send it with the COD amount (defaults to the order total, 0 if paid), an optional rider note and "also mark as Shipped".
- **Several orders:** tick them in the order list and click **Send to Steadfast** (up to 20 at a time; the full total is collected).
- The invoice is the order number, so Steadfast refuses duplicates. Each order is also claimed before the request, so two clicks can't create two parcels.
- The consignment ID, tracking code (with a link to steadfast.com.bd tracking), status and COD are saved on the order.
- Every send, status check and webhook goes into `courier_events`, without the API keys.

**Status updates:**
- **Check status now** on the order page asks Steadfast for the current status.
- Better, set up the **webhook**. In the Steadfast panel, enter the Callback URL `https://your-domain/webhooks/steadfast` and the Auth token (Bearer) shown in Admin → Courier.
- When Steadfast reports **delivered**, the order can become Delivered + Paid automatically (option).
- A courier **cancel/return** is shown as a warning only. Cancelling the order, which returns stock, stays your decision.

**Requirements:** the PHP `curl` extension. Also, the web server must pass the `Authorization` header to PHP; the `.htaccess` files include a rule for this. If the webhook answers 401 on the live site, see DEPLOYMENT.md.

Tables: `orders.courier*` columns and `courier_events` (migration 009).

### Upgrading an older database

Run whichever of these your database doesn't have yet, in order (or import them in phpMyAdmin). New installs get everything from `schema.sql`.
```bash
mysql -u root -p aalto_mart < database/migrations/002_add_login_attempts.sql       # Phase 2
mysql -u root -p aalto_mart < database/migrations/003_categories_unique_name.sql   # Phase 3
mysql -u root -p aalto_mart < database/migrations/004_products_pricing_and_flags.sql  # Phase 4
mysql -u root -p aalto_mart < database/migrations/005_checkout_orders.sql          # Phase 7
mysql -u root -p aalto_mart < database/migrations/006_order_management.sql         # Phase 8
mysql -u root -p aalto_mart < database/migrations/007_options_delivery_locations.sql  # sizes/colours, delivery zones, districts, thanas
mysql -u root -p aalto_mart < database/migrations/008_home_slider.sql              # homepage slider + side banner
mysql -u root -p aalto_mart < database/migrations/009_courier_steadfast.sql         # Steadfast courier on orders + courier log
```
Migration 004 converts existing prices: the old `compare_at_price` becomes the regular price and the old `price` becomes the sale price.

## Database

| Table | Purpose |
|---|---|
| `users` | admins and customers; unique email, hashed password, role |
| `categories` | store categories; unique slug, optional `parent_id`, icon, sort order |
| `products` | SKU/slug unique; `DECIMAL(12,2)` regular/sale price plus generated selling `price`; stock (never negative; with options = their total); active/featured/new flags; size/colour switches; full-text index |
| `product_variants` | one row per size / colour / size+colour of a product, with its own stock (never negative); cascade-deleted with the product |
| `product_images` | multiple images per product, one primary; cascade-deleted with the product |
| `orders` | order number, status, payment status/method (COD), customer name + mobile + address, district/thana (ids + names) and delivery zone, `DECIMAL` subtotal/delivery/total |
| `order_sequences` | one counter per day for `AM-YYYYMMDD-NNNNN` order numbers |
| `order_status_history` | every status change: from, to, note, admin, time |
| `order_items` | product name/SKU/price and size/colour label snapshot per line; cascade-deleted with the order |
| `delivery_zones` | delivery charge per zone (`DECIMAL`, never negative), e.g. Inside Dhaka 70, Outside Dhaka 130 |
| `districts` | 64 districts, each in one zone; can be hidden from checkout |
| `thanas` | thanas/upazilas per district (unique per district); can be hidden |
| `settings` | admin settings: `free_delivery_from`, contact details (`contact_*`), chat button (`chat_*`) |
| `home_slides` | homepage slider images and the side banner: image, title, link, order, shown/hidden |
| `courier_events` | every Steadfast send, status check and webhook per order (status, message, response) |
| `login_attempts` | failed admin sign-ins (email, IP, time) for lockout; purged after 24 h |

All tables use InnoDB, `utf8mb4`, and have `created_at` / `updated_at`.

Sample rows are easy to spot: product SKUs start with `SMP-`, names start with "Sample" and emails use `example.com`. Remove sample products with:
```sql
DELETE FROM products WHERE sku LIKE 'SMP-%';
```

## Tests

HTTP test suites live in `tests/` (not included in the release package). With the dev server on `localhost:8080` and the MySQL dev database `aalto_mart_dev`, run `bash tests/run_all.sh` (all suites) or `bash tests/run_all.sh cart_test` (one). `tests/reseed.php` reloads `schema.sql` + `seed.sql`; `tests/fake_steadfast.php` stands in for the Steadfast API.

## Development notes

- **Routes** live in `app/routes.php`: `$router->get('/path/{slug}', [Controller::class, 'method']);`
- **Views**: `view('pages/home', $data, 'main')` renders a page inside `layouts/main.php`. Always print variables with `e()`.
- **Database**: use `db_all()`, `db_one()`, `db_value()`, `db_insert()`, `db_update()`, `db_transaction()` from `app/helpers/database.php`. They all use prepared statements, so pass user input as parameters.
- **JS**: add features as modules in `public/assets/js/app.js` (`AaltoMart.modules.name = { init() {...} }`), hooked to `data-*` attributes.
- **CSS**: `public/assets/css/app.css`; brand colours are CSS variables at the top.
- Asset URLs include a file-modified-time version, so browsers pick up CSS/JS changes automatically.
- With `APP_DEBUG=true` errors show full details; with `false` users get a generic page and details go to `storage/logs/`.
