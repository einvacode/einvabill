<?php
/**
 * API Endpoint: Log WhatsApp Message Delivery
 * Called from wa_broadcast.php when a message is sent
 */

require_once __DIR__ . '/app/init.php';

// Only allow POST from authenticated users
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['invoice_id']) || empty($data['customer_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$tenant_id = $_SESSION['tenant_id'] ?? 1;
$user_id = $_SESSION['user_id'];

try {
    $stmt = $db->prepare("
        INSERT INTO wa_message_logs 
        (invoice_id, customer_id, customer_name, phone_number, message_type, status, sent_by, tenant_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['invoice_id'],
        $data['customer_id'],
        $data['customer_name'] ?? '',
        $data['phone_number'] ?? '',
        $data['message_type'] ?? 'reminder',
        $data['status'] ?? 'sent',
        $user_id,
        $tenant_id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'WA message logged',
        'log_id' => $db->lastInsertId()
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
