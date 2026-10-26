<?php
/**
 * Admin -> Home slider.
 *
 * @var array $slides all rows (slider first, then side banners)
 */
use App\Core\ImageUpload;

$groups = ['slider' => [], 'side' => []];
foreach ($slides as $s) {
    $groups[$s['placement']][] = $s;
}
$slideSpec = ImageUpload::spec('slide');
$sideSpec = ImageUpload::spec('side_banner');
?>
<p class="text-muted mb-3">
    The slider rotates at the top of the homepage every 5 seconds (swipe on phones). The side banner shows beside it on large screens.
    With no active slides, the homepage shows its standard welcome section.
</p>

<?php foreach (['slider' => ['Slider', 'Add slide', $slideSpec], 'side' => ['Side banner', 'Add side banner', $sideSpec]] as $placement => [$heading, $addLabel, $spec]):
    $rows = $groups[$placement];
    $active = count(array_filter($rows, static fn ($r) => $r['is_active']));
?>
    <div class="card mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h6 fw-semibold mb-0"><?= e($heading) ?> <span class="text-muted fw-normal">(<?= $active ?> shown)</span></h2>
                    <p class="small text-muted mb-0">Image: <?= (int) $spec['width'] ?> × <?= (int) $spec['height'] ?> px (<?= e($spec['ratio']) ?>)<?= $placement === 'side' ? '. Only the first shown banner is used.' : '' ?></p>
                </div>
                <a href="<?= e(url('admin/slides/create' . ($placement === 'side' ? '?placement=side' : ''))) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i><?= e($addLabel) ?>
                </a>
            </div>
            <?php if (!$rows): ?>
                <p class="text-muted small mb-0">None yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr class="small text-muted"><th scope="col">Image</th><th scope="col">Title / link</th><th scope="col">Homepage</th><th scope="col" class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($rows as $i => $s): $sid = (int) $s['id']; ?>
                                <tr class="<?= $s['is_active'] ? '' : 'text-muted' ?>">
                                    <td style="width: 190px">
                                        <img src="<?= e(image_thumb_url($s['image_path'])) ?>" alt="" class="slide-thumb" width="170" height="<?= $placement === 'side' ? 136 : 68 ?>" loading="lazy">
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= e($s['title'] ?: 'Untitled') ?></div>
                                        <div class="small text-muted text-break"><?= $s['link_url'] ? e($s['link_url']) : 'No link' ?></div>
                                    </td>
                                    <td>
                                        <form method="post" action="<?= e(url('admin/slides/toggle/' . $sid)) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>">
                                                <i class="bi <?= $s['is_active'] ? 'bi-eye' : 'bi-eye-slash' ?> me-1" aria-hidden="true"></i><?= $s['is_active'] ? 'Shown' : 'Hidden' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php foreach (['up' => ['bi-arrow-up', 'Move up', $i === 0], 'down' => ['bi-arrow-down', 'Move down', $i === count($rows) - 1]] as $dir => [$ico, $label, $disabled]): ?>
                                            <form method="post" action="<?= e(url('admin/slides/move/' . $sid)) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="direction" value="<?= $dir ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="<?= $label ?>" title="<?= $label ?>" <?= $disabled ? 'disabled' : '' ?>><i class="bi <?= $ico ?>" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endforeach; ?>
                                        <a href="<?= e(url('admin/slides/edit/' . $sid)) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form method="post" action="<?= e(url('admin/slides/delete/' . $sid)) ?>" class="d-inline"
                                              data-confirm="Delete this <?= $placement === 'side' ? 'banner' : 'slide' ?>? The image is removed too." data-confirm-button="Delete">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
