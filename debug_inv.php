<?php
require_once __DIR__ . '/app/init.php';

echo "--- Debug: Invoices for BUMDesa Dawung ---\n";

// 1. Find Customer ID
$customer = $db->query("SELECT id, name FROM customers WHERE name LIKE '%Dawung%'")->fetch();
if (!$customer) {
    die("Customer 'Dawung' not found.\n");
}
$cid = $customer['id'];
echo "Customer Found: " . $customer['name'] . " (ID: $cid)\n";

// 2. Find Invoices
$invoices = $db->query("SELECT * FROM invoices WHERE customer_id = $cid")->fetchAll();
echo "Total Invoices: " . count($invoices) . "\n";
foreach ($invoices as $inv) {
    echo "- INV ID: " . $inv['id'] . ", Amount: " . $inv['amount'] . ", Status: " . $inv['status'] . ", Due: " . $inv['due_date'] . "\n";
}

// 3. Check User Link
$user = $db->query("SELECT id, username, customer_id FROM users WHERE username = 'bumdesdwg' OR name LIKE '%Dawung%'")->fetch();
if ($user) {
    echo "User found: " . $user['username'] . " (ID: " . $user['id'] . "), Linked Customer ID: " . ($user['customer_id'] ?: 'NULL') . "\n";
} else {
    echo "User not found.\n";
}
