<?php
// Penagihan Lapangan for Partner (Synchronized with Collector Dashboard)
$user_id = intval($_SESSION['user_id']);

// Base filter: customers created by this partner
$partner_filter = " AND c.created_by = $user_id ";

// Billing date filter (Siklus Tagih)
$filter_billing_date = $_GET['filter_billing_date'] ?? date('j'); // Default to TODAY
$billing_where = "";
if ($filter_billing_date !== "" && $filter_billing_date !== "all") {
    $billing_where = " AND c.billing_date = " . intval($filter_billing_date);
}

// Month selector logic for comprehensive dashboard filtering
$selected_month = $_GET['month'] ?? date('Y-m');
$date_from = date('Y-m-01', strtotime($selected_month . "-01"));
$date_to = date('Y-m-t', strtotime($selected_month . "-01"));

// Override if manual dates are provided
if (isset($_GET['date_from'])) $date_from = $_GET['date_from'];
if (isset($_GET['date_to'])) $date_to = $_GET['date_to'];

$sql_date_from = $date_from . ' 00:00:00';
$sql_date_to = $date_to . ' 23:59:59';

// === SEARCH FILTERS ===
$search_tugas = $_GET['search_tugas'] ?? '';
$where_search_tugas = "";
if ($search_tugas) {
    $st = $db->quote("%$search_tugas%");
    $where_search_tugas = " AND (c.name LIKE $st OR c.customer_code LIKE $st OR c.address LIKE $st OR c.contact LIKE $st)";
}

$search_cust = $_GET['search_cust'] ?? '';
$where_search_cust = "";
if ($search_cust) {
    $sc = $db->quote("%$search_cust%");
    $where_search_cust = " AND (c.name LIKE $sc OR c.customer_code LIKE $sc OR c.address LIKE $sc OR c.contact LIKE $sc)";
}

