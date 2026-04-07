<?php
// Penagihan Lapangan for Partner
$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$near_due_threshold = date('Y-m-d', strtotime('+3 days'));

// === FILTERS ===
$filter_billing_date = $_GET['filter_billing_date'] ?? '';
$search_query = $_GET['search'] ?? '';

// Base where for my customers
$base_where = " WHERE c.created_by = $user_id";

// Search filter
if ($search_query) {
    $s = $db->quote("%$search_query%");
    $base_where .= " AND (c.name LIKE $s OR c.customer_code LIKE $s OR c.address LIKE $s OR c.contact LIKE $s)";
}

// Billing Date filter
if ($filter_billing_date !== "") {
    $base_where .= " AND c.billing_date = " . intval($filter_billing_date);
}

// 1. Fetch "Tugas Penagihan" (Arrears - Belum Lunas)
$unpaid_tasks = $db->query("
    SELECT 
        c.id as cust_id, c.name, c.address, c.contact, c.customer_code, c.package_name, c.billing_date,
        COUNT(i.id) as num_arrears,
        SUM(i.amount - i.discount) as total_unpaid,
        MIN(i.due_date) as oldest_due_date
    FROM customers c
    JOIN invoices i ON c.id = i.customer_id
    $base_where AND i.status = 'Belum Lunas'
    GROUP BY c.id
    ORDER BY oldest_due_date ASC
")->fetchAll();

// 2. Fetch "Mendekati Jatuh Tempo" (Due in next 3 days, but NOT arrears yet)
// We look for customers who have an unpaid invoice due BETWEEN today and threshold
$near_due_tasks = $db->query("
    SELECT 
        c.id as cust_id, c.name, c.address, c.contact, c.customer_code, c.package_name, c.billing_date,
        i.id as inv_id, i.amount as inv_amount, i.due_date
    FROM customers c
    JOIN invoices i ON c.id = i.customer_id
    $base_where AND i.status = 'Belum Lunas' 
    AND i.due_date >= '$today' AND i.due_date <= '$near_due_threshold'
    ORDER BY i.due_date ASC
")->fetchAll();

// 3. Stats for Summary
$stats = [
    'total_tasks' => count($unpaid_tasks),
    'near_due' => count($near_due_tasks),
    'total_nominal' => array_sum(array_column($unpaid_tasks, 'total_unpaid'))
];

// Global Settings
$settings = $db->query("SELECT * FROM settings WHERE id = 1")->fetch();
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();
$base_url = "http://" . preg_replace("~^https?://~i", "", $base_url);

// Partner Branding & Custom Templates
$u_id = $_SESSION['user_id'];
$p_stg = $db->query("SELECT wa_template, wa_template_paid, brand_bank, brand_rekening FROM users WHERE id = $u_id")->fetch();

$wa_tpl = (!empty($p_stg['wa_template'])) ? $p_stg['wa_template'] : ($settings['wa_template'] ?: "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}");
$wa_tpl_paid = (!empty($p_stg['wa_template_paid'])) ? $p_stg['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sebesar {nominal} sudah LUNAS. Terima kasih telah berlangganan.");
$rekening_receipt = (!empty($p_stg['brand_bank'])) ? $p_stg['brand_bank'] . " " . $p_stg['brand_rekening'] : $settings['bank_account'];

// Success Modal Data
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    // Fetch richer data for WA Receipt
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact']));
        
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        
        // Calculate remaining arrears (Tunggakan)
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas'")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $status_wa = ($tunggakan_val > 0) ? "LUNAS SEBAGIAN (Masih ada sisa tunggakan)" : "LUNAS SEPENUHNYA";
        
        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_display;
        // Replace Tags
        $receipt_msg = str_replace(
            [
                '{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', 
                '{tunggakan}', '{waktu_bayar}', '{admin}', '{link_tagihan}', '{rekening}', '{nominal}', '{status_pembayaran}', '{sisa_tunggakan}'
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
                $tunggakan_display
            ], 
            $wa_tpl_paid
        );
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'unpay_success'): ?>
<div style="padding:12px 20px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:10px; margin-bottom:15px; color:var(--danger);">
    <i class="fas fa-undo"></i> Pembayaran dibatalkan! Tagihan pelanggan kembali masuk ke daftar <strong>Tunggakan</strong>.
</div>
<?php endif; ?>

<div class="glass-panel" style="padding:24px; margin-bottom:20px; border-left:4px solid var(--primary);">
    <div class="grid-header" style="margin-bottom:20px;">
        <div>
            <h3 style="font-size:20px; margin:0;"><i class="fas fa-motorcycle text-primary"></i> Penagihan Lapangan</h3>
            <p style="font-size:12px; color:var(--text-secondary); margin:5px 0 0;">Kelola penagihan & kirim nota dengan mudah di HP</p>
        </div>
        <div class="hide-mobile">
            <span class="badge badge-primary"><?= date('d F Y') ?></span>
        </div>
    </div>

    <!-- Mobile-First Filter Bar -->
    <div style="padding:15px; margin-bottom:20px; background:rgba(var(--primary-rgb), 0.05); border-radius:12px; border:1px solid rgba(var(--primary-rgb), 0.1);">
    <form method="GET" class="grid-filters">
        <input type="hidden" name="page" value="partner_collection">
        
        <div class="filter-group">
            <label><i class="fas fa-search"></i> Cari Pelanggan</label>
            <div style="position:relative;">
                <input type="text" name="search" class="form-control" placeholder="Nama/Alamat/ID..." value="<?= htmlspecialchars($search_query) ?>" style="padding-left:35px; font-size:13px; height:40px;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-secondary); font-size:12px; opacity:0.5;"></i>
            </div>
        </div>

        <div class="filter-group">
            <label><i class="fas fa-calendar-day"></i> Siklus Tagih (Tgl)</label>
            <select name="filter_billing_date" class="form-control" style="font-size:13px; height:40px;">
                <option value="">Semua Tanggal</option>
                <?php for($d=1; $d<=28; $d++): ?>
                    <option value="<?= $d ?>" <?= $filter_billing_date == $d ? 'selected' : '' ?>>Tanggal <?= $d ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="grid-actions" style="margin-top:auto;">
            <div class="btn-group" style="width:100%;">
                <button type="submit" class="btn btn-primary btn-sm" style="flex:1; height:40px; font-weight:700;"><i class="fas fa-filter"></i> Terapkan</button>
                <?php if($search_query || $filter_billing_date): ?>
                    <a href="index.php?page=partner_collection" class="btn btn-ghost btn-sm" style="flex:0; width:45px; height:40px; padding:0; display:flex; align-items:center; justify-content:center;"><i class="fas fa-sync"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    </div>
</div>

<!-- Collection Stats Grid -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:15px; margin-bottom:20px;">
    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--danger);">
        <div style="font-size:22px; font-weight:900; color:var(--danger);"><?= $stats['total_tasks'] ?></div>
        <div style="font-size:11px; color:var(--text-secondary); font-weight:700; text-transform:uppercase;">Menunggak</div>
    </div>
    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--warning);">
        <div style="font-size:22px; font-weight:900; color:var(--warning);"><?= $stats['near_due'] ?></div>
        <div style="font-size:11px; color:var(--text-secondary); font-weight:700; text-transform:uppercase;">Due Soon</div>
    </div>
    <div class="glass-panel hide-mobile" style="padding:15px; text-align:center; border-top:4px solid var(--success);">
        <div style="font-size:18px; font-weight:900; color:var(--text-primary);">Rp<?= number_format($stats['total_nominal'], 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); font-weight:700; text-transform:uppercase;">Potensi Piutang</div>
    </div>
