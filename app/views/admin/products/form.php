<?php
/**
 * Create and edit form.
 *
 * @var array|null $product     null when creating
 * @var array      $images      existing images, main first
 * @var array      $categories  id, name, is_active
 * @var array      $errors      field => message
 */
use App\Controllers\Admin\ProductController;
use App\Core\ImageUpload;
use App\Models\Product;

$isEdit = $product !== null;
$action = $isEdit ? url('admin/products/edit/' . $product['id']) : url('admin/products/create');

// After a failed submit show what was typed; otherwise the saved values
$hasOld = old('_submitted') === '1';
$value = static fn (string $key, string $saved = ''): string => $hasOld ? old($key) : $saved;
$checked = static fn (string $key, bool $saved): bool => $hasOld ? old($key) === '1' : $saved;

$name        = $value('name', $product['name'] ?? '');
$slug        = $value('slug', $product['slug'] ?? '');
$sku         = $value('sku', $product['sku'] ?? '');
$categoryId  = $value('category_id', (string) ($product['category_id'] ?? ''));
$short       = $value('short_description', (string) ($product['short_description'] ?? ''));
$description = $value('description', (string) ($product['description'] ?? ''));
$regular     = $value('regular_price', (string) ($product['regular_price'] ?? ''));
$sale        = $value('sale_price', (string) ($product['sale_price'] ?? ''));
$stock       = $value('stock_quantity', isset($product['stock_quantity']) ? (string) $product['stock_quantity'] : '0');
$isActive    = $checked('is_active', $isEdit ? (bool) $product['is_active'] : true);
$isFeatured  = $checked('is_featured', $isEdit && $product['is_featured']);
$isNew       = $checked('is_new', $isEdit ? (bool) $product['is_new'] : true);

$mainImage = $images && $images[0]['is_primary'] ? $images[0] : null;
$extraImages = $mainImage ? array_slice($images, 1) : $images;
$maxBytes = ImageUpload::maxBytes();
$blocked = $isEdit ? ProductController::deleteBlockedReason($product) : null;

$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$error = static function (string $f) use ($errors): string {
    return isset($errors[$f]) ? '<div id="' . $f . '-error" class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
};
$describedBy = static fn (string $f, string $help = ''): string => trim($help . (isset($errors[$f]) ? " {$f}-error" : ''));
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/products')) ?>">Products</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($product['name']) : 'New product' ?></li>
    </ol>
