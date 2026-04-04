<?php
// Data collector
$user_id = $_SESSION['user_id'];
// Base filter: assigned collector id
$collector_id = $_SESSION['user_id'];
$area_filter = " AND c.collector_id = " . intval($collector_id);

// Billing date filter
$filter_billing_date = $_GET['filter_billing_date'] ?? '';
$billing_where = "";
if ($filter_billing_date !== "") {
    $billing_where = " AND c.billing_date = " . intval($filter_billing_date);
}

// === STATISTIK ===
// Total pelanggan yang menjadi tanggung jawab
$total_customers = $db->query("
    SELECT COUNT(*) FROM customers c WHERE 1=1 $area_filter $billing_where
")->fetchColumn();

// Total tagihan belum lunas (jumlah invoice & nominal)
$unpaid_count = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $area_filter $billing_where
")->fetchColumn();

$unpaid_total = $db->query("
    SELECT COALESCE(SUM(i.amount), 0) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $area_filter $billing_where
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

// Persentase tagihan lunas bulan ini
$total_invoices_month = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.due_date BETWEEN '$month_start' AND '$month_end'
    $area_filter $billing_where
")->fetchColumn();
$percent_paid = $total_invoices_month > 0 ? round(($paid_count_month / $total_invoices_month) * 100) : 0;

// Pagination Logic for Tasks
$items_per_page = 50;
$p_tugas = isset($_GET['p_tugas']) ? max(1, intval($_GET['p_tugas'])) : 1;
$off_tugas = ($p_tugas - 1) * $items_per_page;
$total_tugas_pages = ceil($unpaid_count / $items_per_page);

// Fetch unpaid invoices with limit
$query = "
    SELECT i.*, c.id as cust_id, c.name, c.address, c.contact, c.area as cust_area, c.customer_code, c.package_name
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' $area_filter $billing_where
    ORDER BY i.due_date ASC
    LIMIT $items_per_page OFFSET $off_tugas
";
$unpaid_invoices = $db->query($query)->fetchAll();

// Fetch recently paid (5 terakhir)
$recent_paid = $db->query("
    SELECT i.*, c.name, c.contact, p.payment_date, p.amount as paid_amount
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN payments p ON p.invoice_id = i.id
    WHERE i.status = 'Lunas' $area_filter $billing_where
    ORDER BY p.payment_date DESC
    LIMIT 5
")->fetchAll();

$settings = $db->query("SELECT wa_template, wa_template_paid, bank_account FROM settings WHERE id=1")->fetch();
$wa_tpl = $settings['wa_template'] ?: "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}";
$wa_tpl_paid = $settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah lunas.";

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

$cust_query = "SELECT * FROM customers c WHERE 1=1 $area_filter $billing_where ORDER BY c.name ASC LIMIT $items_per_page OFFSET $off_cust";
$area_customers = $db->query($cust_query)->fetchAll();

$coll_tab = $_GET['tab'] ?? 'tugas';
?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'invoice_created'): ?>
<div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:15px; color:var(--success);">
    <i class="fas fa-check-circle"></i> Tagihan berhasil dibuat!
</div>
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

<!-- Statistik Cards - Mobile optimized 2x2 grid -->
<div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; margin-bottom:16px;">
    <!-- Total Pelanggan -->
    <div class="glass-panel" style="padding:16px; text-align:center; border-top:3px solid var(--primary);">
        <i class="fas fa-users" style="font-size:22px; color:var(--primary); margin-bottom:6px;"></i>
        <div style="font-size:28px; font-weight:800; color:var(--stat-value-color);"><?= $total_customers ?></div>
        <div style="font-size:12px; color:var(--text-secondary); margin-top:3px;">Pelanggan</div>
    </div>

    <!-- Belum Bayar -->
    <div class="glass-panel" style="padding:16px; text-align:center; border-top:3px solid var(--danger);">
        <i class="fas fa-exclamation-circle" style="font-size:22px; color:var(--danger); margin-bottom:6px;"></i>
        <div style="font-size:28px; font-weight:800; color:var(--stat-value-color);"><?= $unpaid_count ?></div>
        <div style="font-size:12px; color:var(--text-secondary); margin-top:3px;">Belum Lunas</div>
        <div style="font-size:12px; font-weight:700; color:var(--danger); margin-top:2px;">Rp <?= number_format($unpaid_total, 0, ',', '.') ?></div>
    </div>

    <!-- Lunas Bulan Ini -->
    <div class="glass-panel" style="padding:16px; text-align:center; border-top:3px solid var(--success);">
        <i class="fas fa-check-circle" style="font-size:22px; color:var(--success); margin-bottom:6px;"></i>
        <div style="font-size:28px; font-weight:800; color:var(--stat-value-color);"><?= $paid_count_month ?></div>
        <div style="font-size:12px; color:var(--text-secondary); margin-top:3px;">Lunas (<?= date('M') ?>)</div>
        <div style="font-size:12px; font-weight:700; color:var(--success); margin-top:2px;">Rp <?= number_format($paid_total_month, 0, ',', '.') ?></div>
    </div>

    <!-- Total Pendapatan -->
    <div class="glass-panel" style="padding:16px; text-align:center; border-top:3px solid var(--warning);">
        <i class="fas fa-coins" style="font-size:22px; color:var(--warning); margin-bottom:6px;"></i>
        <div style="font-size:20px; font-weight:800; color:var(--stat-value-color);">Rp <?= number_format($paid_total_all, 0, ',', '.') ?></div>
        <div style="font-size:12px; color:var(--text-secondary); margin-top:3px;">Total Revenue</div>
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
        <i class="fas fa-tasks"></i> Tugas Penagihan
    </a>
    <a href="index.php?page=collector&tab=pelanggan" class="btn btn-sm" style="<?= $coll_tab === 'pelanggan' ? 'background:var(--primary); color:white;' : 'background:var(--btn-ghost-bg); color:var(--text-secondary); border:1px solid var(--btn-ghost-border);' ?> padding:10px 20px; border-radius:10px;">
        <i class="fas fa-users"></i> Data Pelanggan (<?= count($area_customers) ?>)
    </a>
</div>

<?php if($coll_tab === 'pelanggan'): ?>
<!-- TAB: Data Pelanggan -->
<div class="glass-panel" style="padding:20px;">
    <h3 style="font-size:18px; margin-bottom:16px;"><i class="fas fa-users"></i> Daftar Pelanggan</h3>
    
    <!-- Mobile-friendly card list -->
    <div class="customer-list-mobile" style="display:none;">
        <?php foreach($area_customers as $ac): 
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
        ?>
        <div class="glass-panel" style="padding:16px; margin-bottom:10px; border-left:3px solid var(--primary);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <div style="font-weight:600; font-size:15px;"><?= htmlspecialchars($ac['name']) ?></div>
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

<?php else: ?>
<!-- TAB: Tugas Penagihan (existing content) -->

<!-- Filter Riwayat Pembayaran -->
<div class="glass-panel" style="padding:16px 20px; margin-bottom:16px;">
    <h3 style="font-size:15px; margin-bottom:10px;"><i class="fas fa-search"></i> Cek Riwayat Pembayaran</h3>
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
    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px;">
        <?php 
        // Pre-fetch all unpaid invoices for these customers to avoid N+1 query problem
        $cust_ids = array_filter(array_unique(array_column($unpaid_invoices, 'customer_id')));
        $all_unpaid_data = [];
        if(!empty($cust_ids)) {
            $ids_str = implode(',', $cust_ids);
            $unpaid_list = $db->query("SELECT id, customer_id, amount FROM invoices WHERE status = 'Belum Lunas' AND customer_id IN ($ids_str)")->fetchAll();
            foreach($unpaid_list as $up) $all_unpaid_data[$up['customer_id']][] = $up;
        }

        foreach($unpaid_invoices as $inv): 
            $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv['contact']));
            
            $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            $inv_month = $mon_id[intval(date('m', strtotime($inv['due_date']))) - 1] . ' ' . date('Y', strtotime($inv['due_date']));
            $cust_id_display = $inv['customer_code'] ?: str_pad($inv['cust_id'], 5, "0", STR_PAD_LEFT);
            $package_display = $inv['package_name'] ?: '-';
            $nominal_display = 'Rp ' . number_format($inv['amount'], 0, ',', '.');
            
            // Calculate previous arrears
            $tunggakan_prev = 0;
            $up_list = $all_unpaid_data[$inv['customer_id']] ?? [];
            foreach($up_list as $up) {
                if($up['id'] < $inv['id']) $tunggakan_prev += $up['amount'];
            }
            $t_prev_display = $tunggakan_prev > 0 ? 'Rp ' . number_format($tunggakan_prev, 0, ',', '.') : 'Rp 0';
            $total_harus = $inv['amount'] + $tunggakan_prev;
            $total_harus_display = 'Rp ' . number_format($total_harus, 0, ',', '.');

            $msg = str_replace(
                ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}'], 
                [$inv['name'], $cust_id_display, $package_display, $inv_month, $nominal_display, date('d M Y', strtotime($inv['due_date'])), $settings['bank_account'], $t_prev_display, $total_harus_display], 
                $wa_tpl
            );

            // Add breakdown if not in template
            if(strpos($msg, '{total_harus}') === false && strpos($msg, 'TOTAL') === false) {
                $msg .= "\n\n*Rincian:*";
                $msg .= "\n- Tagihan: $nominal_display";
                if($tunggakan_prev > 0) $msg .= "\n- Tunggakan: $t_prev_display";
                $msg .= "\n-------------------";
                $msg .= "\n*TOTAL: $total_harus_display*";
            }
            
            $wa_text = urlencode($msg);
            
            // Cek overdue
            $is_overdue = strtotime($inv['due_date']) < time();
        ?>
        <div class="glass-panel" style="padding:16px; border-left: 4px solid <?= $is_overdue ? 'var(--danger)' : 'var(--warning)' ?>;">
            <!-- Header: Name + Invoice -->
            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:flex-start;">
                <div style="font-weight:bold; font-size:15px;"><?= htmlspecialchars($inv['name']) ?></div>
                <div style="color:var(--text-secondary); font-size:11px; font-family:monospace;">#INV-<?= str_pad($inv['id'], 5, "0", STR_PAD_LEFT) ?></div>
            </div>
            
            <!-- Arrears Alert -->
            <?php 
            $other_unpaid_count = count($invoices_to_check) - 1; 
            if($other_unpaid_count > 0): 
            ?>
                <div style="margin-bottom:12px; padding:8px 12px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:8px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-exclamation-triangle" style="color:var(--danger); font-size:14px;"></i>
                    <span style="font-size:12px; font-weight:700; color:var(--danger);">Ada <?= $other_unpaid_count ?> Tagihan Lain</span>
                </div>
            <?php endif; ?>

            <!-- Info -->
            <div style="font-size:13px; font-weight: 500; margin-bottom:12px; color:var(--text-secondary);">
                <div style="margin-bottom:4px;"><i class="fas fa-map-marker-alt" style="width:16px; color:var(--primary);"></i> <?= htmlspecialchars($inv['address'] ?: '-') ?></div>
                <div style="margin-bottom:4px;"><i class="fas fa-phone" style="width:16px; color:var(--primary);"></i> <?= htmlspecialchars($inv['contact'] ?: '-') ?></div>
                <div style="color:<?= $is_overdue ? 'var(--danger)' : 'var(--warning)' ?>; font-weight: 700;">
                    <i class="fas fa-calendar-times" style="width:16px;"></i> 
                    Jatuh Tempo: <?= date('d M Y', strtotime($inv['due_date'])) ?>
                    <?php if($is_overdue): ?>
                        <span class="badge badge-danger" style="font-size:10px; margin-left:4px;">OVERDUE</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Amount + Actions -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding-top:12px; border-top:1px solid var(--glass-border);">
                <div style="font-size:18px; font-weight:bold; color:var(--primary);">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></div>
                <div style="display:flex; gap:6px;">
                    <?php if($wa_number): ?>
                        <a href="https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white; padding:10px 12px;" title="Hubungi Pelanggan">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    <?php endif; ?>
                    <a href="index.php?page=admin_invoices&action=mark_paid&id=<?= $inv['id'] ?>" onclick="return confirm('Konfirmasi terima uang Rp <?= number_format($inv['amount'], 0, ',', '.') ?> dari <?= addslashes($inv['name']) ?>?')" class="btn btn-sm btn-success" style="padding:10px 16px; font-weight:700;">
                        <i class="fas fa-check-circle"></i> Bayar
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if(count($unpaid_invoices) == 0): ?>
            <div style="grid-column: 1/-1; text-align:center; padding: 40px;">
                <i class="fas fa-check-circle" style="font-size:48px; color:var(--success); margin-bottom:15px; display:block;"></i>
                <div style="font-size:18px;">Semua tagihan sudah lunas!</div>
                <p style="color:var(--text-secondary);">Tidak ada tugas penagihan untuk saat ini.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Riwayat Lunas Terakhir -->
