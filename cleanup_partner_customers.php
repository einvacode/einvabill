<?php
/**
 * Cleanup: Delete customers created by partners
 * This removes customers that were created by partner users
 * These customers are hidden from admin view by RBAC but still exist in DB
 */

require_once 'app/init.php';

$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Get all partner IDs
$partner_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);

if (empty($partner_ids)) {
    echo "❌ Tidak ada partner ditemukan.\n";
    exit;
}

$partner_list = implode(',', $partner_ids);

// Find customers created by partners
$partner_customers = $db->query("
    SELECT id, name, created_by 
    FROM customers 
    WHERE created_by IN ($partner_list) 
    AND tenant_id = $tenant_id
    ORDER BY id ASC
")->fetchAll();

if (empty($partner_customers)) {
    echo "✅ Tidak ada customer yang dibuat oleh partner.\n";
    exit;
}

echo "🔍 Ditemukan " . count($partner_customers) . " customer yang dibuat oleh partner:\n\n";

$total_invoices = 0;
$total_deleted = 0;

$db->beginTransaction();
try {
    foreach ($partner_customers as $cust) {
        $cust_id = $cust['id'];
        $cust_name = $cust['name'];
        
        // Check invoices
        $invoice_count = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = $cust_id AND tenant_id = $tenant_id")->fetchColumn() ?? 0;
        
        echo "• ID $cust_id - $cust_name ($invoice_count invoices)... ";
        
        // Delete invoices first
        if ($invoice_count > 0) {
            $db->prepare("DELETE FROM invoices WHERE customer_id = ? AND tenant_id = ?")->execute([$cust_id, $tenant_id]);
            $total_invoices += $invoice_count;
        }
        
        // Delete payments related to invoices we just deleted
        $db->prepare("DELETE FROM payments WHERE invoice_id NOT IN (SELECT id FROM invoices) AND tenant_id = ?")->execute([$tenant_id]);
        
        // Delete customer
        $db->prepare("DELETE FROM customers WHERE id = ? AND tenant_id = ?")->execute([$cust_id, $tenant_id]);
        
        echo "✅ DELETED\n";
        $total_deleted++;
    }
    
    $db->commit();
    
    echo "\n";
    echo "✅ SELESAI!\n";
    echo "   • Customer dihapus: $total_deleted\n";
    echo "   • Invoice dihapus: $total_invoices\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
