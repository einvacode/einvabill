<?php
$action = $_GET['action'] ?? 'list';

// Success Modal for Admin Customers
$success_data = null;
if (isset($_GET['sid'])) {
    $sid = intval($_GET['sid']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid AND tenant_id = $tenant_id")->fetch();
    $settings = $db->query("SELECT company_name, wa_template_paid, site_url FROM settings WHERE tenant_id = $tenant_id")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact'] ?? ''));
        $months_paid = intval($_GET['months'] ?? 1);
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas' AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $portal_link = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        $me = $db->query("SELECT * FROM users WHERE id = $_SESSION[user_id]")->fetch();
        $wa_tpl_paid = !empty($me['wa_template_paid']) ? $me['wa_template_paid'] : ($settings['wa_template_paid'] ?? "Halo {nama}, pembayaran {tagihan} LUNAS. Cek nota: {link_tagihan}");
        
        $receipt_msg = parse_wa_template($wa_tpl_paid, [
            'name' => $success_data['name'],
            'id_cust' => ($success_data['customer_code'] ?: $success_data['id']),
            'tagihan' => $success_data['monthly_fee'],
            'package' => ($success_data['package_name'] ?: '-'),
            'period' => $months_paid . ' Bulan',
            'tunggakan' => $tunggakan_val,
            'payment_time' => date('d/m/Y H:i') . ' WIB',
            'admin_name' => $_SESSION['user_name'],
            'company_name' => $settings['company_name'],
            'portal_link' => $portal_link,
            'total_paid' => floatval($success_data['monthly_fee'] * $months_paid)
        ]);
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?>

<?php if($success_data): ?>
<div class="ui-card mb-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="text-[15px] font-bold text-signal">Pembayaran berhasil</div>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Pelanggan <strong class="text-foreground"><?= htmlspecialchars($success_data['name']) ?></strong> telah diupdate.</p>
        </div>
        <button onclick="sendWAGateway('<?= $wa_num_paid ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $success_data['wa_link'] ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa"><i class="fab fa-whatsapp"></i> Kirim WA</button>
    </div>
</div>
<?php endif; ?>

<!-- Error Messages for Delete Prevention -->
<?php if ($_GET['msg'] === 'has_invoices'):
    $invoice_count = intval($_GET['invoice_count'] ?? 0);
    $cust_id = intval($_GET['cust_id'] ?? 0);
    $customer = $db->query("SELECT name FROM customers WHERE id = $cust_id")->fetch();
?>
<div class="ui-card mb-5 border-danger/40 p-4 text-sm">
    <span class="font-semibold text-danger">Tidak bisa menghapus pelanggan.</span>
    Pelanggan <strong><?= htmlspecialchars($customer['name'] ?? 'Unknown') ?></strong> memiliki <strong><?= $invoice_count ?></strong> tagihan yang masih ada.
    <p class="m-0 mt-1 text-xs text-muted-foreground">Untuk menghapus pelanggan, hapus atau lunasi semua tagihannya terlebih dahulu.</p>
</div>
<?php endif; ?>

<?php if ($_GET['msg'] === 'bulk_has_invoices'):
    $blocked_count = intval($_GET['blocked_count'] ?? 0);
?>
<div class="ui-card mb-5 border-danger/40 p-4 text-sm">
    <span class="font-semibold text-danger">Sebagian pelanggan tidak bisa dihapus.</span>
    <strong><?= $blocked_count ?></strong> pelanggan yang dipilih memiliki tagihan aktif dan tidak bisa dihapus.
    <p class="m-0 mt-1 text-xs text-muted-foreground">Silakan hapus atau lunasi tagihan mereka terlebih dahulu, atau pilih pelanggan lain yang tidak memiliki tagihan.</p>
</div>
<?php endif; ?>

<?php
// Fetch all packages for dropdowns (Scoped by Tenant)
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $u_id = $_SESSION['user_id'];
    $u_role = app_scope_role();
    
    // Dynamic logic to identify partners in this tenant
    $partner_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
    $partner_list = !empty($partner_ids) ? implode(',', $partner_ids) : '0';

    $scope_where = ($u_role === 'admin' || $u_role === 'collector') 
        ? " AND (created_by NOT IN ($partner_list) OR created_by = 0 OR created_by IS NULL) " 
        : " AND (created_by = $u_id) ";
    $pkg_scope = ($u_role === 'admin' || $u_role === 'collector') 
        ? "WHERE tenant_id = $tenant_id AND (created_by NOT IN ($partner_list) OR created_by = 0 OR created_by IS NULL)" 
        : "WHERE tenant_id = $tenant_id AND created_by = $u_id";
    $packages_all = $db->query("SELECT * FROM packages $pkg_scope ORDER BY name ASC")->fetchAll();
    $packages_json = json_encode($packages_all);

// Fetch all areas for dropdowns (Scoped: Hidden for Partner)
$areas_all = ($u_role === 'admin' || $u_role === 'collector') ? $db->query("SELECT * FROM areas WHERE tenant_id = $tenant_id ORDER BY name ASC")->fetchAll() : [];

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    $package_name = $_POST['package_name'];
    $monthly_fee = $_POST['monthly_fee'];
    $ip_address = $_POST['ip_address'];
    $type = $_POST['type'];
    $registration_date = $_POST['registration_date'];
    $billing_date = $_POST['billing_date'];
    $area = $_POST['area'] ?? '';
    $router_id = isset($_POST['router_id']) ? intval($_POST['router_id']) : 0;
    $pppoe_name = $_POST['pppoe_name'] ?? '';
    $collector_id = intval($_POST['collector_id'] ?? 0);
    $lat = $_POST['lat'] ?? '';
    $lng = $_POST['lng'] ?? '';
    $odp_id = intval($_POST['odp_id'] ?? 0);
    $odp_port = intval($_POST['odp_port'] ?? 0);
    $ppn_active = isset($_POST['ppn_active']) ? 1 : 0;
    $bhp_active = isset($_POST['bhp_active']) ? 1 : 0;
    $uso_active = isset($_POST['uso_active']) ? 1 : 0;
    
    // Auto-generate unique random customer code
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
    do {
        $customer_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt_check->execute([$customer_code]);
    } while ($stmt_check->fetchColumn() > 0);
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $created_by = $_SESSION['user_id'];
    $invoice_total = compute_customer_invoice_total_from_amount($monthly_fee, $ppn_active, $bhp_active, $uso_active)['total'];
    
    $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, ip_address, type, registration_date, billing_date, area, router_id, pppoe_name, collector_id, lat, lng, odp_id, odp_port, created_by, tenant_id, ppn_active, bhp_active, uso_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$customer_code, $name, $address, $contact, $package_name, $monthly_fee, $ip_address, $type, $registration_date, $billing_date, $area, $router_id, $pppoe_name, $collector_id, $lat, $lng, $odp_id, $odp_port, $created_by, $tenant_id, $ppn_active, $bhp_active, $uso_active]);
    $id = $db->lastInsertId();

    // SELECTIVE AUTOMATIC PAYMENT ON REGISTRATION
    if ($monthly_fee > 0) {
        if ($type === 'customer') {
            // RUMAHAN: Tagihan Terbit di Hari Registrasi (Belum Lunas - perlu konfirmasi manual)
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', ?, ?)");
            $stmt_inv->execute([$id, $invoice_total, $registration_date, date('Y-m-d H:i:s'), $tenant_id]);
        } else {
            // MITRA: Bayar Setelah 30 Hari / Sesuai Tanggal Tagihan Bulan Depan (Belum Lunas)
            $next_month = date('Y-m', strtotime("+1 month"));
            $bday = str_pad($billing_date, 2, '0', STR_PAD_LEFT);
            $due_date = "{$next_month}-{$bday}";
            
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', ?, ?)");
            $stmt_inv->execute([$id, $invoice_total, $due_date, date('Y-m-d H:i:s'), $tenant_id]);
        }
    }
    
    // Arrears Logic (Jika ada tunggakan manual dari migrasi data lama)
    $arrears_months = intval($_POST['arrears_months'] ?? 0);
    $arrears_amount = floatval($_POST['arrears_amount'] ?? 0);
    if ($arrears_amount <= 0) $arrears_amount = $monthly_fee;
    
    if ($arrears_months > 0 && $arrears_amount > 0) {
        $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', ?, ?)");
        for ($m = $arrears_months; $m >= 1; $m--) {
            $due = date('Y-m-d', strtotime("-{$m} months", strtotime(date('Y') . '-' . date('m') . '-' . str_pad($billing_date, 2, '0', STR_PAD_LEFT))));
            $created = $due;
            $stmt_inv->execute([$id, $arrears_amount, $due, $created, $tenant_id]);
        }
    }
    
    header("Location: index.php?page=admin_customers&action=details&id=$id&msg=added");
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $name = $_POST['name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    $package_name = $_POST['package_name'];
    $monthly_fee = $_POST['monthly_fee'];
    $ip_address = $_POST['ip_address'];
    $type = $_POST['type'];
    $registration_date = $_POST['registration_date'];
    $billing_date = $_POST['billing_date'];
    $area = $_POST['area'] ?? '';
    $router_id = isset($_POST['router_id']) ? intval($_POST['router_id']) : 0;
    $pppoe_name = $_POST['pppoe_name'] ?? '';
    $collector_id = intval($_POST['collector_id'] ?? 0);
    $lat = $_POST['lat'] ?? '';
    $lng = $_POST['lng'] ?? '';
    $odp_id = intval($_POST['odp_id'] ?? 0);
    $odp_port = intval($_POST['odp_port'] ?? 0);
    $ppn_active = isset($_POST['ppn_active']) ? 1 : 0;
    $bhp_active = isset($_POST['bhp_active']) ? 1 : 0;
    $uso_active = isset($_POST['uso_active']) ? 1 : 0;
    
    // Multi-Tenancy Ownership Check
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $check = $db->query("SELECT id FROM customers WHERE id = $id AND tenant_id = $tenant_id")->fetchColumn();
    
    if (!$check) {
        header("Location: index.php?page=admin_customers&msg=forbidden");
        exit;
    }
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("UPDATE customers SET name=?, address=?, contact=?, package_name=?, monthly_fee=?, ip_address=?, type=?, registration_date=?, billing_date=?, area=?, router_id=?, pppoe_name=?, collector_id=?, lat=?, lng=?, odp_id=?, odp_port=?, ppn_active=?, bhp_active=?, uso_active=? WHERE id=? AND tenant_id=?");
    $stmt->execute([$name, $address, $contact, $package_name, $monthly_fee, $ip_address, $type, $registration_date, $billing_date, $area, $router_id, $pppoe_name, $collector_id, $lat, $lng, $odp_id, $odp_port, $ppn_active, $bhp_active, $uso_active, $id, $tenant_id]);

    // OPTIMIZATION: Sync existing unpaid invoices with the new total amount, where the value already includes the active taxes
    $invoice_total = compute_customer_invoice_total_from_amount($monthly_fee, $ppn_active, $bhp_active, $uso_active)['total'];
    $db->prepare("UPDATE invoices SET amount = ? WHERE customer_id = ? AND status = 'Belum Lunas' AND tenant_id = ?")->execute([$invoice_total, $id, $tenant_id]);
    
    // Process additional arrears if any during update
    $arrears_months = intval($_POST['arrears_months'] ?? 0);
    $arrears_amount = floatval($_POST['arrears_amount'] ?? 0);
    if ($arrears_months > 0 && $arrears_amount > 0) {
        $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', ?, ?)");
        $check_stmt = $db->prepare("SELECT id FROM invoices WHERE customer_id = ? AND strftime('%Y-%m', due_date) = ? AND tenant_id = ?");
        for ($m = $arrears_months; $m >= 1; $m--) {
            $due = date('Y-m-d', strtotime("-{$m} months", strtotime(date('Y') . '-' . date('m') . '-' . str_pad($billing_date, 2, '0', STR_PAD_LEFT))));
            $due_month = date('Y-m', strtotime($due));
            
            // Check for existing invoice for this month
            $check_stmt->execute([$id, $due_month]);
            if (!$check_stmt->fetchColumn()) {
                $created = $due;
                $stmt_inv->execute([$id, $arrears_amount, $due, $created, $tenant_id]);
            }
        }
    }
    
    header("Location: index.php?page=admin_customers&action=details&id=$id&msg=profile_updated");
    exit;
}

