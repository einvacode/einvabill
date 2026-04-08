<?php
/**
 * WhatsApp Gateway PHP Proxy
 * Bridges the gap between browser and Node.js server to avoid CORS/HTTPS issues.
 */
// Prevent caching of status checks
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? 'status';

$params = $_GET;
unset($params['path']);
$query = http_build_query($params);

// PROXMOX/HTTPS Reliability: Try 127.0.0.1 first, then localhost
$hosts = ['127.0.0.1', 'localhost'];
$response = false;
$http_code = 0;
$curl_err = '';

foreach ($hosts as $host) {
    $url = "http://$host:3000/" . $path . ($query ? "?" . $query : "");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 0) break; // Found a working host
}

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
?>
