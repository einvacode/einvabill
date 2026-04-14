<?php
require_once __DIR__ . '/init.php';

// Security: Check if user is logged in as collector or admin
// Security: Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$customer_id = intval($_GET['id'] ?? 0);

if (!$customer_id) {
    echo json_encode(['error' => 'Invalid Customer ID']);
    exit;
}

header('Content-Type: application/json');

// 1. Fetch Customer Info with Role-based Security
$customer_query = "SELECT * FROM customers WHERE id = ? AND tenant_id = ?";
$params = [$customer_id, $tenant_id];

// If partner, restrict to their own customers
if ($user_role === 'partner') {
    $customer_query .= " AND created_by = ?";
    $params[] = $user_id;
} else if (!in_array($user_role, ['admin', 'collector'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized Role']);
    exit;
}

$stmt_cust = $db->prepare($customer_query);
$stmt_cust->execute($params);
$cust_data = $stmt_cust->fetch();

if (!$cust_data) {
    echo json_encode(['error' => 'Customer not found or Access Denied']);
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
        u.name as collector_name,
        (SELECT GROUP_CONCAT(description, ', ') FROM invoice_items WHERE invoice_id = i.id) as description
    FROM invoices i
    LEFT JOIN payments p ON p.invoice_id = i.id
    LEFT JOIN users u ON p.received_by = u.id
    WHERE i.customer_id = ? AND i.tenant_id = ?
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
