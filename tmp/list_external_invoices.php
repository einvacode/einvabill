<?php
require_once __DIR__ . '/../app/init.php';
// List invoices with created_via = 'external' or customer.type in ('note','temp')
try {
    $rows = $db->query("SELECT i.id, i.created_at, i.amount, i.discount, i.created_via, c.id as cust_id, c.name as cust_name, c.type as cust_type FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id WHERE i.created_via = 'external' OR c.type IN ('note','temp') ORDER BY i.created_at DESC LIMIT 200")->fetchAll();
    if (empty($rows)) { echo "No external/temp invoices found\n"; exit; }
    foreach ($rows as $r) {
        echo sprintf("ID:%d Date:%s Amount:%s Discount:%s Via:%s Cust:%d/%s Type:%s\n", $r['id'], $r['created_at'], $r['amount'], $r['discount'], $r['created_via'] ?? '-', $r['cust_id'] ?? 0, $r['cust_name'] ?? '-', $r['cust_type'] ?? '-');
    }
} catch (Exception $e) { echo 'Error: ' . $e->getMessage() . "\n"; }
