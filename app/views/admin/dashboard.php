<?php
/**
 * @var array $admin
 * @var array $orders   Order::dashboardStats(): total, counts, sales, open_value, recent
 * @var array $catalog  products, products_active, products_out_of_stock, categories, categories_active
 * @var bool  $samplePassword  the admin still has the published seed.sql password
 */
use App\Models\Order;

$counts = $orders['counts'];
$statusIcons = [
    'pending' => 'bi-hourglass-split', 'confirmed' => 'bi-check2-circle', 'processing' => 'bi-box-seam',
    'shipped' => 'bi-truck', 'delivered' => 'bi-house-check', 'cancelled' => 'bi-x-circle',
];
?>
<?php if (!empty($samplePassword)): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <div>
            <strong>You are using the sample admin password from <code>seed.sql</code>.</strong>
            Anyone who has read the project README can sign in. Change it now with
            <code>php tools/hash-password.php --email=<?= e($admin['email']) ?></code>
            (see README &rarr; Admin password).
        </div>
    </div>
<?php endif; ?>
<div class="mb-4">
    <h2 class="h5 fw-semibold mb-1">Hello, <?= e($admin['name']) ?></h2>
    <p class="text-muted small mb-0">
        <?php if (!empty($admin['previous_login'])): ?>
            Last signed in <?= e(date('j M Y, g:i A', strtotime((string) $admin['previous_login']))) ?>.
        <?php else: ?>
            This is your first sign-in.
        <?php endif; ?>
        <?php if ($counts['pending'] > 0): ?>
            <a href="<?= e(url_with_query('admin/orders', ['status' => 'pending'])) ?>" class="fw-semibold">
                <?= $counts['pending'] ?> order<?= $counts['pending'] === 1 ? '' : 's' ?> waiting for confirmation &rarr;
            </a>
        <?php endif; ?>
    </p>
</div>

<!-- Headline figures -->
<div class="row g-3 g-lg-4 mb-3">
    <div class="col-12 col-md-4">
        <div class="card stat-card stat-card--teal h-100" data-stat="sales">
            <div class="card-body d-flex align-items-start gap-3">
                <span class="stat-card__icon" aria-hidden="true"><i class="bi bi-cash-stack"></i></span>
                <div>
                    <p class="stat-card__label mb-1">Total sales</p>
                    <p class="stat-card__value mb-1"><?= e(format_price($orders['sales'])) ?></p>
                    <p class="stat-card__meta mb-0">From <?= $counts['delivered'] ?> delivered order<?= $counts['delivered'] === 1 ? '' : 's' ?> (cash collected)</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <a href="<?= e(url('admin/orders')) ?>" class="card stat-card stat-card--indigo stat-card--link h-100" data-stat="orders">
            <div class="card-body d-flex align-items-start gap-3">
                <span class="stat-card__icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
                <div>
                    <p class="stat-card__label mb-1">Total orders</p>
                    <p class="stat-card__value mb-1"><?= number_format($orders['total']) ?></p>
                    <p class="stat-card__meta mb-0">All time</p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card stat-card stat-card--amber h-100" data-stat="open">
            <div class="card-body d-flex align-items-start gap-3">
                <span class="stat-card__icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                <div>
                    <p class="stat-card__label mb-1">Open order value</p>
                    <p class="stat-card__value mb-1"><?= e(format_price($orders['open_value'])) ?></p>
                    <p class="stat-card__meta mb-0">Not yet delivered or cancelled</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Orders by status -->
<h2 class="h6 fw-semibold text-muted text-uppercase small mb-2">Orders by status</h2>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-2 g-lg-3 mb-4">
    <?php foreach (Order::STATUSES as $key => [$label, $badge]): ?>
        <div class="col">
            <a href="<?= e(url_with_query('admin/orders', ['status' => $key])) ?>" class="status-stat status-stat--<?= e($key) ?>" data-status-count="<?= e($key) ?>">
                <i class="bi <?= e($statusIcons[$key]) ?>" aria-hidden="true"></i>
                <span class="status-stat__value"><?= number_format($counts[$key]) ?></span>
                <span class="status-stat__label"><?= e($label) ?></span>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 g-lg-4">
    <!-- Recent orders -->
    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-body p-0">
                <div class="d-flex justify-content-between align-items-center px-3 pt-3 mb-2">
                    <h2 class="h6 fw-semibold mb-0">Recent orders</h2>
                    <a href="<?= e(url('admin/orders')) ?>" class="small">View all</a>
                </div>
                <?php if ($orders['recent']): ?>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle mb-0">
                            <tbody>
                                <?php foreach ($orders['recent'] as $o): ?>
                                    <tr>
                                        <td class="ps-3"><a href="<?= e(url('admin/orders/view/' . $o['id'])) ?>" class="font-monospace fw-semibold text-body text-decoration-none text-nowrap"><?= e($o['order_number']) ?></a></td>
                                        <td class="cell-name"><?= e($o['customer_name']) ?></td>
                                        <td class="text-end text-nowrap"><?= e(format_price($o['grand_total'])) ?></td>
                                        <td><span class="badge rounded-pill <?= e(Order::statusBadge($o['status'])) ?>"><?= e(Order::statusLabel($o['status'])) ?></span></td>
                                        <td class="d-none d-md-table-cell small text-muted text-nowrap pe-3"><?= e(format_datetime($o['placed_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small px-3 pb-3 mb-0">No orders yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Catalogue -->
    <div class="col-12 col-xl-4">
        <div class="row g-3 g-lg-4">
            <div class="col-12 col-sm-6 col-xl-12">
                <a href="<?= e(url('admin/products')) ?>" class="card stat-card stat-card--teal stat-card--link h-100" data-stat="products">
                    <div class="card-body d-flex align-items-start gap-3">
                        <span class="stat-card__icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                        <div>
                            <p class="stat-card__label mb-1">Total products</p>
                            <p class="stat-card__value mb-1"><?= number_format($catalog['products']) ?></p>
                            <p class="stat-card__meta mb-0"><?= $catalog['products_active'] ?> active · <?= $catalog['products_out_of_stock'] ?> out of stock</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-12 col-sm-6 col-xl-12">
                <a href="<?= e(url('admin/categories')) ?>" class="card stat-card stat-card--amber stat-card--link h-100" data-stat="categories">
                    <div class="card-body d-flex align-items-start gap-3">
                        <span class="stat-card__icon" aria-hidden="true"><i class="bi bi-tags"></i></span>
                        <div>
                            <p class="stat-card__label mb-1">Total categories</p>
                            <p class="stat-card__value mb-1"><?= number_format($catalog['categories']) ?></p>
                            <p class="stat-card__meta mb-0"><?= $catalog['categories_active'] ?> active</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>
