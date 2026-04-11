<?php
require_once __DIR__ . '/../app/init.php';

$date_from = '2026-04-01';
$date_to = '2026-04-12';
$sql_date_from = $date_from . ' 00:00:00';
$sql_date_to = $date_to . ' 23:59:59';

$partner_user_ids = $db->query("SELECT id FROM users WHERE role = 'partner'")->fetchAll(PDO::FETCH_COLUMN);
$partner_list_str = !empty($partner_user_ids) ? implode(',', $partner_user_ids) : '0';
$scope_inner = "(c.created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR c.created_by = 0 OR c.created_by IS NULL)";
$scope_with_external = " AND (" . $scope_inner . " OR (i.created_via IS NOT NULL AND i.created_via <> '') OR i.created_via IN ('external','quick') OR c.type IN ('note','temp')) ";

$sql = "SELECT i.id, i.amount, i.discount, i.due_date, i.created_at, i.created_via, c.id as cust_id, c.name as cust_name, c.type as cust_type FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE ( (i.due_date BETWEEN ? AND ?) OR ((i.created_via IS NOT NULL AND i.created_via <> '') AND (i.created_at BETWEEN ? AND ?)) ) AND i.status = 'Belum Lunas' $scope_with_external";

echo "SQL: $sql\nParams: [$date_from, $date_to, $sql_date_from, $sql_date_to]\n";
$q = $db->prepare($sql);
$q->execute([$date_from, $date_to, $sql_date_from, $sql_date_to]);
$rows = $q->fetchAll();
echo "Found: " . count($rows) . "\n";
foreach($rows as $r) {
    echo sprintf("ID:%d Due:%s Created:%s Amt:%s Via:%s Cust:%d/%s Type:%s\n", $r['id'], $r['due_date'], $r['created_at'], $r['amount'], $r['created_via'] ?? '-', $r['cust_id'], $r['cust_name'], $r['cust_type']);
}
