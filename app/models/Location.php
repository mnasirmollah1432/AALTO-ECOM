<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Delivery zones (charge), districts (belong to a zone) and thanas
 * (belong to a district). Used by checkout and Admin -> Delivery.
 */
final class Location
{
    public const NAME_MAX = 80;

    /* --------------------------------------------------------
     | Checkout
     * ------------------------------------------------------ */

    /**
     * Active districts (Dhaka first, then A-Z) with their zone charge and
     * active thanas: [{id, name, zone, charge, thanas: [{id, name}]}].
     * Cached for the request.
     */
    public static function checkoutTree(): array
    {
        static $tree = null;
        if ($tree !== null) {
            return $tree;
        }
        $districts = db_all(
            'SELECT d.id, d.name, z.name AS zone, z.charge
               FROM districts d JOIN delivery_zones z ON z.id = d.zone_id
              WHERE d.is_active = 1
              ORDER BY d.sort_order, d.name'
        );
        $thanas = db_all(
            'SELECT t.id, t.district_id, t.name
               FROM thanas t JOIN districts d ON d.id = t.district_id AND d.is_active = 1
              WHERE t.is_active = 1
              ORDER BY t.name'
        );
        $byDistrict = [];
        foreach ($thanas as $t) {
            $byDistrict[(int) $t['district_id']][] = ['id' => (int) $t['id'], 'name' => $t['name']];
        }
        $tree = [];
        foreach ($districts as $d) {
            $tree[] = [
                'id'     => (int) $d['id'],
                'name'   => $d['name'],
                'zone'   => $d['zone'],
                'charge' => $d['charge'],
                'thanas' => $byDistrict[(int) $d['id']] ?? [],
            ];
        }
        return $tree;
    }

    /**
     * An active district + active thana pair with the zone charge, or null.
     * $lock = true locks the rows (inside the order transaction).
     *
     * @return array{district_id: int, district: string, thana_id: int, thana: string, zone: string, charge: string}|null
     */
    public static function resolve(int $districtId, int $thanaId, bool $lock = false): ?array
    {
        $row = db_one(
            'SELECT d.id AS district_id, d.name AS district, t.id AS thana_id, t.name AS thana, z.name AS zone, z.charge
               FROM thanas t
               JOIN districts d ON d.id = t.district_id AND d.is_active = 1
               JOIN delivery_zones z ON z.id = d.zone_id
              WHERE t.id = ? AND t.district_id = ? AND t.is_active = 1' . ($lock ? ' FOR UPDATE' : ''),
            [$thanaId, $districtId]
        );
        if ($row === null) {
            return null;
        }
        $row['district_id'] = (int) $row['district_id'];
        $row['thana_id'] = (int) $row['thana_id'];
        return $row;
    }

    /** Cheapest and highest zone charge (DECIMAL strings), for "৳70 - ৳130" hints. */
    public static function chargeRange(): array
    {
        $row = db_one('SELECT MIN(z.charge) AS lo, MAX(z.charge) AS hi FROM delivery_zones z JOIN districts d ON d.zone_id = z.id AND d.is_active = 1');
        return [$row['lo'] ?? '0.00', $row['hi'] ?? '0.00'];
    }

    /** Active zones in order: [{name, charge}], for delivery hints. */
    public static function zoneCharges(): array
    {
        return db_all(
            'SELECT z.name, z.charge FROM delivery_zones z
              WHERE EXISTS (SELECT 1 FROM districts d WHERE d.zone_id = z.id AND d.is_active = 1)
              ORDER BY z.sort_order, z.charge'
        );
    }

    /* --------------------------------------------------------
     | Admin
     * ------------------------------------------------------ */

    public static function zones(): array
    {
        return db_all(
            'SELECT z.*, (SELECT COUNT(*) FROM districts d WHERE d.zone_id = z.id) AS district_count
               FROM delivery_zones z ORDER BY z.sort_order, z.id'
        );
    }

    public static function districts(): array
    {
        return db_all(
            'SELECT d.*, z.name AS zone_name,
                    (SELECT COUNT(*) FROM thanas t WHERE t.district_id = d.id) AS thana_count,
                    (SELECT COUNT(*) FROM thanas t WHERE t.district_id = d.id AND t.is_active = 1) AS active_thanas
               FROM districts d JOIN delivery_zones z ON z.id = d.zone_id
              ORDER BY d.sort_order, d.name'
        );
    }