if ($action === 'delete') {
    $id = intval($_GET['id']);
    
    // Multi-Tenancy Ownership Check
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $check = $db->query("SELECT id FROM customers WHERE id = $id AND tenant_id = $tenant_id")->fetchColumn();
    
    if (!$check) {
        header("Location: index.php?page=admin_customers&msg=forbidden");
        exit;
    }

    // OPTION C: DELETE PREVENTION - Check for related invoices
    $invoice_count = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = $id AND tenant_id = $tenant_id")->fetchColumn() ?? 0;
    
    if ($invoice_count > 0) {
        // Cannot delete - has related invoices
        header("Location: index.php?page=admin_customers&msg=has_invoices&invoice_count=$invoice_count&cust_id=$id");
        exit;
    }

    // Safe to delete - no related invoices
    $before = $db->query("SELECT name, customer_code, type, package_name, monthly_fee FROM customers WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: [];
    $db->prepare("DELETE FROM customers WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
    audit_log($db, 'customer_delete', 'customers', $id, 'Menghapus pelanggan ' . ($before['name'] ?? '-') . ' (' . ($before['customer_code'] ?? '-') . ')', $before);

    header("Location: index.php?page=admin_customers&msg=deleted");
    exit;
}

if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    if (!empty($ids)) {
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // OPTION C: DELETE PREVENTION - Check for related invoices
        $stmt = $db->prepare("SELECT customer_id, COUNT(*) as cnt FROM invoices WHERE customer_id IN ($id_placeholders) AND tenant_id = ? GROUP BY customer_id");
        $stmt->execute(array_merge($ids, [$tenant_id]));
        $invoiced_customers = $stmt->fetchAll();
        
        if (!empty($invoiced_customers)) {
            // Some customers have invoices - prevent bulk delete
            $blocked_ids = implode(',', array_column($invoiced_customers, 'customer_id'));
            $blocked_count = count($invoiced_customers);
            header("Location: index.php?page=admin_customers&msg=bulk_has_invoices&blocked_count=$blocked_count&blocked_ids=$blocked_ids");
            exit;
        }
        
        // Safe to delete - no related invoices
        $db->prepare("DELETE FROM customers WHERE id IN ($id_placeholders) AND tenant_id = ?")->execute(array_merge($ids, [$tenant_id]));
        
        header("Location: index.php?page=admin_customers&msg=bulk_deleted");
        exit;
    } else {
        header("Location: index.php?page=admin_customers");
        exit;
    }
}

if ($action === 'bulk_move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    $target_collector = intval($_POST['target_collector_id'] ?? 0);
    if (!empty($ids) && $target_collector > 0) {
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$target_collector], $ids, [$tenant_id]);
        $db->prepare("UPDATE customers SET collector_id = ? WHERE id IN ($id_placeholders) AND tenant_id = ?")->execute($params);
        header("Location: index.php?page=admin_customers&msg=bulk_moved");
        exit;
    } else {
        header("Location: index.php?page=admin_customers");
        exit;
    }
}

if ($action === 'bulk_assign_partner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u_role = app_scope_role();
    if ($u_role !== 'admin') {
        header("Location: index.php?page=admin_customers&msg=forbidden");
        exit;
    }

    $ids_raw = $_POST['ids'] ?? [];
    $ids = array_values(array_filter(array_map('intval', $ids_raw), function ($v) { return $v > 0; }));
    $target_partner = intval($_POST['target_partner_id'] ?? 0);

    if (empty($ids) || $target_partner <= 0) {
        header("Location: index.php?page=admin_customers&msg=bulk_partner_failed");
        exit;
    }

    $partner_exists = $db->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'partner'");
    $partner_exists->execute([$target_partner]);
    if (!$partner_exists->fetchColumn()) {
        header("Location: index.php?page=admin_customers&msg=bulk_partner_failed");
        exit;
    }

    $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $params = array_merge([$target_partner], $ids, [$tenant_id]);

    // Safety: only move regular customers, never partner profile rows.
    $stmt = $db->prepare("UPDATE customers SET created_by = ? WHERE id IN ($id_placeholders) AND type = 'customer' AND tenant_id = ?");
    $stmt->execute($params);
    $moved = intval($stmt->rowCount());

    header("Location: index.php?page=admin_customers&msg=bulk_partner_assigned&count=$moved");
    exit;
}

if ($action === 'export') {
    $filter_type = $_GET['filter_type'] ?? '';
    $filter_collector = $_GET['filter_collector'] ?? '';
    
    $where = " WHERE 1=1 ";
    if ($filter_type) $where .= " AND type = " . $db->quote($filter_type);
    if ($filter_collector) {
        $coll_area = $db->query("SELECT area FROM users WHERE id = " . intval($filter_collector))->fetchColumn();
        if ($coll_area && trim($coll_area) != '') {
            $where .= " AND area = " . $db->quote(trim($coll_area));
        }
    }

    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $where .= " AND tenant_id = $tenant_id ";
    
    $customers_export = $db->query("SELECT * FROM customers $where ORDER BY name ASC")->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Data_Pelanggan_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
    
    fputcsv($output, ['Kode', 'Nama', 'Tipe', 'Alamat', 'Kontak', 'Paket', 'Biaya Bulanan', 'IP Address', 'Area', 'PPN', 'BHP', 'USO']);
    
    foreach ($customers_export as $row) {
        fputcsv($output, [
            $row['customer_code'],
            $row['name'],
            $row['type'] == 'partner' ? 'Mitra' : 'Pelanggan',
            $row['address'],
            $row['contact'],
            $row['package_name'],
            $row['monthly_fee'],
            $row['ip_address'],
           $row['area'],
           !empty($row['ppn_active']) ? 'Aktif' : 'Nonaktif',
           !empty($row['bhp_active']) ? 'Aktif' : 'Nonaktif',
           !empty($row['uso_active']) ? 'Aktif' : 'Nonaktif'
       ]);
    }
    fclose($output);
    exit;
}

if ($action === 'import_file' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $collector_id = intval($_POST['collector_id'] ?? 0);
    
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
                // Try to detect mapping from header
                $mapping = detectImportMapping($row);
                $isFirst = false;
                if ($mapping) continue; // Skip header row if identified
            }

            if (trim($row[$mapping['name'] ?? 1] ?? '') == '') continue;
            $pending[] = $row;
        }
        fclose($handle);
        $_SESSION['pending_import'] = $pending;
        $_SESSION['pending_mapping'] = $mapping;
        $_SESSION['pending_collector_id'] = $collector_id;
        header("Location: index.php?page=admin_customers&action=import_preview");
        exit;
    }
}

if ($action === 'import_paste' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST['paste_data'];
    $collector_id = intval($_POST['collector_id'] ?? 0);
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
    $_SESSION['pending_import'] = $pending;
    $_SESSION['pending_mapping'] = $mapping;
    $_SESSION['pending_collector_id'] = $collector_id;
    header("Location: index.php?page=admin_customers&action=import_preview");
    exit;
}

// Helper to detect column mapping from a row
function detectImportMapping($row) {
    $map = ['type' => 0, 'name' => 1, 'address' => 2, 'contact' => 3, 'package' => 4, 'fee' => 5, 'ip' => 6, 'reg_date' => 7, 'bill_date' => 8, 'area' => 9];
    $found = false;
    foreach ($row as $idx => $val) {
        $val = strtolower(trim($val));
        if (empty($val)) continue;
        
        // Priority Detection (Specific keywords first to avoid overlap)
        if (strpos($val, 'paket') !== false || strpos($val, 'package') !== false) { 
            $map['package'] = $idx; $found = true; 
        } elseif (strpos($val, 'tipe') !== false || strpos($val, 'type') !== false) { 
            $map['type'] = $idx; $found = true; 
        } elseif (strpos($val, 'ip') !== false) { 
            $map['ip'] = $idx; $found = true; 
        } elseif (strpos($val, 'biaya') !== false || strpos($val, 'fee') !== false || strpos($val, 'harga') !== false || strpos($val, 'tarif') !== false) { 
            $map['fee'] = $idx; $found = true; 
        } elseif (strpos($val, 'registrasi') !== false || strpos($val, 'daftar') !== false) { 
            $map['reg_date'] = $idx; $found = true; 
        } elseif (strpos($val, 'tagihan') !== false || strpos($val, 'billing') !== false || strpos($val, 'tempo') !== false) { 
            $map['bill_date'] = $idx; $found = true; 
        } elseif (strpos($val, 'area') !== false || strpos($val, 'wilayah') !== false || strpos($val, 'lokasi') !== false) { 
            $map['area'] = $idx; $found = true; 
        } elseif (strpos($val, 'nama') !== false || strpos($val, 'name') !== false || strpos($val, 'pelanggan') !== false) { 
            $map['name'] = $idx; $found = true; 
        } elseif (strpos($val, 'alamat') !== false || strpos($val, 'address') !== false) { 
            $map['address'] = $idx; $found = true; 
        } elseif (strpos($val, 'kontak') !== false || strpos($val, 'wa') !== false || strpos($val, 'whatsapp') !== false || strpos($val, 'phone') !== false || strpos($val, 'hp') !== false) { 
            $map['contact'] = $idx; $found = true; 
        }
    }
    return $found ? $map : null;
}

if ($action === 'import_confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending = $_SESSION['pending_import'] ?? [];
    $map = $_SESSION['pending_mapping'] ?? ['type' => 0, 'name' => 1, 'address' => 2, 'contact' => 3, 'package' => 4, 'fee' => 5, 'ip' => 6, 'reg_date' => 7, 'bill_date' => 8, 'area' => 9];
    $collector_id = $_SESSION['pending_collector_id'] ?? 0;
    if (empty($pending)) { header("Location: index.php?page=admin_customers"); exit; }

    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("INSERT INTO customers (name, address, contact, package_name, monthly_fee, ip_address, type, registration_date, billing_date, area, created_by, collector_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_chk = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ? AND tenant_id = ?");
    $count = 0;
    $u_id_import = $_SESSION['user_id'];

    // Pre-fetch collectors for Smart Assignment lookup
    $collector_lookup = [];
    try {
        $all_colls = $db->query("SELECT id, name, area FROM users WHERE role = 'collector'")->fetchAll();
        foreach ($all_colls as $ac) {
            $collector_lookup[strtolower(trim($ac['name']))] = $ac['id'];
            if (!empty($ac['area'])) {
                $areas_split = explode(',', $ac['area']); 
                foreach($areas_split as $as) {
                    $collector_lookup[strtolower(trim($as))] = $ac['id'];
                }
            }
        }
    } catch(Exception $e) {}

    $db->beginTransaction();
    try {
        foreach ($pending as $row) {
            $name = trim($row[$map['name']] ?? '');
            if (empty($name)) continue;

            $pkg_name = trim($row[$map['package']] ?? 'Standar');
            $pkg_fee = (float)preg_replace('/[^0-9]/', '', $row[$map['fee']] ?? 0);

            // Smart Sync: Package
            if (!empty($pkg_name)) {
                $pkg_exist = $db->prepare("SELECT id FROM packages WHERE name = ? AND tenant_id = ?");
                $pkg_exist->execute([$pkg_name, $tenant_id]);
                if (!$pkg_exist->fetch()) {
                    $db->prepare("INSERT INTO packages (name, fee, tenant_id) VALUES (?, ?, ?)")->execute([$pkg_name, $pkg_fee, $tenant_id]);
                }
            }

            // Smart Sync: Area
            $cust_area = isset($row[$map['area']]) ? trim($row[$map['area']]) : '';
            if (!empty($cust_area)) {
                $area_exist = $db->prepare("SELECT id FROM areas WHERE name = ? AND tenant_id = ?");
                $area_exist->execute([$cust_area, $tenant_id]);
                if (!$area_exist->fetch()) {
                    $db->prepare("INSERT INTO areas (name, tenant_id) VALUES (?, ?)")->execute([$cust_area, $tenant_id]);
                }
            }

            // SMART ASSIGNMENT
            $row_collector_id = $collector_id;
            if ($row_collector_id == 0 && !empty($cust_area)) {
                $area_key = strtolower(trim($cust_area));
                if (isset($collector_lookup[$area_key])) {
                    $row_collector_id = $collector_lookup[$area_key];
                }
            }

            $stmt->execute([
                $name, trim($row[$map['address']] ?? ''), trim($row[$map['contact']] ?? ''), $pkg_name, $pkg_fee,
                trim($row[$map['ip']] ?? ''), strtolower(trim($row[$map['type']] ?? '')) == 'partner' ? 'partner' : 'customer', 
                trim($row[$map['reg_date']] ?? date('Y-m-d')), (int)trim($row[$map['bill_date']] ?? 1), $cust_area, $u_id_import, $row_collector_id, $tenant_id
            ]);
            $count++;
            
            $imp_id = $db->lastInsertId();
            // Generate Code (Fast enough within transaction, but we can improve later if needed)
            do {
                $imp_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                $stmt_chk->execute([$imp_code, $tenant_id]);
            } while ($stmt_chk->fetchColumn() > 0);
            $db->prepare("UPDATE customers SET customer_code = ? WHERE id = ? AND tenant_id = ?")->execute([$imp_code, $imp_id, $tenant_id]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: index.php?page=admin_customers&msg=import_error&err=" . urlencode($e->getMessage()));
        exit;
    }
    
    unset($_SESSION['pending_import']);
    unset($_SESSION['pending_mapping']);
    unset($_SESSION['pending_collector_id']);
    header("Location: index.php?page=admin_customers&msg=import_success&count=" . $count);
    exit;
}

if ($action === 'import_cancel') {
    unset($_SESSION['pending_import']);
    unset($_SESSION['pending_collector_id']);
    header("Location: index.php?page=admin_customers&action=import_view");
    exit;
}

if ($action === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="template_pelanggan.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Tipe (customer/partner)', 'Nama Pelanggan', 'Alamat', 'Kontak WhatsApp', 'Nama Paket', 'Biaya Bulanan (Angka)', 'IP Address', 'Tanggal Registrasi (YYYY-MM-DD)', 'Tanggal Tagihan (1-28)', 'Area']);
    fputcsv($output, ['customer', 'Budi Santoso', 'Jl. Merdeka Nomor 1', '081234567890', '10 Mbps', '150000', '192.168.1.10', date('Y-m-d'), '15', 'Blok A']);
    fclose($output);
    exit;
}

if ($action === 'bulk_pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $num_months = intval($_POST['num_months']);
    $amount_per_month = floatval($_POST['amount_per_month']);
    $receiver_id = $_SESSION['user_id'];
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $c = $db->query("SELECT billing_date FROM customers WHERE id = $customer_id AND tenant_id = $tenant_id")->fetch();
    if (!$c) { header("Location: index.php?page=admin_customers&msg=forbidden"); exit; }
    $bday = str_pad($c['billing_date'] ?: 1, 2, '0', STR_PAD_LEFT);
    $pay_account = cash_posted_account($db, (int)$tenant_id);

    // Find the latest invoice due date to start from
    $last_due = $db->query("SELECT due_date FROM invoices WHERE customer_id = $customer_id AND tenant_id = $tenant_id ORDER BY due_date DESC LIMIT 1")->fetchColumn();
    
    $start_time = $last_due ? strtotime($last_due) : strtotime(date('Y-m-') . $bday);
    if(!$last_due) $start_time = strtotime("-1 month", $start_time); // Start from previous to make current first

    for ($i = 1; $i <= $num_months; $i++) {
        $next_due = date('Y-m-d', strtotime("+$i months", $start_time));
        
        // Check if invoice exists for this date
        $exist = $db->query("SELECT id, status FROM invoices WHERE customer_id = $customer_id AND due_date = '$next_due' AND tenant_id = $tenant_id")->fetch();
        
        if ($exist) {
            if ($exist['status'] !== 'Lunas') {
                $inv_id = $exist['id'];
                $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ? AND tenant_id = ?")->execute([$inv_id, $tenant_id]);
                $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date, tenant_id) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$inv_id, $amount_per_month, $receiver_id, date('Y-m-d H:i:s'), $tenant_id]);
                cash_tag_payment($db, (int)$tenant_id, (int)$db->lastInsertId(), $pay_account);
            }
        } else {
            $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, tenant_id) VALUES (?, ?, ?, 'Lunas', ?)")
               ->execute([$customer_id, $amount_per_month, $next_due, $tenant_id]);
            $inv_id = $db->lastInsertId();
            $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date, tenant_id) VALUES (?, ?, ?, ?, ?)")
               ->execute([$inv_id, $amount_per_month, $receiver_id, date('Y-m-d H:i:s'), $tenant_id]);
            cash_tag_payment($db, (int)$tenant_id, (int)$db->lastInsertId(), $pay_account);
        }
    }
    
    header("Location: index.php?page=admin_customers&action=details&id=$customer_id&success=bulk");
    exit;
}
?>

