<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Money;
use App\Models\CheckoutException;
use App\Models\Courier;
use App\Models\Setting;
use App\Services\Steadfast;
use App\Services\SteadfastException;

/**
 * Admin -> Courier (Steadfast): API keys, webhook token, options, connection
 * test; and sending orders / checking their status from the order pages.
 */
final class CourierController
{
    use GuardsPosts;

    /** GET /admin/courier */
    public function edit(): string
    {
        $admin = require_admin();
        admin_headers();
        $s = Courier::settings();
        if ($s['webhook_token'] === '') {
            // a token is needed before Steadfast can be pointed at the webhook
            Setting::set('steadfast_webhook_token', self::newToken());
            $s = Courier::settings();
        }

        return view('admin/courier', [
            'title'      => 'Courier (Steadfast)',
            'admin'      => $admin,
            'settings'   => $s,
            'webhookUrl' => absolute_url(url('webhooks/steadfast')),
            'stats'      => db_one("SELECT COUNT(*) AS sent, COALESCE(SUM(courier_status = 'delivered'), 0) AS delivered,
                                      COALESCE(SUM(courier_status IN ('cancelled','cancelled_approval_pending')), 0) AS cancelled
                                 FROM orders WHERE courier = 'steadfast'"),
            'errors'     => errors(),
        ], 'admin');
    }

    /** POST /admin/courier */
    public function update(): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/courier');

        if (($_POST['action'] ?? '') === 'new_token') {
            Setting::set('steadfast_webhook_token', self::newToken());
            flash('warning', 'A new webhook token was created. Paste it into the Steadfast panel (the old one stops working).');
            redirect('admin/courier');
        }

        $errors = [];
        $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        $current = Courier::settings();

        // Keys: empty = keep the saved one
        $apiKey = $str('api_key');
        $secret = $str('secret_key');
        foreach (['api_key' => $apiKey, 'secret_key' => $secret] as $k => $v) {
            if ($v !== '' && !preg_match('/^[A-Za-z0-9._\-]{8,128}$/', $v)) {
                $errors[$k] = 'Paste the key exactly as shown in the Steadfast panel (letters and numbers).';
            }
        }
        $baseUrl = rtrim($str('base_url'), '/') ?: Steadfast::DEFAULT_BASE_URL;
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $localTest = config('app.env') !== 'production' && in_array($host, ['localhost', '127.0.0.1'], true);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || (!str_starts_with($baseUrl, 'https://') && !$localTest)) {
            $errors['base_url'] = 'Use the https:// API address from Steadfast (default: ' . Steadfast::DEFAULT_BASE_URL . ').';
        }
        $note = mb_substr((string) preg_replace('/\s+/u', ' ', $str('default_note')), 0, Courier::NOTE_MAX);
        if (preg_match('/[<>\x00-\x1F]/', $note)) {
            $errors['default_note'] = 'No < or > in the note.';
        }
        $enabled = !empty($_POST['enabled']);
        if ($enabled && ($apiKey ?: $current['api_key']) === '' ) {
            $errors['api_key'] = 'Enter the API key to switch Steadfast on.';
        }
        if ($enabled && ($secret ?: $current['secret_key']) === '') {
            $errors['secret_key'] = 'Enter the secret key to switch Steadfast on.';
        }
        if ($errors) {
            with_input(['_submitted' => '1', 'base_url' => $baseUrl, 'default_note' => $note, 'enabled' => $enabled ? '1' : '0',
                'auto_deliver' => !empty($_POST['auto_deliver']) ? '1' : '0', 'mark_shipped' => !empty($_POST['mark_shipped']) ? '1' : '0'], $errors);
            flash('error', 'Please fix the highlighted fields.');
            redirect('admin/courier');
        }

