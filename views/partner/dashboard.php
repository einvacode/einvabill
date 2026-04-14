<?php
// Partner view
$user_id = intval($_SESSION['user_id']);
$action = $_GET['action'] ?? 'list';
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

// Fetch current user brand/settings
$me = $db->query("SELECT * FROM users WHERE id = $user_id")->fetch();
$settings = $db->query("SELECT company_name, wa_template, wa_template_paid, site_url, bank_account FROM settings WHERE id = 1")->fetch();

$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();

// Template Resolution: Individual -> Core fallback -> Hardcoded
$wa_tpl = !empty($me['wa_template']) ? $me['wa_template'] : ($settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}");
$wa_tpl_paid = !empty($me['wa_template_paid']) ? $me['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah lunas.");
$my_bank_info = !empty($me['brand_bank']) ? ($me['brand_bank'] . " " . $me['brand_rekening']) : ($settings['bank_account'] ?? 'Hubungi CS');

// Handle Add Customer by partner (MOVE TO TOP for fresh stats)
if (isset($_GET['action']) && $_GET['action'] === 'add_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    $package_name = $_POST['package_name'];
    $monthly_fee = $_POST['monthly_fee'];
    $type = 'customer'; 
    $registration_date = $_POST['registration_date'] ?: date('Y-m-d');
    $billing_date = intval($_POST['billing_date'] ?: 1);
    $area = $_POST['area'] ?? '';
    
    // Auto-generate unique random customer code
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
    do {
        $customer_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt_check->execute([$customer_code]);
    } while ($stmt_check->fetchColumn() > 0);
    
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, type, registration_date, billing_date, area, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_code, $name, $address, $contact, $package_name, $monthly_fee, $type, $registration_date, $billing_date, $area, $user_id]);
        $new_id = $db->lastInsertId();

        // Initial Invoice for current month
        if ($monthly_fee > 0) {
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
            $stmt_inv->execute([$new_id, $monthly_fee, $registration_date, date('Y-m-d H:i:s')]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: index.php?page=partner&msg=error&err=" . urlencode($e->getMessage()));
        exit;
    }
    
    header("Location: index.php?page=partner&msg=added&t=" . time());
    exit;
}

// ACTION: Import Actions (Adapted from Admin)
if ($action === 'import_file' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    if (!empty($file) && is_uploaded_file($file)) {
        $handle = fopen($file, "r");
        $firstLine = fgets($handle);
        $delimiters = [',', ';', "\t"];
        $delimiter = ','; $maxCount = 0;
        foreach ($delimiters as $d) {
            $count = substr_count($firstLine, $d);
            if ($count > $maxCount) { $maxCount = $count; $delimiter = $d; }
        }
        rewind($handle);
        $pending = [];
        $mapping = null;
        $isFirst = true;
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            if (empty($row) || count($row) < 3) continue;
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            if ($isFirst) {
                $mapping = detectImportMapping($row);
                $isFirst = false;
                if ($mapping) continue; 
            }
            if (trim($row[$mapping['name'] ?? 1] ?? '') == '') continue;
            $pending[] = $row;
        }
        fclose($handle);
        $_SESSION['pending_import_partner'] = $pending;
        $_SESSION['pending_mapping_partner'] = $mapping;
        header("Location: index.php?page=partner&action=import_preview");
        exit;
    }
}

if ($action === 'import_paste' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST['paste_data'];
    $lines = explode("\n", trim($data));
    $pending = [];
    $mapping = null;
    $isFirst = true;
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = explode("\t", trim($line)); 
        if (count($row) < 5) $row = explode(";", trim($line));
        if (count($row) < 5) $row = str_getcsv(trim($line));
        if (count($row) >= 3) {
            if ($isFirst) {
                $mapping = detectImportMapping($row);
                $isFirst = false;
                if ($mapping) continue;
            }
            if (trim($row[$mapping['name'] ?? 1] ?? '') == '') continue;
            $pending[] = $row;
        }
    }
    $_SESSION['pending_import_partner'] = $pending;
    $_SESSION['pending_mapping_partner'] = $mapping;
    header("Location: index.php?page=partner&action=import_preview");
    exit;
}

