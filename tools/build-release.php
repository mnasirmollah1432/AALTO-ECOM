<?php

declare(strict_types=1);

/**
 * Build the upload package for production (a .zip for cPanel File Manager).
 *
 *     php tools/build-release.php                  -> ../aalto-mart-release-YYYYMMDD-HHMM.zip
 *     php tools/build-release.php --out=C:/x.zip
 *
 * The archive has no top-level folder: extract it straight into the target
 * directory. It includes every .htaccess / .user.ini (easy to lose when
 * zipping by hand) and leaves out what must never be uploaded or overwritten:
 * .env, log files and the live product/category images in public/uploads.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = getopt('', ['out::']);
$out = $options['out'] ?? dirname($root) . '/aalto-mart-release-' . date('Ymd-Hi') . '.zip';

/** Top-level entries that belong in the release. */
$include = ['.htaccess', '.env.example', 'README.md', 'DEPLOYMENT.md', 'PRODUCTION-CHECKLIST.md', 'app', 'database', 'public', 'storage', 'tools'];

/** Relative paths (forward slashes) that are never packaged. */
$exclude = static function (string $rel): bool {
    return $rel === '.env'
        || preg_match('#^storage/logs/(?!\.gitkeep$)#', $rel) === 1                 // logs
        || preg_match('#^public/uploads/(?!\.htaccess$|\.gitkeep$)#', $rel) === 1   // live images
        || preg_match('#(^|/)(\.DS_Store|Thumbs\.db|\.idea|\.vscode)(/|$)#', $rel) === 1;
};

$files = [];
foreach ($include as $entry) {
    $path = $root . '/' . $entry;
    if (is_file($path)) {
        $files[] = $entry;
        continue;
    }
    if (!is_dir($path)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}
$files = array_values(array_filter($files, static fn (string $rel): bool => !$exclude($rel)));
sort($files);

// Files the site depends on for security / routing: refuse to build without them
$required = ['.htaccess', 'public/.htaccess', 'public/.user.ini', 'public/index.php', 'public/uploads/.htaccess',
             'app/.htaccess', 'database/.htaccess', 'storage/.htaccess', 'tools/.htaccess', 'database/schema.sql'];
$missing = array_diff($required, $files);
if ($missing) {
    fwrite(STDERR, 'Missing required files: ' . implode(', ', $missing) . "\n");
    exit(1);
}

if (is_file($out)) {
    unlink($out);
}
$zip = new PharData($out, 0, null, Phar::ZIP);
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, $rel);
}
// Empty folders the app writes to
foreach (['public/uploads/products/', 'public/uploads/categories/'] as $dir) {
    $zip->addEmptyDir($dir);
}
if (Phar::canCompress(Phar::GZ)) {
    $zip->compressFiles(Phar::GZ); // deflate, readable by every unzip tool
}
unset($zip);

printf("Release package: %s\n%d files, %.1f KB\n", realpath($out), count($files), filesize($out) / 1024);
echo "Included security files: " . implode(', ', array_filter($files, static fn ($f) => str_ends_with($f, '.htaccess') || str_ends_with($f, '.user.ini'))) . "\n";
echo "Not included: .env, storage/logs/*.log, public/uploads images (keep the server's own).\n";
