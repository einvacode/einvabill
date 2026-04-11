<?php
require_once __DIR__ . '/../app/init.php';
try {
    $rows = $db->query("SELECT id, created_at, amount, created_via, customer_id FROM invoices WHERE (created_via IS NOT NULL AND created_via <> '') AND (customer_id IS NULL OR customer_id = 0)")->fetchAll();
    if (empty($rows)) { echo "No invoices with customer_id 0 or NULL and created_via present\n"; exit; }
    foreach ($rows as $r) {
        echo sprintf("ID:%d Date:%s Amount:%s Via:%s Cust:%s\n", $r['id'], $r['created_at'], $r['amount'], $r['created_via'] ?? '-', $r['customer_id'] ?? 'NULL');
    }
} catch (Exception $e) { echo 'Error: ' . $e->getMessage() . "\n"; }
