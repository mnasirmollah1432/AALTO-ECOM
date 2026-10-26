<?php
/**
 * Product listing for /shop and /category/{slug}.
 *
 * @var string     $heading
 * @var array|null $category    current category (category page, or ?category= on /shop)
 * @var array      $filters     q, category, sort, sale, in_stock, new
 * @var array      $result      items, total, page, pages, per_page
 * @var string     $basePath    "shop" or "category/{slug}"
 * @var array      $categories  active categories with counts
 */
use App\Models\Product;

$isCategoryPage = str_starts_with($basePath, 'category/');
$items = $result['items'];

// Query parameters that describe the current listing (used by links/forms)
$params = array_filter([
    'q'        => $filters['q'],
    'category' => !$isCategoryPage && $category ? $category['slug'] : '',
    'sale'     => $filters['sale'] ? '1' : '',
    'in_stock' => $filters['in_stock'] ? '1' : '',
    'new'      => $filters['new'] ? '1' : '',
    'sort'     => $filters['sort'] !== 'featured' ? $filters['sort'] : '',
], static fn ($v) => $v !== '');

$without = static fn (string $key): string => url_with_query($basePath, array_diff_key($params, [$key => 1]));
$chips = [];
if ($filters['q'] !== '') { $chips[] = ['Search: “' . $filters['q'] . '”', $without('q')]; }
if (!$isCategoryPage && $category) { $chips[] = [$category['name'], $without('category')]; }
if ($filters['sale']) { $chips[] = ['On sale', $without('sale')]; }
if ($filters['in_stock']) { $chips[] = ['In stock', $without('in_stock')]; }
if ($filters['new']) { $chips[] = ['New', $without('new')]; }

