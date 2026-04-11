<?php
require_once __DIR__ . '/../app/init.php';
$id = 64;
$inv = $db->prepare("SELECT id, created_at, due_date, amount, discount, status, created_via, customer_id FROM invoices WHERE id = ?");
$inv->execute([$id]);
$r = $inv->fetch();
if (!$r) { echo "Invoice not found\n"; exit; }
echo "Invoice ID: " . $r['id'] . "\n";
echo "Created At: " . $r['created_at'] . "\n";
echo "Due Date: " . $r['due_date'] . "\n";
echo "Status: " . $r['status'] . "\n";
echo "Created_via: " . ($r['created_via'] ?? '-') . "\n";
echo "Customer ID: " . ($r['customer_id'] ?? 0) . "\n";

// Check if it matches the 'belum bayar' condition for date range
$date_from = '2026-04-01'; $date_to = '2026-04-12';
$sql_date_from = $date_from . ' 00:00:00'; $sql_date_to = $date_to . ' 23:59:59';
$match = ((($r['due_date'] >= $date_from && $r['due_date'] <= $date_to) || ($r['created_via'] && $r['created_at'] >= $sql_date_from && $r['created_at'] <= $sql_date_to)) && $r['status'] === 'Belum Lunas');
echo "Matches 'belum bayar' filter: " . ($match ? 'YES' : 'NO') . "\n";
