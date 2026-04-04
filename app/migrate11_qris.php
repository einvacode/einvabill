<?php
// Migration script to add company_qris column to settings table
try {
    $db = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Memulai migrasi QRIS...\n";

    try {
        $db->exec("ALTER TABLE settings ADD COLUMN company_qris TEXT");
        echo "- Kolom company_qris ditambahkan.\n";
    } catch(Exception $e) {
        echo "- Kolom company_qris sudah ada.\n";
    }

    echo "Selesai! Pengaturan QRIS sekarang tersedia.\n";

} catch (Exception $e) {
    echo "Gagal: " . $e->getMessage();
}
