<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;
use App\Services\Steadfast;
use App\Services\SteadfastException;

/**
 * Steadfast courier for orders (Admin -> Courier, and the courier card on an order).
 *
 * - send():    creates the parcel at Steadfast (invoice = order number) and stores
 *              the consignment id, tracking code and status on the order.
 * - refresh(): asks Steadfast for the current status.
 * - webhook(): status pushed by Steadfast (Bearer token, see WebhookController).
 * When Steadfast reports "delivered" the order can be marked Delivered (and paid)
 * automatically. Courier cancellations are only shown: cancelling the order (which
 * returns stock) stays the admin's decision.
 */
final class Courier
{
    public const NAME = 'steadfast';

    /** Steadfast delivery_status => [label, Bootstrap badge class]. */
    public const STATUSES = [
        'in_review'                          => ['In review', 'text-bg-secondary'],
        'pending'                            => ['On the way', 'text-bg-info'],
        'hold'                               => ['On hold', 'text-bg-warning'],
        'delivered_approval_pending'         => ['Delivered (approval pending)', 'text-bg-success'],
        'partial_delivered_approval_pending' => ['Partly delivered (approval pending)', 'text-bg-warning'],
        'cancelled_approval_pending'         => ['Cancelled (approval pending)', 'text-bg-danger'],
        'unknown_approval_pending'           => ['Unknown (approval pending)', 'text-bg-secondary'],
        'delivered'                          => ['Delivered', 'text-bg-success'],
        'partial_delivered'                  => ['Partly delivered', 'text-bg-warning'],
        'cancelled'                          => ['Cancelled / returned', 'text-bg-danger'],
        'exceptional'                        => ['Exceptional (lost / damaged)', 'text-bg-danger'],
        'unknown'                            => ['Unknown', 'text-bg-secondary'],
    ];

    public const ADDRESS_MAX = 490; // Steadfast cuts longer addresses
    public const NOTE_MAX = 200;
    public const BULK_MAX = 20;

    /* --------------------------------------------------------
     | Settings
     * ------------------------------------------------------ */

    public static function settings(): array
    {
        return [
            'enabled'       => Setting::get('steadfast_enabled') === '1',
            'api_key'       => Setting::get('steadfast_api_key'),
            'secret_key'    => Setting::get('steadfast_secret_key'),
            'base_url'      => Setting::get('steadfast_base_url', Steadfast::DEFAULT_BASE_URL) ?: Steadfast::DEFAULT_BASE_URL,
            'webhook_token' => Setting::get('steadfast_webhook_token'),
            'auto_deliver'  => Setting::get('steadfast_auto_deliver', '1') === '1',
            'mark_shipped'  => Setting::get('steadfast_mark_shipped', '1') === '1',
            'default_note'  => Setting::get('steadfast_default_note'),
        ];
    }

    /** Switched on and both keys set. */
    public static function ready(): bool
    {
        $s = self::settings();
        return $s['enabled'] && $s['api_key'] !== '' && $s['secret_key'] !== '';
    }

    public static function client(): Steadfast
    {
        $s = self::settings();
        return new Steadfast($s['api_key'], $s['secret_key'], $s['base_url']);
    }

    /** "abcd…wxyz" for showing a saved key without revealing it. */
    public static function mask(string $value): string
    {
        $len = strlen($value);
        return $len === 0 ? '' : ($len <= 8 ? str_repeat('•', $len) : substr($value, 0, 4) . str_repeat('•', 6) . substr($value, -4));
    }

    public static function trackingUrl(?string $code): ?string
    {
        return $code ? 'https://steadfast.com.bd/t/' . rawurlencode($code) : null;
    }

    public static function statusLabel(?string $status): string
    {
        return $status === null || $status === '' ? '-' : (self::STATUSES[$status][0] ?? ucfirst(str_replace('_', ' ', $status)));
    }

    public static function statusBadge(?string $status): string
    {
        return self::STATUSES[$status ?? ''][1] ?? 'text-bg-light';
    }

    /* --------------------------------------------------------
     | Orders
     * ------------------------------------------------------ */

    /** Why this order can't be sent, or null when it can. */
    public static function cannotSend(array $order): ?string
    {
        if (!self::ready()) {
            return 'Steadfast is not set up yet (Admin -> Courier).';
        }
        if (in_array($order['status'], ['delivered', 'cancelled'], true)) {
            return sprintf('The order is %s.', strtolower(Order::statusLabel($order['status'])));
        }
        if (!empty($order['courier']) && $order['courier'] !== 'sending') {
            return 'The order was already sent to Steadfast.';
        }
        return null;
    }

