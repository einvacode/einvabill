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

// NEW: Date range filters for Collector
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
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

// Total seluruh pendapatan (RESPECT DATE RANGE)
$paid_total_range = $db->query("
    SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $area_filter $billing_where
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

// Recent Paid List (Respect Date Range now)
$recent_paid = $db->query("
    SELECT i.*, c.name, c.contact, c.customer_code, c.package_name, p.payment_date, p.amount as paid_amount, u.name as admin_name,
    (SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = i.customer_id AND status = 'Belum Lunas') as total_tunggakan
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN payments p ON p.invoice_id = i.id
    LEFT JOIN users u ON p.received_by = u.id
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $area_filter $billing_where
    ORDER BY p.id DESC
    LIMIT 100
")->fetchAll();

$coll_tab = $_GET['tab'] ?? 'tugas';
$settings = $db->query("SELECT company_name, wa_template, wa_template_paid, bank_account FROM settings WHERE id=1")->fetch();
$wa_tpl = $settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}";
$wa_tpl_paid = $settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah lunas.";

// Fetch Banners for Collector
$banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'collector') ORDER BY created_at DESC")->fetchAll();

// Fetch Packages & Areas for Adding Customers
$packages_all = $db->query("SELECT * FROM packages ORDER BY name ASC")->fetchAll();
$areas_all = $db->query("SELECT * FROM areas ORDER BY name ASC")->fetchAll();

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

// Handle update contact by collector
if (isset($_GET['action']) && $_GET['action'] === 'update_contact' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = intval($_POST['customer_id']);
    $contact = $_POST['contact'];
    $db->prepare("UPDATE customers SET contact = ? WHERE id = ?")
       ->execute([$contact, $cid]);
    header("Location: index.php?page=collector&msg=contact_updated");
    exit;
}

// Handle Add Customer by collector
if (isset($_GET['action']) && $_GET['action'] === 'add_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    $package_name = $_POST['package_name'];
    $monthly_fee = $_POST['monthly_fee'];
    $type = $_POST['type'] ?? 'customer';
    $registration_date = $_POST['registration_date'] ?: date('Y-m-d');
    $billing_date = intval($_POST['billing_date'] ?: 1);
    $area = $_POST['area'] ?? '';
    $collector_id = $_SESSION['user_id'];
    
    // Auto-generate unique random customer code
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
    do {
        $customer_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt_check->execute([$customer_code]);
    } while ($stmt_check->fetchColumn() > 0);
    
    $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, type, registration_date, billing_date, area, collector_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$customer_code, $name, $address, $contact, $package_name, $monthly_fee, $type, $registration_date, $billing_date, $area, $collector_id, $u_id]);
    $new_id = $db->lastInsertId();

    // Create Initial Invoice
    if ($monthly_fee > 0) {
        $now = date('Y-m-d H:i:s');
        if ($type === 'customer') {
            // RUMAHAN: Tagihan Terbit di Hari Registrasi
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
            $stmt_inv->execute([$new_id, $monthly_fee, $registration_date, $now]);
        } else {
            // MITRA: Bayar Bulan Depan
            $next_month = date('Y-m', strtotime("+1 month"));
            $bday = str_pad($billing_date, 2, '0', STR_PAD_LEFT);
            $due_date = "{$next_month}-{$bday}";
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
            $stmt_inv->execute([$new_id, $monthly_fee, $due_date, $now]);
        }
    }
    header("Location: index.php?page=collector&tab=pelanggan&msg=customer_added");
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

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'contact_updated'): ?>
<div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:15px; color:var(--success);">
    <i class="fas fa-check-circle"></i> Nomor HP pelanggan berhasil diperbarui!
</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'unpay_success'): ?>
<div style="padding:12px 20px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:10px; margin-bottom:15px; color:var(--danger);">
    <i class="fas fa-undo"></i> Pembayaran dibatalkan! Tagihan pelanggan kembali masuk ke <strong>Tugas Penagihan</strong>.
