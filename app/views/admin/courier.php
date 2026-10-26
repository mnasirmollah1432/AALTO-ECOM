<?php
/**
 * Admin -> Courier (Steadfast).
 *
 * @var array  $settings   Courier::settings()
 * @var string $webhookUrl
 * @var array  $stats      sent, delivered, cancelled
 * @var array  $errors
 */
use App\Models\Courier;
use App\Services\Steadfast;

$hasOld = old('_submitted') === '1';
$on = static fn (string $k, bool $saved): bool => $hasOld ? old($k) === '1' : $saved;
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$err = static fn (string $f): string => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
$ready = Courier::ready();
?>
<p class="text-muted mb-3">Send orders to <strong>Steadfast Courier</strong> with one click from the order page (or several from the order list), see the consignment and tracking code, and get delivery updates automatically.</p>

<div class="row g-3 g-lg-4">
    <div class="col-12 col-xl-7">
        <form method="post" action="<?= e(url('admin/courier')) ?>" novalidate class="card mb-3">
            <?= csrf_field() ?>
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <h2 class="h6 fw-semibold mb-0">Steadfast API</h2>
                    <span class="badge <?= $ready ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $ready ? 'Connected (on)' : 'Off' ?></span>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= $on('enabled', $settings['enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="enabled">Use Steadfast for orders</label>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="api_key" class="form-label">API key</label>
                        <input type="password" id="api_key" name="api_key" class="form-control font-monospace<?= $invalid('api_key') ?>" autocomplete="off" spellcheck="false"
                               placeholder="<?= $settings['api_key'] !== '' ? e('Saved: ' . Courier::mask($settings['api_key'])) : 'Paste the API key' ?>">
                        <?= $err('api_key') ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="secret_key" class="form-label">Secret key</label>
                        <input type="password" id="secret_key" name="secret_key" class="form-control font-monospace<?= $invalid('secret_key') ?>" autocomplete="off" spellcheck="false"
                               placeholder="<?= $settings['secret_key'] !== '' ? e('Saved: ' . Courier::mask($settings['secret_key'])) : 'Paste the secret key' ?>">
                        <?= $err('secret_key') ?>
                    </div>
                </div>
                <div class="form-text mb-3">Steadfast merchant panel → API (or ask Steadfast support). Leave empty to keep the saved keys; they are never shown again.</div>

                <label for="base_url" class="form-label small">API address</label>
                <input type="text" id="base_url" name="base_url" class="form-control form-control-sm font-monospace mb-3<?= $invalid('base_url') ?>"
                       value="<?= e($hasOld ? old('base_url') : $settings['base_url']) ?>" placeholder="<?= e(Steadfast::DEFAULT_BASE_URL) ?>" autocapitalize="none" spellcheck="false">
                <?= $err('base_url') ?>

                <label for="default_note" class="form-label small">Default note for the rider <span class="text-muted">(optional)</span></label>
                <input type="text" id="default_note" name="default_note" maxlength="<?= Courier::NOTE_MAX ?>" class="form-control form-control-sm mb-3<?= $invalid('default_note') ?>"
                       value="<?= e($hasOld ? old('default_note') : $settings['default_note']) ?>" placeholder="e.g. Please call before delivery">
                <?= $err('default_note') ?>

                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" id="mark_shipped" name="mark_shipped" value="1" <?= $on('mark_shipped', $settings['mark_shipped']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mark_shipped">When an order is sent to Steadfast, mark it <strong>Shipped</strong></label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="auto_deliver" name="auto_deliver" value="1" <?= $on('auto_deliver', $settings['auto_deliver']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="auto_deliver">When Steadfast reports <strong>delivered</strong>, mark the order Delivered and Paid</label>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">Save courier settings</button>
                    <?php if ($settings['api_key'] !== '' || $settings['secret_key'] !== ''): ?>
                        <button type="submit" name="clear_keys" value="1" class="btn btn-outline-danger" data-confirm-click="Remove the saved Steadfast keys and switch Steadfast off?">Remove keys</button>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if ($settings['api_key'] !== '' && $settings['secret_key'] !== ''): ?>
            <form method="post" action="<?= e(url('admin/courier/test')) ?>" class="mb-3">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1" aria-hidden="true"></i>Test connection (shows your Steadfast balance)</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card mb-3">
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-1">Delivery updates (webhook)</h2>
                <p class="small text-muted">So order statuses update by themselves, enter these two values in the Steadfast merchant panel → Webhook settings:</p>
                <label class="form-label small mb-1" for="wh-url">Callback URL</label>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" id="wh-url" class="form-control font-monospace" value="<?= e($webhookUrl) ?>" readonly>
                    <button type="button" class="btn btn-outline-secondary" data-copy-target="#wh-url">Copy</button>
                </div>
                <label class="form-label small mb-1" for="wh-token">Auth token (Bearer)</label>
                <div class="input-group input-group-sm mb-2">
                    <input type="text" id="wh-token" class="form-control font-monospace" value="<?= e($settings['webhook_token']) ?>" readonly>
                    <button type="button" class="btn btn-outline-secondary" data-copy-target="#wh-token">Copy</button>
                </div>
                <form method="post" action="<?= e(url('admin/courier')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="new_token">
                    <button type="submit" class="btn btn-link btn-sm px-0" data-confirm-click="Create a new token? You must paste the new one into Steadfast, the old one stops working.">Create a new token</button>
                </form>
                <p class="small text-muted mb-0">Without the webhook you can still press "Check status now" on an order.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body p-3 p-md-4 small">
                <h2 class="h6 fw-semibold mb-2">Steadfast orders</h2>
                <p class="mb-0">Sent: <strong><?= (int) $stats['sent'] ?></strong> · Delivered: <strong><?= (int) $stats['delivered'] ?></strong> · Cancelled/returned: <strong><?= (int) $stats['cancelled'] ?></strong></p>
            </div>
        </div>
    </div>
</div>
