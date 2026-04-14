<?php
require_once __DIR__ . '/../app/init.php';

echo "--- CUSTOMER RUDIMAN ---\n";
$rudiman = $db->query("SELECT * FROM customers WHERE name LIKE '%Rudiman%'")->fetch();
print_r($rudiman);

echo "\n--- SETTINGS TABLE ---\n";
$settings = $db->query("SELECT id, company_name, tenant_id FROM settings")->fetchAll();
print_r($settings);

echo "\n--- INVOICE FOR RUDIMAN ---\n";
if ($rudiman) {
    $inv = $db->query("SELECT * FROM invoices WHERE customer_id = " . $rudiman['id'] . " ORDER BY id DESC LIMIT 1")->fetch();
    print_r($inv);
}
