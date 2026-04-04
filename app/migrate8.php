<?php
require_once __DIR__ . '/init.php';

$db->exec("
    CREATE TABLE IF NOT EXISTS routers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        host TEXT NOT NULL,
        port INTEGER DEFAULT 8728,
        username TEXT NOT NULL,
        password TEXT NOT NULL
    );
");

// Extract logic if upgrading from old single router setup
try {
    $settings = $db->query("SELECT router_ip, router_user, router_pass, router_port FROM settings WHERE id=1")->fetch();
    if (!empty($settings['router_ip'])) {
        $count = $db->query("SELECT COUNT(*) FROM routers")->fetchColumn();
        if ($count == 0) {
            $db->prepare("INSERT INTO routers (name, host, port, username, password) VALUES (?, ?, ?, ?, ?)")
               ->execute(['MikroTik Utama', $settings['router_ip'], $settings['router_port'] ?: 8728, $settings['router_user'], $settings['router_pass']]);
        }
    }
} catch (Exception $e) {}

// Alter customers
try {
    $db->exec("ALTER TABLE customers ADD COLUMN router_id INTEGER DEFAULT 0");
} catch(Exception $e) {}

try {
    $db->exec("ALTER TABLE customers ADD COLUMN pppoe_name TEXT");
} catch(Exception $e) {}

echo "Migrasi Multi-Router selesai dilaksanakan.\n";
