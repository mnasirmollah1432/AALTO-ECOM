<?php
/**
 * @var array $cart     see App\Models\Cart::build()
 * @var array $notices  cart adjustments since the last visit
 * @var array $errors   field => message
 * @var array $tree     districts with zone charge and thanas (Location::checkoutTree())
 */
use App\Core\Money;
use App\Models\Cart;
use App\Models\Order;

$lines = $cart['lines'];

// Delivery is known once a district is chosen (after a failed submit: the typed one)
$oldDistrict = (int) old('district_id');
$oldThana = (int) old('thana_id');
$chosen = null;
foreach ($tree as $d) {
    if ($d['id'] === $oldDistrict) {
        $chosen = $d;
    }
}
$delivery = $chosen ? Cart::deliveryFor($cart['subtotal'], Money::toCents($chosen['charge'])) : null;
$total = $cart['subtotal'] + ($delivery ?? 0);
$deliveryText = $delivery === null ? 'Select district' : ($delivery === 0 ? 'Free' : format_price($delivery));
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$error = static fn (string $f): string => isset($errors[$f])
    ? '<div id="' . $f . '-error" class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
$describedBy = static fn (string $f, string $help = ''): string => trim($help . (isset($errors[$f]) ? " {$f}-error" : ''));
?>
<div class="page-head">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('cart')) ?>">Cart</a></li>
                <li class="breadcrumb-item active" aria-current="page">Checkout</li>
            </ol>
        </nav>
        <h1 class="page-head__title">Checkout</h1>
        <p class="page-head__count mb-0">Pay with cash when your order arrives.</p>
    </div>
</div>

