<?php
require_once __DIR__ . '/app/init.php';

echo "--- Diagnostic: Partner Linking ---\n";

// 1. Find the customer record for 'Dawung'
$dawung_customer = $db->query("SELECT * FROM customers WHERE name LIKE '%Dawung%'")->fetch();
if ($dawung_customer) {
    echo "Found Customer: " . $dawung_customer['name'] . " (ID: " . $dawung_customer['id'] . ")\n";
    
    // 2. Check for invoices for this customer
    $inv_count = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = " . $dawung_customer['id'])->fetchColumn();
    echo "Invoice Count for this Customer: " . $inv_count . "\n";
    
    // 3. Find the user record associated with this customer
    $associated_user = $db->query("SELECT * FROM users WHERE customer_id = " . $dawung_customer['id'])->fetch();
    if ($associated_user) {
        echo "Linked User: " . $associated_user['username'] . " (ID: " . $associated_user['id'] . ")\n";
    } else {
        echo "WARNING: No user is linked to this customer record (customer_id field in users table is not set to " . $dawung_customer['id'] . ")\n";
        
        // 4. Find all partner users to see if any should be linked
        $partner_users = $db->query("SELECT * FROM users WHERE role = 'partner'")->fetchAll();
        echo "List of Partner Users:\n";
        foreach ($partner_users as $pu) {
            echo "- " . $pu['username'] . " (ID: " . $pu['id'] . ", current customer_id link: " . ($pu['customer_id'] ?: 'NONE') . ")\n";
        }
    }
} else {
    echo "ERROR: No customer found with name like 'Dawung'\n";
    $all_partners = $db->query("SELECT id, name FROM customers WHERE type = 'partner'")->fetchAll();
    echo "Current Partners in Customers table:\n";
    foreach ($all_partners as $ap) {
        echo "- " . $ap['name'] . " (ID: " . $ap['id'] . ")\n";
    }
}
