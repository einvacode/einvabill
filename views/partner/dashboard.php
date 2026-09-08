<?php
// Partner view
$user_id = intval($_SESSION['user_id']);
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$action = $_GET['action'] ?? 'list';
$stmt_u = $db->prepare("SELECT customer_id FROM users WHERE id = ? AND tenant_id = ?");
$stmt_u->execute([$user_id, $tenant_id]);
$u = $stmt_u->fetch();
$partner_cid = $u['customer_id'] ?? 0;

$company_wa = $db->query("SELECT company_contact FROM settings WHERE tenant_id = $tenant_id")->fetchColumn();
if (!$company_wa) {
    $company_wa = $db->query("SELECT company_contact FROM settings WHERE id=1")->fetchColumn();
}

// Date filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'belum';
$sort_date = $_GET['sort_date'] ?? 'desc';

// Fetch current user brand/settings
$me = $db->query("SELECT * FROM users WHERE id = $user_id AND tenant_id = $tenant_id")->fetch();
$settings = $db->query("SELECT company_name, wa_template, wa_template_paid, site_url, bank_account FROM settings WHERE tenant_id = $tenant_id")->fetch();
if (!$settings) {
    $settings = $db->query("SELECT company_name, wa_template, wa_template_paid, site_url, bank_account FROM settings WHERE id = 1")->fetch();
}

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
    $ppn_active = isset($_POST['ppn_active']) ? 1 : 0;
    $bhp_active = isset($_POST['bhp_active']) ? 1 : 0;
    $uso_active = isset($_POST['uso_active']) ? 1 : 0;
    
    // Auto-generate unique random customer code
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ? AND tenant_id = ?");
    do {
        $customer_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt_check->execute([$customer_code, $tenant_id]);
    } while ($stmt_check->fetchColumn() > 0);
    
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, type, registration_date, billing_date, area, created_by, tenant_id, ppn_active, bhp_active, uso_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_code, $name, $address, $contact, $package_name, $monthly_fee, $type, $registration_date, $billing_date, $area, $user_id, $tenant_id, $ppn_active, $bhp_active, $uso_active]);
        $new_id = $db->lastInsertId();
        $invoice_total = compute_customer_invoice_total_from_amount($monthly_fee, $ppn_active, $bhp_active, $uso_active)['total'];

        // Initial Invoice for current month
        if ($monthly_fee > 0) {
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', ?, ?)");
            $stmt_inv->execute([$new_id, $invoice_total, $registration_date, date('Y-m-d H:i:s'), $tenant_id]);
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

// Handle Edit Customer by partner
if (isset($_GET['action']) && $_GET['action'] === 'edit_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $name = $_POST['name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    $package_name = $_POST['package_name'];
    $monthly_fee = floatval($_POST['monthly_fee']);
    $billing_date = intval($_POST['billing_date'] ?: 1);
    $area = $_POST['area'] ?? '';
    $ppn_active = isset($_POST['ppn_active']) ? 1 : 0;
    $bhp_active = isset($_POST['bhp_active']) ? 1 : 0;
    $uso_active = isset($_POST['uso_active']) ? 1 : 0;
    
    // Ownership Check: Only allow editing own customers
    $check = $db->query("SELECT id FROM customers WHERE id = $id AND created_by = $user_id AND tenant_id = $tenant_id")->fetchColumn();
    if (!$check) {
        header("Location: index.php?page=partner&msg=forbidden");
        exit;
    }
    
    $stmt = $db->prepare("UPDATE customers SET name=?, address=?, contact=?, package_name=?, monthly_fee=?, billing_date=?, area=?, ppn_active=?, bhp_active=?, uso_active=? WHERE id=? AND created_by=? AND tenant_id=?");
    $stmt->execute([$name, $address, $contact, $package_name, $monthly_fee, $billing_date, $area, $ppn_active, $bhp_active, $uso_active, $id, $user_id, $tenant_id]);
    $invoice_total = compute_customer_invoice_total_from_amount($monthly_fee, $ppn_active, $bhp_active, $uso_active)['total'];
    
    // Sync existing unpaid invoices with the new total amount, where the entered monthly fee already includes active taxes
    $db->prepare("UPDATE invoices SET amount = ? WHERE customer_id = ? AND status = 'Belum Lunas' AND tenant_id = ?")->execute([$invoice_total, $id, $tenant_id]);
    
    header("Location: index.php?page=partner&msg=updated&t=" . time());
    exit;
}

// Handle Delete Customer by partner
if (isset($_GET['action']) && $_GET['action'] === 'delete_customer' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Ownership Check: Only allow deleting own customers
    $check = $db->query("SELECT id FROM customers WHERE id = $id AND created_by = $user_id AND tenant_id = $tenant_id")->fetchColumn();
    if (!$check) {
        header("Location: index.php?page=partner&msg=forbidden");
        exit;
    }
    
    // Cascade Delete: Payments -> Invoice Items -> Invoices -> Customer
    $db->prepare("DELETE FROM payments WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id = ? AND tenant_id = ?) AND tenant_id = ?")->execute([$id, $tenant_id, $tenant_id]);
    $db->prepare("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id = ? AND tenant_id = ?)")->execute([$id, $tenant_id]);
    $db->prepare("DELETE FROM invoices WHERE customer_id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
    $db->prepare("DELETE FROM customers WHERE id = ? AND created_by = ? AND tenant_id = ?")->execute([$id, $user_id, $tenant_id]);
    
    header("Location: index.php?page=partner&msg=deleted&t=" . time());
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

if ($action === 'export_csv') {
    $customers_export = $db->query("SELECT * FROM customers WHERE created_by = $user_id ORDER BY name ASC")->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Data_Pelanggan_Mitra_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
    
    fputcsv($output, ['Kode', 'Nama', 'Alamat', 'Kontak', 'Paket', 'Biaya Bulanan', 'IP Address', 'Tgl Daftar', 'Tgl Tagihan', 'Area', 'PPN', 'BHP', 'USO']);
    
    foreach ($customers_export as $row) {
        fputcsv($output, [
            $row['customer_code'],
            $row['name'],
            $row['address'],
            $row['contact'],
            $row['package_name'],
            $row['monthly_fee'],
            $row['ip_address'],
            $row['registration_date'],
            $row['billing_date'],
            $row['area'],
            !empty($row['ppn_active']) ? 'Aktif' : 'Nonaktif',
            !empty($row['bhp_active']) ? 'Aktif' : 'Nonaktif',
            !empty($row['uso_active']) ? 'Aktif' : 'Nonaktif'
        ]);
    }
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
    LIMIT 3
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
$p_stg = $db->query("SELECT wa_template, wa_template_paid, brand_bank, brand_rekening FROM users WHERE id = $u_id")->fetch();

$wa_tpl_unpaid = (!empty($p_stg['wa_template'])) ? $p_stg['wa_template'] : ($settings['wa_template'] ?? "Halo {nama}, tagihan {tagihan} jatuh tempo pada {jatuh_tempo}.");
$wa_tpl_paid = (!empty($p_stg['wa_template_paid'])) ? $p_stg['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah LUNAS.");
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
        
        $receipt_msg = parse_wa_template($wa_tpl_paid, [
            'name' => $success_data['name'],
            'id_cust' => ($success_data['customer_code'] ?: $success_data['id']),
            'package' => ($success_data['package_name'] ?: '-'),
            'period' => $months_paid . ' Bulan',
            'tagihan' => $success_data['monthly_fee'],
            'total_paid' => $total_paid,
            'tunggakan' => $tunggakan_val,
            'sisa_tunggakan' => $tunggakan_val,
            'payment_time' => date('d/m/Y H:i') . ' WIB',
            'admin_name' => $_SESSION['user_name'],
            'portal_link' => $portal_link,
            'rekening' => $rekening_receipt,
            'payment_status' => $status_wa
        ]);
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}


// Fetch Packages & Areas (Scoped for Partner)
$packages_all = $db->query("SELECT * FROM packages WHERE created_by = $user_id OR created_by = 0 OR created_by IS NULL ORDER BY name ASC")->fetchAll();
?>

<?php if($success_data): ?>
<div class="ui-card mb-5 p-4 sm:p-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h3 class="m-0 text-[15px] font-bold text-signal">Pembayaran berhasil</h3>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Tagihan untuk <strong class="text-foreground"><?= htmlspecialchars($success_data['name']) ?></strong> telah diperbarui.</p>
        </div>
        <button type="button" onclick="this.parentElement.parentElement.style.display='none'" class="grid h-8 w-8 shrink-0 place-items-center rounded-md border-0 bg-transparent text-muted-foreground hover:bg-muted cursor-pointer" aria-label="Tutup"><i class="fas fa-times"></i></button>
    </div>
    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
        <a href="<?= $success_data['wa_link'] ?>" target="_blank" class="ui-btn ui-btn-wa w-full sm:w-auto"><i class="fab fa-whatsapp"></i> Kirim notifikasi WA</a>
        <a href="index.php?page=invoice_print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" class="ui-btn ui-btn-outline w-full sm:w-auto"><i class="fas fa-print"></i> Cetak kuitansi</a>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
    <div class="ui-card mb-5 p-4 text-sm <?= $_GET['msg'] == 'forbidden' ? 'border-danger/40' : '' ?>">
        <?php
            if($_GET['msg'] == 'added') echo '<span class="font-semibold text-signal">Berhasil.</span> Pelanggan baru berhasil didaftarkan.';
            if($_GET['msg'] == 'updated') echo '<span class="font-semibold text-signal">Tersimpan.</span> Data pelanggan berhasil diperbarui.';
            if($_GET['msg'] == 'deleted') echo '<span class="font-semibold text-signal">Terhapus.</span> Pelanggan berhasil dihapus beserta seluruh tagihan dan pembayarannya.';
            if($_GET['msg'] == 'import_success') echo '<span class="font-semibold text-signal">Berhasil.</span> Berhasil mengimpor ' . intval($_GET['count'] ?? 0) . ' data pelanggan ke akun Anda.';
            if($_GET['msg'] == 'forbidden') echo '<span class="font-semibold text-danger">Akses ditolak.</span> Anda hanya bisa mengelola pelanggan milik Anda sendiri.';
        ?>
    </div>
<?php endif; ?>

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div class="flex items-center gap-3">
        <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-solid border-border bg-card text-foreground cursor-pointer lg:hidden" onclick="toggleSidebar()" aria-label="Buka menu"><i class="fas fa-bars"></i></button>
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Dashboard mitra</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground"><?= $_SESSION['user_name'] ?> · ID <?= $partner_cid ?: 'N/A' ?></p>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="index.php?page=partner&action=import_view" class="ui-btn ui-btn-outline"><i class="fas fa-file-import"></i> <span class="hidden sm:inline">Impor</span></a>
        <a href="index.php?page=partner&action=export_csv" class="ui-btn ui-btn-outline"><i class="fas fa-file-export"></i> <span class="hidden sm:inline">Ekspor</span></a>
        <button type="button" onclick="PartnerPage.showAddCustomerModal()" class="ui-btn ui-btn-primary"><i class="fas fa-user-plus"></i> <span class="hidden sm:inline">Tambah pelanggan</span></button>
    </div>
</div>

<?php if ($action === 'import_view'): ?>
<div class="ui-card mx-auto mb-6 max-w-3xl p-4 sm:p-6">
    <h3 class="m-0 mb-4 text-lg font-bold">Impor data pelanggan</h3>
    <div class="mb-5 rounded-md bg-muted p-4 text-sm leading-relaxed">
        <strong>Petunjuk impor:</strong><br>
        1. Gunakan file format <strong>.csv</strong> atau tempel dari Excel.<br>
        2. Pastikan kolom Nama, Alamat, dan WhatsApp terisi.<br>
        3. <a href="index.php?page=partner&action=download_template" class="font-semibold text-primary no-underline"><i class="fas fa-download"></i> Unduh template CSV</a>
    </div>

    <div class="import-tabs mb-5 flex gap-2">
        <button type="button" class="tab-btn active flex-1 rounded-md px-4 py-2.5 text-sm font-semibold cursor-pointer" id="btn-file" onclick="switchImportTab('file')" style="background:var(--nav-active-bg); border:none; color:var(--primary);"><i class="fas fa-file-csv"></i> File CSV</button>
        <button type="button" class="tab-btn flex-1 rounded-md px-4 py-2.5 text-sm font-semibold cursor-pointer" id="btn-paste" onclick="switchImportTab('paste')" style="background:transparent; border:1px solid var(--glass-border); color:var(--text-secondary);"><i class="fas fa-paste"></i> Tempel dari Excel</button>
    </div>

    <div id="import-file" class="import-section">
        <form action="index.php?page=partner&action=import_file" method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>
            <div class="rounded-md border border-dashed border-border p-8 text-center">
                <input type="file" name="csv_file" id="csv_input" accept=".csv" required style="display: none;" onchange="this.nextElementSibling.innerText = this.files[0].name">
                <button type="button" class="ui-btn ui-btn-primary" onclick="document.getElementById('csv_input').click()">Pilih file CSV</button>
                <div class="mt-3 text-xs text-muted-foreground">Belum ada file terpilih</div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <a href="index.php?page=partner" class="ui-btn ui-btn-outline">Batal</a>
                <button type="submit" class="ui-btn ui-btn-primary">Unggah & pratinjau</button>
            </div>
        </form>
    </div>

    <div id="import-paste" class="import-section" style="display:none;">
        <form action="index.php?page=partner&action=import_paste" method="POST">
<?= csrf_field() ?>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Data dari Excel</span>
                <textarea name="paste_data" class="form-control w-full font-mono text-xs" rows="8" placeholder="Nama [Tab] Alamat [Tab] WhatsApp..." required></textarea>
            </label>
            <div class="mt-5 flex justify-end gap-2">
                <a href="index.php?page=partner" class="ui-btn ui-btn-outline">Batal</a>
                <button type="submit" class="ui-btn ui-btn-primary">Proses data</button>
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
<div class="ui-card mx-auto mb-6 max-w-5xl overflow-hidden">
    <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
        <h3 class="m-0 text-[15px] font-bold">Pratinjau data</h3>
        <p class="m-0 text-xs text-muted-foreground"><?= count($pending) ?> pelanggan siap diimpor</p>
    </div>
    <div class="max-h-[400px] overflow-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold">Nama</th><th class="px-4 py-2.5 font-semibold">Alamat</th><th class="px-4 py-2.5 font-semibold">WhatsApp</th><th class="px-4 py-2.5 font-semibold">Paket</th><th class="px-4 py-2.5 font-semibold">Biaya</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pending as $row): ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-2.5"><?= htmlspecialchars($row[$map['name']] ?? '-') ?></td>
                    <td class="px-4 py-2.5"><?= htmlspecialchars($row[$map['address']] ?? '-') ?></td>
                    <td class="px-4 py-2.5"><?= htmlspecialchars($row[$map['contact']] ?? '-') ?></td>
                    <td class="px-4 py-2.5"><?= htmlspecialchars($row[$map['package']] ?? '-') ?></td>
                    <td class="px-4 py-2.5 tabular-nums whitespace-nowrap">Rp <?= number_format(floatval(preg_replace('/[^0-9]/', '', $row[$map['fee']] ?? 0)), 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="flex justify-end gap-2 border-t border-solid border-border px-4 py-3 sm:px-5">
        <a href="index.php?page=partner&action=import_cancel" class="ui-btn ui-btn-outline">Batalkan</a>
        <form action="index.php?page=partner&action=import_confirm" method="POST">
<?= csrf_field() ?>
            <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i> Konfirmasi & impor</button>
        </form>
    </div>
</div>

<?php else: // ACTION: list (Default) ?>

    <!-- Source navigation -->
    <div class="mb-5 flex flex-wrap gap-1 rounded-md bg-muted p-1 w-fit">
        <a href="index.php?page=partner" class="rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground no-underline shadow-card" aria-current="page">Data pelanggan</a>
        <a href="index.php?page=partner_isp_invoices" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Data tagihan</a>
        <a href="index.php?page=partner_reports" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Data laporan</a>
    </div>

    <!-- Summary tiles -->
    <div class="mb-6 grid grid-cols-2 gap-3 xl:grid-cols-4">
        <a href="index.php?page=partner" class="ui-card flex flex-col gap-1 p-4 no-underline text-foreground transition-colors hover:border-primary/40">
            <div class="text-xs font-medium text-muted-foreground">Total pelanggan</div>
            <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl"><?= number_format($my_cust_count) ?></div>
            <div class="text-xs text-muted-foreground">Pelanggan aktif yang dikelola</div>
            <div class="mt-auto text-xs font-semibold text-primary">Buka sumber data</div>
        </a>
        <a href="index.php?page=partner_reports" class="ui-card flex flex-col gap-1 p-4 no-underline text-foreground transition-colors hover:border-primary/40">
            <div class="text-xs font-medium text-muted-foreground">Pendapatan bulan ini</div>
            <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums text-signal sm:text-2xl">Rp <?= number_format($total_paid_val, 0, ',', '.') ?></div>
            <div class="text-xs text-muted-foreground"><?= date('F Y') ?></div>
            <div class="mt-auto text-xs font-semibold text-primary">Buka sumber data</div>
        </a>
        <a href="index.php?page=partner_isp_invoices&filter_status=belum" class="ui-card flex flex-col gap-1 p-4 no-underline text-foreground transition-colors hover:border-primary/40">
            <div class="text-xs font-medium text-muted-foreground">Total piutang</div>
            <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums text-danger sm:text-2xl">Rp <?= number_format($total_unpaid_val, 0, ',', '.') ?></div>
            <div class="text-xs text-muted-foreground">Jatuh tempo hari ini: <?= number_format($due_today) ?></div>
            <div class="mt-auto text-xs font-semibold text-primary">Buka sumber data</div>
        </a>
        <a href="index.php?page=partner_reports" class="ui-card flex flex-col gap-1 p-4 no-underline text-foreground transition-colors hover:border-primary/40">
            <div class="text-xs font-medium text-muted-foreground">Laba bersih</div>
            <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl">Rp <?= number_format($my_net_profit, 0, ',', '.') ?></div>
            <div class="text-xs text-muted-foreground">Koleksi dikurangi tagihan ISP</div>
            <div class="mt-auto text-xs font-semibold text-primary">Buka sumber data</div>
        </a>
    </div>

    <!-- Data sources -->
    <div class="mb-5 flex flex-wrap gap-2">
        <a href="index.php?page=partner" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-users"></i> Sumber pelanggan</a>
        <a href="index.php?page=partner_isp_invoices" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-file-invoice"></i> Sumber tagihan</a>
        <a href="index.php?page=partner_reports" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-chart-line"></i> Sumber laporan</a>
    </div>

    <div class="grid gap-5">
        <!-- 0. TRANSAKSI TERBARU -->
        <?php if(!empty($recent_revenue)): ?>
        <section class="ui-card overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                <div>
                    <h3 class="m-0 text-[15px] font-bold">Transaksi pelanggan terbaru</h3>
                    <p class="m-0 text-xs text-muted-foreground">Tiga pembayaran terakhir yang tercatat</p>
                </div>
                <a href="index.php?page=partner_reports" class="ui-btn ui-btn-sm ui-btn-outline">Lihat laporan</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold sm:px-5">Pelanggan</th>
                            <th class="px-3 py-2.5 font-semibold">Tanggal</th>
                            <th class="px-3 py-2.5 text-right font-semibold">Total</th>
                            <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_revenue as $tr): ?>
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 font-semibold sm:px-5"><?= htmlspecialchars($tr['customer_name']) ?></td>
                                <td class="px-3 py-3 tabular-nums text-muted-foreground whitespace-nowrap"><?= date('d/m/y H:i', strtotime($tr['payment_date'])) ?></td>
                                <td class="px-3 py-3 text-right font-bold tabular-nums text-signal whitespace-nowrap">Rp <?= number_format($tr['paid_amount'], 0, ',', '.') ?></td>
                                <td class="px-4 py-3 text-right sm:px-5">
                                    <a href="index.php?page=invoice_print&id=<?= $tr['id'] ?>&format=thermal" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak kuitansi"><i class="fas fa-print"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- 1. MY CUSTOMERS LIST -->
        <section class="ui-card overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                <div>
                    <h3 class="m-0 text-[15px] font-bold">Daftar pelanggan saya</h3>
                    <p class="m-0 text-xs text-muted-foreground">Pelanggan yang Anda kelola beserta tunggakannya</p>
                </div>
                <span class="ui-badge ui-badge-muted"><?= count($my_customers) ?> pelanggan</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold sm:px-5">Pelanggan</th>
                            <th class="px-3 py-2.5 font-semibold">Layanan</th>
                            <th class="hidden px-3 py-2.5 font-semibold md:table-cell">Kontak</th>
                            <th class="px-3 py-2.5 font-semibold">Tunggakan</th>
                            <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($my_customers)): ?>
                            <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada pelanggan. Klik "Tambah pelanggan" untuk memulai.</td></tr>
                        <?php endif; ?>
                        <?php foreach($my_customers as $cust): ?>
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 sm:px-5">
                                    <div class="font-semibold"><?= htmlspecialchars($cust['name']) ?></div>
                                    <div class="text-xs text-muted-foreground"><?= $cust['customer_code'] ?></div>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-medium"><?= htmlspecialchars($cust['package_name']) ?></div>
                                    <div class="text-xs text-muted-foreground tabular-nums">Rp <?= number_format($cust['monthly_fee'],0,',','.') ?></div>
                                </td>
                                <td class="hidden px-3 py-3 md:table-cell">
                                    <div class="text-xs"><?= $cust['contact'] ?></div>
                                    <div class="text-xs text-muted-foreground"><?= htmlspecialchars($cust['area']) ?></div>
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap">
                                    <?php if($cust['unpaid_count'] > 0): ?>
                                        <div class="font-bold tabular-nums text-danger">Rp <?= number_format($cust['total_unpaid'], 0, ',', '.') ?></div>
                                        <div class="text-xs text-danger"><?= $cust['unpaid_count'] ?> bulan</div>
                                    <?php else: ?>
                                        <span class="ui-badge ui-badge-signal">Lunas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 sm:px-5">
                                    <div class="flex flex-wrap justify-end gap-1.5">
                                        <?php if($cust['unpaid_count'] > 0): ?>
                                            <button type="button" onclick="PartnerPage.quickPay(<?= $cust['id'] ?>, '<?= addslashes($cust['name']) ?>', <?= $cust['unpaid_count'] ?>, <?= $cust['total_unpaid'] ?>)" class="ui-btn ui-btn-sm ui-btn-primary" title="Bayar kilat"><i class="fas fa-check"></i></button>
                                        <?php endif; ?>
                                        <button type="button" onclick='PartnerPage.editCustomer(<?= json_encode([
                                            "id" => $cust["id"],
                                            "name" => $cust["name"],
                                            "address" => $cust["address"],
                                            "contact" => $cust["contact"],
                                            "package_name" => $cust["package_name"],
                                            "monthly_fee" => $cust["monthly_fee"],
                                            "billing_date" => $cust["billing_date"],
                                            "area" => $cust["area"],
                                            "ppn_active" => $cust["ppn_active"] ?? 0,
                                            "bhp_active" => $cust["bhp_active"] ?? 0,
                                            "uso_active" => $cust["uso_active"] ?? 0
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="ui-btn ui-btn-sm ui-btn-outline" title="Edit pelanggan"><i class="fas fa-pen"></i></button>
                                        <button type="button" onclick="PartnerPage.deleteCustomer(<?= $cust['id'] ?>, '<?= addslashes($cust['name']) ?>')" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus pelanggan"><i class="fas fa-trash"></i></button>
                                        <?php
                                            $dash_wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $cust['contact']));
                                            $dash_portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($cust['customer_code'] ?: $cust['id']);
                                            // If has arrears, send reminder. Otherwise send generic hello.
                                            if ($cust['unpaid_count'] > 0) {
                                                $dash_wa_msg = parse_wa_template($wa_tpl_unpaid, [
                                                    'name' => $cust['name'],
                                                    'id_cust' => ($cust['customer_code'] ?: $cust['id']),
                                                    'package' => $cust['package_name'],
                                                    'tagihan' => $cust['monthly_fee'],
                                                    'total_payment' => $cust['total_unpaid'],
                                                    'due_date' => 'Tgl ' . ($cust['billing_date'] ?: '1'),
                                                    'rekening' => $rekening_receipt,
                                                    'portal_link' => $dash_portal_link
                                                ]);
                                            } else {
                                                $dash_wa_msg = "Halo " . $cust['name'] . ", ada yang bisa kami bantu?";
                                            }
                                            $dash_wa_link = "https://api.whatsapp.com/send?phone=$dash_wa_num&text=" . urlencode($dash_wa_msg);
                                        ?>
                                        <a href="<?= $dash_wa_link ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- 2. MY BILLS TO ISP -->
        <?php if(!empty($partner_invoices)): ?>
        <section class="ui-card overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                <div>
                    <h3 class="m-0 text-[15px] font-bold">Tagihan saya ke ISP</h3>
                    <p class="m-0 text-xs text-muted-foreground">Tagihan akun reseller Anda dari ISP pusat</p>
                </div>
                <span class="ui-badge ui-badge-muted">Pribadi</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold sm:px-5">Nominal</th><th class="px-3 py-2.5 font-semibold">Jatuh tempo</th><th class="px-3 py-2.5 font-semibold">Status</th><th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($partner_invoices as $inv): ?>
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 font-bold tabular-nums whitespace-nowrap sm:px-5">Rp <?= number_format($inv['amount'],0,',','.') ?></td>
                                <td class="px-3 py-3 tabular-nums whitespace-nowrap"><?= date('d/m/Y', strtotime($inv['due_date'])) ?></td>
                                <td class="px-3 py-3">
                                    <span class="ui-badge <?= ($inv['status'] === 'Lunas' ? 'ui-badge-signal' : 'ui-badge-danger') ?>"><?= htmlspecialchars($inv['status']) ?></span>
                                </td>
                                <td class="px-4 py-3 text-right sm:px-5">
                                    <a href="index.php?page=invoice_print&id=<?= $inv['id'] ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak"><i class="fas fa-print"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <!-- SUMMARY -->
        <div class="ui-card flex flex-wrap items-center justify-between gap-4 p-4 sm:p-5">
            <div>
                <div class="text-xs font-medium text-muted-foreground">Laba bersih saya</div>
                <div class="text-lg font-extrabold leading-tight tabular-nums">Rp <?= number_format($my_net_profit, 0, ',', '.') ?></div>
            </div>
            <div class="text-right">
                <div class="text-xs font-medium text-muted-foreground">Piutang aktif</div>
                <div class="text-lg font-extrabold leading-tight tabular-nums text-danger">Rp <?= number_format($total_unpaid_val, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Hidden Form for Quick Pay -->
<form id="quickPayForm" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
<?= csrf_field() ?>
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

    function editCustomer(data) {
        document.getElementById('edit_cust_id').value = data.id;
        document.getElementById('edit_name').value = data.name || '';
        document.getElementById('edit_address').value = data.address || '';
        document.getElementById('edit_contact').value = data.contact || '';
        document.getElementById('edit_package_name').value = data.package_name || '';
        document.getElementById('edit_monthly_fee').value = data.monthly_fee || 0;
        document.getElementById('edit_billing_date').value = data.billing_date || 1;
        document.getElementById('edit_area').value = data.area || '';
        document.getElementById('edit_ppn_active').checked = !!parseInt(data.ppn_active || 0, 10);
        document.getElementById('edit_bhp_active').checked = !!parseInt(data.bhp_active || 0, 10);
        document.getElementById('edit_uso_active').checked = !!parseInt(data.uso_active || 0, 10);
        document.getElementById('editCustomerModal').style.display = 'flex';
    }
    function syncEditPrice(select){ const fee = select.options[select.selectedIndex].getAttribute('data-fee'); if(fee){ document.getElementById('edit_monthly_fee').value = fee; } }

    function deleteCustomer(id, name) {
        if (confirm(`HAPUS PELANGGAN: ${name}?\n\nSeluruh data tagihan dan pembayaran pelanggan ini akan dihapus permanen.\n\nTindakan ini TIDAK BISA dibatalkan!`)) {
            window.location.href = `index.php?page=partner&action=delete_customer&id=${id}`;
        }
    }

    return { quickPay, showAddCustomerModal, syncAddPrice, editCustomer, syncEditPrice, deleteCustomer };
})();
</script>

