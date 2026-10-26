<?php
/**
 * Admin -> Delivery: zone charges, free delivery, districts.
 *
 * @var array  $zones      id, name, charge, district_count
 * @var array  $districts  id, name, bn_name, zone_id, zone_name, is_active, thana_count, active_thanas
 * @var string $freeFrom   DECIMAL, "0.00" = never free
 */
use App\Core\Money;

$symbol = (string) config('app.store.currency_symbol');
$activeDistricts = count(array_filter($districts, static fn ($d) => $d['is_active']));
?>
<p class="text-muted mb-3">Checkout charges delivery by the customer's district: each district belongs to a zone, and each zone has one charge.</p>

<div class="row g-3 g-lg-4">
    <div class="col-12 col-xl-7">
        <!-- Zones -->
        <form method="post" action="<?= e(url('admin/delivery/zones')) ?>" class="card mb-3">
            <?= csrf_field() ?>
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-1">Delivery charges</h2>
                <p class="small text-muted mb-3">Change a charge and save. It applies to new orders straight away.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-2">
                        <thead>
                            <tr class="small text-muted"><th scope="col">Zone</th><th scope="col" style="width: 11rem">Charge</th><th scope="col" class="text-center">Districts</th><th scope="col"></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($zones as $zone): $zid = (int) $zone['id']; ?>
                                <tr>
                                    <td><input type="text" name="zone_name[<?= $zid ?>]" value="<?= e($zone['name']) ?>" class="form-control form-control-sm zone-name" maxlength="80" required aria-label="Zone name"></td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text"><?= e($symbol) ?></span>
                                            <input type="text" name="zone_charge[<?= $zid ?>]" value="<?= e($zone['charge']) ?>" class="form-control" inputmode="decimal" required aria-label="Charge for <?= e($zone['name']) ?>">
                                        </div>
                                    </td>
                                    <td class="text-center"><?= (int) $zone['district_count'] ?></td>
                                    <td class="text-end">
                                        <?php if ((int) $zone['district_count'] === 0): ?>
                                            <button type="submit" form="delete-zone-<?= $zid ?>" class="btn btn-sm btn-outline-danger" aria-label="Delete zone <?= e($zone['name']) ?>" title="Delete zone"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light">
                                <td><input type="text" name="new_zone_name" class="form-control form-control-sm zone-name" maxlength="80" placeholder="Add a zone (optional)" aria-label="New zone name"></td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><?= e($symbol) ?></span>
                                        <input type="text" name="new_zone_charge" class="form-control" inputmode="decimal" placeholder="e.g. 100" aria-label="New zone charge">
                                    </div>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary">Save charges</button>
            </div>
        </form>
        <?php foreach ($zones as $zone): if ((int) $zone['district_count'] === 0): ?>
            <form method="post" action="<?= e(url('admin/delivery/zones/delete/' . $zone['id'])) ?>" id="delete-zone-<?= (int) $zone['id'] ?>"
                  data-confirm="Delete the zone &quot;<?= e($zone['name']) ?>&quot;?" data-confirm-button="Delete">
                <?= csrf_field() ?>
            </form>
        <?php endif; endforeach; ?>
    </div>

    <div class="col-12 col-xl-5">
        <!-- Free delivery -->
        <form method="post" action="<?= e(url('admin/delivery/settings')) ?>" class="card mb-3">
            <?= csrf_field() ?>
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-1">Free delivery</h2>
                <p class="small text-muted mb-3">Orders of this amount or more pay no delivery charge. 0 = off (every order pays its zone charge).</p>
                <label for="free_delivery_from" class="form-label small">Free delivery from</label>
                <div class="input-group mb-2">
                    <span class="input-group-text"><?= e($symbol) ?></span>
                    <input type="text" id="free_delivery_from" name="free_delivery_from" value="<?= e($freeFrom) ?>" class="form-control" inputmode="decimal">
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
                <p class="small mb-0 <?= Money::toCents($freeFrom) > 0 ? 'text-success' : 'text-muted' ?>">
                    <?= Money::toCents($freeFrom) > 0 ? 'On: free from ' . e(format_price($freeFrom)) . '.' : 'Off.' ?>
                </p>
            </div>
        </form>

        <!-- Add district -->
        <form method="post" action="<?= e(url('admin/delivery/districts/create')) ?>" class="card mb-3">
            <?= csrf_field() ?>
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-semibold mb-3">Add a district</h2>
                <div class="row g-2">
                    <div class="col-12 col-sm-6">
                        <label for="new-district" class="form-label small">Name</label>
                        <input type="text" id="new-district" name="name" class="form-control" maxlength="80" required>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="new-district-zone" class="form-label small">Zone</label>
                        <select id="new-district-zone" name="zone_id" class="form-select" required>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?= (int) $zone['id'] ?>" <?= $zone === end($zones) ? 'selected' : '' ?>><?= e($zone['name']) ?> (<?= e(format_price($zone['charge'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-primary mt-3"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add district</button>
            </div>
        </form>
    </div>