    /** Address for the courier: address lines + thana + district, one line, max 490 characters. */
    public static function address(array $order): string
    {
        $parts = array_filter(array_map('trim', explode("\n", (string) $order['shipping_address'])), 'strlen');
        foreach (['shipping_thana', 'shipping_district'] as $k) {
            if (!empty($order[$k]) && !str_contains(mb_strtolower(implode(' ', $parts)), mb_strtolower($order[$k]))) {
                $parts[] = $order[$k];
            }
        }
        return mb_substr(implode(', ', $parts), 0, self::ADDRESS_MAX);
    }

    /**
     * Create the parcel at Steadfast.
     *
     * @param string $cod COD amount (DECIMAL string, already validated)
     * @return array{consignment_id: string, tracking_code: string, status: string}
     * @throws SteadfastException|CheckoutException
     */
    public static function send(int $orderId, string $cod, string $note, ?int $adminId, bool $markShipped): array
    {
        // Claim the order so two clicks (or two admins) can't create two parcels
        $claimed = db_query(
            "UPDATE orders SET courier = 'sending', courier_updated_at = NOW()
              WHERE id = ? AND status NOT IN ('delivered', 'cancelled')
                AND (courier IS NULL OR (courier = 'sending' AND courier_updated_at < NOW() - INTERVAL 5 MINUTE))",
            [$orderId]
        )->rowCount();
        if ($claimed !== 1) {
            throw new CheckoutException(['This order was already sent to Steadfast (or is being sent right now).']);
        }

        $order = Order::findWithItems($orderId);
        $items = implode(', ', array_map(static fn ($i) => $i['product_name'] . (!empty($i['variant_label']) ? ' (' . str_replace(['Size: ', 'Colour: ', ' · '], ['', '', ' / '], $i['variant_label']) . ')' : '') . ' x' . (int) $i['quantity'], $order['items']));
        $cents = Money::toCents($cod);
        $payload = array_filter([
            'invoice'           => $order['order_number'],
            'recipient_name'    => mb_substr($order['customer_name'], 0, 100),
            'recipient_phone'   => $order['customer_phone'],
            'recipient_address' => self::address($order),
            'cod_amount'        => $cents % 100 === 0 ? intdiv($cents, 100) : (float) Money::fromCents($cents),
            'note'              => mb_substr($note, 0, self::NOTE_MAX),
            'item_description'  => mb_substr($items, 0, 250),
        ], static fn ($v) => $v !== '' && $v !== null);

        try {
            $result = self::client()->createOrder($payload);
        } catch (\Throwable $e) {
            db_query("UPDATE orders SET courier = NULL, courier_updated_at = NULL WHERE id = ? AND courier = 'sending'", [$orderId]);
            self::log($orderId, 'send', null, null, $e instanceof SteadfastException ? $e->getMessage() : 'Request failed', $payload);
            if (!$e instanceof SteadfastException) {
                log_message('error', 'Steadfast send failed for order ' . $orderId . ': ' . $e->getMessage());
                throw new SteadfastException('Could not send the order to Steadfast. Please try again.');
            }
            throw $e;
        }

        db_update('orders', [
            'courier'                => self::NAME,
            'courier_consignment_id' => $result['consignment_id'],
            'courier_tracking_code'  => $result['tracking_code'] ?: null,
            'courier_status'         => $result['status'],
            'courier_cod_amount'     => Money::fromCents($cents),
            'courier_sent_at'        => date('Y-m-d H:i:s'),
            'courier_updated_at'     => date('Y-m-d H:i:s'),
        ], ['id' => $orderId]);
        self::log($orderId, 'send', $result['consignment_id'], $result['status'], 'Parcel created', $result['raw']);
        Order::addNote($orderId, sprintf('Sent to Steadfast: consignment %s, tracking %s, COD %s', $result['consignment_id'], $result['tracking_code'] ?: '-', format_price($cents)), $adminId);

        if ($markShipped && Order::canTransition($order['status'], 'shipped')) {
            try {
                Order::changeStatus($orderId, 'shipped', $order['status'], 'Handed to Steadfast', $adminId);
            } catch (CheckoutException) {
                // someone changed the status meanwhile: keep theirs
            }
        }
        return $result;
    }

    /** Ask Steadfast for the latest status. Returns the status. */
    public static function refresh(int $orderId, ?int $adminId): string
    {
        $order = db_one('SELECT id, status, courier, courier_consignment_id FROM orders WHERE id = ?', [$orderId]);
        if ($order === null || $order['courier'] !== self::NAME || !$order['courier_consignment_id']) {
            throw new CheckoutException(['This order has not been sent to Steadfast.']);
        }
        $status = self::client()->statusByConsignment($order['courier_consignment_id']);
        self::applyStatus($order, $status, 'check', ['delivery_status' => $status], $adminId);
        return $status;
    }

