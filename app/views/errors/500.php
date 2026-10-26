<?php
/** @var Throwable|null $exception only set when APP_DEBUG=true */
?>
<section class="section">
    <div class="container py-5">
        <div class="text-center">
            <div class="status-code"><?= http_response_code() ?: 500 ?></div>
            <h1 class="h2"><?= e($title ?? 'Something went wrong') ?></h1>
            <p class="text-muted mb-4">We could not complete your request. Please try again in a moment.</p>
            <a href="<?= e(url('/')) ?>" class="btn btn-primary">Back to home</a>
        </div>

        <?php if (!empty($exception)): ?>
            <div class="alert alert-danger mt-5 text-start">
                <strong><?= e($exception::class) ?>:</strong> <?= e($exception->getMessage()) ?><br>
                <small><?= e($exception->getFile()) ?>:<?= (int) $exception->getLine() ?></small>
                <pre class="small mt-3 mb-0 debug-trace"><?= e($exception->getTraceAsString()) ?></pre>
            </div>
        <?php endif; ?>
    </div>
</section>
