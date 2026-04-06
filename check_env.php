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

    // 2. Extensions Check
    $required_exts = [
        'pdo_sqlite' => 'apt install php-sqlite3',
        'curl'       => 'apt install php-curl',
        'gd'         => 'apt install php-gd',
        'mbstring'   => 'apt install php-mbstring',
        'openssl'    => 'Ekstensi internal PHP',
        'xml'        => 'apt install php-xml',
        'zip'        => 'apt install php-zip'
    ];

    foreach ($required_exts as $ext => $inst) {
        $loaded = extension_loaded($ext);
        $all_ok &= check(
            "Extension: $ext", 
            $loaded, 
            "Ekstensi $ext tidak ditemukan!", 
            $inst
        );
    }

    // 3. Permissions: Core Directories
    $dirs = [
        '.' => 'Folder Root',
        'app' => 'Folder Aplikasi',
        'public/uploads' => 'Folder Gambar/Logo',
        'public/uploads/banners' => 'Folder Banner',
        'backups' => 'Folder Backup'
    ];

    foreach ($dirs as $path => $name) {
        $abs_path = __DIR__ . '/' . $path;
        if (!is_dir($abs_path)) {
            @mkdir($abs_path, 0775, true);
        }
        $writable = is_writable($abs_path);
        $all_ok &= check(
            "Izin Tulis: $name (" . (str_replace('\\', '/', $path)) . ")", 
            $writable, 
            "Server tidak bisa menulis ke folder ini.",
            "chown -R www-data:www-data " . __DIR__ . " && chmod -R 775 " . __DIR__
        );
    }

    // 4. Database File existence & Write
    $db_file = __DIR__ . '/database.sqlite';
    if (file_exists($db_file)) {
        $all_ok &= check(
            "Izin Tulis database.sqlite", 
            is_writable($db_file), 
            "File database tidak bisa ditulis.",
            "chown www-data:www-data $db_file && chmod 775 $db_file"
        );
    } else {
        check("File database.sqlite", false, "Database belum ada. Akan dibuat otomatis saat aplikasi dijalankan.");
    }

    // 5. PHP Settings
    $mem_limit = ini_get('memory_limit');
    $upload_max = ini_get('upload_max_filesize');
    
    echo '<div class="info" style="background:#f8f9fa; border:1px solid #ddd;">';
    echo '💻 <strong>Server Info:</strong> ' . php_uname() . '<br>';
    echo '📦 <strong>Memory Limit:</strong> ' . $mem_limit . '<br>';
    echo '📤 <strong>Max Upload:</strong> ' . $upload_max . '<br>';
    echo '🕒 <strong>Timezone:</strong> ' . date_default_timezone_get();
    echo '</div>';

    if ($all_ok) {
        echo '<div class="info" style="background: #e7f3ff; color: #0b5030; border: 1px solid #b8daff; margin-top:20px;">';
        echo '🚀 <strong>Selamat!</strong> Server Anda sudah siap. Jika portal masih tidak tampil, silakan pastikan cache browser sudah dibersihkan atau periksa file log error web server.';
        echo '</div>';
    } else {
        echo '<div class="info" style="background: #fff3cd; border: 1px solid #ffeeba; margin-top:20px;">';
        echo '💡 <strong>Saran:</strong> Jalankan perintah yang diawali dengan <code>apt</code> atau <code>chown</code> di terminal Ubuntu/Proxmox Anda sebagai <code>root</code> atau menggunakan <code>sudo</code>.';
        echo '</div>';
    }
    ?>

    <p style="text-align: center; margin-top: 30px; display: flex; gap:10px; justify-content:center;">
        <a href="check_env.php" style="text-decoration: none; background: #6c757d; color: #fff; padding: 10px 20px; border-radius: 5px;"><i class="fas fa-sync"></i> Refresh</a>
        <a href="index.php" style="text-decoration: none; background: #2c3e50; color: #fff; padding: 10px 20px; border-radius: 5px;">Buka Portal EinvaBill</a>
    </p>
</div>
</body>
</html>
</div>
</body>
</html>
