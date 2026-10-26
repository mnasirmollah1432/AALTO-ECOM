<?php
/**
 * @var array $errors field => message
 */
$appName = (string) config('app.name', 'Aalto Mart');
?>
<div class="auth-card card border-0">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <img src="<?= e(asset('images/brand/logo.webp')) ?>" alt="" width="424" height="240" class="auth-logo mb-3" loading="eager">
            <h1 class="h5 fw-bold mb-1"><?= e($appName) ?> Admin</h1>
            <p class="text-muted small mb-0">Sign in to manage your store</p>
        </div>

        <?php partial('flash', ['bare' => true]); ?>

        <form method="post" action="<?= e(url('admin/login')) ?>" novalidate data-auth-form>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <div class="input-group has-validation">
                    <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
                    <input type="email" id="email" name="email"
                           class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                           value="<?= e(old('email')) ?>" maxlength="190"
                           autocomplete="username" autocapitalize="none" spellcheck="false"
                           required <?= old('email') === '' ? 'autofocus' : '' ?>
                           <?= isset($errors['email']) ? 'aria-describedby="email-error"' : '' ?>>
                    <?php if (isset($errors['email'])): ?>
                        <div id="email-error" class="invalid-feedback"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group has-validation">
                    <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                    <input type="password" id="password" name="password"
                           class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           maxlength="1024" autocomplete="current-password" required
                           <?= old('email') !== '' ? 'autofocus' : '' ?>
                           <?= isset($errors['password']) ? 'aria-describedby="password-error"' : '' ?>>
                    <button type="button" class="btn btn-outline-secondary" data-toggle-password="password"
                            aria-label="Show password" aria-pressed="false">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                    <?php if (isset($errors['password'])): ?>
                        <div id="password-error" class="invalid-feedback"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2" data-submit>
                <span data-submit-label>Sign in</span>
                <span class="spinner-border spinner-border-sm d-none" aria-hidden="true" data-submit-spinner></span>
            </button>
        </form>
    </div>
</div>

<p class="text-center small mt-4 mb-0">
    <a href="<?= e(url('/')) ?>" class="auth-back"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to store</a>
</p>
