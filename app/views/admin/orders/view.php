<?php
/**
 * @var array $order    order row + items
 * @var array $history  status changes, oldest first
 * @var array $errors   field => message
 */
use App\Core\Money;
use App\Models\Order;

$status = $order['status'];
$next = Order::TRANSITIONS[$status] ?? [];
$itemCount = array_sum(array_map('intval', array_column($order['items'], 'quantity')));
$placed = $order['placed_at'] ?? $order['created_at'];
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/orders')) ?>">Orders</a></li>
        <li class="breadcrumb-item active font-monospace" aria-current="page"><?= e($order['order_number']) ?></li>
    </ol>
</nav>

<div class="order-head card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="me-auto">
            <h2 class="h4 fw-bold font-monospace mb-1" data-order-number><?= e($order['order_number']) ?></h2>
            <div class="small text-muted">Placed <?= e(format_datetime($placed)) ?> · <?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?></div>
        </div>
        <dl class="order-head__facts mb-0">
            <div><dt>Status</dt><dd><span class="badge rounded-pill fs-6 <?= e(Order::statusBadge($status)) ?>" data-status><?= e(Order::statusLabel($status)) ?></span></dd></div>
            <div><dt>Payment</dt><dd><?= e(Order::paymentLabel($order['payment_method'])) ?>
                <span class="badge rounded-pill <?= $order['payment_status'] === 'paid' ? 'text-bg-success' : 'text-bg-light border' ?>"><?= e(ucfirst($order['payment_status'])) ?></span></dd></div>
            <div><dt>Total</dt><dd class="fw-bold"><?= e(format_price($order['grand_total'])) ?></dd></div>
        </dl>
    </div>
</div>

