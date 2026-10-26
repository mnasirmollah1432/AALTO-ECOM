<section class="section">
    <div class="container text-center py-5">
        <div class="status-code">404</div>
        <h1 class="h2"><?= e($title ?? 'Page not found') ?></h1>
        <p class="text-muted mb-4">The page you are looking for does not exist or has been moved.</p>
        <a href="<?= e(url('/')) ?>" class="btn btn-primary">Back to home</a>
    </div>
</section>
