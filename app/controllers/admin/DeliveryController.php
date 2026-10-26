<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Money;
use App\Models\Location;
use App\Models\Setting;

/**
 * Admin -> Delivery: zone charges (e.g. Inside Dhaka 70, Outside Dhaka 130),
 * the free-delivery amount, districts (each belongs to a zone) and the
 * thanas of each district. Checkout reads all of this from the database.
 */
final class DeliveryController
{
    use GuardsPosts;

    /** Highest delivery charge / free-delivery amount accepted. */
    private const CHARGE_MAX_CENTS = 9_999_999; // 99,999.99

    /** Most thanas added in one go. */
    private const BULK_MAX = 100;

    /** GET /admin/delivery */
    public function index(): string
    {
        $admin = require_admin();
        admin_headers();

        return view('admin/delivery/index', [
            'title'     => 'Delivery',
            'admin'     => $admin,
            'zones'     => Location::zones(),
            'districts' => Location::districts(),
            'freeFrom'  => Setting::freeDeliveryFrom(),
            'errors'    => errors(),
        ], 'admin');
    }

    /** POST /admin/delivery/zones  (zone_name[id], zone_charge[id]; new_zone_name, new_zone_charge) */
    public function saveZones(): never
    {
        require_admin();
        admin_headers();
        $back = 'admin/delivery';
        self::guardPost($back);

        $names = is_array($_POST['zone_name'] ?? null) ? $_POST['zone_name'] : [];
        $charges = is_array($_POST['zone_charge'] ?? null) ? $_POST['zone_charge'] : [];
        $updates = [];
        foreach (Location::zones() as $zone) {
            $id = (int) $zone['id'];
            if (!isset($names[$id], $charges[$id])) {
                continue;
            }
            $name = Location::cleanName($names[$id]);
            $charge = self::charge($charges[$id]);
            if ($name === null) {
                self::fail($back, sprintf('Enter a name for the zone "%s" (letters, up to %d characters).', $zone['name'], Location::NAME_MAX));
            }
            if ($charge === null) {
                self::fail($back, sprintf('Enter a delivery charge for "%s", e.g. 70 or 130.', $name));
            }
            if (Location::nameTaken('delivery_zones', $name, $id) || in_array(mb_strtolower($name), array_map(static fn ($u) => mb_strtolower($u[0]), $updates), true)) {
                self::fail($back, sprintf('There is already a zone called "%s".', $name));
            }
            $updates[$id] = [$name, $charge];
        }

        $newName = trim((string) (is_string($_POST['new_zone_name'] ?? null) ? $_POST['new_zone_name'] : ''));
        $newCharge = trim((string) (is_string($_POST['new_zone_charge'] ?? null) ? $_POST['new_zone_charge'] : ''));
        $new = null;
        if ($newName !== '' || $newCharge !== '') {
            $name = Location::cleanName($newName);
            $charge = self::charge($newCharge);
            if ($name === null) {
                self::fail($back, 'Enter a name for the new zone, e.g. "Dhaka suburbs".');
            }
            if ($charge === null) {
                self::fail($back, 'Enter a delivery charge for the new zone, e.g. 100.');
            }
            if (Location::nameTaken('delivery_zones', $name) || in_array(mb_strtolower($name), array_map(static fn ($u) => mb_strtolower($u[0]), $updates), true)) {
                self::fail($back, sprintf('There is already a zone called "%s".', $name));
            }
            $new = [$name, $charge];
        }

        db_transaction(static function () use ($updates, $new): void {
            foreach ($updates as $id => [$name, $charge]) {
                Location::updateZone($id, $name, $charge);
            }
            if ($new) {
                Location::createZone(...$new);
            }
        });

        flash('success', $new ? sprintf('Delivery charges saved. Zone "%s" added - assign districts to it below.', $new[0]) : 'Delivery charges saved.');
        redirect($back);
    }

    /** POST /admin/delivery/zones/delete/{id}  (only zones without districts) */
    public function deleteZone(string $id): never
    {
        require_admin();
        admin_headers();
        $back = 'admin/delivery';
        self::guardPost($back);

        $zone = ($zoneId = self::routeId($id)) ? Location::zone($zoneId) : null;
        if ($zone === null) {
            self::fail($back, 'That zone no longer exists.');
        }
        if ((int) db_value('SELECT COUNT(*) FROM districts WHERE zone_id = ?', [$zoneId]) > 0) {
            self::fail($back, sprintf('"%s" still has districts. Move them to another zone first.', $zone['name']));
        }
        Location::deleteZone($zoneId);
        flash('success', sprintf('Zone "%s" was deleted.', $zone['name']));
        redirect($back);
    }

