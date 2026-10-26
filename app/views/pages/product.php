<?php
/**
 * @var array    $product
 * @var array    $images    main image first
 * @var array    $related
 * @var bool     $onSale
 * @var int|null $discount
 * @var array|null $options  sizes/colours with stock per combination (Product::options()), null when not used
 */
use App\Core\Money;
use App\Models\Cart;
use App\Models\Product;

$stock = (int) $product['stock_quantity'];
[$stockText, $stockTone] = stock_status($stock);
$priceCents = Money::toCents($product['price']);
$inCart = Cart::quantityOf((int) $product['id']);

// Sizes/colours: stock per combination and what is already in the cart
$variantData = [];
$sizeHasStock = $colourHasStock = static fn (string $v): bool => true;
$autoSize = $autoColour = null;
if ($options) {
    $inCart = 0;
    foreach (Product::variants((int) $product['id']) as $v) {
        $qty = Cart::quantityOf(Cart::key((int) $product['id'], (int) $v['id']));
        $inCart += $qty;
        $variantData[] = ['size' => $v['size'], 'colour' => $v['colour'], 'stock' => (int) $v['stock_quantity'], 'in_cart' => $qty,
            'line' => (string) Cart::key((int) $product['id'], (int) $v['id']), 'max' => min((int) $v['stock_quantity'], Product::MAX_CART_QTY)];
    }
    $sizeHasStock = static fn (string $size): bool => (bool) array_filter($variantData, static fn ($v) => $v['size'] === $size && $v['stock'] > 0);
    $colourHasStock = static fn (string $colour): bool => (bool) array_filter($variantData, static fn ($v) => $v['colour'] === $colour && $v['stock'] > 0);
    // Only one choice available: pick it
    $availSizes = array_values(array_filter($options['sizes'], $sizeHasStock));
    $availColours = array_values(array_filter(array_column($options['colours'], 'name'), $colourHasStock));
    $autoSize = count($options['sizes']) === 1 && $availSizes ? $availSizes[0] : null;
    $autoColour = count($options['colours']) === 1 && $availColours ? $availColours[0] : null;
    // Just added or changed (e.g. from the side cart): re-select that size/colour
    $last = $_SESSION['_last_added'] ?? null;
    if (is_array($last) && (int) ($last['product_id'] ?? 0) === (int) $product['id']) {
        unset($_SESSION['_last_added']);
        if (in_array($last['size'] ?? '', $availSizes, true)) {
            $autoSize = $last['size'];
        }
        if (in_array($last['colour'] ?? '', $availColours, true)) {
            $autoColour = $last['colour'];
        }
    }
}
$main = $images[0] ?? null;
$gallerySizes = '(min-width: 992px) 540px, 100vw';
$productSpec = App\Core\ImageUpload::spec('product'); // 800 x 1000, 4:5
?>
<div class="container pt-3 pt-md-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('category/' . $product['category_slug'])) ?>"><?= e($product['category_name']) ?></a></li>
            <li class="breadcrumb-item active text-truncate" aria-current="page"><?= e($product['name']) ?></li>
        </ol>
    </nav>

    <div class="row g-4 g-lg-5">
        <!-- Gallery -->
        <div class="col-12 col-md-6">
            <div class="product-gallery<?= count($images) > 1 ? ' product-gallery--with-thumbs' : '' ?>" data-gallery
                 data-images="<?= e(json_encode(array_map(static fn ($img) => image_url($img['image_path']), $images ?: [['image_path' => null]]))) ?>">
                <div class="product-gallery__main" data-zoom>
                    <?= responsive_img($main['image_path'] ?? null, $product['name'], $gallerySizes, [
                        'width' => $productSpec['width'], 'height' => $productSpec['height'], 'loading' => 'eager', 'fetchpriority' => 'high', 'data-gallery-main' => '1',
                    ], true) ?>
                    <?php if ($discount): ?>
                        <span class="badge product-gallery__badge">-<?= $discount ?>%</span>
                    <?php endif; ?>
                    <button type="button" class="product-gallery__zoom js-only" data-lightbox-open aria-label="View larger image"><?= icon('zoom-in') ?></button>
                </div>
                <?php if (count($images) > 1): ?>
                    <ul class="product-gallery__thumbs list-unstyled" aria-label="Product images">
                        <?php foreach ($images as $i => $img):
                            $full = image_url($img['image_path']);
                            $thumbPath = App\Core\ImageUpload::thumbPath($img['image_path']);
                            $srcset = $thumbPath !== $img['image_path'] ? image_url($thumbPath) . ' ' . image_width($thumbPath, 480) . 'w, ' . $full . ' ' . image_width($img['image_path'], 800) . 'w' : '';
                        ?>
                            <li>
                                <a href="<?= e($full) ?>" class="product-gallery__thumb<?= $i === 0 ? ' is-active' : '' ?>"
                                   data-gallery-thumb data-full="<?= e($full) ?>" data-srcset="<?= e($srcset) ?>"
                                   aria-label="Show image <?= $i + 1 ?> of <?= count($images) ?>" <?= $i === 0 ? 'aria-current="true"' : '' ?>>
                                    <img src="<?= e(image_url($thumbPath)) ?>" alt="" width="80" height="100" loading="lazy" decoding="async">
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Details -->
        <div class="col-12 col-md-6">
            <div class="product-info">
                <a href="<?= e(url('category/' . $product['category_slug'])) ?>" class="product-info__category"><?= e($product['category_name']) ?></a>
                <h1 class="product-info__title"><?= e($product['name']) ?></h1>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if ($product['is_new']): ?><span class="badge rounded-pill text-bg-primary">New</span><?php endif; ?>
                    <span class="stock-pill stock-pill--<?= $stockTone ?>">
                        <?= icon($stockTone === 'out' ? 'x-circle' : ($stockTone === 'low' ? 'exclamation-circle' : 'check-circle')) ?>
                        <?= e($stockText) ?>
                    </span>
                </div>

                <div class="product-info__prices">
                    <?php if ($onSale): ?>
                        <span class="product-info__price product-info__price--sale">
                            <span class="visually-hidden">Sale price: </span><?= e(format_price($product['sale_price'])) ?>
                        </span>
                        <del class="product-info__regular"><span class="visually-hidden">Regular price: </span><?= e(format_price($product['regular_price'])) ?></del>
                        <span class="product-info__save">Save <?= e(format_price(App\Core\Money::toCents($product['regular_price']) - App\Core\Money::toCents($product['sale_price']))) ?> (<?= (int) $discount ?>%)</span>
                    <?php else: ?>
                        <span class="product-info__price"><?= e(format_price($product['regular_price'])) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($product['short_description']): ?>
                    <p class="product-info__lead"><?= e($product['short_description']) ?></p>
                <?php endif; ?>

                <?php if ($inCart > 0): ?>
                    <p class="in-cart-note"><?= icon('bag-check') ?>
                        You have <strong><?= $inCart ?></strong> in your cart. <a href="<?= e(url('cart')) ?>">View cart</a></p>
                <?php endif; ?>

                <?php
                // Order Now only: adds one (unless already in the cart) and goes to checkout.
                // Products with sizes/colours: app.js checks the chosen option (data-variants).
                $plainInCart = $options ? 0 : $inCart;
                ?>
                <?php if ($stock > 0): ?>
                    <form method="post" action="<?= e(url('cart/add')) ?>" id="buy-form" data-buy-form
                          data-price="<?= (int) $priceCents ?>" data-currency="<?= e((string) config('app.store.currency_symbol', '')) ?>"
                          <?= $options ? 'data-variants="' . e(json_encode($variantData, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                        <input type="hidden" name="return" value="<?= e(current_return_path()) ?>">

                        <?php if ($options): ?>
                            <?php if ($options['has_sizes']): ?>
                                <fieldset class="option-group" data-option-group="size">
                                    <legend class="option-group__label">Size<span class="option-group__chosen" data-option-chosen></span></legend>
                                    <div class="option-chips">
                                        <?php foreach ($options['sizes'] as $i => $size): $out = !$sizeHasStock($size); ?>
                                            <input type="radio" class="option-chip__input" name="size" id="size-<?= $i ?>" value="<?= e($size) ?>"
                                                   required <?= $out ? 'disabled' : '' ?> <?= $autoSize === $size ? 'checked' : '' ?>>
                                            <label for="size-<?= $i ?>" class="option-chip<?= $out ? ' is-out' : '' ?>"
                                                   <?= $out ? 'title="Out of stock"' : '' ?>><?= e($size) ?><?php if ($out): ?><span class="visually-hidden"> (out of stock)</span><?php endif; ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            <?php endif; ?>
                            <?php if ($options['has_colours']): ?>
                                <fieldset class="option-group" data-option-group="colour">
                                    <legend class="option-group__label">Colour<span class="option-group__chosen" data-option-chosen></span></legend>
                                    <div class="option-chips">
                                        <?php foreach ($options['colours'] as $i => $c): $out = !$colourHasStock($c['name']); ?>
                                            <input type="radio" class="option-chip__input" name="colour" id="colour-<?= $i ?>" value="<?= e($c['name']) ?>"
                                                   required <?= $out ? 'disabled' : '' ?> <?= $autoColour === $c['name'] ? 'checked' : '' ?>>
                                            <label for="colour-<?= $i ?>" class="option-chip option-chip--colour<?= $out ? ' is-out' : '' ?>"
                                                   <?= $out ? 'title="Out of stock"' : '' ?>>
                                                <?php if ($c['hex']): ?><span class="option-chip__swatch" style="background-color: <?= e($c['hex']) ?>" aria-hidden="true"></span><?php endif; ?>
                                                <?= e($c['name']) ?><?php if ($out): ?><span class="visually-hidden"> (out of stock)</span><?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            <?php endif; ?>
                            <p class="option-status small mb-3" data-option-status aria-live="polite"></p>
                        <?php endif; ?>
                    </form>

                    <div class="product-buy">
                        <button type="submit" form="buy-form" name="buy_now" value="1" class="btn btn-primary btn-lg product-buy__btn" data-order-now>
                            <?= icon('bag-check', 'me-2') ?>Order Now
                        </button>
                    </div>
                    <p class="product-total" aria-live="polite">Total: <strong data-line-total><?= e(format_price($priceCents * max(1, $plainInCart))) ?></strong>
                        <?php if ($plainInCart > 0): ?><span class="text-muted small"> (<?= $plainInCart ?> in cart)</span><?php endif; ?></p>
                    <?php if ($stockTone === 'low' && !$options): ?>
                        <p class="small text-warning-emphasis mt-2 mb-0"><?= icon('lightning-charge') ?> Selling fast &mdash; only <?= $stock ?> left.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="product-buy">
                        <button type="button" class="btn btn-secondary btn-lg product-buy__btn" disabled>Out of stock</button>
                    </div>
                    <p class="small text-muted mt-2 mb-0">This item is currently unavailable. Please check back soon.</p>
                <?php endif; ?>

                <?php $inWishlist = App\Models\Wishlist::has((int) $product['id']); ?>
                <form method="post" action="<?= e(url('wishlist/toggle')) ?>" class="product-wish">
                    <?= csrf_field() ?>
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <input type="hidden" name="return" value="<?= e(current_return_path()) ?>">
                    <button type="submit" class="btn btn-link px-0 text-decoration-none text-body<?= $inWishlist ? ' is-on' : '' ?>" aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>">
                        <?= icon($inWishlist ? 'heart-fill' : 'heart') ?><?= $inWishlist ? 'Saved to wishlist' : 'Add to wishlist' ?>
                    </button>
                </form>

                <ul class="product-info__perks list-unstyled">
                    <?php if ($freeFrom = free_delivery_from()): ?>
                        <li><?= icon('truck') ?> Free delivery over <?= e(format_price($freeFrom)) ?></li>
                    <?php elseif (($zoneHint = delivery_zone_hint()) !== ''): ?>
                        <li><?= icon('truck') ?> Delivery: <?= e($zoneHint) ?></li>
                    <?php endif; ?>
                    <li><?= icon('cash-coin') ?> Cash on delivery</li>
                    <li><?= icon('arrow-repeat') ?> <?= (int) config('app.store.return_days', 7) ?>-day returns</li>
                </ul>

                <div class="product-accordion">
                    <?php if ($product['description']): ?>
                        <section class="product-description" aria-labelledby="desc-title">
                            <details open>
                                <summary><h2 class="product-accordion__title" id="desc-title">Description</h2></summary>
                                <div class="product-description__body"><?= nl2br(e($product['description'])) ?></div>
                            </details>
                        </section>
                    <?php endif; ?>
                    <details>
                        <summary><h2 class="product-accordion__title">Delivery &amp; returns</h2></summary>
                        <p><?php if (($zoneHint = delivery_zone_hint()) !== ''): ?>Delivery charge: <?= e($zoneHint) ?>.<?php endif; ?>
                            <?php if ($freeFrom = free_delivery_from()): ?>Free on orders over <?= e(format_price($freeFrom)) ?>.<?php endif; ?>
                            We call to confirm every order before dispatch.</p>
                        <p class="mb-0">Not right? Return unused items within <?= (int) config('app.store.return_days', 7) ?> days of delivery.</p>
                    </details>
                    <details>
                        <summary><h2 class="product-accordion__title">Payment</h2></summary>
                        <p class="mb-0">Pay in cash when your order arrives. No card needed.</p>
                    </details>
                </div>

                <p class="product-info__sku">SKU <span class="font-monospace"><?= e($product['sku']) ?></span></p>
            </div>
        </div>
    </div>
</div>

<?php if ($stock > 0): ?>
    <!-- Phones: price + Order Now always within reach -->
    <div class="buy-bar d-md-none">
        <div class="buy-bar__price">
            <span class="buy-bar__label"><?= e($product['name']) ?></span>
            <strong data-line-total><?= e(format_price($priceCents * max(1, $plainInCart))) ?></strong>
        </div>
        <button type="submit" form="buy-form" name="buy_now" value="1" class="btn btn-primary" data-order-now>Order Now</button>
    </div>
<?php endif; ?>

<?php if ($related): ?>
<section class="section" aria-labelledby="related-title">
    <div class="container">
        <div class="section-head">
            <h2 class="section-title" id="related-title">You may also like</h2>
            <a href="<?= e(url('category/' . $product['category_slug'])) ?>" class="section-link">More in <?= e($product['category_name']) ?> <?= icon('chevron-right') ?></a>
        </div>
        <div class="product-grid">
            <?php foreach ($related as $item): ?>
                <div><?php partial('product-card', ['product' => $item, 'buy_button' => false]); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<dialog class="lightbox" data-lightbox aria-label="Product images">
    <div class="lightbox__stage" data-lightbox-stage>
        <img src="<?= e(image_url($main['image_path'] ?? null)) ?>" alt="<?= e($product['name']) ?>" class="lightbox__img" width="<?= (int) $productSpec['width'] ?>" height="<?= (int) $productSpec['height'] ?>" loading="lazy" decoding="async" data-lightbox-img>
    </div>
    <button type="button" class="lightbox__btn lightbox__close" data-lightbox-close aria-label="Close"><?= icon('x') ?></button>
    <?php if (count($images) > 1): ?>
        <button type="button" class="lightbox__btn lightbox__prev" data-lightbox-step="-1" aria-label="Previous image"><?= icon('chevron-left') ?></button>
        <button type="button" class="lightbox__btn lightbox__next" data-lightbox-step="1" aria-label="Next image"><?= icon('chevron-right') ?></button>
    <?php endif; ?>
    <p class="lightbox__hint" data-lightbox-count></p>
</dialog>
