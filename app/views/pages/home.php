<?php
/**
 * @var array $categories
 * @var array $featured
 * @var array $newArrivals
 * @var int   $maxDiscount biggest current discount in %
 * @var array $banners     slider slides + side banner (Admin -> Home slider)
 */
?>
<?php if ($banners['slides']): ?>
    <?php partial('home-slider', ['banners' => $banners]); ?>
<?php else: ?>
<section class="hero">
    <div class="container">
        <div class="hero__grid">
            <div class="hero__copy">
                <p class="eyebrow">The new season edit</p>
                <h1 class="hero__title">Everyday style, <em>thoughtfully</em> priced.</h1>
                <p class="hero__lead">
                    Considered pieces and essentials for every day &mdash; delivered to your door,
                    paid for when they arrive.
                </p>
                <div class="hero__actions">
                    <a href="<?= e(url_with_query('shop', ['new' => '1'])) ?>" class="btn btn-primary btn-lg">Shop new in</a>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-outline-dark btn-lg">Browse all</a>
                </div>
            </div>

            <div class="hero__edits">
                <a href="<?= e(url_with_query('shop', ['new' => '1'])) ?>" class="edit-tile edit-tile--new">
                    <span class="edit-tile__kicker">Just landed</span>
                    <span class="edit-tile__title">New in</span>
                    <span class="edit-tile__cta">Shop now <?= icon('arrow-right') ?></span>
                </a>
                <a href="<?= e(url_with_query('shop', ['sale' => '1'])) ?>" class="edit-tile edit-tile--sale">
                    <span class="edit-tile__kicker"><?= $maxDiscount > 0 ? 'Up to ' . $maxDiscount . '% off' : 'Limited time' ?></span>
                    <span class="edit-tile__title">The sale</span>
                    <span class="edit-tile__cta">Shop deals <?= icon('arrow-right') ?></span>
                </a>
                <a href="<?= e(url('shop')) ?>" class="edit-tile edit-tile--all">
                    <span class="edit-tile__kicker">Everything</span>
                    <span class="edit-tile__title">Shop all</span>
                    <span class="edit-tile__cta">Explore <?= icon('arrow-right') ?></span>
                </a>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($categories): ?>
<section id="categories" class="section" aria-labelledby="categories-title">
    <div class="container">
        <div class="section-head">
            <h2 class="section-title" id="categories-title">Shop by category</h2>
            <a href="<?= e(url('shop')) ?>" class="section-link">View all</a>
        </div>
        <?php $catSpec = App\Core\ImageUpload::spec('category'); ?>
        <div class="category-rail">
            <?php foreach ($categories as $i => $cat): ?>
                <a class="category-card category-card--tone-<?= $i % 5 ?>" href="<?= e(url('category/' . $cat['slug'])) ?>">
                    <span class="category-card__visual">
                        <?php if (!empty($cat['image_path'])): ?>
                            <?= responsive_img($cat['image_path'], '', '(min-width: 992px) 220px, 40vw', ['class' => 'category-card__image', 'width' => $catSpec['width'], 'height' => $catSpec['height']]) ?>
                        <?php else: ?>
                            <span class="category-card__icon"><?= icon((string) ($cat['icon'] ?: 'tag')) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="category-card__name"><?= e($cat['name']) ?></span>
                    <span class="category-card__count"><?= (int) $cat['product_count'] ?> product<?= (int) $cat['product_count'] === 1 ? '' : 's' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($featured): ?>
<section class="section section--tinted" aria-labelledby="featured-title">
    <div class="container">
        <div class="section-head">
            <h2 class="section-title" id="featured-title">Featured products</h2>
            <a href="<?= e(url('shop')) ?>" class="section-link">Shop all</a>
        </div>
        <div class="product-grid">
            <?php foreach ($featured as $product): ?>
                <div><?php partial('product-card', ['product' => $product, 'view_button' => true]); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
