<?php
try {
    $db = new PDO('sqlite:G:/Produk Antigravity/database.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->query("SELECT id, username, role, name, customer_id FROM users WHERE username LIKE '%eka%' OR name LIKE '%eka%'");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
