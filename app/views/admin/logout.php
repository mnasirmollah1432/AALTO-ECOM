<?php
/** @var array $admin */
?>
<div class="card mx-auto" style="max-width: 28rem;">
    <div class="card-body text-center p-4">
        <i class="bi bi-box-arrow-right display-6 text-primary" aria-hidden="true"></i>
        <h2 class="h5 mt-3">Sign out?</h2>
        <p class="text-muted">You are signed in as <strong><?= e($admin['email']) ?></strong>.</p>
        <form method="post" action="<?= e(url('admin/logout')) ?>" class="d-flex gap-2 justify-content-center">
            <?= csrf_field() ?>
            <a href="<?= e(url('admin/dashboard')) ?>" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-danger">Sign out</button>
        </form>
    </div>
</div>