<div class="row g-3 g-lg-4">
    <div class="col-12 col-xl-8">
        <!-- Items -->
        <div class="card mb-3">
            <div class="card-body p-0">
                <h2 class="h6 fw-semibold px-3 pt-3 mb-2">Products</h2>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" class="ps-3">Product</th>
                                <th scope="col" class="text-center">Qty</th>
                                <th scope="col" class="text-end">Unit price</th>
                                <th scope="col" class="text-end pe-3">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order['items'] as $item): ?>
                                <tr>
                                    <td class="ps-3 cell-name">
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="<?= e(image_thumb_url($item['image_path'])) ?>" alt="" class="thumb" width="44" height="44" loading="lazy">
                                            <div>
                                                <?php if ($item['product_id']): ?>
                                                    <a href="<?= e(url('admin/products/edit/' . $item['product_id'])) ?>" class="fw-semibold text-body text-decoration-none"><?= e($item['product_name']) ?></a>
                                                <?php else: ?>
                                                    <span class="fw-semibold"><?= e($item['product_name']) ?></span>
                                                    <span class="badge text-bg-light border">product deleted</span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['variant_label'])): ?><span class="d-block small"><?= e($item['variant_label']) ?></span><?php endif; ?>
                                                <div class="small text-muted font-monospace"><?= e($item['product_sku']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center fw-semibold"><?= (int) $item['quantity'] ?></td>
                                    <td class="text-end text-nowrap"><?= e(format_price($item['unit_price'])) ?></td>
                                    <td class="text-end text-nowrap pe-3 fw-semibold"><?= e(format_price($item['line_total'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <dl class="order-totals">
                    <div><dt>Subtotal</dt><dd><?= e(format_price($order['subtotal'])) ?></dd></div>
                    <div><dt>Delivery</dt><dd><?= Money::toCents($order['shipping_fee']) === 0 ? 'Free' : e(format_price($order['shipping_fee'])) ?></dd></div>
                    <?php if (Money::toCents($order['discount_total']) > 0): ?>
                        <div><dt>Discount</dt><dd>&minus;<?= e(format_price($order['discount_total'])) ?></dd></div>
                    <?php endif; ?>
                    <div class="order-totals__grand"><dt>Grand total</dt><dd><?= e(format_price($order['grand_total'])) ?></dd></div>
                </dl>
            </div>
        </div>

        <!-- History -->
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 fw-semibold mb-3">History</h2>
                <ol class="order-timeline list-unstyled mb-0">
                    <?php foreach (array_reverse($history) as $h): ?>
                        <li class="order-timeline__item">
                            <span class="badge rounded-pill <?= e(Order::statusBadge($h['to_status'])) ?>"><?= e(Order::statusLabel($h['to_status'])) ?></span>
                            <span class="small text-muted">
                                <?= e(format_datetime($h['created_at'])) ?> ·
                                <?= $h['from_status'] === null ? 'by customer' : ($h['admin_name'] !== null ? 'by ' . e($h['admin_name']) : 'automatically') ?>
                                <?php if ($h['from_status'] !== null && $h['from_status'] !== $h['to_status']): ?>
                                    · from <?= e(Order::statusLabel($h['from_status'])) ?>
                                <?php endif; ?>
                            </span>
                            <?php if ($h['note']): ?>
                                <div class="order-timeline__note"><?= nl2br(e($h['note'])) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <!-- Customer -->
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 fw-semibold mb-3">Customer</h2>
                <dl class="meta-list row small mb-0">
                    <dt class="col-4 text-muted fw-normal">Name</dt>
                    <dd class="col-8 mb-2 fw-semibold" data-customer-name><?= e($order['customer_name']) ?></dd>
                    <dt class="col-4 text-muted fw-normal">Mobile</dt>
                    <dd class="col-8 mb-2"><a href="tel:<?= e($order['customer_phone']) ?>" class="text-decoration-none" data-customer-mobile><?= e($order['customer_phone']) ?></a></dd>
                    <dt class="col-4 text-muted fw-normal">Address</dt>
                    <dd class="col-8 mb-2" data-customer-address><?= nl2br(e($order['shipping_address'])) ?></dd>
                    <dt class="col-4 text-muted fw-normal">Thana, district</dt>
                    <dd class="col-8 mb-2" data-customer-area><?= ($area = order_area($order)) !== '' ? e($area) : '<span class="text-muted">-</span>' ?></dd>
                    <dt class="col-4 text-muted fw-normal">Delivery zone</dt>
                    <dd class="col-8 mb-0"><?= !empty($order['delivery_zone']) ? e($order['delivery_zone']) : '<span class="text-muted">-</span>' ?></dd>
                </dl>
            </div>
        </div>

        <!-- Status -->
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 fw-semibold mb-3">Update status</h2>
                <?php if (!$next): ?>
                    <p class="small text-muted mb-0">
                        <i class="bi bi-lock me-1" aria-hidden="true"></i>
                        This order is <strong><?= e(Order::statusLabel($status)) ?></strong>. Its status can no longer be changed.
                    </p>
                <?php else: ?>
                    <form method="post" action="<?= e(url('admin/orders/status/' . $order['id'])) ?>"
                          data-confirm="Cancel order <?= e($order['order_number']) ?>? Its items will be returned to stock. This cannot be undone."
                          data-confirm-button="Cancel order" data-confirm-when="cancelled" data-submit-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="current_status" value="<?= e($status) ?>">
                        <div class="mb-3">
                            <label for="status" class="form-label small">New status</label>
                            <select id="status" name="status" class="form-select" required>
                                <?php foreach ($next as $i => $option): ?>
                                    <option value="<?= e($option) ?>" <?= $i === 0 ? 'selected' : '' ?>>
                                        <?= e(Order::statusLabel($option)) ?><?= $i === 0 && $option !== 'cancelled' ? ' (next step)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="note" class="form-label small">Note <span class="text-muted">(optional, internal)</span></label>
                            <textarea id="note" name="note" rows="2" maxlength="<?= Order::NOTE_MAX ?>"
                                      class="form-control<?= isset($errors['note']) ? ' is-invalid' : '' ?>"
                                      placeholder="e.g. Confirmed by phone"><?= e(old('note')) ?></textarea>
                            <?php if (isset($errors['note'])): ?>
                                <div class="invalid-feedback"><?= e($errors['note']) ?></div>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" data-submit>
                            <span data-submit-label>Update status</span>
                            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-submit-spinner></span>
                        </button>
                    </form>
                    <p class="small text-muted mt-3 mb-0">
                        Orders move forward only. Cancelling returns the items to stock; delivering a cash-on-delivery order marks it paid.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php require __DIR__ . '/courier.php'; ?>
    </div>
</div>
