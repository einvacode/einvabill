<?php
// Partner view
$user_id = intval($_SESSION['user_id']);
$stmt_u = $db->prepare("SELECT customer_id FROM users WHERE id = ?");
$stmt_u->execute([$user_id]);
$u = $stmt_u->fetch();
$partner_cid = $u['customer_id'] ?? 0;

$company_wa = $db->query("SELECT company_contact FROM settings WHERE id=1")->fetchColumn();

// Date filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'belum';
$sort_date = $_GET['sort_date'] ?? 'desc';

$params = [$partner_cid];
$date_where = '';
if ($date_from && $date_to) {
    $date_where = " AND i.due_date BETWEEN ? AND ? ";
    $params[] = $date_from;
    $params[] = $date_to;
} elseif ($date_from) {
    $date_where = " AND i.due_date >= ? ";
    $params[] = $date_from;
} elseif ($date_to) {
    $date_where = " AND i.due_date <= ? ";
    $params[] = $date_to;
}

$status_where = '';
if ($filter_status === 'lunas') $status_where = " AND i.status = 'Lunas'";
elseif ($filter_status === 'belum') $status_where = " AND i.status = 'Belum Lunas'";

// Fetch stats if partner is linked
$partner_stats = null;
$partner_invoices = [];
if ($partner_cid) {
    $stmt_stats = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN i.status='Lunas' THEN 1 ELSE 0 END) as lunas,
            SUM(CASE WHEN i.status='Belum Lunas' THEN 1 ELSE 0 END) as belum,
            COALESCE(SUM(CASE WHEN i.status='Lunas' THEN i.amount ELSE 0 END),0) as total_lunas,
            COALESCE(SUM(CASE WHEN i.status='Belum Lunas' THEN i.amount ELSE 0 END),0) as total_belum
        FROM invoices i WHERE i.customer_id = ? $date_where $status_where
    ");
    $stmt_stats->execute($params);
    $partner_stats = $stmt_stats->fetch();
    
    $order_sql = ($sort_date === 'asc') ? 'ASC' : 'DESC';
    
    $stmt_inv = $db->prepare("
        SELECT i.*, c.name FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE c.id = ? $date_where $status_where
        ORDER BY i.due_date $order_sql, i.id DESC
    ");
    $stmt_inv->execute($params);
    $partner_invoices = $stmt_inv->fetchAll();

    // Fetch items for these invoices
    $partner_invoice_items = [];
    if (!empty($partner_invoices)) {
        $p_inv_ids = array_column($partner_invoices, 'id');
        $p_ids_str = implode(',', $p_inv_ids);
        $items_raw = $db->query("SELECT * FROM invoice_items WHERE invoice_id IN ($p_ids_str)")->fetchAll();
        foreach ($items_raw as $item) {
            $partner_invoice_items[$item['invoice_id']][] = $item;
        }
    }
}

// Fetch Active Banners
$active_banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'partner') ORDER BY created_at DESC")->fetchAll();

// NEW: My Own Business Stats (Scoping) - Only count PAST DUE as Tunggakan
$today = date('Y-m-d');
$my_cust_count = $db->query("SELECT COUNT(*) FROM customers WHERE created_by = $user_id")->fetchColumn();
$my_inv_unpaid = $db->query("SELECT COALESCE(SUM(amount), 0) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas' AND i.due_date < '$today'")->fetchColumn();
$count_unpaid = $db->query("SELECT COUNT(DISTINCT i.customer_id) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas' AND i.due_date < '$today'")->fetchColumn();
$my_income_month = $db->query("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND p.payment_date LIKE '" . date('Y-m') . "%'")->fetchColumn();

