<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Admin authentication (session based).
 *
 * The session only stores the admin's id, name, email and timing data.
 * The password hash is read inside attempt() and never leaves it.
 */
final class Auth
{
    private const SESSION_KEY = '_admin';

    /** Rotate the session id this often while signed in (seconds). */
    private const REGENERATE_EVERY = 900;

    /**
     * Verified against when the email is unknown, so a wrong email and a
     * wrong password take the same time (no account enumeration by timing).
     */
    private const DUMMY_HASH = '$2y$10$3FM7.O0BLnnrWu7pzNbil.V9R8Jgb9k4UDL2DqYnbCMqW3PRSytY6';

    public const OK      = 'ok';
    public const INVALID = 'invalid';
    public const LOCKED  = 'locked';

    private static ?array $user = null;
    private static bool $resolved = false;

    /**
     * Try to sign in. Returns [status, minutesUntilUnlock].
     *
     * @return array{0: string, 1: int}
     */
    public static function attempt(string $email, string $password): array
    {
        $ip = self::clientIp();

        $wait = self::lockoutMinutes($email, $ip);
        if ($wait > 0) {
            return [self::LOCKED, $wait];
        }

        $row = db_one(
            "SELECT id, name, email, password_hash, last_login_at
               FROM users
              WHERE email = ? AND role = 'admin' AND is_active = 1
              LIMIT 1",
            [$email]
        );

        $valid = password_verify($password, $row['password_hash'] ?? self::DUMMY_HASH);

        if ($row === null || !$valid) {
            self::recordFailure($email, $ip);
            return [self::INVALID, 0];
        }

        // Upgrade the hash transparently if PHP's default algorithm/cost changed
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $row['id']]);
        }

        db_query('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?', [$email, $ip]);
        db_update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);

        // New session id on privilege change: defeats session fixation
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        csrf_regenerate();

        $now = time();
        $_SESSION[self::SESSION_KEY] = [
            'id'              => (int) $row['id'],
            'name'            => $row['name'],
            'email'           => $row['email'],
            'previous_login'  => $row['last_login_at'],
            'login_at'        => $now,
            'last_activity'   => $now,
            'regenerated_at'  => $now,
            'fingerprint'     => self::fingerprint(),
        ];

        self::$user = null;
        self::$resolved = false;

        return [self::OK, 0];
    }

    /**
     * The signed-in admin, or null. Enforces idle timeout, absolute
     * lifetime, browser fingerprint and that the account is still an
     * active admin in the database.
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $session = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($session) || empty($session['id'])) {
            return self::$user = null;
        }

        $now = time();
        $idle = (int) config('app.admin.session_idle', 1800);
        $lifetime = (int) config('app.admin.session_lifetime', 28800);

        if ($now - (int) $session['last_activity'] > $idle || $now - (int) $session['login_at'] > $lifetime) {
            self::logout();
            flash('warning', 'Your session expired. Please sign in again.');
            return self::$user = null;
        }

        if (!hash_equals((string) $session['fingerprint'], self::fingerprint())) {
            self::logout();
            return self::$user = null;
        }

        // Account may have been disabled or demoted since login
        $row = db_one(
            "SELECT id, name, email FROM users WHERE id = ? AND role = 'admin' AND is_active = 1",
            [$session['id']]
        );
        if ($row === null) {
            self::logout();
            return self::$user = null;
        }

        $_SESSION[self::SESSION_KEY]['last_activity'] = $now;
        $_SESSION[self::SESSION_KEY]['name'] = $row['name'];
        $_SESSION[self::SESSION_KEY]['email'] = $row['email'];

        if ($now - (int) $session['regenerated_at'] > self::REGENERATE_EVERY && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY]['regenerated_at'] = $now;
        }

        return self::$user = [
            'id'             => (int) $row['id'],
            'name'           => $row['name'],
            'email'          => $row['email'],
            'previous_login' => $session['previous_login'] ?? null,
        ];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Destroy the session completely and start a fresh, empty one. */
    public static function logout(): void
    {
        self::$user = null;
        self::$resolved = true;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }

        session_destroy();

        // Fresh session (new id) so flash messages and a new CSRF token work
        if (!headers_sent()) {
            session_start();
            session_regenerate_id(true);
        }
    }

    /* --------------------------------------------------------
     | Brute-force protection
     * ------------------------------------------------------ */

    /** Minutes until login is allowed again, or 0 if not locked. */
    private static function lockoutMinutes(string $email, string $ip): int
    {
        $window = (int) config('app.admin.lockout_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - $window * 60);

        $row = db_one(
            'SELECT
                SUM(email = ?) AS by_email,
                COUNT(*)       AS by_ip,
                MIN(CASE WHEN email = ? THEN attempted_at END) AS oldest_email,
                MIN(attempted_at) AS oldest_ip
               FROM login_attempts
              WHERE ip_address = ? AND attempted_at >= ?',
            [$email, $email, $ip, $since]
        );

        $oldest = null;
        if ((int) $row['by_email'] >= (int) config('app.admin.max_attempts', 5)) {
            $oldest = $row['oldest_email'];
        } elseif ((int) $row['by_ip'] >= (int) config('app.admin.max_attempts_ip', 20)) {
            $oldest = $row['oldest_ip'];
        }

        if ($oldest === null) {
            return 0;
        }

        $unlockAt = strtotime((string) $oldest) + $window * 60;
        return max(1, (int) ceil(($unlockAt - time()) / 60));
    }

    private static function recordFailure(string $email, string $ip): void
    {
        db_insert('login_attempts', [
            'email'        => mb_substr($email, 0, 190),
            'ip_address'   => $ip,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);

        // Housekeeping: old rows are useless
        db_query('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);

        $safeEmail = preg_replace('/[\x00-\x1F\x7F]/', '', mb_substr($email, 0, 190));
        log_message('warning', sprintf('Failed admin login for "%s" from %s', $safeEmail, $ip));
    }

    /** REMOTE_ADDR only - proxy headers can be forged by the client. */
    private static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    private static function fingerprint(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}
