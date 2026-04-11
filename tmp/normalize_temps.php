<?php
// Debug helper: normalize temporary customers to created_by = 0
require_once __DIR__ . '/../app/init.php';

if (!isset($_GET['confirm'])) {
    echo "<p>Konfirmasi: akan mengubah semua pelanggan dengan type 'note' atau 'temp' sehingga created_by=0.</p>";
    echo "<p><a href='normalize_temps.php?confirm=1'>Klik untuk konfirmasi dan jalankan</a></p>";
    exit;
}

try {
    $count = $db->exec("UPDATE customers SET created_by = 0 WHERE type IN ('note','temp') AND (created_by IS NULL OR created_by <> 0)");
    echo "<p>Normalisasi selesai. Baris terpengaruh: " . intval($count) . "</p>";
} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p><a href='inspect_temps.php'>Kembali ke daftar</a></p>";

?>