    /**
     * Status pushed by Steadfast. Returns [HTTP status, response body].
     *
     * @return array{0: int, 1: array}
     */
    public static function webhook(array $payload): array
    {
        $cid = isset($payload['consignment_id']) && is_scalar($payload['consignment_id']) ? trim((string) $payload['consignment_id']) : '';
        $invoice = isset($payload['invoice']) && is_scalar($payload['invoice']) ? trim((string) $payload['invoice']) : '';
        $status = isset($payload['status']) && is_string($payload['status']) ? strtolower(trim($payload['status'])) : '';
        $type = isset($payload['notification_type']) && is_string($payload['notification_type']) ? $payload['notification_type'] : 'delivery_status';

        $order = null;
        if ($cid !== '' && strlen($cid) <= 40) {
            $order = db_one('SELECT id, status, courier, courier_consignment_id FROM orders WHERE courier = ? AND courier_consignment_id = ?', [self::NAME, $cid]);
        }
        if ($order === null && $invoice !== '' && strlen($invoice) <= 40) {
            $order = db_one('SELECT id, status, courier, courier_consignment_id FROM orders WHERE courier = ? AND order_number = ?', [self::NAME, $invoice]);
        }
        if ($order === null) {
            if ($cid === '' && $invoice === '') {
                // Not about one parcel (e.g. a payout or return-list notice): accept and keep a record
                self::log(null, 'webhook', null, null, mb_substr($type, 0, 255), $payload);
                return [200, ['status' => 'success', 'message' => 'Notification received.']];
            }
            self::log(null, 'webhook', $cid ?: null, $status ?: null, 'No matching order', $payload);
            return [404, ['status' => 'error', 'message' => 'Order not found']];
        }

        if ($status !== '' && preg_match('/^[a-z_]{2,60}$/', $status)) {
            self::applyStatus($order, $status, 'webhook', $payload, null);
        } else {
            $message = isset($payload['tracking_message']) && is_string($payload['tracking_message']) ? $payload['tracking_message'] : $type;
            self::log((int) $order['id'], 'webhook', $order['courier_consignment_id'], null, mb_substr($message, 0, 255), $payload);
        }
        return [200, ['status' => 'success', 'message' => 'Webhook received successfully.']];
    }

    /** Store a courier status; "delivered" can complete the order. */
    private static function applyStatus(array $order, string $status, string $source, array $payload, ?int $adminId): void
    {
        $orderId = (int) $order['id'];
        db_update('orders', ['courier_status' => mb_substr($status, 0, 60), 'courier_updated_at' => date('Y-m-d H:i:s')], ['id' => $orderId]);
        $message = isset($payload['tracking_message']) && is_string($payload['tracking_message']) ? $payload['tracking_message'] : self::statusLabel($status);
        self::log($orderId, $source, $order['courier_consignment_id'], $status, mb_substr($message, 0, 255), $payload);

        if ($status === 'delivered' && self::settings()['auto_deliver'] && Order::canTransition($order['status'], 'delivered')) {
            try {
                Order::changeStatus($orderId, 'delivered', $order['status'], 'Steadfast: delivered', $adminId);
            } catch (CheckoutException) {
                // changed meanwhile
            }
        }
    }

    /* --------------------------------------------------------
     | Customer record (fraud check)
     * ------------------------------------------------------ */

    public const FRAUD_STATUS = 'fraud_check';

    /** Ask Steadfast for the customer's delivery record and keep the summary on the order. */
    public static function checkFraud(int $orderId): array
    {
        $order = db_one('SELECT id, customer_phone FROM orders WHERE id = ?', [$orderId]);
        if ($order === null || !preg_match('/^01\d{9}$/', (string) $order['customer_phone'])) {
            throw new CheckoutException(['This order has no valid mobile number to check.']);
        }
        $raw = self::client()->fraudCheck($order['customer_phone']);
        $summary = self::fraudSummary($raw);
        self::log($orderId, 'check', null, self::FRAUD_STATUS, self::fraudLine($summary), $summary);
        return $summary;
    }

