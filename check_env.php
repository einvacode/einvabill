<?php
/**
 * EinvaBill Environment Diagnostic Tool
 * Run this in your browser: http://your-ip-address/check_env.php
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EinvaBill Diagnostic Tool</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f6; color: #333; padding: 20px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
        .result { padding: 15px; border-radius: 8px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .status-icon { font-size: 20px; font-weight: bold; }
        .info { font-size: 0.9em; background: #eee; padding: 10px; border-radius: 5px; margin-top: 20px; }
        code { background: #f8f9fa; padding: 2px 5px; border-radius: 3px; border: 1px solid #ddd; }
        .remedy { margin-top: 5px; font-size: 0.85em; font-style: italic; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 EinvaBill Diagnostic Setup</h1>
    
    <?php
    function check($label, $condition, $error_msg, $remedy = "") {
        echo '<div class="result ' . ($condition ? 'success' : 'error') . '">';
        echo '<div><strong>' . $label . '</strong>';
        if (!$condition) {
            echo '<div class="remedy">⚠️ ' . $error_msg . '</div>';
            if ($remedy) echo '<div class="remedy">👉 <code>' . $remedy . '</code></div>';
        }
        echo '</div>';
        echo '<span class="status-icon">' . ($condition ? '✅' : '❌') . '</span>';
        echo '</div>';
        return $condition;
    }

    $all_ok = true;

    // 1. PHP Version
    $all_ok &= check(
        "PHP Version: " . PHP_VERSION, 
        version_compare(PHP_VERSION, '7.4.0', '>='),
        "PHP version harus minimal 7.4.0 untuk performa terbaik.",
        "apt install php"
    );

    // 2. PDO SQLite Extension
    $has_sqlite = extension_loaded('pdo_sqlite');
    $all_ok &= check(
        "PDO SQLite Extension", 
        $has_sqlite, 
        "Ekstensi PDO SQLite tidak terdeteksi!", 
        "apt install php-sqlite3 && systemctl restart apache2"
    );

    // 3. Database File existence
    $db_file = __DIR__ . '/database.sqlite';
    $db_exists = file_exists($db_file);
    check(
        "File database.sqlite", 
        $db_exists, 
        "File database.sqlite tidak ditemukan. Ini akan dibuat otomatis saat aplikasi dijalankan."
    );

    // 4. Permissions: File Write
    if ($db_exists) {
        $db_writable = is_writable($db_file);
        $all_ok &= check(
            "Izin Tulis database.sqlite", 
            $db_writable, 
            "File database tidak bisa ditulis oleh server web (www-data).",
            "chown www-data:www-data $db_file && chmod 775 $db_file"
        );
    }

    // 5. Permissions: Directory Write (Crucial for SQLite Journaling)
    $dir_writable = is_writable(__DIR__);
    $all_ok &= check(
        "Izin Tulis Folder Root", 
        $dir_writable, 
        "Folder root aplikasi tidak bisa ditulis. SQLite butuh izin ini untuk membuat file temporary (journal).",
        "chown -R www-data:www-data " . __DIR__ . " && chmod -R 775 " . __DIR__
    );

    // 6. Database Connection & Content
    if ($has_sqlite && $db_exists && $db_writable && $dir_writable) {
        try {
            $db = new PDO('sqlite:' . $db_file);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            $table_count = count($tables);
            
            $settings_count = 0;
            if (in_array('settings', $tables)) {
                $settings_count = $db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
            }

            check(
                "Koneksi Database & Tabel", 
                $table_count > 0, 
                "Terhubung tapi tidak ada tabel ditemukan.", 
                "Coba hapus database.sqlite dan refresh halaman utama agar di-generate ulang."
            );
            
            check(
                "Data Pengaturan (Settings Table)", 
                $settings_count > 0, 
                "Tabel settings kosong. Ini akan diperbaiki oleh update terbaru app/init.php.",
                "Silakan akses index.php satu kali untuk memicu migrasi."
            );

        } catch (Exception $e) {
            check("Koneksi Database", false, "Gagal terhubung: " . $e->getMessage());
            $all_ok = false;
        }
    }

    if ($all_ok) {
        echo '<div class="info" style="background: #e7f3ff; color: #0b5030; border: 1px solid #b8daff;">';
        echo '🚀 <strong>Selamat!</strong> Server Anda sudah siap. Jika portal masih tidak tampil, silakan pastikan cache browser sudah dibersihkan atau periksa file <code>/var/log/apache2/error.log</code>.';
        echo '</div>';
    } else {
        echo '<div class="info" style="background: #fff3cd; border: 1px solid #ffeeba;">';
        echo '💡 <strong>Saran:</strong> Jalankan perintah yang diawali dengan <code>apt</code> atau <code>chown</code> di terminal Ubuntu/Proxmox Anda sebagai <code>root</code> atau menggunakan <code>sudo</code>.';
        echo '</div>';
    }
    ?>

    <p style="text-align: center; margin-top: 30px;">
        <a href="index.php" style="text-decoration: none; background: #2c3e50; color: #fff; padding: 10px 20px; border-radius: 5px;">Buka Portal EinvaBill</a>
    </p>
</div>
</body>
</html>
