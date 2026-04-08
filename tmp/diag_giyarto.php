<?php
$db = new PDO('sqlite:database.sqlite');
$u = $db->query("SELECT * FROM users WHERE name LIKE '%Giyarto%'")->fetchAll(PDO::FETCH_ASSOC);
echo "USERS FOUND:\n";
print_r($u);

foreach ($u as $user) {
    $uid = $user['id'];
    $cust = $db->query("SELECT COUNT(*) FROM customers WHERE collector_id = $uid")->fetchColumn();
    echo "Customers assigned to {$user['name']} (ID: $uid): $cust\n";
    
    // Also check by area if that's a fallback
    if (!empty($user['area'])) {
        $area = $db->quote($user['area']);
        $cust_area = $db->query("SELECT COUNT(*) FROM customers WHERE area = $area")->fetchColumn();
        echo "Customers in area '{$user['area']}': $cust_area\n";
    }
}
?>
