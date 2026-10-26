<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Courier;
use App\Models\Setting;

/**
 * Incoming webhooks.
 *
 * POST /webhooks/steadfast - Steadfast sends parcel status updates as JSON with
 * "Authorization: Bearer <token>" (the token shown in Admin -> Courier).
 */
final class WebhookController
{
    public function steadfast(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');

        $token = Setting::get('steadfast_webhook_token');
        $sent = self::bearerToken();
        if ($token === '' || $sent === null || !hash_equals($token, $sent)) {
            self::reply(401, ['status' => 'error', 'message' => 'Unauthorized']);
        }

        $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            parse_str($raw, $payload); // form-encoded fallback
        }
        if (!is_array($payload) || !$payload) {
            self::reply(400, ['status' => 'error', 'message' => 'Invalid payload']);
        }

        [$code, $body] = Courier::webhook($payload);
        self::reply($code, $body);
    }

    /** The Bearer token, wherever the web server put the Authorization header. */
    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($header === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                }
            }
        }
        return is_string($header) && preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : null;
    }

    private static function reply(int $code, array $body): never
    {
        http_response_code($code);
        echo json_encode($body);
        exit;
    }
}
