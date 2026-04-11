<?php
require_once __DIR__ . '/../app/init.php';
try {
    $stmt = $db->prepare("SELECT id, name, type, created_by, customer_code FROM customers WHERE name LIKE ? LIMIT 50");
    $stmt->execute(['%Sentra%']);
    $rows = $stmt->fetchAll();
    if (empty($rows)) { echo "No matches for 'Sentra'\n"; exit; }
    foreach ($rows as $r) {
        echo "ID: " . $r['id'] . " | Name: " . $r['name'] . " | Type: " . $r['type'] . " | Created_by: " . $r['created_by'] . " | Code: " . $r['customer_code'] . "\n";
    }
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }
