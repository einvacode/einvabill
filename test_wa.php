<?php
require_once __DIR__ . '/app/init.php';

$cid = $_GET['cid'] ?? '1'; // Default to 1 (collector id? or admin?)
$urls = [
    "status" => "http://localhost:3000/status?cid=$cid",
    "qr" => "http://localhost:3000/qr?cid=$cid"
];

echo "<h1>Connectivity Test</h1>";
foreach ($urls as $name => $url) {
    echo "<h2>Testing $name: $url</h2>";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    if (curl_errno($ch)) {
        echo "<pre style='color:red'>CURL ERROR: " . curl_error($ch) . "</pre>";
    } else {
        echo "<p>HTTP Code: $http_code</p>";
        echo "<p>Content-Type: $content_type</p>";
        echo "<pre style='background:#eee; padding:10px'>" . htmlspecialchars(substr($resp, 0, 500)) . "...</pre>";
    }
    curl_close($ch);
}
?>