</div>

<!-- Districts -->
<div class="card">
    <div class="card-body p-3 p-md-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="h6 fw-semibold mb-0">Districts <span class="text-muted fw-normal">(<?= $activeDistricts ?> of <?= count($districts) ?> shown at checkout)</span></h2>
            <input type="search" class="form-control form-control-sm" style="max-width: 16rem" placeholder="Find a district" aria-label="Find a district" data-table-filter="#district-table">
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="district-table">
                <thead>
                    <tr class="small text-muted"><th scope="col">District</th><th scope="col">Zone</th><th scope="col" class="text-center">Thanas</th><th scope="col">Checkout</th><th scope="col" class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($districts as $d): $did = (int) $d['id']; ?>
                        <tr data-filter-text="<?= e(mb_strtolower($d['name'] . ' ' . ($d['bn_name'] ?? ''))) ?>">
                            <th scope="row" class="fw-semibold">
                                <a href="<?= e(url('admin/delivery/districts/' . $did)) ?>" class="text-body text-decoration-none"><?= e($d['name']) ?></a>
                                <?php if (!empty($d['bn_name'])): ?><span class="small text-muted fw-normal ms-1"><?= e($d['bn_name']) ?></span><?php endif; ?>
                            </th>
                            <td>
                                <form method="post" action="<?= e(url('admin/delivery/districts/' . $did)) ?>" class="d-flex gap-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return" value="list">
                                    <select name="zone_id" class="form-select form-select-sm" aria-label="Zone of <?= e($d['name']) ?>" data-autosubmit>
                                        <?php foreach ($zones as $zone): ?>
                                            <option value="<?= (int) $zone['id'] ?>" <?= (int) $zone['id'] === (int) $d['zone_id'] ? 'selected' : '' ?>><?= e($zone['name']) ?> (<?= e(format_price($zone['charge'])) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary js-hide">Save</button>
                                </form>
                            </td>
                            <td class="text-center"><?= (int) $d['active_thanas'] ?><?= (int) $d['active_thanas'] !== (int) $d['thana_count'] ? '<span class="text-muted">/' . (int) $d['thana_count'] . '</span>' : '' ?></td>
                            <td>
                                <form method="post" action="<?= e(url('admin/delivery/districts/' . $did)) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return" value="list">
                                    <input type="hidden" name="is_active" value="<?= $d['is_active'] ? '0' : '1' ?>">
                                    <button type="submit" class="btn btn-sm <?= $d['is_active'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>" title="<?= $d['is_active'] ? 'Click to hide at checkout' : 'Click to show at checkout' ?>">
                                        <i class="bi <?= $d['is_active'] ? 'bi-eye' : 'bi-eye-slash' ?> me-1" aria-hidden="true"></i><?= $d['is_active'] ? 'Shown' : 'Hidden' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end"><a href="<?= e(url('admin/delivery/districts/' . $did)) ?>" class="btn btn-sm btn-outline-primary">Thanas</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
