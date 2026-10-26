<?php
/**
 * @var array $filters     q, category, status, flag, stock, sort
 * @var array $result      items, total, page, pages, per_page
 * @var array $categories  id, name, is_active
 * @var array $counts      total, active, out_of_stock
 */
use App\Controllers\Admin\ProductController;
use App\Core\Money;
use App\Models\Product;

$items = $result['items'];
$query = array_filter($filters, static fn ($v) => $v !== '');
$returnQuery = http_build_query($query + ['page' => $result['page']]);
$isFiltered = $filters['q'] !== '' || $filters['category'] !== '' || $filters['status'] !== '' || $filters['flag'] !== '' || $filters['stock'] !== '';
$from = $result['total'] ? ($result['page'] - 1) * $result['per_page'] + 1 : 0;
$to = min($result['total'], $result['page'] * $result['per_page']);

$flagButtons = [
    'active'   => ['is_active', 'Active', 'bi-eye', 'Visible in the store'],
    'featured' => ['is_featured', 'Featured', 'bi-star', 'Shown in Featured products'],
    'new'      => ['is_new', 'New', 'bi-stars', 'Shows a "New" badge'],
];
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <p class="text-muted mb-0">
        <?= (int) $counts['total'] ?> product<?= (int) $counts['total'] === 1 ? '' : 's' ?>
        · <?= (int) $counts['active'] ?> active
        <?php if ((int) $counts['out_of_stock'] > 0): ?>
            · <a href="<?= e(url_with_query('admin/products', ['stock' => 'out'])) ?>" class="text-danger text-decoration-none"><?= (int) $counts['out_of_stock'] ?> out of stock</a>
        <?php endif; ?>
    </p>
    <a href="<?= e(url('admin/products/create')) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add product
    </a>
</div>

