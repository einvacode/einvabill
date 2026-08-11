<?php
/**
 * Dashboard Admin Sederhana & Modern
 * Fokus pada 6 Parameter Utama sesuai permintaan User.
 */
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// --- SCOPE OPTIMIZATION ---
// Pre-calculate scoped user IDs to avoid repeated subqueries in SQLite
$partner_user_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
$partner_list_str = !empty($partner_user_ids) ? implode(',', $partner_user_ids) : '0';

$scope_where = ($u_role === 'admin') ? " AND (created_by NOT IN ($partner_list_str) OR created_by = 0 OR created_by IS NULL) " : " AND (created_by = $u_id) ";
$c_scope = ($u_role === 'admin') ? " AND (c.created_by NOT IN ($partner_list_str) OR c.created_by = 0 OR c.created_by IS NULL) " : " AND (c.created_by = $u_id) ";

// Add Tenant Scoping to base filters
$scope_where = " AND tenant_id = $tenant_id " . $scope_where;
$c_scope = " AND i.tenant_id = $tenant_id " . $c_scope;

// --- CONSOLIDATED STATS ENGINE ---
function get_dashboard_stats($db, $scope_where, $c_scope) {
    // Combine 9 queries into 3 main optimized aggregates
    
    // 1. Customer & Revenue Stats
    $cust_stats = $db->query("
        SELECT 
            SUM(CASE WHEN type='customer' THEN 1 ELSE 0 END) as retail_count,
            SUM(CASE WHEN type='customer' THEN monthly_fee ELSE 0 END) as retail_est,
            SUM(CASE WHEN type='partner' THEN 1 ELSE 0 END) as mitra_count,
            SUM(CASE WHEN type='partner' THEN monthly_fee ELSE 0 END) as mitra_est,
            SUM(CASE WHEN strftime('%Y-%m', registration_date) = strftime('%Y-%m', 'now') THEN 1 ELSE 0 END) as baru_count
        FROM customers 
        WHERE 1=1 $scope_where
    ")->fetch();

    // 2. Unpaid (Piutang) Stats
    $unpaid_stats = $db->query("
        SELECT 
            SUM(CASE WHEN c.type='customer' THEN (i.amount - i.discount) ELSE 0 END) as piutang_r,
            COUNT(DISTINCT CASE WHEN c.type='customer' THEN i.customer_id ELSE NULL END) as piutang_r_c,
            SUM(CASE WHEN c.type='partner' THEN (i.amount - i.discount) ELSE 0 END) as piutang_m,
            COUNT(DISTINCT CASE WHEN c.type='partner' THEN i.customer_id ELSE NULL END) as piutang_m_c
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.status='Belum Lunas' $c_scope
    ")->fetch();

    // 3. Collection (Koleksi) & Cash Flow Stats
    $cash_stats = $db->query("
        SELECT 
            SUM(CASE WHEN c.type='customer' THEN p.amount ELSE 0 END) as koleksi_r,
            SUM(CASE WHEN c.type='partner' THEN p.amount ELSE 0 END) as koleksi_m,
            SUM(CASE WHEN c.type='customer' AND strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now') THEN p.amount ELSE 0 END) as cash_r,
            SUM(CASE WHEN c.type='partner' AND strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now') THEN p.amount ELSE 0 END) as cash_m
        FROM payments p 
        JOIN invoices i ON p.invoice_id = i.id 
        JOIN customers c ON i.customer_id = c.id 
        WHERE 1=1 $c_scope
    ")->fetch();
    
        // 4. External Invoices (created via external integrations or quick temp customers)
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $external_stats = $db->query("SELECT COUNT(*) as ext_count, COALESCE(SUM(i.amount - i.discount),0) as ext_total FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.tenant_id = $tenant_id AND (i.created_via = 'external' OR c.type IN ('note','temp')) $c_scope")->fetch();

    return array_merge($cust_stats, $unpaid_stats, $cash_stats, $external_stats ?? []);
}

// --- AJAX REFRESH ENDPOINT ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    $s = get_dashboard_stats($db, $scope_where, $c_scope);
    echo json_encode([
        'retail_count' => number_format($s['retail_count'] ?: 0, 0),
        'retail_est'   => 'Rp' . number_format($s['retail_est'] ?: 0, 0, ',', '.'),
        'mitra_count'  => number_format($s['mitra_count'] ?: 0, 0),
        'mitra_est'    => 'Rp' . number_format($s['mitra_est'] ?: 0, 0, ',', '.'),
        'baru_count'   => number_format($s['baru_count'] ?: 0, 0),
        'piutang_r'    => 'Rp' . number_format($s['piutang_r'] ?: 0, 0, ',', '.'),
        'piutang_r_c'  => number_format($s['piutang_r_c'] ?: 0),
        'piutang_m'    => 'Rp' . number_format($s['piutang_m'] ?: 0, 0, ',', '.'),
        'piutang_m_c'  => number_format($s['piutang_m_c'] ?: 0),
        'koleksi_r'    => 'Rp' . number_format($s['koleksi_r'] ?: 0, 0, ',', '.'),
        'koleksi_m'    => 'Rp' . number_format($s['koleksi_m'] ?: 0, 0, ',', '.'),
        'cash_r'       => 'Rp' . number_format($s['cash_r'] ?: 0, 0, ',', '.'),
        'cash_m'       => 'Rp' . number_format($s['cash_m'] ?: 0, 0, ',', '.'),
        'ext_count'    => intval($s['ext_count'] ?? 0),
        'ext_total'    => 'Rp' . number_format($s['ext_total'] ?? 0, 0, ',', '.')
    ]);
    exit;
}

// Initial Page Load Stats
$s = get_dashboard_stats($db, $scope_where, $c_scope);

// --- END AJAX ---

// 1. Total Pelanggan (User) - Count & Est. Revenue (Scoped)
$total_customers = $s['retail_count'];
$est_revenue_cust = $s['retail_est'] ?: 0;

// 2. Total Mitra (B2B) - Count & Est. Revenue (Scoped)
$total_partners = $s['mitra_count'];
$est_revenue_part = $s['mitra_est'] ?: 0;

// 3. Pelanggan Baru Bulan Ini (Scoped)
$new_customers_month = $s['baru_count'];

// 4. Belum Bayar (Piutang) - Split Retail vs Partner
$count_unpaid_cust = $s['piutang_r_c'];
$total_unpaid_cust = $s['piutang_r'] ?: 0;

$count_unpaid_part = $s['piutang_m_c'];
$total_unpaid_part = $s['piutang_m'] ?: 0;

// 5. Total Pendapatan Terkumpul - Split Retail vs Partner
$total_received_cust = $s['koleksi_r'] ?: 0;
$total_received_part = $s['koleksi_m'] ?: 0;

// 6. Arus Kas Bulanan 
$cash_monthly_cust = $s['cash_r'] ?: 0;
$cash_monthly_part = $s['cash_m'] ?: 0;

$total_unpaid_all = $total_unpaid_cust + $total_unpaid_part;
$count_unpaid_all = $count_unpaid_cust + $count_unpaid_part;
$total_received_all = $total_received_cust + $total_received_part;
$cash_monthly_all = $cash_monthly_cust + $cash_monthly_part;

$tenant_id = $_SESSION['tenant_id'] ?? 1;
$settings = $db->query("SELECT company_name, wa_template_paid, site_url FROM settings WHERE tenant_id = $tenant_id")->fetch();
if (!$settings) $settings = ['company_name' => 'ISP', 'wa_template_paid' => '', 'site_url' => ''];
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();

// Success Modal for Admin Dashboard
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid AND tenant_id = $tenant_id")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact'] ?? ''));
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas' AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        $receipt_msg = str_replace(
            ['{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', '{tunggakan}', '{waktu_bayar}', '{admin}', '{perusahaan}', '{link_tagihan}'], 
            [$success_data['name'], ($success_data['customer_code'] ?: $success_data['id']), 'Rp ' . number_format($success_data['monthly_fee'], 0, ',', '.'), ($success_data['package_name'] ?: '-'), $months_paid, $tunggakan_display, date('d/m/Y H:i') . ' WIB', $_SESSION['user_name'], $settings['company_name'], $portal_link], 
            $settings['wa_template_paid'] ?: "Halo {nama}, pembayaran {tagihan} LUNAS. Cek nota: {link_tagihan}"
        );
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?><!DOCTYPE html>

<?php if($success_data): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--success); padding:20px; animation: slideDown 0.4s ease-out; background:rgba(16,185,129,0.1);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
        <div>
            <h3 style="margin:0; color:var(--success); font-size:18px;"><i class="fas fa-check-circle"></i> Pembayaran Berhasil!</h3>
            <p style="margin:5px 0 0; font-size:13px; color:var(--text-secondary);">Tagihan <strong><?= htmlspecialchars($success_data['name']) ?></strong> diperbarui.</p>
        </div>
        <button onclick="this.parentElement.parentElement.style.display='none'" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button onclick="sendWAGateway('<?= $wa_num_paid ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $success_data['wa_link'] ?>', this)" class="btn btn-sm btn-success" style="padding:10px 20px;"><i class="fab fa-whatsapp"></i> Kirim Notifikasi WA</button>
    </div>
</div>
<style> @keyframes slideDown { from { transform: translateY(-10px); opacity:0; } to { transform: translateY(0); opacity:1; } } </style>
<?php endif; ?>

<!-- Banner Lisensi -->
<?php if(LICENSE_ST === 'TRIAL'): ?>
    <div class="glass-panel" style="padding: 12px 20px; margin-bottom: 25px; background: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fas fa-clock" style="color: #f59e0b; font-size: 18px;"></i>
            <div style="font-size: 14px; font-weight: 600; color: #f59e0b;"><?= LICENSE_MSG ?></div>
        </div>
        <a href="index.php?page=admin_license" class="btn btn-sm" style="background: #f59e0b; color: white; border-radius: 8px;">Aktivasi Sekarang</a>
    </div>
<?php elseif(LICENSE_ST === 'UNLIMITED'): ?>
    <div style="margin-bottom: 25px; display: flex; justify-content: flex-end;">
        <div class="badge" style="background: rgba(59, 130, 246, 0.1); color: var(--primary); border: 1px solid var(--primary); padding: 8px 20px; border-radius: 50px; font-weight: 700; font-size: 12px;">
            <i class="fas fa-crown"></i> UNLIMITED MASTER LICENSE
        </div>
    </div>
<?php endif; ?>

<!-- Dashboard Title -->
<div style="margin-bottom: 25px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
    <div>
        <h2 style="font-size: 24px; font-weight: 800; color: var(--text-primary);"><i class="fas fa-th-large text-primary" style="margin-right: 10px;"></i> Ringkasan Perusahaan</h2>
        <p style="color: var(--text-secondary); font-size: 14px;">Pantau ringkasan operasional, aset, dan finansial perusahaan dalam sekejap.</p>
    </div>
    <div class="wa-status-indicator" style="cursor:pointer;" onclick="location.href='index.php?page=admin_wa_gateway'">
        <span class="badge" style="background:rgba(148,163,184,0.1); color:#94a3b8; border:1px solid rgba(148,163,184,0.3); font-size:10px;"><i class="fas fa-power-off"></i> WA OFFLINE</span>
    </div>
</div>

<style>
    .dashboard-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 14px;
    }
    .dashboard-links a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        border-radius: 10px;
        border: 1px solid rgba(59, 130, 246, 0.24);
        background: rgba(59, 130, 246, 0.08);
        color: var(--primary);
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
    }
    .dashboard-links a:hover {
        background: rgba(59, 130, 246, 0.14);
    }
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
        margin-bottom: 25px;
    }
    .summary-card {
        text-decoration: none;
        color: inherit;
        border-top: 3px solid var(--primary);
        padding: 14px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.12);
    }
    .summary-label {
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 800;
        color: var(--text-secondary);
        letter-spacing: 0.4px;
    }
    .summary-value {
        font-size: 22px;
        font-weight: 900;
        line-height: 1.2;
        color: var(--text-primary);
    }
    .summary-sub {
        font-size: 12px;
        color: var(--text-secondary);
        font-weight: 600;
    }
    .summary-source {
        margin-top: auto;
        font-size: 12px;
        font-weight: 700;
        color: var(--primary);
    }
    @media (max-width: 640px) {
        .summary-grid {
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .summary-card {
            padding: 12px;
        }
        .summary-value {
            font-size: 18px;
        }
    }
</style>

<div class="dashboard-links">
    <a href="index.php?page=admin_customers"><i class="fas fa-users"></i> Data Pelanggan</a>
    <a href="index.php?page=admin_invoices"><i class="fas fa-file-invoice"></i> Data Tagihan</a>
    <a href="index.php?page=admin_reports"><i class="fas fa-chart-line"></i> Data Laporan</a>
    <a href="index.php?page=admin_expenses"><i class="fas fa-receipt"></i> Data Pengeluaran</a>
</div>

<!-- Main Statistics Grid (Simplified + Linked) -->
<div class="summary-grid">
    <a class="glass-panel summary-card" href="index.php?page=admin_customers">
        <div class="summary-label">Total Pelanggan</div>
        <div id="stat-retail-count" class="summary-value"><?= number_format($total_customers, 0) ?></div>
        <div id="stat-retail-est" class="summary-sub">Estimasi Rp<?= number_format($est_revenue_cust, 0, ',', '.') ?></div>
        <div class="summary-source">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="glass-panel summary-card" href="index.php?page=admin_customers">
        <div class="summary-label">Total Mitra</div>
        <div id="stat-mitra-count" class="summary-value"><?= number_format($total_partners, 0) ?></div>
        <div id="stat-mitra-est" class="summary-sub">Estimasi Rp<?= number_format($est_revenue_part, 0, ',', '.') ?></div>
        <div class="summary-source">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="glass-panel summary-card" href="index.php?page=admin_customers">
        <div class="summary-label">Pelanggan Baru Bulan Ini</div>
        <div id="stat-baru-count" class="summary-value"><?= number_format($new_customers_month, 0) ?></div>
        <div class="summary-sub">Registrasi bulan berjalan</div>
        <div class="summary-source">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="glass-panel summary-card" href="index.php?page=admin_invoices&filter_status=belum">
        <div class="summary-label">Total Piutang</div>
        <div class="summary-value">Rp<?= number_format($total_unpaid_all, 0, ',', '.') ?></div>
        <div class="summary-sub">Pelanggan menunggak: <?= number_format($count_unpaid_all, 0) ?></div>
        <div class="summary-source">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="glass-panel summary-card" href="index.php?page=admin_reports">
        <div class="summary-label">Total Penerimaan</div>
        <div class="summary-value">Rp<?= number_format($total_received_all, 0, ',', '.') ?></div>
        <div class="summary-sub">Akumulasi pembayaran masuk</div>
        <div class="summary-source">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="glass-panel summary-card" href="index.php?page=admin_reports">
        <div class="summary-label">Kas Masuk Bulan Ini</div>
        <div class="summary-value">Rp<?= number_format($cash_monthly_all, 0, ',', '.') ?></div>
        <div class="summary-sub">Arus kas periode berjalan</div>
        <div class="summary-source">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="glass-panel summary-card" href="index.php?page=admin_invoices">
        <div class="summary-label">Invoice External</div>
        <div id="stat-inv-external" class="summary-value"><?='Rp' . number_format($s['ext_total'] ?? 0, 0, ',', '.') ?></div>
        <div class="summary-sub">Jumlah invoice: <?= number_format($s['ext_count'] ?? 0) ?></div>
        <div class="summary-source">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>
</div>

<!-- Secondary Components -->
<?php require __DIR__ . '/../components/wa_broadcast.php'; ?>

<!-- Invoice Reminder Widget (H-3) -->
<div style="margin-top:20px; margin-bottom:20px;">
    <?php 
        require_once __DIR__ . '/../../app/reminder_widget.php';
        render_reminder_widget($db, $tenant_id, $u_id, $u_role, '600px');
    ?>
</div>

<!-- Daftar Tunggakan Teragregasi (Per Customer) -->
<div class="glass-panel" style="padding: 24px; margin-top:20px; border-left: 5px solid #ef4444;">
    <div style="font-size:18px; font-weight:800; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-user-clock text-danger"></i> Daftar Tunggakan Pelanggan (Teragregasi)
        </div>
        <a href="index.php?page=admin_invoices&filter_status=belum" class="btn btn-sm btn-info" style="font-size:11px;">Lihat Semua</a>
    </div>
    
    <div class="table-container" style="max-height:400px; overflow-y:auto; padding-right:5px;">
        <table style="width:100%;">
            <thead>
                <tr>
                    <th style="padding:12px; font-size:11px;">PELANGGAN & AKSI</th>
                    <th style="padding:12px; font-size:11px;">PERIODE</th>
                    <th style="padding:12px; font-size:11px; text-align:right;">TOTAL HUTANG</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $late_summary = $db->query("
                    SELECT 
                        c.id as cust_id, c.name, c.contact, 
                        COUNT(i.id) as months_owed,
                        SUM(i.amount - i.discount) as total_debt
                    FROM invoices i
                    JOIN customers c ON i.customer_id = c.id
                    WHERE i.status = 'Belum Lunas' $c_scope
                    GROUP BY c.id
                    ORDER BY months_owed DESC, total_debt DESC
                    LIMIT 5
                ")->fetchAll();
                
                foreach($late_summary as $ls):
                ?>
                <tr style="border-bottom:1px solid var(--glass-border);">
                    <td style="padding:12px;">
                        <div style="font-weight:700; font-size:14px; color:var(--text-primary);"><?= htmlspecialchars($ls['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px;"><?= htmlspecialchars($ls['contact']) ?></div>
                        <div class="btn-group compact-action-group">
                            <button onclick="quickPay(<?= $ls['cust_id'] ?>, '<?= addslashes($ls['name']) ?>', <?= $ls['months_owed'] ?>, <?= $ls['total_debt'] ?>)" class="btn btn-xs btn-primary">
                                <i class="fas fa-money-bill-wave"></i> Bayar
                            </button>
                            <button onclick="sendWAGateway('<?= preg_replace('/[^0-9]/', '', $ls['contact']) ?>', 'Halo <?= addslashes($ls['name']) ?>, mohon segera melunasi tunggakan sebesar Rp <?= number_format($ls['total_debt'], 0, ',', '.') ?>. Terima kasih.', 'https://wa.me/<?= preg_replace('/[^0-9]/', '', $ls['contact']) ?>', this)" class="btn btn-xs btn-success">
                                <i class="fab fa-whatsapp"></i> Tagih
                            </button>
                        </div>
                    </td>
                    <td style="padding:12px;">
                        <span class="badge" style="background:rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.3); font-size:11px;">
                             <i class="fas fa-history"></i> <?= $ls['months_owed'] ?>
                        </span>
                    </td>
                    <td style="padding:12px;">
                        <div style="font-weight:800; color:#ef4444; font-size:14px;">Rp <?= number_format($ls['total_debt'], 0, ',', '.') ?></div>
                    </td>

                </tr>
                <?php endforeach; ?>
                <?php if(empty($late_summary)): ?>
                <tr>
                    <td colspan="3" style="text-align:center; padding:30px; color:var(--text-secondary);">🎉 Tidak ada tunggakan saat ini.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Financial Activity Pulse (Live Monitor) -->
<div class="glass-panel" style="padding: 24px; margin-top:20px;">
    <div style="font-size:18px; font-weight:800; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-satellite-dish text-primary"></i> Monitoring Transaksi (Live)
        </div>
        <span class="badge" style="background:rgba(16, 185, 129, 0.1); color:#10b981; border:1px solid #10b981; font-size:10px; animation: pulse 2s infinite;">• LIVE PULSE</span>
    </div>
    
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap:15px; max-height:500px; overflow-y:auto; padding-right:5px;">
        <?php
        $latest = $db->query("
            SELECT p.*, c.name as customer_name, u.name as receiver_name
            FROM payments p 
            JOIN invoices i ON p.invoice_id = i.id 
            JOIN customers c ON i.customer_id = c.id 
            LEFT JOIN users u ON p.received_by = u.id
            WHERE 1=1 $c_scope
            ORDER BY p.payment_date DESC LIMIT 10
        ")->fetchAll();
        
        foreach($latest as $l):
            $is_admin = strpos(strtolower($l['receiver_name'] ?? ''), 'admin') !== false;
        ?>
        <div style="display:flex; align-items:center; gap:15px; padding:15px; background:rgba(255,255,255,0.03); border-radius:15px; border:1px solid var(--glass-border); border-left:4px solid #10b981;">
            <div style="width:45px; height:45px; border-radius:12px; background:rgba(16,185,129,0.1); color:#10b981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            </div>
            <div style="flex:1;">
                <div style="font-weight:700; font-size:14px; color:var(--text-primary);"><?= htmlspecialchars($l['customer_name']) ?></div>
                <div style="font-size:11px; color:var(--text-secondary);">
                    Diterima oleh: <span style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($l['receiver_name'] ?? 'Sistem') ?></span>
                    <br>
                    <?= date('d M, H:i', strtotime($l['payment_date'])) ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-weight:900; color:#10b981; font-size:16px;">+Rp <?= number_format($l['amount'], 0, ',', '.') ?></div>
                <div style="font-size:9px; text-transform:uppercase; font-weight:800; color:var(--text-secondary);">Lunas</div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($latest)): ?>
            <div style="grid-column: 1/-1; text-align:center; padding:40px; color:var(--text-secondary); font-size:14px;">
                <i class="fas fa-inbox fa-2x" style="display:block; margin-bottom:10px; opacity:0.3;"></i>
                Belum ada aktivitas penagihan yang tercatat.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden Form for Quick Pay -->
<form id="quickPayForm" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
    <input type="hidden" name="customer_id" id="qp_cust_id">
    <input type="hidden" name="num_months" id="qp_num_months">
</form>

<script>
function quickPay(custId, name, months, total) {
    const formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
    if (confirm(`Proses pembayaran cepat untuk ${name}?\n\nTotal: ${formattedTotal} (${months} Bulan)\n\nTindakan ini akan menandai tagihan tertua sebagai LUNAS.`)) {
        document.getElementById('qp_cust_id').value = custId;
        document.getElementById('qp_num_months').value = months;
        document.getElementById('quickPayForm').submit();
    }
}

// Function to update stats via AJAX
async function updateDashboardStats() {
    try {
        const response = await fetch('index.php?page=admin_dashboard&ajax=stats');
        if (!response.ok) return;
        const data = await response.json();
        
        const updateEl = (id, val) => {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.innerText !== val) {
                el.innerText = val;
                el.style.color = 'var(--primary)';
                el.style.transform = 'scale(1.1)';
                el.style.transition = 'all 0.3s ease';
                setTimeout(() => {
                    el.style.color = '';
                    el.style.transform = 'scale(1)';
                }, 1000);
            }
        };

        updateEl('stat-retail-count', data.retail_count);
        updateEl('stat-retail-est', 'Estimasi: ' + data.retail_est);
        updateEl('stat-mitra-count', data.mitra_count);
        updateEl('stat-mitra-est', 'Estimasi: ' + data.mitra_est);
        updateEl('stat-baru-count', data.baru_count);
        updateEl('stat-piutang-r', data.piutang_r);
        updateEl('stat-piutang-r-count', data.piutang_r_c);
        updateEl('stat-piutang-m', data.piutang_m);
        updateEl('stat-piutang-m-count', data.piutang_m_c);
        updateEl('stat-koleksi-r', data.koleksi_r);
        updateEl('stat-koleksi-m', data.koleksi_m);
        updateEl('stat-cash-r', data.cash_r);
        updateEl('stat-cash-m', data.cash_m);

    } catch (error) {
        console.error('Failed to update stats:', error);
    }
}

// Start polling every 45 seconds
setInterval(updateDashboardStats, 45000);
</script>