        $save = [
            'steadfast_enabled'      => $enabled ? '1' : '0',
            'steadfast_base_url'     => $baseUrl,
            'steadfast_auto_deliver' => !empty($_POST['auto_deliver']) ? '1' : '0',
            'steadfast_mark_shipped' => !empty($_POST['mark_shipped']) ? '1' : '0',
            'steadfast_default_note' => $note,
        ];
        if ($apiKey !== '') {
            $save['steadfast_api_key'] = $apiKey;
        }
        if ($secret !== '') {
            $save['steadfast_secret_key'] = $secret;
        }
        if (!empty($_POST['clear_keys'])) {
            $save['steadfast_api_key'] = '';
            $save['steadfast_secret_key'] = '';
            $save['steadfast_enabled'] = '0';
        }
        db_transaction(static function () use ($save): void {
            foreach ($save as $k => $v) {
                Setting::set($k, $v);
            }
        });
        flash('success', 'Courier settings saved.' . (!empty($_POST['clear_keys']) ? ' Keys removed and Steadfast switched off.' : ''));
        redirect('admin/courier');
    }

    /** POST /admin/courier/test - checks the keys by reading the balance */
    public function test(): never
    {
        require_admin();
        admin_headers();
        self::guardPost('admin/courier');
        if (!Steadfast::ping(Courier::settings()['base_url'])) {
            flash('error', 'Could not reach Steadfast at ' . Courier::settings()['base_url'] . '. Check the API address, or ask your host whether outgoing connections are allowed.');
            redirect('admin/courier');
        }
        try {
            $balance = Courier::client()->balance();
            flash('success', 'Connected to Steadfast. Current balance: ' . format_price(is_numeric($balance) ? number_format((float) $balance, 2, '.', '') : '0') . '.');
        } catch (SteadfastException $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin/courier');
    }

    /** POST /admin/orders/courier/{id}  (action = send | refresh) */
    public function order(string $id): never
    {
        $admin = require_admin();
        admin_headers();
        $orderId = self::routeId($id) ?? 0;
        $back = 'admin/orders/view/' . $orderId . '#courier';
        self::guardPost($back);
        $order = $orderId ? db_one('SELECT * FROM orders WHERE id = ?', [$orderId]) : null;
        if ($order === null) {
            flash('error', 'That order does not exist.');
            redirect('admin/orders');
        }

        try {
            if (($_POST['action'] ?? '') === 'fraud') {
                $s = Courier::checkFraud($orderId);
                flash($s['level'] === 'high' ? 'warning' : 'success', 'Customer record: ' . Courier::fraudLine($s) . '.');
                redirect('admin/orders/view/' . $orderId . '#customer-record');
            }
            if (($_POST['action'] ?? '') === 'refresh') {
                $status = Courier::refresh($orderId, (int) $admin['id']);
                flash('success', 'Steadfast status: ' . Courier::statusLabel($status) . '.');
                redirect($back);
            }
            if ($blocked = Courier::cannotSend($order)) {
                flash('error', $blocked);
                redirect($back);
            }
            $cod = self::cod($_POST['cod_amount'] ?? '', $order);
            if ($cod === null) {
                with_input(['cod_amount' => is_string($_POST['cod_amount'] ?? null) ? mb_substr($_POST['cod_amount'], 0, 20) : ''],
                    ['cod_amount' => 'Enter the amount to collect, from 0 up to the order total.']);
                flash('error', 'Please check the COD amount.');
                redirect($back);
            }
            $note = is_string($_POST['courier_note'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F<>]+/', ' ', $_POST['courier_note'])) : '';
            $r = Courier::send($orderId, $cod, $note, (int) $admin['id'], !empty($_POST['mark_shipped']));
            flash('success', sprintf('Sent to Steadfast. Consignment %s, tracking %s.', $r['consignment_id'], $r['tracking_code'] ?: '-'));
        } catch (SteadfastException $e) {
            flash('error', $e->getMessage());
        } catch (CheckoutException $e) {
            flash('error', implode(' ', $e->messages()));
        }
        redirect($back);
    }

    /** POST /admin/orders/courier-bulk  (ids[]) - send several orders with their full total as COD */
    public function bulk(): never
    {
        $admin = require_admin();
        admin_headers();
        $back = safe_return_path(is_string($_POST['return'] ?? null) && str_starts_with($_POST['return'], '/admin/orders') ? $_POST['return'] : null, 'admin/orders');
        self::guardPost($back);

        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => is_string($v) && ctype_digit($v) ? (int) $v : 0, (array) ($_POST['ids'] ?? [])))));
        if (!$ids) {
            flash('error', 'Tick the orders to send first.');
            redirect($back);
        }
        if (count($ids) > Courier::BULK_MAX) {
            flash('error', sprintf('Send up to %d orders at a time.', Courier::BULK_MAX));
            redirect($back);
        }
        if (!Courier::ready()) {
            flash('error', 'Steadfast is not set up yet (Admin -> Courier).');
            redirect($back);
        }
        @set_time_limit(30 + 15 * count($ids));

        $ok = $failed = [];
        $note = Courier::settings()['default_note'];
        $markShipped = Courier::settings()['mark_shipped'];
        foreach ($ids as $orderId) {
            $order = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            if ($order === null) {
                continue;
            }
            if ($blocked = Courier::cannotSend($order)) {
                $failed[] = $order['order_number'] . ': ' . $blocked;
                continue;
            }
            $cod = $order['payment_status'] === 'paid' ? '0.00' : Money::fromCents(Money::toCents($order['grand_total']));
            try {
                $r = Courier::send($orderId, $cod, $note, (int) $admin['id'], $markShipped);
                $ok[] = $order['order_number'] . ' (' . $r['consignment_id'] . ')';
            } catch (SteadfastException $e) {
                $failed[] = $order['order_number'] . ': ' . $e->getMessage();
            } catch (CheckoutException $e) {
                $failed[] = $order['order_number'] . ': ' . implode(' ', $e->messages());
            }
        }
        if ($ok) {
            flash('success', sprintf('Sent %d order%s to Steadfast: %s.', count($ok), count($ok) === 1 ? '' : 's', implode(', ', $ok)));
        }
        if ($failed) {
            flash($ok ? 'warning' : 'error', 'Not sent - ' . implode(' | ', array_slice($failed, 0, 10)));
        }
        redirect($back);
    }

    /* --------------------------------------------------------
     | Helpers
     * ------------------------------------------------------ */

    /** COD amount: 0 .. order total (DECIMAL string), or null. */
    private static function cod(mixed $input, array $order): ?string
    {
        if (!is_string($input) || trim($input) === '' || str_starts_with(trim($input), '-')) {
            return null;
        }
        $amount = Money::parse($input);
        if ($amount === null || Money::toCents($amount) > Money::toCents($order['grand_total'])) {
            return null;
        }
        return $amount;
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
