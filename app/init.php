<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// Helper to get base URL dynamically
function get_app_url($custom = null) {
    if (!empty($custom)) {
        // Keep the custom URL as provided (http or https)
        return rtrim($custom, '/');
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'fibernodeinternet.com';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    $base = rtrim($protocol . $host . $dir, '/');
    return $base;
}

// --- DATABASE INITIALIZATION ---
$db_file = __DIR__ . '/../database.sqlite';

// Proxmox/Linux Permission Helper
if (!file_exists($db_file)) {
    if (!is_writable(dirname($db_file))) {
        die("<div style='padding:40px; text-align:center; font-family:sans-serif;'>
            <h2 style='color:#ef4444;'>⚠️ Izin Akses Direktori Ditolak (Permisson Denied)</h2>
            <p>Sistem tidak dapat membuat file database. Folder <b>" . basename(dirname(__DIR__)) . "</b> tidak dapat ditulisi oleh web server.</p>
            <div style='background:#f3f4f6; padding:20px; border-radius:10px; display:inline-block; text-align:left; border:1px solid #d1d5db;'>
                <code>chown -R www-data:www-data " . realpath(dirname(__DIR__)) . "</code><br>
                <code>chmod -R 775 " . realpath(dirname(__DIR__)) . "</code>
            </div>
            <p style='color:#6b7280; font-size:14px; margin-top:20px;'>Jalankan perintah di atas pada terminal Proxmox Anda, lalu <b>Refresh</b>.</p>
        </div>");
    }
}

$db = new PDO('sqlite:' . $db_file);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// High-Performance Concurrency Settings (WAL Mode)
$db->exec("PRAGMA journal_mode=WAL;");
$db->exec("PRAGMA synchronous=NORMAL;");
$db->exec("PRAGMA cache_size = -2000;"); // 2MB Cache
$db->exec("PRAGMA temp_store = MEMORY;");

// --- VERSIONED SCHEMA MANAGEMENT ---
define('APP_DB_VERSION', 20); // Sync with database_setup.php

$current_db_ver = 0;
try {
    $current_db_ver = $db->query("SELECT db_version FROM settings WHERE id=1")->fetchColumn();
} catch (Exception $e) {
    // Table settings might not exist yet
}

if ($current_db_ver < APP_DB_VERSION) {
    require_once __DIR__ . '/database_setup.php';
    run_database_setup($db);
}

// Fetch Core Settings
$site_settings = $db->query("SELECT * FROM settings WHERE id=1")->fetch();

// --- LICENSE ENGINE (Static Optimization) ---
$MASTER_KEY = "EB-ULTIMATE-2026";
$license_key = $site_settings['license_key'] ?? '';
$install_date = $site_settings['installation_date'] ?: date('Y-m-d');
$expiry_date = $site_settings['license_expiry'] ?? '';

if (empty($site_settings['installation_date'])) {
    $db->prepare("UPDATE settings SET installation_date = ? WHERE id=1")->execute([$install_date]);
}

$LICENSE_ST = 'EXPIRED';
$LICENSE_MSG = '';

if ($license_key === $MASTER_KEY) {
    $LICENSE_ST = 'UNLIMITED';
} elseif (!empty($expiry_date) && strtotime($expiry_date) >= strtotime(date('Y-m-d'))) {
    $LICENSE_ST = 'ACTIVE';
} else {
    $days_since_install = (strtotime(date('Y-m-d')) - strtotime($install_date)) / 86400;
    if ($days_since_install <= 7) {
        $LICENSE_ST = 'TRIAL';
        $remaining = 7 - floor($days_since_install);
        $LICENSE_MSG = "Masa Percobaan (Trial) sisa $remaining hari.";
    } else {
        $LICENSE_ST = 'EXPIRED';
        $LICENSE_MSG = "Masa Percobaan / Lisensi Anda telah habis.";
    }
}

define('LICENSE_ST', $LICENSE_ST);
define('LICENSE_MSG', $LICENSE_MSG);

?>
