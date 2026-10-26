<?php
/**
 * @var array $cart     see App\Models\Cart::build()
 * @var array $notices  adjustments made since the last visit
 */
$lines = $cart['lines'];
$count = $cart['count'];
?>
<div class="page-head">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
                <li class="breadcrumb-item active" aria-current="page">Cart</li>
            </ol>
        </nav>
        <h1 class="page-head__title">Your cart</h1>
        <p class="page-head__count mb-0"><?= $count ?> item<?= $count === 1 ? '' : 's' ?></p>
    </div>
</div>

<div class="container">
    <?php foreach ($notices as $notice): ?>
        <div class="alert alert-warning d-flex gap-2 align-items-start" role="status">
            <?= icon('exclamation-triangle') ?><span><?= e($notice) ?></span>
        </div>
    <?php endforeach; ?>

    <?php if (!$lines): ?>
        <div class="empty-state my-4">
            <?= icon('bag') ?>
            <h2 class="h5 mt-2">Your cart is empty</h2>
            <p class="text-muted">Looks like you haven't added anything yet.</p>
            <a href="<?= e(url('shop')) ?>" class="btn btn-primary">Start shopping</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <!-- Items -->
            <div class="col-12 col-lg-8">
                <div class="cart-list" role="list" aria-label="Items in your cart">
                    <div class="cart-list__head d-none d-md-grid" aria-hidden="true">
                        <span>Product</span><span>Price</span><span>Quantity</span><span class="text-end">Subtotal</span><span></span>
                    </div>

                    <?php foreach ($lines as $line):
                        $key = $line['key'];
                        $domId = 'qty-' . str_replace(':', '-', $key);
                        $productUrl = url('product/' . $line['slug']);
                        $qty = (int) $line['quantity'];
                    ?>
                        <div class="cart-line" role="listitem">
                            <a href="<?= e($productUrl) ?>" class="cart-line__image" tabindex="-1" aria-hidden="true">
                                <?= responsive_img($line['image_path'], '', '96px', ['width' => 96, 'height' => 96]) ?>
                            </a>

                            <div class="cart-line__info">
                                <a href="<?= e($productUrl) ?>" class="cart-line__name"><?= e($line['name']) ?></a>
                                <?php if ($line['variant_label'] !== ''): ?>
                                    <div class="cart-line__variant"><?= e($line['variant_label']) ?></div>
                                <?php endif; ?>
                                <div class="cart-line__meta"><?= e($line['category_name']) ?></div>
                                <?php if ($line['max_qty'] <= 5): ?>
                                    <div class="cart-line__stock">Only <?= (int) $line['max_qty'] ?> left</div>
                                <?php endif; ?>
                            </div>

                            <div class="cart-line__price">
                                <span class="cart-line__label d-md-none">Price</span>
                                <span>
                                    <?= e(format_price($line['unit_cents'])) ?>
                                    <?php if ($line['on_sale']): ?>
                                        <del class="d-block small text-muted"><span class="visually-hidden">Regular price </span><?= e(format_price($line['regular_price'])) ?></del>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <form method="post" action="<?= e(url('cart/update')) ?>" class="cart-line__qty">
                                <?= csrf_field() ?>
                                <input type="hidden" name="line" value="<?= e($key) ?>">
                                <!-- First submit button = what Enter triggers: apply the typed quantity -->
                                <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">Update</button>
                                <div class="qty qty--sm">
                                    <button type="submit" name="step" value="-1" class="qty__btn"
                                            aria-label="Decrease quantity of <?= e($line['name']) ?>" <?= $qty <= 1 ? 'disabled' : '' ?>>&minus;</button>
                                    <label for="<?= e($domId) ?>" class="visually-hidden">Quantity of <?= e($line['name']) ?></label>
                                    <input type="number" id="<?= e($domId) ?>" name="quantity" value="<?= $qty ?>" min="0" max="<?= (int) $line['max_qty'] ?>"
                                           step="1" inputmode="numeric" class="qty__input" data-autosubmit>
                                    <button type="submit" name="step" value="1" class="qty__btn"
                                            aria-label="Increase quantity of <?= e($line['name']) ?>" <?= $qty >= $line['max_qty'] ? 'disabled' : '' ?>>+</button>
                                </div>
                                <button type="submit" class="btn btn-link btn-sm p-0 js-hide">Update</button>
                            </form>

                            <div class="cart-line__total">
                                <span class="cart-line__label d-md-none">Subtotal</span>
                                <strong><?= e(format_price($line['line_cents'])) ?></strong>
                            </div>

                            <form method="post" action="<?= e(url('cart/remove')) ?>" class="cart-line__remove">
                                <?= csrf_field() ?>
                                <input type="hidden" name="line" value="<?= e($key) ?>">
                                <button type="submit" class="btn btn-icon btn-sm" aria-label="Remove <?= e($line['name']) ?> from cart" title="Remove">
                                    <?= icon('trash3') ?>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
                    <a href="<?= e(url('shop')) ?>" class="btn btn-outline-dark">
                        <?= icon('arrow-left', 'me-1') ?>Continue shopping
                    </a>
                    <form method="post" action="<?= e(url('cart/clear')) ?>" data-confirm="Remove all items from your cart?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-link text-danger text-decoration-none">
                            <?= icon('x-circle', 'me-1') ?>Clear cart
                        </button>
                    </form>
                </div>
            </div>

            <!-- Summary -->
            <div class="col-12 col-lg-4">
                <aside class="cart-summary" aria-labelledby="summary-title">
                    <h2 class="cart-summary__title" id="summary-title">Order summary</h2>

                    <?php if ($cart['free_delivery_gap'] > 0):
                        $progress = (int) floor($cart['subtotal'] * 100 / $cart['free_delivery_from']);
                    ?>
                        <div class="delivery-nudge">
                            <p class="small mb-2">
                                Add <strong><?= e(format_price($cart['free_delivery_gap'])) ?></strong> more for <strong>free delivery</strong>.
                            </p>
                            <div class="progress" role="progressbar" aria-label="Progress towards free delivery"
                                 aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100" style="height: 6px;">
                                <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                            </div>
                        </div>
                    <?php elseif ($cart['free_delivery_from'] > 0): ?>
                        <p class="delivery-nudge delivery-nudge--done small">
                            <?= icon('check-circle-fill', 'me-1') ?>Your order qualifies for free delivery.
                        </p>
                    <?php endif; ?>

                    <dl class="cart-summary__rows">
                        <div><dt>Subtotal (<?= $count ?> item<?= $count === 1 ? '' : 's' ?>)</dt><dd><?= e(format_price($cart['subtotal'])) ?></dd></div>
                        <div><dt>Delivery</dt><dd><?= $cart['free_delivery'] ? '<span class="text-success fw-semibold">Free</span>' : '<span class="text-muted">At checkout</span>' ?></dd></div>
                        <div class="cart-summary__total"><dt><?= $cart['free_delivery'] ? 'Grand total' : 'Total before delivery' ?></dt><dd><?= e(format_price($cart['subtotal'])) ?></dd></div>
                    </dl>
                    <?php if (!$cart['free_delivery'] && ($zoneHint = delivery_zone_hint()) !== ''): ?>
                        <p class="small text-muted mb-3"><?= icon('truck', 'me-1') ?>Delivery: <?= e($zoneHint) ?>. Choose your district at checkout.</p>
                    <?php endif; ?>

                    <a href="<?= e(url('checkout')) ?>" class="btn btn-primary btn-lg w-100">
                        Proceed to checkout <?= icon('arrow-right', 'ms-1') ?>
                    </a>
                    <p class="small text-muted text-center mt-2 mb-0">
                        <?= icon('cash-coin', 'me-1') ?>Cash on Delivery. Prices and stock are checked again when you order.
                    </p>
                </aside>
            </div>
        </div>

        <!-- Phones/tablets: total + checkout always within reach -->
        <div class="checkout-bar d-lg-none">
            <div class="checkout-bar__total">
                <span class="checkout-bar__label">Total</span>
                <strong><?= e(format_price($cart['subtotal'])) ?></strong>
            </div>
            <a href="<?= e(url('checkout')) ?>" class="btn btn-primary">Checkout</a>
        </div>
    <?php endif; ?>
</div>