</div>

<!-- Tab Navigation - Segmented Control Style -->
<div style="display:flex; background:rgba(255,255,255,0.03); padding:5px; border-radius:14px; margin-bottom:20px; gap:5px;">
    <button onclick="switchColTab('tunggakan')" id="tab-btn-tunggakan" style="flex:1; text-align:center; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; border:none; cursor:pointer; background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);" class="btn-tab active">
        <i class="fas fa-history"></i> <span class="hide-mobile">Tunggakan</span><span class="show-mobile">Tunggak</span> (<?= count($unpaid_tasks) ?>)
    </button>
    <button onclick="switchColTab('near_due')" id="tab-btn-near_due" style="flex:1; text-align:center; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; border:none; cursor:pointer; background:transparent; color:var(--text-secondary);" class="btn-tab">
        <i class="fas fa-calendar-alt"></i> <span class="hide-mobile">Akan Datang</span><span class="show-mobile">Nanti</span> (<?= count($near_due_tasks) ?>)
    </button>
</div>

<!-- TAB CONTENT: TUNGGAKAN -->
<div id="tab-content-tunggakan" class="collection-tab-content">
    <div class="grid-items" style="gap:15px;">
        <?php if(empty($unpaid_tasks)): ?>
            <div class="glass-panel" style="padding:40px; text-align:center; color:var(--text-secondary);">
                <i class="fas fa-check-circle" style="font-size:40px; color:var(--success); opacity:0.5; margin-bottom:15px; display:block;"></i>
                <p style="font-weight:700; margin:0;">Tidak ada tunggakan!</p>
                <p style="font-size:12px;">Semua pelanggan Anda sudah lunas atau sesuai jadwal.</p>
            </div>
        <?php else: ?>
            <?php foreach($unpaid_tasks as $task): 
                $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $task['contact']));
                $nominal_display = 'Rp ' . number_format($task['total_unpaid'], 0, ',', '.');
                $is_overdue = strtotime($task['oldest_due_date']) < time();
                
                // WA Message Logic
                $cust_id_display = $task['customer_code'] ?: '-';
                $oldest_due = date('d/m/Y', strtotime($task['oldest_due_date']));
                $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                $inv_month = $mon_id[intval(date('m', strtotime($task['oldest_due_date']))) - 1] . ' ' . date('Y', strtotime($task['oldest_due_date']));
                
                $portal_link = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . $cust_id_display;
                $msg = str_replace(
                    ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}', '{link_tagihan}'], 
                    [$task['name'], '*' . $cust_id_display . '*', $task['package_name'] ?: '-', $inv_month, '*' . $nominal_display . '*', '*' . $oldest_due . '*', '*' . trim($rekening_receipt) . '*', '*' . $task['num_arrears'] . ' Bulan*', '*' . $nominal_display . '*', $portal_link], 
                    $wa_tpl
                );
                $wa_text = urlencode($msg);
            ?>
            <div class="glass-panel" style="padding:16px; border-left:5px solid <?= $is_overdue ? 'var(--danger)' : 'var(--warning)' ?>;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div>
                        <div style="font-weight:800; font-size:16px; color:var(--text-primary);"><?= htmlspecialchars($task['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">
                            ID: <?= $cust_id_display ?> • Siklus: Tgl <?= $task['billing_date'] ?>
                        </div>
                    </div>
                    <span class="badge <?= $is_overdue ? 'badge-danger' : 'badge-warning' ?>" style="font-size:10px; font-weight:800;"><?= $task['num_arrears'] ?> BULAN</span>
                </div>
                
                <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:10px; margin-bottom:15px; border:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:11px; font-weight:700; color:var(--text-secondary);">TOTAL TAGIHAN</span>
                    <span style="font-size:18px; font-weight:900; color:var(--text-primary);"><?= $nominal_display ?></span>
                </div>

                <!-- Actions Grid -->
                <div style="margin-top:12px; display:grid; grid-template-columns: 1fr 44px; gap:8px;">
                    <button onclick="quickPay(<?= $task['cust_id'] ?>, '<?= addslashes($task['name']) ?>', <?= $task['num_arrears'] ?>, <?= $task['total_unpaid'] ?>)" class="btn btn-primary" style="padding:12px; font-weight:800; border-radius:12px; display:flex; align-items:center; justify-content:center; gap:8px; font-size:13px; box-shadow: 0 4px 15px rgba(var(--primary-rgb), 0.3);">
                        <i class="fas fa-hand-holding-dollar"></i> BAYAR
                    </button>
                    <a href="https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-ghost" style="color:#20c997; border-color:rgba(32, 201, 151, 0.2); width:44px; height:44px; display:flex; align-items:center; justify-content:center; border-radius:12px; padding:0;" title="Kirim WA Tagihan">
                        <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- TAB CONTENT: NEAR DUE -->
<div id="tab-content-near_due" class="collection-tab-content" style="display:none;">
    <div class="grid-items" style="gap:15px;">
        <?php if(empty($near_due_tasks)): ?>
            <div class="glass-panel" style="padding:40px; text-align:center; color:var(--text-secondary);">
                <i class="fas fa-calendar-check" style="font-size:40px; color:var(--primary); opacity:0.5; margin-bottom:15px; display:block;"></i>
                <p style="font-weight:700; margin:0;">Tidak ada tagihan segera!</p>
                <p style="font-size:12px;">Tagihan berikutnya masih jauh atau sudah terlambat masuk ke tab Tunggakan.</p>
            </div>
        <?php else: ?>
            <?php foreach($near_due_tasks as $task): 
                $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $task['contact']));
                $nominal_display = 'Rp ' . number_format($task['inv_amount'], 0, ',', '.');
                $due_text = date('d/m/Y', strtotime($task['due_date']));
                $days_left = ceil((strtotime($task['due_date']) - time()) / 86400);
            ?>
            <div class="glass-panel" style="padding:16px; border-left:5px solid var(--warning); background:linear-gradient(to right, rgba(245, 158, 11, 0.05), transparent);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div>
                        <div style="font-weight:800; font-size:16px; color:var(--text-primary);"><?= htmlspecialchars($task['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">
                            ID: <?= htmlspecialchars($task['customer_code'] ?: '-') ?> • Siklus: Tgl <?= $task['billing_date'] ?>
                        </div>
                    </div>
                    <span class="badge badge-warning" style="font-size:10px; font-weight:800;"><?= $days_left ?> HARI LAGI</span>
                </div>
                
                <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:10px; margin-bottom:15px; border:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-size:10px; color:var(--text-secondary); font-weight:700; text-transform:uppercase;">NOMINAL</div>
                        <div style="font-size:16px; font-weight:800; color:var(--text-primary);"><?= $nominal_display ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:10px; color:var(--text-secondary); font-weight:700; text-transform:uppercase;">JATUH TEMPO</div>
                        <div style="font-size:14px; font-weight:800; color:var(--warning);"><?= $due_text ?></div>
                    </div>
                </div>

                <div class="grid-actions" style="margin-top:10px;">
                    <button onclick="quickPay(<?= $task['cust_id'] ?>, '<?= addslashes($task['name']) ?>', 1, <?= $task['inv_amount'] ?>)" class="btn btn-primary" style="flex:2; padding:15px; font-weight:800; border-radius:12px; font-size:14px;">
                        <i class="fas fa-hand-holding-dollar"></i> BAYAR
                    </button>
                    <a href="https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= urlencode("Halo {$task['name']}, sekedar mengingatkan tagihan internet Anda {$nominal_display} akan jatuh tempo pada {$due_text}.") ?>" target="_blank" class="btn" style="background:#25D366; color:white; flex:1; padding:15px; border-radius:12px; display:flex; align-items:center; justify-content:center;">
                        <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden Form for Quick Pay -->
<form id="quickPayForm" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
    <input type="hidden" name="customer_id" id="qp_cust_id">
    <input type="hidden" name="num_months" id="qp_num_months">
</form>

<style>
.btn-tab {
    background: var(--btn-ghost-bg);
    color: var(--text-secondary);
    border: 1px solid var(--glass-border);
    font-weight: 700;
    transition: all 0.3s ease;
}
.btn-tab.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.3);
}
.collection-tab-content {
    animation: fadeIn 0.3s ease-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<!-- Success Modal -->
<?php if ($success_data): ?>
<div id="paymentSuccessModal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); z-index:9999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(8px); animation: fadeIn 0.3s ease;">
    <div class="glass-panel" style="width:90%; max-width:400px; padding:30px; text-align:center; position:relative; border-top:5px solid var(--success);">
        <div style="width:80px; height:80px; background:rgba(16, 185, 129, 0.1); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">
            <i class="fas fa-check-circle" style="font-size:50px; color:var(--success);"></i>
        </div>
        <h2 style="font-size:24px; font-weight:900; margin-bottom:10px; color:var(--text-primary);">Pembayaran Berhasil!</h2>
        <p style="color:var(--text-secondary); margin-bottom:25px; font-size:14px;">Tagihan pelanggan <strong><?= htmlspecialchars($success_data['name']) ?></strong> telah ditandai sebagai LUNAS.</p>
        
        <div style="display:flex; flex-direction:column; gap:12px;">
            <a href="<?= $success_data['wa_link'] ?>" target="_blank" class="btn" style="background:#25D366; color:white; padding:16px; border-radius:12px; font-weight:800; font-size:15px; display:flex; align-items:center; justify-content:center; gap:10px;">
                <i class="fab fa-whatsapp" style="font-size:20px;"></i> KIRIM BUKTI PEMBAYARAN
            </a>
            <button onclick="document.getElementById('paymentSuccessModal').style.display='none'" class="btn btn-ghost" style="padding:14px; font-weight:600; font-size:14px; opacity:0.7;">Tutup</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Payment Selection Modal -->
<div id="paymentSelectionModal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.8); z-index:9998; display:none; align-items:center; justify-content:center; backdrop-filter:blur(8px); animation: fadeIn 0.3s ease;">
    <div class="glass-panel" style="width:90%; max-width:400px; padding:25px; border-top:5px solid var(--primary);">
        <h3 style="font-size:18px; font-weight:800; margin-bottom:15px; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-hand-holding-dollar text-primary"></i> Pembayaran Tagihan
        </h3>
        <p id="ps_cust_name" style="font-weight:700; color:var(--text-primary); margin-bottom:5px;"></p>
        <p id="ps_cust_info" style="font-size:12px; color:var(--text-secondary); margin-bottom:20px;"></p>
        
        <div style="margin-bottom:20px;">
            <label style="display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:8px;">JUMLAH BULAN DIBAYAR</label>
            <select id="ps_months_select" class="form-control" onchange="updatePSModalPrice()" style="height:48px; font-weight:700; border-radius:12px; background:var(--input-bg);">
                <!-- Generated by JS -->
            </select>
        </div>

        <div style="background:rgba(var(--primary-rgb), 0.05); padding:15px; border-radius:12px; border:1px solid var(--glass-border); margin-bottom:25px; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:11px; font-weight:700; color:var(--text-secondary);">TOTAL HARUS DIBAYAR</span>
            <span id="ps_total_price" style="font-size:20px; font-weight:900; color:var(--text-primary);">Rp 0</span>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
            <button onclick="document.getElementById('paymentSelectionModal').style.display='none'" class="btn btn-ghost" style="padding:12px; border-radius:10px; font-weight:600;">Batal</button>
            <button onclick="submitQuickPay()" class="btn btn-primary" style="padding:12px; border-radius:10px; font-weight:800;">KONFIRMASI</button>
        </div>
    </div>
</div>

<script>
let currentPsData = { id: 0, months: 1, perMonth: 0 };

function switchColTab(tab) {
    // Hide all
    document.querySelectorAll('.collection-tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.btn-tab').forEach(el => el.classList.remove('active'));
    
    // Show selected
    document.getElementById('tab-content-' + tab).style.display = 'block';
    document.getElementById('tab-btn-' + tab).classList.add('active');
}

function quickPay(custId, name, months, total) {
    currentPsData = { 
        id: custId, 
        months: months, 
        perMonth: total / months 
    };
    
    document.getElementById('ps_cust_name').innerText = name;
    document.getElementById('ps_cust_info').innerText = `Pelanggan memiliki tunggakan ${months} bulan.`;
    
    const select = document.getElementById('ps_months_select');
    select.innerHTML = '';
    for(let i=1; i<=months; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.innerText = `${i} Bulan`;
        if (i === 1) opt.selected = true; // Default pay 1 month as requested
        select.appendChild(opt);
    }
    
    updatePSModalPrice();
    document.getElementById('paymentSelectionModal').style.display = 'flex';
}

function updatePSModalPrice() {
    const months = parseInt(document.getElementById('ps_months_select').value);
    const total = months * currentPsData.perMonth;
    const formatted = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
    document.getElementById('ps_total_price').innerText = formatted;
}

function submitQuickPay() {
    const months = document.getElementById('ps_months_select').value;
    document.getElementById('qp_cust_id').value = currentPsData.id;
    document.getElementById('qp_num_months').value = months;
    document.getElementById('quickPayForm').submit();
}
</script>