<div class="container">
    <?php foreach ($notices as $notice): ?>
        <div class="alert alert-warning d-flex gap-2" role="status">
            <?= icon('exclamation-triangle') ?><span><?= e($notice) ?></span>
        </div>
    <?php endforeach; ?>

    <details class="mobile-summary d-lg-none">
        <summary>
            <span><?= icon('bag', 'me-2') ?>Order summary (<?= (int) $cart['count'] ?> item<?= $cart['count'] === 1 ? '' : 's' ?>)</span>
            <strong data-total-out><?= e(format_price($total)) ?></strong>
        </summary>
        <ul class="checkout-items list-unstyled mb-0">
            <?php foreach ($lines as $line): ?>
                <li class="checkout-item">
                    <span class="checkout-item__image">
                        <?= responsive_img($line['image_path'], '', '56px', ['width' => 56, 'height' => 70]) ?>
                        <span class="checkout-item__qty" aria-hidden="true"><?= (int) $line['quantity'] ?></span>
                    </span>
                    <span class="checkout-item__name"><?= e($line['name']) ?>
                        <?php if ($line['variant_label'] !== ''): ?><span class="d-block small"><?= e($line['variant_label']) ?></span><?php endif; ?>
                        <span class="d-block small text-muted"><?= (int) $line['quantity'] ?> × <?= e(format_price($line['unit_cents'])) ?></span></span>
                    <span class="checkout-item__total"><?= e(format_price($line['line_cents'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>

    <form method="post" action="<?= e(url('checkout')) ?>" novalidate data-checkout-form
          data-subtotal="<?= (int) $cart['subtotal'] ?>" data-free-from="<?= (int) $cart['free_delivery_from'] ?>"
          data-currency="<?= e((string) config('app.store.currency_symbol', '')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="cart_fingerprint" value="<?= e($cart['fingerprint']) ?>">
        <!-- The delivery charge the shopper saw; the server refuses the order if it no longer matches -->
        <input type="hidden" name="delivery_shown" value="<?= $delivery !== null ? e(Money::fromCents($delivery)) : '' ?>" data-delivery-shown>

        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <section class="checkout-card" aria-labelledby="details-title">
                    <h2 class="checkout-card__title" id="details-title">Delivery details</h2>

                    <div class="mb-3">
                        <label for="name" class="form-label">Full name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" id="name" name="name" class="form-control form-control-lg<?= $invalid('name') ?>"
                               value="<?= e(old('name')) ?>" maxlength="<?= Order::NAME_MAX ?>" autocomplete="name" required
                               <?= $describedBy('name') ? 'aria-describedby="' . e($describedBy('name')) . '"' : '' ?>>
                        <?= $error('name') ?>
                    </div>

                    <div class="mb-3">
                        <label for="mobile" class="form-label">Mobile number <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="tel" id="mobile" name="mobile" class="form-control form-control-lg<?= $invalid('mobile') ?>"
                               value="<?= e(old('mobile')) ?>" maxlength="20" inputmode="tel" autocomplete="tel"
                               placeholder="01XXXXXXXXX" required
                               aria-describedby="<?= e($describedBy('mobile', 'mobile-help')) ?>">
                        <?= $error('mobile') ?>
                        <div id="mobile-help" class="form-text">We'll call this number to confirm your order and delivery.</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label for="district_id" class="form-label">District <span class="text-danger" aria-hidden="true">*</span></label>
                            <select id="district_id" name="district_id" class="form-select form-select-lg<?= $invalid('district_id') ?>" required data-district
                                    <?= $describedBy('district_id') ? 'aria-describedby="' . e($describedBy('district_id')) . '"' : '' ?>>
                                <option value="">Select district</option>
                                <?php foreach ($tree as $d): ?>
                                    <option value="<?= (int) $d['id'] ?>" data-charge="<?= e($d['charge']) ?>" data-zone="<?= e($d['zone']) ?>"
                                        <?= $d['id'] === $oldDistrict ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $error('district_id') ?>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="thana_id" class="form-label">Thana / Area <span class="text-danger" aria-hidden="true">*</span></label>
                            <!-- Grouped by district so it works without JavaScript; the script shows only the chosen district's thanas -->
                            <select id="thana_id" name="thana_id" class="form-select form-select-lg<?= $invalid('thana_id') ?>" required data-thana
                                    <?= $describedBy('thana_id') ? 'aria-describedby="' . e($describedBy('thana_id')) . '"' : '' ?>>
                                <option value="">Select thana</option>
                                <?php foreach ($tree as $d): ?>
                                    <optgroup label="<?= e($d['name']) ?>" data-district-id="<?= (int) $d['id'] ?>">
                                        <?php foreach ($d['thanas'] as $t): ?>
                                            <option value="<?= (int) $t['id'] ?>" <?= $t['id'] === $oldThana && $d['id'] === $oldDistrict ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <?= $error('thana_id') ?>
                        </div>
                    </div>

                    <div>
                        <label for="address" class="form-label">Address <span class="text-danger" aria-hidden="true">*</span></label>
                        <p class="address-preview" data-address-preview hidden><?= icon('geo-alt', 'me-1') ?>Your Address: <strong data-address-text></strong></p>
                        <textarea id="address" name="address" rows="2" class="form-control form-control-lg<?= $invalid('address') ?>"
                                  maxlength="<?= Order::ADDRESS_MAX ?>" autocomplete="street-address" required
                                  placeholder="House, road, area (e.g. House#4, Road 2, Dhanmondi)" data-address
                                  aria-describedby="<?= e($describedBy('address', 'address-help')) ?>"><?= e(old('address')) ?></textarea>
                        <?= $error('address') ?>
                        <div id="address-help" class="form-text">House/flat, road and area. Choose the district and thana above.</div>
                    </div>
                </section>

                <section class="checkout-card" aria-labelledby="payment-title">
                    <h2 class="checkout-card__title" id="payment-title">Payment</h2>
                    <div class="payment-option<?= $invalid('payment_method') ?>">
                        <input class="form-check-input" type="radio" name="payment_method" id="pay-cod" value="<?= e(Order::PAYMENT_COD) ?>" checked>
                        <label for="pay-cod" class="payment-option__label">
                            <?= icon('cash-coin') ?>
                            <span>
                                <strong>Cash on Delivery</strong>
                                <span class="d-block small text-muted">Pay in cash when you receive your order.</span>
                            </span>
                        </label>
                    </div>
                    <?php if (isset($errors['payment_method'])): ?>
                        <div class="invalid-feedback d-block"><?= e($errors['payment_method']) ?></div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-12 col-lg-5">
                <aside class="cart-summary" aria-labelledby="review-title">
                    <h2 class="cart-summary__title" id="review-title">Your order</h2>

                    <ul class="checkout-items list-unstyled d-none d-lg-block">
                        <?php foreach ($lines as $line): ?>
                            <li class="checkout-item">
                                <span class="checkout-item__image">
                                    <?= responsive_img($line['image_path'], '', '56px', ['width' => 56, 'height' => 56]) ?>
                                    <span class="checkout-item__qty" aria-hidden="true"><?= (int) $line['quantity'] ?></span>
                                </span>
                                <span class="checkout-item__name">
                                    <?= e($line['name']) ?>
                                    <?php if ($line['variant_label'] !== ''): ?><span class="d-block small"><?= e($line['variant_label']) ?></span><?php endif; ?>
                                    <span class="d-block small text-muted"><?= (int) $line['quantity'] ?> × <?= e(format_price($line['unit_cents'])) ?></span>
                                </span>
                                <span class="checkout-item__total"><?= e(format_price($line['line_cents'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <dl class="cart-summary__rows">
                        <div><dt>Subtotal (<?= (int) $cart['count'] ?> item<?= $cart['count'] === 1 ? '' : 's' ?>)</dt><dd><?= e(format_price($cart['subtotal'])) ?></dd></div>
                        <div><dt>Delivery <span class="d-block small text-muted fw-normal" data-zone-out><?= $chosen ? e($chosen['zone']) : '' ?></span></dt><dd data-delivery-out class="<?= $delivery === 0 ? 'text-success fw-semibold' : ($delivery === null ? 'text-muted' : '') ?>"><?= e($deliveryText) ?></dd></div>
                        <div class="cart-summary__total"><dt>Total to pay</dt><dd data-total-out><?= e(format_price($total)) ?></dd></div>
                    </dl>
                    <?php if ($cart['free_delivery']): ?>
                        <p class="small text-success mb-3"><?= icon('check-circle-fill', 'me-1') ?>Your order qualifies for free delivery.</p>
                    <?php elseif (($zoneHint = delivery_zone_hint()) !== ''): ?>
                        <p class="small text-muted mb-3"><?= icon('truck', 'me-1') ?>Delivery charge: <?= e($zoneHint) ?>.</p>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-lg w-100" data-place-order>
                        <span data-label>Place order &middot; <span data-total-out><?= e(format_price($total)) ?></span></span>
                        <span class="spinner-border spinner-border-sm d-none ms-2" aria-hidden="true" data-spinner></span>
                    </button>
                    <p class="small text-muted text-center mt-2 mb-0">
                        By placing your order you agree to pay <span data-total-out><?= e(format_price($total)) ?></span> in cash on delivery.
                    </p>
                    <p class="text-center mt-3 mb-0"><a href="<?= e(url('cart')) ?>" class="small">Edit cart</a></p>
                </aside>
            </div>
        </div>
    </form>
</div>
