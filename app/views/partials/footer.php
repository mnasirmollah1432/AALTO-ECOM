<?php
$appName = (string) config('app.name', 'Aalto Mart');
$navCategories = nav_categories();
$social = array_values(array_filter(App\Models\Contact::channels(), static fn ($c) => in_array($c['key'], ['whatsapp', 'messenger', 'facebook', 'instagram', 'youtube', 'tiktok'], true)));
?>
<section class="value-strip" aria-label="Why shop with us">
    <div class="container">
        <ul class="value-strip__list list-unstyled">
            <?php if ($freeFrom = free_delivery_from()): ?>
                <li><?= icon('truck') ?><span><strong>Free delivery</strong> over <?= e(format_price($freeFrom)) ?></span></li>
            <?php else: ?>
                <li><?= icon('truck') ?><span><strong>Home delivery</strong> all over Bangladesh</span></li>
            <?php endif; ?>
            <li><?= icon('cash-coin') ?><span><strong>Cash on delivery</strong> pay at your door</span></li>
            <li><?= icon('arrow-repeat') ?><span><strong>Easy returns</strong> within <?= (int) config('app.store.return_days', 7) ?> days</span></li>
            <li><?= icon('shield-check') ?><span><strong>Secure</strong> checkout</span></li>
        </ul>
    </div>
</section>

<footer class="site-footer">
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <a class="brand brand--light mb-3 d-inline-flex" href="<?= e(url('/')) ?>" aria-label="<?= e($appName) ?> home">
                    <img src="<?= e(asset('images/brand/logo.webp')) ?>" alt="<?= e($appName) ?> - Style Made Simple" width="424" height="240" class="brand__logo brand__logo--full" loading="lazy">
                </a>
                <p class="mb-0 footer-muted">Considered everyday pieces and essentials at honest prices, delivered to your door.</p>
                <?php if ($social): ?>
                    <ul class="footer-social" aria-label="Follow and message us">
                        <?php foreach ($social as $c): ?>
                            <li><a href="<?= e($c['href']) ?>" data-contact="<?= e($c['key']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e($c['label']) ?>" title="<?= e($c['label']) ?>"><?= icon($c['icon']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <h2 class="footer-heading">Shop</h2>
                <ul class="list-unstyled footer-links">
                    <li><a href="<?= e(url('shop')) ?>">All products</a></li>
                    <li><a href="<?= e(url_with_query('shop', ['new' => '1'])) ?>">New arrivals</a></li>
                    <li><a href="<?= e(url_with_query('shop', ['sale' => '1'])) ?>">Deals</a></li>
                </ul>
            </div>

            <div class="col-6 col-md-4 col-lg-3">
                <h2 class="footer-heading">Categories</h2>
                <ul class="list-unstyled footer-links">
                    <?php foreach (array_slice($navCategories, 0, 6) as $cat): ?>
                        <li><a href="<?= e(url('category/' . $cat['slug'])) ?>"><?= e($cat['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="col-12 col-md-4 col-lg-3">
                <h2 class="footer-heading">Help</h2>
                <ul class="list-unstyled footer-links">
                    <li><a href="<?= e(url('contact')) ?>">Contact us</a></li>
                    <li><a href="<?= e(url('wishlist')) ?>">Wishlist</a></li>
                    <li><a href="mailto:<?= e(config('app.store.email')) ?>"><?= e(config('app.store.email')) ?></a></li>
                    <?php if (config('app.store.phone')): ?>
                        <li><?= e(config('app.store.phone')) ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="footer-bottom py-3">
        <div class="container d-flex flex-column flex-md-row justify-content-between gap-2 small">
            <span>&copy; <?= date('Y') ?> <?= e($appName) ?>. All rights reserved.</span>
            <?php if (config('app.env') !== 'production'): ?>
                <span class="footer-muted">Sample store data for development only.</span>
            <?php endif; ?>
        </div>
    </div>
</footer>

<a href="#main" class="back-to-top" data-back-to-top aria-label="Back to top">
    <?= icon('arrow-up') ?>
</a>