<?php if ($action === 'list'): ?>
    <?php
    $filter_type = $_GET['filter_type'] ?? '';
    $create_url = "index.php?page=admin_customers&action=create" . ($filter_type ? "&type=$filter_type" : "");
    $export_url = "index.php?page=admin_customers&action=export" . ($filter_type ? "&type=$filter_type" : "");
    $page_title = $filter_type === 'partner' ? 'Manajemen kemitraan (B2B)' : ($filter_type === 'customer' ? 'Manajemen pelanggan rumahan' : 'Pelanggan & mitra');
    ?>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl"><?= $page_title ?></h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Manajemen data pelanggan & konfigurasi layanan</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= $export_url ?>" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-down"></i> <span class="hidden sm:inline">Export</span></a>
            <a href="index.php?page=admin_customers&action=import_view" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-up"></i> <span class="hidden sm:inline">Import</span></a>
            <a href="<?= $create_url ?>" class="ui-btn ui-btn-primary"><i class="fas fa-plus"></i> Tambah</a>
        </div>
    </div>

    <div class="mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1">
        <a href="index.php?page=admin_customers" class="rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground shadow-card no-underline" aria-current="page">Pelanggan</a>
        <a href="index.php?page=admin_invoices" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Tagihan</a>
        <a href="index.php?page=admin_reports" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Laporan</a>
    </div>

    <?php if(isset($_GET['msg'])): ?>
        <div class="ui-card mb-5 p-4 text-sm">
            <span class="font-semibold text-signal">
                <?php
                if($_GET['msg'] == 'added') echo "Berhasil menambah pelanggan.";
                if($_GET['msg'] == 'import_success') echo "Berhasil mengimpor " . intval($_GET['count'] ?? 0) . " data pelanggan ke akun Anda.";
                if($_GET['msg'] == 'bulk_moved') echo "Berhasil memindahkan penagih untuk pelanggan terpilih.";
                if($_GET['msg'] == 'bulk_partner_assigned') echo "Berhasil memindahkan " . intval($_GET['count'] ?? 0) . " pelanggan ke akun mitra terpilih.";
                if($_GET['msg'] == 'bulk_partner_failed') echo "Gagal memindahkan pelanggan ke mitra. Pastikan mitra tujuan valid.";
                ?>
            </span>
        </div>
    <?php endif; ?>

    <?php
    $filter_type = $_GET['filter_type'] ?? '';
    $filter_collector = $_GET['filter_collector'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $where_type = "";
    if ($filter_type) $where_type = " AND type = " . $db->quote($filter_type);

    // By default, exclude temporary quick-invoice customers from general lists
    // They should only appear when an explicit filter requests them.
    if (empty($filter_type)) {
        $where_type .= " AND type NOT IN ('note','temp')";
    }
    
    $where_collector = "";
    if ($filter_collector) {
        $stmt_coll = $db->prepare("SELECT area FROM users WHERE id = ? AND tenant_id = ?");
        $stmt_coll->execute([intval($filter_collector), $tenant_id]);
        $coll_area = $stmt_coll->fetchColumn();
        if ($coll_area && trim($coll_area) != '') {
            $where_collector = " AND area = " . $db->quote(trim($coll_area));
        }
    }

    $where_search = "";
    if ($search) {
        $s = $db->quote("%$search%");
        $where_search = " AND (name LIKE $s OR customer_code LIKE $s OR address LIKE $s OR contact LIKE $s)";
    }

    $filter_month = $_GET['filter_month'] ?? '';
    if ($filter_month) {
        $where_search .= " AND strftime('%Y-%m', registration_date) = " . $db->quote($filter_month);
    }

    $collectors = $db->query("SELECT id, name FROM users WHERE role = 'collector' ORDER BY name ASC")->fetchAll();
    $partners = ($u_role === 'admin') ? $db->query("SELECT id, name FROM users WHERE role = 'partner' ORDER BY name ASC")->fetchAll() : [];

    // Pagination Logic
    $items_per_page = 50;
    $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;
    
    // Scoping Logic (Multi-tenancy & Hierarchical Isolation)
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $scope_where = " AND tenant_id = $tenant_id ";
    
    // Partner exclusion for Admin/Collector
    if ($u_role === 'admin' || $u_role === 'collector') {
        $scope_where .= " AND (created_by NOT IN ($partner_list) OR created_by = 0 OR created_by IS NULL) ";
    }
    
    // Additional restriction for collectors (only their assigned area/customers)
    if ($u_role === 'collector') {
        $scope_where .= " AND collector_id = " . intval($_SESSION['user_id']);
    }

    // Count total rows for this filter
    $count_q = "SELECT COUNT(*) FROM customers WHERE 1=1 $where_type $where_collector $where_search $scope_where";
    $total_rows = $db->query($count_q)->fetchColumn();
    $total_pages = ceil($total_rows / $items_per_page);
    ?>

    <!-- Stats Section -->
    <?php
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stats_q = "SELECT SUM(monthly_fee) as total_mrr, AVG(monthly_fee) as avg_fee FROM customers WHERE tenant_id = $tenant_id $where_type $where_collector $where_search $scope_where";
    $stats_data = $db->query($stats_q)->fetch();
    ?>
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3">
        <div class="ui-card p-4">
            <div class="text-xs font-medium text-muted-foreground">Total <?= $filter_type == 'partner' ? 'mitra' : 'pelanggan' ?></div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= number_format($total_rows) ?></div>
        </div>
        <div class="ui-card p-4">
            <div class="text-xs font-medium text-muted-foreground">Estimasi MRR</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums">Rp <?= number_format($stats_data['total_mrr'] ?? 0, 0, ',', '.') ?></div>
        </div>
        <div class="ui-card p-4">
            <div class="text-xs font-medium text-muted-foreground">Rata-rata ARPU</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums">Rp <?= number_format($stats_data['avg_fee'] ?? 0, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_180px_180px_auto] lg:items-end">
        <input type="hidden" name="page" value="admin_customers">

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Cari pelanggan</span>
            <input type="text" name="search" class="form-control" placeholder="Nama, kode, atau nomor HP" value="<?= htmlspecialchars($search) ?>">
        </label>

        <?php if ($u_role === 'admin'): ?>
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Tipe</span>
            <select name="filter_type" class="form-control">
                <option value="">Semua tipe</option>
                <option value="customer" <?= $filter_type == 'customer' ? 'selected' : '' ?>>Rumahan</option>
                <option value="partner" <?= $filter_type == 'partner' ? 'selected' : '' ?>>Mitra / B2B</option>
            </select>
        </label>
        <?php endif; ?>

        <?php if ($u_role === 'admin'): ?>
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Penagih</span>
            <select name="filter_collector" class="form-control">
                <option value="">Semua penagih</option>
                <?php foreach($collectors as $coll): ?>
                    <option value="<?= $coll['id'] ?>" <?= $filter_collector == $coll['id'] ? 'selected' : '' ?>><?= htmlspecialchars($coll['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>

        <div class="flex gap-2">
            <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto"><i class="fas fa-search"></i> Cari</button>
            <?php if($search || $filter_type || $filter_collector): ?>
                <a href="index.php?page=admin_customers" class="ui-btn ui-btn-outline" title="Reset filter"><i class="fas fa-sync"></i></a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Mobile Card View (Hidden on Desktop) -->
    <div class="customers-mobile-container block md:hidden">
        <?php
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $routers = [];
        try { $routers = $db->query("SELECT * FROM routers WHERE tenant_id = $tenant_id")->fetchAll(); } catch(Exception $e) {}
        $customers = $db->query("SELECT id, customer_code, name, type, contact, address, package_name, monthly_fee, area, router_id, pppoe_name, created_by, collector_id FROM customers WHERE 1=1 $where_type $where_collector $where_search $scope_where ORDER BY id DESC LIMIT $items_per_page OFFSET $offset")->fetchAll();

        // Reminder text for the row buttons: the saved template, with this
        // customer's real outstanding rather than a generic sentence.
        $list_settings = $db->query("SELECT company_name, wa_template, bank_account, site_url FROM settings WHERE tenant_id = $tenant_id")->fetch() ?: [];
        $list_me = $db->query("SELECT wa_template FROM users WHERE id = " . intval($_SESSION['user_id'] ?? 0))->fetch() ?: [];
        $list_tpl = !empty($list_me['wa_template']) ? $list_me['wa_template'] : ($list_settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}");
        $list_base = !empty($list_settings['site_url']) ? rtrim($list_settings['site_url'], '/') : get_app_url();
        $list_due = [];
        if ($customers) {
            $ids = implode(',', array_map(fn($x) => intval($x['id']), $customers));
            foreach ($db->query("SELECT customer_id, COALESCE(SUM(amount - COALESCE(discount,0)),0) AS sisa, MIN(due_date) AS due FROM invoices WHERE tenant_id = $tenant_id AND status <> 'Lunas' AND customer_id IN ($ids) GROUP BY customer_id") as $r) {
                $list_due[(int)$r['customer_id']] = $r;
            }
        }

        foreach($customers as $c):
            $rtName = '-';
            foreach($routers as $r) { if($r['id'] == ($c['router_id'] ?? 0)) $rtName = $r['name']; }
        ?>
        <div class="ui-card mb-3 p-4">
            <div class="mb-2.5 flex items-start justify-between gap-3">
                <div>
                    <div class="text-[15px] font-bold"><?= htmlspecialchars($c['name']) ?></div>
                    <div class="font-mono text-xs text-muted-foreground"><?= htmlspecialchars($c['customer_code'] ?? '') ?></div>
                </div>
                <?php if($u_role === 'admin'): ?>
                    <?php if($c['type']=='partner'): ?>
                        <span class="ui-badge ui-badge-accent">Mitra</span>
                    <?php else: ?>
                        <span class="ui-badge ui-badge-muted">Pelanggan</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="mb-3 space-y-0.5 text-[13px] text-muted-foreground">
                <div><?= htmlspecialchars($c['package_name']) ?> — <strong class="tabular-nums text-foreground">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></strong></div>
                <?php if($u_role === 'admin'): ?>
                <div>Area: <?= htmlspecialchars($c['area'] ?: '-') ?></div>
                <?php endif; ?>
                <div>Kontak: <?= htmlspecialchars($c['contact']) ?></div>
                <div class="font-mono text-xs">IP: <?= htmlspecialchars($c['ip_address'] ?: '-') ?> · Sumber: <?= htmlspecialchars($rtName) ?></div>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-solid border-border pt-3">
                <div class="conn-status" data-router="<?= htmlspecialchars($c['router_id'] ?? 0) ?>" data-pppoe="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>">
                    <?php if(empty($c['pppoe_name'])): ?>
                        <span class="text-xs text-muted-foreground">Tanpa API</span>
                    <?php elseif(intval($c['router_id'] ?? 0) <= 0): ?>
                        <span class="text-xs text-muted-foreground">Router N/A</span>
                    <?php else: ?>
                        <i class="fas fa-spinner fa-spin text-muted-foreground"></i>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap justify-end gap-1.5">
                    <button class="ui-btn ui-btn-sm ui-btn-outline" onclick="createInvoice(<?= $c['id'] ?>, <?= $c['monthly_fee'] ?>)" title="Tagih"><i class="fas fa-file-invoice-dollar"></i></button>
                    <?php if(!empty($c['pppoe_name'])): ?>
                        <button class="ui-btn ui-btn-sm ui-btn-outline" onclick="viewTR069('<?= htmlspecialchars($c['pppoe_name']) ?>')" title="TR-069"><i class="fas fa-satellite-dish"></i></button>
                    <?php endif; ?>
                    <a href="index.php?page=admin_customers&action=details&id=<?= $c['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Detail lengkap"><i class="fas fa-eye"></i></a>
                    <a href="index.php?page=admin_customers&action=edit&id=<?= $c['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit"><i class="fas fa-edit"></i></a>
                    <a data-method="post" href="index.php?page=admin_customers&action=delete&id=<?= $c['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus?')" title="Hapus"><i class="fas fa-trash"></i></a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($customers)): ?>
        <div class="ui-card px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</div>
        <?php endif; ?>
    </div>

    <!-- Desktop Table View (Hidden on Mobile) -->
    <section class="customers-desktop-table ui-card hidden overflow-hidden md:block">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="w-10 px-4 py-2.5 text-center font-semibold"><input type="checkbox" id="check-all-cust" class="cursor-pointer"></th>
                        <th class="px-4 py-2.5 font-semibold">Nama</th>
                        <?php if ($u_role === 'admin'): ?><th class="px-4 py-2.5 font-semibold">Tipe / area</th><?php endif; ?>
                        <th class="px-4 py-2.5 font-semibold">Layanan / kontak</th>
                        <th class="px-4 py-2.5 font-semibold">Biaya bulanan</th>
                        <th class="px-4 py-2.5 font-semibold">IP / koneksi</th>
                        <th class="px-4 py-2.5 text-center font-semibold">Status</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($customers)): ?>
                        <tr><td colspan="8" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</td></tr>
                    <?php endif; ?>
                    <?php foreach($customers as $c):
                        $rtName = '-';
                        foreach($routers as $r) { if($r['id'] == ($c['router_id'] ?? 0)) $rtName = $r['name']; }
                    ?>
                    <tr class="cust-row border-t border-solid border-border">
                        <td class="px-4 py-3 text-center"><input type="checkbox" class="cust-checkbox cursor-pointer" value="<?= $c['id'] ?>"></td>
                        <td class="px-4 py-3">
                            <div class="font-semibold"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="font-mono text-xs text-muted-foreground"><?= htmlspecialchars($c['customer_code'] ?? '') ?></div>
                        </td>
                        <?php if ($u_role === 'admin'): ?>
                        <td class="px-4 py-3">
                            <?php if($c['type']=='partner'): ?>
                                <span class="ui-badge ui-badge-accent">Mitra</span>
                            <?php else: ?>
                                <span class="ui-badge ui-badge-muted">Pelanggan</span>
                            <?php endif; ?>
                            <div class="mt-1 text-xs text-muted-foreground"><?= htmlspecialchars($c['area'] ?: '-') ?></div>
                        </td>
                        <?php endif; ?>
                        <td class="px-4 py-3">
                            <div class="font-semibold"><?= htmlspecialchars($c['package_name']) ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($c['contact']) ?></div>
                        </td>
                        <td class="px-4 py-3 font-semibold tabular-nums">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></td>
                        <td class="px-4 py-3 font-mono text-xs leading-relaxed">
                            IP: <?= htmlspecialchars($c['ip_address'] ?? '-') ?><br>
                            <span class="text-muted-foreground">Router: <?= htmlspecialchars($rtName) ?></span><br>
                            <span class="text-muted-foreground">PPPoE: <?= htmlspecialchars($c['pppoe_name'] ?? 'Tidak disetel') ?></span>
                        </td>
                        <td class="conn-status px-4 py-3 text-center" data-router="<?= htmlspecialchars($c['router_id'] ?? 0) ?>" data-pppoe="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>">
                            <?php if(empty($c['pppoe_name'])): ?>
                                <span class="text-xs text-muted-foreground">Tanpa API</span>
                            <?php else: ?>
                                <i class="fas fa-circle-notch fa-spin text-muted-foreground"></i>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap justify-end gap-1.5">
                            <button class="ui-btn ui-btn-sm ui-btn-outline" onclick="createInvoice(<?= $c['id'] ?>, <?= $c['monthly_fee'] ?>)" title="Tagih manual"><i class="fas fa-file-invoice"></i></button>
                            <?php if(!empty($c['pppoe_name'])): ?>
                                <button class="ui-btn ui-btn-sm ui-btn-outline" onclick="viewTR069('<?= htmlspecialchars($c['pppoe_name']) ?>')" title="Monitoring TR-069"><i class="fas fa-satellite-dish"></i></button>
                            <?php endif; ?>
                            <a href="index.php?page=admin_customers&action=details&id=<?= $c['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Detail & riwayat"><i class="fas fa-eye"></i></a>
                            <a href="index.php?page=admin_customers&action=edit&id=<?= $c['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit"><i class="fas fa-edit"></i></a>
                            <a data-method="post" href="index.php?page=admin_customers&action=delete&id=<?= $c['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus pelanggan ini?')" title="Hapus"><i class="fas fa-trash"></i></a>

                            <?php
                            $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $c['contact']));
                            $c_due = $list_due[(int)$c['id']] ?? null;
                            $c_sisa = $c_due ? (float)$c_due['sisa'] : 0;
                            $wa_text = parse_wa_template($list_tpl, [
                                'name' => $c['name'],
                                'id_cust' => $c['customer_code'] ?: $c['id'],
                                'package' => $c['package_name'] ?: '-',
                                'period' => $c_due && $c_due['due'] ? date('F Y', strtotime($c_due['due'])) : date('F Y'),
                                'tagihan' => $c_sisa > 0 ? $c_sisa : (float)$c['monthly_fee'],
                                'tunggakan' => $c_sisa,
                                'total_payment' => $c_sisa > 0 ? $c_sisa : (float)$c['monthly_fee'],
                                'due_date' => $c_due && $c_due['due'] ? date('d/m/Y', strtotime($c_due['due'])) : '-',
                                'rekening' => trim((string)($list_settings['bank_account'] ?? '')),
                                'company_name' => $list_settings['company_name'] ?? '',
                                'portal_link' => $list_base . '/index.php?page=customer_portal&code=' . urlencode($c['customer_code'] ?: $c['id']),
                            ]);
                            ?>
                            <button onclick="sendWAGateway('<?= $wa_number ?>', <?= htmlspecialchars(json_encode($wa_text)) ?>, null, this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim WA"><i class="fab fa-whatsapp"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Pagination Navigation -->
    <?php if($total_pages > 1): ?>
    <div class="mt-5 flex flex-wrap items-center justify-center gap-1.5">
        <?php
        $params = $_GET;
        unset($params['p']);
        $query_str = http_build_query($params);
        $base_url = "index.php?" . $query_str . "&p=";
        ?>

        <?php if($current_page > 1): ?>
            <a href="<?= $base_url . ($current_page - 1) ?>" class="ui-btn ui-btn-sm ui-btn-outline">&laquo; Prev</a>
        <?php endif; ?>

        <?php
        $start_p = max(1, $current_page - 2);
        $end_p = min($total_pages, $current_page + 2);
        if($start_p > 1) echo '<span class="px-2 text-muted-foreground">...</span>';
        for($i = $start_p; $i <= $end_p; $i++):
        ?>
            <a href="<?= $base_url . $i ?>" class="ui-btn ui-btn-sm <?= $i == $current_page ? 'ui-btn-primary' : 'ui-btn-outline' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if($end_p < $total_pages) echo '<span class="px-2 text-muted-foreground">...</span>'; ?>

        <?php if($current_page < $total_pages): ?>
            <a href="<?= $base_url . ($current_page + 1) ?>" class="ui-btn ui-btn-sm ui-btn-outline">Next &raquo;</a>
        <?php endif; ?>

        <div class="w-full text-center text-xs text-muted-foreground">
            Menampilkan <?= count($customers) ?> dari <?= $total_rows ?> pelanggan (halaman <?= $current_page ?> dari <?= $total_pages ?>)
        </div>
    </div>
    <?php endif; ?>

    <!-- Bulk Action Bar -->
    <div id="bulk-bar" class="ui-card fixed bottom-4 left-1/2 z-[1000] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 flex-wrap items-center justify-between gap-3 border-primary/40 p-3 shadow-md sm:px-5" style="display:none;">
        <div class="flex items-center gap-3">
            <button class="ui-btn ui-btn-sm ui-btn-ghost" onclick="cancelBulkSelection()" title="Batalkan seleksi"><i class="fas fa-times"></i></button>
            <div class="flex items-center gap-2 text-sm font-semibold">
                <span id="bulk-count" class="ui-badge ui-badge-signal tabular-nums">0</span> terpilih
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <?php if ($u_role === 'admin'): ?>
            <div id="move-collector-box" class="items-center gap-2 rounded-md bg-muted px-2.5 py-1.5" style="display:none;">
                <span class="text-xs font-semibold">Pindah ke:</span>
                <select id="bulk-target-collector" class="form-control w-40">
                    <option value="">-- Pilih penagih --</option>
                    <?php
                    $colls = $db->query("SELECT id, name FROM users WHERE role = 'collector'")->fetchAll();
                    foreach($colls as $coll) echo "<option value='{$coll['id']}'>{$coll['name']}</option>";
                    ?>
                </select>
                <button class="ui-btn ui-btn-sm ui-btn-primary" onclick="submitBulkMove()">Simpan</button>
                <button class="ui-btn ui-btn-sm ui-btn-ghost" onclick="toggleMoveBox(false)">Batal</button>
            </div>

            <div id="assign-partner-box" class="items-center gap-2 rounded-md bg-muted px-2.5 py-1.5" style="display:none;">
                <span class="text-xs font-semibold">Mitra:</span>
                <select id="bulk-target-partner" class="form-control w-44">
                    <option value="">-- Pilih mitra --</option>
                    <?php foreach($partners as $p) echo "<option value='{$p['id']}'>" . htmlspecialchars($p['name']) . "</option>"; ?>
                </select>
                <button class="ui-btn ui-btn-sm ui-btn-primary" onclick="submitBulkAssignPartner()">Simpan</button>
                <button class="ui-btn ui-btn-sm ui-btn-ghost" onclick="togglePartnerBox(false)">Batal</button>
            </div>

            <button class="ui-btn ui-btn-sm ui-btn-outline" id="btn-move-trigger" onclick="toggleMoveBox(true)">
                <i class="fas fa-exchange-alt"></i> Pindah penagih
            </button>

            <button class="ui-btn ui-btn-sm ui-btn-outline" id="btn-assign-partner-trigger" onclick="togglePartnerBox(true)">
                <i class="fas fa-user-tag"></i> Pindah mitra
            </button>
            <?php endif; ?>

            <button class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="submitBulkDelete()">
                <i class="fas fa-trash"></i> Hapus masal
            </button>
        </div>
    </div>

    <!-- Hidden forms for bulk actions -->
    <form id="bulk-form-delete" action="index.php?page=admin_customers&action=bulk_delete" method="POST" style="display:none;">
