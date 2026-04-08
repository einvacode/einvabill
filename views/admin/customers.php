<?php
$action = $_GET['action'] ?? 'list';

// Success Modal for Admin Customers
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['id'])) {
    $sid = intval($_GET['id']);
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid")->fetch();
    $settings = $db->query("SELECT company_name, wa_template_paid, site_url FROM settings WHERE id=1")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact'] ?? ''));
        $months_paid = intval($_GET['months'] ?? 1);
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas'")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $portal_link = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        $me = $db->query("SELECT * FROM users WHERE id = " . $_SESSION['user_id'])->fetch();
        $wa_tpl_paid = !empty($me['wa_template_paid']) ? $me['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, pembayaran {tagihan} LUNAS. Cek nota: {link_tagihan}");
        
        $receipt_msg = str_replace(
            ['{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', '{tunggakan}', '{waktu_bayar}', '{admin}', '{perusahaan}', '{link_tagihan}'], 
            [$success_data['name'], ($success_data['customer_code'] ?: $success_data['id']), 'Rp ' . number_format($success_data['monthly_fee'], 0, ',', '.'), ($success_data['package_name'] ?: '-'), $months_paid, $tunggakan_display, date('d/m/Y H:i') . ' WIB', $_SESSION['user_name'], $settings['company_name'], $portal_link], 
            $wa_tpl_paid
        );
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?>

<?php if($success_data): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--success); padding:20px; background:rgba(16,185,129,0.1);">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h3 style="margin:0; color:var(--success); font-size:16px;"><i class="fas fa-check-circle"></i> Pembayaran Berhasil!</h3>
            <p style="margin:0; font-size:12px; color:var(--text-secondary);">Pelanggan <strong><?= htmlspecialchars($success_data['name']) ?></strong> telah diupdate.</p>
        </div>
        <button onclick="sendWAGateway('<?= $wa_num_paid ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $success_data['wa_link'] ?>', this)" class="btn btn-sm btn-success"><i class="fab fa-whatsapp"></i> Kirim WA</button>
    </div>
</div>
<?php endif; ?>
<?php
// Fetch all packages for dropdowns (Scoped)
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'] ?? 'admin';
    $scope_where = ($u_role === 'admin' || $u_role === 'collector') ? " AND (created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR created_by = 0 OR created_by IS NULL) " : " AND (created_by = $u_id) ";
$pkg_scope = ($u_role === 'admin' || $u_role === 'collector') ? "WHERE created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR created_by = 0 OR created_by IS NULL" : "WHERE created_by = $u_id";
$packages_all = $db->query("SELECT * FROM packages $pkg_scope ORDER BY name ASC")->fetchAll();
$packages_json = json_encode($packages_all);

// Fetch all areas for dropdowns (Scoped: Hidden for Partner)
$areas_all = ($u_role === 'admin' || $u_role === 'collector') ? $db->query("SELECT * FROM areas ORDER BY name ASC")->fetchAll() : [];

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
    
    // Auto-generate unique random customer code
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
    do {
        $customer_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt_check->execute([$customer_code]);
    } while ($stmt_check->fetchColumn() > 0);
    
    $created_by = $_SESSION['user_id'];
    
    $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, ip_address, type, registration_date, billing_date, area, router_id, pppoe_name, collector_id, lat, lng, odp_id, odp_port, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$customer_code, $name, $address, $contact, $package_name, $monthly_fee, $ip_address, $type, $registration_date, $billing_date, $area, $router_id, $pppoe_name, $collector_id, $lat, $lng, $odp_id, $odp_port, $created_by]);
    $id = $db->lastInsertId();

    // SELECTIVE AUTOMATIC PAYMENT ON REGISTRATION
    if ($monthly_fee > 0) {
        if ($type === 'customer') {
            // RUMAHAN: Tagihan Terbit di Hari Registrasi (Belum Lunas - perlu konfirmasi manual)
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
            $stmt_inv->execute([$id, $monthly_fee, $registration_date, date('Y-m-d H:i:s')]);
        } else {
            // MITRA: Bayar Setelah 30 Hari / Sesuai Tanggal Tagihan Bulan Depan (Belum Lunas)
            $next_month = date('Y-m', strtotime("+1 month"));
            $bday = str_pad($billing_date, 2, '0', STR_PAD_LEFT);
            $due_date = "{$next_month}-{$bday}";
            
            $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
            $stmt_inv->execute([$id, $monthly_fee, $due_date, date('Y-m-d H:i:s')]);
        }
    }
    
    // Arrears Logic (Jika ada tunggakan manual dari migrasi data lama)
    $arrears_months = intval($_POST['arrears_months'] ?? 0);
    $arrears_amount = floatval($_POST['arrears_amount'] ?? 0);
    if ($arrears_amount <= 0) $arrears_amount = $monthly_fee;
    
    if ($arrears_months > 0 && $arrears_amount > 0) {
        $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
        for ($m = $arrears_months; $m >= 1; $m--) {
            $due = date('Y-m-d', strtotime("-{$m} months", strtotime(date('Y') . '-' . date('m') . '-' . str_pad($billing_date, 2, '0', STR_PAD_LEFT))));
            $created = $due;
            $stmt_inv->execute([$id, $arrears_amount, $due, $created]);
        }
    }
    
    header("Location: index.php?page=admin_customers&action=details&id=$id&msg=added");
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
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
    
    // Ownership Check
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    $check = $db->query("SELECT created_by FROM customers WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? true : (($u_role === 'collector') ? ($check == $u_id || $check == 0 || $check === NULL) : ($check == $u_id));
    
    if (!$is_owner) {
        header("Location: index.php?page=admin_customers&msg=forbidden");
        exit;
    }
    
    $stmt = $db->prepare("UPDATE customers SET name=?, address=?, contact=?, package_name=?, monthly_fee=?, ip_address=?, type=?, registration_date=?, billing_date=?, area=?, router_id=?, pppoe_name=?, collector_id=?, lat=?, lng=?, odp_id=?, odp_port=? WHERE id=?");
    $stmt->execute([$name, $address, $contact, $package_name, $monthly_fee, $ip_address, $type, $registration_date, $billing_date, $area, $router_id, $pppoe_name, $collector_id, $lat, $lng, $odp_id, $odp_port, $id]);

    // OPTIMIZATION: Sync existing unpaid invoices with the new monthly fee
    $db->prepare("UPDATE invoices SET amount = ? WHERE customer_id = ? AND status = 'Belum Lunas'")->execute([$monthly_fee, $id]);
    
    // Process additional arrears if any during update
    $arrears_months = intval($_POST['arrears_months'] ?? 0);
    $arrears_amount = floatval($_POST['arrears_amount'] ?? 0);
    if ($arrears_months > 0 && $arrears_amount > 0) {
        $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
        $check_stmt = $db->prepare("SELECT id FROM invoices WHERE customer_id = ? AND strftime('%Y-%m', due_date) = ?");
        for ($m = $arrears_months; $m >= 1; $m--) {
            $due = date('Y-m-d', strtotime("-{$m} months", strtotime(date('Y') . '-' . date('m') . '-' . str_pad($billing_date, 2, '0', STR_PAD_LEFT))));
            $due_month = date('Y-m', strtotime($due));
            
            // Check for existing invoice for this month
            $check_stmt->execute([$id, $due_month]);
            if (!$check_stmt->fetchColumn()) {
                $created = $due;
                $stmt_inv->execute([$id, $arrears_amount, $due, $created]);
            }
        }
    }
    
    header("Location: index.php?page=admin_customers&action=details&id=$id&msg=profile_updated");
    exit;
}

if ($action === 'delete') {
    $id = intval($_GET['id']);
    
    // Ownership Check
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    $check = $db->query("SELECT created_by FROM customers WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? true : ($check == $u_id);
    
    if (!$is_owner) {
        header("Location: index.php?page=admin_customers&msg=forbidden");
        exit;
    }

    // Cascade Delete
    $db->prepare("DELETE FROM payments WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id = ?)")->execute([$id]);
    $db->prepare("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id = ?)")->execute([$id]);
    $db->prepare("DELETE FROM invoices WHERE customer_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
    
    header("Location: index.php?page=admin_customers&msg=deleted");
    exit;
}

if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    if (!empty($ids)) {
        $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // Clean up everything related to these customers
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id IN ($id_placeholders))")->execute($ids);
        $db->prepare("DELETE FROM payments WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id IN ($id_placeholders))")->execute($ids);
        $db->prepare("DELETE FROM invoices WHERE customer_id IN ($id_placeholders)")->execute($ids);
        $db->prepare("DELETE FROM customers WHERE id IN ($id_placeholders)")->execute($ids);
        
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
        $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$target_collector], $ids);
        $db->prepare("UPDATE customers SET collector_id = ? WHERE id IN ($id_placeholders)")->execute($params);
        header("Location: index.php?page=admin_customers&msg=bulk_moved");
        exit;
    } else {
        header("Location: index.php?page=admin_customers");
        exit;
    }
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

    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    $scope_where_exp = " AND (created_by = $u_id) ";
    if ($u_role === 'admin' || $u_role === 'collector') {
        $scope_where_exp = " AND (created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR created_by = 0 OR created_by IS NULL) ";
    }

    $customers_export = $db->query("SELECT * FROM customers $where $scope_where_exp ORDER BY name ASC")->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Data_Pelanggan_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
    
    fputcsv($output, ['Kode', 'Nama', 'Tipe', 'Alamat', 'Kontak', 'Paket', 'Biaya Bulanan', 'IP Address', 'Area']);
    
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
            $row['area']
        ]);
    }
    fclose($output);
    exit;
}

if ($action === 'import_paste' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST['paste_data'];
    $lines = explode("\n", trim($data));
    $stmt = $db->prepare("INSERT INTO customers (name, address, contact, package_name, monthly_fee, ip_address, type, registration_date, billing_date, area) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = explode("\t", trim($line)); 
        if (count($row) < 9) $row = explode(";", trim($line));
        if (count($row) < 9) $row = str_getcsv(trim($line));

        if (count($row) >= 9) {
            if (trim($row[1]) == '' || strtolower(trim($row[1])) == 'nama pelanggan') continue;
            
            $pkg_name = trim($row[4]);
            $pkg_fee = (float)preg_replace('/[^0-9]/', '', $row[5]);

            // Smart Sync: Check/Create Package
            if (!empty($pkg_name)) {
                $pkg_exist = $db->prepare("SELECT id FROM packages WHERE name = ?");
                $pkg_exist->execute([$pkg_name]);
                if (!$pkg_exist->fetch()) {
                    $db->prepare("INSERT INTO packages (name, fee) VALUES (?, ?)")->execute([$pkg_name, $pkg_fee]);
                }
            }

            $cust_area = isset($row[9]) ? trim($row[9]) : '';
            // Smart Sync: Check/Create Area
            if (!empty($cust_area)) {
                $area_exist = $db->prepare("SELECT id FROM areas WHERE name = ?");
                $area_exist->execute([$cust_area]);
                if (!$area_exist->fetch()) {
                    $db->prepare("INSERT INTO areas (name) VALUES (?)")->execute([$cust_area]);
                }
            }

            $stmt->execute([
                trim($row[1]), trim($row[2]), trim($row[3]), $pkg_name, $pkg_fee,
                trim($row[6]), strtolower(trim($row[0])) == 'partner' ? 'partner' : 'customer', trim($row[7]), (int)trim($row[8]), $cust_area
            ]);
            
            $imp_id = $db->lastInsertId();
            $stmt_chk = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
            do {
                $imp_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                $stmt_chk->execute([$imp_code]);
            } while ($stmt_chk->fetchColumn() > 0);
            $db->prepare("UPDATE customers SET customer_code = ? WHERE id = ?")->execute([$imp_code, $imp_id]);
        }
    }
    header("Location: index.php?page=admin_customers");
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
    
    $c = $db->query("SELECT billing_date FROM customers WHERE id = $customer_id")->fetch();
    $bday = str_pad($c['billing_date'] ?: 1, 2, '0', STR_PAD_LEFT);
    
    // Find the latest invoice due date to start from
    $last_due = $db->query("SELECT due_date FROM invoices WHERE customer_id = $customer_id ORDER BY due_date DESC LIMIT 1")->fetchColumn();
    
    $start_time = $last_due ? strtotime($last_due) : strtotime(date('Y-m-') . $bday);
    if(!$last_due) $start_time = strtotime("-1 month", $start_time); // Start from previous to make current first

    for ($i = 1; $i <= $num_months; $i++) {
        $next_due = date('Y-m-d', strtotime("+$i months", $start_time));
        
        // Check if invoice exists for this date
        $exist = $db->query("SELECT id, status FROM invoices WHERE customer_id = $customer_id AND due_date = '$next_due'")->fetch();
        
        if ($exist) {
            if ($exist['status'] !== 'Lunas') {
                $inv_id = $exist['id'];
                $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ?")->execute([$inv_id]);
                $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date) VALUES (?, ?, ?, ?)")
                   ->execute([$inv_id, $amount_per_month, $receiver_id, date('Y-m-d H:i:s')]);
            }
        } else {
            $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status) VALUES (?, ?, ?, 'Lunas')")
               ->execute([$customer_id, $amount_per_month, $next_due]);
            $inv_id = $db->lastInsertId();
            $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date) VALUES (?, ?, ?, ?)")
               ->execute([$inv_id, $amount_per_month, $receiver_id, date('Y-m-d H:i:s')]);
        }
    }
    
    header("Location: index.php?page=admin_customers&action=details&id=$customer_id&success=bulk");
    exit;
}
?>