<!-- Modal Tambah Pelanggan Baru -->
<div id="addCustomerModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4 border-b border-solid border-border px-5 py-4">
            <div>
                <h3 class="m-0 text-lg font-bold">Pelanggan baru</h3>
                <p class="m-0 mt-0.5 text-xs text-muted-foreground">Pendaftaran mitra di lapangan</p>
            </div>
            <button type="button" onclick="document.getElementById('addCustomerModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <form action="index.php?page=partner&action=add_customer" method="POST" class="min-h-0 overflow-y-auto p-5" onsubmit="return confirm('Daftarkan pelanggan baru ini?')">
<?= csrf_field() ?>
            <!-- Section 1: Data Diri -->
            <div class="mb-5">
                <div class="mb-3 text-sm font-bold">Identitas pelanggan</div>
                <div class="grid gap-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama lengkap / instansi</span>
                        <input type="text" name="name" class="form-control w-full" placeholder="Masukkan nama pelanggan" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">No. WhatsApp (aktif)</span>
                        <input type="text" name="contact" class="form-control w-full" placeholder="08xxxx" required>
                    </label>
                </div>
            </div>

            <!-- Section 2: Layanan -->
            <div class="mb-5">
                <div class="mb-3 text-sm font-bold">Paket & lokasi</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Paket internet</span>
                        <select name="package_name" class="form-control w-full" onchange="syncAddPrice(this)" required>
                            <option value="">-- Pilih paket --</option>
                            <?php foreach($packages_all as $pkg): ?>
                                <option value="<?= htmlspecialchars($pkg['name']) ?>" data-fee="<?= $pkg['fee'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rp <?= number_format($pkg['fee'],0,',','.') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Biaya bulanan (Rp)</span>
                        <input type="number" name="monthly_fee" id="add_monthly_fee" class="form-control w-full font-semibold tabular-nums" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal tagih</span>
                        <select name="billing_date" class="form-control w-full">
                            <?php for($d=1;$d<=28;$d++): ?>
                                <option value="<?= $d ?>">Tanggal <?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal registrasi</span>
                        <input type="date" name="registration_date" value="<?= date('Y-m-d') ?>" class="form-control w-full" required>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat lengkap</span>
                        <textarea name="address" class="form-control w-full" rows="3" placeholder="Jl. Contoh Nomor 1..."></textarea>
                    </label>
                    <div class="sm:col-span-2">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Aktivasi fitur tambahan</span>
                        <div class="flex flex-wrap gap-2">
                            <label class="inline-flex items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                                <input type="checkbox" name="ppn_active" value="1">
                                <span>PPN</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                                <input type="checkbox" name="bhp_active" value="1">
                                <span>BHP</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                                <input type="checkbox" name="uso_active" value="1">
                                <span>USO</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer: Actions -->
            <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-4">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('addCustomerModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> Simpan pelanggan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Pelanggan -->
