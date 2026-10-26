<?php
$appName = (string) config('app.name', 'Aalto Mart');
$navCategories = nav_categories();
$cartCount = cart_count();
$searchQuery = request_path() === '/shop' && is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$isHome = request_path() === '/';
$isShop = is_active_path('/shop') || is_active_path('/product');
$isCategory = is_active_path('/category');
$isDeals = request_path() === '/shop' && ($_GET['sale'] ?? '') === '1';
$isContact = request_path() === '/contact';
$wishCount = App\Models\Wishlist::count();
$look = App\Models\Appearance::get(); // Admin -> Appearance (colours are validated #rrggbb)
?>
<?php if ($look['announce_enabled'] === '1'): ?>
<div class="announcement" style="background: <?= e($look['announce_bg']) ?>; color: <?= e($look['announce_color']) ?>">
    <div class="container">
        <p class="mb-0">
            <?php if ($look['announce_text'] !== ''): ?>
                <?= e($look['announce_text']) ?>
            <?php elseif ($freeFrom = free_delivery_from()): ?>
                Free delivery on orders over <?= e(format_price($freeFrom)) ?>
            <?php else: ?>
                Delivery all over Bangladesh<?php if (($zoneHint = delivery_zone_hint()) !== ''): ?><span class="d-none d-lg-inline">: <?= e($zoneHint) ?></span><?php endif; ?>
            <?php endif; ?>
            <?php if ($look['announce_text'] === ''): ?>
                <span class="announcement__sep d-none d-sm-inline" aria-hidden="true">·</span>
                <span class="d-none d-sm-inline">Cash on delivery</span>
                <span class="announcement__sep d-none d-sm-inline" aria-hidden="true">·</span>
                <span class="d-none d-md-inline"><?= (int) config('app.store.return_days', 7) ?>-day easy returns</span>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<header class="site-header sticky-top" data-sticky-header style="--am-header-line: <?= e($look['header_line']) ?>">
    <nav class="navbar navbar-expand-lg" aria-label="Main navigation">
        <div class="container site-header__bar">
            <button class="navbar-toggler site-header__toggle" type="button" data-bs-toggle="offcanvas"
                    data-bs-target="#mainNav" aria-controls="mainNav" aria-label="Open menu">
                <?= icon('list') ?>
            </button>

            <a class="navbar-brand brand" href="<?= e(url('/')) ?>" aria-label="<?= e($appName) ?> home">
                <img src="<?= e(asset('images/brand/logo-wordmark.webp')) ?>" alt="<?= e($appName) ?>" width="255" height="120" class="brand__logo" loading="eager" decoding="async">
            </a>

            <div class="offcanvas-lg offcanvas-start site-nav" tabindex="-1" id="mainNav" aria-labelledby="mainNavLabel">
                <div class="offcanvas-header">
                    <span class="brand" id="mainNavLabel"><img src="<?= e(asset('images/brand/logo-wordmark.webp')) ?>" alt="<?= e($appName) ?>" width="255" height="120" class="brand__logo" loading="lazy"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#mainNav" aria-label="Close menu"></button>
                </div>
                <div class="offcanvas-body">
                    <ul class="navbar-nav site-nav__list">
                        <li class="nav-item">
                            <a class="nav-link<?= $isHome ? ' active' : '' ?>" href="<?= e(url('/')) ?>" <?= $isHome ? 'aria-current="page"' : '' ?>>Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= $isShop && !$isDeals ? ' active' : '' ?>" href="<?= e(url('shop')) ?>" <?= request_path() === '/shop' && !$isDeals ? 'aria-current="page"' : '' ?>>Shop</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?= $isCategory ? ' active' : '' ?>" href="<?= e(url('shop')) ?>"
                               role="button" data-bs-toggle="dropdown" aria-expanded="false">Categories</a>
                            <ul class="dropdown-menu">
                                <?php foreach ($navCategories as $cat): ?>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center justify-content-between gap-3<?= request_path() === '/category/' . $cat['slug'] ? ' active' : '' ?>"
                                           href="<?= e(url('category/' . $cat['slug'])) ?>">
                                            <span><?= e($cat['name']) ?></span>
                                            <span class="dropdown-item__count"><?= (int) $cat['product_count'] ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                <?php if ($navCategories): ?><li><hr class="dropdown-divider"></li><?php endif; ?>
                                <li><a class="dropdown-item" href="<?= e(url('shop')) ?>">All products</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link nav-link--sale<?= $isDeals ? ' active' : '' ?>" href="<?= e(url_with_query('shop', ['sale' => '1'])) ?>">Deals</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= $isContact ? ' active' : '' ?>" href="<?= e(url('contact')) ?>" <?= $isContact ? 'aria-current="page"' : '' ?>>Contact us</a>
                        </li>
                    </ul>

                    <form class="header-search" action="<?= e(url('shop')) ?>" method="get" role="search">
                        <label for="header-q" class="visually-hidden">Search products</label>
                        <?= icon('search', 'header-search__icon') ?>
                        <input type="search" id="header-q" name="q" class="form-control" placeholder="Search"
                               value="<?= e($searchQuery) ?>" maxlength="100" autocomplete="off">
                        <button class="visually-hidden" type="submit">Search</button>
                    </form>

                    <div class="site-nav__extra d-lg-none">
                        <a href="<?= e(url('wishlist')) ?>"><?= icon('heart') ?> Wishlist<?= $wishCount ? ' (' . $wishCount . ')' : '' ?></a>
                        <a href="<?= e(url('cart')) ?>"><?= icon('bag') ?> Cart<?= $cartCount ? ' (' . $cartCount . ')' : '' ?></a>
                        <?php if (config('app.store.phone')): ?>
                            <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) config('app.store.phone'))) ?>"><?= icon('telephone') ?> <?= e(config('app.store.phone')) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="site-header__actions">
                <a class="btn-icon wish-link<?= request_path() === '/wishlist' ? ' active' : '' ?>" href="<?= e(url('wishlist')) ?>"
                   aria-label="Wishlist<?= $wishCount ? ', ' . $wishCount . ' item' . ($wishCount === 1 ? '' : 's') : ', empty' ?>" title="Wishlist">
                    <?= icon('heart') ?>
                    <?php if ($wishCount > 0): ?>
                        <span class="cart-badge wish-badge" aria-hidden="true"><?= $wishCount > 99 ? '99+' : $wishCount ?></span>
                    <?php endif; ?>
                </a>
                <a class="btn-icon cart-link<?= is_active_path('/cart') ? ' active' : '' ?>" href="<?= e(url('cart')) ?>"
                   <?= cart_drawer_enabled() ? 'data-bs-toggle="offcanvas" data-bs-target="#cartDrawer" aria-controls="cartDrawer"' : '' ?>
                   aria-label="Shopping cart<?= $cartCount ? ', ' . $cartCount . ' item' . ($cartCount === 1 ? '' : 's') : ', empty' ?>">
                    <?= icon('bag') ?>
                    <?php if ($cartCount > 0): ?>
                        <span class="cart-badge" aria-hidden="true"><?= $cartCount > 99 ? '99+' : $cartCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </nav>
</header>