</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'customer_added'): ?>
<div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:15px; color:var(--success);">
    <i class="fas fa-check-circle"></i> Pelanggan baru berhasil ditambahkan dan ditugaskan kepada Anda!
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
        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{perusahaan}', '{tunggakan}', '{waktu_bayar}', '{admin}'],
        [
            $inv_data['name'], 
            '*' . ($inv_data['customer_code'] ?: $inv_data['customer_id']) . '*', 
            $inv_data['package_name'], 
            $bulan_inv,
            '*Rp ' . number_format($inv_data['amount'], 0, ',', '.') . '*', 
            $settings['company_name'],
            '*Rp ' . number_format($inv_data['total_tunggakan'], 0, ',', '.') . '*',
            '*' . ($inv_data['payment_date'] ? date('d/m/Y H:i', strtotime($inv_data['payment_date'])) : date('d/m/Y H:i')) . '*',
            '*' . $_SESSION['user_name'] . '*'
        ],
        $wa_tpl_paid
    );
    $parsed_msg = str_ireplace('LUNAS', '*LUNAS*', $parsed_msg);
    $parsed_msg = str_replace('**', '*', $parsed_msg); // Clean up
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

<!-- Filter Header for Collector Dashboard (Global Period Filter) -->
<div style="padding:20px; margin-bottom:20px; background:rgba(var(--primary-rgb), 0.05); border-radius:12px; border:1px solid rgba(var(--primary-rgb), 0.1);">
    <form method="GET" class="grid-filters">
        <input type="hidden" name="page" value="collector">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($coll_tab) ?>">
        
        <div class="filter-group">
            <label><i class="fas fa-calendar-alt"></i> Periote Revenue</label>
            <div style="display:flex; align-items:center; gap:8px;">
                <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>" style="padding:8px 10px; font-size:12px;">
                <span style="color:var(--text-secondary); font-size:12px; opacity:0.5;">s/d</span>
                <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>" style="padding:8px 10px; font-size:12px;">
            </div>
        </div>

        <div class="filter-group">
            <label><i class="fas fa-search"></i> Cari Data</label>
            <input type="text" name="search_tugas" class="form-control" placeholder="Nama / Alamat..." value="<?= htmlspecialchars($_GET['search_tugas'] ?? '') ?>" style="padding:8px 12px; font-size:13px;">
        </div>

        <div class="grid-actions" style="margin-top:auto;">
            <button type="submit" class="btn btn-primary btn-sm" style="flex:1; height:38px;"><i class="fas fa-filter"></i> Terapkan</button>
            <?php if($date_from || $date_to || isset($_GET['search_tugas'])): ?>
                <a href="index.php?page=collector&tab=<?= $coll_tab ?>" class="btn btn-ghost btn-sm" style="flex:0; height:38px; padding:0 15px;"><i class="fas fa-sync"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Statistik Cards - Mobile optimized adaptive grid -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap:12px; margin-bottom:16px;">
    <!-- Total Pelanggan -->
    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--primary); background:linear-gradient(135deg, rgba(37, 99, 235, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=pelanggan&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">
        <i class="fas fa-users" style="font-size:20px; color:var(--primary); margin-bottom:8px;"></i>
        <div style="font-size:22px; font-weight:800; color:var(--stat-value-color);"><?= $total_customers ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:600;">Pelanggan</div>
    </div>

    <!-- Belum Bayar -->
    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--danger); background:linear-gradient(135deg, rgba(239, 68, 68, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">
        <i class="fas fa-exclamation-circle" style="font-size:20px; color:var(--danger); margin-bottom:8px;"></i>
        <div style="font-size:22px; font-weight:800; color:var(--stat-value-color);"><?= $unpaid_count ?> <span style="font-size:12px; font-weight:normal;">Item</span></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:600;">Belum Lunas</div>
        <div style="font-size:13px; font-weight:800; color:var(--danger); margin-top:5px;">Rp<?= number_format($unpaid_total, 0, ',', '.') ?></div>
    </div>

    <!-- Lunas Periode Ini -->
    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--success); background:linear-gradient(135deg, rgba(16, 185, 129, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">
        <i class="fas fa-check-circle" style="font-size:20px; color:var(--success); margin-bottom:8px;"></i>
        <div style="font-size:22px; font-weight:800; color:var(--stat-value-color);"><?= count($recent_paid) ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:600;">Berhasil</div>
        <div style="font-size:13px; font-weight:800; color:var(--success); margin-top:5px;">Rp<?= number_format($paid_total_range, 0, ',', '.') ?></div>
    </div>

    <!-- Total Revenue -->
    <div class="glass-panel" style="padding:15px; text-align:center; border-top:4px solid var(--warning); background:linear-gradient(135deg, rgba(245, 158, 11, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">
        <i class="fas fa-coins" style="font-size:20px; color:var(--warning); margin-bottom:8px;"></i>
        <div style="font-size:18px; font-weight:800; color:var(--stat-value-color);">Rp<?= number_format($paid_total_range, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:600;">Revenue</div>
        <div style="font-size:10px; color:var(--text-secondary); margin-top:5px; font-weight:700; opacity:0.8;"><?= date('d/m', strtotime($date_from)) ?> - <?= date('d/m', strtotime($date_to)) ?></div>
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

<!-- Tab Navigation - Segmented Control Style -->
<div style="display:flex; background:rgba(255,255,255,0.03); padding:5px; border-radius:14px; margin-bottom:20px; gap:5px;" class="desktop-tabs">
    <a href="index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" style="flex:1; text-align:center; text-decoration:none; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'tugas' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        <i class="fas fa-tasks"></i> <span class="hide-mobile">Tugas Penagihan</span><span class="show-mobile">Tugas</span>
    </a>
    <a href="index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" style="flex:1; text-align:center; text-decoration:none; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'lunas' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        <i class="fas fa-check-double"></i> <span class="hide-mobile">Sudah Lunas</span><span class="show-mobile">Lunas</span>
    </a>
    <a href="index.php?page=collector&tab=pelanggan&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" style="flex:1; text-align:center; text-decoration:none; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'pelanggan' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        <i class="fas fa-users"></i> <span class="hide-mobile">Daftar Pelanggan</span><span class="show-mobile">Pelanggan</span>
    </a>
</div>
        <i class="fas fa-users"></i> Semua Pelanggan
    </a>
</div>

<?php if($coll_tab === 'pelanggan'): ?>
<!-- TAB: Data Pelanggan -->
<div class="glass-panel" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:16px; border-bottom:1px solid var(--glass-border); padding-bottom:15px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:34px; height:34px; border-radius:8px; background:var(--primary); color:white; display:flex; align-items:center; justify-content:center; font-size:14px;">
                <i class="fas fa-users"></i>
            </div>
            <h3 style="font-size:18px; font-weight:700; margin:0;">Daftar Pelanggan</h3>
        </div>
        <div style="display:flex; gap:10px; flex:1; justify-content:flex-end; flex-wrap:wrap; align-items:center;">
            <button class="btn btn-primary" onclick="showAddCustomerModal()" style="padding:10px 20px; font-weight:700; border-radius:10px; font-size:13px; width:fit-content; height:42px;">
                <i class="fas fa-plus"></i> Tambah Pelanggan
            </button>
            <form method="GET" style="display:flex; gap:0; flex:1; max-width:320px; position:relative;">
                <input type="hidden" name="page" value="collector">
                <input type="hidden" name="tab" value="pelanggan">
                <input type="text" name="search_cust" class="form-control" placeholder="Cari pelanggan..." value="<?= htmlspecialchars($search_cust) ?>" style="padding:10px 45px 10px 20px; font-size:13px; height:42px; width:100%; border-radius:10px 0 0 10px; border-right:none;">
                <button type="submit" class="btn btn-primary" style="padding:0 18px; height:42px; position:absolute; right:0; border-radius:0 10px 10px 0; display:flex; align-items:center; justify-content:center; box-shadow:none;">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>
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
            <div class="grid-actions" style="margin-top:15px; grid-template-columns: 1fr 1fr; display:grid;">
                <button class="btn btn-sm btn-warning" style="color:white; font-weight:700; border-radius:10px;" onclick="showCreateInvoice(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                    <i class="fas fa-file-invoice-dollar"></i> Tagihan
                </button>
                <div class="btn-group">
                    <?php if($wa_num): ?>
                        <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white; flex:1;" title="WA Tagih">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                        <a href="tel:<?= htmlspecialchars($ac['contact']) ?>" class="btn btn-sm btn-ghost" style="flex:1;" title="Telepon">
                            <i class="fas fa-phone"></i>
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-ghost" style="flex:1;" onclick="showUpdateContact(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', '<?= htmlspecialchars($ac['contact'] ?: '') ?>')" title="Ubah No HP">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
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
                        <button class="btn btn-sm btn-ghost" onclick="showUpdateContact(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', '<?= htmlspecialchars($ac['contact'] ?: '') ?>')">
                            <i class="fas fa-edit"></i> No HP
                        </button>
                        <?php if($wa_num): 
                            $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                            $inv_month = $mon_id[intval(date('m')) - 1] . ' ' . date('Y');
                            $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
                            $package_display = $ac['package_name'] ?: '-';
                            $nominal_display = 'Rp ' . number_format($ac['monthly_fee'], 0, ',', '.');

                            $reminder_msg_desk = str_replace(
                                ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}'], 
                                [$ac['name'], '*' . $cust_id_display . '*', $package_display, $inv_month, '*' . $nominal_display . '*', '-', '*' . trim($settings['bank_account']) . '*', '*Rp 0*', '*' . $nominal_display . '*'], 
                                $wa_tpl
                            );
                            $wa_text = urlencode($reminder_msg_desk);
                        ?>
                            <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white; font-weight:800;">
                                <i class="fab fa-whatsapp"></i> WA Tagih
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div> <!-- end .table-container -->
</div> <!-- end .glass-panel (Tab: Data Pelanggan) -->

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
                            <?= date('d/m/Y H:i', strtotime($fp['payment_date'])) ?>
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

            // Consolidated Arrears Reminder using WA Template
            $mon_name = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            $oldest_due = $inv['oldest_due_date'] ? date('d/m/Y', strtotime($inv['oldest_due_date'])) : '-';
            $inv_month = $inv['oldest_due_date'] ? $mon_name[intval(date('m', strtotime($inv['oldest_due_date']))) - 1] . ' ' . date('Y', strtotime($inv['oldest_due_date'])) : '-';
            
            // Calculate breakdown for consolidated reminder
            $total_all = $inv['total_unpaid'];
            $num_inv = intval($inv['num_arrears']);
            $current_tagihan = $num_inv > 0 ? $total_all / $num_inv : $total_all; // Average per month
            $tunggakan_past = $total_all - $current_tagihan;

            $tagihan_display = 'Rp ' . number_format($current_tagihan, 0, ',', '.');
            $tunggakan_display = 'Rp ' . number_format($tunggakan_past, 0, ',', '.');
            $total_display = 'Rp ' . number_format($total_all, 0, ',', '.');
            
            $msg = str_replace(
                ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}'], 
                [$inv['name'], '*' . $cust_id_display . '*', $inv['package_name'] ?: '-', $inv_month, '*' . $tagihan_display . '*', '*' . $oldest_due . '*', '*' . trim($settings['bank_account']) . '*', '*' . $tunggakan_display . '*', '*' . $total_display . '*'], 
                $wa_tpl
            );

            // Safety check for clarity in multiple arrears
            if (strpos($msg, 'TOTAL') === false && strpos($msg, 'total') === false && $num_inv > 1) {
                $msg .= "\n\n*Informasi Penting:* Anda memiliki *$num_inv tunggakan* dengan total pembayaran *$total_display*.";
            }
            
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
            
            <!-- Amount + Actions (Redesigned for better density and alignment) -->
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--glass-border);">
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:10px;">
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin-bottom:2px;">Total Tagihan</div>
                        <div style="font-size:20px; font-weight:900; color:var(--primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            Rp <?= number_format($grand_total, 0, ',', '.') ?>
                        </div>
                    </div>
                    
                    <div style="display:flex; gap:6px; flex-shrink:0;">
                <!-- Actions Grid -->
                <div style="margin-top:12px; display:grid; grid-template-columns: 1fr 44px; gap:8px;">
                    <form id="payForm_<?= $inv['cust_id'] ?>" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
                        <input type="hidden" name="customer_id" value="<?= $inv['cust_id'] ?>">
                        <input type="hidden" name="num_months" id="numMonths_<?= $inv['cust_id'] ?>" value="1">
                    </form>
                    <button type="button" class="btn btn-primary" style="padding:12px; font-weight:800; border-radius:12px; display:flex; align-items:center; justify-content:center; gap:8px; font-size:13px;" 
                            onclick="handlePay(<?= $inv['cust_id'] ?>, <?= $num_arrears ?>, '<?= addslashes($inv['name']) ?>')">
                        <i class="fas fa-wallet"></i> BAYAR
                    </button>
                    
                    <?php if($wa_number): ?>
                        <a href="https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-ghost" style="color:#20c997; border-color:rgba(32, 201, 151, 0.2); width:44px; height:44px; display:flex; align-items:center; justify-content:center; border-radius:12px; padding:0;" title="Kirim WA Tagihan">
                            <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                        </a>
                    <?php endif; ?>
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
        <i class="fas fa-check-double"></i> Pelanggan Sudah Lunas (<?= date('d M', strtotime($date_from)) ?> - <?= date('d M', strtotime($date_to)) ?>)
    </h3>
    <div class="scroll-container" style="max-height:600px; overflow-y:auto; padding-right:5px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px;">
            <?php foreach($recent_paid as $rp): ?>
        <div class="glass-panel" style="padding:16px; border-left: 4px solid var(--success);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div>
                    <div style="font-weight:bold; font-size:15px;"><?= htmlspecialchars($rp['name']) ?></div>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:2px;">
                        <i class="fas fa-calendar-check" style="color:var(--success);"></i> Bayar: <?= date('d/m/Y H:i', strtotime($rp['payment_date'])) ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:800; color:var(--success); font-size:16px;">Rp <?= number_format($rp['paid_amount'], 0, ',', '.') ?></div>
                    <div style="font-size:10px; color:var(--text-secondary);">STATUS LUNAS</div>
                </div>
            </div>
            <div style="margin-top:10px; padding-top:10px; border-top:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="font-size:12px; color:var(--text-secondary);"><i class="fas fa-check-circle" style="color:var(--success);"></i> Bayar Lunas</div>
                    <a href="index.php?page=admin_invoices&action=unpay&id=<?= $rp['id'] ?>" class="btn btn-xs btn-ghost" style="color:var(--danger); font-size:9px; padding:2px 6px; border:1px solid rgba(239, 68, 68, 0.1);" title="Batalkan Pembayaran" onclick="return confirm('Yakin ingin membatalkan pembayaran ini?')">
                        <i class="fas fa-undo"></i> Batal
                    </a>
                </div>
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
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{perusahaan}', '{tunggakan}', '{waktu_bayar}', '{admin}'],
                            [
                                $rp['name'], 
                                '*' . ($rp['customer_code'] ?: $rp['customer_id']) . '*', 
                                $rp['package_name'], 
                                $bulan_rp,
                                '*Rp ' . number_format($rp['paid_amount'], 0, ',', '.') . '*', 
                                $settings['company_name'],
                                '*Rp ' . number_format($rp['total_tunggakan'] ?? 0, 0, ',', '.') . '*',
                                '*' . date('d/m/Y H:i', strtotime($rp['payment_date'])) . '*',
                                '*' . ($rp['admin_name'] ?: 'System') . '*'
                            ],
                            $wa_tpl_paid
                            );
                        $parsed_msg_rp = str_ireplace('LUNAS', '*LUNAS*', $parsed_msg_rp);
                        $parsed_msg_rp = str_replace('**', '*', $parsed_msg_rp); // Clean up
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

<!-- Modal Ubah Nomor HP -->
<div id="updateContactModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;"><i class="fas fa-edit"></i> Ubah Nomor HP</h3>
        <div style="font-size:14px; color:var(--text-secondary); margin-bottom:15px;">Pelanggan: <strong id="modalContactCustName"></strong></div>
        <form action="index.php?page=collector&action=update_contact" method="POST">
            <input type="hidden" name="customer_id" id="modalContactCustId">
            <div class="form-group">
                <label>Nomor HP Baru</label>
                <input type="text" name="contact" id="modalContactValue" class="form-control" placeholder="Contoh: 08123456789" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('updateContactModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-sm" style="background:var(--primary); color:white;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Pelanggan (Redesigned for Premium Layout) -->
<div id="addCustomerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter: blur(8px);">
    <div class="glass-panel scroll-container" style="width:100%; max-width:600px; padding:0; margin:20px; max-height:85vh; overflow-y:auto; border-radius:18px; border:1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 50px rgba(0,0,0,0.3);">
        <!-- Modal Header -->
        <div style="padding:22px 28px; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center; background:rgba(35, 206, 217, 0.05);">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:36px; height:36px; border-radius:10px; background:var(--primary); color:white; display:flex; align-items:center; justify-content:center; font-size:16px;">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h3 style="margin:0; font-size:18px; font-weight:700;">Tambah Pelanggan Baru</h3>
            </div>
            <button onclick="document.getElementById('addCustomerModal').style.display='none'" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; font-size:18px; transition:color 0.2s;" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-secondary)'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form action="index.php?page=collector&action=add_customer" method="POST" id="addCustomerForm" style="padding:28px;">
            <!-- Group: Identity -->
            <div style="margin-bottom:24px;">
                <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--primary); margin-bottom:16px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-id-card"></i> Informasi Identitas
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Nama Lengkap Pelanggan</label>
                        <input type="text" name="name" class="form-control" required placeholder="Sesuai KTP" style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">WhatsApp / HP</label>
                        <input type="text" name="contact" class="form-control" placeholder="0812..." required style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Tipe Pelanggan</label>
                        <select name="type" class="form-control" required style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                            <option value="customer">Rumahan (Standard)</option>
                            <option value="partner">Mitra (B2B)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Group: Service -->
            <div style="margin-bottom:24px;">
                <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--primary); margin-bottom:16px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-wifi"></i> Paket & Lokasi
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px;">
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Paket Internet</label>
                        <select name="package_name" class="form-control" onchange="syncAddPrice(this)" required style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                            <option value="">-- Pilih Paket --</option>
                            <?php foreach($packages_all as $pkg): ?>
                                <option value="<?= htmlspecialchars($pkg['name']) ?>" data-fee="<?= $pkg['price'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rp<?= number_format($pkg['price'],0,',','.') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Biaya Bulanan (Rp)</label>
                        <input type="number" name="monthly_fee" id="add_monthly_fee" class="form-control" required style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Area Pemasangan</label>
                        <select name="area" class="form-control" required style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                            <option value="">-- Pilih Area --</option>
                            <?php foreach($areas_all as $area): ?>
                                <option value="<?= htmlspecialchars($area['name']) ?>"><?= htmlspecialchars($area['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Tanggal Tagih</label>
                        <select name="billing_date" class="form-control" style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                            <?php for($d=1;$d<=28;$d++): ?>
                                <option value="<?= $d ?>">Tanggal <?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Alamat Lengkap</label>
                        <textarea name="address" class="form-control" rows="3" placeholder="Jl. Contoh No. 1, Desa/Dusun, RT/RW..." style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;"></textarea>
                    </div>
                </div>
            </div>

            <!-- Footer: Actions -->
            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:10px; padding-top:24px; border-top:1px solid var(--glass-border);">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addCustomerModal').style.display='none'" style="padding:12px 24px; border-radius:12px; font-weight:600; font-size:14px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:12px 30px; border-radius:12px; font-weight:800; font-size:14px; box-shadow: 0 4px 15px rgba(35, 206, 217, 0.2);">
                    <i class="fas fa-save" style="margin-right:8px;"></i> Daftarkan Pelanggan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddCustomerModal() {
    document.getElementById('addCustomerModal').style.display = 'flex';
}

function syncAddPrice(select) {
    const fee = select.options[select.selectedIndex].getAttribute('data-fee');
    if(fee) {
        document.getElementById('add_monthly_fee').value = fee;
    }
}

function showCreateInvoice(id, name, fee) {
    document.getElementById('modalCustId').value = id;
    document.getElementById('modalCustName').textContent = name;
    document.getElementById('modalAmount').value = fee;
    document.getElementById('createInvoiceModal').style.display = 'flex';
}

function showUpdateContact(id, name, contact) {
    document.getElementById('modalContactCustId').value = id;
    document.getElementById('modalContactCustName').textContent = name;
    document.getElementById('modalContactValue').value = contact;
    document.getElementById('updateContactModal').style.display = 'flex';
}

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
