<?php
/**
 * Admin panel layout (signed-in pages only).
 *
 * @var string     $content
 * @var string     $title
 * @var array      $admin   signed-in admin: id, name, email
 */
$appName = (string) config('app.name', 'Aalto Mart');

$menu = [
    ['path' => 'admin/dashboard',  'label' => 'Dashboard',  'icon' => 'bi-speedometer2', 'ready' => true],
    ['path' => 'admin/products',   'label' => 'Products',   'icon' => 'bi-box-seam',     'ready' => true],
    ['path' => 'admin/categories', 'label' => 'Categories', 'icon' => 'bi-tags',         'ready' => true],
    ['path' => 'admin/orders',     'label' => 'Orders',     'icon' => 'bi-receipt',      'ready' => true],
    ['path' => 'admin/delivery',   'label' => 'Delivery',   'icon' => 'bi-truck',        'ready' => true],
    ['path' => 'admin/courier',    'label' => 'Courier',    'icon' => 'bi-box-seam-fill', 'ready' => true],
    ['path' => 'admin/slides',     'label' => 'Home slider', 'icon' => 'bi-images',      'ready' => true],
    ['path' => 'admin/contact',    'label' => 'Contact & social', 'icon' => 'bi-chat-dots', 'ready' => true],
    ['path' => 'admin/appearance', 'label' => 'Appearance', 'icon' => 'bi-palette',       'ready' => true],
    ['path' => 'admin/tracking',   'label' => 'Marketing & tracking', 'icon' => 'bi-graph-up', 'ready' => true],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title . ' | ' . $appName . ' Admin') ?></title>

    <link rel="icon" href="<?= e(url('favicon.ico')) ?>" sizes="any">
    <link rel="icon" href="<?= e(asset('images/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="icon" href="<?= e(asset('images/favicon-192.png')) ?>" type="image/png" sizes="192x192">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin-body">
    <div class="admin-shell">
        <aside class="admin-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="adminSidebar"
               aria-labelledby="adminSidebarLabel">
            <div class="offcanvas-header admin-sidebar__brand">
                <a href="<?= e(url('admin/dashboard')) ?>" class="d-flex align-items-center gap-2 text-decoration-none"
                   id="adminSidebarLabel">
                    <span class="admin-brand"><img src="<?= e(asset('images/brand/logo-wordmark.webp')) ?>" alt="<?= e($appName) ?>" width="255" height="120" class="admin-brand__logo" loading="eager"> <span class="admin-brand__tag">Admin</span></span>
                </a>
                <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas"
                        data-bs-target="#adminSidebar" aria-label="Close menu"></button>
            </div>

            <nav class="offcanvas-body flex-column" aria-label="Admin navigation">
                <ul class="nav flex-column admin-nav w-100">
                    <?php foreach ($menu as $item): $active = is_active_path('/' . $item['path']); ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e(url($item['path'])) ?>"
                               <?= $active ? 'aria-current="page"' : '' ?>>
                                <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i>
                                <span><?= e($item['label']) ?></span>
                                <?php if (!$item['ready']): ?>
                                    <span class="badge admin-nav__soon ms-auto">Soon</span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="admin-sidebar__footer mt-auto w-100">
                    <a class="nav-link" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-shop" aria-hidden="true"></i><span>View store</span>
                        <i class="bi bi-box-arrow-up-right ms-auto small" aria-hidden="true"></i>
                    </a>
                </div>
            </nav>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas"
                        data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open menu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <h1 class="admin-topbar__title"><?= e($title) ?></h1>

                <div class="dropdown ms-auto">
                    <button class="btn admin-user dropdown-toggle" type="button" data-bs-toggle="dropdown"
                            aria-expanded="false" aria-label="Account menu for <?= e($admin['name']) ?>">
                        <span class="admin-user__avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></span>
                        <span class="d-none d-sm-inline"><?= e($admin['name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><span class="dropdown-item-text small text-muted"><?= e($admin['email']) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('admin/logout')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </header>

            <main class="admin-content" id="main">
                <?php partial('flash', ['bare' => true]); ?>
                <?= $content ?>
            </main>
        </div>
    </div>

    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
