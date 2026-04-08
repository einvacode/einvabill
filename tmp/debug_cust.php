<?php
try {
    $db = new PDO('sqlite:database.db');
    $id = '225572';
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? OR customer_code = ?");
    $stmt->execute([$id, $id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "--- CUSTOMER ---\n";
    print_r($c);
    
    if ($c) {
        $cid = $c['id'];
        $stmt = $db->prepare("SELECT * FROM invoices WHERE customer_id = ?");
        $stmt->execute([$cid]);
        $invs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\n--- INVOICES ---\n";
        print_r($invs);
    } else {
        echo "\nCustomer not found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
