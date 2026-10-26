<?php
/**
 * Add / edit a slide or the side banner.
 *
 * @var array|null $slide
 * @var string     $placement slider | side
 * @var array      $errors
 */
use App\Controllers\Admin\SlideController;
use App\Core\ImageUpload;

$isEdit = $slide !== null;
$type = SlideController::imageType($placement);
$spec = ImageUpload::spec($type);
$hasOld = old('_submitted') === '1';
$title = $hasOld ? old('title') : (string) ($slide['title'] ?? '');
$link = $hasOld ? old('link_url') : (string) ($slide['link_url'] ?? '');
$active = $hasOld ? old('is_active') === '1' : ($isEdit ? (bool) $slide['is_active'] : true);
$action = $isEdit ? url('admin/slides/edit/' . $slide['id']) : url('admin/slides/create');
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/slides')) ?>">Home slider</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? 'Edit' : 'Add' ?> <?= $placement === 'side' ? 'side banner' : 'slide' ?></li>
    </ol>
</nav>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= ImageUpload::maxBytes() ?>">
    <input type="hidden" name="placement" value="<?= e($placement) ?>">

    <div class="row g-3 g-lg-4">
        <div class="col-12 col-lg-7">
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Image</h2>
                    <div data-image-field>
                        <?php partial('image-spec', ['type' => $type, 'id' => 'image-spec']); ?>
                        <div class="image-preview mb-2" data-image-preview style="aspect-ratio: <?= (int) $spec['width'] ?> / <?= (int) $spec['height'] ?>; max-width: 100%">
                            <?php if ($isEdit): ?>
                                <img src="<?= e(image_url($slide['image_path'])) ?>" alt="Current image" data-current-image>
                            <?php else: ?>
                                <span class="image-preview__empty" data-image-empty><i class="bi bi-image" aria-hidden="true"></i><span>No image</span></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isEdit && ($meta = image_meta_line($slide['image_path'])) !== ''): ?>
                            <p class="image-meta" data-current-meta>Current: <?= e($meta) ?></p>
                        <?php endif; ?>
                        <div class="image-check" data-image-status aria-live="polite"></div>
                        <label for="image" class="form-label small fw-semibold mb-1"><?= $isEdit ? 'Replace image' : 'Upload image' ?><?= $isEdit ? '' : ' <span class="text-danger" aria-hidden="true">*</span>' ?></label>
                        <input type="file" id="image" name="image" class="form-control<?= $invalid('image') ?>"
                               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                               aria-describedby="image-spec<?= isset($errors['image']) ? ' image-error' : '' ?>"
                               data-image-input data-spec="<?= e(image_spec_json($type)) ?>" <?= $isEdit ? '' : 'required' ?>>
                        <?php if (isset($errors['image'])): ?><div id="image-error" class="invalid-feedback"><?= e($errors['image']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h6 fw-semibold mb-3">Details</h2>
                    <div class="mb-3">
                        <label for="title" class="form-label">Title <span class="text-muted fw-normal">(for screen readers and search engines)</span></label>
                        <input type="text" id="title" name="title" class="form-control<?= $invalid('title') ?>" value="<?= e($title) ?>" maxlength="120" placeholder="e.g. Eid collection - up to 30% off">
                        <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?= e($errors['title']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="link_url" class="form-label">Link <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" id="link_url" name="link_url" class="form-control<?= $invalid('link_url') ?>" value="<?= e($link) ?>" maxlength="255"
                               placeholder="/shop?sale=1" autocapitalize="none" spellcheck="false" aria-describedby="link-help">
                        <?php if (isset($errors['link_url'])): ?><div class="invalid-feedback"><?= e($errors['link_url']) ?></div><?php endif; ?>
                        <div id="link-help" class="form-text">Where a click goes: a page on this site (<code>/category/fashion</code>, <code>/product/baby-panjabi</code>, <code>/shop?sale=1</code>) or a full https:// address (opens in a new tab).</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Show on the homepage</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= e(url('admin/slides')) ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary" data-submit>
            <span data-submit-label><?= $isEdit ? 'Save changes' : ($placement === 'side' ? 'Add banner' : 'Add slide') ?></span>
            <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-submit-spinner></span>
        </button>
    </div>
</form>
