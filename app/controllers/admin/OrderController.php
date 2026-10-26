<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CheckoutException;
use App\Models\Order;
use DateTimeImmutable;

/**
 * Admin order management: list/search/filter, details, status changes.
 */
final class OrderController
{
    use GuardsPosts;

    private const PER_PAGE = 20;

    /** GET /admin/orders?q=&status=&from=Y-m-d&to=Y-m-d&page= */
    public function index(): string
    {
        $admin = require_admin();
        admin_headers();

        [$filters, $dateError] = self::filters($_GET);
        $page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? (int) $_GET['page'] : 1;

        return view('admin/orders/index', [
            'title'     => 'Orders',
            'admin'     => $admin,
            'filters'   => $filters,
            'dateError' => $dateError,
            'result'    => Order::adminPaginate($filters, $page, self::PER_PAGE),
            'counts'    => Order::statusCounts($filters),
        ], 'admin');
    }

    /** GET /admin/orders/view/{id} */
    public function view(string $id): string
    {
        $admin = require_admin();
        admin_headers();
        $order = self::findOrBack($id);

        return view('admin/orders/view', [
            'title'   => 'Order ' . $order['order_number'],
            'admin'   => $admin,
            'order'   => $order,
            'history' => Order::history((int) $order['id']),
            'errors'  => errors(),
        ], 'admin');
    }

    /** POST /admin/orders/status/{id}  (status, current_status, note) */
    public function updateStatus(string $id): never
    {
        $admin = require_admin();
        admin_headers();
        $order = self::findOrBack($id);
        $url = 'admin/orders/view/' . $order['id'];
        self::guardPost($url);

        $to = $_POST['status'] ?? '';
        $expected = $_POST['current_status'] ?? '';
        $note = $_POST['note'] ?? '';
        if (!is_string($to) || !is_string($expected) || !is_string($note) || !mb_check_encoding($note, 'UTF-8')) {
            flash('error', 'The form was not valid. Please try again.');
            redirect($url);
        }
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if (mb_strlen($note) > Order::NOTE_MAX) {
            with_input(['note' => mb_substr($note, 0, Order::NOTE_MAX + 100)], ['note' => sprintf('Note must be %d characters or fewer.', Order::NOTE_MAX)]);
            flash('error', 'Please check the note.');
            redirect($url);
        }
        if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $note)) {
            flash('error', 'The note contains invalid characters.');
            redirect($url);
        }

        try {
            Order::changeStatus((int) $order['id'], $to, $expected, $note, (int) $admin['id']);
        } catch (CheckoutException $e) {
            with_input(['note' => $note]);
            flash('error', implode(' ', $e->messages()));
            redirect($url);
        }

        $message = sprintf('Order %s is now %s.', $order['order_number'], Order::statusLabel($to));
        if ($to === 'cancelled') {
            $message .= ' Its items were returned to stock.';
        } elseif ($to === 'delivered' && $order['payment_method'] === Order::PAYMENT_COD) {
            $message .= ' Payment marked as received.';
        }
        flash('success', $message);
        redirect($url);
    }

    /* --------------------------------------------------------
     | Helpers
     * ------------------------------------------------------ */

    private static function findOrBack(string $id): array
    {
        $orderId = self::routeId($id);
        $order = $orderId ? Order::findWithItems($orderId) : null;
        if ($order === null) {
            flash('error', 'That order does not exist.');
            redirect('admin/orders');
        }
        return $order;
    }

    /**
     * Only known filters with valid values.
     *
     * @return array{0: array, 1: bool} [filters, whether a date was rejected]
     */
    private static function filters(array $input): array
    {
        $str = static fn (string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
        $dateError = false;
        $date = static function (string $value) use (&$dateError): string {
            if ($value === '') {
                return '';
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($d === false || $d->format('Y-m-d') !== $value) {
                $dateError = true;
                return '';
            }
            return $value;
        };

        $q = $str('q');
        $filters = [
            'q'      => mb_check_encoding($q, 'UTF-8') ? mb_substr($q, 0, 100) : '',
            'status' => isset(Order::STATUSES[$str('status')]) ? $str('status') : '',
            'from'   => $date($str('from')),
            'to'     => $date($str('to')),
        ];
        // A reversed range is almost certainly a slip: swap it
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
        }
        return [$filters, $dateError];
    }
}