// === STATISTIK ===
// Total pelanggan saya
$total_customers = $db->query("
    SELECT COUNT(*) FROM customers c WHERE 1=1 $partner_filter $where_search_cust
")->fetchColumn();

// Total tagihan belum lunas (JUMLAH PELANGGAN & NOMINAL)
$unpaid_customers_count = $db->query("
    SELECT COUNT(DISTINCT customer_id) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $partner_filter $where_search_tugas
")->fetchColumn();

$unpaid_count = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $partner_filter $where_search_tugas
")->fetchColumn();

$unpaid_total = $db->query("
    SELECT COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $partner_filter $where_search_tugas
")->fetchColumn();

// Total tagihan lunas di periode terpilih
$paid_total_range = $db->query("
    SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $partner_filter
")->fetchColumn();

// Count of successful payments in range
$paid_count_range = $db->query("
    SELECT COUNT(*) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $partner_filter
")->fetchColumn();

// Net Revenue logic (Collected - Bills paid to ISP in same month)
$partner_cid = $db->query("SELECT customer_id FROM users WHERE id = $user_id")->fetchColumn() ?: 0;
$bills_to_isp_paid = $db->query("
    SELECT COALESCE(SUM(amount - discount), 0) FROM invoices 
    WHERE customer_id = $partner_cid 
    AND status = 'Lunas' 
    AND strftime('%Y-%m', due_date) = '" . date('Y-m', strtotime($date_from)) . "'
")->fetchColumn();
$net_revenue = $paid_total_range - $bills_to_isp_paid;

// Pendaftaran Baru
$new_customers_range = $db->query("
    SELECT COUNT(*) FROM customers c 
    WHERE c.registration_date BETWEEN '$date_from' AND '$date_to'
    $partner_filter
")->fetchColumn();

// Pengeluaran Mitra (Partner's own expenses)
$expenses_range = $db->query("
    SELECT COALESCE(SUM(amount), 0) FROM expenses 
    WHERE date BETWEEN '$date_from' AND '$date_to'
    AND created_by = $user_id
")->fetchColumn();

// Progres Penagihan
$total_potential = $unpaid_total + $paid_total_range;
$percent_paid = $total_potential > 0 ? round(($paid_total_range / $total_potential) * 100) : 0;

// === TAB DATA FETCHING ===
$coll_tab = $_GET['tab'] ?? 'tugas';

// 1. Tugas Penagihan (Arrears)
$query_unpaid = "
    SELECT 
        c.id as cust_id, c.name, c.address, c.contact, c.customer_code, c.package_name, c.monthly_fee, c.billing_date,
        COUNT(i.id) as num_arrears,
        SUM(i.amount - COALESCE(i.discount, 0)) as total_unpaid,
        MIN(i.due_date) as oldest_due_date
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $partner_filter $billing_where $where_search_tugas
    GROUP BY c.id
    ORDER BY c.billing_date ASC, oldest_due_date ASC
";
$unpaid_invoices = $db->query($query_unpaid)->fetchAll();

// 2. Sudah Lunas (History)
$recent_paid = $db->query("
    SELECT i.*, c.name, c.contact, c.customer_code, c.package_name, p.payment_date, p.amount as paid_amount, u.name as admin_name,
    (SELECT COALESCE(SUM(amount - COALESCE(discount, 0)), 0) FROM invoices WHERE customer_id = i.customer_id AND status = 'Belum Lunas') as total_tunggakan
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN payments p ON p.invoice_id = i.id
    LEFT JOIN users u ON p.received_by = u.id
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $partner_filter
    ORDER BY p.id DESC
")->fetchAll();

// 3. Daftar Pelanggan (Database)
$items_per_page = 50;
$p_cust = isset($_GET['p_cust']) ? max(1, intval($_GET['p_cust'])) : 1;
$off_cust = ($p_cust - 1) * $items_per_page;
$total_cust_pages = ceil($total_customers / $items_per_page);

$cust_query = "
    SELECT c.*, 
    (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND status = 'Belum Lunas') as unpaid_count
    FROM customers c 
    WHERE 1=1 $partner_filter $where_search_cust 
    ORDER BY c.id DESC LIMIT $items_per_page OFFSET $off_cust
";
$area_customers = $db->query($cust_query)->fetchAll();

// Settings & Banners
$settings = $db->query("SELECT * FROM settings WHERE id = 1")->fetch();
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();
$banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'partner') ORDER BY created_at DESC")->fetchAll();

// WA Templates
$p_stg = $db->query("SELECT wa_template_paid, brand_bank, brand_rekening FROM users WHERE id = $user_id")->fetch();
$wa_tpl_paid = (!empty($p_stg['wa_template_paid'])) ? $p_stg['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sebesar {nominal} sudah LUNAS.");
$wa_tpl_unpaid = $settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}.";
$rekening_receipt = (!empty($p_stg['brand_bank'])) ? $p_stg['brand_bank'] . " " . $p_stg['brand_rekening'] : $settings['bank_account'];

// Success Modal Data
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
        $receipt_msg = str_replace(
            [
                '{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', '{tunggakan}', 
                '{waktu_bayar}', '{admin}', '{link_tagihan}', '{rekening}', '{nominal}',
                '{status_pembayaran}', '{sisa_tunggakan}', '{total_bayar}'
            ], 
            [
                $success_data['name'], 
                ($success_data['customer_code'] ?: $success_data['id']), 
                'Rp ' . number_format($success_data['monthly_fee'], 0, ',', '.'), 
                ($success_data['package_name'] ?: '-'), 
                $months_paid . ' Bulan', 
                $tunggakan_display, 
                date('d/m/Y H:i'), 
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
/* Flexbox Layout for Collection Command Center */
.tab-flex-container {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 250px);
    overflow: hidden;
}
@media (max-width: 768px) {
    .tab-flex-container {
        height: calc(100vh - 310px); /* Adjust for mobile bottom nav */
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
    margin-top: 10px;
    flex-shrink: 0;
    width: 100%;
    animation: fadeInStatic 0.4s ease-out;
}
@keyframes fadeInStatic {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Stat Card Click Animation & Uniform Layout */
.stat-card-interactive {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 155px;
    padding: 15px;
}
.stat-card-interactive:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15);
}

/* Responsive Visibility */
@media (min-width: 769px) {
    .customer-list-desktop { display: block !important; }
    .customer-list-mobile { display: none !important; }
    .hide-mobile { display: inline !important; }
}
@media (max-width: 768px) {
    .customer-list-desktop { display: none !important; }
    .customer-list-mobile { display: block !important; }
    .hide-mobile { display: none !important; }
}
</style>

<?php if($success_data): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:5px solid var(--success); padding:0; overflow:hidden; animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1); border-radius:20px; box-shadow:0 15px 35px rgba(0,0,0,0.2);">
    <!-- Header Success -->
    <div style="background:rgba(16, 185, 129, 0.08); padding:20px; border-bottom:1px solid rgba(16, 185, 129, 0.1);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div style="display:flex; gap:15px; align-items:center;">
                <div style="width:45px; height:45px; border-radius:14px; background:var(--success); color:white; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 8px 15px rgba(16, 185, 129, 0.3);">
                    <i class="fas fa-check"></i>
                </div>
                <div>
                    <h3 style="margin:0; color:var(--text-primary); font-size:18px; font-weight:800;">Pelunasan Berhasil!</h3>
                    <div style="font-size:13px; color:var(--text-secondary); margin-top:2px;">Tagihan <strong><?= htmlspecialchars($success_data['name']) ?></strong> telah diperbarui.</div>
                </div>
            </div>
            <button onclick="this.closest('.glass-panel').style.display='none'" style="background:rgba(255,255,255,0.05); border:none; color:var(--text-secondary); width:32px; height:32px; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;">&times;</button>
        </div>
    </div>

    <!-- Action Buttons Group -->
    <div style="padding:15px 20px; display:flex; gap:12px; flex-direction:column;">
        <a href="<?= $success_data['wa_link'] ?>" target="_blank" style="display:flex; align-items:center; justify-content:center; gap:10px; background:#25D366; color:white; text-decoration:none; padding:14px; border-radius:15px; font-weight:800; font-size:14px; box-shadow:0 10px 20px rgba(37, 211, 102, 0.2); transition:all 0.3s; width:100%;">
            <i class="fab fa-whatsapp" style="font-size:18px;"></i> KIRIM NOTA KE WHATSAPP
        </a>
        <a href="index.php?page=admin_invoices&action=print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" style="display:flex; align-items:center; justify-content:center; gap:10px; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--text-primary); text-decoration:none; padding:12px; border-radius:15px; font-weight:700; font-size:13px; transition:all 0.3s;">
            <i class="fas fa-print"></i> CETAK STRUK PEMBAYARAN
        </a>
    </div>
</div>
<style>
@keyframes slideDown { 
    from { transform: translateY(-20px); opacity:0; } 
    to { transform: translateY(0); opacity:1; } 
}
</style>
<?php endif; ?>

<!-- Header Period Selector -->
<div class="glass-panel" style="padding:15px; margin-bottom:15px; border-radius:16px;">
    <form method="GET" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <input type="hidden" name="page" value="partner_collection">
        <input type="hidden" name="tab" value="<?= $coll_tab ?>">
        
        <div style="display:flex; align-items:center; gap:8px;">
            <div style="width:40px; height:40px; border-radius:12px; background:rgba(var(--primary-rgb), 0.1); color:var(--primary); display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-motorcycle"></i>
            </div>
            <div>
                <h4 style="margin:0; font-size:15px; font-weight:800;">Penagihan Mitra</h4>
                <div style="font-size:10px; color:var(--text-secondary);"><?= date('l, d/m/Y') ?></div>
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.03); padding:5px 12px; border-radius:12px; border:1px solid var(--glass-border);">
            <i class="far fa-calendar-alt" style="color:var(--primary); font-size:14px;"></i>
            <select name="month" onchange="this.form.submit()" style="background:none; border:none; color:var(--text-primary); font-size:13px; font-weight:700; outline:none; cursor:pointer;">
                <?php
                for ($i = 0; $i < 6; $i++) {
                    $m = date('Y-m', strtotime("-$i month"));
                    $label = date('m/Y', strtotime("-$i month"));
                    echo "<option value=\"$m\" " . ($selected_month === $m ? 'selected' : '') . ">$label</option>";
                }
                ?>
            </select>
        </div>
    </form>
</div>

<!-- Statistik Cards (Collector Dashboard Style) -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap:12px; margin-bottom:16px;">
    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--primary); background:linear-gradient(135deg, rgba(37, 99, 235, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=partner_collection&tab=pelanggan&month=<?= $selected_month ?>'">
        <i class="fas fa-users" style="font-size:20px; color:var(--primary); margin-bottom:8px;"></i>
        <div style="font-size:22px; font-weight:800; color:var(--text-primary);"><?= $total_customers ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Pelanggan</div>
    </div>

    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--danger); background:linear-gradient(135deg, rgba(239, 68, 68, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=partner_collection&tab=tugas&month=<?= $selected_month ?>'">
        <i class="fas fa-exclamation-circle" style="font-size:20px; color:var(--danger); margin-bottom:8px;"></i>
        <div style="font-size:22px; font-weight:800; color:var(--text-primary);"><?= $unpaid_count ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Belum Lunas</div>
        <div style="font-size:12px; font-weight:800; color:var(--danger); margin-top:5px;">Rp<?= number_format($unpaid_total, 0, ',', '.') ?></div>
    </div>

    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--success); background:linear-gradient(135deg, rgba(16, 185, 129, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=partner_collection&tab=lunas&month=<?= $selected_month ?>'">
        <i class="fas fa-check-circle" style="font-size:20px; color:var(--success); margin-bottom:8px;"></i>
        <div style="font-size:22px; font-weight:800; color:var(--text-primary);"><?= $paid_count_range ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Berhasil</div>
        <div style="font-size:12px; font-weight:800; color:var(--success); margin-top:5px;">Rp<?= number_format($paid_total_range, 0, ',', '.') ?></div>
    </div>

    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--warning); background:linear-gradient(135deg, rgba(245, 158, 11, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=partner_collection&tab=lunas&month=<?= $selected_month ?>'">
        <i class="fas fa-coins" style="font-size:20px; color:var(--warning); margin-bottom:8px;"></i>
        <div style="font-size:15px; font-weight:800; color:var(--text-primary);">Rp<?= number_format($net_revenue, 0, ',', '.') ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Pendapatan Bersih</div>
        <div style="font-size:10px; font-weight:700; color:var(--warning); margin-top:5px;">Estimasi Laba</div>
    </div>
</div>

<!-- Tab Navigation (Segmented Style) -->
<div style="display:flex; background:rgba(255,255,255,0.03); padding:5px; border-radius:14px; margin-bottom:20px; gap:5px;">
    <a href="index.php?page=partner_collection&tab=tugas&month=<?= $selected_month ?>" style="flex:1; text-align:center; text-decoration:none; padding:12px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'tugas' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        Tugas <span class="badge" style="background:rgba(255,255,255,0.2); font-size:9px;"><?= count($unpaid_invoices) ?></span>
    </a>
    <a href="index.php?page=partner_collection&tab=lunas&month=<?= $selected_month ?>" style="flex:1; text-align:center; text-decoration:none; padding:12px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'lunas' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        Lunas <span class="badge" style="background:rgba(255,255,255,0.2); font-size:9px;"><?= count($recent_paid) ?></span>
    </a>
    <a href="index.php?page=partner_collection&tab=pelanggan&month=<?= $selected_month ?>" style="flex:1; text-align:center; text-decoration:none; padding:12px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'pelanggan' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        Database
    </a>
</div>

<!-- Tab Contents -->
<?php if($coll_tab === 'tugas'): ?>
<!-- TAB: Tugas Penagihan -->
<div class="glass-panel tab-flex-container" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
        <h3 style="font-size:16px; margin:0; color:var(--danger);"><i class="fas fa-tasks"></i> Daftar Tugas Belum Lunas</h3>
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="font-size:10px; font-weight:800; color:var(--text-secondary);">SIKLUS:</span>
            <form method="GET" style="display:inline;">
                <input type="hidden" name="page" value="partner_collection">
                <input type="hidden" name="tab" value="tugas">
                <select name="filter_billing_date" onchange="this.form.submit()" style="padding:4px 10px; border-radius:8px; background:rgba(var(--primary-rgb), 0.1); border:1px solid var(--primary); color:var(--primary); font-size:11px; font-weight:800; cursor:pointer;">
                    <option value="all" <?= $filter_billing_date === 'all' ? 'selected' : '' ?>>SEMUA TANGGAL</option>
                    <?php for($i=1; $i<=31; $i++): ?>
                        <option value="<?= $i ?>" <?= $filter_billing_date == $i ? 'selected' : '' ?>>Tanggal <?= $i ?><?= $i == date('j') ? ' (Hari Ini)' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- Search in Tasks -->
    <form method="GET" style="margin-bottom:15px; display:flex; gap:10px;">
        <input type="hidden" name="page" value="partner_collection">
        <input type="hidden" name="tab" value="tugas">
        <div style="position:relative; flex:1;">
            <input type="text" name="search_tugas" class="form-control" placeholder="Cari nama/alamat..." value="<?= htmlspecialchars($search_tugas) ?>" style="padding:10px 40px 10px 15px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); width:100%;">
            <button type="submit" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--primary); cursor:pointer;">
                <i class="fas fa-search"></i>
            </button>
        </div>
    </form>

    <div class="scroll-container">
        <div class="grid-items" style="gap:15px;">
            <?php foreach($unpaid_invoices as $ui): 
                $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ui['contact']));
                $is_overdue = strtotime($ui['oldest_due_date']) < time();
            ?>
            <div class="glass-panel" style="padding:16px; border-left:5px solid <?= $is_overdue ? 'var(--danger)' : 'var(--warning)' ?>;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div>
                        <div style="font-weight:800; font-size:16px; color:var(--text-primary);"><?= htmlspecialchars($ui['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;"><i class="fas fa-calendar-alt text-primary"></i> Tagih Tanggal: <strong><?= $ui['billing_date'] ?? '-' ?></strong> | <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ui['address'] ?: '-') ?></div>
                    </div>
                    <span class="badge <?= $is_overdue ? 'badge-danger' : 'badge-warning' ?>" style="font-size:10px; font-weight:800;"><?= $ui['num_arrears'] ?> BULAN</span>
                </div>
                <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:10px; margin-bottom:15px; border:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:11px; font-weight:700; color:var(--text-secondary);">TOTAL TAGIHAN</span>
                    <span style="font-size:18px; font-weight:900; color:var(--text-primary);">Rp<?= number_format($ui['total_unpaid'], 0, ',', '.') ?></span>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 45px; gap:8px;">
                    <button onclick="handlePay(<?= $ui['cust_id'] ?>, <?= $ui['num_arrears'] ?>, '<?= addslashes($ui['name']) ?>', <?= $ui['monthly_fee'] ?>)" class="btn btn-primary" style="padding:12px; font-weight:800; border-radius:12px; font-size:13px;">
                        <i class="fas fa-wallet"></i> BAYAR SEKARANG
                    </button>
                    <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>" target="_blank" class="btn btn-ghost" style="color:#25D366; border-color:rgba(37,211,102,0.2); border-radius:12px; display:flex; align-items:center; justify-content:center; padding:0;">
                        <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($unpaid_invoices)): ?>
                <div style="text-align:center; padding:50px; opacity:0.5;">
                    <i class="fas fa-check-circle fa-3x" style="color:var(--success); margin-bottom:15px;"></i>
                    <p>Semua tagihan sudah tertagih!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Static for Tasks -->
    <div class="static-summary-bar">
        <div class="glass-panel" style="padding:12px 18px; border-left:4px solid var(--danger); background:linear-gradient(to right, rgba(239, 68, 68, 0.12), rgba(239, 68, 68, 0.04)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:15px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:var(--danger); color:white; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.8); text-transform:uppercase; font-weight:800;">Total Piutang</div>
                    <div style="font-size:16px; font-weight:800; color:white; line-height:1.1;">Rp<?= number_format($unpaid_total, 0, ',', '.') ?></div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:9px; color:rgba(255,255,255,0.8); font-weight:800; text-transform:uppercase;">Volume</div>
                <div style="font-size:16px; font-weight:800; color:white; line-height:1.1;"><?= $unpaid_count ?> <small style="font-size:9px; opacity:0.8;">TAGIHAN</small></div>
            </div>
        </div>
    </div>
</div>

<?php elseif($coll_tab === 'lunas'): ?>
<!-- TAB: Sudah Lunas -->
<div class="glass-panel tab-flex-container" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
        <h3 style="font-size:16px; margin:0; color:var(--success);">
            Riwayat Lunas
        </h3>
        <!-- Date Filter -->
        <form method="GET" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="page" value="partner_collection">
            <input type="hidden" name="tab" value="lunas">
            <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>" style="font-size:12px; width:130px; border-radius:8px;">
            <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>" style="font-size:12px; width:130px; border-radius:8px;">
            <button type="submit" class="btn btn-sm btn-primary" style="height:36px;"><i class="fas fa-filter"></i></button>
        </form>
    </div>

    <div class="scroll-container">
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:12px;">
            <?php foreach($recent_paid as $rp): 
                $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $rp['contact']));
            ?>
            <div class="glass-panel" style="padding:18px; border-left:5px solid var(--success); border-radius:18px; transition:all 0.3s; box-shadow:0 8px 15px rgba(0,0,0,0.1); background:rgba(255,255,255,0.02);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div style="flex:1;">
                        <div style="font-weight:800; font-size:15px; color:var(--text-primary); letter-spacing:0.5px;"><?= htmlspecialchars($rp['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px; display:flex; align-items:center; gap:5px;">
                            <i class="fas fa-calendar-check" style="color:var(--success); font-size:12px;"></i> <?= date('d/m/Y', strtotime($rp['payment_date'])) ?> 
                            <span style="opacity:0.4;">|</span> 
                            <i class="far fa-clock" style="opacity:0.6;"></i> <?= date('H:i', strtotime($rp['payment_date'])) ?>
                        </div>
                    </div>
                </div>

                <div style="background:rgba(16, 185, 129, 0.05); padding:12px; border-radius:12px; margin:15px 0; border:1px solid rgba(16, 185, 129, 0.1); display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Nominal Diterima</span>
                    <span style="font-weight:900; color:var(--success); font-size:17px;">Rp<?= number_format($rp['paid_amount'], 0, ',', '.') ?></span>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 45px; gap:10px;">
                    <a href="index.php?page=admin_invoices&action=print&id=<?= $rp['id'] ?>&format=thermal" target="_blank" style="display:flex; align-items:center; justify-content:center; gap:8px; background:rgba(var(--primary-rgb), 0.1); border:1px solid rgba(var(--primary-rgb), 0.2); color:var(--primary); text-decoration:none; padding:10px; border-radius:12px; font-weight:800; font-size:12px; transition:all 0.2s;">
                        <i class="fas fa-print"></i> CETAK KWITANSI
                    </a>
                    <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>" target="_blank" style="display:flex; align-items:center; justify-content:center; background:rgba(37, 211, 102, 0.1); border:1px solid rgba(37, 211, 102, 0.2); color:#25D366; text-decoration:none; border-radius:12px; height:42px; transition:all 0.2s;">
                        <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Summary Static for Lunas -->
    <div class="static-summary-bar">
        <div class="glass-panel" style="padding:12px 18px; border-left:4px solid var(--success); background:linear-gradient(to right, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.04)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:15px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:var(--success); color:white; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
                </div>
                <div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.8); text-transform:uppercase; font-weight:800;">Total Terkumpul</div>
                    <div style="font-size:16px; font-weight:800; color:white; line-height:1.1;">Rp<?= number_format($paid_total_range, 0, ',', '.') ?></div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:9px; color:rgba(255,255,255,0.8); font-weight:800; text-transform:uppercase;">Tagihan</div>
                <div style="font-size:16px; font-weight:800; color:white; line-height:1.1;"><?= $paid_count_range ?> <small style="font-size:9px; opacity:0.8;">TAGIHAN</small></div>
            </div>
        </div>
    </div>
</div>

<?php elseif($coll_tab === 'pelanggan'): ?>
<!-- TAB: Database Pelanggan -->
<div class="glass-panel tab-flex-container" style="padding:20px;">
    <div style="margin-bottom:15px; display:flex; gap:10px;">
        <form method="GET" style="flex:1; display:flex; gap:8px;">
            <input type="hidden" name="page" value="partner_collection">
            <input type="hidden" name="tab" value="pelanggan">
            <input type="text" name="search_cust" class="form-control" placeholder="Cari Pelanggan..." value="<?= htmlspecialchars($search_cust) ?>" style="height:42px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); flex:1;">
            <button type="submit" class="btn btn-primary" style="width:auto; height:42px; padding:0 20px; border-radius:12px;"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <div class="scroll-container">
        <div class="grid-items" style="gap:12px;">
            <?php foreach($area_customers as $ac): ?>
            <div class="glass-panel" style="padding:15px; border-left:3px solid var(--primary); display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-weight:700;"><?= htmlspecialchars($ac['name']) ?></div>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:2px;"><?= htmlspecialchars($ac['package_name']) ?> • Tanggal <?= $ac['billing_date'] ?></div>
                </div>
                <div style="display:flex; gap:6px;">
                    <button onclick="showCustomerDetails(<?= $ac['id'] ?>)" class="btn btn-sm btn-ghost" style="width:34px; height:34px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:8px;"><i class="fas fa-eye"></i></button>
                    <?php if($ac['unpaid_count'] > 0): ?>
                    <button onclick="handlePay(<?= $ac['id'] ?>, <?= $ac['unpaid_count'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)" class="btn btn-sm" style="background:var(--success); color:white; height:34px; padding:0 12px; border-radius:8px; font-weight:800;">BAYAR</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Summary Static for Customers -->
    <div class="static-summary-bar">
        <div class="glass-panel" style="padding:12px 18px; border-left:4px solid var(--primary); background:linear-gradient(to right, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.04)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:15px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:var(--primary); color:white; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.8); text-transform:uppercase; font-weight:800;">Total Pelanggan</div>
                    <div style="font-size:16px; font-weight:800; color:white; line-height:1.1;"><?= number_format($total_customers, 0, ',', '.') ?> <small style="font-size:9px; opacity:0.8;">USER</small></div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:9px; color:rgba(255,255,255,0.8); font-weight:700; text-transform:uppercase;">ID Mitra</div>
                <div style="font-size:16px; font-weight:800; color:white; line-height:1.1;"><?= $partner_cid ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- End of Tabs -->

<!-- Modal Bulk Pay (Sync with Collector) -->
<div id="bulkPayModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px; border-top:4px solid var(--success);">
        <h3 style="margin-bottom:10px; font-weight:800;"><i class="fas fa-layer-group text-success"></i> Pelunasan Tunggakan</h3>
        <p style="font-size:14px; color:var(--text-secondary); margin-bottom:20px;">Bayar sebagian atau seluruh tunggakan untuk <strong><span id="bulkCustNameTitle"></span></strong>.</p>
        
        <div class="form-group mb-4">
            <label style="display:block; font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:10px;">Berapa Bulan Dijadikan Lunas?</label>
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="flex:1;">
                    <input type="number" id="bulkMonthInput" class="form-control" value="1" min="1" oninput="updateBulkTotalDisplay()" style="padding:12px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); width:100%; font-weight:800; font-size:18px; text-align:center;">
                </div>
                <div style="font-weight:600; color:var(--text-secondary);">Dari <span id="bulkTotalMonthsTitle"></span> Bulan</div>
            </div>
        </div>

        <div style="padding:15px; background:rgba(16, 185, 129, 0.05); border:1px solid rgba(16, 185, 129, 0.2); border-radius:12px; margin-bottom:24px;">
            <div style="font-size:11px; color:var(--text-secondary); font-weight:700; margin-bottom:4px;">TOTAL PENERIMAAN:</div>
            <div style="font-size:24px; font-weight:800; color:var(--success);" id="bulkTotalAmtDisplay">Rp 0</div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulkPayModal').style.display='none'">Batal</button>
            <button type="button" class="btn btn-success" style="font-weight:800; padding:10px 25px;" onclick="confirmBulkPay()">KONFIRMASI BAYAR</button>
        </div>
    </div>
</div>

<form id="payFormGlobal" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
    <input type="hidden" name="customer_id" id="globalCustId">
    <input type="hidden" name="num_months" id="globalNumMonths">
</form>

<script>
let currentPayData = { custId: 0, monthlyFee: 0, maxMonths: 0 };

function handlePay(custId, maxMonths, custName, monthlyFee) {
    currentPayData = { custId, monthlyFee, maxMonths };
    document.getElementById('bulkCustNameTitle').innerText = custName;
    document.getElementById('bulkTotalMonthsTitle').innerText = maxMonths;
    const input = document.getElementById('bulkMonthInput');
    input.max = maxMonths;
    input.value = maxMonths;
    updateBulkTotalDisplay();
    document.getElementById('bulkPayModal').style.display = 'flex';
}

function updateBulkTotalDisplay() {
    const input = document.getElementById('bulkMonthInput');
    let val = parseInt(input.value) || 1;
    if(val > currentPayData.maxMonths) { val = currentPayData.maxMonths; input.value = val; }
    if(val < 1) { val = 1; input.value = val; }
    const total = val * currentPayData.monthlyFee;
    const formatted = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
    document.getElementById('bulkTotalAmtDisplay').innerText = formatted;
}

function confirmBulkPay() {
    const months = document.getElementById('bulkMonthInput').value;
    document.getElementById('globalCustId').value = currentPayData.custId;
    document.getElementById('globalNumMonths').value = months;
    document.getElementById('payFormGlobal').submit();
}
</script>

<!-- Modal Detail Pelanggan & Riwayat Pembayaran (Synced from Collector) -->
<div id="customerDetailModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; z-index:9999; backdrop-filter: blur(10px);">
    <div class="glass-panel" style="width:95%; max-width:500px; padding:0; overflow:hidden; border-radius:20px; border:1px solid rgba(255,255,255,0.1);">
        <div style="padding:20px; background:linear-gradient(to right, var(--primary), #1e293b); color:white; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div>
                    <div id="detCustName" style="font-weight:800; font-size:17px;">...</div>
                    <div id="detCustId" style="font-size:11px; opacity:0.8; font-family:monospace;">ID: ...</div>
                </div>
            </div>
            <button onclick="document.getElementById('customerDetailModal').style.display='none'" style="background:none; border:none; color:white; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        
        <div class="scroll-container" style="max-height:65vh; overflow-y:auto; padding:20px;">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:20px;">
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Paket</div>
                    <div id="detCustPkg" style="font-weight:700; font-size:13px;">...</div>
                </div>
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Siklus Tagihan</div>
                    <div id="detCustBilling" style="font-weight:700; font-size:13px;">Tanggal ...</div>
                </div>
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Nomor Telepon / WhatsApp</div>
                    <div id="detCustPhone" style="font-weight:700; font-size:13px;">...</div>
                </div>
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Tanggal Registrasi</div>
                    <div id="detCustRegDate" style="font-weight:700; font-size:13px;">...</div>
                </div>
            </div>
            
            <div style="font-size:11px; font-weight:800; color:var(--primary); margin-bottom:10px; text-transform:uppercase; letter-spacing:1px;">Riwayat Pembayaran</div>
            <div id="detHistoryList"></div>
        </div>
    </div>
</div>

<script>
async function showCustomerDetails(id) {
    document.getElementById('detCustName').textContent = 'Memuat...';
    document.getElementById('detHistoryList').innerHTML = '<div style="text-align:center; padding:30px; opacity:0.5;"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>';
    document.getElementById('customerDetailModal').style.display = 'flex';
    
    try {
        const response = await fetch(`app/customer_history.php?id=${id}`);
        const data = await response.json();
        
        document.getElementById('detCustName').textContent = data.customer.name;
        document.getElementById('detCustId').textContent = 'ID: ' + (data.customer.customer_code || data.customer.id);
        document.getElementById('detCustPkg').textContent = data.customer.package_name;
        document.getElementById('detCustBilling').textContent = 'Tanggal ' + data.customer.billing_date;
        document.getElementById('detCustPhone').textContent = data.customer.contact;
        document.getElementById('detCustRegDate').textContent = data.customer.registration_date;
        
        let historyHtml = '';
        data.history.forEach(item => {
            const isPaid = item.status === 'Lunas';
            const color = isPaid ? 'var(--success)' : 'var(--danger)';
            historyHtml += `
                <div class="glass-panel" style="padding:12px; border-left:4px solid ${color}; background:rgba(255,255,255,0.02); margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:12px; font-weight:800; color:${color};">${item.status}</div>
                            <div style="font-size:10px; color:var(--text-secondary);">${item.due_date}</div>
                        </div>
                        <div style="font-weight:800;">Rp${new Intl.NumberFormat('id-ID').format(item.invoice_amount)}</div>
                    </div>
                </div>
            `;
        });
        document.getElementById('detHistoryList').innerHTML = historyHtml;
    } catch (e) {
        document.getElementById('detHistoryList').innerHTML = 'Error loading history.';
    }
}
</script>