<?= csrf_field() ?></form>
    <form id="bulk-form-move" action="index.php?page=admin_customers&action=bulk_move" method="POST" style="display:none;">
<?= csrf_field() ?>
        <input type="hidden" name="target_collector_id" id="hidden-target-collector">
    </form>
    <form id="bulk-form-assign-partner" action="index.php?page=admin_customers&action=bulk_assign_partner" method="POST" style="display:none;">
<?= csrf_field() ?>
        <input type="hidden" name="target_partner_id" id="hidden-target-partner">
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkAll = document.getElementById('check-all-cust');
        const checkboxes = document.querySelectorAll('.cust-checkbox');
        const bulkBar = document.getElementById('bulk-bar');
        const bulkCount = document.getElementById('bulk-count');

        function updateBulkBar() {
            const checkedCount = document.querySelectorAll('.cust-checkbox:checked').length;
            if (checkedCount > 0) {
                bulkBar.style.display = 'flex';
                bulkCount.innerText = checkedCount;
            } else {
                bulkBar.style.display = 'none';
                toggleMoveBox(false);
                togglePartnerBox(false);
            }
        }

        if(checkAll) {
            checkAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = checkAll.checked);
                updateBulkBar();
            });
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                if (!this.checked) checkAll.checked = false;
                if (document.querySelectorAll('.cust-checkbox:checked').length === checkboxes.length) checkAll.checked = true;
                updateBulkBar();
            });
        });

        window.cancelBulkSelection = function() {
            if(checkAll) checkAll.checked = false;
            checkboxes.forEach(cb => cb.checked = false);
            updateBulkBar();
        };
    });

    function toggleMoveBox(show) {
        const box = document.getElementById('move-collector-box');
        const btn = document.getElementById('btn-move-trigger');
        if (!box || !btn) return;
        if (show) togglePartnerBox(false);
        box.style.display = show ? 'flex' : 'none';
        btn.style.display = show ? 'none' : 'inline-block';
    }

    function togglePartnerBox(show) {
        const box = document.getElementById('assign-partner-box');
        const btn = document.getElementById('btn-assign-partner-trigger');
        if (!box || !btn) return;
        if (show) toggleMoveBox(false);
        box.style.display = show ? 'flex' : 'none';
        btn.style.display = show ? 'none' : 'inline-block';
    }

    function submitBulkDelete() {
        const selected = document.querySelectorAll('.cust-checkbox:checked');
        if (selected.length === 0) return;
        
        if (confirm(`PERINGATAN: Anda akan menghapus ${selected.length} pelanggan beserta seluruh data invoice & pembayaran mereka secara permanen. Lanjutkan?`)) {
            const form = document.getElementById('bulk-form-delete');
            form.innerHTML = '';
            selected.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            form.submit();
        }
    }

    function submitBulkMove() {
        const selected = document.querySelectorAll('.cust-checkbox:checked');
        const targetColl = document.getElementById('bulk-target-collector').value;
        
        if (selected.length === 0) return;
        if (!targetColl) {
            alert('Silakan pilih penagih tujuan.');
            return;
        }
        
        if (confirm(`Pindahkan penugasan ${selected.length} pelanggan terpilih?`)) {
            const form = document.getElementById('bulk-form-move');
            form.innerHTML = ''; // basic reset
            
            const targetInput = document.createElement('input');
            targetInput.type = 'hidden';
            targetInput.name = 'target_collector_id';
            targetInput.value = targetColl;
            form.appendChild(targetInput);

            selected.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            form.submit();
        }
    }

    function submitBulkAssignPartner() {
        const selected = document.querySelectorAll('.cust-checkbox:checked');
        const targetPartner = document.getElementById('bulk-target-partner').value;

        if (selected.length === 0) return;
        if (!targetPartner) {
            alert('Silakan pilih mitra tujuan.');
            return;
        }

        if (confirm(`Pindahkan ${selected.length} pelanggan terpilih ke akun mitra ini?`)) {
            const form = document.getElementById('bulk-form-assign-partner');
            form.innerHTML = '';

            const targetInput = document.createElement('input');
            targetInput.type = 'hidden';
            targetInput.name = 'target_partner_id';
            targetInput.value = targetPartner;
            form.appendChild(targetInput);

            selected.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            form.submit();
        }
    }
    </script>

