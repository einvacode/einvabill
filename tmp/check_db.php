<?php
$db = new PDO('sqlite:database.sqlite');
echo "=== AREAS ===\n";
$areas = $db->query("SELECT * FROM areas")->fetchAll(PDO::FETCH_ASSOC);
print_r($areas);

echo "\n=== USERS (Collectors) ===\n";
$users = $db->query("SELECT id, name, username, role, area FROM users WHERE role='collector'")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

echo "\n=== CUSTOMERS COUNT BY AREA ===\n";
$counts = $db->query("SELECT area, COUNT(*) as total FROM customers GROUP BY area")->fetchAll(PDO::FETCH_ASSOC);
print_r($counts);

echo "\n=== CUSTOMERS COUNT BY COLLECTOR ===\n";
$coll_counts = $db->query("SELECT collector_id, COUNT(*) as total FROM customers GROUP BY collector_id")->fetchAll(PDO::FETCH_ASSOC);
print_r($coll_counts);
?>
