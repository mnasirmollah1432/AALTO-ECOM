<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Steadfast Courier API client (https://portal.packzy.com/api/v1).
 *
 * Auth: "Api-Key" and "Secret-Key" headers on every call (Steadfast merchant panel -> API keys).
 * Endpoints used: POST /create_order, GET /status_by_cid/{id}, GET /get_balance.
 * Every call throws SteadfastException with a message safe to show the admin;
 * keys are never included in messages or logs.
 */
final class Steadfast
{
    public const DEFAULT_BASE_URL = 'https://portal.packzy.com/api/v1';
    private const TIMEOUT = 25;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        if ($apiKey === '' || $secretKey === '') {
            throw new SteadfastException('Steadfast API key and secret key are not set (Admin -> Courier).');
        }
    }

    /**
     * Create a parcel.
     *
     * @param array{invoice: string, recipient_name: string, recipient_phone: string, recipient_address: string,
     *              cod_amount: int|float, note?: string, item_description?: string, alternative_phone?: string} $order
     * @return array{consignment_id: string, tracking_code: string, status: string, raw: array}
     */
    public function createOrder(array $order): array
    {
        $res = $this->request('POST', '/create_order', $order);
        $c = $res['consignment'] ?? null;
        if (!is_array($c) || empty($c['consignment_id'])) {
            throw new SteadfastException(self::errorText($res, 'Steadfast did not create the parcel.'));
        }
        return [
            'consignment_id' => (string) $c['consignment_id'],
            'tracking_code'  => (string) ($c['tracking_code'] ?? ''),
            'status'         => (string) ($c['status'] ?? 'in_review'),
            'raw'            => $res,
        ];
    }

    /** Current delivery status of a consignment, e.g. "in_review", "delivered". */
    public function statusByConsignment(string $consignmentId): string
    {
        $res = $this->request('GET', '/status_by_cid/' . rawurlencode($consignmentId));
        if (!isset($res['delivery_status']) || !is_string($res['delivery_status'])) {
            throw new SteadfastException(self::errorText($res, 'Steadfast did not return a status.'));
        }
        return $res['delivery_status'];
    }

    /**
     * A customer's delivery record by phone (GET /fraud_check/score/{phone}):
     * parcels delivered / returned and reports from other merchants. Returned as
     * sent by Steadfast; App\Models\Courier::fraudSummary() reads it.
     */
    public function fraudCheck(string $phone): array
    {
        return $this->request('GET', '/fraud_check/score/' . rawurlencode($phone));
    }

    /** Merchant balance (DECIMAL-like string). */
    public function balance(): string
    {
        $res = $this->request('GET', '/get_balance');
        if (!array_key_exists('current_balance', $res)) {
            throw new SteadfastException(self::errorText($res, 'Steadfast did not return the balance.'));
        }
        return (string) $res['current_balance'];
    }

    /**
     * GET /ping - needs no keys. True when Steadfast answers, so a later
     * failure is about the keys or the request, not the connection.
     */
    public static function ping(string $baseUrl = self::DEFAULT_BASE_URL): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init(rtrim($baseUrl, '/') . '/ping');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT      => 'AaltoMart/1.0',
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $raw !== false && $status >= 200 && $status < 300;
    }

    /* --------------------------------------------------------
     | HTTP
     * ------------------------------------------------------ */

    private function request(string $method, string $path, ?array $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new SteadfastException('The PHP curl extension is not enabled on this server (cPanel -> Select PHP Version -> Extensions -> curl).');
        }
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Secret-Key: ' . $this->secretKey,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT      => 'AaltoMart/1.0',
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new SteadfastException('Could not reach Steadfast (' . ($error !== '' ? $error : 'connection failed') . '). Please try again.');
        }
        // Steadfast: 10 failed logins in 5 minutes lock the keys for 60 minutes - say so clearly
        if ($status === 401) {
            throw new SteadfastException('Steadfast refused the API key / secret key. Check them in Admin -> Courier before trying again (10 failed tries lock the keys for 60 minutes).');
        }
        if ($status === 403) {
            throw new SteadfastException('Steadfast: your merchant account is inactive or its ownership (KYC) details need resubmitting. Please contact Steadfast.');
        }
        if ($status === 429) {
            throw new SteadfastException('Steadfast: too many requests or failed attempts - they block this server for up to 60 minutes. Check the keys, then try again later.');
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new SteadfastException(sprintf('Steadfast sent an unexpected reply (HTTP %d).', $status) . ($status >= 500 ? ' Please try again - a retry cannot book the parcel twice.' : ''));
        }
        if ($status >= 400 || (isset($data['status']) && (int) $data['status'] >= 400)) {
            throw new SteadfastException(self::errorText($data, sprintf('Steadfast returned an error (HTTP %d).', $status)));
        }
        return $data;
    }

    /** Readable message from a Steadfast error body ("message" or validation "errors"). */
    private static function errorText(array $data, string $fallback): string
    {
        $parts = [];
        if (!empty($data['message']) && is_string($data['message'])) {
            $parts[] = $data['message'];
        }
        if (!empty($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $field => $msgs) {
                foreach ((array) $msgs as $m) {
                    if (is_string($m)) {
                        $parts[] = (is_string($field) ? $field . ': ' : '') . $m;
                    }
                }
            }
        }
        $text = $parts ? implode(' ', array_unique($parts)) : $fallback;
        return 'Steadfast: ' . mb_substr(strip_tags($text), 0, 300);
    }
}
