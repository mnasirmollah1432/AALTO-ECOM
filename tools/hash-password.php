<?php

declare(strict_types=1);

/**
 * Generate a password hash, or set the admin password directly.
 *
 * Print a hash only (paste into SQL / phpMyAdmin yourself):
 *     php tools/hash-password.php
 *
 * Update a user's password in the database configured in .env:
 *     php tools/hash-password.php --email=admin@example.com
 *
 * Create a new admin account (fresh install without the sample seed):
 *     php tools/hash-password.php --create --email=you@your-domain.com --name="Your Name"
 *
 * The password is always typed at a hidden prompt, never passed as an
 * argument, so it does not end up in shell history.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

function prompt_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: PowerShell can read without echo
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
             . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
             . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $value = rtrim((string) shell_exec($cmd), "\r\n");
    } else {
        shell_exec('stty -echo');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $value;
}

$options = getopt('', ['email::', 'name::', 'create']);
$email   = $options['email'] ?? null;
$create  = isset($options['create']);

if ($create && ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    fwrite(STDERR, "--create needs a valid --email=you@your-domain.com
");
    exit(1);
}
if ($create && db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
    fwrite(STDERR, "A user with email {$email} already exists. Run without --create to change its password.
");
    exit(1);
}

$password = prompt_hidden('New password: ');
$confirm  = prompt_hidden('Confirm password: ');

if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Use at least 10 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($email === null) {
    echo PHP_EOL, $hash, PHP_EOL;
    exit(0);
}

if ($create) {
    $name = trim((string) ($options['name'] ?? '')) ?: 'Store Admin';
    db_insert('users', [
        'name'          => mb_substr($name, 0, 120),
        'email'         => $email,
        'password_hash' => $hash,
        'role'          => 'admin',
        'is_active'     => 1,
    ]);
    echo "Admin account created for {$email}.
";
    exit(0);
}

$updated = db_update('users', ['password_hash' => $hash], ['email' => $email]);

if ($updated === 0) {
    fwrite(STDERR, "No user found with email {$email}.\n");
    exit(1);
}

echo "Password updated for {$email}.\n";