// Extra Metrics for Partner
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$three_days_later = date('Y-m-d', strtotime('+3 days'));
$due_today = $db->query("SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.due_date = '$today' AND i.status = 'Belum Lunas'")->fetchColumn();
$due_soon = $db->query("SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas' AND i.due_date >= '$tomorrow' AND i.due_date <= '$three_days_later'")->fetchColumn();
$new_this_month = $db->query("SELECT COUNT(*) FROM customers WHERE created_by = $user_id AND strftime('%Y-%m', registration_date) = '" . date('Y-m') . "'")->fetchColumn();

// NEW: Dashboard Summary (Recent Revenue & High-level tasks)
$recent_revenue = $db->query("
    SELECT i.*, c.name as customer_name, p.amount as paid_amount, p.payment_date 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    JOIN customers c ON i.customer_id = c.id 
    WHERE c.created_by = $user_id 
    ORDER BY p.payment_date DESC 
    LIMIT 5
")->fetchAll();

// --- LOGIC CALCULATIONS FOR SUMMARY BAR ---
$current_month_str = date('Y-m');
$total_unpaid_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas'")->fetchColumn();
$total_paid_val = $db->query("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND p.payment_date LIKE '$current_month_str%'")->fetchColumn();

// Calculate Net Profit (Collection - Bills to ISP)
$my_bills_to_isp_paid = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $partner_cid AND status = 'Lunas' AND strftime('%Y-%m', due_date) = '$current_month_str'")->fetchColumn();
$my_net_profit = $total_paid_val - $my_bills_to_isp_paid;
$total_potential = $total_unpaid_val + $total_paid_val;
// --- END LOGIC ---

// Global Settings
$settings = $db->query("SELECT * FROM settings WHERE id = 1")->fetch();
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();

// Partner Branding & Custom Templates
$u_id = $_SESSION['user_id'];
$p_stg = $db->query("SELECT wa_template_paid, brand_bank, brand_rekening FROM users WHERE id = $u_id")->fetch();

$wa_tpl_paid = (!empty($p_stg['wa_template_paid'])) ? $p_stg['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sebesar {nominal} sudah LUNAS. Terima kasih telah berlangganan.");
$rekening_receipt = (!empty($p_stg['brand_bank'])) ? $p_stg['brand_bank'] . " " . $p_stg['brand_rekening'] : $settings['bank_account'];

// Success Modal Data for Partner Dashboard
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact']));
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas'")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $status_wa = ($tunggakan_val > 0) ? "LUNAS SEBAGIAN (Masih ada sisa tunggakan)" : "LUNAS SEPENUHNYA";
        
        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        // Replace Tags
        $receipt_msg = str_replace(
            [
                '{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', 
                '{tunggakan}', '{waktu_bayar}', '{admin}', '{link_tagihan}', '{rekening}', '{nominal}', '{status_pembayaran}', '{sisa_tunggakan}', '{total_bayar}'
            ], 
            [
                $success_data['name'], 
                ($success_data['customer_code'] ?: $success_data['id']), 
                'Rp ' . number_format($success_data['monthly_fee'], 0, ',', '.'),
                ($success_data['package_name'] ?: '-'),
                $months_paid . ' Bulan',
                $tunggakan_display,
                date('d/m/Y H:i') . ' WIB',
                $_SESSION['user_name'],
                $portal_link,
                $rekening_receipt,
                $total_display,
                $status_wa,
                $tunggakan_display,
                $total_display
            ], 
            $wa_tpl_paid
        );
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?>

<style>
/* Flexbox Layout for Partner Command Center */
.tab-flex-container {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px);
    overflow: hidden;
}
@media (max-width: 768px) {
    .tab-flex-container {
        height: calc(100vh - 160px); /* Adjust for mobile bottom nav */
    }
}

.scroll-container {
    flex: 1;
    overflow-y: auto;
    padding-right: 5px;
}
/* Custom Scrollbar */
.scroll-container::-webkit-scrollbar { width: 5px; }
.scroll-container::-webkit-scrollbar-track { background: transparent; }
.scroll-container::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb), 0.2); border-radius: 10px; }
.scroll-container { scrollbar-width: thin; scrollbar-color: rgba(var(--primary-rgb), 0.2) transparent; }