<?php if(count($recent_paid) > 0): ?>
<div class="glass-panel" style="padding:20px;">
    <h3 style="font-size:16px; margin-bottom:12px; color:var(--success);">
        <i class="fas fa-history"></i> 5 Pembayaran Terakhir
    </h3>
    
    <!-- Mobile-friendly recent payments -->
    <div class="recent-paid-mobile" style="display:none;">
        <?php foreach($recent_paid as $rp): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid var(--glass-border);">
            <div>
                <div style="font-weight:600; font-size:14px;"><?= htmlspecialchars($rp['name']) ?></div>
                <div style="font-size:11px; color:var(--text-secondary);"><i class="fas fa-check-circle" style="color:var(--success);"></i> <?= date('d M Y H:i', strtotime($rp['payment_date'])) ?></div>
            </div>
            <div style="font-weight:bold; color:var(--success); font-size:14px;">Rp <?= number_format($rp['paid_amount'], 0, ',', '.') ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Desktop table -->
    <div class="table-container recent-paid-desktop">
        <table>
            <thead>
                <tr>
                    <th>Pelanggan</th>
                    <th>Nominal</th>
                    <th>Tanggal Bayar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($recent_paid as $rp): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($rp['name']) ?></td>
                    <td style="font-weight:bold; color:var(--success);">Rp <?= number_format($rp['paid_amount'], 0, ',', '.') ?></td>
                    <td style="font-size:13px; color:var(--text-secondary);">
                        <i class="fas fa-check-circle" style="color:var(--success);"></i>
                        <?= date('d M Y H:i', strtotime($rp['payment_date'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
@media (max-width: 768px) {
    .recent-paid-mobile { display: block !important; }
    .recent-paid-desktop { display: none !important; }
}
</style>
<?php endif; ?>
<?php endif; /* end tab tugas */ ?>
