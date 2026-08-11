<?php
/**
 * Orphan Data Cleanup Tool
 * Path: views/admin/cleanup_orphans.php
 * Accessible only to admin - Delete orphan invoices and payments
 */

$u_role = $_SESSION['user_role'] ?? 'guest';
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Only admin can access
if ($u_role !== 'admin') {
    echo '<div class="glass-panel p-5 text-center"><h1>403 Access Denied</h1></div>';
    return;
}

$action = $_GET['action'] ?? 'list';
$result_message = '';
$result_type = '';

// Handle deletion
if ($action === 'delete_invoices' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_ids = $_POST['invoice_ids'] ?? [];
    
    if (!empty($invoice_ids) && isset($db)) {
        $ids_str = implode(',', array_map('intval', $invoice_ids));
        
        try {
            // Delete associated payments first (if any)
            if (method_exists($db, 'query')) {
                $db->query("DELETE FROM payments WHERE invoice_id IN ($ids_str)");
                
                // Then delete invoices
                $db->query("DELETE FROM invoices WHERE id IN ($ids_str) AND tenant_id = $tenant_id");
                
                $result_message = "✅ Successfully deleted " . count($invoice_ids) . " orphan invoice(s)";
                $result_type = 'success';
            }
        } catch (Exception $e) {
            $result_message = "❌ Error deleting invoices: " . $e->getMessage();
            $result_type = 'error';
        }
    }
}

// Get orphan invoices
$orphans = array();
if (isset($db) && method_exists($db, 'query')) {
    try {
        $result = $db->query("
            SELECT 
                i.id, i.customer_id, i.amount, i.due_date, i.status,
                i.created_at
            FROM invoices i
            WHERE tenant_id = $tenant_id
            AND customer_id NOT IN (SELECT id FROM customers WHERE tenant_id = $tenant_id)
            ORDER BY i.created_at DESC
        ");
        $orphans = $result ? $result->fetchAll() : array();
    } catch (Exception $e) {
        $result_message = "❌ Database error: " . $e->getMessage();
        $result_type = 'error';
    }
}

?>

<style>
.cleanup-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 20px;
}

.cleanup-header {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.cleanup-section {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.result-message {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid;
}

.result-message.success {
    background: #dcfce7;
    border-left-color: #15803d;
    color: #15803d;
}

.result-message.error {
    background: #fee2e2;
    border-left-color: #991b1b;
    color: #991b1b;
}

.cleanup-table {
    width: 100%;
    border-collapse: collapse;
}

.cleanup-table thead {
    background: rgba(0, 0, 0, 0.05);
}

.cleanup-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid var(--glass-border);
}

.cleanup-table td {
    padding: 12px;
    border-bottom: 1px solid var(--glass-border);
}

.cleanup-table input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.cleanup-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
}

.btn-delete-all {
    background: #ef4444;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.btn-delete-all:hover {
    background: #dc2626;
}

.btn-cancel {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    color: var(--text-primary);
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.btn-cancel:hover {
    background: rgba(0, 0, 0, 0.05);
}

.selected-count {
    padding: 10px 15px;
    background: rgba(59, 130, 246, 0.1);
    border-radius: 6px;
    color: #3b82f6;
    font-weight: 600;
}

.no-orphans {
    padding: 30px;
    text-align: center;
    background: rgba(16, 185, 129, 0.1);
    border-radius: 8px;
    color: #059669;
}

.info-box {
    background: rgba(59, 130, 246, 0.1);
    border-left: 4px solid #3b82f6;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}
</style>

<div class="cleanup-container">
    <div class="cleanup-header">
        <h1>🧹 Orphan Data Cleanup</h1>
        <p style="color: var(--text-secondary);">Delete invoices that reference non-existent customers</p>
    </div>

    <?php if ($result_message): ?>
        <div class="result-message <?= $result_type ?>">
            <?= $result_message ?>
        </div>
    <?php endif; ?>

    <div class="cleanup-section">
        <?php if (empty($orphans)): ?>
            <div class="no-orphans">
                <h2>✓ No Orphan Invoices Found</h2>
                <p>Your database is clean! All invoices reference valid customers.</p>
                <a href="?page=database_audit" class="btn" style="margin-top: 15px;">Back to Audit Tool</a>
            </div>
        <?php else: ?>
            <div class="info-box">
                <strong>⚠️ Found <?= count($orphans) ?> orphan invoice(s)</strong>
                <p style="margin-top: 8px; margin-bottom: 0;">
                    These invoices reference customers that no longer exist in the database.
                    Select which ones to delete below.
                </p>
            </div>

            <form method="POST" action="?page=cleanup_orphans&action=delete_invoices">
                <table class="cleanup-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="select-all" onchange="toggleAll(this)">
                            </th>
                            <th>Invoice ID</th>
                            <th>Customer ID</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphans as $inv): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="invoice_ids[]" value="<?= $inv['id'] ?>" 
                                       class="orphan-checkbox" onchange="updateCount()">
                            </td>
                            <td><strong>#<?= $inv['id'] ?></strong></td>
                            <td>
                                <span style="background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px;">
                                    <?= $inv['customer_id'] ?> (MISSING)
                                </span>
                            </td>
                            <td>Rp<?= number_format($inv['amount'], 0, ',', '.') ?></td>
                            <td><?= $inv['due_date'] ?></td>
                            <td>
                                <span style="background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px;">
                                    <?= $inv['status'] ?>
                                </span>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($inv['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="cleanup-actions" style="margin-top: 20px;">
                    <div class="selected-count" id="selected-count">
                        0 invoice(s) selected
                    </div>
                    <button type="submit" class="btn-delete-all" id="delete-btn" disabled 
                            onclick="return confirm('⚠️ DELETE PERMANENTLY? This cannot be undone.\\n\\nSelected invoices and their payments will be permanently deleted from the database.')">
                        🗑️ Delete Selected
                    </button>
                    <a href="?page=database_audit" class="btn-cancel">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAll(checkbox) {
    const checkboxes = document.querySelectorAll('.orphan-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateCount();
}

function updateCount() {
    const checkboxes = document.querySelectorAll('.orphan-checkbox:checked');
    const count = checkboxes.length;
    const btn = document.getElementById('delete-btn');
    const selectAll = document.getElementById('select-all');
    
    // Update display
    document.getElementById('selected-count').textContent = 
        count + ' invoice(s) selected';
    
    // Update button state
    btn.disabled = count === 0;
    
    // Update select-all checkbox
    const allCheckboxes = document.querySelectorAll('.orphan-checkbox');
    selectAll.checked = count === allCheckboxes.length && count > 0;
}

// Initialize
updateCount();
</script>
