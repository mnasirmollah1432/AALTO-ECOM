<?php
/**
 * "Size & colour options" card of the product form (included by form.php).
 * The stock grid is rendered here and rebuilt live by admin.js
 * (modules.productOptions) when sizes/colours change.
 *
 * Field names (see Admin\ProductController::validateOptions):
 *   has_sizes, has_colours, sizes, colour_names[], colour_hexes[],
 *   variant_stock[bin2hex(size)|_][bin2hex(colour)|_]
 *
 * @var array|null $product
 * @var array      $variants  saved option rows
 * @var array      $errors
 * @var bool       $hasOld    re-showing a failed submit
 */
use App\Models\Product;

if ($hasOld) {
    $optSizesOn = old('has_sizes') === '1';
    $optColoursOn = old('has_colours') === '1';
    $typed = json_decode(old('options_json', '{}'), true) ?: [];
    $optSizesText = is_string($typed['sizes'] ?? null) ? $typed['sizes'] : '';
    $optColours = [];
    foreach ((array) ($typed['names'] ?? []) as $i => $name) {
        $hex = (string) ($typed['hexes'][$i] ?? '');
        $optColours[] = ['name' => (string) $name, 'hex' => preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtolower($hex) : null];
    }
    $optStock = is_array($typed['stock'] ?? null) ? $typed['stock'] : [];
} else {
    $optSizesOn = !empty($product['has_sizes']);
    $optColoursOn = !empty($product['has_colours']);
    $sizeList = $optColours = $optStock = [];
    foreach ($variants as $v) {
        if ($v['size'] !== '' && !in_array($v['size'], $sizeList, true)) {
            $sizeList[] = $v['size'];
        }
        if ($v['colour'] !== '' && !in_array($v['colour'], array_column($optColours, 'name'), true)) {
            $optColours[] = ['name' => $v['colour'], 'hex' => $v['colour_hex']];
        }
        $optStock[$v['size'] === '' ? '_' : bin2hex($v['size'])][$v['colour'] === '' ? '_' : bin2hex($v['colour'])] = (string) $v['stock_quantity'];
    }
    $optSizesText = implode(', ', $sizeList);
}
if (!$optColours) {
    $optColours[] = ['name' => '', 'hex' => null];
}

// Grid values: the sizes/colours currently entered
$gridSizes = [];
foreach (explode(',', $optSizesText) as $s) {
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    if ($s !== '' && !in_array(mb_strtolower($s), array_map('mb_strtolower', $gridSizes), true)) {
        $gridSizes[] = $s;
    }
}
$gridColours = array_values(array_filter(array_map(static fn ($c) => trim($c['name']), $optColours), 'strlen'));
$key = static fn (string $v): string => $v === '' ? '_' : bin2hex($v);
$cell = static function (string $size, string $colour, string $label) use ($optStock, $key): string {
    $k1 = $key($size);
    $k2 = $key($colour);
    $val = (string) ($optStock[$k1][$k2] ?? '0');
    return '<input type="number" class="form-control form-control-sm stock-grid__input" min="0" max="' . Product::STOCK_MAX . '" step="1" inputmode="numeric"'
        . ' name="variant_stock[' . $k1 . '][' . $k2 . ']" value="' . e($val) . '" aria-label="' . e('Stock for ' . $label) . '" data-stock-cell>';
};
$gridOn = ($optSizesOn && $gridSizes) || ($optColoursOn && $gridColours);
$total = 0;
foreach ($optStock as $row) {
    foreach ((array) $row as $v) {
        $total += (int) $v;
    }
}

