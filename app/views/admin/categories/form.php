<?php
/**
 * Create and edit form.
 *
 * @var array|null $category  null when creating
 * @var array      $errors    field => message
 */
use App\Controllers\Admin\CategoryController;
use App\Core\ImageUpload;
use App\Models\Category;

$isEdit = $category !== null;
$action = $isEdit ? url('admin/categories/edit/' . $category['id']) : url('admin/categories/create');

// After a failed submit show what was typed; otherwise the saved values
$hasOld = form_state('old') !== [];
$value = static fn (string $key, string $saved = ''): string => $hasOld ? old($key) : $saved;

$name = $value('name', $category['name'] ?? '');
$slug = $value('slug', $category['slug'] ?? '');
$description = $value('description', (string) ($category['description'] ?? ''));
$status = $value('status', $isEdit ? ($category['is_active'] ? 'active' : 'inactive') : 'active');
$removeImage = $hasOld && old('remove_image') === '1';

$maxBytes = ImageUpload::maxBytes();
$blocked = $isEdit ? CategoryController::deleteBlockedReason($category) : null;

$invalid = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
$describedBy = static fn (string $field, string $help = ''): string => trim(
    ($help !== '' ? $help : '') . (isset($errors[$field]) ? " {$field}-error" : '')
);
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/categories')) ?>">Categories</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($category['name']) : 'New category' ?></li>
    </ol>