<form id="autoInvoiceForm" method="POST" action="index.php?page=admin_invoices&action=create_auto" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="customer_id" id="inv_customer_id">
    <input type="hidden" name="amount" id="inv_amount">
</form>

<script>
function createInvoice(custId, amount) {
    if(confirm('Buat tagihan otomatis untuk bulan ini?')) {
        document.getElementById('inv_customer_id').value = custId;
        document.getElementById('inv_amount').value = amount;
        document.getElementById('autoInvoiceForm').submit();
    }
}

document.addEventListener("DOMContentLoaded", function() {
    let activeSessions = {};
    let routerIds = [...new Set(Array.from(document.querySelectorAll('.conn-status[data-router]'))
        .map(el => parseInt(el.getAttribute('data-router')))
        .filter(id => id > 0))];

    function checkOnlineStatus() {
        // Always refresh UI for non-API/non-router rows too.
        updateUI();
        if(routerIds.length === 0) return;
        routerIds.forEach(rId => {
            fetch(`index.php?page=router_data&router_id=${rId}&action=pppoe_active`)
                .then(res => res.json())
                .then(data => {
                    activeSessions[rId] = {};
                    if(data && !data.error && Array.isArray(data)) {
                        data.forEach(sess => activeSessions[rId][sess.name] = true);
                    }
                    updateUI();
                })
                .catch(() => {
                    // API error should not leave endless spinner.
                    activeSessions[rId] = {};
                    updateUI();
                });
        });
    }

    function updateUI() {
        document.querySelectorAll('.conn-status[data-router]').forEach(td => {
            let rId = parseInt(td.getAttribute('data-router'));
            let pppoe = td.getAttribute('data-pppoe');
            if(!pppoe) {
                td.innerHTML = '<span style="color:var(--text-secondary); font-size:12px;">Tanpa API</span>';
                return;
            }
            if(!(rId > 0)) {
                td.innerHTML = '<span style="color:var(--text-secondary); font-size:12px;">Router N/A</span>';
                return;
            }
            if(rId > 0 && pppoe !== "") {
                if(activeSessions[rId] && activeSessions[rId][pppoe]) {
                    td.innerHTML = '<span class="badge badge-success" style="padding:4px 8px; font-size:10px;"><i class="fas fa-circle" style="font-size:8px;"></i> Online</span>';
                } else {
                    td.innerHTML = '<span class="badge" style="background:var(--badge-danger-bg); color:var(--danger); border:1px solid var(--badge-danger-border); padding:4px 8px; font-size:10px;"><i class="far fa-circle" style="font-size:8px;"></i> Offline</span>';
                }
            }
        });
    }

    checkOnlineStatus();
    setInterval(checkOnlineStatus, 15000);
});
</script>

<?php elseif ($action === 'create' || $action === 'edit'): 
    if($action === 'edit') {
        $id = intval($_GET['id']);
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $c = $db->query("SELECT * FROM customers WHERE id = $id AND tenant_id = $tenant_id")->fetch();
        
        // Ownership Check
        if ($c) {
            $u_id = $_SESSION['user_id'];
            $u_role = app_scope_role();
            if (!$c) {
                echo "<div class='ui-card p-10 text-center'><h3 class='m-0 text-lg font-bold'>Akses ditolak</h3><p class='mt-1 text-sm text-muted-foreground'>Anda tidak berwenang mengedit data ini.</p><a href='index.php?page=admin_customers' class='ui-btn ui-btn-primary mt-4'>Kembali</a></div>";
                return;
            }
        }
    } else {
        $u_role = app_scope_role();
        $default_type = ($u_role === 'partner') ? 'customer' : ($_GET['type'] ?? 'customer');
        $c = ['type'=>$default_type, 'registration_date'=>date('Y-m-d'), 'billing_date'=>'', 'router_id'=>0, 'pppoe_name'=>'', 'name'=>'', 'address'=>'', 'contact'=>'', 'package_name'=>'', 'monthly_fee'=>'', 'ip_address'=>'', 'area'=>'', 'ppn_active'=>0, 'bhp_active'=>0, 'uso_active'=>0];
    }
