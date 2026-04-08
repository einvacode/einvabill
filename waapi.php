<?php
/**
 * WhatsApp Gateway PHP Proxy (waapi.php)
 * Jembatan internal untuk menghubungkan Frontend (HTTPS) dengan WhatsApp Gateway (Node.js/localhost)
 * Mengatasi masalah Mixed Content, CORS, dan mempermudah akses di berbagai environment.
 */
require_once __DIR__ . '/app/init.php';

// Proteksi: Hanya user yang login yang bisa menggunakan proxy ini
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Unauthorized access']);
    exit;
}

$path = $_GET['path'] ?? '';
$cid = $_GET['cid'] ?? 'admin';
$target_url = "http://localhost:3000/" . ltrim($path, '/');

// Build query string if any
$query = $_GET;
unset($query['path']);
if (!empty($query)) {
    $target_url .= (strpos($target_url, '?') === false ? '?' : '&') . http_build_query($query);
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Fallback logic for localhost vs 127.0.0.1
if (strpos($target_url, 'localhost') !== false) {
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_input = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_input);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_input)
    ]);
}

$response = curl_exec($ch);

// If localhost failed, try 127.0.0.1
if (curl_errno($ch) && strpos($target_url, 'localhost') !== false) {
    $target_url = str_replace('localhost', '127.0.0.1', $target_url);
    curl_setopt($ch, CURLOPT_URL, $target_url);
    $response = curl_exec($ch);
}
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    header('Content-Type: application/json');
    http_response_code(502);
    echo json_encode(['error' => true, 'message' => 'Gateway Connection Error: ' . $error_msg]);
} else {
    if ($content_type) {
        header('Content-Type: ' . $content_type);
    } else {
        header('Content-Type: application/json');
    }
    http_response_code($http_code);
    echo $response;
}

curl_close($ch);
?>