if ($action === 'import_confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending = $_SESSION['pending_import_partner'] ?? [];
    $map = $_SESSION['pending_mapping_partner'] ?? ['type' => 0, 'name' => 1, 'address' => 2, 'contact' => 3, 'package' => 4, 'fee' => 5, 'ip' => 6, 'reg_date' => 7, 'bill_date' => 8, 'area' => 9];
    if (empty($pending)) { header("Location: index.php?page=partner"); exit; }

    $stmt = $db->prepare("INSERT INTO customers (name, address, contact, package_name, monthly_fee, ip_address, type, registration_date, billing_date, area, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_chk = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
    $count = 0;
    $db->beginTransaction();
    try {
        foreach ($pending as $row) {
            $name = trim($row[$map['name']] ?? '');
            if (empty($name)) continue;
            $pkg_name = trim($row[$map['package']] ?? 'Standar');
            $pkg_fee = (float)preg_replace('/[^0-9]/', '', $row[$map['fee']] ?? 0);
            
            $stmt->execute([
                $name, trim($row[$map['address']] ?? ''), trim($row[$map['contact']] ?? ''), $pkg_name, $pkg_fee,
                trim($row[$map['ip']] ?? ''), 'customer', 
                trim($row[$map['reg_date']] ?? date('Y-m-d')), (int)trim($row[$map['bill_date']] ?? 1), trim($row[$map['area']] ?? ''), $user_id
            ]);
            $count++;
            $imp_id = $db->lastInsertId();
            do {
                $imp_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                $stmt_chk->execute([$imp_code]);
            } while ($stmt_chk->fetchColumn() > 0);
            $db->prepare("UPDATE customers SET customer_code = ? WHERE id = ?")->execute([$imp_code, $imp_id]);
            
            // Initial Invoice
            if ($pkg_fee > 0) {
                $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)")
                   ->execute([$imp_id, $pkg_fee, trim($row[$map['reg_date']] ?? date('Y-m-d')), date('Y-m-d H:i:s')]);
            }
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: index.php?page=partner&msg=error&err=" . urlencode($e->getMessage()));
        exit;
    }
    unset($_SESSION['pending_import_partner']);
    unset($_SESSION['pending_mapping_partner']);
    header("Location: index.php?page=partner&msg=import_success&count=" . $count);
    exit;
}

if ($action === 'import_cancel') {
    unset($_SESSION['pending_import_partner']);
    header("Location: index.php?page=partner");
    exit;
}

if ($action === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="template_import_mitra.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Nama Pelanggan', 'Alamat', 'WhatsApp', 'Nama Paket', 'Biaya', 'IP Address', 'Tgl Daftar (YYYY-MM-DD)', 'Tgl Tagihan (1-28)', 'Area']);
    fputcsv($output, ['Contoh Pelanggan', 'Jl. Contoh 123', '08123456789', '10Mbps', '150000', '192.168.1.50', date('Y-m-d'), '10', 'Area A']);
    fclose($output);
    exit;
}

function detectImportMapping($row) {
    $map = ['type' => 0, 'name' => 0, 'address' => 1, 'contact' => 2, 'package' => 3, 'fee' => 4, 'ip' => 5, 'reg_date' => 6, 'bill_date' => 7, 'area' => 8];
    $found = false;
    foreach ($row as $idx => $val) {
        $val = strtolower(trim($val));
        if (empty($val)) continue;
        if (strpos($val, 'paket') !== false || strpos($val, 'package') !== false) { $map['package'] = $idx; $found = true; }
        elseif (strpos($val, 'biaya') !== false || strpos($val, 'fee') !== false || strpos($val, 'harga') !== false) { $map['fee'] = $idx; $found = true; }
        elseif (strpos($val, 'registrasi') !== false || strpos($val, 'daftar') !== false) { $map['reg_date'] = $idx; $found = true; }
        elseif (strpos($val, 'tagihan') !== false || strpos($val, 'billing') !== false || strpos($val, 'tempo') !== false) { $map['bill_date'] = $idx; $found = true; }
        elseif (strpos($val, 'area') !== false || strpos($val, 'wilayah') !== false) { $map['area'] = $idx; $found = true; }
        elseif (strpos($val, 'nama') !== false || strpos($val, 'name') !== false) { $map['name'] = $idx; $found = true; }
        elseif (strpos($val, 'alamat') !== false || strpos($val, 'address') !== false) { $map['address'] = $idx; $found = true; }
        elseif (strpos($val, 'kontak') !== false || strpos($val, 'wa') !== false || strpos($val, 'phone') !== false) { $map['contact'] = $idx; $found = true; }
        elseif (strpos($val, 'ip') !== false) { $map['ip'] = $idx; $found = true; }
    }
    return $found ? $map : null;
}

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

// NEW: Fetch all customers managed by this partner
$my_customers = $db->query("
    SELECT c.*, 
    (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND status = 'Belum Lunas') as unpaid_count,
    (SELECT SUM(amount - discount) FROM invoices WHERE customer_id = c.id AND status = 'Belum Lunas') as total_unpaid
    FROM customers c 
    WHERE c.created_by = $user_id 
    ORDER BY c.name ASC
")->fetchAll();

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


// Fetch Packages & Areas (Scoped for Partner)
$packages_all = $db->query("SELECT * FROM packages WHERE created_by = $user_id OR created_by = 0 OR created_by IS NULL ORDER BY name ASC")->fetchAll();
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
                <h2 style="margin:0; font-size:20px; font-weight:800; color:var(--text-primary);">Dashboard Mitra</h2>
                <p style="margin:0; font-size:12px; color:var(--text-secondary);"><?= $_SESSION['user_name'] ?> | ID: <?= $partner_cid ?: 'N/A' ?></p>
            </div>
            <div class="btn-group">
                <a href="index.php?page=partner&action=import_view" class="btn btn-sm btn-ghost" style="color:var(--success); border-radius:10px 0 0 10px; border:1px solid var(--glass-border); padding:0 15px;"><i class="fas fa-file-import"></i> <span class="hide-mobile">Import CSV</span></a>
                <button onclick="PartnerPage.showAddCustomerModal()" class="btn btn-sm btn-primary" style="height:38px; border-radius:0 10px 10px 0; font-weight:700;"><i class="fas fa-user-plus"></i> <span class="hide-mobile">Tambah Pelanggan</span></button>
            </div>
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
        <a href="index.php?page=invoice_print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" class="btn btn-ghost" style="flex:1; min-width:150px; padding:12px; font-weight:700; border-radius:10px; text-align:center; text-decoration:none; border:1px solid var(--glass-border);">
            <i class="fas fa-print"></i> Cetak Struk
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
    <div style="padding: 15px 20px; background: rgba(16, 185, 129, 0.1); border-left: 4px solid var(--success); margin-bottom: 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px; animation: slideDown 0.4s ease-out;">
        <i class="fas fa-check-circle" style="color: var(--success); font-size: 18px;"></i>
        <div style="font-weight: 700; color: var(--success); font-size: 14px;">
            <?php 
                if($_GET['msg'] == 'added') echo 'Pelanggan baru berhasil didaftarkan!';
                if($_GET['msg'] == 'import_success') echo 'Berhasil mengimpor ' . intval($_GET['count'] ?? 0) . ' data pelanggan ke akun Anda.';
            ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($action === 'import_view'): ?>
<div class="glass-panel" style="padding: 24px; max-width:800px; margin:0 auto 30px;">
    <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-file-import text-success"></i> Import Data Pelanggan</h3>
    <div style="background:var(--hover-bg); padding:15px; border-radius:12px; margin-bottom:25px; font-size:13px; line-height:1.6; border-left:4px solid var(--success);">
        <strong>Petunjuk Impor:</strong><br>
        1. Gunakan file format <strong>.csv</strong> atau Paste dari Excel.<br>
        2. Pastikan kolom Nama, Alamat, dan WhatsApp terisi.<br>
        3. <a href="index.php?page=partner&action=download_template" style="color:var(--primary); font-weight:700; text-decoration:none;"><i class="fas fa-download"></i> Download Template CSV</a>
    </div>

    <div class="import-tabs" style="display:flex; gap:10px; margin-bottom:20px;">
        <button class="tab-btn active" id="btn-file" onclick="switchImportTab('file')" style="flex:1; padding:12px; border-radius:10px; background:var(--nav-active-bg); border:none; color:var(--primary); font-weight:700; cursor:pointer;"><i class="fas fa-file-csv"></i> File CSV</button>
        <button class="tab-btn" id="btn-paste" onclick="switchImportTab('paste')" style="flex:1; padding:12px; border-radius:10px; background:transparent; border:1px solid var(--glass-border); color:var(--text-secondary); font-weight:700; cursor:pointer;"><i class="fas fa-paste"></i> Paste Excel</button>
    </div>

    <div id="import-file" class="import-section">
        <form action="index.php?page=partner&action=import_file" method="POST" enctype="multipart/form-data">
            <div style="border: 2px dashed var(--glass-border); padding: 40px; border-radius: 15px; text-align: center; background: rgba(255,255,255,0.02);">
                <i class="fas fa-cloud-upload-alt" style="font-size: 40px; color: var(--primary); opacity: 0.5; margin-bottom: 15px;"></i>
                <input type="file" name="csv_file" id="csv_input" accept=".csv" required style="display: none;" onchange="this.nextElementSibling.innerText = this.files[0].name">
                <button type="button" class="btn btn-primary" onclick="document.getElementById('csv_input').click()">Pilih File CSV</button>
                <div style="margin-top: 10px; font-size: 12px; color: var(--text-secondary);">Belum ada file terpilih</div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <a href="index.php?page=partner" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn btn-primary">Upload & Preview</button>
            </div>
        </form>
    </div>

    <div id="import-paste" class="import-section" style="display:none;">
        <form action="index.php?page=partner&action=import_paste" method="POST">
            <div class="form-group">
                <textarea name="paste_data" class="form-control" rows="8" placeholder="Nama [Tab] Alamat [Tab] WhatsApp..." required style="font-family:monospace; font-size:12px; background:rgba(0,0,0,0.2);"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <a href="index.php?page=partner" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn btn-primary">Proses Data</button>
            </div>
        </form>
    </div>
</div>
<script>
function switchImportTab(t){
    document.querySelectorAll('.import-section').forEach(s => s.style.display='none');
    document.getElementById('import-'+t).style.display='block';
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.style.background='transparent'; b.style.color='var(--text-secondary)'; b.style.border='1px solid var(--glass-border)';
    });
    const active = document.getElementById('btn-'+t);
    active.style.background='var(--nav-active-bg)'; active.style.color='var(--primary)'; active.style.border='none';
}
</script>

