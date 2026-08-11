<?php
/**
 * Admin - Partner Dashboard
 * Menampilkan statistik mitra dan pendapatannya
 */

$tenant_id = $_SESSION['tenant_id'] ?? 1;
$u_id = $_SESSION['user_id'];

// Get all partners with statistics
$partners = $db->query("
    SELECT 
        u.id,
        u.name as partner_name,
        u.email,
        u.phone,
        COUNT(DISTINCT c.id) as total_customers,
        SUM(c.monthly_fee) as estimated_revenue,
        COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) as active_customers,
        COUNT(DISTINCT CASE WHEN c.status = 'inactive' THEN c.id END) as inactive_customers
    FROM users u
    LEFT JOIN customers c ON c.created_by = u.id AND c.tenant_id = $tenant_id
    WHERE u.role = 'partner' 
    AND u.tenant_id = $tenant_id
    GROUP BY u.id
    ORDER BY total_customers DESC
")->fetchAll();

// Get payment statistics per partner
$partner_payments = [];
foreach ($partners as $p) {
    $payments = $db->query("
        SELECT 
            COUNT(*) as total_paid_invoices,
            SUM(p.amount) as total_paid_amount,
            MAX(p.payment_date) as last_payment_date
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN customers c ON i.customer_id = c.id
        WHERE c.created_by = " . $p['id'] . "
        AND c.tenant_id = $tenant_id
    ")->fetch();
    
    $unpaid = $db->query("
        SELECT 
            COUNT(*) as unpaid_count,
            SUM(i.amount - i.discount) as unpaid_amount
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE c.created_by = " . $p['id'] . "
        AND i.status = 'Belum Lunas'
        AND c.tenant_id = $tenant_id
    ")->fetch();
    
    $partner_payments[$p['id']] = [
        'payments' => $payments,
        'unpaid' => $unpaid
    ];
}

// Summary stats for all partners
$summary = $db->query("
    SELECT 
        COUNT(DISTINCT u.id) as total_partners,
        COUNT(DISTINCT c.id) as total_partner_customers,
        SUM(c.monthly_fee) as total_potential_revenue,
        SUM(CASE WHEN c.status = 'active' THEN c.monthly_fee ELSE 0 END) as active_revenue
    FROM users u
    LEFT JOIN customers c ON c.created_by = u.id AND c.tenant_id = $tenant_id
    WHERE u.role = 'partner' 
    AND u.tenant_id = $tenant_id
")->fetch();

$summary_payments = $db->query("
    SELECT 
        SUM(p.amount) as total_collected
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id
    WHERE c.created_by IN (SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id)
    AND c.tenant_id = $tenant_id
")->fetch();

$summary_unpaid = $db->query("
    SELECT 
        SUM(i.amount - i.discount) as total_unpaid
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE c.created_by IN (SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id)
    AND i.status = 'Belum Lunas'
    AND c.tenant_id = $tenant_id
")->fetch();
?>

<style>
.partner-stat-card {
    padding: 16px;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: all 0.3s ease;
}

.partner-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(139, 92, 246, 0.15) 100%);
}

.stat-label {
    font-size: 11px;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 22px;
    font-weight: 900;
    color: var(--text-primary);
    line-height: 1.2;
}

.stat-sublabel {
    font-size: 12px;
    color: var(--text-secondary);
    font-weight: 600;
}

.partner-row-header {
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%);
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 12px;
    border-left: 4px solid var(--primary);
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
    gap: 16px;
    align-items: center;
}

.partner-row {
    background: var(--hover-bg);
    padding: 16px;
    border-radius: 10px;
    margin-bottom: 10px;
    border-left: 3px solid var(--primary);
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
    gap: 16px;
    align-items: center;
}

.partner-row:hover {
    background: linear-gradient(90deg, var(--hover-bg) 0%, rgba(59, 130, 246, 0.05) 100%);
}

.partner-name-block {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.partner-name {
    font-weight: 700;
    color: var(--text-primary);
    font-size: 14px;
}

.partner-contact {
    font-size: 12px;
    color: var(--text-secondary);
}

.stat-metric {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.stat-metric-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-metric-label {
    font-size: 11px;
    color: var(--text-secondary);
    font-weight: 600;
}

.revenue-positive {
    color: var(--success);
}

.revenue-warning {
    color: var(--warning);
}

.revenue-danger {
    color: var(--danger);
}

@media (max-width: 1200px) {
    .partner-row-header,
    .partner-row {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
}

@media (max-width: 768px) {
    .partner-row-header,
    .partner-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }
}
</style>

<div class="content-wrapper">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <div>
            <h1 style="margin: 0; font-size: 28px; font-weight: 900; color: var(--text-primary);">
                <i class="fas fa-handshake" style="margin-right: 10px; color: var(--primary);"></i>Dashboard Mitra
            </h1>
            <p style="margin: 5px 0 0; font-size: 13px; color: var(--text-secondary);">Kelola dan monitor kinerja semua mitra bisnis Anda</p>
        </div>
        <button onclick="refreshDashboard()" class="btn btn-primary" style="height: 40px;">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
    </div>

    <!-- Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
        <div class="partner-stat-card">
            <div class="stat-label">Total Mitra</div>
            <div class="stat-value" style="color: var(--primary);"><?= number_format($summary['total_partners'] ?? 0) ?></div>
            <div class="stat-sublabel">Mitra aktif</div>
        </div>

        <div class="partner-stat-card">
            <div class="stat-label">Pelanggan Mitra</div>
            <div class="stat-value" style="color: #3b82f6;"><?= number_format($summary['total_partner_customers'] ?? 0) ?></div>
            <div class="stat-sublabel">Total dari semua mitra</div>
        </div>

        <div class="partner-stat-card">
            <div class="stat-label">Potensi Pendapatan</div>
            <div class="stat-value revenue-positive">Rp<?= number_format($summary['total_potential_revenue'] ?? 0, 0, ',', '.') ?></div>
            <div class="stat-sublabel">Tagihan perbulan</div>
        </div>

        <div class="partner-stat-card">
            <div class="stat-label">Pendapatan Terkumpul</div>
            <div class="stat-value revenue-positive">Rp<?= number_format($summary_payments['total_collected'] ?? 0, 0, ',', '.') ?></div>
            <div class="stat-sublabel">Total pembayaran</div>
        </div>

        <div class="partner-stat-card">
            <div class="stat-label">Piutang Mitra</div>
            <div class="stat-value revenue-danger">Rp<?= number_format($summary_unpaid['total_unpaid'] ?? 0, 0, ',', '.') ?></div>
            <div class="stat-sublabel">Tagihan belum lunas</div>
        </div>
    </div>

    <!-- Partners List -->
    <div class="glass-panel" style="padding: 24px;">
        <div style="font-size: 18px; font-weight: 800; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-list" style="color: var(--primary);"></i> Daftar Mitra & Statistik
        </div>

        <?php if (empty($partners)): ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--text-secondary);">
            <i class="fas fa-inbox" style="font-size: 40px; opacity: 0.3; margin-bottom: 10px; display: block;"></i>
            <p>Belum ada mitra terdaftar</p>
        </div>
        <?php else: ?>

        <!-- Table Header -->
        <div class="partner-row-header">
            <div class="partner-name-block">
                <span class="stat-label">Nama Mitra</span>
            </div>
            <div class="stat-metric">
                <span class="stat-label">Pelanggan</span>
            </div>
            <div class="stat-metric">
                <span class="stat-label">Pendapatan Bulanan</span>
            </div>
            <div class="stat-metric">
                <span class="stat-label">Terkumpul / Piutang</span>
            </div>
            <div style="text-align: right;">
                <span class="stat-label">Aksi</span>
            </div>
        </div>

        <!-- Table Rows -->
        <?php foreach ($partners as $p): 
            $pay_info = $partner_payments[$p['id']] ?? [];
            $total_collected = $pay_info['payments']['total_paid_amount'] ?? 0;
            $total_unpaid = $pay_info['unpaid']['unpaid_amount'] ?? 0;
            $total_estimated = $p['estimated_revenue'] ?? 0;
            $collection_rate = $total_estimated > 0 ? ($total_collected / $total_estimated) * 100 : 0;
        ?>
        <div class="partner-row">
            <div class="partner-name-block">
                <div class="partner-name"><?= htmlspecialchars($p['partner_name']) ?></div>
                <div class="partner-contact">
                    <i class="fas fa-envelope" style="margin-right: 4px; opacity: 0.6;"></i><?= htmlspecialchars($p['email'] ?? '-') ?>
                </div>
            </div>

            <div class="stat-metric">
                <div class="stat-metric-value"><?= number_format($p['total_customers'] ?? 0) ?></div>
                <div class="stat-metric-label">
                    <span class="revenue-positive"><?= number_format($p['active_customers'] ?? 0) ?> aktif</span>
                    <span style="color: var(--text-secondary); margin: 0 4px;">•</span>
                    <span class="revenue-warning"><?= number_format($p['inactive_customers'] ?? 0) ?> nonaktif</span>
                </div>
            </div>

            <div class="stat-metric">
                <div class="stat-metric-value">Rp<?= number_format($p['estimated_revenue'] ?? 0, 0, ',', '.') ?></div>
                <div class="stat-metric-label">Estimasi bulanan</div>
            </div>

            <div class="stat-metric">
                <div class="stat-metric-value">
                    <span class="revenue-positive">Rp<?= number_format($total_collected, 0, ',', '.') ?></span>
                    <span style="color: var(--text-secondary); margin: 0 4px;">•</span>
                    <span class="revenue-danger">Rp<?= number_format($total_unpaid, 0, ',', '.') ?></span>
                </div>
                <div class="stat-metric-label">
                    Koleksi Rate: <strong><?= number_format($collection_rate, 1) ?>%</strong>
                </div>
            </div>

            <div style="display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;">
                <a href="index.php?page=admin_customers&filter_created_by=<?= $p['id'] ?>" class="btn btn-sm btn-info" title="Lihat pelanggan mitra">
                    <i class="fas fa-users"></i> Pelanggan
                </a>
                <a href="index.php?page=admin_invoices&filter_created_by=<?= $p['id'] ?>" class="btn btn-sm btn-warning" title="Lihat tagihan mitra">
                    <i class="fas fa-file-invoice"></i> Tagihan
                </a>
                <button onclick="viewPartnerDetail(<?= $p['id'] ?>, '<?= addslashes($p['partner_name']) ?>')" class="btn btn-sm btn-primary" title="Detail mitra">
                    <i class="fas fa-eye"></i> Detail
                </button>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div>

<script>
function refreshDashboard() {
    location.reload();
}

function viewPartnerDetail(partnerId, partnerName) {
    alert('Fitur detail partner sedang dikembangkan.\n\nMitra: ' + partnerName + '\nID: ' + partnerId);
    // TODO: Implementasi modal/page untuk detail mitra
}
</script>
