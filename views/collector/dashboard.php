<?php
// Data collector
$user_id = $_SESSION['user_id'];
// Base filter: assigned collector id
$collector_id = $_SESSION['user_id'];

// Get collector area assignment
$collector_area = $db->query("SELECT area FROM users WHERE id = " . intval($collector_id))->fetchColumn() ?: '';

$area_filter = " AND c.collector_id = " . intval($collector_id);

// Billing date filter
$filter_billing_date = $_GET['filter_billing_date'] ?? '';
$billing_where = "";
if ($filter_billing_date !== "") {
    $billing_where = " AND c.billing_date = " . intval($filter_billing_date);
}

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
// Total pelanggan yang menjadi tanggung jawab
$total_customers = $db->query("
    SELECT COUNT(*) FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust
")->fetchColumn();

// Total tagihan belum lunas (JUMLAH PELANGGAN & NOMINAL)
$unpaid_customers_count = $db->query("
    SELECT COUNT(DISTINCT customer_id) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $area_filter $billing_where $where_search_tugas
")->fetchColumn();

// Keep original unpaid_count (total invoices) for the badge
$unpaid_count = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $area_filter $billing_where $where_search_tugas
")->fetchColumn();

$unpaid_total = $db->query("
    SELECT COALESCE(SUM(i.amount - i.discount), 0) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $area_filter $billing_where $where_search_tugas
")->fetchColumn();

// Total tagihan lunas bulan ini
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

$paid_count_month = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN payments p ON p.invoice_id = i.id
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$month_start' AND '$month_end 23:59:59'
    $area_filter $billing_where
")->fetchColumn();

$paid_total_month = $db->query("
    SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE p.payment_date BETWEEN '$month_start' AND '$month_end 23:59:59'
    $area_filter $billing_where
")->fetchColumn();

// Total seluruh pendapatan (sepanjang masa)
$paid_total_all = $db->query("
    SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Lunas' $area_filter $billing_where
")->fetchColumn();

// Persentase progres penagihan (Monetary Based)
$total_potential = $unpaid_total + $paid_total_month;
$percent_paid = $total_potential > 0 ? round(($paid_total_month / $total_potential) * 100) : 0;
$percent_paid = min(100, $percent_paid); // Cap at 100%

// Pagination Logic for Tasks
$items_per_page = 50;
$p_tugas = isset($_GET['p_tugas']) ? max(1, intval($_GET['p_tugas'])) : 1;
$off_tugas = ($p_tugas - 1) * $items_per_page;
$total_tugas_pages = ceil($unpaid_customers_count / $items_per_page);

// Fetch unique customers with aggregated arrears
$query = "
    SELECT 
        c.id as cust_id, c.name, c.address, c.contact, c.area as cust_area, c.customer_code, c.package_name, c.type as customer_type,
        COUNT(i.id) as num_arrears,
        SUM(i.amount - i.discount) as total_unpaid,
        MIN(i.due_date) as oldest_due_date,
        MIN(i.id) as oldest_invoice_id
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $area_filter $billing_where $where_search_tugas
    GROUP BY c.id
    ORDER BY oldest_due_date ASC
    LIMIT $items_per_page OFFSET $off_tugas
";
$unpaid_invoices = $db->query($query)->fetchAll();

$recent_paid = $db->query("
    SELECT i.*, c.name, c.contact, c.customer_code, c.package_name, p.payment_date, p.amount as paid_amount,
    (SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = i.customer_id AND status = 'Belum Lunas') as total_tunggakan
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN payments p ON p.invoice_id = i.id
    WHERE i.status = 'Lunas' $area_filter $billing_where
    ORDER BY p.id DESC
    LIMIT 50
")->fetchAll();

$coll_tab = $_GET['tab'] ?? 'tugas';
$settings = $db->query("SELECT company_name, wa_template, wa_template_paid, bank_account FROM settings WHERE id=1")->fetch();
$wa_tpl = $settings['wa_template'] ?: "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}";
$wa_tpl_paid = $settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah lunas.";

// Fetch Banners for Collector
$banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'collector') ORDER BY created_at DESC")->fetchAll();

// Handle manual invoice creation by collector
if (isset($_GET['action']) && $_GET['action'] === 'create_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = intval($_POST['customer_id']);
    $amt = floatval($_POST['amount']);
    $due = $_POST['due_date'];
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Lunas', ?)")
       ->execute([$cid, $amt, $due, $now]);
    header("Location: index.php?page=collector&msg=invoice_created");
    exit;
}

// Fetch all customers with pagination
$p_cust = isset($_GET['p_cust']) ? max(1, intval($_GET['p_cust'])) : 1;
$off_cust = ($p_cust - 1) * $items_per_page;
$total_cust_pages = ceil($total_customers / $items_per_page);

$cust_query = "SELECT * FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust ORDER BY c.name ASC LIMIT $items_per_page OFFSET $off_cust";
$area_customers = $db->query($cust_query)->fetchAll();

$coll_tab = $_GET['tab'] ?? 'tugas';
?>

<style>
/* Custom Scrollbar for better UI */
.scroll-container::-webkit-scrollbar { width: 5px; }
.scroll-container::-webkit-scrollbar-track { background: transparent; }
.scroll-container::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb), 0.2); border-radius: 10px; }
.scroll-container { scrollbar-width: thin; scrollbar-color: rgba(var(--primary-rgb), 0.2) transparent; }
</style>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'invoice_created'): ?>
<div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:15px; color:var(--success);">
    <i class="fas fa-check-circle"></i> Tagihan berhasil dibuat!
</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'paid' && isset($_GET['last_id'])): 
    $last_id = intval($_GET['last_id']);
    $inv_data = $db->query("
        SELECT i.*, c.name, c.contact, c.customer_code, c.package_name,
        (SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = i.customer_id AND status = 'Belum Lunas') as total_tunggakan
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.id = $last_id
    ")->fetch();
    $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv_data['contact'] ?? ''));
    
    // Parse Full Tagihan Lunas Template
    $mon_name = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $bulan_inv = $mon_name[intval(date('m', strtotime($inv_data['due_date']))) - 1] . ' ' . date('Y', strtotime($inv_data['due_date']));
    
    $parsed_msg = str_replace(
        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{perusahaan}', '{tunggakan}'],
        [
            $inv_data['name'], 
            $inv_data['customer_code'] ?: $inv_data['customer_id'], 
            $inv_data['package_name'], 
            $bulan_inv,
            'Rp ' . number_format($inv_data['amount'], 0, ',', '.'), 
            $settings['company_name'],
            'Rp ' . number_format($inv_data['total_tunggakan'], 0, ',', '.')
        ],
        $wa_tpl_paid
    );
    $wa_msg = urlencode($parsed_msg);
