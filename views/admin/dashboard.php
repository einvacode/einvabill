<?php
/**
 * Dashboard Admin Sederhana & Modern
 * Fokus pada 6 Parameter Utama sesuai permintaan User.
 */
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';
// --- SCOPE OPTIMIZATION ---
// Pre-calculate scoped user IDs to avoid repeated subqueries in SQLite
$partner_user_ids = $db->query("SELECT id FROM users WHERE role = 'partner'")->fetchAll(PDO::FETCH_COLUMN);
$partner_list_str = !empty($partner_user_ids) ? implode(',', $partner_user_ids) : '0';

$scope_where = ($u_role === 'admin') ? " AND (created_by NOT IN ($partner_list_str) OR created_by = 0 OR created_by IS NULL) " : " AND (created_by = $u_id) ";
$c_scope = ($u_role === 'admin') ? " AND (c.created_by NOT IN ($partner_list_str) OR c.created_by = 0 OR c.created_by IS NULL) " : " AND (c.created_by = $u_id) ";

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

    return array_merge($cust_stats, $unpaid_stats, $cash_stats);
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
        'cash_m'       => 'Rp' . number_format($s['cash_m'] ?: 0, 0, ',', '.')
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

$settings = $db->query("SELECT company_name, wa_template_paid, site_url FROM settings WHERE id = 1")->fetch();
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();

// Success Modal for Admin Dashboard
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact'] ?? ''));
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas'")->fetchColumn() ?: 0;
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
        <a href="<?= $success_data['wa_link'] ?>" target="_blank" class="btn btn-sm btn-success" style="padding:10px 20px;"><i class="fab fa-whatsapp"></i> Kirim Notifikasi WA</a>
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
<div style="margin-bottom: 25px;">
    <h2 style="font-size: 24px; font-weight: 800; color: var(--text-primary);"><i class="fas fa-th-large text-primary" style="margin-right: 10px;"></i> Ringkasan Bisnis</h2>
    <p style="color: var(--text-secondary); font-size: 14px;">Pantau performa operasional dan finansial Anda dalam sekejap.</p>
</div>

