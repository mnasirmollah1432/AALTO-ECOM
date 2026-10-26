<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Admin-editable key/value settings (table `settings`).
 * Values are strings; callers convert them (money stays a DECIMAL string).
 */
final class Setting
{
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            try {
                self::$cache = array_column(db_all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
            } catch (\Throwable) {
                self::$cache = []; // table missing (not yet migrated): fall back to defaults
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        db_query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
        self::$cache = null;
    }

    /** Order subtotal (DECIMAL string) from which delivery is free; "0.00" = never free. */
    public static function freeDeliveryFrom(): string
    {
        return self::get('free_delivery_from', '0.00');
    }
}