$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$presets = [
    'Clothing'  => 'S, M, L, XL, XXL',
    'Panjabi'   => '38, 40, 42, 44, 46',
    'Kids'      => '0-6M, 6-12M, 1-2Y, 2-3Y, 3-4Y, 4-5Y',
    'Shoes'     => '38, 39, 40, 41, 42, 43, 44',
];
?>
<div class="card mb-3" data-product-options data-stock-max="<?= Product::STOCK_MAX ?>">
    <div class="card-body p-3 p-md-4">
        <h2 class="h6 fw-semibold mb-1">Size &amp; colour options</h2>
        <p class="small text-muted mb-3">Switch on to sell this product in sizes and/or colours with separate stock. Customers must choose one; sold-out options show a cross and can't be ordered.</p>

        <!-- Sizes -->
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="has_sizes" name="has_sizes" value="1" <?= $optSizesOn ? 'checked' : '' ?> data-option-toggle="sizes">
            <label class="form-check-label fw-semibold" for="has_sizes">Sizes</label>
        </div>
        <div class="option-panel mb-3" data-option-panel="sizes" <?= $optSizesOn ? '' : 'hidden' ?>>
            <label for="sizes" class="form-label small mb-1">Sizes, in the order customers see them</label>
            <input type="text" id="sizes" name="sizes" class="form-control<?= $invalid('sizes') ?>" value="<?= e($optSizesText) ?>"
                   maxlength="1000" placeholder="S, M, L, XL" autocomplete="off" data-sizes-input
                   aria-describedby="sizes-help<?= isset($errors['sizes']) ? ' sizes-error' : '' ?>">
            <?php if (isset($errors['sizes'])): ?><div id="sizes-error" class="invalid-feedback"><?= e($errors['sizes']) ?></div><?php endif; ?>
            <div id="sizes-help" class="form-text">Separate with commas. Up to <?= Product::OPTION_VALUES_MAX ?> sizes.</div>
            <div class="d-flex flex-wrap gap-1 mt-2">
                <span class="small text-muted me-1 align-self-center">Quick fill:</span>
                <?php foreach ($presets as $label => $list): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-size-preset="<?= e($list) ?>" title="<?= e($list) ?>"><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Colours -->
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="has_colours" name="has_colours" value="1" <?= $optColoursOn ? 'checked' : '' ?> data-option-toggle="colours">
            <label class="form-check-label fw-semibold" for="has_colours">Colours</label>
        </div>
        <div class="option-panel mb-3" data-option-panel="colours" <?= $optColoursOn ? '' : 'hidden' ?>>
            <p class="form-label small mb-1" id="colours-label">Colours: name and swatch</p>
            <div class="colour-list" data-colour-list role="group" aria-labelledby="colours-label">
                <?php foreach ($optColours as $i => $c): ?>
                    <div class="colour-row" data-colour-row>
                        <input type="color" name="colour_hexes[]" value="<?= e($c['hex'] ?? '#cccccc') ?>" class="form-control form-control-color" aria-label="Swatch colour" data-colour-hex>
                        <input type="text" name="colour_names[]" value="<?= e($c['name']) ?>" class="form-control form-control-sm<?= $invalid('colours') ?>" maxlength="<?= Product::OPTION_MAX ?>" placeholder="e.g. Maroon" aria-label="Colour name" autocomplete="off" data-colour-name>
                        <button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove colour" data-colour-remove><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (isset($errors['colours'])): ?><div class="invalid-feedback d-block"><?= e($errors['colours']) ?></div><?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2" data-colour-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add colour</button>
            <template data-colour-template>
                <div class="colour-row" data-colour-row>
                    <input type="color" name="colour_hexes[]" value="#cccccc" class="form-control form-control-color" aria-label="Swatch colour" data-colour-hex>
                    <input type="text" name="colour_names[]" value="" class="form-control form-control-sm" maxlength="<?= Product::OPTION_MAX ?>" placeholder="e.g. Maroon" aria-label="Colour name" autocomplete="off" data-colour-name>
                    <button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove colour" data-colour-remove><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
            </template>
        </div>

        <!-- Stock per option -->
        <div class="stock-grid-wrap" data-stock-grid-wrap <?= $gridOn ? '' : 'hidden' ?>>
            <h3 class="h6 fw-semibold mb-1">Stock per option</h3>
            <p class="small text-muted mb-2">0 = sold out (shown with a cross). Changing the sizes or colours updates this table.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle stock-grid mb-1" data-stock-grid>
                    <?php if ($optSizesOn && $gridSizes && $optColoursOn && $gridColours): ?>
                        <thead><tr><th scope="col">Size \ Colour</th><?php foreach ($gridColours as $c): ?><th scope="col"><?= e($c) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php foreach ($gridSizes as $s): ?>
                                <tr><th scope="row"><?= e($s) ?></th><?php foreach ($gridColours as $c): ?><td><?= $cell($s, $c, $s . ' / ' . $c) ?></td><?php endforeach; ?></tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php elseif ($gridOn): ?>
                        <?php $single = $optSizesOn && $gridSizes ? $gridSizes : $gridColours; ?>
                        <thead><tr><th scope="col"><?= $optSizesOn && $gridSizes ? 'Size' : 'Colour' ?></th><th scope="col">Stock</th></tr></thead>
                        <tbody>
                            <?php foreach ($single as $v): ?>
                                <tr><th scope="row"><?= e($v) ?></th><td><?= $optSizesOn && $gridSizes ? $cell($v, '', $v) : $cell('', $v, $v) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>
            <?php if (isset($errors['variant_stock'])): ?><div class="invalid-feedback d-block"><?= e($errors['variant_stock']) ?></div><?php endif; ?>
            <p class="small mb-0">Total stock: <strong data-stock-total><?= number_format($total) ?></strong></p>
        </div>
    </div>
</div>
