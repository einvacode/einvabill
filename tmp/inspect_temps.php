<?php
// Debug: tampilkan pelanggan sementara (type 'note' atau 'temp')
require_once __DIR__ . '/../app/init.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Pelanggan Sementara (type='note' atau 'temp')</h2>";
try {
    $rows = $db->query("SELECT id, name, type, created_by, customer_code, registration_date FROM customers WHERE type IN ('note','temp') ORDER BY registration_date DESC LIMIT 200")->fetchAll();
} catch (Exception $e) {
    echo "<pre>Error: " . htmlspecialchars($e->getMessage()) . "</pre>"; exit;
}

echo "<table border=1 cellpadding=6 cellspacing=0 style='border-collapse:collapse;'>";
echo "<tr><th>ID</th><th>Name</th><th>Type</th><th>Created_by</th><th>Code</th><th>Registration</th></tr>";
foreach ($rows as $r) {
    echo "<tr>";
    echo "<td>" . intval($r['id']) . "</td>";
    echo "<td>" . htmlspecialchars($r['name']) . "</td>";
    echo "<td>" . htmlspecialchars($r['type']) . "</td>";
    echo "<td>" . htmlspecialchars($r['created_by']) . "</td>";
    echo "<td>" . htmlspecialchars($r['customer_code']) . "</td>";
    echo "<td>" . htmlspecialchars($r['registration_date']) . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<p><a href='../tmp/normalize_temps.php'>Jalankan normalisasi (set created_by=0)</a> — hanya lakukan jika Anda setuju.</p>";

?>