/* Persistent Summary Bar */
.static-summary-bar {
    margin-top: 15px;
    flex-shrink: 0;
    width: 100%;
    animation: fadeInStatic 0.5s ease-out;
}
@keyframes fadeInStatic {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Stat Card Hover Fix */
.stat-card-interactive {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.stat-card-interactive:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15);
}
</style>

<div class="tab-flex-container">
    <!-- STATIC HEADER: Title & Banners -->
    <div style="flex-shrink:0; margin-bottom:10px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; padding:0 5px;">
            <div>
                <h2 style="margin:0; font-size:20px; font-weight:800; color:var(--text-primary);">Dashboard Mitra Reseller</h2>
                <p style="margin:0; font-size:12px; color:var(--text-secondary);"><?= $_SESSION['user_name'] ?> | ID: <?= $partner_cid ?: 'N/A' ?></p>
            </div>
            <a href="index.php?page=admin_customers&action=create" class="btn btn-sm btn-primary" style="height:38px; border-radius:10px; font-weight:700;"><i class="fas fa-user-plus"></i> <span class="hide-mobile">Add Cust</span></a>
        </div>
    </div>

<?php if($success_data): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--success); padding:20px; animation: slideDown 0.4s ease-out; background:rgba(16,185,129,0.1);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
        <div>
            <h3 style="margin:0; color:var(--success); font-size:18px;"><i class="fas fa-check-circle"></i> Pembayaran Berhasil!</h3>
            <p style="margin:5px 0 0; font-size:13px; color:var(--text-secondary);">Tagihan untuk <strong><?= htmlspecialchars($success_data['name']) ?></strong> telah diperbarui.</p>
        </div>
        <button onclick="this.parentElement.parentElement.style.display='none'" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="<?= $success_data['wa_link'] ?>" target="_blank" class="btn" style="background:#25D366; color:white; flex:1; min-width:150px; padding:12px; font-weight:700; text-align:center; text-decoration:none; border-radius:10px;">
            <i class="fab fa-whatsapp"></i> Kirim Notifikasi WA
        </a>
        <a href="index.php?page=admin_invoices&action=print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" class="btn btn-ghost" style="flex:1; min-width:150px; padding:12px; font-weight:700; border-radius:10px; text-align:center; text-decoration:none; border:1px solid var(--glass-border);">
            <i class="fas fa-print"></i> Cetak Struk
        </a>
    </div>
