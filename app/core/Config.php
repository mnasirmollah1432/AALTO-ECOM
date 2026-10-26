<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Loads every file in app/config as a config group.
 * config('app.name') reads key "name" from app/config/app.php.
 */
final class Config
{
    private static array $items = [];

    public static function load(string $dir): void
    {
        foreach (glob($dir . '/*.php') as $file) {
            self::$items[basename($file, '.php')] = require $file;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