?>
<div class="glass-panel success-receipt-modal" style="margin-bottom:20px; border-left:4px solid var(--success); padding:20px; animation: slideDown 0.4s ease-out; background:rgba(16,185,129,0.08);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
        <div>
            <h3 style="margin:0; color:var(--success); font-size:18px;"><i class="fas fa-check-circle"></i> Pembayaran Berhasil!</h3>
            <p style="margin:5px 0 0; font-size:13px; color:var(--text-secondary);">Kwitansi siap untuk dikirim/cetak untuk <strong><?= htmlspecialchars($inv_data['name']) ?></strong></p>
        </div>
        <button onclick="this.parentElement.parentElement.style.display='none'" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if($wa_num): ?>
            <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= $wa_msg ?>" target="_blank" class="btn" style="background:#25D366; color:white; flex:1; min-width:140px; padding:12px;">
                <i class="fab fa-whatsapp"></i> Kirim Kwitansi
            </a>
        <?php endif; ?>
        <a href="index.php?page=admin_invoices&action=print&id=<?= $last_id ?>&format=thermal" target="_blank" class="btn btn-primary" style="flex:1; min-width:140px; padding:12px;">
            <i class="fas fa-print"></i> Cetak Struk
        </a>
    </div>
</div>

<style>
@keyframes slideDown { from { transform: translateY(-20px); opacity:0; } to { transform: translateY(0); opacity:1; } }
</style>
<?php endif; ?>

<!-- Header -->
<div class="glass-panel" style="padding:20px 24px; margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <h3 style="font-size:18px; margin:0;">
                <i class="fas fa-motorcycle" style="color:var(--warning);"></i> 
                Halo, <?= htmlspecialchars($_SESSION['user_name']) ?>!
            </h3>
            <div style="color:var(--text-secondary); font-size:13px; margin-top:4px;">
                <?= strftime('%A') ?>, <?= date('d F Y') ?> — 
                <?php if(!empty(trim($collector_area))): ?>
                    <span class="badge badge-warning" style="font-size:11px;">Area: <?= htmlspecialchars($collector_area) ?></span>
                <?php else: ?>
                    <span class="badge badge-success" style="font-size:11px;">Semua Area</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="font-size:13px; color:var(--text-secondary);">
            <i class="fas fa-clock"></i> <?= date('H:i') ?> WIB
        </div>
    </div>
