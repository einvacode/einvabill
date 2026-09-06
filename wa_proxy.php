<?php
/**
 * WhatsApp Gateway PHP Proxy
 * Bridges the browser and the local Node.js gateway (wa-gateway/server.js)
 * so the browser never talks to port 3000 directly.
 *
 * Security:
 *  - requires a logged-in session (admin, partner or collector);
 *  - only the known gateway endpoints can be reached;
 *  - POST requests need the CSRF token (layout.php adds it to fetch());
 *  - the client id (cid) is derived from the session, never from the
 *    request, so a user can only touch their own WhatsApp device;
 *  - every call to Node carries the shared secret from
 *    wa-gateway/.gateway_token, which Node generates on first start.
 */
require_once __DIR__ . '/app/init.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => true, 'connected' => false, 'message' => 'Silakan login terlebih dahulu.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? 'status';
$allowed_paths = ['status' => 'GET', 'qr' => 'GET', 'logs' => 'GET', 'send' => 'POST', 'logout' => 'POST'];
if (!isset($allowed_paths[$path]) || $allowed_paths[$path] !== $method) {
    http_response_code(404);
    echo json_encode(['error' => true, 'message' => 'Endpoint gateway tidak dikenal.']);
    exit;
}
if ($method === 'POST') {
    csrf_require();
}

// The device id is a function of who is logged in (same rule as layout.php).
$session_cid = ($_SESSION['user_role'] === 'admin')
    ? 'admin_' . intval($_SESSION['tenant_id'] ?? 1)
    : 'u_' . intval($_SESSION['user_id']);

$params = $_GET;
unset($params['path']);
$params['cid'] = $session_cid;
$query = http_build_query($params);

$body = '';
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];
    $json['cid'] = $session_cid;
    $body = json_encode($json);
}

$token_file = __DIR__ . '/wa-gateway/.gateway_token';
$gateway_token = is_readable($token_file) ? trim((string) file_get_contents($token_file)) : '';
if ($gateway_token === '') {
    http_response_code(503);
    echo json_encode([
        'error' => true,
        'connected' => false,
        'message' => 'Gateway belum dijalankan',
        'debug' => ['hint' => 'Jalankan "node server.js" di folder wa-gateway; file .gateway_token dibuat otomatis saat pertama kali start.']
    ]);
    exit;
}

$url = 'http://127.0.0.1:3000/' . $path . ($query ? '?' . $query : '');
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$headers = ['X-Gateway-Token: ' . $gateway_token];
if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $headers[] = 'Content-Type: application/json';
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($http_code === 0) {
    echo json_encode([
        'connected' => false,
        'error' => true,
        'message' => 'Gateway Unreachable',
        'debug' => [
            'curl_error' => $curl_err,
            'hint' => 'Pastikan "node server.js" sudah berjalan di VPS/Proxmox Anda pada port 3000.'
        ]
    ]);
} else {
    http_response_code($http_code);
    echo $response;
}
