<?php
/**
 * Admin -> Appearance.
 *
 * @var array $values announce_enabled, announce_bg, announce_color, announce_text, header_line
 * @var array $errors
 */
$hasOld = old('_submitted') === '1';
$v = static fn (string $key): string => $hasOld ? old($key) : (string) $values[$key];
$safe = static fn (string $key, string $fallback): string => preg_match('/^#[0-9a-fA-F]{6}$/', $v($key)) ? strtolower($v($key)) : $fallback;
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$err = static fn (string $f): string => isset($errors[$f]) ? '<div class="invalid-feedback d-block">' . e($errors[$f]) . '</div>' : '';
$enabled = $v('announce_enabled') === '1';
$bg = $safe('announce_bg', '#16130f');
$fg = $safe('announce_color', '#ffffff');
$line = $safe('header_line', '#fc5d07');
$autoText = 'Delivery all over Bangladesh · Cash on delivery · ' . (int) config('app.store.return_days', 7) . '-day easy returns';
$colourField = static function (string $key, string $label, string $value) use ($v, $invalid, $err): string {
    return '<div class="mb-3"><label for="' . $key . '" class="form-label">' . e($label) . '</label>'
        . '<div class="input-group colour-field" data-colour-field>'
        . '<input type="color" class="form-control form-control-color" value="' . e($value) . '" aria-label="' . e($label) . ' picker" data-colour-picker>'
        . '<input type="text" id="' . $key . '" name="' . $key . '" class="form-control font-monospace' . $invalid($key) . '" value="' . e($v($key)) . '" maxlength="7" autocomplete="off" spellcheck="false" data-colour-text data-preview="' . $key . '">'
        . '</div>' . $err($key) . '</div>';
};
?>
<p class="text-muted mb-3">The thin bar at the very top of every store page, the two promo boxes on the home page, and the line under the menu.</p>

<form method="post" action="<?= e(url('admin/appearance')) ?>" novalidate data-appearance-form>
    <?= csrf_field() ?>
    <div class="row g-3 g-lg-4">
        <div class="col-12 col-xl-7">
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Announcement bar</h2>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="announce_enabled" name="announce_enabled" value="1" <?= $enabled ? 'checked' : '' ?> data-preview="announce_enabled">
                        <label class="form-check-label fw-semibold" for="announce_enabled">Show the announcement bar</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><?= $colourField('announce_bg', 'Background colour', $bg) ?></div>
                        <div class="col-12 col-md-6"><?= $colourField('announce_color', 'Text colour', $fg) ?></div>
                    </div>
                    <label for="announce_text" class="form-label">Text <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" id="announce_text" name="announce_text" class="form-control<?= $invalid('announce_text') ?>" value="<?= e($v('announce_text')) ?>"
                           maxlength="160" placeholder="<?= e($autoText) ?>" data-preview="announce_text" data-auto-text="<?= e($autoText) ?>">
                    <?= $err('announce_text') ?>
                    <div class="form-text">Leave empty to show the automatic text (delivery charges or free delivery, cash on delivery, returns).</div>
                </div>
            </div>
            <?php foreach ([
                'sale'     => ['Home page box: Deals (dark)', 'Limited-time deals', 'Save up to ' . max(1, App\Models\Product::maxDiscountPercent()) . '% on selected items', 'Great prices on everyday favourites, while stocks last.', 'Shop the sale', '/shop?sale=1'],
                'delivery' => ['Home page box: Delivery (light)', 'Home delivery', 'All over Bangladesh', 'Delivery charges, then: Pay in cash when it arrives. Easy ' . (int) config('app.store.return_days', 7) . '-day returns.', 'Start shopping', '/shop'],
            ] as $box => [$heading, $autoEyebrow, $autoTitle, $autoText, $autoButton, $autoLink]):
                $auto = ['eyebrow' => $autoEyebrow, 'title' => $autoTitle, 'text' => $autoText, 'button' => $autoButton, 'link' => $autoLink];
            ?>
                <div class="card mb-3">
                    <div class="card-body p-3 p-md-4">
                        <h2 class="h6 fw-semibold mb-3"><?= e($heading) ?></h2>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="promo_<?= $box ?>_enabled" name="promo_<?= $box ?>_enabled" value="1" <?= $v("promo_{$box}_enabled") === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="promo_<?= $box ?>_enabled">Show this box on the home page</label>
                        </div>
                        <div class="row g-3">
                            <?php foreach (App\Models\Appearance::PROMO_FIELDS as $field => [$label, $max]): $key = "promo_{$box}_{$field}"; ?>
                                <div class="col-12 <?= in_array($field, ['title', 'text'], true) ? '' : 'col-md-6' ?>">
                                    <label for="<?= $key ?>" class="form-label small"><?= e($label) ?></label>
                                    <input type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($v($key)) ?>" maxlength="<?= $max ?>"
                                           class="form-control<?= $invalid($key) ?>" placeholder="<?= e($auto[$field]) ?>" autocomplete="off"
                                           <?= $field === 'link' ? 'autocapitalize="none" spellcheck="false"' : '' ?>>
                                    <?= $err($key) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text mt-2">Leave a field empty to use the automatic text shown in grey.</div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Top menu</h2>
                    <?= $colourField('header_line', 'Line under the menu (2 px)', $line) ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card mb-3 position-sticky" style="top: 1rem">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Preview</h2>
                    <div class="appearance-preview" aria-hidden="true">
                        <div class="appearance-preview__bar" data-preview-bar style="background: <?= e($bg) ?>; color: <?= e($fg) ?>" <?= $enabled ? '' : 'hidden' ?>>
                            <span data-preview-text><?= e($v('announce_text') !== '' ? $v('announce_text') : $autoText) ?></span>
                        </div>
                        <div class="appearance-preview__header" data-preview-header style="border-bottom-color: <?= e($line) ?>">
                            <i class="bi bi-list"></i>
                            <img src="<?= e(asset('images/brand/logo-wordmark.webp')) ?>" alt="" width="255" height="120">
                            <span><i class="bi bi-heart me-2"></i><i class="bi bi-bag"></i></span>
                        </div>
                        <div class="appearance-preview__page"></div>
                    </div>
                    <p class="small text-muted mt-2 mb-0" data-preview-hidden-note <?= $enabled ? 'hidden' : '' ?>>The announcement bar is hidden.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" name="reset" value="1" class="btn btn-outline-secondary">Reset colours</button>
        <button type="submit" class="btn btn-primary">Save appearance</button>
    </div>
</form>