    /** The last customer-record check of an order (summary + when), or null. */
    public static function lastFraud(int $orderId): ?array
    {
        $row = db_one('SELECT payload, created_at FROM courier_events WHERE order_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [$orderId, self::FRAUD_STATUS]);
        $summary = $row ? json_decode((string) $row['payload'], true) : null;
        return is_array($summary) ? $summary + ['checked_at' => $row['created_at']] : null;
    }

    /**
     * Read a fraud-check reply. Steadfast does not publish the field names, so
     * common ones are recognised wherever they sit in the JSON; every plain
     * value is also kept in "details" so nothing is hidden.
     *
     * @return array{total: ?int, delivered: ?int, cancelled: ?int, reports: int, rate: ?int, level: string, details: array<string, string>}
     */
    public static function fraudSummary(array $raw): array
    {
        $flat = [];
        $walk = static function (array $a, string $prefix) use (&$walk, &$flat): void {
            foreach ($a as $k => $v) {
                $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                if (is_array($v)) {
                    $flat[$key . '#count'] = count($v);
                    $walk($v, $key);
                } elseif (is_scalar($v) || $v === null) {
                    $flat[$key] = $v;
                }
            }
        };
        $walk($raw, '');

        $find = static function (string $pattern) use ($flat): ?int {
            foreach ($flat as $key => $v) {
                $leaf = strtolower((string) preg_replace('/^.*\./', '', str_replace('#count', '', $key)));
                if (!str_ends_with($key, '#count') && preg_match($pattern, $leaf) && is_numeric($v)) {
                    return max(0, (int) $v);
                }
            }
            return null;
        };
        $delivered = $find('/^(total_?)?(delivered|success|successful|delivery_success)(_?(parcels?|orders?|count))?$/');
        $cancelled = $find('/^(total_?)?(cancel+ed|cancel|returned|returns?|failed)(_?(parcels?|orders?|count))?$/');
        $total = $find('/^(total|total_?(parcels?|orders?|count)|parcels?|orders?)$/');
        if ($total === null && ($delivered !== null || $cancelled !== null)) {
            $total = (int) $delivered + (int) $cancelled;
        }
        // Fraud reports from other merchants: a number, or a list
        $reports = 0;
        foreach ($flat as $key => $v) {
            $leaf = strtolower((string) preg_replace('/^.*\./', '', $key));
            if (preg_match('/(fraud|report)/', $leaf)) {
                if (str_ends_with($key, '#count')) {
                    $reports = max($reports, (int) $v);
                } elseif (is_numeric($v) && !preg_match('/score|rate|ratio|percent/', $leaf)) {
                    $reports = max($reports, (int) $v);
                }
            }
        }
        $rate = $total ? (int) round(((int) $delivered) * 100 / $total) : null;
        $level = match (true) {
            $total === null => 'unknown',
            $total === 0 => 'new',
            $reports > 0 || $rate < 50 => 'high',
            $rate < 80 => 'caution',
            default => 'good',
        };

        $details = [];
        foreach ($flat as $key => $v) {
            if (str_ends_with($key, '#count') || $key === 'status' || count($details) >= 30) {
                continue;
            }
            $details[ucfirst(str_replace(['_', '.'], [' ', ' › '], $key))] = mb_substr(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v, 0, 120);
        }
        return compact('total', 'delivered', 'cancelled', 'reports', 'rate', 'level', 'details');
    }

    /** "8 of 10 delivered (80%), 1 report" */
    public static function fraudLine(array $s): string
    {
        if ($s['total'] === null) {
            return 'Customer record received (see details)';
        }
        if ($s['total'] === 0) {
            return 'No earlier Steadfast parcels for this number';
        }
        return sprintf('%d of %d delivered (%d%%), %d returned', (int) $s['delivered'], $s['total'], (int) $s['rate'], (int) $s['cancelled'])
            . ($s['reports'] ? sprintf(', %d fraud report%s', $s['reports'], $s['reports'] === 1 ? '' : 's') : '');
    }

    /** [label, badge class] for a risk level. */
    public static function fraudLevel(string $level): array
    {
        return [
            'good'    => ['Good customer', 'text-bg-success'],
            'caution' => ['Caution', 'text-bg-warning'],
            'high'    => ['High risk', 'text-bg-danger'],
            'new'     => ['New customer', 'text-bg-secondary'],
            'unknown' => ['See details', 'text-bg-light border'],
        ][$level] ?? ['See details', 'text-bg-light border'];
    }

    /** Courier history of an order, newest first. */
    public static function events(int $orderId, int $limit = 20): array
    {
        return db_all('SELECT source, consignment_id, status, message, created_at FROM courier_events WHERE order_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$orderId]);
    }

    private static function log(?int $orderId, string $source, ?string $cid, ?string $status, ?string $message, array $payload): void
    {
        try {
            db_insert('courier_events', [
                'order_id'       => $orderId,
                'courier'        => self::NAME,
                'source'         => $source,
                'consignment_id' => $cid !== null ? mb_substr($cid, 0, 40) : null,
                'status'         => $status !== null ? mb_substr($status, 0, 60) : null,
                'message'        => $message !== null ? mb_substr($message, 0, 255) : null,
                'payload'        => mb_substr((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 4000),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'courier_events insert failed: ' . $e->getMessage());
        }
    }
}
