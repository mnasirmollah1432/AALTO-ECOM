<?php
/** @var bool $bare  true = no container wrapper (for cards / admin) */
$bare = $bare ?? false;
$map = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'];
foreach ($map as $key => $style):
    $message = flash($key);
    if ($message === null) {
        continue;
    }
?>
    <?php if (!$bare): ?><div class="container mt-3"><?php endif; ?>
        <div class="alert alert-<?= $style ?> alert-dismissible fade show" role="<?= $key === 'error' ? 'alert' : 'status' ?>">
            <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php if (!$bare): ?></div><?php endif; ?>
<?php endforeach; ?>