</div>

<!-- Banners / PENGUMUMAN -->
<?php if(!empty($banners)): ?>
<div style="margin-bottom:16px;">
    <?php foreach($banners as $b): ?>
        <div class="glass-panel" style="padding:15px; margin-bottom:10px; border-left:4px solid var(--primary); position:relative; overflow:hidden;">
            <?php if($b['image_path']): ?>
                <div style="margin-bottom:10px;">
                    <img src="<?= $b['image_path'] ?>" style="width:100%; height:120px; object-fit:cover; border-radius:8px; cursor:pointer;" onclick="openImagePreview(this.src)" title="Perbesar">
                </div>
            <?php endif; ?>
            <div style="display:flex; align-items:flex-start; gap:12px;">
                <div style="width:36px; height:36px; background:rgba(var(--primary-rgb), 0.1); color:var(--primary); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div>
                    <div style="font-weight:700; font-size:14px; color:var(--text-primary);"><?= htmlspecialchars($b['title']) ?></div>
                    <div style="font-size:12px; color:var(--text-secondary); line-height:1.5; margin-top:4px;">
                        <?= nl2br(htmlspecialchars($b['content'])) ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Statistik Cards - Mobile optimized adaptive grid -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:16px;">
    <!-- Total Pelanggan -->
    <div class="glass-panel" style="padding:10px 12px; text-align:center; border-top:3px solid var(--primary); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=pelanggan'">
        <i class="fas fa-users" style="font-size:18px; color:var(--primary); margin-bottom:4px;"></i>
        <div style="font-size:20px; font-weight:800; color:var(--stat-value-color);"><?= $total_customers ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Pelanggan</div>
    </div>

    <!-- Belum Bayar -->
    <div class="glass-panel" style="padding:10px 12px; text-align:center; border-top:3px solid var(--danger); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=tugas'">
        <i class="fas fa-exclamation-circle" style="font-size:18px; color:var(--danger); margin-bottom:4px;"></i>
        <div style="font-size:20px; font-weight:800; color:var(--stat-value-color);"><?= $unpaid_count ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Belum Lunas</div>
        <div style="font-size:11px; font-weight:700; color:var(--danger); margin-top:2px;">Rp<?= number_format($unpaid_total, 0, ',', '.') ?></div>
    </div>

    <!-- Lunas Bulan Ini -->
    <div class="glass-panel" style="padding:10px 12px; text-align:center; border-top:3px solid var(--success); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=lunas'">
        <i class="fas fa-check-circle" style="font-size:18px; color:var(--success); margin-bottom:4px;"></i>
        <div style="font-size:20px; font-weight:800; color:var(--stat-value-color);"><?= $paid_count_month ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Sudah Lunas</div>
        <div style="font-size:11px; font-weight:700; color:var(--success); margin-top:2px;">Rp<?= number_format($paid_total_month, 0, ',', '.') ?></div>
    </div>

    <!-- Total Pendapatan -->
    <div class="glass-panel" style="padding:10px 12px; text-align:center; border-top:3px solid var(--warning);">
        <i class="fas fa-coins" style="font-size:18px; color:var(--warning); margin-bottom:4px;"></i>
        <div style="font-size:16px; font-weight:800; color:var(--stat-value-color);">Rp<?= number_format($paid_total_all, 0, ',', '.') ?></div>
        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Total Revenue</div>
    </div>
</div>

<!-- Progress Bar -->
<div class="glass-panel" style="padding:14px 20px; margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <span style="font-size:12px; color:var(--text-secondary);"><i class="fas fa-chart-pie"></i> Progres <?= date('M Y') ?></span>
        <span style="font-size:14px; font-weight:700; color:<?= $percent_paid >= 80 ? 'var(--success)' : ($percent_paid >= 50 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $percent_paid ?>%</span>
    </div>
    <div style="background:var(--progress-bg); border-radius:10px; height:8px; overflow:hidden;">
        <div style="width:<?= $percent_paid ?>%; height:100%; background:linear-gradient(to right, <?= $percent_paid >= 80 ? 'var(--success), #34d399' : ($percent_paid >= 50 ? 'var(--warning), #fbbf24' : 'var(--danger), #f87171') ?>); border-radius:10px; transition:width 1s ease;"></div>
    </div>
</div>