</nav>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate data-product-form>
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxBytes ?>">

    <div class="row g-3 g-lg-4">
        <div class="col-12 col-lg-8">
            <!-- Details -->
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Details</h2>

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" id="name" name="name" class="form-control<?= $invalid('name') ?>" value="<?= e($name) ?>"
                               maxlength="<?= Product::NAME_MAX ?>" required data-slug-source
                               <?= $describedBy('name') ? 'aria-describedby="' . e($describedBy('name')) . '"' : '' ?>
                               <?= !$isEdit && !$errors ? 'autofocus' : '' ?>>
                        <?= $error('name') ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label for="slug" class="form-label">Slug</label>
                            <div class="input-group has-validation">
                                <span class="input-group-text small text-muted d-none d-sm-flex">/product/</span>
                                <input type="text" id="slug" name="slug" class="form-control<?= $invalid('slug') ?>" value="<?= e($slug) ?>"
                                       maxlength="<?= Product::SLUG_MAX ?>" autocapitalize="none" spellcheck="false"
                                       placeholder="generated-from-name" data-slug-target
                                       aria-describedby="<?= e($describedBy('slug', 'slug-help')) ?>">
                                <?= $error('slug') ?>
                            </div>
                            <div id="slug-help" class="form-text">Leave blank to generate it from the name.</div>
                        </div>
                        <div class="col-12 col-md-5">
                            <label for="sku" class="form-label">SKU</label>
                            <input type="text" id="sku" name="sku" class="form-control font-monospace<?= $invalid('sku') ?>" value="<?= e($sku) ?>"
                                   maxlength="<?= Product::SKU_MAX ?>" autocapitalize="characters" spellcheck="false"
                                   placeholder="<?= $isEdit ? '' : 'Auto-generated' ?>"
                                   aria-describedby="<?= e($describedBy('sku', 'sku-help')) ?>">
                            <?= $error('sku') ?>
                            <div id="sku-help" class="form-text"><?= $isEdit ? 'Leave blank to keep the current SKU.' : 'Leave blank to generate one.' ?></div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label for="category_id" class="form-label">Category <span class="text-danger" aria-hidden="true">*</span></label>
                        <select id="category_id" name="category_id" class="form-select<?= $invalid('category_id') ?>" required
                                <?= $describedBy('category_id') ? 'aria-describedby="' . e($describedBy('category_id')) . '"' : '' ?>>
                            <option value="">Choose a category…</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (string) $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?><?= $cat['is_active'] ? '' : ' (inactive – hidden in store)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $error('category_id') ?>
                    </div>
                </div>
            </div>

            <!-- Descriptions -->
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Description</h2>
                    <div class="mb-3">
                        <label for="short_description" class="form-label">Short description</label>
                        <textarea id="short_description" name="short_description" rows="2"
                                  class="form-control<?= $invalid('short_description') ?>" maxlength="<?= Product::SHORT_MAX ?>"
                                  data-char-count="short-count"
                                  aria-describedby="<?= e($describedBy('short_description', 'short-count')) ?>"><?= e($short) ?></textarea>
                        <?= $error('short_description') ?>
                        <div id="short-count" class="form-text text-end" data-max="<?= Product::SHORT_MAX ?>"><?= mb_strlen($short) ?> / <?= Product::SHORT_MAX ?></div>
                    </div>
                    <div>
                        <label for="description" class="form-label">Full description</label>
                        <textarea id="description" name="description" rows="8"
                                  class="form-control<?= $invalid('description') ?>" maxlength="<?= Product::DESCRIPTION_MAX ?>"
                                  data-char-count="description-count"
                                  aria-describedby="<?= e($describedBy('description', 'description-count')) ?>"><?= e($description) ?></textarea>
                        <?= $error('description') ?>
                        <div id="description-count" class="form-text text-end" data-max="<?= Product::DESCRIPTION_MAX ?>"><?= mb_strlen($description) ?> / <?= number_format(Product::DESCRIPTION_MAX) ?></div>
                    </div>
                </div>
            </div>

            <!-- Pricing & stock -->
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Pricing &amp; stock</h2>
                    <div class="row g-3">
                        <div class="col-12 col-sm-6 col-md-4">
                            <label for="regular_price" class="form-label">Regular price <span class="text-danger" aria-hidden="true">*</span></label>
                            <div class="input-group has-validation">
                                <span class="input-group-text"><?= e(config('app.store.currency_symbol')) ?></span>
                                <input type="text" id="regular_price" name="regular_price" inputmode="decimal"
                                       class="form-control<?= $invalid('regular_price') ?>" value="<?= e($regular) ?>"
                                       placeholder="0.00" required autocomplete="off" data-price="regular"
                                       <?= $describedBy('regular_price') ? 'aria-describedby="' . e($describedBy('regular_price')) . '"' : '' ?>>
                                <?= $error('regular_price') ?>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-4">
                            <label for="sale_price" class="form-label">Sale price</label>
                            <div class="input-group has-validation">
                                <span class="input-group-text"><?= e(config('app.store.currency_symbol')) ?></span>
                                <input type="text" id="sale_price" name="sale_price" inputmode="decimal"
                                       class="form-control<?= $invalid('sale_price') ?>" value="<?= e($sale) ?>"
                                       placeholder="Optional" autocomplete="off" data-price="sale"
                                       aria-describedby="<?= e($describedBy('sale_price', 'sale-help')) ?>">
                                <?= $error('sale_price') ?>
                            </div>
                            <div id="sale-help" class="form-text" data-sale-preview>Empty or 0 = regular price is used.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="stock_quantity" class="form-label">Stock quantity <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="number" id="stock_quantity" name="stock_quantity" min="0" max="<?= Product::STOCK_MAX ?>" step="1"
                                   inputmode="numeric" class="form-control<?= $invalid('stock_quantity') ?>" value="<?= e($stock) ?>" required data-stock-single
                                   aria-describedby="<?= e($describedBy('stock_quantity', 'stock-help')) ?>">
                            <?= $error('stock_quantity') ?>
                            <div id="stock-help" class="form-text" data-stock-help>Whole number, 0 or more. 0 = out of stock.</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php require __DIR__ . "/options.php"; ?>
        </div>

        <div class="col-12 col-lg-4">
            <!-- Visibility -->
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Visibility</h2>
                    <?php foreach ([
                        ['is_active', $isActive, 'Active', 'Visible and purchasable in the store'],
                        ['is_featured', $isFeatured, 'Featured', 'Shown in "Featured products" on the homepage'],
                        ['is_new', $isNew, 'New product', 'Shows a "New" badge and appears first in new arrivals'],
                    ] as [$field, $on, $label, $help]): ?>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="<?= $field ?>" name="<?= $field ?>" value="1" <?= $on ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= $field ?>">
                                <?= e($label) ?><span class="d-block small text-muted"><?= e($help) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Images: standards in app/config/images.php (types "product" and "gallery") -->
            <?php
            $accept = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';
            $productSpec = ImageUpload::spec('product');
            $frame = 'aspect-ratio: ' . (int) $productSpec['width'] . ' / ' . (int) $productSpec['height'];
            ?>
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Images</h2>

                    <div data-image-field>
                        <label for="main_image" class="form-label small fw-semibold mb-1">Main image</label>
                        <?php partial('image-spec', ['type' => 'product', 'id' => 'main-spec']); ?>
                        <div class="image-preview mb-2" data-image-preview style="<?= e($frame) ?>">
                            <?php if ($mainImage): ?>
                                <img src="<?= e(image_url($mainImage['image_path'])) ?>" alt="Current main image" loading="lazy">
                            <?php else: ?>
                                <span class="image-preview__empty"><i class="bi bi-image" aria-hidden="true"></i><span>No main image</span></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($mainImage && ($meta = image_meta_line($mainImage['image_path'])) !== ''): ?>
                            <p class="image-meta" data-current-meta>Current: <?= e($meta) ?></p>
                        <?php endif; ?>
                        <div class="image-check" data-image-status aria-live="polite"></div>
                        <input type="file" id="main_image" name="main_image" class="form-control form-control-sm<?= $invalid('main_image') ?>"
                               accept="<?= e($accept) ?>" data-image-input data-spec="<?= e(image_spec_json('product')) ?>"
                               aria-describedby="<?= e($describedBy('main_image', 'main-spec main-help')) ?>">
                        <?= $error('main_image') ?>
                        <div id="main-help" class="form-text"><?= $mainImage ? 'Choose a file to replace the current main image.' : 'Shown on product cards and first in the gallery.' ?></div>

                        <?php if ($mainImage): ?>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="remove_images[]" value="<?= (int) $mainImage['id'] ?>" id="remove-main" data-remove-image>
                                <label class="form-check-label small" for="remove-main">Delete main image</label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <hr class="my-3">

                    <p class="form-label small fw-semibold mb-1" id="extra-label">
                        Gallery images <span class="text-muted fw-normal">(<?= count($extraImages) ?>/<?= Product::MAX_EXTRA_IMAGES ?>)</span>
                    </p>
                    <?php partial('image-spec', ['type' => 'gallery', 'id' => 'gallery-spec']); ?>
                    <?php if ($extraImages): ?>
                        <div class="gallery-grid mb-2" role="group" aria-labelledby="extra-label">
                            <?php foreach ($extraImages as $img): $imgId = (int) $img['id']; $meta = image_meta_line($img['image_path']); ?>
                                <div class="gallery-item" data-gallery-item>
                                    <img src="<?= e(image_thumb_url($img['image_path'])) ?>" alt="Gallery image <?= $imgId ?>" loading="lazy" decoding="async"
                                         style="<?= e($frame) ?>" data-gallery-img>
                                    <?php if ($meta !== ''): ?>
                                        <div class="gallery-item__meta" title="<?= e($meta) ?>"><?= e(preg_replace('/ · \d+:\d+ · /', ' · ', $meta)) ?></div>
                                    <?php endif; ?>
                                    <div class="gallery-item__actions">
                                        <label class="gallery-item__action" title="Use as main image">
                                            <input type="radio" name="primary_image" value="<?= $imgId ?>" class="form-check-input">
                                            <span>Main</span>
                                        </label>
                                        <label class="gallery-item__action gallery-item__replace" title="Replace this image">
                                            <input type="file" name="replace_images[<?= $imgId ?>]" class="visually-hidden" accept="<?= e($accept) ?>"
                                                   data-replace-input data-spec="<?= e(image_spec_json('gallery')) ?>" aria-describedby="gallery-spec">
                                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i><span>Replace</span>
                                        </label>
                                        <label class="gallery-item__action text-danger" title="Delete this image">
                                            <input type="checkbox" name="remove_images[]" value="<?= $imgId ?>" class="form-check-input" data-remove-gallery>
                                            <span>Delete</span>
                                        </label>
                                    </div>
                                    <div class="image-check image-check--compact" data-image-status aria-live="polite"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (count($extraImages) < Product::MAX_EXTRA_IMAGES): ?>
                        <label for="images" class="visually-hidden">Add gallery images</label>
                        <input type="file" id="images" name="images[]" multiple
                               class="form-control form-control-sm<?= $invalid('images') ?>"
                               accept="<?= e($accept) ?>" data-multi-image-input data-spec="<?= e(image_spec_json('gallery')) ?>"
                               data-max-files="<?= Product::MAX_EXTRA_IMAGES - count($extraImages) ?>"
                               aria-describedby="<?= e($describedBy('images', 'gallery-spec images-help')) ?>">
                        <?= $error('images') ?>
                        <div id="images-help" class="form-text">You can add <?= Product::MAX_EXTRA_IMAGES - count($extraImages) ?> more. Select several at once, or add them one by one.</div>
                        <div class="gallery-grid gallery-grid--pending mt-2" data-multi-preview></div>
                    <?php else: ?>
                        <?php if (isset($errors['images'])): ?>
                            <div class="invalid-feedback d-block mb-1"><?= e($errors['images']) ?></div>
                        <?php endif; ?>
                        <p class="small text-muted mb-0">Image limit reached. Delete or replace one to change the gallery.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isEdit): ?>
                <div class="card mb-3">
                    <div class="card-body p-3 p-md-4 small">
                        <dl class="row mb-0 meta-list">
                            <dt class="col-5 text-muted fw-normal">ID</dt>
                            <dd class="col-7 mb-1"><?= (int) $product['id'] ?></dd>
                            <dt class="col-5 text-muted fw-normal">Selling price</dt>
                            <dd class="col-7 mb-1"><?= e(format_price($product['price'])) ?></dd>
                            <dt class="col-5 text-muted fw-normal">Order lines</dt>
                            <dd class="col-7 mb-1"><?= (int) $product['order_count'] ?></dd>
                            <dt class="col-5 text-muted fw-normal">Created</dt>
                            <dd class="col-7 mb-1"><?= e(format_datetime($product['created_at'])) ?></dd>
                            <dt class="col-5 text-muted fw-normal">Updated</dt>
                            <dd class="col-7 mb-0"><?= e(format_datetime($product['updated_at'])) ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= e(url('admin/products')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary" data-submit>
            <span data-submit-label><?= $isEdit ? 'Save changes' : 'Create product' ?></span>
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-submit-spinner></span>
        </button>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="card border-danger-subtle mt-4">
        <div class="card-body d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">
            <div>
                <h2 class="h6 fw-semibold text-danger mb-1">Delete product</h2>
                <p class="small text-muted mb-0">
                    <?= $blocked !== null ? e($blocked) : 'Permanently removes this product and all its images. This cannot be undone.' ?>
                </p>
            </div>
            <form method="post" action="<?= e(url('admin/products/delete/' . $product['id'])) ?>"
                  data-confirm="Delete the product &quot;<?= e($product['name']) ?>&quot; and its images? This cannot be undone."
                  data-confirm-button="Delete">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-danger text-nowrap" <?= $blocked !== null ? 'disabled' : '' ?>>
                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>
