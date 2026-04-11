<?php
// Lightweight server diagnostic for this app. Upload to server and open in browser.
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

echo "== PHP Version ==\n";
echo phpversion() . "\n\n";

echo "== Loaded PHP Modules ==\n";oreach (get_loaded_extensions() as $ext) echo $ext . "\n";

echo "\n== PDO Drivers ==\n";
if (class_exists('PDO')) {
    print_r(PDO::getAvailableDrivers());
} else {
    echo "PDO not available\n";
}

echo "\n== phpinfo() summary (disabled full output) ==\n";
if (function_exists('phpinfo')) {
    // print selected phpinfo items
    ob_start(); phpinfo(INFO_MODULES); $mods = ob_get_clean();
    // show only pdo and sqlite related lines
    preg_match_all('/<tr><td class="e">(.*?)<\/td>\s*<td class="v">(.*?)<\/td><\/tr>/si', $mods, $m);
    foreach ($m[1] as $i => $k) {
        $k = strip_tags($k); $v = strip_tags($m[2][$i]);
        if (stripos($k, 'pdo') !== false || stripos($k, 'sqlite') !== false || stripos($k, 'sqlite3') !== false) {
            echo "$k: $v\n";
        }
    }
} else {
    echo "phpinfo() not available\n";
}

// Check app DB
$dbPath = __DIR__ . '/../database.sqlite';
echo "\n== Database check ==\n";
echo "Expected DB path: $dbPath\n";
if (file_exists($dbPath)) {
    echo "File exists, size: " . filesize($dbPath) . " bytes\n";
    echo "File perms: " . substr(sprintf('%o', fileperms($dbPath)), -4) . "\n";
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        echo "Connected to SQLite OK, sample tables:\n";
        foreach ($row as $r) echo " - " . ($r['name'] ?? json_encode($r)) . "\n";
    } catch (Exception $e) {
        echo "PDO/SQLite connect failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "Database file not found at expected path.\n";
}

// Simple writable check for tmp/ and public/uploads
$paths = [__DIR__ . '/../tmp', __DIR__ . '/../public/uploads', __DIR__ . '/../app'];
echo "\n== Writable checks ==\n";
foreach ($paths as $p) {
    echo "$p: ";
    if (file_exists($p)) {
        echo is_writable($p) ? "writable\n" : "not writable\n";
    } else {
        echo "missing\n";
    }
}

echo "\n== End Diagnostic ==\n";
