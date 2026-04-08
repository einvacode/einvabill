<?php
/**
 * ONE-TIME FIX SCRIPT
 * Run this once to assign customers to collectors based on matching area names.
 */
// require_once 'app/init.php';
// if ($_SESSION['user_role'] !== 'admin') {
//     die("Unauthorized. Please login as admin.");
// }
$db = new PDO('sqlite:database.sqlite');

echo "Starting smart assignment fix...<br>";

$collectors = $db->query("SELECT id, name, area FROM users WHERE role = 'collector'")->fetchAll();
$lookup = [];
foreach ($collectors as $c) {
    if (!empty($c['area'])) {
        $areas = explode(',', $c['area']);
        foreach($areas as $a) $lookup[strtolower(trim($a))] = $c['id'];
    }
    $lookup[strtolower(trim($c['name']))] = $c['id'];
}

$customers = $db->query("SELECT id, area FROM customers WHERE collector_id = 0 OR collector_id IS NULL")->fetchAll();
$fixed = 0;

foreach ($customers as $cust) {
    if (empty($cust['area'])) continue;
    $area_key = strtolower(trim($cust['area']));
    if (isset($lookup[$area_key])) {
        $cid = $lookup[$area_key];
        $db->prepare("UPDATE customers SET collector_id = ? WHERE id = ?")->execute([$cid, $cust['id']]);
        $fixed++;
    }
}

echo "Finished! Fixed $fixed customers.<br>";
if ($fixed > 0) {
    echo "Customers in area 'Giyarto' (and others) should now appear in their respective dashboards.";
}
?>
