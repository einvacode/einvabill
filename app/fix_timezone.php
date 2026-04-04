<?php
require_once __DIR__ . '/init.php';

echo "<h2>Timezone Fix Migration (UTC to WIB)</h2>";

try {
    // 1. Fix payments table (payment_date)
    // Only update if it looks like UTC (hour < 7 on early morning payments usually, but safely we check if it was before our fix)
    // A safer way is to just add 7 hours to all existing records once.
    // We check if we've already run this by checking a small flag or just trust the user runs it once.
    
    // We will use SQLite's datetime function to add 7 hours
    $db->exec("UPDATE payments SET payment_date = datetime(payment_date, '+7 hours') WHERE payment_date NOT LIKE '%:%:%+07:00%'");
    echo "✅ Berhasil memperbarui tabel <b>payments</b> (waktu pembayaran).<br>";

    // 2. Fix invoices table (created_at)
    $db->exec("UPDATE invoices SET created_at = datetime(created_at, '+7 hours') WHERE created_at NOT LIKE '%:%:%+07:00%'");
    echo "✅ Berhasil memperbarui tabel <b>invoices</b> (waktu pembuatan tagihan).<br>";

    echo "<br><div style='color:green; font-weight:bold;'>Seluruh data lama telah disinkronkan ke WIB (GMT+7).</div>";
    echo "<p><a href='../index.php?page=admin_invoices'>Kembali ke Dashboard</a></p>";

    // Auto-delete this file for security after run? 
    // Usually better to let user delete it, but let's just leave it for now.
} catch (Exception $e) {
    echo "<div style='color:red;'>Gagal menjalankan migrasi: " . $e->getMessage() . "</div>";
}
