<?php
/**
 * Courier (Steadfast) card on the admin order page (included by view.php).
 *
 * @var array $order
 * @var array $errors
 */
use App\Core\Money;
use App\Models\Courier;

$sent = ($order['courier'] ?? null) === Courier::NAME;
$sending = ($order['courier'] ?? null) === 'sending';
$blocked = $sent ? null : Courier::cannotSend($order);
$settings = Courier::settings();
$defaultCod = $order['payment_status'] === 'paid' ? '0.00' : Money::fromCents(Money::toCents($order['grand_total']));
$fraud = Courier::ready() ? Courier::lastFraud((int) $order['id']) : null;
?>
<?php if (Courier::ready()): ?>
<div class="card mb-3" id="customer-record">
    <div class="card-body">
        <h2 class="h6 fw-semibold mb-1"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Customer record</h2>
        <p class="small text-muted mb-2">Delivery history of <?= e($order['customer_phone']) ?> across Steadfast merchants: delivered vs returned, and fraud reports.</p>
        <?php if ($fraud):
            [$levelLabel, $levelClass] = Courier::fraudLevel($fraud['level']);
        ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge <?= e($levelClass) ?> fs-6" data-fraud-level><?= e($levelLabel) ?></span>
                <?php if ($fraud['rate'] !== null && $fraud['total']): ?><strong><?= (int) $fraud['rate'] ?>% delivered</strong><?php endif; ?>
            </div>
            <?php if ($fraud['total'] !== null && $fraud['total'] > 0): ?>
                <div class="progress mb-2" role="progressbar" aria-label="Delivered share" aria-valuenow="<?= (int) $fraud['rate'] ?>" aria-valuemin="0" aria-valuemax="100" style="height: 8px">
                    <div class="progress-bar bg-success" style="width: <?= (int) $fraud['rate'] ?>%"></div>
                    <div class="progress-bar bg-danger" style="width: <?= 100 - (int) $fraud['rate'] ?>%"></div>
                </div>
                <dl class="row small mb-2">
                    <dt class="col-6 text-muted fw-normal">Total parcels</dt><dd class="col-6 mb-1"><?= (int) $fraud['total'] ?></dd>
                    <dt class="col-6 text-muted fw-normal">Delivered</dt><dd class="col-6 mb-1 text-success"><?= (int) $fraud['delivered'] ?></dd>
                    <dt class="col-6 text-muted fw-normal">Returned / cancelled</dt><dd class="col-6 mb-1 text-danger"><?= (int) $fraud['cancelled'] ?></dd>
                    <dt class="col-6 text-muted fw-normal">Fraud reports</dt><dd class="col-6 mb-0<?= $fraud['reports'] ? ' text-danger fw-semibold' : '' ?>"><?= (int) $fraud['reports'] ?></dd>
                </dl>
            <?php else: ?>
                <p class="small mb-2"><?= e(Courier::fraudLine($fraud)) ?>.</p>
            <?php endif; ?>
            <?php if ($fraud['details']): ?>
                <details class="small mb-2">
                    <summary class="text-muted">Details from Steadfast</summary>
                    <dl class="row mt-2 mb-0">
                        <?php foreach ($fraud['details'] as $label => $value): ?>
                            <dt class="col-7 text-muted fw-normal text-break"><?= e($label) ?></dt><dd class="col-5 mb-1 text-break"><?= e($value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </details>
            <?php endif; ?>
            <p class="small text-muted mb-2">Checked <?= e(format_datetime($fraud['checked_at'])) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/orders/courier/' . $order['id'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="fraud">
            <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-search me-1" aria-hidden="true"></i><?= $fraud ? 'Check again' : 'Check customer record' ?></button>
        </form>
    </div>
</div>
<?php endif; ?>
<div class="card mb-3" id="courier">
    <div class="card-body">
        <h2 class="h6 fw-semibold mb-3"><i class="bi bi-truck me-1" aria-hidden="true"></i>Courier: Steadfast</h2>

        <?php if ($sent): ?>
            <dl class="meta-list row small mb-3">
                <dt class="col-5 text-muted fw-normal">Status</dt>
                <dd class="col-7 mb-2"><span class="badge rounded-pill <?= e(Courier::statusBadge($order['courier_status'])) ?>" data-courier-status><?= e(Courier::statusLabel($order['courier_status'])) ?></span></dd>
                <dt class="col-5 text-muted fw-normal">Consignment</dt>
                <dd class="col-7 mb-2 font-monospace" data-consignment><?= e($order['courier_consignment_id']) ?></dd>
                <dt class="col-5 text-muted fw-normal">Tracking</dt>
                <dd class="col-7 mb-2 font-monospace">
                    <?php if ($order['courier_tracking_code']): ?>
                        <a href="<?= e(Courier::trackingUrl($order['courier_tracking_code'])) ?>" target="_blank" rel="noopener noreferrer"><?= e($order['courier_tracking_code']) ?> <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                    <?php else: ?>-<?php endif; ?>
                </dd>
                <dt class="col-5 text-muted fw-normal">COD</dt>
                <dd class="col-7 mb-2"><?= e(format_price($order['courier_cod_amount'])) ?></dd>
                <dt class="col-5 text-muted fw-normal">Sent</dt>
                <dd class="col-7 mb-2"><?= e(format_datetime($order['courier_sent_at'])) ?></dd>
                <dt class="col-5 text-muted fw-normal">Last update</dt>
                <dd class="col-7 mb-0"><?= e(format_datetime($order['courier_updated_at'])) ?></dd>
            </dl>
            <?php if (in_array($order['courier_status'], ['cancelled', 'cancelled_approval_pending'], true) && $order['status'] !== 'cancelled'): ?>
                <div class="alert alert-warning small py-2">Steadfast reports the parcel as cancelled/returned. Cancel the order above to return the items to stock.</div>
            <?php endif; ?>
            <?php if (Courier::ready()): ?>
                <form method="post" action="<?= e(url('admin/orders/courier/' . $order['id'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Check status now</button>
                </form>
            <?php endif; ?>
            <?php if ($events = Courier::events((int) $order['id'], 6)): ?>
                <ul class="list-unstyled small mt-3 mb-0 courier-events">
                    <?php foreach ($events as $ev): ?>
                        <li><span class="text-muted"><?= e(format_datetime($ev['created_at'])) ?></span> · <?= e(['send' => 'Sent', 'check' => 'Checked', 'webhook' => 'Steadfast'][$ev['source']] ?? $ev['source']) ?>: <?= e($ev['message'] ?? Courier::statusLabel($ev['status'])) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        <?php elseif ($sending): ?>
            <p class="small mb-0"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Being sent to Steadfast... Refresh this page in a moment.</p>

        <?php elseif ($blocked !== null): ?>
            <p class="small text-muted mb-0"><?= e($blocked) ?>
                <?php if (!Courier::ready()): ?><a href="<?= e(url('admin/courier')) ?>">Set up Steadfast</a><?php endif; ?></p>

        <?php else: ?>
            <form method="post" action="<?= e(url('admin/orders/courier/' . $order['id'])) ?>" data-submit-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <div class="mb-2">
                    <label for="cod_amount" class="form-label small mb-1">Cash to collect (COD)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><?= e((string) config('app.store.currency_symbol')) ?></span>
                        <input type="text" id="cod_amount" name="cod_amount" inputmode="decimal" class="form-control<?= isset($errors['cod_amount']) ? ' is-invalid' : '' ?>"
                               value="<?= e(old('cod_amount', $defaultCod)) ?>" required>
                    </div>
                    <?php if (isset($errors['cod_amount'])): ?><div class="invalid-feedback d-block"><?= e($errors['cod_amount']) ?></div><?php endif; ?>
                    <div class="form-text">Order total <?= e(format_price($order['grand_total'])) ?>. Use 0 if already paid.</div>
                </div>
                <div class="mb-2">
                    <label for="courier_note" class="form-label small mb-1">Note for the rider <span class="text-muted">(optional)</span></label>
                    <input type="text" id="courier_note" name="courier_note" maxlength="<?= Courier::NOTE_MAX ?>" class="form-control form-control-sm" value="<?= e(old('courier_note', $settings['default_note'])) ?>" placeholder="e.g. Call before delivery">
                </div>
                <div class="small text-muted mb-2">Address sent: <?= e(Courier::address($order)) ?></div>
                <div class="form-check small mb-3">
                    <input class="form-check-input" type="checkbox" id="mark_shipped" name="mark_shipped" value="1" <?= $settings['mark_shipped'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mark_shipped">Also mark the order as Shipped</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100" data-submit>
                    <span data-submit-label><i class="bi bi-send me-1" aria-hidden="true"></i>Send to Steadfast</span>
                    <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-submit-spinner></span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
