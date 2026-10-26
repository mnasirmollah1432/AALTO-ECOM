<?php
/**
 * Side cart (slides in from the right) + the floating "N Items / total" tab,
 * like Ghorer Bazar. Opens from the tab or the header cart icon, and by itself
 * after an item is added or a quantity changed. Not shown on the cart,
 * checkout and confirmation pages. Without JavaScript the tab links to /cart.
 */
if (!cart_drawer_enabled()) {
    return;
}
try {
    $cart = App\Models\Cart::build();
} catch (Throwable) {
    return;
}
$lines = $cart['lines'];
$count = $cart['count'];
$open = !empty($_SESSION['_open_cart']);
unset($_SESSION['_open_cart']);
$return = current_return_path();
?>
<a href="<?= e(url('cart')) ?>" class="cart-tab" data-bs-toggle="offcanvas" data-bs-target="#cartDrawer" aria-controls="cartDrawer"
   aria-label="Open cart: <?= $count ?> item<?= $count === 1 ? '' : 's' ?>, <?= e(format_price($cart['subtotal'])) ?>">
    <span class="cart-tab__top"><?= icon('bag') ?><span><?= $count ?> Item<?= $count === 1 ? '' : 's' ?></span></span>
    <span class="cart-tab__total"><?= e(format_price($cart['subtotal'])) ?></span>
</a>

<div class="offcanvas offcanvas-end cart-drawer" tabindex="-1" id="cartDrawer" aria-labelledby="cartDrawerTitle" data-cart-drawer <?= $open ? 'data-open="1"' : '' ?>>
    <div class="offcanvas-header cart-drawer__head">
        <h2 class="offcanvas-title h5 mb-0" id="cartDrawerTitle"><?= icon('bag', 'me-2') ?>Your cart <span class="text-muted fw-normal fs-6">(<?= $count ?>)</span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close cart"></button>
    </div>
    <div class="offcanvas-body cart-drawer__body">
        <?php if (!$lines): ?>
            <div class="text-center text-muted py-5">
                <?= icon('bag', 'fs-1 d-block mx-auto mb-2') ?>
                <p class="mb-3">Your cart is empty.</p>
                <a href="<?= e(url('shop')) ?>" class="btn btn-primary">Start shopping</a>
            </div>
        <?php else: ?>
            <ul class="list-unstyled cart-drawer__list">
                <?php foreach ($lines as $line):
                    $key = $line['key'];
                    $qty = (int) $line['quantity'];
                ?>
                    <li class="cart-drawer__item">
                        <a href="<?= e(url('product/' . $line['slug'])) ?>" class="cart-drawer__img" tabindex="-1" aria-hidden="true">
                            <?= responsive_img($line['image_path'], '', '64px', ['width' => 64, 'height' => 80]) ?>
                        </a>
                        <div class="cart-drawer__info">
                            <a href="<?= e(url('product/' . $line['slug'])) ?>" class="cart-drawer__name"><?= e($line['name']) ?></a>
                            <?php if ($line['variant_label'] !== ''): ?><div class="cart-drawer__variant"><?= e($line['variant_label']) ?></div><?php endif; ?>
                            <div class="cart-drawer__price"><?= e(format_price($line['unit_cents'])) ?></div>
                            <div class="cart-drawer__row">
                                <form method="post" action="<?= e(url('cart/update')) ?>" class="cart-stepper cart-stepper--sm">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="line" value="<?= e($key) ?>">
                                    <input type="hidden" name="return" value="<?= e($return) ?>">
                                    <input type="hidden" name="drawer" value="1">
                                    <button type="submit" name="step" value="-1" class="cart-stepper__btn" aria-label="One less <?= e($line['name']) ?>">&minus;</button>
                                    <span class="cart-stepper__count"><?= $qty ?></span>
                                    <button type="submit" name="step" value="1" class="cart-stepper__btn" aria-label="One more <?= e($line['name']) ?>" <?= $qty >= $line['max_qty'] ? 'disabled' : '' ?>>+</button>
                                </form>
                                <strong class="cart-drawer__line"><?= e(format_price($line['line_cents'])) ?></strong>
                            </div>
                        </div>
                        <form method="post" action="<?= e(url('cart/remove')) ?>" class="cart-drawer__remove">
                            <?= csrf_field() ?>
                            <input type="hidden" name="line" value="<?= e($key) ?>">
                            <input type="hidden" name="return" value="<?= e($return) ?>">
                            <input type="hidden" name="drawer" value="1">
                            <button type="submit" class="btn-icon btn-sm" aria-label="Remove <?= e($line['name']) ?>" title="Remove"><?= icon('trash3') ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php if ($lines): ?>
        <div class="cart-drawer__foot">
            <div class="d-flex justify-content-between align-items-baseline mb-1">
                <span class="fw-semibold">Subtotal</span>
                <strong class="fs-5"><?= e(format_price($cart['subtotal'])) ?></strong>
            </div>
            <p class="small text-muted mb-3"><?= $cart['free_delivery'] ? 'Free delivery on this order.' : 'Delivery is added at checkout by district.' ?></p>
            <div class="d-grid gap-2">
                <a href="<?= e(url('checkout')) ?>" class="btn btn-primary btn-lg"><?= icon('bag-check', 'me-2') ?>Checkout</a>
                <a href="<?= e(url('cart')) ?>" class="btn btn-outline-dark">View cart</a>
            </div>
        </div>
    <?php endif; ?>
</div>
