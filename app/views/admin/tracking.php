<?php
/**
 * Admin -> Marketing & tracking.
 *
 * @var array $values fb_pixel_id, gtm_id
 * @var array $errors
 */
$hasOld = old('_submitted') === '1';
$v = static fn (string $k): string => $hasOld ? old($k) : (string) $values[$k];
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
?>
<p class="text-muted mb-3">Add your Facebook (Meta) Pixel and Google Tag Manager to the store. Paste only the ID - the store adds the code itself. Leave a field empty to switch it off.</p>

<form method="post" action="<?= e(url('admin/tracking')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3 g-lg-4">
        <div class="col-12 col-xl-7">
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3"><i class="bi bi-facebook me-1 text-primary" aria-hidden="true"></i>Facebook (Meta) Pixel</h2>
                    <label for="fb_pixel_id" class="form-label">Pixel ID</label>
                    <input type="text" id="fb_pixel_id" name="fb_pixel_id" value="<?= e($v('fb_pixel_id')) ?>" maxlength="100" inputmode="numeric"
                           class="form-control font-monospace<?= $invalid('fb_pixel_id') ?>" placeholder="e.g. 1234567890123456" autocomplete="off">
                    <?php if (isset($errors['fb_pixel_id'])): ?><div class="invalid-feedback"><?= e($errors['fb_pixel_id']) ?></div><?php endif; ?>
                    <div class="form-text">Meta Events Manager → Data sources → your Pixel → the number under its name.</div>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3"><i class="bi bi-google me-1" aria-hidden="true"></i>Google Tag Manager</h2>
                    <label for="gtm_id" class="form-label">Container ID</label>
                    <input type="text" id="gtm_id" name="gtm_id" value="<?= e($v('gtm_id')) ?>" maxlength="100"
                           class="form-control font-monospace<?= $invalid('gtm_id') ?>" placeholder="GTM-XXXXXXX" autocomplete="off" autocapitalize="characters">
                    <?php if (isset($errors['gtm_id'])): ?><div class="invalid-feedback"><?= e($errors['gtm_id']) ?></div><?php endif; ?>
                    <div class="form-text">Google Tag Manager → your container → the GTM-... code at the top. Use it for Google Analytics 4, Google Ads and more.</div>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save tracking</button>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4 small">
                    <h2 class="h6 fw-semibold mb-2">Events sent automatically</h2>
                    <table class="table table-sm mb-2">
                        <thead><tr class="text-muted"><th scope="col">When</th><th scope="col">Pixel</th><th scope="col">GTM / GA4</th></tr></thead>
                        <tbody>
                            <tr><td>Every page</td><td>PageView</td><td>gtm.js</td></tr>
                            <tr><td>Product page</td><td>ViewContent</td><td>view_item</td></tr>
                            <tr><td>Add to cart</td><td>AddToCart</td><td>add_to_cart</td></tr>
                            <tr><td>Checkout page</td><td>InitiateCheckout</td><td>begin_checkout</td></tr>
                            <tr><td>Order placed</td><td>Purchase</td><td>purchase</td></tr>
                        </tbody>
                    </table>
                    <p class="mb-2">Each Pixel event includes product SKU (content_ids), name, category, quantity and price, value and currency in BDT. On Purchase the customer's phone, name, district and country are added for Advanced Matching, hashed (SHA-256) before they leave the server.</p>
                    <p class="mb-2"> Purchase is sent once per order (refreshing the confirmation page doesn't count it again) and uses the order number as the event / transaction ID.</p>
                    <p class="mb-0 text-muted">In GTM, read the events with a Custom Event trigger and the <code>ecommerce</code> data layer variables. GTM "Custom HTML" tags that contain inline JavaScript are blocked by the store's security policy - use Google's built-in tags (GA4, Google Ads) or community templates instead.</p>
                </div>
            </div>
        </div>
    </div>
</form>
