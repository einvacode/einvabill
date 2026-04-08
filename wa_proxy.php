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

// Rebuild query string without the 'path' parameter
$params = $_GET;
unset($params['path']);
$query = http_build_query($params);

$url = "http://127.0.0.1:3000/" . $path . ($query ? "?" . $query : "");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 0) {
    echo json_encode(['connected' => false, 'message' => 'Node.js server not reachable on localhost:3000']);
} else {
    http_response_code($http_code);
    echo $response;
}
?>