    /** POST /admin/delivery/settings  (free_delivery_from) */
    public function saveSettings(): never
    {
        require_admin();
        admin_headers();
        $back = 'admin/delivery';
        self::guardPost($back);

        $input = trim((string) (is_string($_POST['free_delivery_from'] ?? null) ? $_POST['free_delivery_from'] : ''));
        $amount = $input === '' ? '0.00' : self::charge($input);
        if ($amount === null) {
            self::fail($back, 'Enter the order amount for free delivery, e.g. 3000 - or 0 for never.');
        }
        Setting::set('free_delivery_from', $amount);
        flash('success', Money::toCents($amount) > 0
            ? sprintf('Delivery is now free on orders of %s or more.', format_price($amount))
            : 'Free delivery is off: every order pays its zone charge.');
        redirect($back);
    }

    /** POST /admin/delivery/districts/create  (name, zone_id) */
    public function createDistrict(): never
    {
        require_admin();
        admin_headers();
        $back = 'admin/delivery';
        self::guardPost($back);

        $name = Location::cleanName($_POST['name'] ?? '');
        $zone = self::zoneFromPost();
        if ($name === null) {
            self::fail($back, sprintf('Enter the district name (letters, up to %d characters).', Location::NAME_MAX));
        }
        if ($zone === null) {
            self::fail($back, 'Choose the delivery zone of the district.');
        }
        if (Location::nameTaken('districts', $name)) {
            self::fail($back, sprintf('The district "%s" already exists.', $name));
        }
        $id = Location::createDistrict($name, (int) $zone['id']);
        flash('success', sprintf('District "%s" added (%s, %s). Now add its thanas.', $name, $zone['name'], format_price($zone['charge'])));
        redirect('admin/delivery/districts/' . $id);
    }

    /** GET /admin/delivery/districts/{id} - the district and its thanas */
    public function district(string $id): string
    {
        $admin = require_admin();
        admin_headers();
        $district = self::findDistrictOrBack($id);

        return view('admin/delivery/district', [
            'title'      => $district['name'] . ' - thanas',
            'admin'      => $admin,
            'district'   => $district,
            'thanas'     => Location::thanas((int) $district['id']),
            'zones'      => Location::zones(),
            'orderCount' => Location::districtOrderCount((int) $district['id']),
            'errors'     => errors(),
        ], 'admin');
    }

    /** POST /admin/delivery/districts/{id}  (name, zone_id, is_active) */
    public function updateDistrict(string $id): never
    {
        require_admin();
        admin_headers();
        $district = self::findDistrictOrBack($id);
        $districtId = (int) $district['id'];
        $back = self::safeBack('admin/delivery/districts/' . $districtId);
        self::guardPost($back);

        $name = Location::cleanName($_POST['name'] ?? $district['name']);
        $zone = isset($_POST['zone_id']) ? self::zoneFromPost() : Location::zone((int) $district['zone_id']);
        $active = isset($_POST['is_active']) ? $_POST['is_active'] === '1' : (bool) $district['is_active'];
        if ($name === null) {
            self::fail($back, sprintf('Enter the district name (letters, up to %d characters).', Location::NAME_MAX));
        }
        if ($zone === null) {
            self::fail($back, 'Choose the delivery zone of the district.');
        }
        if (Location::nameTaken('districts', $name, $districtId)) {
            self::fail($back, sprintf('The district "%s" already exists.', $name));
        }
        Location::updateDistrict($districtId, $name, (int) $zone['id'], $active);
        flash('success', sprintf('"%s" saved: %s (%s), %s at checkout.', $name, $zone['name'], format_price($zone['charge']), $active ? 'shown' : 'hidden'));
        redirect($back);
    }

    /** POST /admin/delivery/districts/delete/{id}  (only without orders) */
    public function deleteDistrict(string $id): never
    {
        require_admin();
        admin_headers();
        $district = self::findDistrictOrBack($id);
        $districtId = (int) $district['id'];
        self::guardPost('admin/delivery/districts/' . $districtId);

        if (Location::districtOrderCount($districtId) > 0) {
            self::fail('admin/delivery/districts/' . $districtId, sprintf('"%s" has orders, so it cannot be deleted. Hide it from checkout instead.', $district['name']));
        }
        Location::deleteDistrict($districtId);
        flash('success', sprintf('District "%s" and its thanas were deleted.', $district['name']));
        redirect('admin/delivery');
    }

