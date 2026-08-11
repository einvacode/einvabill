<?php
/**
 * Database Audit & Cleanup Tool
 * Untuk Admin - Verify data integrity dan cleanup orphan records
 * Path: views/admin/database_audit.php
 */

$u_role = $_SESSION['user_role'] ?? 'guest';
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Only admin can access
if ($u_role !== 'admin') {
    die('<div class="glass-panel p-5 text-center"><h1>403 Access Denied</h1></div>');
}

$action = $_GET['action'] ?? 'overview';
$issue_type = $_GET['type'] ?? '';
?>

<style>
.audit-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.audit-section {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.audit-stat {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-box {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    color: white;
    padding: 15px;
    border-radius: 10px;
    text-align: center;
}

.stat-box.warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.stat-box.danger {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

.stat-box.success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.stat-value {
    font-size: 28px;
    font-weight: 800;
}

.stat-label {
    font-size: 12px;
    opacity: 0.9;
    margin-top: 5px;
}

.issue-list {
    border-top: 1px solid var(--glass-border);
    padding-top: 15px;
}

.issue-item {
    background: rgba(255, 0, 0, 0.05);
    border-left: 4px solid #ef4444;
    padding: 12px;
    margin-bottom: 10px;
    border-radius: 6px;
}

.issue-item.warning {
    background: rgba(245, 158, 11, 0.05);
    border-left-color: #f59e0b;
}

.issue-item.success {
    background: rgba(16, 185, 129, 0.05);
    border-left-color: #10b981;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

table th {
    background: var(--table-header-bg);
    padding: 12px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
}

table td {
    padding: 12px;
    border-bottom: 1px solid var(--glass-border);
}

.btn-cleanup {
    background: #ef4444;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
}

.btn-cleanup:hover {
    background: #dc2626;
}

.btn-review {
    background: #f59e0b;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
}
</style>

<div class="audit-container">
    <h1 style="margin-bottom: 30px;">
        <i class="fas fa-database" style="color: var(--primary); margin-right: 10px;"></i>
        Database Audit & Cleanup
    </h1>

    <!-- Navigation Tabs -->
    <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
        <a href="?page=database_audit&action=overview" class="btn <?= $action === 'overview' ? 'btn-primary' : 'btn-ghost' ?>">
            <i class="fas fa-chart-bar"></i> Overview
        </a>
        <a href="?page=database_audit&action=orphan_invoices" class="btn <?= $action === 'orphan_invoices' ? 'btn-primary' : 'btn-ghost' ?>">
            <i class="fas fa-file-invoice"></i> Orphan Invoices
        </a>
        <a href="?page=database_audit&action=orphan_payments" class="btn <?= $action === 'orphan_payments' ? 'btn-primary' : 'btn-ghost' ?>">
            <i class="fas fa-money-bill"></i> Orphan Payments
        </a>
        <a href="?page=database_audit&action=scope_violations" class="btn <?= $action === 'scope_violations' ? 'btn-primary' : 'btn-ghost' ?>">
            <i class="fas fa-shield-alt"></i> Scope Issues
        </a>
        <a href="?page=database_audit&action=data_quality" class="btn <?= $action === 'data_quality' ? 'btn-primary' : 'btn-ghost' ?>">
            <i class="fas fa-check-circle"></i> Data Quality
        </a>
    </div>

    <?php if ($action === 'overview'): ?>
        <!-- OVERVIEW TAB -->
        <div class="audit-section">
            <h2 style="margin-bottom: 20px;">Database Health Check</h2>
            
            <?php
            // Get statistics
            $total_customers = $db->query("SELECT COUNT(*) as cnt FROM customers WHERE tenant_id = $tenant_id")->fetch()['cnt'] ?? 0;
            $total_invoices = $db->query("SELECT COUNT(*) as cnt FROM invoices WHERE tenant_id = $tenant_id")->fetch()['cnt'] ?? 0;
            $total_payments = $db->query("SELECT COUNT(*) as cnt FROM payments WHERE 1=1")->fetch()['cnt'] ?? 0;
            $total_users = $db->query("SELECT COUNT(*) as cnt FROM users WHERE tenant_id = $tenant_id")->fetch()['cnt'] ?? 0;
            
            // Check for issues
            $orphan_invoices = $db->query("
                SELECT COUNT(*) as cnt FROM invoices i
                WHERE tenant_id = $tenant_id
                AND customer_id NOT IN (SELECT id FROM customers WHERE tenant_id = $tenant_id)
            ")->fetch()['cnt'] ?? 0;
            
            $orphan_payments = $db->query("
                SELECT COUNT(*) as cnt FROM payments p
                WHERE invoice_id NOT IN (SELECT id FROM invoices)
            ")->fetch()['cnt'] ?? 0;
            
            $orphan_customers = $db->query("
                SELECT COUNT(*) as cnt FROM customers
                WHERE tenant_id IS NULL OR tenant_id = ''
            ")->fetch()['cnt'] ?? 0;
            
            $unpaid_invoices = $db->query("
                SELECT COUNT(*) as cnt FROM invoices
                WHERE status = 'Belum Lunas' AND tenant_id = $tenant_id
            ")->fetch()['cnt'] ?? 0;
            
            $health_score = 100;
            if ($orphan_invoices > 0) $health_score -= 20;
            if ($orphan_payments > 0) $health_score -= 20;
            if ($orphan_customers > 0) $health_score -= 15;
            ?>
            
            <div class="audit-stat">
                <div class="stat-box">
                    <div class="stat-value"><?= $total_customers ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $total_invoices ?></div>
                    <div class="stat-label">Total Invoices</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $unpaid_invoices ?></div>
                    <div class="stat-label">Unpaid Invoices</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $total_users ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            </div>

            <div class="audit-stat">
                <div class="stat-box <?= $orphan_invoices > 0 ? 'danger' : 'success' ?>">
                    <div class="stat-value"><?= $orphan_invoices ?></div>
                    <div class="stat-label">Orphan Invoices</div>
                </div>
                <div class="stat-box <?= $orphan_payments > 0 ? 'danger' : 'success' ?>">
                    <div class="stat-value"><?= $orphan_payments ?></div>
                    <div class="stat-label">Orphan Payments</div>
                </div>
                <div class="stat-box <?= $orphan_customers > 0 ? 'warning' : 'success' ?>">
                    <div class="stat-value"><?= $orphan_customers ?></div>
                    <div class="stat-label">Orphan Customers</div>
                </div>
                <div class="stat-box <?= $health_score >= 80 ? 'success' : ($health_score >= 60 ? 'warning' : 'danger') ?>">
                    <div class="stat-value"><?= $health_score ?>%</div>
                    <div class="stat-label">Database Health</div>
                </div>
            </div>

            <div class="issue-list">
                <h3>Issues Found:</h3>
                <?php if ($orphan_invoices > 0): ?>
                    <div class="issue-item danger">
                        <strong>⚠️ Orphan Invoices:</strong> <?= $orphan_invoices ?> invoices reference non-existent customers
                        <br><small>These should be reviewed and deleted if they're old/unpaid test data</small>
                        <br><a href="?page=database_audit&action=orphan_invoices" class="btn-cleanup" style="margin-top: 8px;">Review Details →</a>
                    </div>
                <?php else: ?>
                    <div class="issue-item success">
                        <strong>✓ No orphan invoices found</strong>
                    </div>
                <?php endif; ?>

                <?php if ($orphan_payments > 0): ?>
                    <div class="issue-item danger">
                        <strong>⚠️ Orphan Payments:</strong> <?= $orphan_payments ?> payments reference non-existent invoices
                        <br><small>Data corruption detected. Manual review required.</small>
                        <br><a href="?page=database_audit&action=orphan_payments" class="btn-cleanup" style="margin-top: 8px;">Review Details →</a>
                    </div>
                <?php else: ?>
                    <div class="issue-item success">
                        <strong>✓ No orphan payments found</strong>
                    </div>
                <?php endif; ?>

                <?php if ($orphan_customers > 0): ?>
                    <div class="issue-item warning">
                        <strong>⚠️ Orphan Customers:</strong> <?= $orphan_customers ?> customers have no tenant_id set
                        <br><small>These customers should be assigned to a tenant</small>
                    </div>
                <?php else: ?>
                    <div class="issue-item success">
                        <strong>✓ All customers have valid tenant_id</strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'orphan_invoices'): ?>
        <!-- ORPHAN INVOICES TAB -->
        <div class="audit-section">
            <h2>Orphan Invoices</h2>
            <p style="color: var(--text-secondary); margin-bottom: 15px;">
                Invoices that reference customers that no longer exist
            </p>
            
            <?php
            $orphans = $db->query("
                SELECT 
                    i.id, i.invoice_number, i.customer_id, i.amount, i.due_date, i.status,
                    i.created_at
                FROM invoices i
                WHERE tenant_id = $tenant_id
                AND customer_id NOT IN (SELECT id FROM customers WHERE tenant_id = $tenant_id)
                ORDER BY i.created_at DESC
            ")->fetchAll();
            
            if (empty($orphans)):
            ?>
                <div class="issue-item success">
                    ✓ No orphan invoices found. Database is clean!
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer ID</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orphans as $inv): ?>
                        <tr>
                            <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                            <td><?= $inv['customer_id'] ?> (MISSING)</td>
                            <td>Rp<?= number_format($inv['amount'], 0, ',', '.') ?></td>
                            <td><?= $inv['due_date'] ?></td>
                            <td><span style="background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px;"><?= $inv['status'] ?></span></td>
                            <td><?= date('d/m/Y', strtotime($inv['created_at'])) ?></td>
                            <td>
                                <button onclick="alert('Cleanup: Delete invoice #<?= $inv['id'] ?> - Implement in admin panel')" class="btn-cleanup">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 15px; color: var(--text-secondary); font-size: 12px;">
                    <strong>Recommendation:</strong> Delete orphan invoices if they're old test data or unpaid. 
                    If they're recent, investigate why the customer was deleted.
                </p>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'data_quality'): ?>
        <!-- DATA QUALITY TAB -->
        <div class="audit-section">
            <h2>Data Quality Report</h2>
            
            <?php
            // Check various data quality issues
            $issues = [];
            
            // 1. Customers with missing fields
            $missing = $db->query("
                SELECT COUNT(*) as cnt FROM customers
                WHERE tenant_id = $tenant_id
                AND (name IS NULL OR name = '' OR monthly_fee IS NULL OR monthly_fee <= 0)
            ")->fetch()['cnt'] ?? 0;
            if ($missing > 0) {
                $issues[] = ['severity' => 'danger', 'msg' => "$missing customers with missing/invalid fields (name, monthly_fee)"];
            }
            
            // 2. Invoices with invalid amounts
            $invalid = $db->query("
                SELECT COUNT(*) as cnt FROM invoices
                WHERE tenant_id = $tenant_id
                AND (amount IS NULL OR amount <= 0)
            ")->fetch()['cnt'] ?? 0;
            if ($invalid > 0) {
                $issues[] = ['severity' => 'danger', 'msg' => "$invalid invoices with invalid amount"];
            }
            
            // 3. Users with no password
            $no_pwd = $db->query("
                SELECT COUNT(*) as cnt FROM users
                WHERE password IS NULL OR password = ''
            ")->fetch()['cnt'] ?? 0;
            if ($no_pwd > 0) {
                $issues[] = ['severity' => 'danger', 'msg' => "$no_pwd users without passwords"];
            }
            
            // 4. Duplicate customers (same name, same tenant)
            $dupes = $db->query("
                SELECT COUNT(*) as cnt FROM (
                    SELECT name, tenant_id, COUNT(*) as dup_count
                    FROM customers
                    WHERE tenant_id = $tenant_id
                    GROUP BY name, tenant_id
                    HAVING dup_count > 1
                )
            ")->fetch()['cnt'] ?? 0;
            if ($dupes > 0) {
                $issues[] = ['severity' => 'warning', 'msg' => "$dupes duplicate customer names in same tenant"];
            }
            
            if (empty($issues)):
            ?>
                <div class="issue-item success">
                    ✓ All data quality checks passed! No issues found.
                </div>
            <?php else: ?>
                <?php foreach ($issues as $issue): ?>
                    <div class="issue-item <?= $issue['severity'] ?>">
                        <?= $issue['msg'] ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div style="margin-top: 20px; padding: 15px; background: #eff6ff; border-radius: 8px;">
                <strong>Next Steps:</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <li>Review each issue above</li>
                    <li>Use Admin panels to fix/delete invalid data</li>
                    <li>Re-run this audit after cleanup</li>
                    <li>Backup database before making bulk changes</li>
                </ul>
            </div>
        </div>

    <?php else: ?>
        <div class="audit-section">
            <div class="issue-item warning">
                <strong>Section: <?= htmlspecialchars($action) ?></strong>
                <p>Coming soon. Click tabs above to explore available audits.</p>
            </div>
        </div>
    <?php endif; ?>

</div>