<form method="get" action="<?= e(url('admin/products')) ?>" class="card filter-bar mb-3" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-xl-3">
            <label for="q" class="form-label small text-muted mb-1">Search</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input type="search" id="q" name="q" class="form-control" value="<?= e($filters['q']) ?>"
                       placeholder="Name, SKU or slug" maxlength="100">
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <label for="category" class="form-label small text-muted mb-1">Category</label>
            <select id="category" name="category" class="form-select">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $filters['category'] === (int) $cat['id'] ? 'selected' : '' ?>>
                        <?= e($cat['name']) ?><?= $cat['is_active'] ? '' : ' (inactive)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-xl-1">
            <label for="status" class="form-label small text-muted mb-1">Status</label>
            <select id="status" name="status" class="form-select">
                <option value="">All</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-6 col-md-4 col-xl-1">
            <label for="flag" class="form-label small text-muted mb-1">Show</label>
            <select id="flag" name="flag" class="form-select">
                <option value="">All</option>
                <option value="featured" <?= $filters['flag'] === 'featured' ? 'selected' : '' ?>>Featured</option>
                <option value="new" <?= $filters['flag'] === 'new' ? 'selected' : '' ?>>New</option>
                <option value="sale" <?= $filters['flag'] === 'sale' ? 'selected' : '' ?>>On sale</option>
            </select>
        </div>
        <div class="col-6 col-md-4 col-xl-1">
            <label for="stock" class="form-label small text-muted mb-1">Stock</label>
            <select id="stock" name="stock" class="form-select">
                <option value="">All</option>
                <option value="in" <?= $filters['stock'] === 'in' ? 'selected' : '' ?>>In stock</option>
                <option value="low" <?= $filters['stock'] === 'low' ? 'selected' : '' ?>>Low (1–<?= Product::LOW_STOCK ?>)</option>
                <option value="out" <?= $filters['stock'] === 'out' ? 'selected' : '' ?>>Out of stock</option>
            </select>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <label for="sort" class="form-label small text-muted mb-1">Sort by</label>
            <select id="sort" name="sort" class="form-select">
                <?php foreach (Product::SORTS as $key => [$label]): ?>
                    <option value="<?= e($key) ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4 col-xl-2 d-flex gap-2">
            <button type="submit" class="btn btn-dark flex-grow-1">Filter</button>
            <?php if ($isFiltered || $filters['sort'] !== 'newest'): ?>
                <a href="<?= e(url('admin/products')) ?>" class="btn btn-outline-secondary" aria-label="Clear filters" title="Clear filters">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (!$items): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi <?= $isFiltered ? 'bi-search' : 'bi-box-seam' ?> display-6 text-muted" aria-hidden="true"></i>
            <?php if ($isFiltered): ?>
                <h2 class="h6 mt-3">No products match these filters</h2>
                <p class="text-muted small mb-3">Try a different search or clear the filters.</p>
                <a href="<?= e(url('admin/products')) ?>" class="btn btn-outline-secondary btn-sm">Clear filters</a>
            <?php else: ?>
                <h2 class="h6 mt-3">No products yet</h2>
                <p class="text-muted small mb-3">Add your first product to start selling.</p>
                <a href="<?= e(url('admin/products/create')) ?>" class="btn btn-primary btn-sm">Add product</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col" class="ps-3 d-none d-sm-table-cell" style="width: 64px;"><span class="visually-hidden">Image</span></th>
                        <th scope="col" class="ps-3 ps-sm-2">Product</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col" class="text-center">Stock</th>
                        <th scope="col" class="text-center d-none d-md-table-cell">Visibility</th>
                        <th scope="col" class="d-none d-xl-table-cell">Updated</th>
                        <th scope="col" class="text-end pe-3"><span class="d-none d-sm-inline">Actions</span><span class="visually-hidden d-sm-none">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $p):
                        $editUrl = url('admin/products/edit/' . $p['id']);
                        $onSale = Money::isOnSale($p['regular_price'], $p['sale_price']);
                        $stock = (int) $p['stock_quantity'];
                        $blocked = ProductController::deleteBlockedReason($p);
                    ?>
                        <tr class="<?= $p['is_active'] ? '' : 'is-inactive' ?>">
                            <td class="ps-3 d-none d-sm-table-cell">
                                <img src="<?= e(image_thumb_url($p['image_path'])) ?>" alt="" class="thumb" width="44" height="44" loading="lazy" decoding="async">
                            </td>
                            <td class="cell-name">
                                <a href="<?= e($editUrl) ?>" class="fw-semibold text-body text-decoration-none"><?= e($p['name']) ?></a>
                                <div class="small text-muted">
                                    <span class="font-monospace"><?= e($p['sku']) ?></span>
                                    · <?= e($p['category_name']) ?><?= $p['category_active'] ? '' : ' <span class="badge text-bg-light border">category inactive</span>' ?>
                                </div>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($onSale): ?>
                                    <div class="fw-semibold"><?= e(format_price($p['sale_price'])) ?></div>
                                    <div class="small text-muted">
                                        <del><?= e(format_price($p['regular_price'])) ?></del>
                                        <span class="badge badge-sale">-<?= Money::discountPercent($p['regular_price'], $p['sale_price']) ?>%</span>
                                    </div>
                                <?php else: ?>
                                    <div class="fw-semibold"><?= e(format_price($p['regular_price'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($stock === 0): ?>
                                    <span class="badge text-bg-danger">Out</span>
                                <?php elseif ($stock <= Product::LOW_STOCK): ?>
                                    <span class="badge text-bg-warning" title="Low stock"><?= $stock ?></span>
                                <?php else: ?>
                                    <span class="fw-medium"><?= number_format($stock) ?></span>
                                <?php endif; ?>
                                <?php if ($p['has_sizes'] || $p['has_colours']): ?>
                                    <span class="d-block small text-muted" title="Total of its size/colour options"><?= implode(' + ', array_filter([$p['has_sizes'] ? 'sizes' : '', $p['has_colours'] ? 'colours' : ''])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center d-none d-md-table-cell">
                                <div class="d-inline-flex gap-1">
                                    <?php foreach ($flagButtons as $flag => [$column, $label, $icon, $hint]): $on = (bool) $p[$column]; ?>
                                        <form method="post" action="<?= e(url('admin/products/toggle/' . $p['id'])) ?>" data-flag-toggle>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="flag" value="<?= e($flag) ?>">
                                            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                                            <button type="submit" class="btn btn-sm flag-btn flag-btn--<?= e($flag) ?><?= $on ? ' is-on' : '' ?>"
                                                    aria-pressed="<?= $on ? 'true' : 'false' ?>"
                                                    title="<?= e($label . ': ' . ($on ? 'on' : 'off') . ' — ' . $hint) ?>">
                                                <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                                                <span><?= e($label) ?></span>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="d-none d-xl-table-cell small text-muted text-nowrap"><?= e(format_datetime($p['updated_at'])) ?></td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex flex-column flex-sm-row gap-1">
                                    <a href="<?= e($editUrl) ?>" class="btn btn-sm btn-light" title="Edit" aria-label="Edit <?= e($p['name']) ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <?php if ($blocked === null): ?>
                                        <form method="post" action="<?= e(url('admin/products/delete/' . $p['id'])) ?>" class="d-inline"
                                              data-confirm="Delete the product &quot;<?= e($p['name']) ?>&quot; and its images? This cannot be undone."
                                              data-confirm-button="Delete">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                                            <button type="submit" class="btn btn-sm btn-light text-danger" title="Delete"
                                                    aria-label="Delete <?= e($p['name']) ?>">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="d-inline-block" tabindex="0" title="<?= e($blocked) ?>" data-bs-toggle="tooltip">
                                            <button type="button" class="btn btn-sm btn-light text-danger" disabled
                                                    aria-label="Cannot delete <?= e($p['name']) ?>: it has orders">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
        <p class="small text-muted mb-0">Showing <?= $from ?>–<?= $to ?> of <?= (int) $result['total'] ?></p>
        <?= view('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'path' => 'admin/products', 'params' => $query]) ?>
    </div>
<?php endif; ?>

<div class="toast-container position-fixed bottom-0 end-0 p-3" aria-live="polite" aria-atomic="true" data-toast-area></div>