</nav>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate data-category-form>
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxBytes ?>">

    <div class="row g-3 g-lg-4">
        <!-- Main details -->
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Details</h2>

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" id="name" name="name" class="form-control<?= $invalid('name') ?>"
                               value="<?= e($name) ?>" maxlength="<?= Category::NAME_MAX ?>" required
                               <?= $describedBy('name') !== '' ? 'aria-describedby="' . e($describedBy('name')) . '"' : '' ?>
                               <?= !$isEdit && !$errors ? 'autofocus' : '' ?> data-slug-source>
                        <?php if (isset($errors['name'])): ?>
                            <div id="name-error" class="invalid-feedback"><?= e($errors['name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label">Slug</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text small text-muted d-none d-sm-flex">/category/</span>
                            <input type="text" id="slug" name="slug" class="form-control<?= $invalid('slug') ?>"
                                   value="<?= e($slug) ?>" maxlength="<?= Category::SLUG_MAX ?>"
                                   autocapitalize="none" spellcheck="false" placeholder="generated-from-name"
                                   aria-describedby="<?= e($describedBy('slug', 'slug-help')) ?>" data-slug-target>
                            <?php if (isset($errors['slug'])): ?>
                                <div id="slug-error" class="invalid-feedback"><?= e($errors['slug']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div id="slug-help" class="form-text">
                            Used in the web address. Lowercase letters, numbers and hyphens.
                            Leave blank to generate it from the name.
                        </div>
                    </div>

                    <div class="mb-1">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="5"
                                  class="form-control<?= $invalid('description') ?>"
                                  maxlength="<?= Category::DESCRIPTION_MAX ?>"
                                  aria-describedby="<?= e($describedBy('description', 'description-count')) ?>"
                                  data-char-count="description-count"><?= e($description) ?></textarea>
                        <?php if (isset($errors['description'])): ?>
                            <div id="description-error" class="invalid-feedback"><?= e($errors['description']) ?></div>
                        <?php endif; ?>
                        <div id="description-count" class="form-text text-end" data-max="<?= Category::DESCRIPTION_MAX ?>">
                            <?= mb_strlen($description) ?> / <?= Category::DESCRIPTION_MAX ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar: status, image, info -->
        <div class="col-12 col-lg-4">
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3" id="status-label">Status</h2>
                    <div role="radiogroup" aria-labelledby="status-label">
                        <div class="form-check mb-2">
                            <input class="form-check-input<?= $invalid('status') ?>" type="radio" name="status" id="status-active"
                                   value="active" <?= $status === 'active' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status-active">
                                Active <span class="d-block small text-muted">Visible in the store</span>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input<?= $invalid('status') ?>" type="radio" name="status" id="status-inactive"
                                   value="inactive" <?= $status === 'inactive' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status-inactive">
                                Inactive <span class="d-block small text-muted">Hidden from the store</span>
                            </label>
                        </div>
                    </div>
                    <?php if (isset($errors['status'])): ?>
                        <div class="invalid-feedback d-block"><?= e($errors['status']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-1">Image</h2>
                    <!-- Standard: app/config/images.php, type "category" -->
                    <?php $catSpec = ImageUpload::spec('category'); ?>
                    <div data-image-field>
                        <?php partial('image-spec', ['type' => 'category', 'id' => 'image-spec']); ?>
                        <div class="image-preview image-preview--square mb-2" data-image-preview
                             style="aspect-ratio: <?= (int) $catSpec['width'] ?> / <?= (int) $catSpec['height'] ?>">
                            <?php if ($isEdit && $category['image_path']): ?>
                                <img src="<?= e(image_url($category['image_path'])) ?>" alt="Current image for <?= e($category['name']) ?>"
                                     data-current-image>
                            <?php else: ?>
                                <span class="image-preview__empty" data-image-empty>
                                    <i class="bi bi-image" aria-hidden="true"></i><span>No image</span>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isEdit && ($meta = image_meta_line($category['image_path'])) !== ''): ?>
                            <p class="image-meta" data-current-meta>Current: <?= e($meta) ?></p>
                        <?php endif; ?>
                        <div class="image-check" data-image-status aria-live="polite"></div>

                        <label for="image" class="form-label small fw-semibold mb-1"><?= $isEdit && $category['image_path'] ? 'Replace image' : 'Upload image' ?></label>
                        <input type="file" id="image" name="image" class="form-control<?= $invalid('image') ?>"
                               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                               aria-describedby="<?= e($describedBy('image', 'image-spec')) ?>"
                               data-image-input data-spec="<?= e(image_spec_json('category')) ?>">
                        <?php if (isset($errors['image'])): ?>
                            <div id="image-error" class="invalid-feedback"><?= e($errors['image']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($isEdit && $category['image_path']): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_image"
                                   <?= $removeImage ? 'checked' : '' ?> data-remove-image>
                            <label class="form-check-label small" for="remove_image">Delete current image</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isEdit): ?>
                <div class="card mb-3">
                    <div class="card-body p-3 p-md-4 small">
                        <dl class="row mb-0 meta-list">
                            <dt class="col-5 text-muted fw-normal">ID</dt>
                            <dd class="col-7 mb-1"><?= (int) $category['id'] ?></dd>
                            <dt class="col-5 text-muted fw-normal">Products</dt>
                            <dd class="col-7 mb-1"><?= (int) $category['product_count'] ?></dd>
                            <dt class="col-5 text-muted fw-normal">Created</dt>
                            <dd class="col-7 mb-1"><?= e(format_datetime($category['created_at'])) ?></dd>
                            <dt class="col-5 text-muted fw-normal">Updated</dt>
                            <dd class="col-7 mb-0"><?= e(format_datetime($category['updated_at'])) ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= e(url('admin/categories')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary" data-submit>
            <span data-submit-label><?= $isEdit ? 'Save changes' : 'Create category' ?></span>
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-submit-spinner></span>
        </button>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="card border-danger-subtle mt-4">
        <div class="card-body d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">
            <div>
                <h2 class="h6 fw-semibold text-danger mb-1">Delete category</h2>
                <p class="small text-muted mb-0">
                    <?= $blocked !== null ? e($blocked) : 'Permanently removes this category and its image. This cannot be undone.' ?>
                </p>
            </div>
            <form method="post" action="<?= e(url('admin/categories/delete/' . $category['id'])) ?>"
                  data-confirm="Delete the category &quot;<?= e($category['name']) ?>&quot;? This cannot be undone."
                  data-confirm-button="Delete">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-danger text-nowrap" <?= $blocked !== null ? 'disabled' : '' ?>>
                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>
