<?php
/**
 * Minimal layout for the admin sign-in screen.
 *
 * @var string $content
 * @var string|null $title
 */
$appName = (string) config('app.name', 'Aalto Mart');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Admin') . ' | ' . $appName) ?></title>

    <link rel="icon" href="<?= e(url('favicon.ico')) ?>" sizes="any">
    <link rel="icon" href="<?= e(asset('images/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="icon" href="<?= e(asset('images/favicon-192.png')) ?>" type="image/png" sizes="192x192">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="auth-body">
    <main class="auth-shell">
        <?= $content ?>
    </main>

    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