?>
<div class="ui-card mx-auto max-w-2xl p-5 sm:p-6">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold"><?= $action === 'edit' ? 'Edit data pelanggan' : 'Tambah pelanggan' ?></h2>
        </div>
        <a href="index.php?page=admin_customers" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>
    <form action="index.php?page=admin_customers&action=<?= $action === 'edit' ? 'update' : 'add' ?>" method="POST" onsubmit="return validateAndSync(event)">
        <?php if($action === 'edit'): ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><?php endif; ?>

        <?php if ($u_role === 'admin'): ?>
        <label class="mb-4 block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Tipe entitas</span>
            <select name="type" id="customer_type_selector" class="form-control" required onchange="togglePkgFields(this.value)">
                <option value="customer" <?= $c['type']=='customer'?'selected':'' ?>>Pelanggan (rumahan)</option>
                <option value="partner" <?= $c['type']=='partner'?'selected':'' ?>>Mitra (bandwidth)</option>
            </select>
        </label>
        <?php else: ?>
            <input type="hidden" name="type" id="customer_type_selector" value="customer">
        <?php endif; ?>
        <label class="mb-4 block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama lengkap / instansi</span>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']) ?>" required>
        </label>
        <label class="mb-4 block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat instalasi</span>
            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($c['address']) ?>">
        </label>
        <label class="mb-4 block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Kontak / WhatsApp</span>
            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($c['contact']) ?>">
        </label>

        <div id="standard-pkg-zone" class="mb-4 flex flex-col gap-4 sm:flex-row" style="<?= $c['type'] == 'partner' ? 'display:none;' : '' ?>">
            <div class="sm:flex-1">
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Pilih paket</label>
                <select name="package_name_select" id="package_selector" class="form-control" onchange="updateFee(this.value)">
                    <option value="">-- Pilih paket --</option>
                    <?php foreach($packages_all as $p): ?>
                        <option value="<?= htmlspecialchars($p['name']) ?>" data-fee="<?= $p['fee'] ?>" <?= $c['package_name'] == $p['name'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-1 text-xs text-muted-foreground">Atur paket di menu Manajemen paket.</div>
            </div>
            <div class="sm:flex-1">
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Biaya bulanan (Rp)</label>
                <input type="number" name="monthly_fee_std" id="monthly_fee_input" class="form-control" value="<?= $c['monthly_fee'] ?>">
            </div>
        </div>

        <div id="custom-pkg-zone" class="mb-4 flex flex-col gap-4 sm:flex-row" style="<?= $c['type'] != 'partner' ? 'display:none;' : '' ?>">
            <div class="sm:flex-1">
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Nama paket (custom)</label>
                <input type="text" name="package_name_custom" class="form-control" value="<?= htmlspecialchars($c['package_name']) ?>" placeholder="Misal: Dedicated 50Mbps">
                <div class="mt-1 text-xs text-muted-foreground">Khusus mitra, tulis manual nama paketnya.</div>
            </div>
            <div class="sm:flex-1">
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Biaya custom (Rp)</label>
                <input type="number" name="monthly_fee_custom" class="form-control" value="<?= $c['monthly_fee'] ?>" placeholder="Sesuai kontrak">
            </div>
        </div>

        <!-- Hidden real fields to sync before submit -->
        <input type="hidden" name="package_name" id="real_package_name" value="<?= htmlspecialchars($c['package_name']) ?>">
        <input type="hidden" name="monthly_fee" id="real_monthly_fee" value="<?= $c['monthly_fee'] ?>">

        <div class="mb-4 grid gap-4 rounded-lg border border-solid border-border p-4">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Router (bawaan)</span>
                <select name="router_id" id="sel_router_id" class="form-control" onchange="loadPPPoE(this.value)">
                    <option value="0">-- Akses manual --</option>
                    <?php
                    $tenant_id = $_SESSION['tenant_id'] ?? 1;
                    $routers = []; try { $routers = $db->query("SELECT * FROM routers WHERE tenant_id = $tenant_id")->fetchAll(); } catch(Exception $e) {}
                    foreach($routers as $r):
                    ?>
                    <option value="<?= $r['id'] ?>" <?= ($c['router_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Akun PPPoE (secret)</span>
                <select name="pppoe_name" id="sel_pppoe_name" class="form-control" data-prev="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>">
                    <option value="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>"><?= htmlspecialchars($c['pppoe_name'] ?: '-- Pilih Router --') ?></option>
                </select>
            </label>
            <?php if ($u_role === 'admin'): ?>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Petugas penagih (collector)</span>
                <select name="collector_id" class="form-control">
                    <option value="0">-- Pilih penagih --</option>
                    <?php
                    $tenant_id = $_SESSION['tenant_id'] ?? 1;
                    $colls = $db->query("SELECT id, name FROM users WHERE role = 'collector' AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
                    foreach($colls as $coll):
                    ?>
                    <option value="<?= $coll['id'] ?>" <?= ($c['collector_id'] ?? 0) == $coll['id'] ? 'selected' : '' ?>><?= htmlspecialchars($coll['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php else: ?>
                <input type="hidden" name="collector_id" value="0">
            <?php endif; ?>
        </div>

        <?php if($u_role === 'admin'): ?>
        <div class="mb-4">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">IP address & area penagihan</span>
            <div class="grid gap-3 sm:grid-cols-[1fr_2fr]">
                <input type="text" name="ip_address" class="form-control" value="<?= htmlspecialchars($c['ip_address']) ?>" placeholder="IP (192.168.x.x)">
                <select name="area" class="form-control">
                    <option value="">-- Pilih area --</option>
                    <?php foreach($areas_all as $a): ?>
                        <option value="<?= htmlspecialchars($a['name']) ?>" <?= $c['area'] == $a['name'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if(!empty($c['area']) && !in_array($c['area'], array_column($areas_all, 'name'))): ?>
                        <option value="<?= htmlspecialchars($c['area']) ?>" selected><?= htmlspecialchars($c['area']) ?> (Legacy)</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="mt-1 text-xs text-muted-foreground">Atur daftar area di menu Manajemen area.</div>
        </div>
        <?php else: ?>
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">IP address</span>
                <input type="text" name="ip_address" class="form-control" value="<?= htmlspecialchars($c['ip_address']) ?>" placeholder="IP (192.168.x.x)">
            </label>
            <input type="hidden" name="area" value="">
        <?php endif; ?>

        <div class="mb-4">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Aktivasi fitur tambahan</span>
            <div class="mt-1 flex flex-wrap gap-2">
                <label class="inline-flex min-w-[110px] cursor-pointer items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                    <input type="checkbox" name="ppn_active" value="1" <?= !empty($c['ppn_active']) ? 'checked' : '' ?>>
                    <span>PPN</span>
                </label>
                <label class="inline-flex min-w-[110px] cursor-pointer items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                    <input type="checkbox" name="bhp_active" value="1" <?= !empty($c['bhp_active']) ? 'checked' : '' ?>>
                    <span>BHP</span>
                </label>
                <label class="inline-flex min-w-[110px] cursor-pointer items-center gap-2 rounded-md border border-solid border-border px-3 py-2 text-sm">
                    <input type="checkbox" name="uso_active" value="1" <?= !empty($c['uso_active']) ? 'checked' : '' ?>>
                    <span>USO</span>
                </label>
            </div>
        </div>

        <?php if ($u_role === 'admin'): ?>
        <!-- NEW: Infra & GIS Section -->
        <div class="mb-4 rounded-lg border border-solid border-border p-4">
            <div class="mb-3 text-sm font-semibold">Infra & GIS jaringan</div>
            <div class="mb-4 grid gap-4 sm:grid-cols-[2fr_1fr]">
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Sumber koneksi (ODP/switch/radio)</label>
                    <select name="odp_id" class="form-control">
                        <option value="0">-- Pilih sumber --</option>
                        <?php
                        $all_assets = $db->query("SELECT id, name, type, total_ports FROM infrastructure_assets ORDER BY type DESC, name ASC")->fetchAll();
                        foreach($all_assets as $o):
                            $tenant_id = $_SESSION['tenant_id'] ?? 1;
                            $used = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ? AND id != ? AND tenant_id = ?");
                            $used->execute([$o['id'], $c['id'] ?? 0, $tenant_id]);
                            $count = $used->fetchColumn();
                        ?>
                        <option value="<?= $o['id'] ?>" <?= ($c['odp_id'] ?? 0) == $o['id'] ? 'selected' : '' ?>>
                            <?= $o['type'] ?>: <?= htmlspecialchars($o['name']) ?> (<?= $count ?>/<?= $o['total_ports'] ?> Port)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Port ODP</label>
                    <input type="number" name="odp_port" class="form-control" value="<?= $c['odp_port'] ?? '' ?>" placeholder="1-16">
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Latitude</label>
                    <input type="text" name="lat" class="form-control" value="<?= htmlspecialchars($c['lat'] ?? '') ?>" placeholder="-6.xxx">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Longitude</label>
                    <input type="text" name="lng" class="form-control" value="<?= htmlspecialchars($c['lng'] ?? '') ?>" placeholder="106.xxx">
                </div>
            </div>
            <div class="mt-2 text-xs text-muted-foreground">
                Tip: gunakan Google Maps (klik kanan di lokasi > ambil koordinat) atau buka <a href="index.php?page=admin_map" target="_blank" class="font-semibold text-primary">Peta jaringan</a> untuk referensi.
            </div>
        </div>
        <?php endif; ?>

        <div class="mb-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal registrasi</span>
                <input type="date" name="registration_date" class="form-control" value="<?= htmlspecialchars($c['registration_date']) ?>" required>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal tagihan (1-28)</span>
                <input type="number" name="billing_date" class="form-control" min="1" max="28" value="<?= htmlspecialchars($c['billing_date']) ?>" placeholder="1 s/d 28" required>
            </label>
        </div>

        <?php
        // Summary Tunggakan jika sedang Edit
        if($action === 'edit'):
            $unpaid_stats = $db->query("SELECT COUNT(*) as count, SUM(amount) as total FROM invoices WHERE customer_id = {$c['id']} AND status = 'Belum Lunas'")->fetch();
            if($unpaid_stats['count'] > 0):
        ?>
            <div class="mb-4 rounded-lg border border-solid border-danger/40 bg-danger-soft p-4 text-sm">
                <div class="font-semibold text-danger">Status tunggakan saat ini</div>
                <div class="mt-1">
                    Pelanggan memiliki <strong><?= $unpaid_stats['count'] ?> bulan</strong> tunggakan belum lunas
                    dengan total <strong class="tabular-nums">Rp <?= number_format($unpaid_stats['total'], 0, ',', '.') ?></strong>.
                </div>
            </div>
        <?php
            endif;
        endif;
        ?>

        <div class="mb-4 rounded-lg border border-solid border-border p-4">
            <div class="flex cursor-pointer items-center justify-between text-sm font-semibold" onclick="toggleArrears()">
                <span><?= $action === 'edit' ? 'Tambah tunggakan manual' : 'Tunggakan migrasi (opsional)' ?></span>
                <i class="fas fa-chevron-down text-muted-foreground"></i>
            </div>
            <div id="arrearsPanel" class="mt-4" style="display:none;">
                <p class="m-0 mb-2.5 text-xs text-muted-foreground">
                    <?= $action === 'edit' ? 'Gunakan ini untuk memasukkan tunggakan tambahan yang belum tercatat.' : 'Gunakan ini untuk memasukkan data hutang lama pelanggan.' ?>
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <input type="number" name="arrears_months" id="arr_m" class="form-control" min="0" placeholder="Jml bulan" oninput="prevArr()">
                    <input type="number" name="arrears_amount" id="arr_a" class="form-control" min="0" placeholder="Nominal/bln (kosongkan = biaya bulanan)" oninput="prevArr()">
                </div>
                <div id="arrPrev" class="mt-2.5 text-xs text-danger" style="display:none;"></div>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-5">
            <a href="index.php?page=admin_customers" class="ui-btn ui-btn-outline">Batal</a>
            <button type="submit" class="ui-btn ui-btn-primary">Simpan</button>
        </div>
    </form>
</div>

<script>
function updateFee(packageName) {
    const selector = document.getElementById('package_selector');
    const selectedOption = selector.options[selector.selectedIndex];
    const fee = selectedOption.getAttribute('data-fee');
    if(fee) {
        document.getElementById('monthly_fee_input').value = fee;
    }
}

function togglePkgFields(type) {
    const stdZone = document.getElementById('standard-pkg-zone');
    const customZone = document.getElementById('custom-pkg-zone');
    if(type === 'partner') {
        stdZone.style.display = 'none';
        customZone.style.display = 'flex';
    } else {
        stdZone.style.display = 'flex';
        customZone.style.display = 'none';
    }
}

function syncPackageData() {
    // This is now inside validateAndSync
}

function validateAndSync(e) {
    const type = document.getElementById('customer_type_selector').value;
    const realPkg = document.getElementById('real_package_name');
    const realFee = document.getElementById('real_monthly_fee');

    let pkgVal = '';
    let feeVal = '';

    if(type === 'partner') {
        pkgVal = document.querySelector('[name=package_name_custom]').value;
        feeVal = document.querySelector('[name=monthly_fee_custom]').value;
    } else {
        pkgVal = document.querySelector('[name=package_name_select]').value;
        feeVal = document.querySelector('[name=monthly_fee_std]').value;
    }

    if(!pkgVal || !feeVal || feeVal <= 0) {
        alert("Mohon lengkapi Nama Paket dan Biaya Bulanan!");
        return false;
    }

    realPkg.value = pkgVal;
    realFee.value = feeVal;
    return true;
}

function toggleArrears() { let p = document.getElementById('arrearsPanel'); p.style.display = p.style.display === 'none' ? 'block' : 'none'; }
function prevArr() {
    let m = document.getElementById('arr_m').value;
    let a = document.getElementById('arr_a').value || document.querySelector('[name=monthly_fee]').value;
    let d = document.getElementById('arrPrev');
    if(m > 0) {
        d.innerHTML = `Menciptakan ${m} tagihan tunggakan (@ Rp ${parseInt(a).toLocaleString()}).`;
        d.style.display = 'block';
    } else d.style.display = 'none';
}
function loadPPPoE(rId) {
    let s = document.getElementById('sel_pppoe_name');
    if(!rId || rId == 0) { s.innerHTML = '<option value="">-- Manual --</option>'; return; }
    s.innerHTML = '<option>Memuat...</option>';
    fetch(`index.php?page=router_data&router_id=${rId}&action=pppoe_secrets`)
        .then(r => r.json()).then(data => {
            s.innerHTML = '<option value="">-- Pilih Akun --</option>';
            let prev = s.getAttribute('data-prev');
            data.forEach(item => {
                let o = document.createElement('option'); o.value = item.name; o.textContent = item.name;
                if(item.name === prev) o.selected = true;
                s.appendChild(o);
            });
        });
}
document.addEventListener("DOMContentLoaded", () => {
    let rId = document.getElementById('sel_router_id')?.value;
    if(rId > 0) loadPPPoE(rId);
});
</script>

<?php elseif ($action === 'import_view'): ?>
<div class="ui-card mx-auto max-w-3xl p-5 sm:p-6">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold">Import data pelanggan</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Unggah file CSV atau tempel data dari Excel/Sheets.</p>
        </div>
        <a href="index.php?page=admin_customers&action=download_template" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-download"></i> Download template CSV</a>
    </div>

    <div class="mb-5 rounded-lg border border-solid border-border p-4 text-sm leading-relaxed">
        <div class="font-semibold">Persyaratan format</div>
        <ol class="m-0 mt-1 list-decimal pl-5 text-muted-foreground">
            <li>Gunakan file format <strong class="text-foreground">.csv</strong> atau paste dari Excel/Sheets.</li>
            <li>Sistem akan otomatis mensinkronisasi nama paket &amp; area baru.</li>
            <li>ID pelanggan akan dibuatkan otomatis secara unik oleh sistem.</li>
        </ol>
    </div>

    <div class="collector-assign-box mb-5 flex flex-col gap-2 rounded-lg border border-solid border-border p-4 sm:flex-row sm:items-center sm:gap-4">
        <label for="common_collector_id" class="m-0 whitespace-nowrap text-xs font-medium text-muted-foreground">Tugaskan data impor ke penagih (opsional)</label>
        <?php
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $colls_import = $db->query("SELECT id, name FROM users WHERE role = 'collector' AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
        ?>
        <select id="common_collector_id" class="form-control sm:max-w-xs" onchange="syncCollector(this.value)">
            <option value="0">-- Biarkan tanpa penagih --</option>
            <?php foreach($colls_import as $cl): ?>
                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <style>
        .tab-btn.active { background: #FFFFFF; color: #172026; font-weight: 600; box-shadow: 0 1px 2px rgba(23,32,38,.05), 0 1px 0 rgba(23,32,38,.02); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>

    <div class="import-tabs mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1">
        <button type="button" class="tab-btn active cursor-pointer rounded-sm border-0 bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground" onclick="switchTab('file')"><i class="fas fa-file-csv"></i> Upload file CSV</button>
        <button type="button" class="tab-btn cursor-pointer rounded-sm border-0 bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground" onclick="switchTab('paste')"><i class="fas fa-paste"></i> Paste data Excel</button>
    </div>

    <!-- Mode 1: File Upload -->
    <div id="tab-file" class="tab-content active">
        <form action="index.php?page=admin_customers&action=import_file" method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>
            <input type="hidden" name="collector_id" class="sync-collector" value="0">
            <div class="rounded-lg border-2 border-dashed border-border p-8 text-center" id="drop-zone" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--glass-border)'">
                <h4 class="m-0 mb-1 text-[15px] font-bold">Pilih file CSV</h4>
                <p class="m-0 mb-4 text-xs text-muted-foreground">Upload file format .csv sesuai template untuk hasil maksimal.</p>
                <input type="file" name="csv_file" id="csv_input" accept=".csv" required style="display: none;" onchange="updateFileName(this)">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('csv_input').click()">Pilih file</button>
                <div id="file-name" class="mt-4 text-sm font-semibold text-signal" style="display: none;"></div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <a href="index.php?page=admin_customers" class="ui-btn ui-btn-outline">Batal</a>
                <button type="submit" class="ui-btn ui-btn-primary">Upload &amp; proses</button>
            </div>
        </form>
    </div>

    <!-- Mode 2: Paste Data -->
    <div id="tab-paste" class="tab-content">
        <form action="index.php?page=admin_customers&action=import_paste" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="collector_id" class="sync-collector" value="0">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Salin &amp; tempel data (tab-separated)</span>
                <textarea name="paste_data" class="form-control font-mono text-xs" rows="10" placeholder="customer [Tab] Budi [Tab] Alamat..." required></textarea>
            </label>
            <div class="mt-6 flex justify-end gap-2">
                <a href="index.php?page=admin_customers" class="ui-btn ui-btn-outline">Batal</a>
                <button type="submit" class="ui-btn ui-btn-primary">Mulai import</button>
            </div>
        </form>
    </div>
</div>

<script>
    function switchTab(type) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + type).classList.add('active');
    }

    function updateFileName(input) {
        let nameEl = document.getElementById('file-name');
        if(input.files && input.files[0]) {
            nameEl.innerText = "Terpilih: " + input.files[0].name;
            nameEl.style.display = 'block';
            document.getElementById('drop-zone').style.background = 'rgba(var(--primary-rgb), 0.05)';
        }
    }

    function syncCollector(val) {
        document.querySelectorAll('.sync-collector').forEach(el => el.value = val);
    }
</script>

<?php elseif ($action === 'import_preview'): 
    $pending = $_SESSION['pending_import'] ?? [];
    $map = $_SESSION['pending_mapping'] ?? ['type' => 0, 'name' => 1, 'address' => 2, 'contact' => 3, 'package' => 4, 'fee' => 5, 'ip' => 6, 'reg_date' => 7, 'bill_date' => 8, 'area' => 9];
    $hasMapping = isset($_SESSION['pending_mapping']);
?>
<div class="ui-card mx-auto max-w-6xl p-5 sm:p-6">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold">Preview &amp; verifikasi kolom</h2>
        </div>
        <span class="ui-badge ui-badge-muted"><?= count($pending) ?> calon pelanggan</span>
    </div>

    <?php if ($hasMapping): ?>
        <div class="mb-5 rounded-lg border border-solid border-border p-4 text-sm">
            <span class="font-semibold text-signal">Sistem mendeteksi judul kolom (header).</span>
            Data akan diproses berdasarkan nama kolom yang ditemukan, bukan berdasarkan urutan template standar.
        </div>
    <?php else: ?>
        <div class="mb-5 rounded-lg border border-solid border-accent/50 bg-accent-soft p-4 text-sm">
            <span class="font-semibold text-accent-ink">Judul kolom tidak ditemukan.</span>
            Sistem akan menggunakan urutan template standar EinvaBill. Pastikan data Anda sudah berurutan dengan benar.
        </div>
    <?php endif; ?>

    <div class="table-container max-h-[500px] overflow-auto rounded-lg border border-solid border-border">
        <table class="w-full border-collapse text-xs">
            <thead class="sticky top-0 z-10 bg-card">
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['type']+1 ?></div>Tipe</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['name']+1 ?></div>Nama</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['address']+1 ?></div>Alamat</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['contact']+1 ?></div>WhatsApp</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['package']+1 ?></div>Paket</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['fee']+1 ?></div>Biaya</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['ip']+1 ?></div>IP address</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['reg_date']+1 ?></div>Tgl daftar</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['bill_date']+1 ?></div>Jatuh tempo</th>
                    <th class="border-b border-solid border-border px-3 py-2.5 font-semibold"><div class="text-[9px] font-medium">Kolom <?= $map['area']+1 ?></div>Area</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($pending)): ?>
                    <tr><td colspan="10" class="px-5 py-10 text-center text-sm text-muted-foreground">Tidak ada data yang terdeteksi.</td></tr>
                <?php else: ?>
                    <?php foreach($pending as $row): ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-3 py-2.5 text-center"><span class="ui-badge ui-badge-muted"><?= htmlspecialchars($row[$map['type']] ?? '-') ?></span></td>
                        <td class="px-3 py-2.5 font-semibold"><?= htmlspecialchars($row[$map['name']] ?? '-') ?></td>
                        <td class="px-3 py-2.5"><?= htmlspecialchars($row[$map['address']] ?? '-') ?></td>
                        <td class="px-3 py-2.5 font-mono"><?= htmlspecialchars($row[$map['contact']] ?? '-') ?></td>
                        <td class="px-3 py-2.5"><?= htmlspecialchars($row[$map['package']] ?? '-') ?></td>
                        <td class="px-3 py-2.5 font-semibold tabular-nums">Rp <?= number_format(floatval(preg_replace('/[^0-9]/', '', $row[$map['fee']] ?? 0)), 0, ',', '.') ?></td>
                        <td class="px-3 py-2.5 font-mono"><?= htmlspecialchars($row[$map['ip']] ?? '-') ?></td>
                        <td class="px-3 py-2.5"><?= htmlspecialchars($row[$map['reg_date']] ?? '-') ?></td>
                        <td class="px-3 py-2.5 text-center"><?= htmlspecialchars($row[$map['bill_date']] ?? '-') ?></td>
                        <td class="px-3 py-2.5"><?= htmlspecialchars($row[$map['area']] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-5">
        <form action="index.php?page=admin_customers&action=import_cancel" method="POST">
<?= csrf_field() ?>
            <button type="submit" class="ui-btn ui-btn-outline">Batalkan</button>
        </form>
        <?php if(!empty($pending)): ?>
            <form action="index.php?page=admin_customers&action=import_confirm" method="POST">
<?= csrf_field() ?>
                <button type="submit" class="ui-btn ui-btn-primary">
                    <i class="fas fa-check"></i> Sudah sesuai, impor sekarang
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'details'): 
    $id = intval($_GET['id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $c = $db->query("SELECT * FROM customers WHERE id = $id AND tenant_id = $tenant_id")->fetch();
    
    if(!$c) { 
        echo "<div class='ui-card p-10 text-center'><h3 class='m-0 text-lg font-bold'>Akses ditolak</h3><p class='mt-1 text-sm text-muted-foreground'>Data tidak ditemukan atau Anda tidak berwenang melihat data ini.</p><a href='index.php?page=admin_customers' class='ui-btn ui-btn-primary mt-4'>Kembali</a></div>";
        return; 
    }
    
    // Riwayat Pembayaran (Lunas)
    $history = $db->query("
        SELECT p.*, i.due_date, u.name as receiver_name 
        FROM payments p 
        JOIN invoices i ON p.invoice_id = i.id 
        LEFT JOIN users u ON p.received_by = u.id
        WHERE i.customer_id = $id AND p.tenant_id = $tenant_id
        ORDER BY p.payment_date DESC
    ")->fetchAll();

    // Tagihan Belum Lunas
    $unpaid_invoices = $db->query("
        SELECT * FROM invoices 
        WHERE customer_id = $id AND status = 'Belum Lunas' AND tenant_id = $tenant_id
        ORDER BY due_date DESC
    ")->fetchAll();

    // Total Tunggakan / Kekurangan
    $unpaid_total = $db->query("SELECT SUM(amount) FROM invoices WHERE customer_id = $id AND status = 'Belum Lunas' AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
    
    // Alerts/Messages
    $msg = $_GET['msg'] ?? '';
    $success = $_GET['success'] ?? '';
?>

<?php if($msg === 'invoice_created'): ?>
    <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> Tagihan manual untuk bulan ini telah diterbitkan.</div>
<?php elseif($msg === 'invoice_itemized_created'): ?>
    <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> Tagihan rincian (add-ons) telah diterbitkan.</div>
<?php elseif($msg === 'bulk_payment_success' || $success === 'bulk'): ?>
    <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> Pembayaran untuk beberapa bulan telah tercatat.</div>
<?php endif; ?>

<div class="flex flex-col gap-5">
    <!-- Customer Info Brief -->
    <div class="ui-card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="index.php?page=admin_customers" class="ui-btn ui-btn-sm ui-btn-outline mb-4"><i class="fas fa-arrow-left"></i> Kembali</a>
                <h2 class="m-0 text-xl font-bold sm:text-2xl"><?= htmlspecialchars($c['name']) ?></h2>
                <div class="mt-1 font-mono text-sm text-muted-foreground"><?= htmlspecialchars($c['customer_code']) ?></div>
            </div>
            <div class="text-right">
                <span class="ui-badge <?= $c['type']=='customer'?'ui-badge-muted':'ui-badge-accent' ?>"><?= $c['type']=='partner' ? 'Mitra' : 'Pelanggan' ?></span>
                <div class="mt-2 text-lg font-bold tabular-nums">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?> <span class="text-sm font-medium text-muted-foreground">/ bulan</span></div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-lg border border-solid border-border p-4">
                <div class="text-xs font-medium text-muted-foreground">Info layanan</div>
                <div class="mt-1 font-semibold"><?= htmlspecialchars($c['package_name']) ?></div>
                <div class="mt-0.5 text-[13px] text-muted-foreground">Tagihan: tanggal <?= $c['billing_date'] ?></div>
            </div>
            <div class="rounded-lg border border-solid border-border p-4">
                <div class="text-xs font-medium text-muted-foreground">Kontak &amp; alamat</div>
                <div class="mt-1 font-semibold"><?= htmlspecialchars($c['contact']) ?></div>
                <div class="mt-0.5 text-[13px] text-muted-foreground"><?= htmlspecialchars($c['area']) ?></div>
            </div>
            <!-- New Arrears Card -->
            <div class="rounded-lg border border-solid p-4 <?= $unpaid_total > 0 ? 'border-danger/40' : 'border-border' ?>">
                <div class="text-xs font-medium text-muted-foreground">
                    <?= $c['type'] === 'partner' ? 'Kekurangan mitra' : 'Sisa tagihan' ?>
                </div>
                <div class="mt-1 text-lg font-extrabold tabular-nums <?= $unpaid_total > 0 ? 'text-danger' : 'text-signal' ?>">
                    Rp <?= number_format($unpaid_total, 0, ',', '.') ?>
                </div>
                <div class="mt-0.5 text-xs text-muted-foreground">
                    <?= $unpaid_total > 0 ? 'Segera lakukan penagihan' : 'Semua tagihan lunas' ?>
                </div>
            </div>
        </div>
    </div>

    <div class="details-grid-container grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <!-- Riwayat Pembayaran -->
        <!-- Section Penagihan -->
        <div class="ui-card overflow-hidden">
            <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Tagihan belum lunas</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold">Periode</th>
                            <th class="px-4 py-2.5 font-semibold">Nominal</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($unpaid_invoices)): ?>
                            <tr><td colspan="3" class="px-5 py-8 text-center text-sm text-muted-foreground">Semua tagihan lunas.</td></tr>
                        <?php endif; ?>
                        <?php foreach($unpaid_invoices as $ui): ?>
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 font-semibold"><?= date('M Y', strtotime($ui['due_date'])) ?></td>
                                <td class="px-4 py-3 font-semibold tabular-nums text-danger">Rp <?= number_format($ui['amount'] - ($ui['discount'] ?? 0), 0, ',', '.') ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap justify-end gap-1.5">
                                            <?php 
                                                // Fetch template settings (Tenant Aware)
                                                if (!isset($wa_tpl)) {
                                                    $u_id_me = $_SESSION['user_id'];
                                                    $me_user = $db->query("SELECT wa_template, wa_template_paid FROM users WHERE id = $u_id_me")->fetch();
                                                    $wa_tpl = !empty($me_user['wa_template']) ? $me_user['wa_template'] : ($site_settings['wa_template'] ?? "Halo {nama}, tagihan {tagihan} ({bulan}) jatuh tempo pada {jatuh_tempo}.");
                                                    $base_url_me = !empty($site_settings['site_url']) ? $site_settings['site_url'] : get_app_url();
                                                    $bank_acc_me = $site_settings['bank_account'] ?? '';
                                                }

                                                $wa_raw = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $c['contact']));
                                                $mon_label = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                                $inv_month = $mon_label[intval(date('m', strtotime($ui['due_date']))) - 1] . ' ' . date('Y', strtotime($ui['due_date']));
                                                $cust_id_display = $c['customer_code'] ?: str_pad($c['id'], 5, "0", STR_PAD_LEFT);
                                                $portal_link = $base_url_me . "/index.php?page=customer_portal&code=" . $cust_id_display;
                                                
                                                // Variable Replacement
                                                $wa_msg = parse_wa_template($wa_tpl, [
                                                    'name' => $c['name'],
                                                    'id_cust' => $cust_id_display,
                                                    'package' => $c['package_name'],
                                                    'period' => $inv_month,
                                                    'tagihan' => $ui['amount'] - ($ui['discount'] ?? 0),
                                                    'due_date' => date('d/m/Y', strtotime($ui['due_date'])),
                                                    'rekening' => trim($bank_acc_me),
                                                    'tunggakan' => $unpaid_total - ($ui['amount'] - ($ui['discount'] ?? 0)),
                                                    'total_payment' => $unpaid_total,
                                                    'portal_link' => $portal_link
                                                ]);
                                                $wa_fallback = "https://api.whatsapp.com/send?phone=$wa_raw&text=" . urlencode($wa_msg);
                                            ?>
                                        <button onclick="sendWAGateway('<?= $wa_raw ?>', <?= htmlspecialchars(json_encode($wa_msg)) ?>, '<?= $wa_fallback ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim pengingat WA"><i class="fab fa-whatsapp"></i></button>
                                        <a data-method="post" href="index.php?page=admin_invoices&action=mark_paid&id=<?= $ui['id'] ?>&ref=customer_details&cust_id=<?= $id ?>" class="ui-btn ui-btn-sm ui-btn-primary" onclick="return confirm('Tandai tagihan ini Lunas?')"><i class="fas fa-check"></i> Bayar</a>
                                        <button onclick="CustomersPage.showEditInvoice(<?= $ui['id'] ?>, <?= $ui['amount'] ?>, <?= $ui['discount'] ?? 0 ?>, '<?= $ui['due_date'] ?>')" class="ui-btn ui-btn-sm ui-btn-outline btn-edit-invoice" title="Edit" data-inv-id="<?= $ui['id'] ?>" data-inv-amount="<?= $ui['amount'] ?>" data-inv-discount="<?= $ui['discount'] ?? 0 ?>" data-inv-date="<?= $ui['due_date'] ?>"><i class="fas fa-edit"></i></button>
                                        <a data-method="post" href="index.php?page=admin_invoices&action=delete&id=<?= $ui['id'] ?>&ref=customer_details&cust_id=<?= $id ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus tagihan ini?')" title="Hapus"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="border-b border-t border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Riwayat pembayaran (lunas)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold">Bulan tagihan</th>
                            <th class="px-4 py-2.5 font-semibold">Nominal</th>
                            <th class="px-4 py-2.5 font-semibold">Tanggal bayar</th>
                            <th class="px-4 py-2.5 font-semibold">Diterima oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($history)): ?>
                            <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-muted-foreground">Belum ada riwayat pembayaran.</td></tr>
                        <?php endif; ?>
                        <?php foreach($history as $h): ?>
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 font-semibold"><?= date('F Y', strtotime($h['due_date'])) ?></td>
                                <td class="px-4 py-3 font-semibold tabular-nums text-signal">Rp <?= number_format($h['amount'], 0, ',', '.') ?></td>
                                <td class="px-4 py-3 tabular-nums"><?= date('d/m/Y H:i', strtotime($h['payment_date'])) ?></td>
                                <td class="px-4 py-3 text-[13px]"><?= htmlspecialchars($h['receiver_name'] ?: 'System') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex flex-col gap-5">
        <!-- Section Bayar Banyak Bulan -->
        <div class="ui-card p-5">
            <h3 class="m-0 text-[15px] font-bold">Bayar banyak bulan</h3>
            <p class="m-0 mb-4 mt-1 text-xs text-muted-foreground">Gunakan fitur ini jika pelanggan ingin membayar untuk bulan ini dan bulan-bulan berikutnya sekaligus secara manual.</p>

            <form action="index.php?page=admin_customers&action=bulk_pay" method="POST">
<?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= $id ?>">
                <input type="hidden" name="amount_per_month" value="<?= $c['monthly_fee'] ?>">

                <?php if (($_SESSION['user_role'] ?? '') === 'admin'): $pay_accounts = cash_company_accounts($db, (int)($_SESSION['tenant_id'] ?? 1)); $pay_last = intval($_SESSION['cash_last_account'] ?? 0); ?>
                <label class="mb-4 block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Uang masuk ke</span>
                    <select name="account_id" class="form-control">
                        <?php foreach ($pay_accounts as $pa): ?><option value="<?= intval($pa['id']) ?>" <?= ($pay_last ? $pay_last === intval($pa['id']) : $pa['is_default']) ? 'selected' : '' ?>><?= htmlspecialchars($pa['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>

                <label class="mb-4 block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Jumlah bulan</span>
                    <div class="flex items-center gap-2.5">
                        <input type="number" name="num_months" class="form-control text-center text-lg font-bold tabular-nums" value="1" min="1" max="12" required>
                        <span class="text-sm font-medium">bulan</span>
                    </div>
                </label>

                <div class="mb-4 rounded-lg border border-solid border-border p-4">
                    <div class="text-xs font-medium text-muted-foreground">Estimasi total</div>
                    <div class="mt-1 text-xl font-extrabold tabular-nums" id="bulk_total_display">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></div>
                </div>

                <button type="submit" class="ui-btn ui-btn-primary w-full" onclick="return confirm('Proses pembayaran banyak bulan untuk pelanggan ini?')">
                    <i class="fas fa-check-circle"></i> Proses bayar
                </button>
            </form>
        </div>

        <!-- Section Tagihan Khusus / Add-ons -->
        <div class="ui-card p-5">
            <h3 class="m-0 text-[15px] font-bold">Tagihan khusus / add-ons</h3>
            <p class="m-0 mb-4 mt-1 text-xs text-muted-foreground">Gunakan ini untuk membuat tagihan dengan rincian item (biaya bulanan + extra).</p>

            <form action="index.php?page=admin_invoices&action=create_itemized" method="POST" id="itemizedForm">
<?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= $id ?>">

                <label class="mb-4 block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Jatuh tempo</span>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                </label>

                <div id="itemsContainer">
                    <div class="mb-2.5 grid grid-cols-[1fr_140px_40px] items-center gap-2.5">
                        <input type="text" name="item_desc[]" class="form-control" value="Biaya Langganan Bulanan" placeholder="Deskripsi" required>
                        <input type="number" name="item_amount[]" class="form-control item-amount" value="<?= $c['monthly_fee'] ?>" placeholder="Nominal" required>
                        <span></span>
                    </div>
                </div>

                <button type="button" class="ui-btn ui-btn-sm ui-btn-outline mb-4" onclick="CustomersPage.addItemRow()">
                    <i class="fas fa-plus"></i> Tambah item
                </button>

                <div class="mb-3 rounded-lg border border-solid border-border p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-xs font-medium text-muted-foreground">Subtotal</div>
                        <div class="font-semibold tabular-nums" id="itemized_subtotal_display">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></div>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Potongan / diskon (Rp)</span>
                        <input type="number" name="invoice_discount" class="form-control tabular-nums" value="0" oninput="updateItemizedTotal()">
                    </label>
                </div>

                <div class="mb-4 flex items-center justify-between rounded-lg border border-solid border-border bg-muted p-4">
                    <div class="text-xs font-semibold text-muted-foreground">Total akhir</div>
                    <div class="text-xl font-extrabold tabular-nums" id="itemized_total_display">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></div>
                </div>

                <button type="submit" class="ui-btn ui-btn-primary w-full">
                    <i class="fas fa-file-invoice-dollar"></i> Terbitkan tagihan
                </button>
            </form>
        </div>
        </div>
    </div>
</div>

<script>
window.CustomersPage = (function(){
    function addItemRow() {
        const container = document.getElementById('itemsContainer');
        const div = document.createElement('div');
        div.style.display = 'grid';
        div.style.gridTemplateColumns = '1fr 140px 40px';
        div.style.gap = '10px';
        div.style.marginBottom = '10px';
        div.style.alignItems = 'center';
        div.innerHTML = `
            <input type="text" name="item_desc[]" class="form-control" placeholder="Biaya Lainnya..." required>
            <input type="number" name="item_amount[]" class="form-control item-amount" value="0" placeholder="Nominal" required>
            <button type="button" class="btn btn-sm btn-danger" onclick="CustomersPage.removeRow(this)" style="padding:8px;"><i class="fas fa-times"></i></button>
        `;
        container.appendChild(div);
        // Add event listener to new input
        const amt = div.querySelector('.item-amount'); if (amt) amt.addEventListener('input', updateItemizedTotal);
    }
    function removeRow(btn) { const row = btn.closest('div'); if (row) row.remove(); updateItemizedTotal(); }
    function updateItemizedTotal() {
        const amounts = document.querySelectorAll('.item-amount');
        const discountInput = document.querySelector('input[name="invoice_discount"]');
        const discount = parseInt(discountInput ? discountInput.value : 0) || 0;
        let subtotal = 0;
        amounts.forEach(input => { subtotal += parseInt(input.value) || 0; });
        let total = subtotal - discount;
        const formattedSub = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(subtotal).replace('IDR', 'Rp');
        const formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(total).replace('IDR', 'Rp');
        const subEl = document.getElementById('itemized_subtotal_display'); if (subEl) subEl.innerText = formattedSub;
        const totEl = document.getElementById('itemized_total_display'); if (totEl) totEl.innerText = formattedTotal;
    }
    function init(){
        document.querySelectorAll('.item-amount').forEach(i => i.addEventListener('input', updateItemizedTotal));
        // ensure displays are correct
        updateItemizedTotal();
    }
    return { addItemRow, removeRow, updateItemizedTotal, init };
})();

document.addEventListener('DOMContentLoaded', function(){ try{ if(window.CustomersPage) window.CustomersPage.init(); }catch(e){} });
</script>

<script>
document.querySelector('input[name="num_months"]').addEventListener('input', function() {
    const months = parseInt(this.value) || 0;
    const fee = <?= $c['monthly_fee'] ?>;
    const total = months * fee;
    const formatted = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(total).replace('IDR', 'Rp');
    document.getElementById('bulk_total_display').innerText = formatted;
});
</script>

<?php endif; ?>

<!-- TR-069 Monitor Modal -->
<div id="tr069Modal" class="modal fixed inset-0 z-[1001] overflow-auto bg-black/50 p-4" style="display:none;">
    <div class="ui-card relative mx-auto my-[5%] w-full max-w-xl p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Monitoring ONT (TR-069)</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('tr069Modal').style.display='none'" aria-label="Tutup">&times;</button>
        </div>
        <div id="tr069-content">
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">
                <i class="fas fa-circle-notch fa-spin"></i>
                <p class="m-0 mt-3">Menghubungkan ke server ACS...</p>
            </div>
        </div>
    </div>
</div>


<!-- Modal Edit Invoice -->
<div id="editInvoiceModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 id="editTitle" class="m-0 text-lg font-bold">Edit tagihan</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="CustomersPage.hideEditInvoice()" aria-label="Tutup">&times;</button>
        </div>
        <form action="index.php?page=admin_invoices&action=edit_post" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="editInvId">
            <input type="hidden" name="ref" value="customer_details">
            <input type="hidden" name="cust_id" value="<?= $id ?>">
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Nominal tagihan (Rp)</span>
                <input type="number" name="amount" id="editInvAmount" class="form-control text-lg font-bold tabular-nums" required>
            </label>
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Potongan / restitusi (Rp)</span>
                <input type="number" name="discount" id="editInvDiscount" class="form-control tabular-nums" value="0">
            </label>
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Jatuh tempo</span>
                <input type="date" name="due_date" id="editInvDate" class="form-control" required>
            </label>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="CustomersPage.hideEditInvoice()">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
if (!window.CustomersPage) window.CustomersPage = {};
(function(ns){
    ns.showEditInvoice = function(id, amount, discount, date) {
        const elId = document.getElementById('editInvId'); if(elId) elId.value = id;
        const am = document.getElementById('editInvAmount'); if(am) am.value = amount;
        const disc = document.getElementById('editInvDiscount'); if(disc) disc.value = discount;
        const dt = document.getElementById('editInvDate'); if(dt) dt.value = date;
        const title = document.getElementById('editTitle'); if(title) title.innerText = 'Edit INV-' + String(id).padStart(5, '0');
        const modal = document.getElementById('editInvoiceModal'); if(modal) modal.style.display = 'flex';
    };
    ns.hideEditInvoice = function(){ const modal = document.getElementById('editInvoiceModal'); if(modal) modal.style.display = 'none'; };
    ns.viewTR069 = function(pppoe) {
        const modal = document.getElementById('tr069Modal');
        const content = document.getElementById('tr069-content');
        if (!modal || !content) return;
        modal.style.display = 'block';
        if(window.innerWidth < 900) modal.style.paddingTop = '20px';
        content.innerHTML = '<div style="text-align:center; padding:40px;'><i class="fas fa-circle-notch fa-spin fa-2x text-primary"></i><p style="margin-top:15px; color:var(--text-secondary);">Mengambil data perangkat...</p></div>';
        fetch('views/components/tr069_monitor.php?pppoe=' + encodeURIComponent(pppoe))
            .then(response => response.text())
            .then(html => { content.innerHTML = html; })
            .catch(err => { content.innerHTML = '<div class="alert alert-danger">Gagal memuat data monitoring.</div>'; });
    };
    // Close TR-069 modal when clicking outside
    window.addEventListener('click', function(event){
        const modal = document.getElementById('tr069Modal');
        if (!modal) return;
        if (event.target == modal) modal.style.display = 'none';
    });
})(window.CustomersPage);
</script>
