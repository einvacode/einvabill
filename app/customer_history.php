<?php
require_once __DIR__ . '/init.php';

// Security: Check if user is logged in as collector or admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'collector'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$customer_id = intval($_GET['id'] ?? 0);

if (!$customer_id) {
    echo json_encode(['error' => 'Invalid Customer ID']);
    exit;
}

header('Content-Type: application/json');

// 1. Fetch Customer Info
$customer = $db->prepare("SELECT * FROM customers WHERE id = ?");
$customer->execute([$customer_id]);
$cust_data = $customer->fetch();

if (!$cust_data) {
    echo json_encode(['error' => 'Customer not found']);
    exit;
}

// 2. Fetch Invoices and their Payments (Last 12)
$query = "
    SELECT 
        i.id as invoice_id, 
        i.amount as invoice_amount, 
        i.due_date, 
        i.status, 
        p.payment_date, 
        p.amount as paid_amount, 
        u.name as collector_name
    FROM invoices i
    LEFT JOIN payments p ON p.invoice_id = i.id
    LEFT JOIN users u ON p.received_by = u.id
    WHERE i.customer_id = ?
    ORDER BY i.due_date DESC
    LIMIT 12
";
$stmt = $db->prepare($query);
$stmt->execute([$customer_id]);
$history = $stmt->fetchAll();

echo json_encode([
    'customer' => $cust_data,
    'history' => $history
]);