<!-- Tab Navigation - Hidden on mobile (using bottom nav instead) -->
<div style="display:flex; gap:10px; margin-bottom:16px;" class="desktop-tabs">
    <a href="index.php?page=collector&tab=tugas" class="btn btn-sm" style="<?= $coll_tab === 'tugas' ? 'background:var(--primary); color:white;' : 'background:var(--btn-ghost-bg); color:var(--text-secondary); border:1px solid var(--btn-ghost-border);' ?> padding:10px 20px; border-radius:10px;">
        <i class="fas fa-tasks"></i> Belum Lunas
    </a>
    <a href="index.php?page=collector&tab=lunas" class="btn btn-sm" style="<?= $coll_tab === 'lunas' ? 'background:var(--primary); color:white;' : 'background:var(--btn-ghost-bg); color:var(--text-secondary); border:1px solid var(--btn-ghost-border);' ?> padding:10px 20px; border-radius:10px;">
        <i class="fas fa-check-double"></i> Sudah Lunas
    </a>
    <a href="index.php?page=collector&tab=pelanggan" class="btn btn-sm" style="<?= $coll_tab === 'pelanggan' ? 'background:var(--primary); color:white;' : 'background:var(--btn-ghost-bg); color:var(--text-secondary); border:1px solid var(--btn-ghost-border);' ?> padding:10px 20px; border-radius:10px;">
        <i class="fas fa-users"></i> Semua Pelanggan
    </a>
</div>