<?php if ($action === 'list'): ?>
<div class="glass-panel" style="padding: 24px;">
    <?php
    $filter_type = $_GET['filter_type'] ?? '';
    $create_url = "index.php?page=admin_customers&action=create" . ($filter_type ? "&type=$filter_type" : "");
    $export_url = "index.php?page=admin_customers&action=export" . ($filter_type ? "&type=$filter_type" : "");
    $page_title = $filter_type === 'partner' ? 'Manajemen Kemitraan (B2B)' : ($filter_type === 'customer' ? 'Manajemen Pelanggan Rumahan' : 'Pelanggan & Mitra');
    $title_icon = $filter_type === 'partner' ? 'fa-handshake' : 'fa-users';
    ?>
    <div class="grid-header">
        <div>
            <h3 style="font-size:20px; font-weight:800; margin:0;"><i class="fas <?= $title_icon ?> text-primary"></i> <?= $page_title ?></h3>
            <div style="font-size:12px; color:var(--text-secondary); margin-top:4px; opacity:0.8;">
                Manajemen data pelanggan & konfigurasi layanan
            </div>
        </div>
        <div class="grid-actions">
            <div class="btn-group">
                <a href="<?= $export_url ?>" class="btn btn-sm btn-ghost" style="color:var(--success);"><i class="fas fa-arrow-down"></i> <span class="hide-mobile">Export</span></a>
                <a href="index.php?page=admin_customers&action=import_view" class="btn btn-sm btn-ghost" style="color:var(--success);"><i class="fas fa-arrow-up"></i> <span class="hide-mobile">Import</span></a>
                <a href="<?= $create_url ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah</a>
            </div>
        </div>
    </div>
    
    <?php if(isset($_GET['msg'])): ?>
        <div class="glass-panel" style="padding:15px; margin-bottom:20px; background:rgba(16, 185, 129, 0.1); border-left:4px solid var(--success); display:flex; align-items:center; gap:12px;">
            <i class="fas fa-check-circle" style="color:var(--success); font-size:20px;"></i>
            <div style="font-weight:600; color:var(--success);">
                <?php
                if($_GET['msg'] == 'bulk_deleted') echo "Berhasil menghapus pelanggan terpilih secara massal.";
                if($_GET['msg'] == 'bulk_moved') echo "Berhasil memindahkan penugasan pelanggan ke penagih baru.";
                if($_GET['msg'] == 'deleted') echo "Berhasil menghapus pelanggan.";
                if($_GET['msg'] == 'added') echo "Berhasil menambah pelanggan.";
                ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php
    $filter_type = $_GET['filter_type'] ?? '';
    $filter_collector = $_GET['filter_collector'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $where_type = "";
    if ($filter_type) $where_type = " AND type = " . $db->quote($filter_type);
    
    $where_collector = "";
    if ($filter_collector) {
        $coll_area = $db->query("SELECT area FROM users WHERE id = " . intval($filter_collector))->fetchColumn();
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

    // Pagination Logic
    $items_per_page = 50;
    $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;
    
    // Scoping Logic (Multi-tenancy)
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    if ($u_role === 'admin') {
        $scope_where = " AND (created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR created_by = 0 OR created_by IS NULL) ";
    } elseif ($u_role === 'collector') {
        $scope_where = " AND (collector_id = $u_id) ";
    }

    // Count total rows for this filter
    $count_q = "SELECT COUNT(*) FROM customers WHERE 1=1 $where_type $where_collector $where_search $scope_where";
    $total_rows = $db->query($count_q)->fetchColumn();
    $total_pages = ceil($total_rows / $items_per_page);
    ?>

    <!-- Stats Section -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:25px;">
        <div class="glass-panel" style="padding:15px; border-left:4px solid var(--primary); background:linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(37, 99, 235, 0.05));">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:5px;">Total <?= $filter_type == 'partner' ? 'Mitra' : 'Pelanggan' ?></div>
            <div style="font-size:24px; font-weight:800; color:var(--primary);"><?= number_format($total_rows) ?></div>
        </div>
        <?php
        $stats_q = "SELECT SUM(monthly_fee) as total_mrr, AVG(monthly_fee) as avg_fee FROM customers WHERE 1=1 $where_type $where_collector $where_search $scope_where";
        $stats_data = $db->query($stats_q)->fetch();
        ?>
        <div class="glass-panel" style="padding:15px; border-left:4px solid var(--success); background:linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:5px;">Estimasi MRR</div>
            <div style="font-size:24px; font-weight:800; color:var(--success);">Rp <?= number_format($stats_data['total_mrr'] ?? 0, 0, ',', '.') ?></div>
        </div>
        <div class="glass-panel" style="padding:15px; border-left:4px solid var(--warning); background:linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:5px;">Rata-rata ARPU</div>
            <div style="font-size:24px; font-weight:800; color:var(--warning);">Rp <?= number_format($stats_data['avg_fee'] ?? 0, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding:20px; background:rgba(var(--primary-rgb), 0.05); border-radius:12px; margin-bottom:25px; border:1px solid rgba(var(--primary-rgb), 0.1);">
        <form method="GET" class="grid-filters">
            <input type="hidden" name="page" value="admin_customers">
            
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Cari Pelanggan</label>
                <div style="position:relative;">
                    <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-secondary); opacity:0.5; font-size:12px;"></i>
                    <input type="text" name="search" class="form-control" placeholder="Nama / Kode / HP..." value="<?= htmlspecialchars($search) ?>" style="padding-left:35px; font-size:13px; height:40px;">
                </div>
            </div>

            <?php if ($u_role === 'admin'): ?>
            <div class="filter-group">
                <label><i class="fas fa-user-tag"></i> Tipe</label>
                <select name="filter_type" class="form-control" style="font-size:13px; height:40px;">
                    <option value="">Semua Tipe</option>
                    <option value="customer" <?= $filter_type == 'customer' ? 'selected' : '' ?>>Rumahan</option>
                    <option value="partner" <?= $filter_type == 'partner' ? 'selected' : '' ?>>Mitra / B2B</option>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($u_role === 'admin'): ?>
            <div class="filter-group">
                <label><i class="fas fa-user-tie"></i> Penagih</label>
                <select name="filter_collector" class="form-control" style="font-size:13px; height:40px;">
                    <option value="">Semua Penagih</option>
                    <?php foreach($collectors as $coll): ?>
                        <option value="<?= $coll['id'] ?>" <?= $filter_collector == $coll['id'] ? 'selected' : '' ?>><?= htmlspecialchars($coll['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="grid-actions" style="margin-top:auto;">
                <div class="btn-group" style="width:100%;">
                    <button type="submit" class="btn btn-primary btn-sm" style="flex:1; height:40px;"><i class="fas fa-search"></i> Cari</button>
                    <?php if($search || $filter_type || $filter_collector): ?>
                        <a href="index.php?page=admin_customers" class="btn btn-ghost btn-sm" style="flex:0; width:45px; height:40px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-sync"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Scrollable Container for Customers -->
    <div class="scroll-container" style="max-height:70vh; overflow-y:auto; padding-right:5px; margin-bottom:20px; border-radius:12px;">
        <!-- Mobile Card View (Hidden on Desktop) -->
        <div class="customers-mobile-container" style="display:none;">
        <?php
        $rt_scope = ($u_role === 'admin') ? "WHERE (created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR created_by = 0 OR created_by IS NULL)" : "WHERE created_by = $u_id";
        $routers = [];
        try { $routers = $db->query("SELECT * FROM routers $rt_scope")->fetchAll(); } catch(Exception $e) {}
        $customers = $db->query("SELECT * FROM customers WHERE 1=1 $where_type $where_collector $where_search $scope_where ORDER BY id DESC LIMIT $items_per_page OFFSET $offset")->fetchAll();
        
        foreach($customers as $c):
            $rtName = '-';
            foreach($routers as $r) { if($r['id'] == ($c['router_id'] ?? 0)) $rtName = $r['name']; }
        ?>
        <div class="glass-panel" style="padding:16px; margin-bottom:12px; border-left:4px solid var(--primary);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                <div>
                    <div style="font-weight:700; font-size:16px;"><?= htmlspecialchars($c['name']) ?></div>
                    <div style="font-size:11px; color:var(--primary); font-family:monospace;"><?= htmlspecialchars($c['customer_code'] ?? '') ?></div>
                </div>
                    <?php if($u_role === 'admin'): ?>
                        <?php if($c['type']=='partner'): ?>
                            <span class="badge badge-warning">Mitra</span>
                        <?php else: ?>
                            <span class="badge badge-success">SLA</span>
                        <?php endif; ?>
                    <?php endif; ?>
            </div>
            
            <div style="font-size:13px; color:var(--text-secondary); margin-bottom:12px;">
                <div style="margin-bottom:3px;"><i class="fas fa-box" style="width:16px;"></i> <?= htmlspecialchars($c['package_name']) ?> — <strong>Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></strong></div>
                <?php if($u_role === 'admin'): ?>
                <div style="margin-bottom:3px;"><i class="fas fa-map-marker-alt" style="width:16px;"></i> <?= htmlspecialchars($c['area'] ?: '-') ?></div>
                <?php endif; ?>
                <div style="margin-bottom:3px;"><i class="fas fa-phone" style="width:16px;"></i> <?= htmlspecialchars($c['contact']) ?></div>
                <div style="font-family:monospace; font-size:11px;">
                    IP: <?= htmlspecialchars($c['ip_address'] ?: '-') ?> • RB: <?= htmlspecialchars($rtName) ?>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--glass-border); padding-top:12px;">
                <div class="conn-status" data-router="<?= htmlspecialchars($c['router_id'] ?? 0) ?>" data-pppoe="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>">
                    <i class="fas fa-spinner fa-spin text-warning"></i>
                </div>
                <div class="btn-group">
                    <button class="btn btn-xs btn-ghost" onclick="createInvoice(<?= $c['id'] ?>, <?= $c['monthly_fee'] ?>)" title="Tagih"><i class="fas fa-file-invoice-dollar"></i></button>
                    <?php if(!empty($c['pppoe_name'])): ?>
                        <button class="btn btn-xs btn-ghost" onclick="viewTR069('<?= htmlspecialchars($c['pppoe_name']) ?>')" title="TR-069"><i class="fas fa-satellite-dish"></i></button>
                    <?php endif; ?>
                    <a href="index.php?page=admin_customers&action=details&id=<?= $c['id'] ?>" class="btn btn-xs btn-ghost" style="color:var(--primary);" title="Detail Lengkap"><i class="fas fa-eye"></i></a>
                    <a href="index.php?page=admin_customers&action=edit&id=<?= $c['id'] ?>" class="btn btn-xs btn-ghost" style="color:var(--warning);" title="Edit"><i class="fas fa-edit"></i></a>
                    <a href="index.php?page=admin_customers&action=delete&id=<?= $c['id'] ?>" class="btn btn-xs btn-danger" onclick="return confirm('Hapus?')" title="Hapus"><i class="fas fa-trash"></i></a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Desktop Table View (Hidden on Mobile) -->
    <div class="table-container customers-desktop-table">
        <table>
            <thead>
                <tr>
                    <th style="width:40px; text-align:center;"><input type="checkbox" id="check-all-cust" style="transform:scale(1.2); cursor:pointer;"></th>
                    <th>Nama</th>
                    <?php if ($u_role === 'admin'): ?><th>Tipe / Area</th><?php endif; ?>
                    <th>Paket / Kontak</th>
                    <th>Biaya Bulanan</th>
                    <th>IP / Koneksi</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($customers as $c):
                    $rtName = '-';
                    foreach($routers as $r) { if($r['id'] == ($c['router_id'] ?? 0)) $rtName = $r['name']; }
                ?>
                <tr class="cust-row">
                    <td style="text-align:center;"><input type="checkbox" class="cust-checkbox" value="<?= $c['id'] ?>" style="transform:scale(1.2); cursor:pointer;"></td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></div>
                        <div style="font-size:11px; color:var(--primary); font-family:monospace;"><?= htmlspecialchars($c['customer_code'] ?? '') ?></div>
                    </td>
                    <?php if ($u_role === 'admin'): ?>
                    <td>
                        <?php if($c['type']=='partner'): ?>
                            <span class="badge badge-warning">Mitra</span>
                        <?php else: ?>
                            <span class="badge badge-success">Pelanggan</span>
                        <?php endif; ?>
                        <div style="font-size:12px; margin-top:5px; color:var(--text-secondary);"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($c['area'] ?: '-') ?></div>
                    </td>
                    <?php endif; ?>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($c['package_name']) ?></div>
                        <div style="font-size:12px; color:var(--text-secondary);"><i class="fas fa-phone"></i> <?= htmlspecialchars($c['contact']) ?></div>
                    </td>
                    <td style="font-weight:bold;">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></td>
                    <td style="font-family:monospace; line-height:1.4;">
                        IP: <?= htmlspecialchars($c['ip_address'] ?? '-') ?><br>
                        <span style="font-size:11px; color:var(--text-secondary);"><i class="fas fa-network-wired"></i> <?= htmlspecialchars($rtName) ?></span><br>
                        <span style="font-size:11px; color:var(--text-secondary);"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($c['pppoe_name'] ?? 'Tidak Disetel') ?></span>
                    </td>
                    <td class="conn-status" style="text-align:center;" data-router="<?= htmlspecialchars($c['router_id'] ?? 0) ?>" data-pppoe="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>">
                        <?php if(empty($c['pppoe_name'])): ?>
                            <span style="color:var(--text-secondary); font-size:12px;">Tanpa API</span>
                        <?php else: ?>
                            <i class="fas fa-circle-notch fa-spin text-warning"></i>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-ghost" onclick="createInvoice(<?= $c['id'] ?>, <?= $c['monthly_fee'] ?>)" title="Tagih Manual"><i class="fas fa-file-invoice"></i></button>
                        <?php if(!empty($c['pppoe_name'])): ?>
                            <button class="btn btn-sm btn-ghost" onclick="viewTR069('<?= htmlspecialchars($c['pppoe_name']) ?>')" title="Monitoring TR-069"><i class="fas fa-satellite-dish"></i></button>
                        <?php endif; ?>
                        <a href="index.php?page=admin_customers&action=details&id=<?= $c['id'] ?>" class="btn btn-sm btn-info" title="Detail & Riwayat"><i class="fas fa-eye"></i></a>
                        <a href="index.php?page=admin_customers&action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                        <a href="index.php?page=admin_customers&action=delete&id=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus Pelanggan ini?')" title="Hapus"><i class="fas fa-trash"></i></a>
                        
                        <?php 
                        $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $c['contact']));
                        $wa_text = "Halo " . urlencode($c['name']) . ", tagihan internet Anda sebesar Rp " . number_format($c['monthly_fee'], 0, ',', '.') . " telah tersedia. Mohon segera melakukan pembayaran.";
                        ?>
                        <button onclick="sendWAGateway('<?= $wa_number ?>', <?= htmlspecialchars(json_encode($wa_text)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>', this)" class="btn btn-sm btn-ghost" style="color:#25D366;" title="Kirim WA"><i class="fab fa-whatsapp"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    </div>

    <!-- Pagination Navigation -->
    <?php if($total_pages > 1): ?>
    <div style="display:flex; justify-content:center; gap:8px; margin-top:24px; flex-wrap:wrap;">
        <?php 
        $params = $_GET; 
        unset($params['p']); 
        $query_str = http_build_query($params);
        $base_url = "index.php?" . $query_str . "&p=";
        ?>
        
        <?php if($current_page > 1): ?>
            <a href="<?= $base_url . ($current_page - 1) ?>" class="btn btn-sm btn-ghost">&laquo; Prev</a>
        <?php endif; ?>

        <?php 
        $start_p = max(1, $current_page - 2);
        $end_p = min($total_pages, $current_page + 2);
        if($start_p > 1) echo '<span style="padding:5px 10px; color:var(--text-secondary);">...</span>';
        for($i = $start_p; $i <= $end_p; $i++): 
        ?>
            <a href="<?= $base_url . $i ?>" class="btn btn-sm <?= $i == $current_page ? 'btn-primary' : 'btn-ghost' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if($end_p < $total_pages) echo '<span style="padding:5px 10px; color:var(--text-secondary);">...</span>'; ?>

        <?php if($current_page < $total_pages): ?>
            <a href="<?= $base_url . ($current_page + 1) ?>" class="btn btn-sm btn-ghost">Next &raquo;</a>
        <?php endif; ?>
        
        <div style="width:100%; text-align:center; margin-top:8px; font-size:12px; color:var(--text-secondary);">
            Menampilkan <?= count($customers) ?> dari <?= $total_rows ?> pelanggan (Halaman <?= $current_page ?> dari <?= $total_pages ?>)
        </div>
    </div>
    <?php endif; ?>

    <!-- Bulk Action Bar -->
    <div id="bulk-bar" class="glass-panel" style="display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); width:91%; max-width:900px; padding:15px 25px; z-index:1000; box-shadow:0 -10px 40px rgba(0,0,0,0.3); border:2px solid var(--primary); align-items:center; justify-content:space-between; flex-wrap:wrap; gap:15px;">
        <div style="display:flex; align-items:center; gap:20px;">
            <button class="btn btn-sm btn-ghost" onclick="cancelBulkSelection()" style="padding:10px; border-radius:50%; width:35px; height:35px; display:flex; align-items:center; justify-content:center;" title="Batalkan Seleksi">
                <i class="fas fa-times"></i>
            </button>
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="background:var(--primary); color:white; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:13px;" id="bulk-count">0</div>
                <div style="font-weight:700; font-size:14px;">Terpilih</div>
            </div>
        </div>
        
        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($u_role === 'admin'): ?>
            <div id="move-collector-box" style="display:none; align-items:center; gap:8px; background:var(--hover-bg); padding:5px 10px; border-radius:8px; border:1px solid var(--glass-border);">
                <span style="font-size:12px; font-weight:600;">Pindah ke:</span>
                <select id="bulk-target-collector" class="form-control" style="width:150px; padding:5px;">
                    <option value="">-- Pilih Penagih --</option>
                    <?php 
                    $colls = $db->query("SELECT id, name FROM users WHERE role = 'collector'")->fetchAll();
                    foreach($colls as $coll) echo "<option value='{$coll['id']}'>{$coll['name']}</option>";
                    ?>
                </select>
                <button class="btn btn-sm btn-primary" onclick="submitBulkMove()">Simpan</button>
                <button class="btn btn-sm btn-ghost" onclick="toggleMoveBox(false)">Batal</button>
            </div>

            <button class="btn btn-sm btn-ghost" id="btn-move-trigger" onclick="toggleMoveBox(true)" style="color:var(--primary); font-weight:700;">
                <i class="fas fa-exchange-alt"></i> Pindah Penagih
            </button>
            <?php endif; ?>
            
            <button class="btn btn-sm btn-danger" onclick="submitBulkDelete()" style="font-weight:700;">
                <i class="fas fa-trash"></i> Hapus Masal
            </button>
        </div>
    </div>

    <!-- Hidden forms for bulk actions -->
    <form id="bulk-form-delete" action="index.php?page=admin_customers&action=bulk_delete" method="POST" style="display:none;"></form>
    <form id="bulk-form-move" action="index.php?page=admin_customers&action=bulk_move" method="POST" style="display:none;">
        <input type="hidden" name="target_collector_id" id="hidden-target-collector">
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
        document.getElementById('move-collector-box').style.display = show ? 'flex' : 'none';
        document.getElementById('btn-move-trigger').style.display = show ? 'none' : 'inline-block';
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
    </script>
</div>

<style>
@media (max-width: 768px) {
    .customers-desktop-table { display: none !important; }
    .customers-mobile-container { display: block !important; }
}
</style>

<form id="autoInvoiceForm" method="POST" action="index.php?page=admin_invoices&action=create_auto" style="display:none;">
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
                });
        });
    }

    function updateUI() {
        document.querySelectorAll('.conn-status[data-router]').forEach(td => {
            let rId = parseInt(td.getAttribute('data-router'));
            let pppoe = td.getAttribute('data-pppoe');
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
        $c = $db->query("SELECT * FROM customers WHERE id = $id")->fetch();
        
        // Ownership Check
        if ($c) {
            $u_id = $_SESSION['user_id'];
            $u_role = $_SESSION['user_role'];
            $is_owner = ($u_role === 'admin') ? true : (($c['created_by'] == $u_id || $c['created_by'] == 0 || $c['created_by'] === NULL) && $_SESSION['user_role'] === 'collector' ? true : ($c['created_by'] == $u_id));
            if (!$is_owner) {
                echo "<div class='glass-panel p-5 text-center'><h3>Akses Ditolak</h3><p>Anda tidak berwenang mengedit data ini.</p><a href='index.php?page=admin_customers' class='btn btn-primary'>Kembali</a></div>";
                return;
            }
        }
    } else {
        $u_role = $_SESSION['user_role'] ?? 'admin';
        $default_type = ($u_role === 'partner') ? 'customer' : ($_GET['type'] ?? 'customer');
        $c = ['type'=>$default_type, 'registration_date'=>date('Y-m-d'), 'billing_date'=>'', 'router_id'=>0, 'pppoe_name'=>'', 'name'=>'', 'address'=>'', 'contact'=>'', 'package_name'=>'', 'monthly_fee'=>'', 'ip_address'=>'', 'area'=>''];
    }
?>
<div class="glass-panel" style="padding: 24px; max-width:600px; margin:0 auto;">
    <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-user-edit text-primary"></i> <?= $action === 'edit' ? 'Edit Data Pelanggan' : 'Tambah Baru' ?></h3>
    <form action="index.php?page=admin_customers&action=<?= $action === 'edit' ? 'update' : 'add' ?>" method="POST" onsubmit="return validateAndSync(event)">
        <?php if($action === 'edit'): ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><?php endif; ?>
        
        <?php if ($u_role === 'admin'): ?>
        <div class="form-group">
            <label>Tipe Entitas</label>
            <select name="type" id="customer_type_selector" class="form-control" required onchange="togglePkgFields(this.value)">
                <option value="customer" <?= $c['type']=='customer'?'selected':'' ?>>Pelanggan (Rumahan)</option>
                <option value="partner" <?= $c['type']=='partner'?'selected':'' ?>>Mitra (Bandwidth)</option>
            </select>
        </div>
        <?php else: ?>
            <input type="hidden" name="type" id="customer_type_selector" value="customer">
        <?php endif; ?>
        <div class="form-group">
            <label>Nama Lengkap / Instansi</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>Alamat Instalasi</label>
            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($c['address']) ?>">
        </div>
        <div class="form-group">
            <label>Kontak / WhatsApp</label>
            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($c['contact']) ?>">
        </div>
        <div id="standard-pkg-zone" class="flex" style="gap:15px; margin-bottom:20px; <?= $c['type'] == 'partner' ? 'display:none;' : '' ?>">
            <div style="flex:1;">
                <label style="font-size:14px; margin-bottom:8px; display:block;">Pilih Paket</label>
                <select name="package_name_select" id="package_selector" class="form-control" onchange="updateFee(this.value)">
                    <option value="">-- Pilih Paket --</option>
                    <?php foreach($packages_all as $p): ?>
                        <option value="<?= htmlspecialchars($p['name']) ?>" data-fee="<?= $p['fee'] ?>" <?= $c['package_name'] == $p['name'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:10px; color:var(--text-secondary); margin-top:5px;">*Atur paket di menu Manajemen Paket</div>
            </div>
            <div style="flex:1;">
                <label style="font-size:14px; margin-bottom:8px; display:block;">Biaya Bulanan (Rp)</label>
                <input type="number" name="monthly_fee_std" id="monthly_fee_input" class="form-control" value="<?= $c['monthly_fee'] ?>">
            </div>
        </div>

        <div id="custom-pkg-zone" class="flex" style="gap:15px; margin-bottom:20px; <?= $c['type'] != 'partner' ? 'display:none;' : '' ?>">
            <div style="flex:1;">
                <label style="font-size:14px; margin-bottom:8px; display:block;">Nama Paket (Custom)</label>
                <input type="text" name="package_name_custom" class="form-control" value="<?= htmlspecialchars($c['package_name']) ?>" placeholder="Misal: Dedicated 50Mbps">
                <div style="font-size:10px; color:var(--success); margin-top:5px;">*Khusus Mitra tulis manual nama paketnya</div>
            </div>
            <div style="flex:1;">
                <label style="font-size:14px; margin-bottom:8px; display:block;">Biaya Custom (Rp)</label>
                <input type="number" name="monthly_fee_custom" class="form-control" value="<?= $c['monthly_fee'] ?>" placeholder="Sesuai Kontrak">
            </div>
        </div>
        
        <!-- Hidden real fields to sync before submit -->
        <input type="hidden" name="package_name" id="real_package_name" value="<?= htmlspecialchars($c['package_name']) ?>">
        <input type="hidden" name="monthly_fee" id="real_monthly_fee" value="<?= $c['monthly_fee'] ?>">

        <div style="padding:15px; background:var(--hover-bg); border-radius:12px; margin-bottom:20px; border-left:4px solid var(--primary);">
            <div class="form-group" style="margin-bottom:10px;">
                <label>Router (Bawaan)</label>
                <select name="router_id" id="sel_router_id" class="form-control" onchange="loadPPPoE(this.value)">
                    <option value="0">-- Akses Manual --</option>
                    <?php
                    $rt_scope = ($u_role === 'admin') ? "WHERE created_by = $u_id OR created_by = 0 OR created_by IS NULL" : "WHERE created_by = $u_id";
                    $routers = []; try { $routers = $db->query("SELECT * FROM routers $rt_scope")->fetchAll(); } catch(Exception $e) {}
                    foreach($routers as $r):
                    ?>
                    <option value="<?= $r['id'] ?>" <?= ($c['router_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:10px;">
                <label>Akun PPPoE (Secret)</label>
                <select name="pppoe_name" id="sel_pppoe_name" class="form-control" data-prev="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>">
                    <option value="<?= htmlspecialchars($c['pppoe_name'] ?? '') ?>"><?= htmlspecialchars($c['pppoe_name'] ?: '-- Pilih Router --') ?></option>
                </select>
            </div>
            <?php if ($u_role === 'admin'): ?>
            <div class="form-group" style="margin-bottom:0;">
                <label>Petugas Penagih (Collector)</label>
                <select name="collector_id" class="form-control">
                    <option value="0">-- Pilih Penagih --</option>
                    <?php
                    $colls = $db->query("SELECT id, name FROM users WHERE role = 'collector' ORDER BY name ASC")->fetchAll();
                    foreach($colls as $coll):
                    ?>
                    <option value="<?= $coll['id'] ?>" <?= ($c['collector_id'] ?? 0) == $coll['id'] ? 'selected' : '' ?>><?= htmlspecialchars($coll['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="collector_id" value="0">
            <?php endif; ?>
        </div>
        
        <?php if($u_role === 'admin'): ?>
        <div class="form-group">
            <label>IP Address & Area Penagihan</label>
            <div class="flex" style="gap:10px;">
                <input type="text" name="ip_address" class="form-control" value="<?= htmlspecialchars($c['ip_address']) ?>" placeholder="IP (192.168.x.x)" style="flex:1;">
                <select name="area" class="form-control" style="flex:2;">
                    <option value="">-- Pilih Area --</option>
                    <?php foreach($areas_all as $a): ?>
                        <option value="<?= htmlspecialchars($a['name']) ?>" <?= $c['area'] == $a['name'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if(!empty($c['area']) && !in_array($c['area'], array_column($areas_all, 'name'))): ?>
                        <option value="<?= htmlspecialchars($c['area']) ?>" selected><?= htmlspecialchars($c['area']) ?> (Legacy)</option>
                    <?php endif; ?>
                </select>
            </div>
            <div style="font-size:10px; color:var(--text-secondary); margin-top:5px;">*Atur daftar area di menu Manajemen Area</div>
        </div>
        <?php else: ?>
            <div class="form-group">
                <label>IP Address</label>
                <input type="text" name="ip_address" class="form-control" value="<?= htmlspecialchars($c['ip_address']) ?>" placeholder="IP (192.168.x.x)">
            </div>
            <input type="hidden" name="area" value="">
        <?php endif; ?>

        <?php if ($u_role === 'admin'): ?>
        <!-- NEW: Infra & GIS Section -->
        <div style="padding:15px; background:rgba(236, 72, 153, 0.05); border-radius:12px; margin-bottom:20px; border:1px solid rgba(236, 72, 153, 0.2);">
            <div style="font-weight:700; color:#ec4899; margin-bottom:12px;"><i class="fas fa-network-wired"></i> Infra & GIS Jaringan</div>
            <div class="flex" style="gap:15px; margin-bottom:15px;">
                <div style="flex:2;">
                    <label style="font-size:12px; margin-bottom:5px; display:block;">Sumber Koneksi (ODP/Switch/Radio)</label>
                    <select name="odp_id" class="form-control">
                        <option value="0">-- Pilih Sumber --</option>
                        <?php 
                        $all_assets = $db->query("SELECT id, name, type, total_ports FROM infrastructure_assets ORDER BY type DESC, name ASC")->fetchAll();
                        foreach($all_assets as $o):
                            $used = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ? AND id != ?");
                            $used->execute([$o['id'], $c['id'] ?? 0]);
                            $count = $used->fetchColumn();
                        ?>
                        <option value="<?= $o['id'] ?>" <?= ($c['odp_id'] ?? 0) == $o['id'] ? 'selected' : '' ?>>
                            <?= $o['type'] ?>: <?= htmlspecialchars($o['name']) ?> (<?= $count ?>/<?= $o['total_ports'] ?> Port)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="font-size:12px; margin-bottom:5px; display:block;">Port ODP</label>
                    <input type="number" name="odp_port" class="form-control" value="<?= $c['odp_port'] ?? '' ?>" placeholder="1-16">
                </div>
            </div>
            <div class="flex" style="gap:15px;">
                <div style="flex:1;">
                    <label style="font-size:12px; margin-bottom:5px; display:block;">Latitude</label>
                    <input type="text" name="lat" class="form-control" value="<?= htmlspecialchars($c['lat'] ?? '') ?>" placeholder="-6.xxx">
                </div>
                <div style="flex:1;">
                    <label style="font-size:12px; margin-bottom:5px; display:block;">Longitude</label>
                    <input type="text" name="lng" class="form-control" value="<?= htmlspecialchars($c['lng'] ?? '') ?>" placeholder="106.xxx">
                </div>
            </div>
            <div style="font-size:11px; margin-top:8px; color:var(--text-secondary);">
                <i class="fas fa-info-circle"></i> Tip: Gunakan Google Maps (Klik kanan di lokasi > Ambil koordinat) atau buka <a href="index.php?page=admin_map" target="_blank" style="color:#ec4899; font-weight:700;">Peta Jaringan</a> untuk referensi.
            </div>
        </div>
        <?php endif; ?>

        <div class="flex" style="gap:15px;">
            <div class="form-group" style="flex:1;">
                <label>Tanggal Registrasi</label>
                <input type="date" name="registration_date" class="form-control" value="<?= htmlspecialchars($c['registration_date']) ?>" required>
            </div>
            <div class="form-group" style="flex:1;">
                <label>Tanggal Tagihan (1-28)</label>
                <input type="number" name="billing_date" class="form-control" min="1" max="28" value="<?= htmlspecialchars($c['billing_date']) ?>" placeholder="1 s/d 28" required>
            </div>
        </div>

        <?php 
        // Summary Tunggakan jika sedang Edit
        if($action === 'edit'): 
            $unpaid_stats = $db->query("SELECT COUNT(*) as count, SUM(amount) as total FROM invoices WHERE customer_id = {$c['id']} AND status = 'Belum Lunas'")->fetch();
            if($unpaid_stats['count'] > 0):
        ?>
            <div style="padding:15px; background:rgba(239, 68, 68, 0.1); border-radius:12px; margin-bottom:20px; border:1px solid rgba(239, 68, 68, 0.3);">
                <div style="font-weight:700; color:var(--danger); margin-bottom:5px;">
                    <i class="fas fa-exclamation-triangle"></i> Status Tunggakan Saat Ini
                </div>
                <div style="font-size:14px;">
                    Pelanggan memiliki <strong><?= $unpaid_stats['count'] ?> bulan</strong> tunggakan belum lunas 
                    dengan total <strong>Rp <?= number_format($unpaid_stats['total'], 0, ',', '.') ?></strong>.
                </div>
            </div>
        <?php 
            endif;
        endif; 
        ?>

        <div style="padding:15px; background:var(--badge-danger-bg); border-radius:12px; margin-bottom:20px; border-left:4px solid var(--danger);">
            <div style="cursor:pointer; font-weight:700; color:var(--danger);" onclick="toggleArrears()">
                <i class="fas fa-history"></i> <?= $action === 'edit' ? 'Tambah Tunggakan Manual' : 'Tunggakan Migrasi (Opsional)' ?> 
                <i class="fas fa-chevron-down" style="float:right;"></i>
            </div>
            <div id="arrearsPanel" style="display:none; margin-top:15px;">
                <p style="font-size:12px; color:var(--text-secondary); margin-bottom:10px;">
                    <?= $action === 'edit' ? 'Gunakan ini untuk memasukkan tunggakan tambahan yang belum tercatat.' : 'Gunakan ini untuk memasukkan data hutang lama pelanggan.' ?>
                </p>
                <div class="flex" style="gap:10px;">
                    <input type="number" name="arrears_months" id="arr_m" class="form-control" min="0" placeholder="Jml Bulan" oninput="prevArr()">
                    <input type="number" name="arrears_amount" id="arr_a" class="form-control" min="0" placeholder="Nominal/Bln (Kosongkan = Biaya Bulanan)" oninput="prevArr()">
                </div>
                <div id="arrPrev" style="font-size:12px; color:var(--danger); margin-top:10px; display:none;"></div>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid var(--glass-border); padding-top:20px;">
            <a href="index.php?page=admin_customers" class="btn btn-ghost">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan</button>
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
<div class="glass-panel" style="padding: 24px; max-width:800px; margin:0 auto;">
    <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-file-import text-success"></i> Import Data Excel / CSV</h3>
    <div style="background:var(--hover-bg); padding:15px; border-radius:12px; margin-bottom:20px; font-size:13px; line-height:1.6;">
        <strong>Format Kolom (Urutan Penting):</strong><br>
        <div style="font-size:11px; opacity:0.7; margin-bottom:10px;">
            Tipe | Nama | Alamat | WhatsApp | Paket | Biaya | IP | Tanggal Registrasi | Tanggal Tagihan | Area
        </div>
        <a href="index.php?page=admin_customers&action=download_template" class="btn btn-sm btn-ghost" style="color:var(--success);"><i class="fas fa-download"></i> Contoh CSV</a>
    </div>
    <form action="index.php?page=admin_customers&action=import_paste" method="POST">
        <div class="form-group">
            <label>Paste Data Anda (dari Excel/Sheets):</label>
            <textarea name="paste_data" class="form-control" rows="12" placeholder="customer [Tab] Budi [Tab] Alamat..." required style="font-family:monospace;"></textarea>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
            <a href="index.php?page=admin_customers" class="btn btn-ghost">Batal</a>
            <button type="submit" class="btn btn-primary">Mulai Import</button>
        </div>
    </form>
</div>

<?php elseif ($action === 'details'): 
    $id = intval($_GET['id']);
    $c = $db->query("SELECT * FROM customers WHERE id = $id")->fetch();
    if(!$c) { echo "Pelanggan tidak ditemukan."; return; }
    
    // Ownership Check
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    $is_owner = ($u_role === 'admin') ? true : (($c['created_by'] == $u_id || $c['created_by'] == 0 || $c['created_by'] === NULL) && $_SESSION['user_role'] === 'collector' ? true : ($c['created_by'] == $u_id));
    if (!$is_owner) {
        echo "<div class='glass-panel p-5 text-center'><h3>Akses Ditolak</h3><p>Anda tidak berwenang melihat data ini.</p><a href='index.php?page=admin_customers' class='btn btn-primary'>Kembali</a></div>";
        return;
    }
    
    // Riwayat Pembayaran (Lunas)
    $history = $db->query("
        SELECT p.*, i.due_date, u.name as receiver_name 
        FROM payments p 
        JOIN invoices i ON p.invoice_id = i.id 
        LEFT JOIN users u ON p.received_by = u.id
        WHERE i.customer_id = $id 
        ORDER BY p.payment_date DESC
    ")->fetchAll();

    // Tagihan Belum Lunas
    $unpaid_invoices = $db->query("
        SELECT * FROM invoices 
        WHERE customer_id = $id AND status = 'Belum Lunas' 
        ORDER BY due_date DESC
    ")->fetchAll();

    // Total Tunggakan / Kekurangan
    $unpaid_total = $db->query("SELECT SUM(amount) FROM invoices WHERE customer_id = $id AND status = 'Belum Lunas'")->fetchColumn() ?: 0;
    
    // Alerts/Messages
    $msg = $_GET['msg'] ?? '';
    $success = $_GET['success'] ?? '';
?>

<?php if($msg === 'invoice_created'): ?>
    <div class="glass-panel" style="background:rgba(34,197,94,0.1); border-left:4px solid var(--success); padding:15px; margin-bottom:20px; color:var(--success); font-weight:600;">
        <i class="fas fa-check-circle"></i> Berhasil! Tagihan manual untuk bulan ini telah diterbitkan.
    </div>
<?php elseif($msg === 'invoice_itemized_created'): ?>
    <div class="glass-panel" style="background:rgba(34,197,94,0.1); border-left:4px solid var(--success); padding:15px; margin-bottom:20px; color:var(--success); font-weight:600;">
        <i class="fas fa-check-circle"></i> Berhasil! Tagihan rincian (Add-ons) telah diterbitkan.
    </div>
<?php elseif($msg === 'bulk_payment_success' || $success === 'bulk'): ?>
    <div class="glass-panel" style="background:rgba(34,197,94,0.1); border-left:4px solid var(--success); padding:15px; margin-bottom:20px; color:var(--success); font-weight:600;">
        <i class="fas fa-check-circle"></i> Berhasil! Pembayaran untuk beberapa bulan telah tercatat.
    </div>
<?php endif; ?>

<div style="display:flex; flex-direction:column; gap:25px;">
    <!-- Customer Info Brief -->
    <div class="glass-panel" style="padding: 24px; position:relative; overflow:hidden;">
        <div style="position:absolute; top:-20px; right:-20px; font-size:120px; opacity:0.03; transform:rotate(-15deg);"><i class="fas fa-user-circle"></i></div>
        
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px;">
            <div>
                <a href="index.php?page=admin_customers" class="btn btn-sm btn-ghost" style="margin-bottom:15px;"><i class="fas fa-arrow-left"></i> Kembali</a>
                <h2 style="font-size:24px; font-weight:800; margin-bottom:5px;"><?= htmlspecialchars($c['name']) ?></h2>
                <div style="font-family:monospace; color:var(--primary); font-weight:700;"><?= htmlspecialchars($c['customer_code']) ?></div>
            </div>
            <div style="text-align:right;">
                <div class="badge badge-<?= $c['type']=='customer'?'success':'warning' ?>" style="font-size:14px; padding:6px 15px;"><?= strtoupper($c['type']) ?></div>
                <div style="margin-top:10px; font-weight:600; font-size:18px; color:var(--primary);">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?> / bulan</div>
            </div>
        </div>
        
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-top:30px;">
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border);">
                <div style="font-size:12px; color:var(--text-secondary); margin-bottom:5px;">INFO LAYANAN</div>
                <div style="font-weight:600;"><i class="fas fa-box text-primary"></i> <?= htmlspecialchars($c['package_name']) ?></div>
                <div style="font-size:13px; margin-top:4px;"><i class="fas fa-calendar-alt text-primary"></i> Tagihan: Tanggal <?= $c['billing_date'] ?></div>
            </div>
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border);">
                <div style="font-size:12px; color:var(--text-secondary); margin-bottom:5px;">KONTAK & ALAMAT</div>
                <div style="font-weight:600;"><i class="fab fa-whatsapp text-success"></i> <?= htmlspecialchars($c['contact']) ?></div>
                <div style="font-size:13px; margin-top:4px;"><i class="fas fa-map-marker-alt text-danger"></i> <?= htmlspecialchars($c['area']) ?></div>
            </div>
            <!-- New Arrears Card -->
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid <?= $unpaid_total > 0 ? 'var(--danger)' : 'var(--glass-border)' ?>;">
                <div style="font-size:12px; color:var(--text-secondary); margin-bottom:5px;">
                    <?= $c['type'] === 'partner' ? 'KEKURANGAN MITRA' : 'SISA TAGIHAN' ?>
                </div>
                <div style="font-size:18px; font-weight:800; color:<?= $unpaid_total > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                    Rp <?= number_format($unpaid_total, 0, ',', '.') ?>
                </div>
                <div style="font-size:11px; margin-top:4px; color:var(--text-secondary);">
                    <?= $unpaid_total > 0 ? 'Segera lakukan penagihan' : 'Semua tagihan lunas' ?>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 320px; gap:25px; align-items:start;" class="details-grid-container">
        <!-- Riwayat Pembayaran -->
        <!-- Section Penagihan -->
        <div class="glass-panel" style="padding: 24px;">
            <div style="margin-bottom:25px;">
                <h3 style="font-size:18px; margin-bottom:15px; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-file-invoice text-danger"></i> Tagihan Belum Lunas
                </h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Periode</th>
                                <th>Nominal</th>
                                <th style="text-align:right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($unpaid_invoices)): ?>
                                <tr><td colspan="3" style="text-align:center; padding:20px; color:var(--text-secondary);">Semua tagihan lunas! 🎉</td></tr>
                            <?php endif; ?>
                            <?php foreach($unpaid_invoices as $ui): ?>
                                <tr>
                                    <td><strong><?= date('M Y', strtotime($ui['due_date'])) ?></strong></td>
                                    <td style="font-weight:700; color:var(--danger);">Rp <?= number_format($ui['amount'] - ($ui['discount'] ?? 0), 0, ',', '.') ?></td>
                                    <td style="text-align:right;">
                                        <div style="display:flex; justify-content:flex-end; gap:6px;">
                                            <?php 
                                                $wa_raw = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $c['contact']));
                                                $wa_link = "https://api.whatsapp.com/send?phone=$wa_raw&text=" . urlencode("Halo Bapak/Ibu " . $c['name'] . ", mengingatkan untuk tagihan internet periode " . date('F Y', strtotime($ui['due_date'])) . " sebesar Rp " . number_format($ui['amount'], 0, ',', '.') . " sudah jatuh tempo. Mohon kesediaannya untuk melakukan pembayaran. Terima kasih.");
                                            ?>
                                            <a href="<?= $wa_link ?>" target="_blank" class="btn btn-xs btn-ghost" style="color:#25D366;" title="Kirim Pengingat WA"><i class="fab fa-whatsapp"></i></a>
                                            <a href="index.php?page=admin_invoices&action=mark_paid&id=<?= $ui['id'] ?>&ref=customer_details&cust_id=<?= $id ?>" class="btn btn-xs btn-success" onclick="return confirm('Tandai tagihan ini Lunas?')"><i class="fas fa-check"></i> Bayar</a>
                                            <button onclick="showEditInvoice(<?= $ui['id'] ?>, <?= $ui['amount'] ?>, <?= $ui['discount'] ?? 0 ?>, '<?= $ui['due_date'] ?>')" class="btn btn-xs btn-warning" title="Edit"><i class="fas fa-edit"></i></button>
                                            <a href="index.php?page=admin_invoices&action=delete&id=<?= $ui['id'] ?>&ref=customer_details&cust_id=<?= $id ?>" class="btn btn-xs btn-ghost" style="color:var(--danger);" onclick="return confirm('Hapus tagihan ini?')"><i class="fas fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <hr style="border:0; border-top:1px solid var(--glass-border); margin:25px 0;">

            <h3 style="font-size:18px; margin-bottom:20px;"><i class="fas fa-history text-primary"></i> Riwayat Pembayaran (Lunas)</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Bulan Tagihan</th>
                            <th>Nominal</th>
                            <th>Tanggal Bayar</th>
                            <th>Diterima Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($history)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:30px; color:var(--text-secondary);">Belum ada riwayat pembayaran.</td></tr>
                        <?php endif; ?>
                        <?php foreach($history as $h): ?>
                            <tr>
                                <td><strong><?= date('F Y', strtotime($h['due_date'])) ?></strong></td>
                                <td style="font-weight:700; color:var(--success);">Rp <?= number_format($h['amount'], 0, ',', '.') ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($h['payment_date'])) ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($h['receiver_name'] ?: 'System') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section Bayar Banyak Bulan -->
        <div class="glass-panel" style="padding: 24px; border-top:4px solid var(--success);">
            <h3 style="font-size:18px; margin-bottom:15px;"><i class="fas fa-money-bill-wave text-success"></i> Bayar Banyak Bulan</h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">Gunakan fitur ini jika pelanggan ingin membayar untuk bulan ini dan bulan-bulan berikutnya sekaligus secara manual.</p>
            
            <form action="index.php?page=admin_customers&action=bulk_pay" method="POST">
                <input type="hidden" name="customer_id" value="<?= $id ?>">
                <input type="hidden" name="amount_per_month" value="<?= $c['monthly_fee'] ?>">
                
                <div class="form-group">
                    <label>Jumlah Bulan</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="number" name="num_months" class="form-control" value="1" min="1" max="12" required style="font-size:20px; font-weight:800; text-align:center;">
                        <div style="font-weight:600;">Bulan</div>
                    </div>
                </div>
                
                <div style="padding:15px; background:var(--nav-active-bg); border-radius:10px; margin-bottom:20px;">
                    <div style="font-size:12px; color:var(--text-secondary);">ESTIMASI TOTAL:</div>
                    <div style="font-size:20px; font-weight:800; color:var(--success);" id="bulk_total_display">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></div>
                </div>

                <button type="submit" class="btn btn-success" style="width:100%; border-radius:12px; padding:15px;" onclick="return confirm('Proses pembayaran banyak bulan untuk pelanggan ini?')">
                    <i class="fas fa-check-circle"></i> PROSES BAYAR
                </button>
            </form>
        </div>

        <!-- Section Tagihan Khusus / Add-ons -->
        <div class="glass-panel" style="padding: 24px; border-top:4px solid var(--primary); margin-top:20px;">
            <h3 style="font-size:18px; margin-bottom:15px;"><i class="fas fa-plus-circle text-primary"></i> Tagihan Khusus / Add-ons</h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px;">Gunakan ini untuk membuat tagihan dengan rincian item (Biaya Bulanan + Extra).</p>
            
            <form action="index.php?page=admin_invoices&action=create_itemized" method="POST" id="itemizedForm">
                <input type="hidden" name="customer_id" value="<?= $id ?>">
                
                <div class="form-group">
                    <label>Jatuh Tempo</label>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                </div>

                <div id="itemsContainer">
                    <div style="display:grid; grid-template-columns: 1fr 140px 40px; gap:10px; margin-bottom:10px; align-items:center;">
                        <input type="text" name="item_desc[]" class="form-control" value="Biaya Langganan Bulanan" placeholder="Deskripsi" required>
                        <input type="number" name="item_amount[]" class="form-control item-amount" value="<?= $c['monthly_fee'] ?>" placeholder="Nominal" required>
                        <span></span>
                    </div>
                </div>

                <button type="button" class="btn btn-sm btn-ghost" onclick="addItemRow()" style="margin-bottom:20px; color:var(--primary);">
                    <i class="fas fa-plus"></i> Tambah Item
                </button>

                <div style="padding:15px; background:var(--nav-active-bg); border-radius:10px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <div style="font-size:12px; color:var(--text-secondary);">SUBTOTAL:</div>
                        <div style="font-size:16px; font-weight:700;" id="itemized_subtotal_display">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label style="font-size:11px; color:var(--danger); font-weight:700;">Potongan / Diskon (Rp):</label>
                        <input type="number" name="invoice_discount" class="form-control" value="0" oninput="updateItemizedTotal()" style="padding:5px 10px; border:1px solid var(--danger); font-weight:700; color:var(--danger);">
                    </div>
                </div>

                <div style="padding:15px; background:rgba(37, 99, 235, 0.1); border-radius:10px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; border:2px dashed var(--primary);">
                    <div style="font-size:12px; color:var(--text-secondary); font-weight:800;">TOTAL AKHIR:</div>
                    <div style="font-size:22px; font-weight:800; color:var(--primary);" id="itemized_total_display">Rp <?= number_format($c['monthly_fee'], 0, ',', '.') ?></div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; border-radius:12px; padding:15px;">
                    <i class="fas fa-file-invoice-dollar"></i> TERBITKAN TAGIHAN
                </button>
            </form>
        </div>
    </div>
</div>

<script>
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
        <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove(); updateItemizedTotal();" style="padding:8px;"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(div);
    
    // Add event listener to new input
    div.querySelector('.item-amount').addEventListener('input', updateItemizedTotal);
}

function updateItemizedTotal() {
    const amounts = document.querySelectorAll('.item-amount');
    const discountInput = document.querySelector('input[name="invoice_discount"]');
    const discount = parseInt(discountInput ? discountInput.value : 0) || 0;
    
    let subtotal = 0;
    amounts.forEach(input => {
        subtotal += parseInt(input.value) || 0;
    });
    
    let total = subtotal - discount;
    
    const formattedSub = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(subtotal).replace('IDR', 'Rp');
    const formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(total).replace('IDR', 'Rp');
    
    document.getElementById('itemized_subtotal_display').innerText = formattedSub;
    document.getElementById('itemized_total_display').innerText = formattedTotal;
}

// Initial listener
document.querySelector('.item-amount').addEventListener('input', updateItemizedTotal);
</script>

<style>
@media (max-width: 900px) {
    .details-grid-container { grid-template-columns: 1fr !important; }
}

/* Mobile responsive toggle for customer list */
@media (max-width: 768px) {
    .customers-desktop-table { display: none !important; }
    .customers-mobile-container { display: block !important; }
    .hide-mobile { display: none !important; }
}
</style>

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
<div id="tr069Modal" class="modal" style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; overflow:auto; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(5px);">
    <div class="glass-panel" style="background: var(--bg-color); margin: 5% auto; padding: 25px; border-radius: 20px; width: 90%; max-width: 600px; border: 1px solid var(--glass-border); position:relative;">
        <span onclick="document.getElementById('tr069Modal').style.display='none'" style="position:absolute; right:20px; top:15px; font-size:28px; font-weight:bold; cursor:pointer; color:var(--text-secondary);">&times;</span>
        <h3 style="margin-bottom:20px;"><i class="fas fa-satellite-dish text-primary"></i> Monitoring ONT (TR-069)</h3>
        <div id="tr069-content">
            <div style="text-align:center; padding:40px;">
                <i class="fas fa-circle-notch fa-spin fa-2x text-primary"></i>
                <p style="margin-top:15px; color:var(--text-secondary);">Menghubungkan ke server ACS...</p>
            </div>
        </div>
    </div>
</div>


<!-- Modal Edit Invoice -->
<div id="editInvoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px; border-top:4px solid var(--warning);">
        <h3 id="editTitle" style="margin-bottom:20px; font-weight:800;"><i class="fas fa-edit text-warning"></i> Edit Tagihan</h3>
        <form action="index.php?page=admin_invoices&action=edit_post" method="POST">
            <input type="hidden" name="id" id="editInvId">
            <input type="hidden" name="ref" value="customer_details">
            <input type="hidden" name="cust_id" value="<?= $id ?>">
            <div class="form-group mb-3">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Nominal Tagihan (Rp)</label>
                <input type="number" name="amount" id="editInvAmount" class="form-control" required style="font-size:18px; font-weight:700; height:45px;">
            </div>
            <div class="form-group mb-3">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Potongan / Restitusi (Rp)</label>
                <input type="number" name="discount" id="editInvDiscount" class="form-control" value="0" style="color:var(--danger); font-weight:700;">
            </div>
            <div class="form-group mb-4">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Jatuh Tempo</label>
                <input type="date" name="due_date" id="editInvDate" class="form-control" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-ghost" onclick="hideEditInvoice()">Batal</button>
                <button type="submit" class="btn btn-warning" style="font-weight:800; padding:10px 25px;">SIMPAN PERUBAHAN</button>
            </div>
        </form>
    </div>
</div>

<script>
function showEditInvoice(id, amount, discount, date) {
    document.getElementById('editInvId').value = id;
    document.getElementById('editInvAmount').value = amount;
    document.getElementById('editInvDiscount').value = discount;
    document.getElementById('editInvDate').value = date;
    document.getElementById('editTitle').innerText = 'Edit INV-' + String(id).padStart(5, '0');
    document.getElementById('editInvoiceModal').style.display = 'flex';
}
function hideEditInvoice() {
    document.getElementById('editInvoiceModal').style.display = 'none';
}

function viewTR069(pppoe) {
    const modal = document.getElementById('tr069Modal');
    const content = document.getElementById('tr069-content');
    modal.style.display = 'block';
    if(window.innerWidth < 900) modal.style.paddingTop = '20px';
    
    content.innerHTML = '<div style="text-align:center; padding:40px;"><i class="fas fa-circle-notch fa-spin fa-2x text-primary"></i><p style="margin-top:15px; color:var(--text-secondary);">Mengambil data perangkat...</p></div>';
    
    fetch('views/components/tr069_monitor.php?pppoe=' + encodeURIComponent(pppoe))
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = '<div class="alert alert-danger">Gagal memuat data monitoring.</div>';
        });
}
window.onclick = function(event) {
    const modal = document.getElementById('tr069Modal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
</script>
