<?php
/**
 * Fake Steadfast API for tests (same paths, headers and JSON shapes as
 * https://portal.packzy.com/api/v1). Run: php -S 127.0.0.1:8091 tests/fake_steadfast.php
 * Test helpers: GET /_set?cid=..&status=..  GET /_state  GET /_reset  GET /_fail?next=500|dup
 */
declare(strict_types=1);

const KEY = 'test-api-key-123456';
const SECRET = 'test-secret-key-654321';
$stateFile = __DIR__ . '/fixtures/fake_steadfast.json';
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
$state = is_array($state) ? $state : ['next' => 100001, 'orders' => [], 'fail' => null, 'requests' => []];
$save = static function () use (&$state, $stateFile): void { file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); };
$out = static function (int $code, array $body): never { http_response_code($code); header('Content-Type: application/json'); echo json_encode($body); exit; };

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$rawHeaders = getallheaders();
$headers = array_change_key_case($rawHeaders, CASE_LOWER);
if ($path === '/api/v1/ping') { $out(200, ['status' => 'ok']); } // no keys needed

// test helpers
if ($path === '/_reset') { $state = ['next' => 100001, 'orders' => [], 'fail' => null, 'requests' => []]; $save(); $out(200, ['ok' => true]); }
if ($path === '/_state') { $out(200, $state); }
if ($path === '/_set') { $state['orders'][$_GET['cid']]['status'] = $_GET['status']; $save(); $out(200, ['ok' => true]); }
if ($path === '/_fail') { $state['fail'] = $_GET['next'] ?? null; $save(); $out(200, ['ok' => true]); }

$state['requests'][] = ['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'headers' => ['api-key' => $headers['api-key'] ?? null, 'secret-key' => $headers['secret-key'] ?? null, 'content-type' => $headers['content-type'] ?? null], 'names' => array_keys($rawHeaders)];
$save();

if (($headers['api-key'] ?? '') !== KEY || ($headers['secret-key'] ?? '') !== SECRET) {
    $out(401, ['status' => 401, 'message' => 'Unauthorized']);
}
if (in_array($state['fail'], ['403', '429'], true)) { $c = (int) $state['fail']; $state['fail'] = null; $save(); $out($c, ['status' => $c, 'message' => $c === 429 ? 'Too Many Attempts.' : 'Forbidden']); }
if ($state['fail'] === '500') { $state['fail'] = null; $save(); http_response_code(500); echo '<html>Server error</html>'; exit; }

if (preg_match('#^/api/v1/fraud_check/score/(\d+)$#', $path, $m)) {
    $out(200, match ($m[1]) {
        '01811111111' => ['status' => 200, 'data' => ['summary' => ['total_parcel' => 5, 'success_parcel' => 1, 'cancelled_parcel' => 4], 'fraud_reports' => [['merchant' => 'Shop X', 'details' => 'Refused twice']]]],
        '01999999999' => ['status' => 200, 'total_parcels' => 0, 'total_delivered' => 0, 'total_cancelled' => 0],
        '01700000000' => ['status' => 200, 'score' => 'A', 'message' => 'Looks fine'],
        default => ['status' => 200, 'total_parcels' => 10, 'total_delivered' => 8, 'total_cancelled' => 2, 'total_fraud_reports' => []],
    });
}
if ($path === '/api/v1/get_balance' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $out(200, ['status' => 200, 'current_balance' => 1520]);
}
if (preg_match('#^/api/v1/status_by_cid/(\d+)$#', $path, $m)) {
    $o = $state['orders'][$m[1]] ?? null;
    if ($o === null) { $out(404, ['status' => 404, 'message' => 'Consignment not found']); }
    $out(200, ['status' => 200, 'delivery_status' => $o['status']]);
}
if ($path === '/api/v1/create_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $errors = [];
    foreach (['invoice', 'recipient_name', 'recipient_phone', 'recipient_address', 'cod_amount'] as $f) {
        if (!isset($in[$f]) || $in[$f] === '') { $errors[$f][] = "The $f field is required."; }
    }
    if (isset($in['recipient_phone']) && !preg_match('/^01\d{9}$/', (string) $in['recipient_phone'])) { $errors['recipient_phone'][] = 'The recipient phone must be 11 digits.'; }
    if (isset($in['recipient_address']) && mb_strlen((string) $in['recipient_address']) > 250) { $errors['recipient_address'][] = 'The recipient address may not be greater than 250 characters.'; }
    if (isset($in['cod_amount']) && (!is_numeric($in['cod_amount']) || $in['cod_amount'] < 0)) { $errors['cod_amount'][] = 'The cod amount must be a number.'; }
    foreach ($state['orders'] as $o) { if ($o['invoice'] === ($in['invoice'] ?? null)) { $errors['invoice'][] = 'The invoice has already been taken.'; } }
    if ($state['fail'] === 'dup') { $state['fail'] = null; $errors['invoice'][] = 'The invoice has already been taken.'; }
    if ($errors) { $save(); $out(422, ['status' => 400, 'message' => 'Validation failed', 'errors' => $errors]); }
    $cid = (string) $state['next']++;
    $tracking = strtoupper(substr(md5($cid), 0, 8));
    $state['orders'][$cid] = ['invoice' => $in['invoice'], 'status' => 'in_review', 'tracking' => $tracking, 'payload' => $in];
    $save();
    $out(200, ['status' => 200, 'message' => 'Consignment has been created successfully.', 'consignment' => [
        'consignment_id' => (int) $cid, 'invoice' => $in['invoice'], 'tracking_code' => $tracking,
        'recipient_name' => $in['recipient_name'], 'recipient_phone' => $in['recipient_phone'], 'recipient_address' => $in['recipient_address'],
        'cod_amount' => $in['cod_amount'], 'status' => 'in_review', 'note' => $in['note'] ?? null,
        'created_at' => date('c'), 'updated_at' => date('c'),
    ]]);
}
$out(404, ['status' => 404, 'message' => 'Not found']);
