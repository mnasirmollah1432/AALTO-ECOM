<?php
/**
 * Guest wishlist (session).
 *
 * @var array $products listing rows
 */
$count = count($products);
?>
<div class="page-head">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
                <li class="breadcrumb-item active" aria-current="page">Wishlist</li>
            </ol>
        </nav>
        <h1 class="page-head__title">Your wishlist</h1>
        <p class="page-head__count mb-0"><?= $count ?> product<?= $count === 1 ? '' : 's' ?> saved on this device</p>
    </div>
</div>

<div class="container pb-5">
    <?php if (!$products): ?>
        <div class="empty-state my-4">
            <?= icon('heart') ?>
            <h2 class="h5 mt-2">Your wishlist is empty</h2>
            <p class="text-muted">Tap the heart on any product to save it here.</p>
            <a href="<?= e(url('shop')) ?>" class="btn btn-primary">Start shopping</a>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $item): ?>
                <div><?php partial('product-card', ['product' => $item]); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