<?php elseif ($action === 'import_preview'): 
    $pending = $_SESSION['pending_import_partner'] ?? [];
    $map = $_SESSION['pending_mapping_partner'] ?? ['type' => 0, 'name' => 0, 'address' => 1, 'contact' => 2, 'package' => 3, 'fee' => 4, 'ip' => 5, 'reg_date' => 6, 'bill_date' => 7, 'area' => 8];
?>
<div class="glass-panel" style="padding: 24px; max-width:1000px; margin:0 auto 30px;">
    <h3 style="margin-bottom:20px;"><i class="fas fa-eye text-primary"></i> Preview Data (<?= count($pending) ?> Pelanggan)</h3>
    <div class="table-container" style="max-height: 400px; overflow-y:auto; border:1px solid var(--glass-border); border-radius:12px; margin-bottom:20px;">
        <table style="width:100%; font-size:12px;">
            <thead>
                <tr>
                    <th>Nama</th><th>Alamat</th><th>WhatsApp</th><th>Paket</th><th>Biaya</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pending as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row[$map['name']] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row[$map['address']] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row[$map['contact']] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row[$map['package']] ?? '-') ?></td>
                    <td>Rp<?= number_format(floatval(preg_replace('/[^0-9]/', '', $row[$map['fee']] ?? 0)), 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="display:flex; justify-content:flex-end; gap:10px;">
        <a href="index.php?page=partner&action=import_cancel" class="btn btn-ghost">Batalkan</a>
        <form action="index.php?page=partner&action=import_confirm" method="POST">
            <button type="submit" class="btn btn-primary" style="background:var(--success); border-color:var(--success);"><i class="fas fa-check"></i> Konfirmasi & Impor</button>
        </form>
    </div>
</div>

<?php else: // ACTION: list (Default) ?>
    <div class="scroll-container">
        <!-- Dashboard Home Contents -->
        
        <div style="display:grid; grid-template-columns: 1fr; gap:20px;">
            <!-- LEFT/TOP: Stats and Recent Revenue -->
            <div>
                <!-- REVENUE OVERVIEW -->
                <div class="glass-panel" style="padding: 20px; border-bottom:4px solid var(--success); background:linear-gradient(135deg, rgba(16, 185, 129, 0.05), transparent);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="font-size:15px; font-weight:800; margin:0;"><i class="fas fa-wallet text-success"></i> Pendapatan Saya (Bulan Ini)</h3>
                        <span style="font-size:11px; font-weight:700; color:var(--text-secondary);"><?= date('F Y') ?></span>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <div>
                            <div style="font-size:10px; color:var(--text-secondary); font-weight:800; text-transform:uppercase;">Diterima</div>
                            <div style="font-size:18px; font-weight:900; color:var(--success);">Rp<?= number_format($total_paid_val, 0, ',', '.') ?></div>
                        </div>
                        <div>
                            <div style="font-size:10px; color:var(--text-secondary); font-weight:800; text-transform:uppercase;">Estimasi Bersih</div>
                            <div style="font-size:18px; font-weight:900; color:var(--primary);">Rp<?= number_format($my_net_profit, 0, ',', '.') ?></div>
                        </div>
                    </div>
                </div>

                <!-- QUICK STATS -->
                <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; margin-top:15px;">
                    <div class="glass-panel" style="padding:15px; border-radius:16px;">
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Total Pelanggan</div>
                        <div style="font-size:20px; font-weight:900;"><?= number_format($my_cust_count) ?></div>
                    </div>
                    <div class="glass-panel" style="padding:15px; border-radius:16px;">
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Jatuh Tempo Hari Ini</div>
                        <div style="font-size:20px; font-weight:900;"><?= $due_today ?></div>
                    </div>
                </div>
            </div>

            <!-- MAIN LISTS -->
            <div>
                <!-- 1. MY CUSTOMERS LIST -->
                <div class="glass-panel" style="padding:20px; margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 style="margin:0; font-size:16px; font-weight:800;"><i class="fas fa-users text-primary"></i> Daftar Pelanggan Saya</h3>
                        <span class="badge" style="background:var(--primary); color:white;"><?= count($my_customers) ?></span>
                    </div>
                    
                    <div class="table-container">
                        <table style="width:100%; font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Pelanggan</th>
                                    <th>Layanan</th>
                                    <th class="hide-mobile">Kontak</th>
                                    <th>Tunggakan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($my_customers)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding:30px; color:var(--text-secondary);">Belum ada pelanggan. Klik "Tambah Pelanggan" untuk memulai.</td></tr>
                                <?php endif; ?>
                                <?php foreach($my_customers as $cust): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:700;"><?= htmlspecialchars($cust['name']) ?></div>
                                            <div style="font-size:11px; color:var(--text-secondary);"><?= $cust['customer_code'] ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;"><?= htmlspecialchars($cust['package_name']) ?></div>
                                            <div style="font-size:11px; color:var(--text-secondary);">Rp<?= number_format($cust['monthly_fee'],0,',','.') ?></div>
                                        </td>
                                        <td class="hide-mobile">
                                            <div style="font-size:12px;"><?= $cust['contact'] ?></div>
                                            <div style="font-size:10px; color:var(--text-secondary);"><?= htmlspecialchars($cust['area']) ?></div>
                                        </td>
                                        <td>
                                            <?php if($cust['unpaid_count'] > 0): ?>
                                                <span style="color:var(--danger); font-weight:800;">Rp<?= number_format($cust['total_unpaid'], 0, ',', '.') ?></span>
                                                <div style="font-size:9px; color:var(--danger);"><?= $cust['unpaid_count'] ?> Bulan</div>
                                            <?php else: ?>
                                                <span style="color:var(--success); font-weight:800;">LUNAS</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex; gap:5px;">
                                                <?php if($cust['unpaid_count'] > 0): ?>
                                                    <button onclick="PartnerPage.quickPay(<?= $cust['id'] ?>, '<?= addslashes($cust['name']) ?>', <?= $cust['unpaid_count'] ?>, <?= $cust['total_unpaid'] ?>)" class="btn btn-sm" style="background:var(--success); color:white; padding:5px 8px;" title="Bayar Kilat"><i class="fas fa-check"></i></button>
                                                <?php endif; ?>
                                                <a href="https://api.whatsapp.com/send?phone=<?= preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $cust['contact'])) ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white; padding:5px 8px;"><i class="fab fa-whatsapp"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. MY BILLS TO ISP -->
                <?php if(!empty($partner_invoices)): ?>
                <div class="glass-panel" style="padding:20px; border-left:4px solid var(--danger);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h3 style="margin:0; font-size:16px; font-weight:800; color:var(--danger);"><i class="fas fa-file-invoice-dollar"></i> Tagihan Saya ke ISP</h3>
                        <span class="badge" style="background:var(--danger); color:white; font-size:10px;">PRIBADI</span>
                    </div>
                    <div class="table-container">
                        <table style="width:100%; font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Nominal</th><th>Jatuh Tempo</th><th>Status</th><th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($partner_invoices as $inv): ?>
                                    <tr>
                                        <td style="font-weight:700;">Rp<?= number_format($inv['amount'],0,',','.') ?></td>
                                        <td><?= date('d/m/Y', strtotime($inv['due_date'])) ?></td>
                                        <td>
                                            <span style="padding:3px 8px; border-radius:20px; font-size:10px; font-weight:800; background:<?= ($inv['status'] === 'Lunas' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)') ?>; color:<?= ($inv['status'] === 'Lunas' ? 'var(--success)' : 'var(--danger)') ?>;">
                                                <?= strtoupper($inv['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="index.php?page=invoice_print&id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" style="padding:5px 8px;"><i class="fas fa-print"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- PERSISTENT BOTTOM SUMMARY BAR -->
        <div class="static-summary-bar">
            <div class="glass-panel" style="padding:15px 20px; border-left:4px solid var(--success); background:linear-gradient(to right, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:18px; box-shadow:0 -10px 25px rgba(0,0,0,0.1);">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; border-radius:12px; background:var(--success); color:white; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 4px 10px rgba(16, 185, 129, 0.3); flex-shrink:0;">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div>
                        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:800;">Laba Bersih SAYA</div>
                        <div style="font-size:18px; font-weight:900; color:var(--text-primary); line-height:1.2;">Rp<?= number_format($my_net_profit, 0, ',', '.') ?></div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:10px; color:var(--text-secondary); font-weight:800; text-transform:uppercase;">Piutang Aktif</div>
                    <div style="font-size:18px; font-weight:900; color:var(--danger); line-height:1.2;">Rp<?= number_format($total_unpaid_val, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div> <!-- end .tab-flex-container -->

<!-- Hidden Form for Quick Pay -->
<form id="quickPayForm" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
    <input type="hidden" name="customer_id" id="qp_cust_id">
    <input type="hidden" name="num_months" id="qp_num_months">
</form>

<script>
window.PartnerPage = (function(){
    function quickPay(custId, name, months, total){
        const formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
        if (confirm(`Konfirmasi pembayaran dari ${name}?\n\nTotal: ${formattedTotal} (${months} Bulan)\n\nTindakan ini akan menandai tagihan sebagai LUNAS.`)) {
            const elId = document.getElementById('qp_cust_id'); if(elId) elId.value = custId;
            const elNum = document.getElementById('qp_num_months'); if(elNum) elNum.value = months;
            const form = document.getElementById('quickPayForm'); if(form) form.submit();
        }
    }
    function showAddCustomerModal(){ const m = document.getElementById('addCustomerModal'); if(m) m.style.display = 'flex'; }
    function syncAddPrice(select){ const fee = select.options[select.selectedIndex].getAttribute('data-fee'); if(fee){ const el = document.getElementById('add_monthly_fee'); if(el) el.value = fee; } }
    return { quickPay, showAddCustomerModal, syncAddPrice };
})();
</script>

<!-- Modal Tambah Pelanggan Baru -->
<div id="addCustomerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter: blur(10px); padding:15px;">
    <div class="glass-panel" style="width:100%; max-width:550px; padding:0; border-radius:24px; border:1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px rgba(0,0,0,0.4); overflow:hidden;">
        <!-- Header -->
        <div style="padding:24px; background:linear-gradient(to right, var(--primary), #1e293b); color:white; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:45px; height:45px; border-radius:14px; background:rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center; font-size:20px;">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:18px; font-weight:800;">Pelanggan Baru</h3>
                    <p style="margin:2px 0 0; font-size:12px; opacity:0.8;">Pendaftaran mitra di lapangan</p>
                </div>
            </div>
            <button onclick="document.getElementById('addCustomerModal').style.display='none'" style="background:none; border:none; color:white; cursor:pointer; font-size:24px; padding:10px; opacity:0.7;">&times;</button>
        </div>
        
        <form action="index.php?page=partner&action=add_customer" method="POST" style="padding:24px; max-height:70vh; overflow-y:auto;" onsubmit="return confirm('Daftarkan pelanggan baru ini?')">
            <!-- Section 1: Data Diri -->
            <div style="margin-bottom:24px;">
                <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--primary); margin-bottom:16px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-id-card"></i> Identitas Pelanggan
                </div>
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Nama Lengkap / Instansi</label>
                    <input type="text" name="name" class="form-control" placeholder="Masukan nama pelanggan" required style="padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
                </div>
                <div class="form-group">
                    <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">No. WhatsApp (Aktif)</label>
                    <input type="text" name="contact" class="form-control" placeholder="08xxxx" required style="padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
                </div>
            </div>

            <!-- Section 2: Layanan -->
            <div style="margin-bottom:24px;">
                <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:var(--primary); margin-bottom:16px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-wifi"></i> Paket & Lokasi
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px;">
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Paket Internet</label>
                        <select name="package_name" class="form-control" onchange="syncAddPrice(this)" required style="padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
                            <option value="">-- Pilih Paket --</option>
                            <?php foreach($packages_all as $pkg): ?>
                                <option value="<?= htmlspecialchars($pkg['name']) ?>" data-fee="<?= $pkg['fee'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rp<?= number_format($pkg['fee'],0,',','.') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Biaya Bulanan (Rp)</label>
                        <input type="number" name="monthly_fee" id="add_monthly_fee" class="form-control" required style="padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%; font-weight:700;">
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Tanggal Tagih</label>
                        <select name="billing_date" class="form-control" style="padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
                            <?php for($d=1;$d<=28;$d++): ?>
                                <option value="<?= $d ?>">Tanggal <?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Tanggal Registrasi</label>
                        <input type="date" name="registration_date" value="<?= date('Y-m-d') ?>" class="form-control" required style="padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Alamat Lengkap</label>
                        <textarea name="address" class="form-control" rows="3" placeholder="Jl. Contoh Nomor 1..." style="padding:12px 16px; border-radius:12px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;"></textarea>
                    </div>
                </div>
            </div>

            <!-- Footer: Actions -->
            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:10px; padding-top:24px; border-top:1px solid var(--glass-border);">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addCustomerModal').style.display='none'" style="padding:12px 24px; border-radius:12px; font-weight:600; font-size:14px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:12px 30px; border-radius:12px; font-weight:800; font-size:14px; box-shadow: 0 4px 15px rgba(var(--primary-rgb), 0.3);">
                    <i class="fas fa-save" style="margin-right:8px;"></i> Simpan Pelanggan
                </button>
            </div>
        </form>
    </div>
</div>
