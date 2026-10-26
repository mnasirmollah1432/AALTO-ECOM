<?php
/**
 * Reusable product card.
 *
 * @var array $product listing row (Product::LISTING_COLUMNS)
 * @var bool  $eager   true for above-the-fold cards (no lazy loading)
 * @var bool  $buy_button false hides the Add To Cart button (e.g. "You may also like")
 * @var bool  $view_button true adds a View button (product page) beside Add To Cart (home page)
 */
use App\Core\Money;

$onSale = Money::isOnSale($product['regular_price'], $product['sale_price']);
$discount = $onSale ? Money::discountPercent($product['regular_price'], $product['sale_price']) : null;
[$stockText, $stockTone] = stock_status((int) $product['stock_quantity']);
$productUrl = url('product/' . $product['slug']);
$inWishlist = App\Models\Wishlist::has((int) $product['id']);
$hasOptions = !empty($product['has_sizes']) || !empty($product['has_colours']);
$showView = !empty($view_button) && ($buy_button ?? true) !== false;
?>
<article class="card product-card h-100<?= $stockTone === 'out' ? ' is-sold-out' : '' ?>">
    <a href="<?= e($productUrl) ?>" class="product-card__media" tabindex="-1" aria-hidden="true">
        <?= responsive_img($product['image_path'], $product['name'], '(min-width: 1400px) 300px, (min-width: 992px) 23vw, (min-width: 768px) 31vw, 48vw', [
            'width' => 480, 'height' => 600, 'loading' => !empty($eager) ? 'eager' : 'lazy',
        ]) ?>
        <span class="product-card__badges">
            <?php if (!empty($product['is_new'])): ?>
                <span class="badge product-card__badge product-card__badge--new">New</span>
            <?php endif; ?>
            <?php if ($discount): ?>
                <span class="badge product-card__badge">-<?= $discount ?>%</span>
            <?php endif; ?>
        </span>
        <?php if ($stockTone === 'out'): ?>
            <span class="product-card__soldout">Sold out</span>
        <?php endif; ?>
    </a>
    <div class="card-body">
        <a class="product-card__category" href="<?= e(url('category/' . $product['category_slug'])) ?>">
            <?= e($product['category_name']) ?>
        </a>
        <h3 class="product-card__title">
            <a href="<?= e($productUrl) ?>"><?= e($product['name']) ?></a>
        </h3>
        <div class="product-card__prices">
            <?php if ($onSale): ?>
                <span class="product-card__price product-card__price--sale">
                    <span class="visually-hidden">Sale price </span><?= e(format_price($product['sale_price'])) ?>
                </span>
                <del class="product-card__compare"><span class="visually-hidden">Regular price </span><?= e(format_price($product['regular_price'])) ?></del>
            <?php else: ?>
                <span class="product-card__price"><?= e(format_price($product['regular_price'])) ?></span>
            <?php endif; ?>
        </div>
        <p class="product-card__stock product-card__stock--<?= $stockTone ?>"><?= e($stockText) ?></p>

        <?php if ($showView): ?><div class="product-card__actions"><?php endif; ?>
        <?php if (($buy_button ?? true) === false): ?>
        <?php elseif ($stockTone === 'out'): ?>
            <span class="product-card__btn is-disabled">Out of stock</span>
        <?php elseif ($hasOptions): ?>
            <?php // Sold in sizes/colours: app.js opens the quick-add popup to choose them (without JavaScript: the product page)
            $opts = App\Models\Product::options($product);
            quick_add_used(true); ?>
            <a href="<?= e($productUrl) ?>" class="product-card__btn" aria-label="Add <?= e($product['name']) ?> to cart: choose options"
               data-quick-add data-product-id="<?= (int) $product['id'] ?>" data-name="<?= e($product['name']) ?>"
               data-price="<?= e(format_price($product['price'])) ?>" data-image="<?= e(image_url($product['image_path'])) ?>"
               data-options="<?= e(json_encode(['sizes' => $opts['sizes'] ?? [], 'colours' => $opts['colours'] ?? [], 'stock' => (object) ($opts['stock'] ?? [])], JSON_UNESCAPED_UNICODE)) ?>">
                <?= icon('cart3') ?><span>Add To Cart</span>
            </a>
        <?php else: ?>
            <form method="post" action="<?= e(url('cart/add')) ?>" class="product-card__form">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <input type="hidden" name="quantity" value="1">
                <input type="hidden" name="return" value="<?= e(current_return_path()) ?>">
                <button type="submit" class="product-card__btn" aria-label="Add <?= e($product['name']) ?> to cart">
                    <?= icon('cart3') ?><span>Add To Cart</span>
                </button>
            </form>
        <?php endif; ?>
        <?php if ($showView): ?>
            <a href="<?= e($productUrl) ?>" class="product-card__btn product-card__btn--view" aria-label="View <?= e($product['name']) ?>">
                <?= icon('eye') ?><span>View</span>
            </a>
        </div><?php endif; ?>
    </div>

    <!-- Wishlist heart (a form can't sit inside the image link, so it is positioned over it) -->
    <form method="post" action="<?= e(url('wishlist/toggle')) ?>" class="product-card__wish">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
        <input type="hidden" name="return" value="<?= e(current_return_path()) ?>">
        <button type="submit" class="wish-btn<?= $inWishlist ? ' is-on' : '' ?>" aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>"
                aria-label="<?= e(($inWishlist ? 'Remove from wishlist: ' : 'Save to wishlist: ') . $product['name']) ?>"
                title="<?= $inWishlist ? 'Remove from wishlist' : 'Save to wishlist' ?>">
            <?= icon($inWishlist ? 'heart-fill' : 'heart') ?>
        </button>
    </form>
</article>
