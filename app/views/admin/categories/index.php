<?php
/**
 * @var array $filters  q, status, sort
 * @var array $result   items, total, page, pages, per_page
 * @var array $counts   total, active
 */
use App\Controllers\Admin\CategoryController;

$items = $result['items'];
$returnQuery = http_build_query(array_filter($filters + ['page' => $result['page']], static fn ($v) => $v !== ''));
$isFiltered = $filters['q'] !== '' || $filters['status'] !== '';
$from = $result['total'] ? ($result['page'] - 1) * $result['per_page'] + 1 : 0;
$to = min($result['total'], $result['page'] * $result['per_page']);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <p class="text-muted mb-0">
        <?= (int) $counts['total'] ?> categor<?= (int) $counts['total'] === 1 ? 'y' : 'ies' ?>
        · <?= (int) $counts['active'] ?> active
    </p>
    <a href="<?= e(url('admin/categories/create')) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add category
    </a>
</div>

<form method="get" action="<?= e(url('admin/categories')) ?>" class="card filter-bar mb-3" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label for="q" class="form-label small text-muted mb-1">Search</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input type="search" id="q" name="q" class="form-control" value="<?= e($filters['q']) ?>"
                       placeholder="Name, slug or description" maxlength="100">
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label for="status" class="form-label small text-muted mb-1">Status</label>
            <select id="status" name="status" class="form-select">
                <option value="">All</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="sort" class="form-label small text-muted mb-1">Sort by</label>
            <select id="sort" name="sort" class="form-select">
                <?php foreach (['name' => 'Name (A–Z)', 'newest' => 'Newest first', 'updated' => 'Recently updated', 'products' => 'Most products'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-dark flex-grow-1">Search</button>
            <?php if ($isFiltered || $filters['sort'] !== 'name'): ?>
                <a href="<?= e(url('admin/categories')) ?>" class="btn btn-outline-secondary" aria-label="Clear filters" title="Clear filters">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (!$items): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi <?= $isFiltered ? 'bi-search' : 'bi-tags' ?> display-6 text-muted" aria-hidden="true"></i>
            <?php if ($isFiltered): ?>
                <h2 class="h6 mt-3">No categories match your search</h2>
                <p class="text-muted small mb-3">Try a different search term or status.</p>
                <a href="<?= e(url('admin/categories')) ?>" class="btn btn-outline-secondary btn-sm">Clear search</a>
            <?php else: ?>
                <h2 class="h6 mt-3">No categories yet</h2>
                <p class="text-muted small mb-3">Create your first category to start organising products.</p>
                <a href="<?= e(url('admin/categories/create')) ?>" class="btn btn-primary btn-sm">Add category</a>
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
                        <th scope="col" class="ps-3 ps-sm-2">Name</th>
                        <th scope="col" class="d-none d-md-table-cell text-center">Products</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="d-none d-lg-table-cell">Updated</th>
                        <th scope="col" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $cat):
                        $editUrl = url('admin/categories/edit/' . $cat['id']);
                        $blocked = CategoryController::deleteBlockedReason($cat);
                        $active = (bool) $cat['is_active'];
                    ?>
                        <tr>
                            <td class="ps-3 d-none d-sm-table-cell">
                                <?php if ($cat['image_path']): ?>
                                    <img src="<?= e(image_url($cat['image_path'])) ?>" alt="" class="thumb" width="44" height="44" loading="lazy">
                                <?php else: ?>
                                    <span class="thumb thumb--icon" aria-hidden="true"><i class="bi <?= e($cat['icon'] ?: 'bi-tag') ?>"></i></span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-name">
                                <a href="<?= e($editUrl) ?>" class="fw-semibold text-body text-decoration-none"><?= e($cat['name']) ?></a>
                                <div class="small text-muted">/<?= e($cat['slug']) ?></div>
                                <div class="small text-muted d-md-none"><?= (int) $cat['product_count'] ?> products</div>
                            </td>
                            <td class="d-none d-md-table-cell text-center"><?= (int) $cat['product_count'] ?></td>
                            <td>
                                <span class="badge rounded-pill <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= $active ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="d-none d-lg-table-cell small text-muted text-nowrap"><?= e(format_datetime($cat['updated_at'])) ?></td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-1">
                                    <a href="<?= e($editUrl) ?>" class="btn btn-sm btn-light" title="Edit" aria-label="Edit <?= e($cat['name']) ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>

                                    <form method="post" action="<?= e(url('admin/categories/toggle/' . $cat['id'])) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                                        <button type="submit" class="btn btn-sm btn-light"
                                                title="<?= $active ? 'Deactivate' : 'Activate' ?>"
                                                aria-label="<?= $active ? 'Deactivate' : 'Activate' ?> <?= e($cat['name']) ?>">
                                            <i class="bi <?= $active ? 'bi-eye-slash' : 'bi-eye' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>

                                    <?php if ($blocked === null): ?>
                                        <form method="post" action="<?= e(url('admin/categories/delete/' . $cat['id'])) ?>" class="d-inline"
                                              data-confirm="Delete the category &quot;<?= e($cat['name']) ?>&quot;? This cannot be undone."
                                              data-confirm-button="Delete">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
                                            <button type="submit" class="btn btn-sm btn-light text-danger" title="Delete"
                                                    aria-label="Delete <?= e($cat['name']) ?>">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="d-inline-block" tabindex="0" title="<?= e($blocked) ?>" data-bs-toggle="tooltip">
                                            <button type="button" class="btn btn-sm btn-light text-danger" disabled
                                                    aria-label="Cannot delete <?= e($cat['name']) ?>: in use">
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
        <?= view('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'path' => 'admin/categories', 'params' => $filters]) ?>
    </div>
<?php endif; ?>
