<?php
require_once __DIR__ . '/../app/init.php';

$date_from = '2026-04-01';
$date_to = '2026-04-12';
$sql_date_from = $date_from . ' 00:00:00';
$sql_date_to = $date_to . ' 23:59:59';

$u_role = 'admin';
$u_id = 1;

$partner_user_ids = $db->query("SELECT id FROM users WHERE role = 'partner'")->fetchAll(PDO::FETCH_COLUMN);
$partner_list_str = !empty($partner_user_ids) ? implode(',', $partner_user_ids) : '0';
$scope_inner = "(c.created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR c.created_by = 0 OR c.created_by IS NULL)";
$scope_with_external = " AND (" . $scope_inner . " OR (i.created_via IS NOT NULL AND i.created_via <> '') OR i.created_via IN ('external','quick') OR c.type IN ('note','temp')) ";

echo "Date Range: $date_from -> $date_to\n";

// luns_tepat
$sql = "SELECT SUM(p.amount) as total FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id WHERE p.payment_date BETWEEN ? AND ? AND datetime(p.payment_date) <= datetime(i.due_date) $scope_with_external";
$q = $db->prepare($sql); $q->execute([$sql_date_from, $sql_date_to]); $lunas = $q->fetchColumn() ?: 0;
echo "Lunas Tepat: $lunas\n";

// tunggakan dibayar
$sql = "SELECT SUM(p.amount) as total FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id WHERE p.payment_date BETWEEN ? AND ? AND datetime(p.payment_date) > datetime(i.due_date) $scope_with_external";
$q = $db->prepare($sql); $q->execute([$sql_date_from, $sql_date_to]); $tunggakan = $q->fetchColumn() ?: 0;
echo "Tunggakan Dibayar: $tunggakan\n";

// belum bayar
$sql = "SELECT SUM(i.amount - i.discount) as total FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.due_date BETWEEN ? AND ? AND i.status = 'Belum Lunas' $scope_with_external";
$q = $db->prepare($sql); $q->execute([$date_from, $date_to]); $belum = $q->fetchColumn() ?: 0;
echo "Belum Bayar (due in period): $belum\n";

// list matching invoices
$rows = $db->query("SELECT i.id, i.amount, i.discount, i.due_date, i.created_via, c.id as cust_id, c.name as cust_name, c.type as cust_type FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE (i.created_via IS NOT NULL AND i.created_via <> '') OR c.type IN ('note','temp') ORDER BY i.created_at DESC")->fetchAll();
echo "\nMatching invoices:\n";
foreach($rows as $r) {
    echo sprintf("ID:%d Due:%s Amt:%s Disc:%s Via:%s Cust:%d/%s Type:%s\n", $r['id'], $r['due_date'], $r['amount'], $r['discount'], $r['created_via'] ?? '-', $r['cust_id'], $r['cust_name'], $r['cust_type']);
}