</div>
<?php endif; ?>

    <div class="scroll-container">
        <!-- Banners Section -->
        <?php if(count($active_banners) > 0): ?>
        <div class="banner-container" style="margin-bottom:20px;">
            <?php foreach($active_banners as $banner): ?>
                <div class="glass-panel banner-item" style="padding:15px; border-radius:16px; border-left:4px solid var(--primary); display:flex; gap:15px; align-items:center; margin-bottom:12px; position:relative; overflow:hidden;">
                    <?php if($banner['image_path']): ?>
                        <img src="<?= $banner['image_path'] ?>" style="width:80px; height:60px; object-fit:cover; border-radius:10px; cursor:zoom-in;" onclick="openImagePreview(this.src)">
                    <?php endif; ?>
                    <div class="banner-text">
                        <div style="font-weight:700; color:var(--text-primary); font-size:14px;"><?= htmlspecialchars($banner['title']) ?></div>
                        <div style="font-size:12px; color:var(--text-secondary); line-height:1.4;"><?= nl2br(htmlspecialchars($banner['content'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Support Area -->
        <div class="glass-panel" style="padding: 20px; margin-bottom:20px; border-left:4px solid var(--primary); background:linear-gradient(135deg, rgba(var(--primary-rgb), 0.05), transparent);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h3 style="font-size:16px; margin:0;"><i class="fas fa-headset text-primary"></i> Support ISP Induk</h3>
                <span class="badge badge-success" style="font-size:10px;">AKTIF</span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:13px; color:var(--text-secondary);">Konsultasi Billing & Teknis:</div>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $company_wa) ?>" target="_blank" style="text-decoration:none; color:#25D366; font-weight:800; font-size:15px;">
                    <i class="fab fa-whatsapp"></i> <?= htmlspecialchars($company_wa ?: 'HUBUNGI ISP') ?>
                </a>
            </div>
        </div>

        <!-- MINIMALIST SECTION: Bills to ISP Summary -->
        <div class="glass-panel" style="padding: 24px; margin-bottom:20px; border-left:5px solid var(--danger); background:linear-gradient(to right, rgba(239, 68, 68, 0.05), transparent); cursor:pointer;" onclick="location.href='index.php?page=partner_isp_invoices'">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; gap:15px; align-items:center;">
                    <div style="width:50px; height:50px; border-radius:15px; background:rgba(239, 68, 68, 0.1); color:var(--danger); display:flex; align-items:center; justify-content:center; font-size:24px;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:18px; color:var(--text-primary);">Tagihan Kemitraan Saya</h3>
                        <p style="margin:2px 0 0; font-size:12px; color:var(--text-secondary);">Klik untuk lihat riwayat lengkap tagihan ke pusat.</p>
                    </div>
                </div>
                <div style="text-align:right;">
                    <?php if($partner_stats && $partner_stats['belum'] > 0): ?>
                        <div style="font-size:10px; font-weight:800; color:var(--danger); text-transform:uppercase; letter-spacing:1px;">SISA TUNGGAKAN</div>
                        <div style="font-size:22px; font-weight:900; color:var(--danger);">Rp <?= number_format($partner_stats['total_belum'], 0, ',', '.') ?></div>
                    <?php else: ?>
                        <div style="font-size:10px; font-weight:800; color:var(--success); text-transform:uppercase; letter-spacing:1px;">STATUS</div>
                        <div style="font-size:16px; font-weight:800; color:var(--success);"><i class="fas fa-check-circle"></i> LUNAS TERBAYAR</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div> <!-- end .scroll-container -->

    <!-- PERSISTENT BOTTOM SUMMARY BAR (Quick Check) -->
    <div class="static-summary-bar">
        <div class="glass-panel" style="padding:15px 20px; border-left:4px solid var(--success); background:linear-gradient(to right, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:18px; box-shadow:0 -10px 25px rgba(0,0,0,0.1);">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:42px; height:42px; border-radius:12px; background:var(--success); color:white; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 4px 10px rgba(16, 185, 129, 0.3); flex-shrink:0;">
                    <i class="fas fa-coins"></i>
                </div>
                <div>
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:800;">Laba Bersih Saya (Est)</div>
                    <div style="font-size:18px; font-weight:900; color:var(--text-primary); line-height:1.2;">Rp<?= number_format($my_net_profit, 0, ',', '.') ?></div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:10px; color:var(--text-secondary); font-weight:800; text-transform:uppercase;">Target Penagihan</div>
                <div style="font-size:18px; font-weight:900; color:var(--primary); line-height:1.2;">Rp<?= number_format($total_potential, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
</div> <!-- end .tab-flex-container -->

<!-- Hidden Form for Quick Pay -->
<form id="quickPayForm" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
    <input type="hidden" name="customer_id" id="qp_cust_id">
    <input type="hidden" name="num_months" id="qp_num_months">
</form>

<script>
function quickPay(custId, name, months, total) {
    const formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
    if (confirm(`Konfirmasi pembayaran dari ${name}?\n\nTotal: ${formattedTotal} (${months} Bulan)\n\nTindakan ini akan menandai tagihan sebagai LUNAS.`)) {
        document.getElementById('qp_cust_id').value = custId;
        document.getElementById('qp_num_months').value = months;
        document.getElementById('quickPayForm').submit();
    }
}
</script>