// Promo boxes: texts and show/hide from Admin -> Appearance ('' = automatic text)
$look = App\Models\Appearance::get();
$promoHref = static function (string $link, string $fallback): string {
    if ($link === '') {
        return $fallback;
    }
    return App\Models\Slide::isExternal($link) ? $link : url(ltrim($link, '/'));
};
$promoAttrs = static fn (string $link): string => $link !== '' && App\Models\Slide::isExternal($link) ? ' target="_blank" rel="noopener noreferrer"' : '';
$showSale = $look['promo_sale_enabled'] === '1';
$showDelivery = $look['promo_delivery_enabled'] === '1';
?>
<?php if ($showSale || $showDelivery): ?>
<section class="section pb-0" aria-labelledby="promo-title">
    <div class="container">
        <h2 class="visually-hidden" id="promo-title">Offers</h2>
        <div class="promo-grid<?= $showSale && $showDelivery ? '' : ' promo-grid--single' ?>">
            <?php if ($showSale): ?>
                <div class="promo promo--sale">
                    <p class="promo__eyebrow"><?= e($look['promo_sale_eyebrow'] ?: 'Limited-time deals') ?></p>
                    <p class="promo__title">
                        <?php if ($look['promo_sale_title'] !== ''): ?>
                            <?= e($look['promo_sale_title']) ?>
                        <?php elseif ($maxDiscount > 0): ?>
                            Save up to <?= $maxDiscount ?>% on selected items
                        <?php else: ?>
                            Fresh deals added every week
                        <?php endif; ?>
                    </p>
                    <p class="promo__text"><?= e($look['promo_sale_text'] ?: 'Great prices on everyday favourites, while stocks last.') ?></p>
                    <a href="<?= e($promoHref($look['promo_sale_link'], url_with_query('shop', ['sale' => '1']))) ?>" class="btn btn-light"<?= $promoAttrs($look['promo_sale_link']) ?>><?= e($look['promo_sale_button'] ?: 'Shop the sale') ?></a>
                </div>
            <?php endif; ?>
            <?php if ($showDelivery): ?>
                <div class="promo promo--delivery">
                    <?php if ($look['promo_delivery_eyebrow'] !== '' || $look['promo_delivery_title'] !== ''): ?>
                        <p class="promo__eyebrow"><?= e($look['promo_delivery_eyebrow'] ?: 'Home delivery') ?></p>
                        <p class="promo__title"><?= e($look['promo_delivery_title'] ?: 'All over Bangladesh') ?></p>
                    <?php elseif ($freeFrom = free_delivery_from()): ?>
                        <p class="promo__eyebrow">Free delivery</p>
                        <p class="promo__title">On orders over <?= e(format_price($freeFrom)) ?></p>
                    <?php else: ?>
                        <p class="promo__eyebrow">Home delivery</p>
                        <p class="promo__title">All over Bangladesh</p>
                    <?php endif; ?>
                    <?php if ($look['promo_delivery_text'] !== ''): ?>
                        <p class="promo__text"><?= e($look['promo_delivery_text']) ?></p>
                    <?php else: ?>
                        <?php if (!free_delivery_from() && ($zoneHint = delivery_zone_hint()) !== ''): ?><p class="promo__text mb-1"><?= e($zoneHint) ?></p><?php endif; ?>
                        <p class="promo__text">Pay in cash when it arrives. Easy <?= (int) config('app.store.return_days', 7) ?>-day returns.</p>
                    <?php endif; ?>
                    <a href="<?= e($promoHref($look['promo_delivery_link'], url('shop'))) ?>" class="btn btn-dark"<?= $promoAttrs($look['promo_delivery_link']) ?>><?= e($look['promo_delivery_button'] ?: 'Start shopping') ?></a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($newArrivals): ?>
<section class="section" aria-labelledby="new-title">
    <div class="container">
        <div class="section-head">
            <h2 class="section-title" id="new-title">New products</h2>
            <a href="<?= e(url_with_query('shop', ['new' => '1'])) ?>" class="section-link">See all new</a>
        </div>
        <div class="product-grid">
            <?php foreach ($newArrivals as $product): ?>
                <div><?php partial('product-card', ['product' => $product, 'view_button' => true]); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
