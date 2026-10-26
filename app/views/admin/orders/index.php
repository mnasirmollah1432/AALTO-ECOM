<?php
/**
 * @var array $filters    q, status, from, to
 * @var bool  $dateError  an invalid date was ignored
 * @var array $result     items, total, page, pages, per_page
 * @var array $counts     'all' + per status (for the current search/date filters)
 */
use App\Models\Order;

$items = $result['items'];
$query = array_filter($filters, static fn ($v) => $v !== '');
$isFiltered = $query !== [];
$from = $result['total'] ? ($result['page'] - 1) * $result['per_page'] + 1 : 0;
$to = min($result['total'], $result['page'] * $result['per_page']);
$tabUrl = static fn (string $status): string => url_with_query('admin/orders', ['status' => $status] + array_diff_key($query, ['status' => 1]));
?>
<?php if ($dateError): ?>
    <div class="alert alert-warning small" role="status">A date was not valid (use the date picker) and was ignored.</div>
<?php endif; ?>

<!-- Status tabs with counts -->
<nav class="status-tabs mb-3" aria-label="Filter by status">
    <a href="<?= e($tabUrl('')) ?>" class="status-tab<?= $filters['status'] === '' ? ' active' : '' ?>" <?= $filters['status'] === '' ? 'aria-current="page"' : '' ?>>
        All <span class="status-tab__count"><?= (int) $counts['all'] ?></span>
    </a>
    <?php foreach (Order::STATUSES as $key => [$label]): ?>
        <a href="<?= e($tabUrl($key)) ?>" class="status-tab status-tab--<?= e($key) ?><?= $filters['status'] === $key ? ' active' : '' ?>"
           <?= $filters['status'] === $key ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?> <span class="status-tab__count"><?= (int) $counts[$key] ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<form method="get" action="<?= e(url('admin/orders')) ?>" class="card filter-bar mb-3" role="search">
    <div class="card-body row g-2 align-items-end">
        <?php if ($filters['status'] !== ''): ?>
            <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
        <?php endif; ?>
        <div class="col-12 col-lg-5">
            <label for="q" class="form-label small text-muted mb-1">Search</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input type="search" id="q" name="q" class="form-control" value="<?= e($filters['q']) ?>"
                       placeholder="Order number, customer name or mobile" maxlength="100">
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label for="from" class="form-label small text-muted mb-1">From</label>
            <input type="date" id="from" name="from" class="form-control" value="<?= e($filters['from']) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label for="to" class="form-label small text-muted mb-1">To</label>
            <input type="date" id="to" name="to" class="form-control" value="<?= e($filters['to']) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-12 col-md-4 col-lg-3 d-flex gap-2">
            <button type="submit" class="btn btn-dark flex-grow-1">Filter</button>
            <?php if ($isFiltered): ?>
                <a href="<?= e(url('admin/orders')) ?>" class="btn btn-outline-secondary" aria-label="Clear filters" title="Clear filters">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2 small">
            <span class="text-muted">Quick dates:</span>
            <?php foreach (['Today' => [date('Y-m-d'), date('Y-m-d')], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
                            'This month' => [date('Y-m-01'), date('Y-m-d')]] as $label => [$f, $t]): ?>
                <a href="<?= e(url_with_query('admin/orders', ['from' => $f, 'to' => $t] + array_diff_key($query, ['from' => 1, 'to' => 1]))) ?>"
                   class="link-secondary"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<?php if (!$items): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi <?= $isFiltered ? 'bi-search' : 'bi-receipt' ?> display-6 text-muted" aria-hidden="true"></i>
            <?php if ($isFiltered): ?>
                <h2 class="h6 mt-3">No orders match these filters</h2>
                <a href="<?= e(url('admin/orders')) ?>" class="btn btn-outline-secondary btn-sm mt-2">Clear filters</a>
            <?php else: ?>
                <h2 class="h6 mt-3">No orders yet</h2>
                <p class="text-muted small mb-0">Orders placed in the store will appear here.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <?php $courierOn = App\Models\Courier::ready(); ?>
    <form method="post" action="<?= e(url('admin/orders/courier-bulk')) ?>" id="bulk-courier">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e(current_return_path()) ?>">
    </form>
    <?php if ($courierOn): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <button type="submit" form="bulk-courier" class="btn btn-sm btn-outline-primary" data-bulk-button
                    data-confirm-click="Send the ticked orders to Steadfast? The full order total is collected as COD (0 for paid orders).">
                <i class="bi bi-truck me-1" aria-hidden="true"></i>Send to Steadfast<span data-bulk-count></span>
            </button>
            <span class="small text-muted">Tick orders (up to <?= App\Models\Courier::BULK_MAX ?>) that are not sent yet.</span>
        </div>
    <?php endif; ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead>
                    <tr>
                        <?php if ($courierOn): ?><th scope="col" class="ps-3" style="width: 2rem"><input type="checkbox" class="form-check-input" aria-label="Select all orders on this page" data-select-all></th><?php endif; ?>
                        <th scope="col" class="<?= $courierOn ? '' : 'ps-3' ?>">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="d-none d-md-table-cell">Mobile</th>
                        <th scope="col" class="text-end">Total</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="d-none d-lg-table-cell">Date</th>
                        <th scope="col" class="text-end pe-3"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $o): $viewUrl = url('admin/orders/view/' . $o['id']); ?>
                        <tr>
                            <?php if ($courierOn): ?>
                                <td class="ps-3">
                                    <?php if (empty($o['courier']) && !in_array($o['status'], ['delivered', 'cancelled'], true)): ?>
                                        <input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int) $o['id'] ?>" form="bulk-courier" aria-label="Select <?= e($o['order_number']) ?>" data-select-row>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="<?= $courierOn ? '' : 'ps-3' ?>">
                                <a href="<?= e($viewUrl) ?>" class="fw-semibold font-monospace text-body text-decoration-none text-nowrap"><?= e($o['order_number']) ?></a>
                                <div class="small text-muted"><?= (int) $o['item_count'] ?> item<?= (int) $o['item_count'] === 1 ? '' : 's' ?></div>
                            </td>
                            <td class="cell-name">
                                <?= e($o['customer_name']) ?>
                                <div class="small text-muted d-md-none"><?= e($o['customer_phone']) ?></div>
                                <div class="small text-muted d-lg-none"><?= e(format_datetime($o['placed_at'] ?? $o['created_at'], 'j M Y')) ?></div>
                            </td>
                            <td class="d-none d-md-table-cell text-nowrap"><?= e($o['customer_phone']) ?></td>
                            <td class="text-end text-nowrap fw-semibold"><?= e(format_price($o['grand_total'])) ?></td>
                            <td><span class="badge rounded-pill <?= e(Order::statusBadge($o['status'])) ?>"><?= e(Order::statusLabel($o['status'])) ?></span>
                                <?php if (($o['courier'] ?? null) === 'steadfast'): ?>
                                    <div class="small mt-1" title="Steadfast consignment <?= e($o['courier_consignment_id']) ?>"><i class="bi bi-truck text-muted" aria-hidden="true"></i> <span class="badge <?= e(App\Models\Courier::statusBadge($o['courier_status'])) ?>"><?= e(App\Models\Courier::statusLabel($o['courier_status'])) ?></span></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell small text-muted text-nowrap"><?= e(format_datetime($o['placed_at'] ?? $o['created_at'])) ?></td>
                            <td class="text-end pe-3">
                                <a href="<?= e($viewUrl) ?>" class="btn btn-sm btn-light" aria-label="View order <?= e($o['order_number']) ?>">
                                    <i class="bi bi-eye" aria-hidden="true"></i><span class="d-none d-xl-inline ms-1">View</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
        <p class="small text-muted mb-0">Showing <?= $from ?>–<?= $to ?> of <?= (int) $result['total'] ?></p>
        <?= view('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'path' => 'admin/orders', 'params' => $query]) ?>
    </div>
<?php endif; ?>
