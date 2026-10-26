<?php
/**
 * Main storefront layout.
 *
 * @var string     $content  rendered page body
 * @var array|null $meta     page metadata overrides, see page_meta()
 */
$m = page_meta($meta ?? []);
$ogProduct = $meta['product'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($m['title']) ?></title>
    <meta name="description" content="<?= e($m['description']) ?>">
    <meta name="robots" content="<?= e($m['robots']) ?>">
    <link rel="canonical" href="<?= e($m['canonical']) ?>">
    <meta name="theme-color" content="#16130f">

    <!-- Open Graph / social sharing -->
    <meta property="og:site_name" content="<?= e($m['site']) ?>">
    <meta property="og:type" content="<?= e($m['type']) ?>">
    <meta property="og:title" content="<?= e($m['og_title']) ?>">
    <meta property="og:description" content="<?= e($m['description']) ?>">
    <meta property="og:url" content="<?= e($m['canonical']) ?>">
    <meta property="og:image" content="<?= e($m['image']) ?>">
    <meta property="og:image:alt" content="<?= e($m['image_alt']) ?>">
    <?php if ($m['image_size']): ?>
        <meta property="og:image:width" content="<?= $m['image_size'][0] ?>">
        <meta property="og:image:height" content="<?= $m['image_size'][1] ?>">
    <?php endif; ?>
    <?php if ($ogProduct): ?>
        <meta property="product:price:amount" content="<?= e($ogProduct['price']) ?>">
        <meta property="product:price:currency" content="<?= e($ogProduct['currency']) ?>">
        <meta property="product:availability" content="<?= e($ogProduct['availability']) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($m['og_title']) ?>">
    <meta name="twitter:description" content="<?= e($m['description']) ?>">
    <meta name="twitter:image" content="<?= e($m['image']) ?>">

    <link rel="icon" href="<?= e(url('favicon.ico')) ?>" sizes="any">
    <link rel="icon" href="<?= e(asset('images/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="icon" href="<?= e(asset('images/favicon-192.png')) ?>" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="<?= e(asset('images/apple-touch-icon.png')) ?>">
    <!-- Self-hosted (same files as the Bootstrap 5.3.3 CDN build): no third-party connection -->
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php foreach ($m['json_ld'] as $block): ?>
        <script type="application/ld+json"><?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endforeach; ?>
    <?php if (($tracking = App\Models\Tracking::payload()) !== null): ?>
        <!-- Facebook Pixel / Google Tag Manager (Admin -> Marketing & tracking) -->
        <script type="application/json" id="am-tracking"><?= $tracking ?></script>
        <script src="<?= e(asset('js/tracking.js')) ?>" async></script>
    <?php endif; ?>
</head>
<body class="d-flex flex-column min-vh-100">
    <?php if ($tracking !== null && ($gtm = App\Models\Tracking::gtmId()) !== ''): ?>
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?= e($gtm) ?>" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>
    <?php endif; ?>
    <?php if ($tracking !== null && ($pixel = App\Models\Tracking::pixelId()) !== ''): ?>
        <noscript><img height="1" width="1" style="display:none" alt="" loading="eager" src="https://www.facebook.com/tr?id=<?= e($pixel) ?>&amp;ev=PageView&amp;noscript=1"></noscript>
    <?php endif; ?>
    <a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>

    <?php partial('header'); ?>

    <main id="main" class="flex-grow-1" tabindex="-1">
        <?php partial('flash'); ?>
        <?= $content ?>
    </main>

    <?php partial('footer'); ?>
    <?php partial('chat-widget'); ?>
    <?php partial('cart-drawer'); ?>
    <?php if (quick_add_used()) { partial('quick-add'); } ?>

    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
