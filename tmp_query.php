<?php
require_once 'app/init.php';
echo "--- USERS ---\n";
$users = $db->query("SELECT id, name, username, role FROM users WHERE id = 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

echo "\n--- CUSTOMERS ---\n";
$customers = $db->query("SELECT id, name, type, created_by FROM customers WHERE id = 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($customers);

echo "\n--- INVOICES (ID MITRA AS CUSTOMER) ---\n";
$invoices = $db->query("SELECT id, customer_id, amount, status FROM invoices WHERE id = 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($invoices);
?>