<!-- Main Statistics Grid -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 25px;">
    <style>
        .stats-grid > .glass-panel { border-top: 4px solid var(--primary); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .stats-grid > .glass-panel:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.25); border-color: #ffffff; }
        @media (max-width: 640px) { 
            .stats-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 8px !important; }
            .stats-grid > div { padding: 10px !important; gap: 8px !important; }
            .stats-grid > div:last-child { grid-column: span 2; }
            .stats-grid i { font-size: 14px !important; }
            .stats-grid .glass-panel > div:first-child { width:32px !important; height:32px !important; }
        }
    </style>
    
    <!-- 1. Pelanggan -->
    <div class="glass-panel" style="border-color: #3b82f6; display:flex; align-items:center; gap:12px; padding:15px; background:linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(59, 130, 246, 0.01) 100%);">
        <div style="background:rgba(59, 130, 246, 0.1); color:#3b82f6; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-users"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Retail</div>
            <div id="stat-retail-count" style="font-size:20px; font-weight:800; line-height:1.2;"><?= number_format($total_customers, 0) ?></div>
            <div id="stat-retail-est" style="font-size:10px; color:#3b82f6; font-weight:700; margin-top:2px;">Estimasi: Rp<?= number_format($est_revenue_cust, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- 2. Mitra -->
    <div class="glass-panel" style="border-color: #a855f7; display:flex; align-items:center; gap:12px; padding:15px; background:linear-gradient(135deg, rgba(168, 85, 247, 0.05) 0%, rgba(168, 85, 247, 0.01) 100%);">
        <div style="background:rgba(168, 85, 247, 0.1); color:#a855f7; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-handshake"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Mitra</div>
            <div id="stat-mitra-count" style="font-size:20px; font-weight:800; line-height:1.2;"><?= number_format($total_partners, 0) ?></div>
            <div id="stat-mitra-est" style="font-size:10px; color:#a855f7; font-weight:700; margin-top:2px;">Estimasi: Rp<?= number_format($est_revenue_part, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- 3. Pelanggan Baru -->
    <div class="glass-panel" style="border-color: #06b6d4; display:flex; align-items:center; gap:12px; padding:15px;">
        <div style="background:rgba(6, 182, 212, 0.1); color:#06b6d4; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-user-plus"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Baru</div>
            <div id="stat-baru-count" style="font-size:20px; font-weight:800; line-height:1.2;"><?= number_format($new_customers_month, 0) ?></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Growth</div>
        </div>
    </div>

    <!-- 4. Piutang Retail -->
    <div class="glass-panel" style="border-color: #ef4444; display:flex; align-items:center; gap:12px; padding:15px;">
        <div style="background:rgba(239, 68, 68, 0.1); color:#ef4444; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Piutang Retail</div>
            <div id="stat-piutang-r" style="font-size:16px; font-weight:800; color:#ef4444; line-height:1.2;">Rp<?= number_format($total_unpaid_cust, 0, ',', '.') ?></div>
            <div id="stat-piutang-r-count" style="font-size:10px; color:#ef4444; font-weight:700; margin-top:2px;"><?= number_format($count_unpaid_cust) ?></div>
        </div>
    </div>

    <!-- 5. Piutang Mitra -->
    <div class="glass-panel" style="border-color: #f43f5e; display:flex; align-items:center; gap:12px; padding:15px;">
        <div style="background:rgba(244, 63, 94, 0.1); color:#f43f5e; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-hand-holding-dollar"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Piutang Mitra</div>
            <div id="stat-piutang-m" style="font-size:16px; font-weight:800; color:#f43f5e; line-height:1.2;">Rp<?= number_format($total_unpaid_part, 0, ',', '.') ?></div>
            <div id="stat-piutang-m-count" style="font-size:10px; color:#f43f5e; font-weight:700; margin-top:2px;"><?= number_format($count_unpaid_part) ?></div>
        </div>
    </div>

    <!-- 6. Koleksi Retail -->
    <div class="glass-panel" style="border-color: #10b981; display:flex; align-items:center; gap:12px; padding:15px;">
        <div style="background:rgba(16, 185, 129, 0.1); color:#10b981; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-coins"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Koleksi Retail</div>
            <div id="stat-koleksi-r" style="font-size:16px; font-weight:800; color:#10b981; line-height:1.2;">Rp<?= number_format($total_received_cust, 0, ',', '.') ?></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Lunas</div>
        </div>
    </div>

    <!-- 7. Koleksi Mitra -->
    <div class="glass-panel" style="border-color: #059669; display:flex; align-items:center; gap:12px; padding:15px;">
        <div style="background:rgba(5, 150, 105, 0.1); color:#059669; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-vault"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Koleksi Mitra</div>
            <div id="stat-koleksi-m" style="font-size:16px; font-weight:800; color:#059669; line-height:1.2;">Rp<?= number_format($total_received_part, 0, ',', '.') ?></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Lunas</div>
        </div>
    </div>

    <!-- 8. Kas Retail (Bulan Ini) -->
    <div class="glass-panel" style="border-color: #f59e0b; display:flex; align-items:center; gap:12px; padding:15px;">
        <div style="background:rgba(245, 158, 11, 0.1); color:#f59e0b; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-money-check-alt"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Kas Retail</div>
            <div id="stat-cash-r" style="font-size:16px; font-weight:800; color:#f59e0b; line-height:1.2;">Rp<?= number_format($cash_monthly_cust, 0, ',', '.') ?></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Bulan Ini</div>
        </div>
    </div>

    <!-- 9. Kas Mitra (Bulan Ini) -->
    <div class="glass-panel" style="border-color: #d97706; display:flex; align-items:center; gap:12px; padding:15px;">
        <div style="background:rgba(217, 119, 6, 0.1); color:#d97706; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-briefcase"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Kas Mitra</div>
            <div id="stat-cash-m" style="font-size:16px; font-weight:800; color:#d97706; line-height:1.2;">Rp<?= number_format($cash_monthly_part, 0, ',', '.') ?></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Bulan Ini</div>
        </div>
    </div>
</div>

<!-- Secondary Components -->
<?php require __DIR__ . '/../components/wa_broadcast.php'; ?>

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
                        <div class="btn-group">
                            <button onclick="quickPay(<?= $ls['cust_id'] ?>, '<?= addslashes($ls['name']) ?>', <?= $ls['months_owed'] ?>, <?= $ls['total_debt'] ?>)" class="btn btn-xs btn-primary">
                                <i class="fas fa-money-bill-wave"></i> Bayar
                            </button>
                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $ls['contact']) ?>" target="_blank" class="btn btn-xs btn-success">
                                <i class="fab fa-whatsapp"></i> Tagih
                            </a>
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
            <i class="fas fa-satellite-dish text-primary"></i> Monitoring Penagihan (Live)
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

</style>

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
