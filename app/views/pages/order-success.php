<?php
/** @var array $order order row + items */
use App\Core\Money;
use App\Models\Order;
?>
<div class="container py-4 py-md-5">
    <div class="order-success">
        <div class="order-success__head">
            <span class="order-success__icon" aria-hidden="true"><?= icon('check-lg') ?></span>
            <h1 class="h3 fw-bold mb-1">Thank you, your order is placed!</h1>
            <p class="text-muted mb-0">We'll call <strong><?= e($order['customer_phone']) ?></strong> to confirm it before delivery.</p>
        </div>

        <dl class="order-facts">
            <div><dt>Order number</dt><dd class="font-monospace fw-bold" data-order-number><?= e($order['order_number']) ?></dd></div>
            <div><dt>Order status</dt><dd><span class="badge rounded-pill text-bg-warning"><?= e(Order::statusLabel($order['status'])) ?></span></dd></div>
            <div><dt>Payment method</dt><dd><?= e(Order::paymentLabel($order['payment_method'])) ?></dd></div>
            <div><dt>Placed on</dt><dd><?= e(format_datetime($order['placed_at'])) ?></dd></div>
        </dl>

        <div class="row g-4">
            <div class="col-12 col-md-5">
                <section class="checkout-card h-100" aria-labelledby="deliver-title">
                    <h2 class="checkout-card__title" id="deliver-title">Delivery to</h2>
                    <p class="mb-1 fw-semibold"><?= e($order['customer_name']) ?></p>
                    <p class="mb-1"><?= icon('telephone', 'me-1 text-muted') ?><?= e($order['customer_phone']) ?></p>
                    <p class="mb-0"><?= icon('geo-alt', 'me-1 text-muted') ?><?= nl2br(e($order['shipping_address'])) ?><?php if (($area = order_area($order)) !== ''): ?><br><?= e($area) ?><?php endif; ?></p>
                </section>
            </div>
            <div class="col-12 col-md-7">
                <section class="checkout-card h-100" aria-labelledby="items-title">
                    <h2 class="checkout-card__title" id="items-title">Items</h2>
                    <table class="table order-items align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Product</th>
                                <th scope="col" class="text-center">Qty</th>
                                <th scope="col" class="text-end">Price</th>
                                <th scope="col" class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order['items'] as $item): ?>
                                <tr>
                                    <td>
                                        <?php if ($item['slug']): ?>
                                            <a href="<?= e(url('product/' . $item['slug'])) ?>" class="text-body text-decoration-none fw-medium"><?= e($item['product_name']) ?></a>
                                        <?php else: ?>
                                            <span class="fw-medium"><?= e($item['product_name']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['variant_label'])): ?><span class="d-block small"><?= e($item['variant_label']) ?></span><?php endif; ?>
                                        <span class="d-block small text-muted font-monospace"><?= e($item['product_sku']) ?></span>
                                    </td>
                                    <td class="text-center"><?= (int) $item['quantity'] ?></td>
                                    <td class="text-end text-nowrap"><?= e(format_price($item['unit_price'])) ?></td>
                                    <td class="text-end text-nowrap fw-semibold"><?= e(format_price($item['line_total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><th scope="row" colspan="3" class="text-end fw-normal text-muted">Subtotal</th><td class="text-end text-nowrap"><?= e(format_price($order['subtotal'])) ?></td></tr>
                            <tr><th scope="row" colspan="3" class="text-end fw-normal text-muted">Delivery</th>
                                <td class="text-end text-nowrap"><?= Money::toCents($order['shipping_fee']) === 0 ? 'Free' : e(format_price($order['shipping_fee'])) ?></td></tr>
                            <tr class="order-items__total"><th scope="row" colspan="3" class="text-end">Total to pay on delivery</th><td class="text-end text-nowrap"><?= e(format_price($order['grand_total'])) ?></td></tr>
                        </tfoot>
                    </table>
                </section>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
            <a href="<?= e(url('shop')) ?>" class="btn btn-primary">Continue shopping</a>
            <button type="button" class="btn btn-outline-dark" data-print><?= icon('printer', 'me-1') ?>Print</button>
        </div>
        <p class="text-center small text-muted mt-3 mb-0">Please keep your order number <strong><?= e($order['order_number']) ?></strong> for reference.</p>
    </div>
</div>