    public static function district(int $id): ?array
    {
        return db_one('SELECT d.*, z.name AS zone_name FROM districts d JOIN delivery_zones z ON z.id = d.zone_id WHERE d.id = ?', [$id]);
    }

    public static function thanas(int $districtId): array
    {
        return db_all(
            'SELECT t.*, (SELECT COUNT(*) FROM orders o WHERE o.thana_id = t.id) AS order_count
               FROM thanas t WHERE t.district_id = ? ORDER BY t.name',
            [$districtId]
        );
    }

    public static function zone(int $id): ?array
    {
        return db_one('SELECT * FROM delivery_zones WHERE id = ?', [$id]);
    }

    public static function thana(int $id): ?array
    {
        return db_one('SELECT * FROM thanas WHERE id = ?', [$id]);
    }

    public static function districtOrderCount(int $id): int
    {
        return (int) db_value('SELECT COUNT(*) FROM orders WHERE district_id = ?', [$id]);
    }

    /** Is the name taken (case-insensitive by collation)? $scope limits thanas to one district. */
    public static function nameTaken(string $table, string $name, ?int $exceptId = null, ?int $districtId = null): bool
    {
        $table = match ($table) {
            'delivery_zones', 'districts', 'thanas' => $table,
            default => throw new \InvalidArgumentException('Unknown table'),
        };
        $sql = "SELECT 1 FROM {$table} WHERE name = ?";
        $params = [$name];
        if ($districtId !== null) {
            $sql .= ' AND district_id = ?';
            $params[] = $districtId;
        }
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return db_value($sql . ' LIMIT 1', $params) !== null;
    }

    public static function createZone(string $name, string $charge): int
    {
        $sort = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM delivery_zones');
        return db_insert('delivery_zones', ['name' => $name, 'charge' => $charge, 'sort_order' => $sort]);
    }

    public static function updateZone(int $id, string $name, string $charge): void
    {
        db_update('delivery_zones', ['name' => $name, 'charge' => $charge], ['id' => $id]);
    }

    public static function deleteZone(int $id): void
    {
        db_query('DELETE FROM delivery_zones WHERE id = ? AND NOT EXISTS (SELECT 1 FROM districts WHERE zone_id = ?)', [$id, $id]);
    }

    public static function createDistrict(string $name, int $zoneId): int
    {
        // New districts go after the existing ones (the list is also sorted by name)
        $sort = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM districts');
        return db_insert('districts', ['name' => $name, 'zone_id' => $zoneId, 'sort_order' => $sort, 'is_active' => 1]);
    }

    public static function updateDistrict(int $id, string $name, int $zoneId, bool $active): void
    {
        db_update('districts', ['name' => $name, 'zone_id' => $zoneId, 'is_active' => $active ? 1 : 0], ['id' => $id]);
    }

    /** Thanas go with it (ON DELETE CASCADE); orders keep their snapshot names. */
    public static function deleteDistrict(int $id): void
    {
        db_query('DELETE FROM districts WHERE id = ?', [$id]);
    }

    public static function createThana(int $districtId, string $name): int
    {
        return db_insert('thanas', ['district_id' => $districtId, 'name' => $name, 'is_active' => 1]);
    }

    public static function updateThana(int $id, string $name, bool $active): void
    {
        db_update('thanas', ['name' => $name, 'is_active' => $active ? 1 : 0], ['id' => $id]);
    }

    /** Orders keep their thana name (thana_id becomes NULL). */
    public static function deleteThana(int $id): void
    {
        db_query('DELETE FROM thanas WHERE id = ?', [$id]);
    }

    /** Name for a new/renamed item: trimmed, single spaces, letters required. */
    public static function cleanName(mixed $value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $value));
        return $name !== '' && mb_strlen($name) <= self::NAME_MAX && preg_match('/\p{L}/u', $name)
            && !preg_match('/[\x00-\x1F\x7F<>]/', $name) ? $name : null;
    }
}