$from = $result['total'] ? ($result['page'] - 1) * $result['per_page'] + 1 : 0;
$to = min($result['total'], $result['page'] * $result['per_page']);
?>
<div class="page-head">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
                <?php if ($isCategoryPage): ?>
                    <li class="breadcrumb-item"><a href="<?= e(url('shop')) ?>">Shop</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= e($category['name']) ?></li>
                <?php else: ?>
                    <li class="breadcrumb-item active" aria-current="page">Shop</li>
                <?php endif; ?>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <?php if ($isCategoryPage && !empty($category['image_path'])): ?>
                <?= responsive_img($category['image_path'], '', '64px', ['class' => 'page-head__image', 'width' => 64, 'height' => 64, 'loading' => 'eager']) ?>
            <?php endif; ?>
            <div>
                <h1 class="page-head__title"><?= e($heading) ?></h1>
                <p class="page-head__count mb-0">
                    <?= number_format($result['total']) ?> product<?= $result['total'] === 1 ? '' : 's' ?>
                    <?php if ($isCategoryPage && ($filters['q'] !== '' || $filters['sale'] || $filters['in_stock'] || $filters['new'])): ?>
                        <span class="text-muted">(of <?= (int) $category['product_count'] ?> in this category)</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php if ($isCategoryPage && !empty($category['description'])): ?>
            <p class="page-head__desc"><?= e($category['description']) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <div class="row g-4">
        <!-- Filters: sidebar on desktop, slide-out panel on mobile -->
        <aside class="col-lg-3">
            <div class="offcanvas-lg offcanvas-start shop-filters" tabindex="-1" id="shopFilters" aria-labelledby="shopFiltersLabel">
                <div class="offcanvas-header">
                    <h2 class="offcanvas-title h5" id="shopFiltersLabel">Filters</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#shopFilters" aria-label="Close filters"></button>
                </div>
                <div class="offcanvas-body flex-column">
                    <form method="get" action="<?= e(url($basePath)) ?>" class="w-100" role="search" aria-label="Filter products">
                        <?php if (!$isCategoryPage && $category): ?>
                            <input type="hidden" name="category" value="<?= e($category['slug']) ?>">
                        <?php endif; ?>
                        <?php if ($filters['sort'] !== 'featured'): ?>
                            <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
                        <?php endif; ?>

                        <div class="filter-group">
                            <label for="shop-q" class="filter-group__title"><?= $isCategoryPage ? 'Search in ' . e($category['name']) : 'Search' ?></label>
                            <div class="input-group">
                                <input type="search" id="shop-q" name="q" class="form-control" value="<?= e($filters['q']) ?>"
                                       placeholder="Product name…" maxlength="100">
                                <button class="btn btn-outline-secondary" type="submit" aria-label="Search"><?= icon('search') ?></button>
                            </div>
                        </div>

                        <fieldset class="filter-group">
                            <legend class="filter-group__title">Show only</legend>
                            <?php foreach (['sale' => 'On sale', 'in_stock' => 'In stock', 'new' => 'New arrivals'] as $key => $label): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="f-<?= $key ?>"
                                           <?= $filters[$key] ? 'checked' : '' ?> data-autosubmit>
                                    <label class="form-check-label" for="f-<?= $key ?>"><?= e($label) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>

                        <button type="submit" class="btn btn-primary w-100 mb-4 js-hide">Apply filters</button>
                    </form>

                    <nav class="filter-group w-100" aria-labelledby="cat-nav-title">
                        <h2 class="filter-group__title" id="cat-nav-title">Categories</h2>
                        <ul class="list-unstyled category-nav mb-0">
                            <li>
                                <a href="<?= e(url('shop')) ?>" class="<?= !$category ? 'active' : '' ?>" <?= !$category ? 'aria-current="page"' : '' ?>>
                                    All products
                                </a>
                            </li>
                            <?php foreach ($categories as $cat): $current = $category && $category['id'] === $cat['id']; ?>
                                <li>
                                    <a href="<?= e(url('category/' . $cat['slug'])) ?>" class="<?= $current ? 'active' : '' ?>" <?= $current ? 'aria-current="page"' : '' ?>>
                                        <span><?= e($cat['name']) ?></span>
                                        <span class="category-nav__count"><?= (int) $cat['product_count'] ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                </div>
            </div>
        </aside>

        <div class="col-lg-9">
            <h2 class="visually-hidden">Products</h2>

            <!-- Toolbar -->
            <div class="shop-toolbar">
                <button class="btn btn-outline-dark d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#shopFilters" aria-controls="shopFilters">
                    <?= icon('sliders', 'me-1') ?>Filters<?= $chips ? ' (' . count($chips) . ')' : '' ?>
                </button>
                <p class="shop-toolbar__count d-none d-md-block mb-0" aria-live="polite">
                    <?= $result['total'] ? "Showing {$from}–{$to} of " . number_format($result['total']) : 'No products' ?>
                </p>
                <form method="get" action="<?= e(url($basePath)) ?>" class="shop-toolbar__sort">
                    <?php foreach (array_diff_key($params, ['sort' => 1]) as $key => $value): ?>
                        <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                    <?php endforeach; ?>
                    <label for="sort" class="small text-muted text-nowrap">Sort by</label>
                    <select id="sort" name="sort" class="form-select form-select-sm" data-autosubmit>
                        <?php foreach (Product::SHOP_SORTS as $key => [$label]): ?>
                            <option value="<?= e($key) ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-secondary js-hide">Sort</button>
                </form>
            </div>

            <?php if ($chips): ?>
                <div class="filter-chips" aria-label="Active filters">
                    <?php foreach ($chips as [$label, $removeUrl]): ?>
                        <a href="<?= e($removeUrl) ?>" class="filter-chip" aria-label="Remove filter: <?= e($label) ?>">
                            <?= e($label) ?> <?= icon('x') ?>
                        </a>
                    <?php endforeach; ?>
                    <a href="<?= e(url($basePath)) ?>" class="filter-chip filter-chip--clear">Clear all</a>
                </div>
            <?php endif; ?>

            <?php if ($items): ?>
                <div class="product-grid product-grid--listing">
                    <?php foreach ($items as $i => $product): ?>
                        <div><?php partial('product-card', ['product' => $product, 'eager' => $i < 4]); ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex justify-content-center mt-4">
                    <?= view('partials/pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'path' => $basePath, 'params' => $params]) ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <?= icon('search') ?>
                    <h3 class="h5">No products found</h3>
                    <p class="text-muted">
                        <?= $chips ? 'Try removing a filter or searching for something else.' : 'There are no products here yet. Please check back soon.' ?>
                    </p>
                    <a href="<?= e(url('shop')) ?>" class="btn btn-primary">Browse all products</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
