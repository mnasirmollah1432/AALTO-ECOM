<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Single shared PDO connection, created lazily on first use.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $c = config('database');

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                $c['port'],
                $c['database'],
                $c['charset']
            );

            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);

            // Keep DB timestamps in the same zone as PHP
            self::$pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");
        }

        return self::$pdo;
    }
}