<?php if($coll_tab === 'pelanggan'): ?>
<!-- TAB: Data Pelanggan -->
<div class="glass-panel" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
        <h3 style="font-size:18px; margin:0;"><i class="fas fa-users"></i> Daftar Pelanggan</h3>
        <form method="GET" style="display:flex; gap:5px; flex:1; max-width:300px;">
            <input type="hidden" name="page" value="collector">
            <input type="hidden" name="tab" value="pelanggan">
            <input type="text" name="search_cust" class="form-control" placeholder="Cari pelanggan..." value="<?= htmlspecialchars($search_cust) ?>" style="padding:8px 12px; font-size:13px;">
            <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 12px;"><i class="fas fa-search"></i></button>
        </form>
    </div>
    
    <!-- Mobile-friendly card list -->
    <div class="customer-list-mobile scroll-container" style="display:none; max-height:600px; overflow-y:auto; padding-right:5px; margin-top:10px;">
        <?php foreach($area_customers as $ac): 
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
        ?>
        <div class="glass-panel" style="padding:16px; margin-bottom:10px; border-left:3px solid var(--primary);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <div style="font-weight:600; font-size:15px;">
                        <?= htmlspecialchars($ac['name']) ?>
                        <?php if($ac['type'] === 'partner'): ?>
                            <span style="font-size:9px; background:#a855f7; color:white; padding:2px 6px; border-radius:4px; vertical-align:middle; margin-left:4px;">MITRA</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:11px; color:var(--primary); font-family:monospace;"><?= htmlspecialchars($ac['customer_code'] ?? '') ?></div>
                    <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ac['address'] ?: '-') ?></div>
                </div>
                <div style="font-weight:700; color:var(--stat-value-color); font-size:14px; white-space:nowrap;">Rp <?= number_format($ac['monthly_fee'], 0, ',', '.') ?></div>
            </div>
            <div style="font-size:12px; color:var(--text-secondary); margin-bottom:10px;">
                <i class="fas fa-box"></i> <?= htmlspecialchars($ac['package_name']) ?>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn btn-sm" style="background:var(--warning); color:white; flex:1; min-width:120px;" onclick="showCreateInvoice(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                    <i class="fas fa-file-invoice-dollar"></i> Buat Tagihan
                </button>
                <?php if($wa_num):
                    $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $inv_month = $mon_id[intval(date('m')) - 1] . ' ' . date('Y');
                    $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
                    $package_display = $ac['package_name'] ?: '-';
                    $nominal_display = 'Rp ' . number_format($ac['monthly_fee'], 0, ',', '.');
                    
                    $reminder_msg = "Halo *{$ac['name']}*,\n\nBerikut adalah data tagihan internet Anda:\n- ID Cust: {$cust_id_display}\n- Paket: {$package_display}\n- Bulan: {$inv_month}\n- Nominal: {$nominal_display}\n\nMohon segera melakukan pembayaran. Terima kasih.";
                    $wa_text = urlencode($reminder_msg);
                ?>
                    <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white;">
                        <i class="fab fa-whatsapp"></i> WA
                    </a>
                <?php endif; ?>
                <?php if($wa_num): ?>
                    <a href="tel:<?= htmlspecialchars($ac['contact']) ?>" class="btn btn-sm btn-ghost">
                        <i class="fas fa-phone"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Desktop table -->
    <div class="table-container customer-list-desktop">
        <table>
            <thead>
                <tr>
                    <th>ID / Nama</th>
                    <th>Paket</th>
                    <th>Biaya</th>
                    <th>Kontak</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($area_customers as $ac): 
                    $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($ac['name']) ?></div>
                        <div style="font-size:11px; color:var(--primary); font-family:monospace;"><?= htmlspecialchars($ac['customer_code'] ?? '') ?></div>
                        <div style="font-size:11px; color:var(--text-secondary);"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ac['address'] ?: '-') ?></div>
                    </td>
                    <td><?= htmlspecialchars($ac['package_name']) ?></td>
                    <td style="font-weight:bold;">Rp <?= number_format($ac['monthly_fee'], 0, ',', '.') ?></td>
                    <td>
                        <?php if($wa_num): ?>
                            <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>" target="_blank" style="color:#25D366; text-decoration:none;">
                                <i class="fab fa-whatsapp"></i> <?= htmlspecialchars($ac['contact']) ?>
                            </a>
                        <?php else: ?>
                            <?= htmlspecialchars($ac['contact'] ?: '-') ?>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm" style="background:var(--warning); color:white;" onclick="showCreateInvoice(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                            <i class="fas fa-file-invoice-dollar"></i> Buat Tagihan
                        </button>
                        <?php if($wa_num):
                            $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                            $inv_month = $mon_id[intval(date('m')) - 1] . ' ' . date('Y');
                            $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
                            $package_display = $ac['package_name'] ?: '-';
                            $nominal_display = 'Rp ' . number_format($ac['monthly_fee'], 0, ',', '.');
                            
                            $reminder_msg = "Halo *{$ac['name']}*,\n\nBerikut adalah data tagihan internet Anda:\n- ID Cust: {$cust_id_display}\n- Paket: {$package_display}\n- Bulan: {$inv_month}\n- Nominal: {$nominal_display}\n\nMohon segera melakukan pembayaran. Terima kasih.";
                            $wa_text = urlencode($reminder_msg);
                        ?>
                            <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white;">
                                <i class="fab fa-whatsapp"></i> Reminder
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Buat Tagihan Manual -->
<div id="createInvoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;"><i class="fas fa-file-invoice-dollar"></i> Buat Tagihan Manual</h3>
        <div style="font-size:14px; color:var(--text-secondary); margin-bottom:15px;">Pelanggan: <strong id="modalCustName"></strong></div>
        <form action="index.php?page=collector&action=create_invoice" method="POST">
            <input type="hidden" name="customer_id" id="modalCustId">
            <div class="form-group">
                <label>Nominal Tagihan (Rp)</label>
                <input type="number" name="amount" id="modalAmount" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Jatuh Tempo</label>
                <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+5 days')) ?>" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('createInvoiceModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-sm" style="background:var(--primary); color:white;">Buat Tagihan</button>
            </div>
        </form>
    </div>
</div>

<script>
function showCreateInvoice(id, name, fee) {
    document.getElementById('modalCustId').value = id;
    document.getElementById('modalCustName').textContent = name;
    document.getElementById('modalAmount').value = fee;
    document.getElementById('createInvoiceModal').style.display = 'flex';
}
</script>

<style>
/* Show mobile card list, hide desktop table on mobile */
@media (max-width: 768px) {
    .customer-list-mobile { display: block !important; }
    .customer-list-desktop { display: none !important; }
    .desktop-tabs { display: none !important; }
}
</style>

<?php elseif($coll_tab === 'tugas'): ?>
<!-- TAB: Tugas Penagihan (existing content) -->

<!-- Filter Riwayat Pembayaran -->
<div class="glass-panel" style="padding:16px 20px; margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
        <h3 style="font-size:15px; margin:0;"><i class="fas fa-search"></i> Pencarian & Filter</h3>
        <form method="GET" style="display:flex; gap:5px; flex:1; max-width:300px;">
            <input type="hidden" name="page" value="collector">
            <input type="hidden" name="tab" value="tugas">
            <input type="text" name="search_tugas" class="form-control" placeholder="Cari nama/id..." value="<?= htmlspecialchars($search_tugas) ?>" style="padding:8px 12px; font-size:13px;">
            <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 12px;"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <form method="GET" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="page" value="collector">
        <div style="flex:1; min-width:130px;">
            <label style="font-size:11px; color:var(--text-secondary); display:block; margin-bottom:4px;">Dari Tanggal</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>" style="padding:10px 12px; font-size:14px;">
        </div>
        <div style="flex:1; min-width:130px;">
            <label style="font-size:11px; color:var(--text-secondary); display:block; margin-bottom:4px;">Sampai Tanggal</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" style="padding:10px 12px; font-size:14px;">
        </div>
        <div style="flex:1; min-width:130px;">
            <label style="font-size:11px; color:var(--text-secondary); display:block; margin-bottom:4px;">Tgl Tagih</label>
            <select name="filter_billing_date" class="form-control" style="padding:10px 12px; font-size:14px;">
                <option value="">Semua</option>
                <?php for($d=1; $d<=28; $d++): ?>
                    <option value="<?= $d ?>" <?= $filter_billing_date == $d ? 'selected' : '' ?>><?= $d ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-sm" style="background:var(--primary); color:white; padding:10px 16px; height:fit-content;"><i class="fas fa-filter"></i> Filter</button>
        <?php if(!empty($_GET['date_from']) || !empty($_GET['date_to']) || $filter_billing_date !== ''): ?>
            <a href="index.php?page=collector" class="btn btn-sm btn-ghost" style="padding:10px 16px; height:fit-content;"><i class="fas fa-times"></i> Reset</a>
        <?php endif; ?>
    </form>
    
    <?php
    $coll_date_from = $_GET['date_from'] ?? '';
    $coll_date_to = $_GET['date_to'] ?? '';
    
    if ($coll_date_from || $coll_date_to):
        $coll_date_where = '';
        if ($coll_date_from && $coll_date_to) {
            $coll_date_where = " AND p.payment_date BETWEEN " . $db->quote($coll_date_from) . " AND " . $db->quote($coll_date_to . ' 23:59:59');
        } elseif ($coll_date_from) {
            $coll_date_where = " AND p.payment_date >= " . $db->quote($coll_date_from);
        } elseif ($coll_date_to) {
            $coll_date_where = " AND p.payment_date <= " . $db->quote($coll_date_to . ' 23:59:59');
        }
        
        $filtered_payments = $db->query("
            SELECT i.*, c.name, c.contact, p.payment_date, p.amount as paid_amount
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            JOIN customers c ON i.customer_id = c.id
            WHERE 1=1 $area_filter $coll_date_where
            ORDER BY p.payment_date DESC
        ")->fetchAll();
        
        $filtered_total = array_sum(array_column($filtered_payments, 'paid_amount'));
    ?>
    <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--glass-border);">
        <div style="display:flex; gap:16px; margin-bottom:10px; flex-wrap:wrap;">
            <div style="font-size:13px;">📊 Ditemukan: <strong><?= count($filtered_payments) ?></strong> pembayaran</div>
            <div style="font-size:13px; color:var(--success);">💰 Total: <strong>Rp <?= number_format($filtered_total, 0, ',', '.') ?></strong></div>
        </div>
        
        <?php if(count($filtered_payments) > 0): ?>
        <div class="table-container" style="max-height:400px; overflow-y:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Pelanggan</th>
                        <th>Nominal</th>
                        <th>Tanggal Bayar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($filtered_payments as $fp): ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($fp['name']) ?></td>
                        <td style="font-weight:bold; color:var(--success);">Rp <?= number_format($fp['paid_amount'], 0, ',', '.') ?></td>
                        <td style="font-size:13px; color:var(--text-secondary);">
                            <i class="fas fa-check-circle" style="color:var(--success);"></i>
                            <?= date('d M Y H:i', strtotime($fp['payment_date'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="text-align:center; padding:20px; color:var(--text-secondary);">
            <i class="fas fa-inbox" style="font-size:24px; opacity:0.4;"></i>
            <div style="margin-top:5px;">Tidak ada pembayaran dalam rentang tanggal ini.</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Tagihan Belum Lunas - Mobile card-based layout -->
<div class="glass-panel" style="padding:20px; margin-bottom:16px;">
    <h3 style="font-size:16px; margin-bottom:16px; color:var(--danger);">
        <i class="fas fa-list"></i> Tagihan Belum Lunas 
        <span class="badge badge-danger" style="font-size:13px; margin-left:5px;"><?= $unpaid_count ?></span>
    </h3>
    <div class="scroll-container" style="max-height:600px; overflow-y:auto; padding-right:5px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px;">
            <?php 
            foreach($unpaid_invoices as $inv): 
            $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv['contact']));
            $cust_id_display = $inv['customer_code'] ?: str_pad($inv['cust_id'], 5, "0", STR_PAD_LEFT);
            
            // Overdue check based on oldest invoice
            $is_overdue = strtotime($inv['oldest_due_date']) < time();
            $num_arrears = $inv['num_arrears'];
            $grand_total = $inv['total_unpaid'];

            // Prepare WhatsApp message for consolidated arrears
            $msg = "Halo *" . $inv['name'] . "*, kami dari *" . $settings['company_name'] . "* menginfokan bahwa terdapat *" . $num_arrears . " tunggakan* tagihan internet dengan total *Rp " . number_format($grand_total, 0, ',', '.') . "*.\n\nMohon segera melakukan pembayaran melalui kolektor kami atau transfer ke:\n" . $settings['bank_account'] . "\n\nTerima kasih.";
            $wa_text = urlencode($msg);
        ?>
        <div class="glass-panel" style="padding:16px; border-left: 4px solid <?= $is_overdue ? 'var(--danger)' : 'var(--warning)' ?>;">
            <!-- Header: Name + Badge -->
            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:flex-start;">
                <div>
                    <div style="font-weight:bold; font-size:16px; color:var(--text-primary);"><?= htmlspecialchars($inv['name']) ?></div>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:2px;">ID: <?= $cust_id_display ?></div>
                </div>
                <div class="badge <?= $is_overdue ? 'badge-danger' : 'badge-warning' ?>" style="font-size:11px; padding:4px 8px;">
                    <i class="fas fa-history"></i> <?= $num_arrears ?> Tunggakan
                </div>
            </div>
            
            <!-- Info -->
            <div style="font-size:13px; margin: 12px 0; color:var(--text-secondary);">
                <div style="margin-bottom:6px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-map-marker-alt" style="width:14px; color:var(--primary); opacity:0.7;"></i> 
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($inv['address'] ?: '-') ?></span>
                </div>
                <div style="margin-bottom:6px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-calendar-times" style="width:14px; color:<?= $is_overdue ? 'var(--danger)' : 'var(--warning)' ?>;"></i> 
                    <span>Jatuh Tempo: <?= date('d M Y', strtotime($inv['oldest_due_date'])) ?></span>
                </div>
            </div>
            
            <!-- Amount + Actions -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding-top:14px; border-top:1px solid var(--glass-border);">
                <div>
                    <div style="font-size:12px; color:var(--text-secondary);">Total Tagihan:</div>
                    <div style="font-size:18px; font-weight:800; color:var(--primary);">Rp <?= number_format($grand_total, 0, ',', '.') ?></div>
                </div>
                <div style="display:flex; gap:6px;">
                    <?php if($wa_number): ?>
                        <a href="https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white; padding:10px 14px; border-radius:8px;" title="Hubungi Pelanggan">
                            <i class="fab fa-whatsapp" style="font-size:16px;"></i>
                        </a>
                    <?php endif; ?>
                    
                    <div style="display:flex; gap:6px; flex:1;">
                        <form id="payForm_<?= $inv['cust_id'] ?>" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
                            <input type="hidden" name="customer_id" value="<?= $inv['cust_id'] ?>">
                            <input type="hidden" name="num_months" id="numMonths_<?= $inv['cust_id'] ?>" value="1">
                        </form>
                        
                        <button type="button" class="btn btn-success" style="width:100%; padding:12px; font-weight:700; border-radius:10px; display:flex; align-items:center; justify-content:center; gap:8px;" 
                                onclick="handlePay(<?= $inv['cust_id'] ?>, <?= $num_arrears ?>, '<?= addslashes($inv['name']) ?>')">
                            <i class="fas fa-cash-register"></i> Bayar Tagihan
                        </button>
                    </div>
                </div>
            </div>
        </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <?php if(count($unpaid_invoices) == 0): ?>
            <div style="grid-column: 1/-1; text-align:center; padding: 40px;">
                <p style="color:var(--text-secondary);">Semua tugas penagihan sudah selesai.</p>
            </div>
        <?php endif; ?>
    </div>
<?php elseif($coll_tab === 'lunas'): ?>
<!-- TAB: Sudah Lunas -->
<div class="glass-panel" style="padding:20px;">
    <h3 style="font-size:16px; margin-bottom:16px; color:var(--success);">
        <i class="fas fa-check-double"></i> Pelanggan Sudah Lunas (Bulan Ini)
    </h3>
    <div class="scroll-container" style="max-height:600px; overflow-y:auto; padding-right:5px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px;">
            <?php foreach($recent_paid as $rp): ?>
        <div class="glass-panel" style="padding:16px; border-left: 4px solid var(--success);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div>
                    <div style="font-weight:bold; font-size:15px;"><?= htmlspecialchars($rp['name']) ?></div>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:2px;">
                        <i class="fas fa-calendar-check" style="color:var(--success);"></i> Bayar: <?= date('d M Y H:i', strtotime($rp['payment_date'])) ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:800; color:var(--success); font-size:16px;">Rp <?= number_format($rp['paid_amount'], 0, ',', '.') ?></div>
                    <div style="font-size:10px; color:var(--text-secondary);">STATUS LUNAS</div>
                </div>
            </div>
            <div style="margin-top:10px; padding-top:10px; border-top:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:12px; color:var(--text-secondary);"><i class="fas fa-check-circle" style="color:var(--success);"></i> Bayar Lunas</div>
                <div style="display:flex; gap:6px;">
                    <?php 
                        $wa_num_rp = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $rp['contact'] ?? ''));
                        // Fix for redirecting directly to admin_invoices?action=print
                        $receipt_link = "index.php?page=admin_invoices&action=print&id=" . $rp['id'] . "&format=thermal";
                    ?>
                    <?php if($wa_num_rp): 
                        $mon_name = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                        $bulan_rp = $mon_name[intval(date('m', strtotime($rp['due_date']))) - 1] . ' ' . date('Y', strtotime($rp['due_date']));
                        
                        $parsed_msg_rp = str_replace(
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{perusahaan}', '{tunggakan}'],
                            [
                                $rp['name'], 
                                $rp['customer_code'] ?: $rp['customer_id'], 
                                $rp['package_name'], 
                                $bulan_rp,
                                'Rp ' . number_format($rp['paid_amount'], 0, ',', '.'), 
                                $settings['company_name'],
                                'Rp ' . number_format($rp['total_tunggakan'] ?? 0, 0, ',', '.')
                            ],
                            $wa_tpl_paid
                        );
                        $wa_msg_rp = urlencode($parsed_msg_rp);
                    ?>
                        <a href="https://api.whatsapp.com/send?phone=<?= $wa_num_rp ?>&text=<?= $wa_msg_rp ?>" target="_blank" class="btn btn-sm btn-ghost" style="color:#25D366; border:1px solid #25D36633;" title="Kirim WA">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    <?php endif; ?>
                    <a href="<?= $receipt_link ?>" target="_blank" class="btn btn-sm btn-ghost" style="color:var(--primary); border:1px solid var(--glass-border);" title="Cetak Kwitansi">
                        <i class="fas fa-print"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if(empty($recent_paid)): ?>
            <div style="text-align:center; padding:40px; color:var(--text-secondary); grid-column:1/-1;">
                <i class="fas fa-history" style="font-size:40px; opacity:0.1; display:block; margin-bottom:10px;"></i>
                Belum ada pembayaran yang tercatat bulan ini.
            </div>
        <?php endif; ?>
        </div> <!-- end grid -->
    </div> <!-- end scroll-container -->
<?php else: ?>
    <!-- Fallback / Error -->
    <div class="glass-panel" style="padding:40px; text-align:center;">
        <i class="fas fa-exclamation-triangle" style="font-size:40px; color:var(--warning); margin-bottom:10px;"></i>
        <p>Halaman tidak ditemukan.</p>
        <a href="index.php?page=collector" class="btn btn-primary">Kembali ke Dashboard</a>
    </div>
<?php endif; ?>

<script>
function handlePay(custId, maxMonths, custName) {
    let months = maxMonths;
    
    if (maxMonths > 1) {
        let input = prompt("Pelanggan " + custName + " memiliki " + maxMonths + " tunggakan.\nIngin bayar berapa bulan?", maxMonths);
        if (input === null) return;
        months = parseInt(input);
    } else {
        if (!confirm("Konfirmasi terima pembayaran tagihan " + custName + "?")) return;
        months = 1;
    }

    if (isNaN(months) || months < 1 || months > maxMonths) {
        alert("Masukkan jumlah bulan yang valid (1 - " + maxMonths + ")");
        return;
    }

    document.getElementById('numMonths_' + custId).value = months;
    document.getElementById('payForm_' + custId).submit();
}
</script>