    /** POST /admin/delivery/districts/{id}/thanas  (names: one per line or comma separated) */
    public function createThanas(string $id): never
    {
        require_admin();
        admin_headers();
        $district = self::findDistrictOrBack($id);
        $districtId = (int) $district['id'];
        $back = 'admin/delivery/districts/' . $districtId;
        self::guardPost($back);

        $raw = is_string($_POST['names'] ?? null) && mb_check_encoding($_POST['names'], 'UTF-8') ? $_POST['names'] : '';
        $names = [];
        foreach (preg_split('/[\r\n,]+/', $raw) as $part) {
            if (trim($part) === '') {
                continue;
            }
            $name = Location::cleanName($part);
            if ($name === null) {
                self::fail($back, sprintf('"%s" is not a valid thana name (letters, up to %d characters).', mb_substr(trim($part), 0, 60), Location::NAME_MAX));
            }
            $names[mb_strtolower($name)] ??= $name;
        }
        if (!$names) {
            self::fail($back, 'Enter at least one thana name.');
        }
        if (count($names) > self::BULK_MAX) {
            self::fail($back, sprintf('Add up to %d thanas at a time.', self::BULK_MAX));
        }

        $added = $skipped = [];
        db_transaction(static function () use ($names, $districtId, &$added, &$skipped): void {
            foreach ($names as $name) {
                if (Location::nameTaken('thanas', $name, null, $districtId)) {
                    $skipped[] = $name;
                    continue;
                }
                Location::createThana($districtId, $name);
                $added[] = $name;
            }
        });

        if (!$added) {
            self::fail($back, sprintf('%s already in %s.', implode(', ', $skipped), $district['name']));
        }
        flash('success', sprintf('Added %d thana%s to %s: %s.', count($added), count($added) === 1 ? '' : 's', $district['name'], implode(', ', $added))
            . ($skipped ? ' Already there: ' . implode(', ', $skipped) . '.' : ''));
        redirect($back);
    }

    /** POST /admin/delivery/thanas/{id}  (action = save | toggle | delete; name) */
    public function updateThana(string $id): never
    {
        require_admin();
        admin_headers();
        $thana = ($thanaId = self::routeId($id)) ? Location::thana($thanaId) : null;
        if ($thana === null) {
            self::fail('admin/delivery', 'That thana no longer exists.');
        }
        $back = 'admin/delivery/districts/' . (int) $thana['district_id'];
        self::guardPost($back);

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete') {
            Location::deleteThana($thanaId);
            flash('success', sprintf('Thana "%s" was deleted. Past orders keep its name.', $thana['name']));
            redirect($back);
        }
        if ($action === 'toggle') {
            Location::updateThana($thanaId, $thana['name'], !$thana['is_active']);
            flash('success', sprintf('"%s" is now %s at checkout.', $thana['name'], $thana['is_active'] ? 'hidden' : 'shown'));
            redirect($back);
        }
        if ($action !== 'save') {
            self::fail($back, 'Unknown action.');
        }

        $name = Location::cleanName($_POST['name'] ?? '');
        if ($name === null) {
            self::fail($back, sprintf('Enter the thana name (letters, up to %d characters).', Location::NAME_MAX));
        }
        if (Location::nameTaken('thanas', $name, $thanaId, (int) $thana['district_id'])) {
            self::fail($back, sprintf('"%s" already exists in this district.', $name));
        }
        Location::updateThana($thanaId, $name, (bool) $thana['is_active']);
        flash('success', sprintf('Thana renamed to "%s".', $name));
        redirect($back);
    }

    /* --------------------------------------------------------
     | Helpers
     * ------------------------------------------------------ */

    /** A charge like "70" or "130.50" as a DECIMAL string (0 allowed), or null. */
    private static function charge(mixed $input): ?string
    {
        if (!is_string($input) || trim($input) === '' || str_starts_with(trim($input), '-')) {
            return null;
        }
        $amount = Money::parse($input);
        return $amount !== null && Money::toCents($amount) <= self::CHARGE_MAX_CENTS ? $amount : null;
    }

    private static function zoneFromPost(): ?array
    {
        $id = is_string($_POST['zone_id'] ?? null) && ctype_digit($_POST['zone_id']) ? (int) $_POST['zone_id'] : 0;
        return $id > 0 ? Location::zone($id) : null;
    }

    private static function findDistrictOrBack(string $id): array
    {
        $district = ($districtId = self::routeId($id)) ? Location::district($districtId) : null;
        if ($district === null) {
            flash('error', 'That district no longer exists.');
            redirect('admin/delivery');
        }
        return $district;
    }

    /** Where to go after saving a district: the list or the district page (never elsewhere). */
    private static function safeBack(string $districtPage): string
    {
        return ($_POST['return'] ?? '') === 'list' ? 'admin/delivery' : $districtPage;
    }

    private static function fail(string $back, string $message): never
    {
        flash('error', $message);
        redirect($back);
    }
}
