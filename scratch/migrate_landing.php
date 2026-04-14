<?php
require_once __DIR__ . '/../app/init.php';
$tables = ['landing_packages', 'landing_logos'];
foreach ($tables as $table) {
    echo "Checking $table...\n";
    $columns = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    $has_tenant = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'tenant_id') {
            $has_tenant = true;
            break;
        }
    }
    if (!$has_tenant) {
        echo "Adding tenant_id to $table...\n";
        $db->exec("ALTER TABLE $table ADD COLUMN tenant_id INTEGER DEFAULT 1");
    } else {
        echo "tenant_id already exists in $table.\n";
    }
}
