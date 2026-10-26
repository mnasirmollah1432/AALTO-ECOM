<?php
/**
 * Image standard shown beside an admin upload field, before a file is chosen.
 * Values come from app/config/images.php (never hard-code them in a view).
 *
 * @var string $type  image slot, e.g. 'product'
 * @var string $id    element id (referenced by the input's aria-describedby)
 */
use App\Core\ImageUpload;

$spec = ImageUpload::spec($type);
?>
<div class="image-spec" id="<?= e($id) ?>">
    <div class="image-spec__main">
        <span><strong>Recommended:</strong> <?= (int) $spec['width'] ?> × <?= (int) $spec['height'] ?> px</span>
        <span><strong>Ratio:</strong> <?= e($spec['ratio_label']) ?></span>
        <span><strong><?= e($spec['formats']) ?></strong> · Target: ≤ <?= (int) $spec['target_kb'] ?> KB</span>
    </div>
    <div class="image-spec__more">
        Upload JPG, PNG or WebP up to <?= e(ImageUpload::formatBytes($spec['max_bytes'])) ?>,
        at least <?= (int) $spec['min_width'] ?> × <?= (int) $spec['min_height'] ?> px.
        <?php if ($spec['fit'] === 'cover'): ?>
            Other ratios are centre-cropped to <?= e($spec['ratio_label']) ?> (never stretched);
        <?php endif; ?>
        images are resized, converted to WebP and compressed automatically.
    </div>
</div>
