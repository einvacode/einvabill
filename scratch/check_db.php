<?php
require_once 'app/init.php';

echo "--- Schema Check: expenses ---\n";
try {
    $cols = $db->query("PRAGMA table_info(expenses)")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) {
        echo "{$c['name']} ({$c['type']})\n";
    }
} catch(Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

echo "\n--- Schema Check: settings ---\n";
try {
    $cols = $db->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) {
        echo "{$c['name']} ({$c['type']})\n";
    }
} catch(Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

echo "\n--- Permission Check ---\n";
echo "User ID in session: " . ($_SESSION['user_id'] ?? 'NONE') . "\n";
echo "Tenant ID in session: " . ($_SESSION['tenant_id'] ?? 'NONE') . "\n";
