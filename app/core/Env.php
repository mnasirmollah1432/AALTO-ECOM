<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader (no Composer dependency - cPanel friendly).
 * Supports KEY=value, quoted values and # comments.
 * Real server environment variables take precedence over the file.
 */
final class Env
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            if (preg_match('/^(["\'])(.*)\1$/s', $value, $m)) {
                $value = $m[2];
            } else {
                // strip trailing inline comment on unquoted values
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = self::$values[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