<div id="editCustomerModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4 border-b border-solid border-border px-5 py-4">
            <div>
                <h3 class="m-0 text-lg font-bold">Edit pelanggan</h3>
                <p class="m-0 mt-0.5 text-xs text-muted-foreground">Perbarui data pelanggan Anda</p>
            </div>
            <button type="button" onclick="document.getElementById('editCustomerModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <form action="index.php?page=partner&action=edit_customer" method="POST" class="min-h-0 overflow-y-auto p-5" onsubmit="return confirm('Simpan perubahan data pelanggan ini?')">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="edit_cust_id">

            <!-- Section 1: Data Diri -->
            <div class="mb-5">
                <div class="mb-3 text-sm font-bold">Identitas pelanggan</div>
                <div class="grid gap-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama lengkap / instansi</span>
                        <input type="text" name="name" id="edit_name" class="form-control w-full" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">No. WhatsApp (aktif)</span>
                        <input type="text" name="contact" id="edit_contact" class="form-control w-full" required>
                    </label>
                </div>
            </div>

            <!-- Section 2: Layanan -->
            <div class="mb-5">
                <div class="mb-3 text-sm font-bold">Paket & lokasi</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Paket internet</span>
                        <select name="package_name" id="edit_package_name" class="form-control w-full" onchange="PartnerPage.syncEditPrice(this)">
                            <option value="">-- Custom --</option>
                            <?php foreach($packages_all as $pkg): ?>
                                <option value="<?= htmlspecialchars($pkg['name']) ?>" data-fee="<?= $pkg['fee'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rp <?= number_format($pkg['fee'],0,',','.') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Biaya bulanan (Rp)</span>
                        <input type="number" name="monthly_fee" id="edit_monthly_fee" class="form-control w-full font-semibold tabular-nums" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal tagih</span>
                        <select name="billing_date" id="edit_billing_date" class="form-control w-full">
                            <?php for($d=1;$d<=28;$d++): ?>
                                <option value="<?= $d ?>">Tanggal <?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Area</span>
                        <input type="text" name="area" id="edit_area" class="form-control w-full" placeholder="Area/wilayah">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat lengkap</span>
                        <textarea name="address" id="edit_address" class="form-control w-full" rows="3"></textarea>
                    </label>
                    <div class="sm:col-span-2">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Aktivasi fitur tambahan</span>
                        <div class="flex flex-wrap gap-2">
                            <label class="inline-flex items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                                <input type="checkbox" name="ppn_active" id="edit_ppn_active" value="1">
                                <span>PPN</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                                <input type="checkbox" name="bhp_active" id="edit_bhp_active" value="1">
                                <span>BHP</span>
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                                <input type="checkbox" name="uso_active" id="edit_uso_active" value="1">
                                <span>USO</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer: Actions -->
            <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-4">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('editCustomerModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> Simpan perubahan</button>
            </div>
        </form>
    </div>
</div>
