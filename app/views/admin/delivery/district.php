<?php
/**
 * Admin -> Delivery -> one district: its settings and thanas.
 *
 * @var array $district    with zone_name
 * @var array $thanas      id, name, bn_name, is_active, order_count
 * @var array $zones
 * @var int   $orderCount  orders delivered to this district
 */
$did = (int) $district['id'];
$active = count(array_filter($thanas, static fn ($t) => $t['is_active']));
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(url('admin/delivery')) ?>">Delivery</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($district['name']) ?></li>
    </ol>
</nav>

<div class="row g-3 g-lg-4">
    <div class="col-12 col-lg-4">
        <!-- District settings -->
        <form method="post" action="<?= e(url('admin/delivery/districts/' . $did)) ?>" class="card mb-3">
            <?= csrf_field() ?>
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-3">District</h2>
                <div class="mb-3">
                    <label for="district-name" class="form-label small">Name</label>
                    <input type="text" id="district-name" name="name" value="<?= e($district['name']) ?>" class="form-control" maxlength="80" required>
                    <?php if (!empty($district['bn_name'])): ?><div class="form-text"><?= e($district['bn_name']) ?></div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <label for="district-zone" class="form-label small">Delivery zone</label>
                    <select id="district-zone" name="zone_id" class="form-select">
                        <?php foreach ($zones as $zone): ?>
                            <option value="<?= (int) $zone['id'] ?>" <?= (int) $zone['id'] === (int) $district['zone_id'] ? 'selected' : '' ?>><?= e($zone['name']) ?> (<?= e(format_price($zone['charge'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="district-active" name="is_active" value="1" <?= $district['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="district-active">Show at checkout</label>
                </div>
                <button type="submit" class="btn btn-primary">Save district</button>
            </div>
        </form>

        <!-- Add thanas -->
        <form method="post" action="<?= e(url('admin/delivery/districts/' . $did . '/thanas')) ?>" class="card mb-3">
            <?= csrf_field() ?>
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-1">Add thanas</h2>
                <p class="small text-muted mb-2">One per line, or separated by commas.</p>
                <label for="thana-names" class="visually-hidden">Thana names</label>
                <textarea id="thana-names" name="names" rows="3" class="form-control mb-2" placeholder="e.g. Uttara Sector 10" required></textarea>
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add</button>
            </div>
        </form>

        <div class="card border-danger-subtle">
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold text-danger mb-1">Delete district</h2>
                <?php if ($orderCount > 0): ?>
                    <p class="small text-muted mb-0">Has <?= $orderCount ?> order<?= $orderCount === 1 ? '' : 's' ?>, so it can't be deleted. Switch off "Show at checkout" to hide it.</p>
                <?php else: ?>
                    <p class="small text-muted mb-2">Removes the district and all its thanas.</p>
                    <form method="post" action="<?= e(url('admin/delivery/districts/delete/' . $did)) ?>"
                          data-confirm="Delete &quot;<?= e($district['name']) ?>&quot; and its <?= count($thanas) ?> thanas?" data-confirm-button="Delete">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete district</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h2 class="h6 fw-semibold mb-0">Thanas <span class="text-muted fw-normal">(<?= $active ?> of <?= count($thanas) ?> shown)</span></h2>
                    <input type="search" class="form-control form-control-sm" style="max-width: 14rem" placeholder="Find a thana" aria-label="Find a thana" data-table-filter="#thana-table">
                </div>
                <?php if (!$thanas): ?>
                    <p class="text-muted mb-0">No thanas yet. Add them on the left - customers need at least one to order to this district.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="thana-table">
                            <thead><tr class="small text-muted"><th scope="col">Name</th><th scope="col">Checkout</th><th scope="col" class="text-end">Delete</th></tr></thead>
                            <tbody>
                                <?php foreach ($thanas as $t): $tid = (int) $t['id']; ?>
                                    <tr data-filter-text="<?= e(mb_strtolower($t['name'] . ' ' . ($t['bn_name'] ?? ''))) ?>" class="<?= $t['is_active'] ? '' : 'text-muted' ?>">
                                        <td>
                                            <form method="post" action="<?= e(url('admin/delivery/thanas/' . $tid)) ?>" class="d-flex gap-1">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="save">
                                                <input type="text" name="name" value="<?= e($t['name']) ?>" class="form-control form-control-sm" maxlength="80" required aria-label="Name of thana <?= e($t['name']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Rename"><i class="bi bi-check-lg" aria-hidden="true"></i><span class="visually-hidden">Rename</span></button>
                                            </form>
                                        </td>
                                        <td>
                                            <form method="post" action="<?= e(url('admin/delivery/thanas/' . $tid)) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <button type="submit" class="btn btn-sm <?= $t['is_active'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>">
                                                    <i class="bi <?= $t['is_active'] ? 'bi-eye' : 'bi-eye-slash' ?> me-1" aria-hidden="true"></i><?= $t['is_active'] ? 'Shown' : 'Hidden' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" action="<?= e(url('admin/delivery/thanas/' . $tid)) ?>"
                                                  data-confirm="Delete the thana &quot;<?= e($t['name']) ?>&quot;?<?= (int) $t['order_count'] > 0 ? ' Its ' . (int) $t['order_count'] . ' past orders keep the name.' : '' ?>" data-confirm-button="Delete">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete <?= e($t['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
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
    </div>
</div>
