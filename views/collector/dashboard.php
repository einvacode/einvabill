<?php
// Data collector
$user_id = $_SESSION['user_id'];
$tenant_id = $_SESSION['tenant_id'] ?? 1;
// Base filter: assigned collector id
$collector_id = $_SESSION['user_id'];

// Get collector area assignment
$collector_area = $db->query("SELECT area FROM users WHERE id = " . intval($collector_id) . " AND tenant_id = $tenant_id")->fetchColumn() ?: '';

$collector_area_val = trim($collector_area);
// --- DYNAMIC AREA FILTER ---
$filter_area = $_GET['filter_area'] ?? $collector_area_val;
$areas = $db->query("SELECT * FROM areas WHERE tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();

// Base filter: ALWAYS restricted to assigned collector ID as requested by user
$base_scope = " AND c.collector_id = " . intval($collector_id) . " AND c.tenant_id = $tenant_id ";

if (!empty($filter_area)) {
    $area_filter = $base_scope . " AND c.area = " . $db->quote($filter_area);
} else {
    $area_filter = $base_scope;
}

// Tab navigation state
$coll_tab = $_GET['tab'] ?? 'summary';

// Billing date filter
$filter_billing_date = $_GET['filter_billing_date'] ?? '';
$billing_where = "";
if ($filter_billing_date !== "") {
    $billing_where = " AND c.billing_date = " . intval($filter_billing_date);
}

// Date range calculation: priority to GET params, fallback to current month
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

// Ensure correct format for SQL
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

// Total tagihan belum lunas (JUMLAH PELANGGAN & NOMINAL) - RESPECT MONTH RANGE
$unpaid_customers_count = $db->query("
    SELECT COUNT(DISTINCT customer_id) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $area_filter $billing_where $where_search_tugas
")->fetchColumn();

// Keep original unpaid_count (total invoices) for the badge
$unpaid_count = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $area_filter $billing_where $where_search_tugas
")->fetchColumn();

$unpaid_total = $db->query("
    SELECT COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $area_filter $billing_where $where_search_tugas
")->fetchColumn();

// Total tagihan lunas dalam periode yang dipilih
$paid_count_range = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN payments p ON p.invoice_id = i.id
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $area_filter $billing_where
")->fetchColumn() ?: 0;

$paid_total_range = $db->query("
    SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $area_filter $billing_where
")->fetchColumn() ?: 0;

// Pelanggan Baru dalam periode ini
$new_customers_range = $db->query("
    SELECT COUNT(*) FROM customers c 
    WHERE c.registration_date BETWEEN '$date_from' AND '$date_to'
    $area_filter $billing_where
")->fetchColumn();

// Total Pengeluaran (RESPECT DATE RANGE - showing only this collector's expenses)
$expenses_range = $db->query("
    SELECT COALESCE(SUM(amount), 0) FROM expenses 
    WHERE date BETWEEN '$date_from' AND '$date_to'
    AND created_by = " . intval($collector_id) . "
    AND tenant_id = $tenant_id
")->fetchColumn();

// List of Expenses for the period
$recent_expenses = $db->query("
    SELECT * FROM expenses 
    WHERE date BETWEEN '$date_from' AND '$date_to'
    AND created_by = " . intval($collector_id) . "
    AND tenant_id = $tenant_id
    ORDER BY date DESC, id DESC
")->fetchAll();

// Total Net Revenue (Payments - Expenses)
$net_revenue = $paid_total_range - $expenses_range;

// Persentase progres penagihan (Monetary Based)
$total_potential = $unpaid_total + $paid_total_range;
$percent_paid = $total_potential > 0 ? round(($paid_total_range / $total_potential) * 100) : 0;
$percent_paid = min(100, $percent_paid); // Cap at 100%

// Pagination Logic for Tasks
$items_per_page = 50;
$p_tugas = isset($_GET['p_tugas']) ? max(1, intval($_GET['p_tugas'])) : 1;
$off_tugas = ($p_tugas - 1) * $items_per_page;
$total_tugas_pages = ceil($unpaid_customers_count / $items_per_page);

// Fetch unique customers with aggregated arrears
$query = "
    SELECT 
        c.id as cust_id, c.name, c.address, c.contact, c.area as cust_area, c.customer_code, c.package_name, c.type as customer_type, c.billing_date,
        COUNT(i.id) as num_arrears,
        SUM(i.amount - COALESCE(i.discount, 0)) as total_unpaid,
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
    (SELECT COALESCE(SUM(amount - COALESCE(discount, 0)), 0) FROM invoices WHERE customer_id = i.customer_id AND status = 'Belum Lunas') as total_tunggakan
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

// Fetch current user brand/settings
$me = $db->query("SELECT * FROM users WHERE id = " . intval($_SESSION['user_id']) . " AND tenant_id = $tenant_id")->fetch();
$settings = $db->query("SELECT company_name, wa_template, wa_template_paid, site_url, bank_account FROM settings WHERE tenant_id = $tenant_id")->fetch();
if (!$settings) {
    $settings = $db->query("SELECT company_name, wa_template, wa_template_paid, site_url, bank_account FROM settings WHERE id = 1")->fetch();
}

$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();

// Template Resolution: Individual -> Core fallback -> Hardcoded
$wa_tpl = !empty($me['wa_template']) ? $me['wa_template'] : ($settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}");
$wa_tpl_paid = !empty($me['wa_template_paid']) ? $me['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah lunas.");
$my_bank_info = !empty($me['brand_bank']) ? ($me['brand_bank'] . " " . $me['brand_rekening']) : ($settings['bank_account'] ?? 'Hubungi CS');

// Fetch Banners for Collector
$banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'collector') AND tenant_id = $tenant_id ORDER BY created_at DESC")->fetchAll();

// Success Modal Data for Collector
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid AND tenant_id = $tenant_id")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact']));
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas' AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $status_wa = ($tunggakan_val > 0) ? "LUNAS SEBAGIAN (Masih ada sisa tunggakan)" : "LUNAS SEPENUHNYA";
        
        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        $receipt_msg = parse_wa_template($wa_tpl_paid, [
            'name' => $success_data['name'],
            'id_cust' => ($success_data['customer_code'] ?: $success_data['id']),
            'package' => ($success_data['package_name'] ?: '-'),
            'period' => $months_paid . ' Bulan',
            'tagihan' => $success_data['monthly_fee'],
            'tunggakan' => $tunggakan_val,
            'payment_time' => date('d/m/Y H:i') . ' WIB',
            'admin_name' => $_SESSION['user_name'],
            'portal_link' => $portal_link,
            'rekening' => trim($my_bank_info),
            'total_paid' => $total_paid,
            'payment_status' => $status_wa,
            'sisa_tunggakan' => $tunggakan_val
        ]);
        $success_data['wa_link_msg'] = $receipt_msg;
        $success_data['wa_num'] = $wa_num_paid;
    }
}

// Fetch Packages & Areas for Adding Customers
$packages_all = $db->query("SELECT * FROM packages WHERE created_by NOT IN (SELECT id FROM users WHERE role = 'partner') OR created_by = 0 OR created_by IS NULL ORDER BY name ASC")->fetchAll();
$areas_all = $db->query("SELECT * FROM areas ORDER BY name ASC")->fetchAll();

// Handle manual invoice creation by collector
if (isset($_GET['action']) && $_GET['action'] === 'create_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = intval($_POST['customer_id']);
    $amt = floatval($_POST['amount']);
    $due = $_POST['due_date'];
    $now = date('Y-m-d H:i:s');
    $collector_id = $_SESSION['user_id'];

    // 1. Create Invoice
    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Lunas', ?)");
    $stmt->execute([$cid, $amt, $due, $now]);
    $invoice_id = $db->lastInsertId();

    // 2. Create Payment for manual settlement
    $stmt_pay = $db->prepare("INSERT INTO payments (invoice_id, amount, payment_date, received_by) VALUES (?, ?, ?, ?)");
    $stmt_pay->execute([$invoice_id, $amt, $now, $collector_id]);

    header("Location: index.php?page=collector&msg=invoice_created");
    exit;
}

// [DELETED] redundant update_contact handler removed to consolidate logic below

// Handle Add Customer by collector
if (isset($_GET['action']) && $_GET['action'] === 'add_customer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    $package_name = $_POST['package_name'];
    $monthly_fee = $_POST['monthly_fee'];
    $type = $_POST['type'] ?? 'customer';
    $registration_date = $_POST['registration_date'] ?: date('Y-m-d');
    $previous_debt = floatval($_POST['previous_debt'] ?? 0);
    $billing_date = intval($_POST['billing_date'] ?: 1);
    $area = $_POST['area'] ?? '';
    $collector_id = $_SESSION['user_id'];
    
    // Auto-generate unique random customer code
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
    do {
        $customer_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $stmt_check->execute([$customer_code]);
    } while ($stmt_check->fetchColumn() > 0);
    
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, type, registration_date, billing_date, area, collector_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_code, $name, $address, $contact, $package_name, $monthly_fee, $type, $registration_date, $billing_date, $area, $collector_id, $user_id]);
        $new_id = $db->lastInsertId();

        // Automated Billing Loop (Back-billing logic)
        $now = date('Y-m-d H:i:s');
        if ($monthly_fee > 0) {
            if ($type === 'customer') {
                // 1. Initial Invoice (Registration Month)
                $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
                $stmt_inv->execute([$new_id, $monthly_fee, $registration_date, $now]);

                // 2. Subsequent Monthly Invoices (Until Today)
                $month_idx = 1;
                while (true) {
                    $next_month_ts = strtotime("+$month_idx month", strtotime($registration_date));
                    $bday = str_pad($billing_date, 2, '0', STR_PAD_LEFT);
                    $next_due = date('Y-m', $next_month_ts) . '-' . $bday;
                    
                    if ($next_due > date('Y-m-d')) break; // Stop at future dates
                    
                    $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)")
                    ->execute([$new_id, $monthly_fee, $next_due, $now]);
                    
                    $month_idx++;
                    if($month_idx > 60) break; // Safety cap
                }
            } else {
                // MITRA: Always starts 1 month after registration
                $month_idx = 1;
                while (true) {
                    $next_month_ts = strtotime("+$month_idx month", strtotime($registration_date));
                    $bday = str_pad($billing_date, 2, '0', STR_PAD_LEFT);
                    $next_due = date('Y-m', $next_month_ts) . '-' . $bday;
                    
                    if ($next_due > date('Y-m-d')) break;
                    
                    $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)")
                    ->execute([$new_id, $monthly_fee, $next_due, $now]);
                    
                    $month_idx++;
                    if($month_idx > 60) break;
                }
            }
        }

        // Create extra invoice for previous debt if any
        if ($previous_debt > 0) {
            $stmt_debt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
            $stmt_debt->execute([$new_id, $previous_debt, $registration_date, $now]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: index.php?page=collector&tab=pelanggan&msg=error&err=" . urlencode($e->getMessage()));
        exit;
    }

    header("Location: index.php?page=collector&tab=pelanggan&msg=customer_added");
    exit;
}

// Handle Add Expense by collector
if (isset($_GET['action']) && $_GET['action'] === 'add_expense' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'];
    $amount = floatval($_POST['amount']);
    $description = $_POST['description'];
    $date = $_POST['date'] ?: date('Y-m-d');
    $collector_id = $_SESSION['user_id'];
    
    $stmt = $db->prepare("INSERT INTO expenses (category, amount, description, date, created_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$category, $amount, $description, $date, $collector_id]);
    
    header("Location: index.php?page=collector&msg=expense_added");
    exit;
}

// Handle Add Add-on by collector
if (isset($_GET['action']) && $_GET['action'] === 'add_addon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = intval($_POST['customer_id']);
    $item_name = $_POST['description']; // Using 'description' for consistency with modal field name
    $amount = floatval($_POST['amount']);
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    
    // 1. Create Invoice
    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at) VALUES (?, ?, ?, 'Belum Lunas', ?)");
    $stmt->execute([$cid, $amount, $today, $now]);
    $invoice_id = $db->lastInsertId();
    
    // 2. Create Invoice Item
    $stmt_item = $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)");
    $stmt_item->execute([$invoice_id, $item_name, $amount]);
    
    header("Location: index.php?page=collector&msg=addon_added");
    exit;
}

// Handle Update Profile by collector (Expanded from just contact)
if (isset($_GET['action']) && $_GET['action'] === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = intval($_POST['customer_id']);
    $name = $_POST['name'];
    $address = $_POST['address'];
    $contact = $_POST['contact'];
    
    $stmt = $db->prepare("UPDATE customers SET name = ?, address = ?, contact = ? WHERE id = ?");
    $stmt->execute([$name, $address, $contact, $cid]);
    
    header("Location: " . $_SERVER['HTTP_REFERER'] . "&msg=profile_updated");
    exit;
}

$filter_status = $_GET['filter_status'] ?? 'all';
$status_filter_sql = "";
if ($filter_status === 'unpaid') {
    $status_filter_sql = " AND EXISTS (SELECT 1 FROM invoices i WHERE i.customer_id = c.id AND i.status = 'Belum Lunas')";
} elseif ($filter_status === 'paid') {
    $status_filter_sql = " AND EXISTS (SELECT 1 FROM payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.customer_id = c.id AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to')";
} elseif ($filter_status === 'new') {
    $status_filter_sql = " AND c.registration_date BETWEEN '$date_from' AND '$date_to'";
}

// Fetch all customers with pagination
$p_cust = isset($_GET['p_cust']) ? max(1, intval($_GET['p_cust'])) : 1;
$off_cust = ($p_cust - 1) * $items_per_page;
$total_cust_pages = ceil($total_customers / $items_per_page);

$cust_query = "SELECT c.*, 
                (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND status = 'Belum Lunas') as unpaid_count
                FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust $status_filter_sql ORDER BY c.billing_date ASC LIMIT $items_per_page OFFSET $off_cust";
$area_customers = $db->query($cust_query)->fetchAll();

// Calculate Estimated Revenue (potential) for filtered customers
$total_estimasi = $db->query("SELECT COALESCE(SUM(c.monthly_fee), 0) FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust $status_filter_sql")->fetchColumn();
$total_cust_filter = $db->query("SELECT COUNT(*) FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust $status_filter_sql")->fetchColumn();

$total_revenue_all = $db->query("SELECT COALESCE(SUM(c.monthly_fee), 0) FROM customers c WHERE 1=1 $area_filter")->fetchColumn();

$coll_tab = $_GET['tab'] ?? 'tugas';
?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'invoice_created'): ?>
<div class="ui-card mb-4 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> Tagihan berhasil dibuat.</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'profile_updated'): ?>
<div class="ui-card mb-4 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> Profil pelanggan berhasil diperbarui.</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'unpay_success'): ?>
<div class="ui-card mb-4 border-danger/40 p-4 text-sm"><span class="font-semibold text-danger">Pembayaran dibatalkan.</span> Tagihan pelanggan kembali masuk ke <strong>Tugas penagihan</strong>.</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'addon_added'): ?>
<div class="ui-card mb-4 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> Tagihan add-on berhasil ditambahkan.</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'customer_added'): ?>
<div class="ui-card mb-4 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> Pelanggan baru berhasil ditambahkan dan ditugaskan kepada Anda.</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'expense_added'): ?>
<div class="ui-card mb-4 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> Pengeluaran berhasil dicatat.</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'paid' && isset($_GET['last_id'])):
    $last_id = intval($_GET['last_id']);
    $inv_data = $db->query("
        SELECT i.*, c.name, c.contact, c.customer_code, c.package_name, c.monthly_fee,
        (SELECT payment_date FROM payments WHERE invoice_id = i.id ORDER BY id DESC LIMIT 1) as payment_date,
        (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE customer_id = i.customer_id AND status = 'Belum Lunas') as total_tunggakan
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.id = $last_id
    ")->fetch();
    $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv_data['contact'] ?? ''));

    // Parse Full Tagihan Lunas Template
    $bulan_inv = date('m/Y', strtotime($inv_data['due_date']));

        $status_wa = ($inv_data['total_tunggakan'] > 0) ? "LUNAS SEBAGIAN (Masih ada sisa tunggakan)" : "LUNAS SEPENUHNYA";
        $tunggakan_display = 'Rp ' . number_format($inv_data['total_tunggakan'], 0, ',', '.');
        $portal_link = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . ($inv_data['customer_code'] ?: $inv_data['customer_id']);

        $receipt_msg = parse_wa_template($wa_tpl_paid, [
            'name' => $inv_data['name'],
            'id_cust' => ($inv_data['customer_code'] ?: $inv_data['customer_id']),
            'tagihan' => ($inv_data['monthly_fee'] ?: ($inv_data['amount'])),
            'package' => ($inv_data['package_name'] ?: '-'),
            'period' => '1 Bulan',
            'tunggakan' => $tunggakan_display,
            'payment_time' => date('d/m/Y H:i', strtotime($inv_data['payment_date'] ?: 'now')) . ' WIB',
            'admin_name' => $_SESSION['user_name'],
            'company_name' => $settings['company_name'],
            'portal_link' => $portal_link,
            'payment_status' => $status_wa,
            'sisa_tunggakan' => $tunggakan_display,
            'total_paid' => $inv_data['amount'],
            'rekening' => $my_bank_info
        ]);
    $wa_msg = urlencode($receipt_msg);
?>

<?php if($coll_tab === 'summary'): ?>
<!-- Receipt card after payment (summary tab) -->
<div class="ui-card success-receipt-modal mb-4 p-4 sm:p-5">
    <div class="mb-4 flex flex-nowrap items-start justify-between gap-4">
        <div>
            <h3 class="m-0 text-[15px] font-bold text-signal">Pembayaran berhasil</h3>
            <p class="m-0 mt-1 text-xs text-muted-foreground">Kwitansi siap untuk dikirim/cetak untuk <strong><?= htmlspecialchars($inv_data['name']) ?></strong></p>
        </div>
        <button type="button" onclick="this.parentElement.parentElement.style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php if($wa_num): ?>
            <button onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= $wa_msg ?>', this)" class="ui-btn ui-btn-wa flex-1 sm:flex-none">
                <i class="fab fa-whatsapp"></i> Kirim kwitansi
            </button>
        <?php endif; ?>
        <a href="index.php?page=invoice_print&id=<?= $last_id ?>&format=thermal" target="_blank" class="ui-btn ui-btn-outline flex-1 sm:flex-none">
            <i class="fas fa-print"></i> Cetak struk
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
    <div class="ui-card mb-4 p-4 text-sm font-semibold text-signal empty:hidden"><?php
                if($_GET['msg'] == 'customer_added') echo 'Berhasil mendaftarkan pelanggan baru.';
                if($_GET['msg'] == 'expense_added') echo 'Pengeluaran telah dicatat.';
                if($_GET['msg'] == 'addon_added') echo 'Tagihan add-on berhasil ditambahkan.';
                if($_GET['msg'] == 'profile_updated') echo 'Profil pelanggan berhasil diperbarui.';
                if($_GET['msg'] == 'invoice_created') echo 'Tagihan manual berhasil dibuat.';
            ?></div>
<?php endif; ?>

<!-- Full page header (shown after a payment) -->
<div class="mb-5">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div class="flex items-center gap-3">
            <button type="button" class="burger-btn ui-btn ui-btn-sm ui-btn-outline lg:hidden" onclick="toggleSidebar()" aria-label="Buka menu"><i class="fas fa-bars"></i></button>
            <div>
                <h2 class="m-0 text-xl font-bold sm:text-2xl">Command center</h2>
                <p class="m-0 mt-1 text-sm text-muted-foreground"><?= $_SESSION['user_name'] ?> &middot; Collector hub</p>
            </div>
        </div>
        <div class="flex w-full flex-nowrap items-center gap-2 sm:w-auto sm:min-w-[320px] sm:max-w-md sm:flex-1">
            <form method="GET" class="relative flex-1">
                <input type="hidden" name="page" value="collector">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($coll_tab) ?>">
                <input type="hidden" name="date_from" value="<?= $date_from ?>">
                <input type="hidden" name="date_to" value="<?= $date_to ?>">
                <input type="hidden" name="filter_area" value="<?= $filter_area ?>">
                <input type="text" name="<?= ($coll_tab === 'tugas' ? 'search_tugas' : 'search_cust') ?>" class="form-control w-full pr-10" placeholder="Cari..." value="<?= htmlspecialchars($coll_tab === 'tugas' ? $search_tugas : $search_cust) ?>">
                <button type="submit" class="absolute right-1 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-md border-0 bg-transparent text-muted-foreground hover:text-foreground" aria-label="Cari"><i class="fas fa-search"></i></button>
            </form>
            <button type="button" onclick="openFilterModal()" class="ui-btn ui-btn-outline shrink-0" aria-label="Filter" title="Filter">
                <i class="fas fa-filter"></i>
            </button>
        </div>
    </div>

    <!-- Tab Bar Navigation -->
    <div class="overflow-x-auto">
        <div class="flex w-fit flex-nowrap gap-1 rounded-md bg-muted p-1">
            <a href="index.php?page=collector&tab=summary&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'summary' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'summary' ? ' aria-current="page"' : '' ?>>Home</a>
            <a href="index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'tugas' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'tugas' ? ' aria-current="page"' : '' ?>>
                Tugas
                <?php if($unpaid_count > 0): ?>
                    <span class="ui-badge ui-badge-danger"><?= $unpaid_count ?></span>
                <?php endif; ?>
            </a>
            <a href="index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'lunas' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'lunas' ? ' aria-current="page"' : '' ?>>Lunas</a>
            <a href="index.php?page=collector&tab=pengeluaran&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'pengeluaran' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'pengeluaran' ? ' aria-current="page"' : '' ?>>Biaya</a>
            <a href="index.php?page=collector&tab=pelanggan&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'pelanggan' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'pelanggan' ? ' aria-current="page"' : '' ?>>Data</a>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Simplified Header for List Views -->
<div class="mb-4 flex flex-nowrap items-center justify-between gap-3">
    <div class="min-w-0">
        <h2 class="m-0 text-xl font-bold sm:text-2xl">
            <?php
                if($coll_tab === 'summary') echo 'Ringkasan';
                elseif($coll_tab === 'tugas') echo 'Tugas';
                elseif($coll_tab === 'lunas') echo 'Lunas';
                elseif($coll_tab === 'pengeluaran') echo 'Biaya';
                elseif($coll_tab === 'pelanggan') echo 'Data';
            ?>
        </h2>
    </div>

    <div class="flex shrink-0 flex-nowrap items-center gap-2">
        <button type="button" onclick="openFilterModal()" class="ui-btn ui-btn-sm ui-btn-outline">
            <i class="fas fa-filter"></i> <span class="hide-mobile hidden sm:inline">Filter</span>
        </button>
        <?php if($date_from || $filter_area): ?>
            <a href="index.php?page=collector&tab=<?= $coll_tab ?>&date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-t') ?>&filter_area=" class="ui-btn ui-btn-sm ui-btn-outline" title="Reset filter" aria-label="Reset filter">
                <i class="fas fa-sync-alt"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="mb-5 overflow-x-auto<?= (isset($_GET['msg']) && $_GET['msg'] === 'paid' && isset($_GET['last_id'])) ? ' hidden' : '' ?>">
    <div class="flex w-fit flex-nowrap gap-1 rounded-md bg-muted p-1">
        <a class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'summary' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'summary' ? ' aria-current="page"' : '' ?> href="index.php?page=collector&tab=summary&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Ringkasan</a>
        <a class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'tugas' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'tugas' ? ' aria-current="page"' : '' ?> href="index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Tugas</a>
        <a class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'lunas' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'lunas' ? ' aria-current="page"' : '' ?> href="index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Pelunasan</a>
        <a class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'pengeluaran' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'pengeluaran' ? ' aria-current="page"' : '' ?> href="index.php?page=collector&tab=pengeluaran&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Pengeluaran</a>
        <a class="whitespace-nowrap rounded-sm px-3 py-1.5 text-sm <?= $coll_tab === 'pelanggan' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $coll_tab === 'pelanggan' ? ' aria-current="page"' : '' ?> href="index.php?page=collector&tab=pelanggan&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">Pelanggan</a>
    </div>
</div>

<?php if($success_data): ?>
<div class="ui-card mb-4 p-4 sm:p-5">
    <div class="mb-4 flex flex-nowrap items-start justify-between gap-4">
        <div>
            <h3 class="m-0 text-[15px] font-bold text-signal">Pembayaran berhasil</h3>
            <p class="m-0 mt-1 text-xs text-muted-foreground">Tagihan untuk <strong><?= htmlspecialchars($success_data['name']) ?></strong> telah diperbarui.</p>
        </div>
        <button type="button" onclick="this.parentElement.parentElement.style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
    </div>
    <div class="flex flex-wrap gap-2">
        <button onclick="sendWAGateway('<?= $success_data['wa_num'] ?>', <?= htmlspecialchars(json_encode($success_data['wa_link_msg'])) ?>, 'https://api.whatsapp.com/send?phone=<?= $success_data['wa_num'] ?>&text=<?= urlencode($success_data['wa_link_msg']) ?>', this)" class="ui-btn ui-btn-wa flex-1 sm:flex-none">
            <i class="fab fa-whatsapp"></i> Kirim nota WA
        </button>
        <a href="index.php?page=invoice_print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" class="ui-btn ui-btn-outline flex-1 sm:flex-none">
            <i class="fas fa-print"></i> Cetak struk
        </a>
    </div>
</div>
<?php endif; ?>

<!-- TAB CONTENT -->
<?php if($coll_tab === 'summary'): ?>
<!-- Banners / PENGUMUMAN -->
<?php if(!empty($banners)): ?>
<div class="mb-5 grid gap-3">
    <?php foreach($banners as $b): ?>
        <div class="ui-card p-4">
            <?php if($b['image_path']): ?>
                <div class="mb-3">
                    <img src="<?= $b['image_path'] ?>" class="h-32 w-full cursor-pointer rounded-lg object-cover" onclick="openImagePreview(this.src)" title="Perbesar">
                </div>
            <?php endif; ?>
            <div class="text-sm font-bold"><?= htmlspecialchars($b['title']) ?></div>
            <div class="mt-1 text-xs leading-relaxed text-muted-foreground">
                <?= nl2br(htmlspecialchars($b['content'])) ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="mb-4 flex flex-wrap gap-2">
    <a href="index.php?page=collector&tab=pelanggan" class="ui-btn ui-btn-sm ui-btn-outline">Data pelanggan</a>
    <a href="index.php?page=collector&tab=tugas" class="ui-btn ui-btn-sm ui-btn-outline">Data tunggakan</a>
    <a href="index.php?page=collector&tab=lunas" class="ui-btn ui-btn-sm ui-btn-outline">Data pelunasan</a>
    <a href="index.php?page=collector&tab=pengeluaran" class="ui-btn ui-btn-sm ui-btn-outline">Data pengeluaran</a>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
    <a class="ui-card block p-4 text-inherit no-underline" href="index.php?page=collector&tab=pelanggan">
        <div class="text-xs font-medium text-muted-foreground">Total pelanggan</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= number_format($total_customers) ?></div>
        <div class="text-xs text-muted-foreground">Pelanggan area penagihan Anda</div>
        <div class="mt-2 text-xs font-medium text-primary">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="ui-card block p-4 text-inherit no-underline" href="index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
        <div class="text-xs font-medium text-muted-foreground">Pembayaran terkumpul</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal">Rp <?= number_format($paid_total_range, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground"><?= number_format($paid_count_range) ?> transaksi berhasil</div>
        <div class="mt-2 text-xs font-medium text-primary">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="ui-card block p-4 text-inherit no-underline" href="index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
        <div class="text-xs font-medium text-muted-foreground">Total tunggakan</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums text-danger">Rp <?= number_format($unpaid_total, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground"><?= number_format($unpaid_count) ?> tagihan belum lunas</div>
        <div class="mt-2 text-xs font-medium text-primary">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="ui-card block p-4 text-inherit no-underline" href="index.php?page=collector&tab=pengeluaran&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
        <div class="text-xs font-medium text-muted-foreground">Total pengeluaran</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums">Rp <?= number_format($expenses_range, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Biaya operasional periode ini</div>
        <div class="mt-2 text-xs font-medium text-primary">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>

    <a class="ui-card block p-4 text-inherit no-underline" href="index.php?page=collector&tab=summary&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
        <div class="text-xs font-medium text-muted-foreground">Progress penagihan</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= $percent_paid ?>%</div>
        <div class="text-xs tabular-nums text-muted-foreground">Rp <?= number_format($paid_total_range, 0, ',', '.') ?> dari Rp <?= number_format($total_potential, 0, ',', '.') ?></div>
        <div class="mt-2 text-xs font-medium text-primary">Buka sumber data <i class="fas fa-arrow-right"></i></div>
    </a>
</div>

<!-- TUGAS PENTING (RINGKAS) -->
<section class="ui-card mb-5 overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <h3 class="m-0 text-[15px] font-bold">Tugas mendesak</h3>
        <a href="index.php?page=collector&tab=tugas" class="ui-btn ui-btn-sm ui-btn-outline">Lihat semua</a>
    </div>

    <div>
        <?php
        $urgent_tasks = array_slice($unpaid_invoices, 0, 2);
        foreach($urgent_tasks as $ui):
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ui['contact']));
            $is_overdue = strtotime($ui['oldest_due_date']) < time();
            $initial = strtoupper(substr($ui['name'], 0, 1));
        ?>
            <div class="flex flex-nowrap items-center gap-3 border-b border-solid border-border px-4 py-3 last:border-b-0 sm:px-5">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-bold"><?= htmlspecialchars($ui['name']) ?></div>
                    <div class="mt-0.5 truncate text-xs text-muted-foreground"><span class="<?= $is_overdue ? 'font-semibold text-danger' : '' ?>"><?= $ui['num_arrears'] ?> bulan</span> &middot; <?= htmlspecialchars($ui['address']) ?></div>
                </div>
                <button class="ui-btn ui-btn-sm ui-btn-primary shrink-0" onclick="handlePay(<?= $ui['cust_id'] ?>, <?= $ui['num_arrears'] ?>, '<?= addslashes($ui['name']) ?>', <?= $ui['monthly_fee'] ?>)">
                    Bayar
                </button>
            </div>
        <?php endforeach; ?>
        <?php if(empty($urgent_tasks)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Semua tagihan berhasil ditagih.</div>
        <?php endif; ?>
    </div>
</section>

<div class="ui-card p-4 sm:p-5">
    <div class="flex flex-nowrap items-center justify-between gap-4">
        <div>
            <div class="text-xs font-medium text-muted-foreground">Target penagihan</div>
            <div class="mt-1 text-lg font-bold tabular-nums"><?= $percent_paid ?>% <span class="text-xs font-medium text-muted-foreground">(Rp <?= number_format($paid_total_range, 0, ',', '.') ?> / Rp <?= number_format($total_potential, 0, ',', '.') ?>)</span></div>
        </div>
        <div class="shrink-0 text-lg font-bold tabular-nums text-primary"><?= $percent_paid ?>%</div>
    </div>
    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-muted">
        <div class="h-full rounded-full bg-primary" style="width: <?= $percent_paid ?>%;"></div>
    </div>
</div>

<?php elseif($coll_tab === 'pengeluaran'): ?>
<!-- TAB: Pengeluaran Operasional -->
<section class="ui-card tab-flex-container overflow-hidden">
    <div class="flex flex-nowrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div class="min-w-0">
            <h3 class="m-0 text-[15px] font-bold">Daftar pengeluaran</h3>
            <p class="m-0 text-xs text-muted-foreground">Total: <strong class="tabular-nums text-foreground">Rp <?= number_format($expenses_range, 0, ',', '.') ?></strong></p>
        </div>
        <button onclick="document.getElementById('addExpenseModal').style.display='flex'" class="ui-btn ui-btn-sm ui-btn-primary shrink-0">
            <i class="fas fa-plus"></i> Baru
        </button>
    </div>

    <div class="list-container-responsive">
        <?php if(empty($recent_expenses)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada catatan pengeluaran bulan ini.</div>
        <?php else: ?>
            <div>
            <?php foreach($recent_expenses as $re): ?>
                <div class="flex flex-nowrap items-start justify-between gap-3 border-b border-solid border-border px-4 py-3 last:border-b-0 sm:px-5">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-bold"><?= htmlspecialchars($re['description'] ?: 'Operasional') ?></div>
                        <div class="mt-0.5 text-xs text-muted-foreground"><?= date('d/m/Y', strtotime($re['date'])) ?></div>
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="text-sm font-bold tabular-nums">Rp <?= number_format($re['amount'], 0, ',', '.') ?></div>
                        <div class="mt-0.5 text-[11px] text-muted-foreground">#EXP-<?= $re['id'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php elseif($coll_tab === 'pelanggan'): ?>
<!-- TAB: Data Pelanggan -->
<section class="ui-card overflow-hidden">
    <div class="flex flex-nowrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div class="min-w-0">
            <h3 class="m-0 text-[15px] font-bold">Data pelanggan</h3>
            <p class="m-0 text-xs text-muted-foreground"><?= number_format($total_customers) ?> total</p>
        </div>
        <div class="flex shrink-0 flex-nowrap gap-2">
            <button class="ui-btn ui-btn-sm ui-btn-primary" onclick="showAddCustomerModal()" title="Tambah pelanggan">
                <i class="fas fa-user-plus"></i> <span class="hidden sm:inline">Tambah</span>
            </button>
            <?php if($filter_billing_date): ?>
            <a href="index.php?page=collector&tab=pelanggan&filter_billing_date=" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus filter tanggal tagih" aria-label="Hapus filter tanggal tagih">
                <i class="fas fa-calendar-times"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Status Indicator -->
    <?php if($filter_status !== 'all'): ?>
        <div class="flex flex-nowrap items-center gap-2 border-b border-solid border-border bg-muted px-4 py-2 text-xs sm:px-5">
            <span class="text-muted-foreground">Menampilkan:</span>
            <strong>
                <?php
                    if ($filter_status === 'unpaid') echo 'Belum lunas';
                    elseif ($filter_status === 'paid') echo 'Sudah lunas';
                    elseif ($filter_status === 'new') echo 'Pendaftaran baru';
                    else echo 'Semua';
                ?>
            </strong>
            <a href="index.php?page=collector&tab=pelanggan&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&filter_status=all" class="ml-auto font-semibold text-danger no-underline">Reset</a>
        </div>
    <?php endif; ?>


    <!-- List Container -->
    <div class="list-container-responsive">
        <!-- Mobile-friendly card list -->
        <div class="customer-list-mobile md:hidden">
        <?php foreach($area_customers as $ac):
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
            $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
            $initial = strtoupper(substr($ac['name'], 0, 1));
            $is_unpaid = $ac['unpaid_count'] > 0;
        ?>
        <div class="cursor-pointer border-b border-solid border-border px-4 py-4 last:border-b-0" onclick="CollectorPage.showCustomerDetails(<?= $ac['id'] ?>)">
            <!-- Header: Profile & Status -->
            <div class="flex flex-nowrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <h4 class="m-0 truncate text-sm font-bold"><?= htmlspecialchars($ac['name']) ?></h4>
                    <div class="mt-0.5 text-xs text-muted-foreground"><?= $cust_id_display ?> &middot; <?= htmlspecialchars($ac['address'] ?: '-') ?></div>
                </div>
                <span class="ui-badge <?= $is_unpaid ? 'ui-badge-danger' : 'ui-badge-signal' ?> shrink-0"><?= $is_unpaid ? 'Ada tagihan' : 'Lunas' ?></span>
            </div>

            <!-- Context: Service Details -->
            <div class="mt-3 flex flex-nowrap items-center justify-between gap-3 text-xs text-muted-foreground">
                <div class="min-w-0 truncate"><span class="font-semibold tabular-nums text-foreground">Rp <?= number_format($ac['monthly_fee'], 0, ',', '.') ?></span> / <?= htmlspecialchars($ac['package_name']) ?></div>
                <div class="shrink-0">Siklus tgl <?= $ac['billing_date'] ?></div>
            </div>

            <!-- Footer: Quick Actions -->
            <div class="mt-3 flex flex-nowrap items-center gap-2" onclick="event.stopPropagation();">
                <?php if($is_unpaid): ?>
                    <button class="ui-btn ui-btn-sm ui-btn-primary flex-1" onclick="handlePay(<?= $ac['id'] ?>, <?= $ac['unpaid_count'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                        Bayar
                    </button>
                    <?php
                        $mon_label = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                        $curr_month = $mon_label[intval(date('m')) - 1] . ' ' . date('Y');
                        $portal_link_rem = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_display;
                        $rem_msg = str_replace(
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{link_tagihan}'],
                            [$ac['name'], '*' . $cust_id_display . '*', $ac['package_name'], $curr_month, '*Rp ' . number_format($ac['monthly_fee'], 0, ',', '.') . '*', '*' . $ac['billing_date'] . ' ' . $curr_month . '*', '*' . trim($settings['bank_account']) . '*', $portal_link_rem],
                            $wa_tpl
                        );
                        $rem_wa_link = "https://api.whatsapp.com/send?phone=$wa_num&text=" . urlencode($rem_msg);
                    ?>
                    <button class="ui-btn ui-btn-sm ui-btn-wa w-9 px-0" onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($rem_msg)) ?>, '<?= $rem_wa_link ?>', this)" title="Kirim pengingat WhatsApp" aria-label="Kirim pengingat WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </button>
                <?php else: ?>
                    <button class="ui-btn ui-btn-sm ui-btn-outline flex-1" onclick="CollectorPage.showCreateInvoice(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                        <i class="fas fa-plus"></i> Add-on / manual
                    </button>
                <?php endif; ?>

                <button class="ui-btn ui-btn-sm ui-btn-outline w-9 px-0" onclick="CollectorPage.showUpdateProfile(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', '<?= htmlspecialchars($ac['contact'] ?: '') ?>', '<?= addslashes($ac['address'] ?: '') ?>')" title="Ubah profil" aria-label="Ubah profil">
                    <i class="fas fa-user-edit"></i>
                </button>
                <a href="tel:<?= htmlspecialchars($ac['contact']) ?>" class="ui-btn ui-btn-sm ui-btn-outline w-9 px-0" title="Telepon" aria-label="Telepon">
                    <i class="fas fa-phone-alt"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($area_customers)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</div>
        <?php endif; ?>
    </div>

    <!-- Desktop table -->
    <div class="customer-list-desktop hidden overflow-x-auto md:block">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold">Pelanggan</th>
                    <th class="px-4 py-2.5 font-semibold">Layanan</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($area_customers as $ac):
                    $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
                    $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
                ?>
                <tr class="cursor-pointer border-t border-solid border-border" onclick="CollectorPage.showCustomerDetails(<?= $ac['id'] ?>)">
                    <td class="px-4 py-3 align-top">
                        <div class="text-sm font-bold"><?= htmlspecialchars($ac['name']) ?></div>
                        <div class="mt-0.5 text-xs text-muted-foreground"><?= $cust_id_display ?> &middot; <?= htmlspecialchars($ac['address'] ?: '-') ?></div>
                        <div class="mt-0.5 text-xs text-muted-foreground">Siklus tanggal <?= $ac['billing_date'] ?></div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="text-sm font-semibold"><?= htmlspecialchars($ac['package_name']) ?></div>
                        <div class="mt-0.5 text-sm font-bold tabular-nums">Rp <?= number_format($ac['monthly_fee'], 0, ',', '.') ?></div>
                        <a href="tel:<?= htmlspecialchars($ac['contact']) ?>" class="mt-0.5 block text-xs text-muted-foreground no-underline hover:text-foreground">
                            <i class="fas fa-phone-alt"></i> <?= htmlspecialchars($ac['contact'] ?: '-') ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="flex flex-nowrap justify-end gap-1" onclick="event.stopPropagation();">
                            <?php if($ac['unpaid_count'] > 0): ?>
                            <button class="ui-btn ui-btn-sm ui-btn-primary" onclick="handlePay(<?= $ac['id'] ?>, <?= $ac['unpaid_count'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                                Bayar (<?= $ac['unpaid_count'] ?>)
                            </button>
                            <?php endif; ?>
                            <button class="ui-btn ui-btn-sm ui-btn-outline" onclick="showCreateInvoice(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)" title="Buat tagihan manual" aria-label="Buat tagihan manual">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </button>
                            <button class="ui-btn ui-btn-sm ui-btn-outline" onclick="showUpdateProfile(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', '<?= htmlspecialchars($ac['contact'] ?: '') ?>', '<?= addslashes($ac['address'] ?: '') ?>')" title="Ubah profil" aria-label="Ubah profil">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="ui-btn ui-btn-sm ui-btn-outline" onclick="CollectorPage.showCustomerDetails(<?= $ac['id'] ?>); setTimeout(() => openAddonModal(), 500);" title="Tambah add-on" aria-label="Tambah add-on">
                                <i class="fas fa-plus-circle"></i>
                            </button>
                            <?php if($wa_num):
                                // Re-use the reminder logic for desktop list
                                $mon_label = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                $curr_month = $mon_label[intval(date('m')) - 1] . ' ' . date('Y');
                                $portal_link_rem = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_display;
                                $rem_msg = str_replace(
                                    ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{link_tagihan}'],
                                    [$ac['name'], '*' . $cust_id_display . '*', $ac['package_name'], $curr_month, '*Rp ' . number_format($ac['monthly_fee'], 0, ',', '.') . '*', '*' . $ac['billing_date'] . ' ' . $curr_month . '*', '*' . trim($settings['bank_account']) . '*', $portal_link_rem],
                                    $wa_tpl
                                );
                                $rem_wa_link = "https://api.whatsapp.com/send?phone=$wa_num&text=" . urlencode($rem_msg);
                            ?>
                                <button onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($rem_msg)) ?>, '<?= $rem_wa_link ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim pesan tagihan" aria-label="Kirim pesan tagihan">
                                    <i class="fab fa-whatsapp"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($area_customers)): ?>
                <tr class="border-t border-solid border-border"><td colspan="3" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div> <!-- end desktop table -->
    </div> <!-- end list container -->

    <!-- Estimasi Pendapatan (Static Bottom) -->
    <div class="static-summary-bar flex flex-nowrap items-center justify-between gap-4 border-t border-solid border-border bg-muted px-4 py-3 sm:px-5">
        <div>
            <div class="text-xs font-medium text-muted-foreground">Estimasi pendapatan</div>
            <div class="text-base font-bold tabular-nums">Rp <?= number_format($total_estimasi, 0, ',', '.') ?></div>
        </div>
        <div class="text-right">
            <div class="text-xs font-medium text-muted-foreground">Target</div>
            <div class="text-base font-bold tabular-nums"><?= number_format($total_cust_filter, 0, ',', '.') ?> <span class="text-xs font-medium text-muted-foreground">pelanggan</span></div>
        </div>
    </div>
</section> <!-- end Tab: Data Pelanggan -->
<?php elseif($coll_tab === 'tugas'): ?>
<!-- TAB: Tugas Penagihan -->
<section class="ui-card tab-flex-container overflow-hidden">
    <div class="flex flex-nowrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <h3 class="m-0 text-[15px] font-bold">Daftar tugas belum lunas</h3>
        <div class="shrink-0 text-xs font-medium text-muted-foreground">Total: <?= count($unpaid_invoices) ?> pelanggan</div>
    </div>

    <div class="list-container-responsive">
        <!-- Desktop Mode: Table -->
        <div class="customer-list-desktop hidden overflow-x-auto md:block">
            <table class="table w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold">Pelanggan</th>
                        <th class="px-4 py-2.5 font-semibold">Area / alamat</th>
                        <th class="px-4 py-2.5 text-center font-semibold">Tunggakan</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Total tagihan</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($unpaid_invoices as $ui):
                        $wa_num_t = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ui['contact']));
                        $cust_id_t = $ui['customer_code'] ?: str_pad($ui['cust_id'], 5, "0", STR_PAD_LEFT);
                    ?>
                    <tr class="cursor-pointer border-t border-solid border-border" onclick="CollectorPage.showCustomerDetails(<?= $ui['cust_id'] ?>)">
                        <td class="px-4 py-3">
                            <div class="text-sm font-bold"><?= htmlspecialchars($ui['name']) ?></div>
                            <div class="mt-0.5 text-xs text-muted-foreground"><?= $cust_id_t ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-sm"><?= htmlspecialchars($ui['cust_area'] ?: '-') ?></div>
                            <div class="mt-0.5 max-w-[220px] truncate text-xs text-muted-foreground"><?= htmlspecialchars($ui['address'] ?: '-') ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="ui-badge ui-badge-danger"><?= $ui['num_arrears'] ?> bulan</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="text-sm font-bold tabular-nums text-danger">Rp <?= number_format($ui['total_unpaid'], 0, ',', '.') ?></div>
                            <div class="mt-0.5 text-xs text-muted-foreground"><?= htmlspecialchars($ui['package_name']) ?></div>
                        </td>
                        <td class="px-4 py-3" onclick="event.stopPropagation();">
                            <div class="flex flex-nowrap justify-end gap-1">
                                <button class="ui-btn ui-btn-sm ui-btn-primary" onclick="handlePay(<?= $ui['cust_id'] ?>, <?= $ui['num_arrears'] ?>, '<?= addslashes($ui['name']) ?>', <?= ($ui['total_unpaid'] / $ui['num_arrears']) ?>)">
                                    Bayar
                                </button>
                                <?php if($wa_num_t):
                                    $mon_label = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                    $curr_month = $mon_label[intval(date('m')) - 1] . ' ' . date('Y');
                                    $portal_link_rem = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_t;

                                    // Calculate total due for reminder
                                    $rem_msg_t = str_replace(
                                        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{total_harus}', '{link_tagihan}'],
                                        [$ui['name'], '*' . $cust_id_t . '*', $ui['package_name'], $ui['num_arrears'] . ' Bulan', '*Rp ' . number_format($ui['total_unpaid'], 0, ',', '.') . '*', '*' . date('d/m/Y', strtotime($ui['oldest_due_date'])) . '*', '*' . trim($settings['bank_account']) . '*', '*Rp ' . number_format($ui['total_unpaid'], 0, ',', '.') . '*', $portal_link_rem],
                                        $wa_tpl
                                    );
                                    $rem_wa_link_t = "https://api.whatsapp.com/send?phone=$wa_num_t&text=" . urlencode($rem_msg_t);
                                ?>
                                <button onclick="sendWAGateway('<?= $wa_num_t ?>', <?= htmlspecialchars(json_encode($rem_msg_t)) ?>, '<?= $rem_wa_link_t ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Pengingat WhatsApp" aria-label="Pengingat WhatsApp">
                                    <i class="fab fa-whatsapp"></i>
                                </button>
                                <a href="tel:<?= htmlspecialchars($ui['contact']) ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Telepon" aria-label="Telepon">
                                    <i class="fas fa-phone"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($unpaid_invoices)): ?>
                    <tr class="border-t border-solid border-border"><td colspan="5" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Mode: Cards -->
        <div class="customer-list-mobile md:hidden">
        <?php foreach($unpaid_invoices as $ui):
            $wa_num_t = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ui['contact']));
            $cust_id_t = $ui['customer_code'] ?: str_pad($ui['cust_id'], 5, "0", STR_PAD_LEFT);
        ?>
        <div class="cursor-pointer border-b border-solid border-border px-4 py-4 last:border-b-0" onclick="CollectorPage.showCustomerDetails(<?= $ui['cust_id'] ?>)">
            <div class="flex flex-nowrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-bold"><?= htmlspecialchars($ui['name']) ?></div>
                    <div class="mt-0.5 text-xs text-muted-foreground"><?= $cust_id_t ?> &middot; <?= htmlspecialchars($ui['address'] ?: '-') ?></div>
                    <div class="mt-1.5"><span class="ui-badge ui-badge-danger">Tunggakan <?= $ui['num_arrears'] ?> bulan</span></div>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-sm font-bold tabular-nums text-danger">Rp <?= number_format($ui['total_unpaid'], 0, ',', '.') ?></div>
                    <div class="mt-0.5 text-xs text-muted-foreground"><?= htmlspecialchars($ui['package_name']) ?></div>
                </div>
            </div>
            <div class="mt-3 flex flex-nowrap items-center gap-2" onclick="event.stopPropagation();">
                <button class="ui-btn ui-btn-sm ui-btn-primary flex-1" onclick="handlePay(<?= $ui['cust_id'] ?>, <?= $ui['num_arrears'] ?>, '<?= addslashes($ui['name']) ?>', <?= ($ui['total_unpaid'] / $ui['num_arrears']) ?>)">
                    Bayar
                </button>
                <?php if($wa_num_t):
                    $mon_label = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $curr_month = $mon_label[intval(date('m')) - 1] . ' ' . date('Y');
                    $portal_link_rem = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_t;

                    $rem_msg_t = str_replace(
                        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{total_harus}', '{link_tagihan}'],
                        [$ui['name'], '*' . $cust_id_t . '*', $ui['package_name'], $ui['num_arrears'] . ' Bulan', '*Rp ' . number_format($ui['total_unpaid'], 0, ',', '.') . '*', '*' . date('d/m/Y', strtotime($ui['oldest_due_date'])) . '*', '*' . trim($settings['bank_account']) . '*', '*Rp ' . number_format($ui['total_unpaid'], 0, ',', '.') . '*', $portal_link_rem],
                        $wa_tpl
                    );
                    $rem_wa_link_t = "https://api.whatsapp.com/send?phone=$wa_num_t&text=" . urlencode($rem_msg_t);
                ?>
                <button onclick="sendWAGateway('<?= $wa_num_t ?>', <?= htmlspecialchars(json_encode($rem_msg_t)) ?>, '<?= $rem_wa_link_t ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa w-9 px-0" title="Pengingat WhatsApp" aria-label="Pengingat WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                </button>
                <a href="tel:<?= htmlspecialchars($ui['contact']) ?>" class="ui-btn ui-btn-sm ui-btn-outline w-9 px-0" title="Telepon" aria-label="Telepon">
                    <i class="fas fa-phone"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($unpaid_invoices)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</div>
        <?php endif; ?>
        </div> <!-- end .customer-list-mobile -->
    </div> <!-- end list container -->

    <!-- Summary Static for Tasks -->
    <div class="static-summary-bar flex flex-nowrap items-center justify-between gap-4 border-t border-solid border-border bg-muted px-4 py-3 sm:px-5">
        <div>
            <div class="text-xs font-medium text-muted-foreground">Total tunggakan</div>
            <div class="text-base font-bold tabular-nums text-danger">Rp <?= number_format($unpaid_total, 0, ',', '.') ?></div>
        </div>
        <div class="text-right">
            <div class="text-xs font-medium text-muted-foreground">Terhutang</div>
            <div class="text-base font-bold tabular-nums"><?= $unpaid_count ?> <span class="text-xs font-medium text-muted-foreground">tagihan</span></div>
        </div>
    </div>
</section> <!-- end Tab: Tugas -->
<?php elseif($coll_tab === 'lunas'): ?>
<!-- TAB: Sudah Lunas -->
<section class="ui-card tab-flex-container overflow-hidden">
    <div class="flex flex-nowrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <h3 class="m-0 text-[15px] font-bold">Riwayat pembayaran lunas</h3>
    </div>
    <div class="list-container-responsive p-4 sm:p-5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach($recent_paid as $rp): ?>
        <div class="rounded-lg border border-solid border-border p-4">
            <div class="flex flex-nowrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-sm font-bold"><?= htmlspecialchars($rp['name']) ?></div>
                    <div class="mt-0.5 text-xs text-muted-foreground">Bayar: <?= date('d/m/Y H:i', strtotime($rp['payment_date'])) ?></div>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-sm font-bold tabular-nums text-signal">Rp <?= number_format($rp['paid_amount'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="mt-3 flex flex-nowrap items-center justify-between gap-3 border-t border-solid border-border pt-3">
                <span class="ui-badge ui-badge-signal">Lunas</span>
                <div class="flex flex-nowrap gap-1">
                    <?php
                        $wa_num_rp = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $rp['contact'] ?? ''));
                        // Fix for redirecting directly to admin_invoices?action=print
                        $receipt_link = "index.php?page=invoice_print&id=" . $rp['id'] . "&format=thermal";
                    ?>
                    <?php if($wa_num_rp):
                        $bulan_rp = date('m/Y', strtotime($rp['due_date']));

                        $portal_link_rp = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . ($rp['customer_code'] ?: $rp['customer_id']);
                        $parsed_msg_rp = str_replace(
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{perusahaan}', '{tunggakan}', '{waktu_bayar}', '{admin}', '{link_tagihan}'],
                            [
                                $rp['name'],
                                '*' . ($rp['customer_code'] ?: $rp['customer_id']) . '*',
                                $rp['package_name'],
                                $bulan_rp,
                                '*Rp ' . number_format($rp['paid_amount'], 0, ',', '.') . '*',
                                $settings['company_name'],
                                '*Rp ' . number_format($rp['total_tunggakan'] ?? 0, 0, ',', '.') . '*',
                                '*' . date('d/m/Y H:i', strtotime($rp['payment_date'])) . '*',
                                '*' . ($rp['admin_name'] ?: 'System') . '*',
                                $portal_link_rp
                            ],
                            $wa_tpl_paid
                            );
                        $parsed_msg_rp = str_ireplace('LUNAS', '*LUNAS*', $parsed_msg_rp);
                        $parsed_msg_rp = str_replace('**', '*', $parsed_msg_rp); // Clean up
                        $wa_msg_rp = urlencode($parsed_msg_rp);
                    ?>
                        <button onclick="sendWAGateway('<?= $wa_num_rp ?>', <?= htmlspecialchars(json_encode($parsed_msg_rp)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_num_rp ?>&text=<?= $wa_msg_rp ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim WA" aria-label="Kirim WA">
                            <i class="fab fa-whatsapp"></i>
                        </button>
                    <?php endif; ?>
                    <a href="<?= $receipt_link ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak kwitansi" aria-label="Cetak kwitansi">
                        <i class="fas fa-print"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if(empty($recent_paid)): ?>
            <div class="col-span-full px-5 py-10 text-center text-sm text-muted-foreground">Belum ada pembayaran yang tercatat bulan ini.</div>
        <?php endif; ?>
        </div> <!-- end grid -->
    </div> <!-- end list container -->

    <!-- Summary Static for Lunas -->
    <div class="static-summary-bar flex flex-nowrap items-center justify-between gap-4 border-t border-solid border-border bg-muted px-4 py-3 sm:px-5">
        <div>
            <div class="text-xs font-medium text-muted-foreground">Pendapatan terkumpul</div>
            <div class="text-base font-bold tabular-nums text-signal">Rp <?= number_format($paid_total_range, 0, ',', '.') ?></div>
        </div>
        <div class="text-right">
            <div class="text-xs font-medium text-muted-foreground">Lunas</div>
            <div class="text-base font-bold tabular-nums"><?= count($recent_paid) ?> <span class="text-xs font-medium text-muted-foreground">pelanggan</span></div>
        </div>
    </div>
</section> <!-- end Tab: Lunas -->
<?php else: ?>
    <!-- Fallback / Error -->
    <div class="ui-card px-5 py-10 text-center">
        <p class="m-0 text-sm text-muted-foreground">Halaman tidak ditemukan.</p>
        <a href="index.php?page=collector" class="ui-btn ui-btn-primary mt-4">Kembali ke dashboard</a>
    </div>
<?php endif; ?>

<!-- Modal Buat Tagihan Manual -->
<div id="createInvoiceModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Buat tagihan manual</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('createInvoiceModal').style.display='none'" aria-label="Tutup">&times;</button>
        </div>
        <div class="mb-4 text-sm text-muted-foreground">Pelanggan: <strong id="modalCustName" class="text-foreground"></strong></div>
        <form action="index.php?page=collector&action=create_invoice" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="customer_id" id="modalCustId">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nominal tagihan (Rp)</span>
                    <input type="number" name="amount" id="modalAmount" class="form-control" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Jatuh tempo</span>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+5 days')) ?>" required>
                </label>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('createInvoiceModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Buat tagihan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Profil Pelanggan (Expanded) -->
<div id="updateContactModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Edit profil pelanggan</h3>
            <button type="button" onclick="document.getElementById('updateContactModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>
        <form action="index.php?page=collector&action=update_profile" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="customer_id" id="modalContactCustId">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama pelanggan</span>
                    <input type="text" name="name" id="modalContactCustNameInput" class="form-control" placeholder="Nama lengkap" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nomor telepon / WhatsApp</span>
                    <input type="text" name="contact" id="modalContactValue" class="form-control" placeholder="0812..." required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat lengkap</span>
                    <textarea name="address" id="modalContactAddrInput" class="form-control" rows="3" placeholder="Alamat lengkap..."></textarea>
                </label>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('updateContactModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Pelanggan -->
<div id="addCustomerModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card scroll-container max-h-[90vh] w-full max-w-xl overflow-y-auto p-5 sm:p-6">
        <!-- Modal Header -->
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Tambah pelanggan baru</h3>
            <button type="button" onclick="document.getElementById('addCustomerModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <form action="index.php?page=collector&action=add_customer" method="POST" id="addCustomerForm">
<?= csrf_field() ?>
            <!-- Group: Identity -->
            <div class="mb-5">
                <div class="mb-3 text-[13px] font-bold">Informasi identitas</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama lengkap pelanggan</span>
                        <input type="text" name="name" class="form-control" required placeholder="Sesuai KTP">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">WhatsApp / telepon</span>
                        <input type="text" name="contact" class="form-control" placeholder="0812..." required>
                    </label>
                    <div>
                        <input type="hidden" name="type" value="customer">
                    </div>
                </div>
            </div>

            <!-- Group: Service -->
            <div class="mb-5">
                <div class="mb-3 text-[13px] font-bold">Paket &amp; lokasi</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Paket internet</span>
                        <select name="package_name" class="form-control" onchange="syncAddPrice(this)" required>
                            <option value="">-- Pilih paket --</option>
                            <?php foreach($packages_all as $pkg): ?>
                                <option value="<?= htmlspecialchars($pkg['name']) ?>" data-fee="<?= $pkg['fee'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rp <?= number_format($pkg['fee'],0,',','.') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Biaya bulanan (Rp)</span>
                        <input type="number" name="monthly_fee" id="add_monthly_fee" class="form-control" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Area pemasangan</span>
                        <select name="area" class="form-control" required>
                            <?php if($collector_area_val): ?>
                                <option value="<?= htmlspecialchars($collector_area_val) ?>" selected><?= htmlspecialchars($collector_area_val) ?> (Wajib)</option>
                            <?php else: ?>
                                <option value="">-- Pilih area --</option>
                                <?php foreach($areas_all as $area): ?>
                                    <option value="<?= htmlspecialchars($area['name']) ?>"><?= htmlspecialchars($area['name']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal tagih</span>
                        <select name="billing_date" class="form-control">
                            <?php for($d=1;$d<=28;$d++): ?>
                                <option value="<?= $d ?>">Tanggal <?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal registrasi</span>
                        <input type="date" name="registration_date" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tunggakan / hutang awal (Rp)</span>
                        <input type="number" name="previous_debt" placeholder="Jika ada" class="form-control">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat lengkap</span>
                        <textarea name="address" class="form-control" rows="3" placeholder="Jl. Contoh Nomor 1, Desa/Dusun, RT/RW..."></textarea>
                    </label>
                </div>
            </div>

            <!-- Footer: Actions -->
            <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-4">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('addCustomerModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Daftarkan pelanggan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Pelunasan Tunggakan (Bulk Pay) -->
<div id="bulkPayModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-1 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Pelunasan tunggakan</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('bulkPayModal').style.display='none'" aria-label="Tutup">&times;</button>
        </div>
        <p class="m-0 mb-4 text-sm text-muted-foreground">Bayar sebagian atau seluruh tunggakan untuk <strong class="text-foreground"><span id="bulkCustNameTitle"></span></strong>.</p>

        <div class="mb-4">
            <label for="bulkMonthInput" class="mb-1 block text-xs font-medium text-muted-foreground">Berapa bulan?</label>
            <div class="flex flex-nowrap items-center gap-3">
                <div class="flex-1">
                    <input type="number" id="bulkMonthInput" class="form-control w-full text-center text-lg font-bold tabular-nums" value="1" min="1" oninput="updateBulkTotalDisplay()" onchange="updateBulkTotalDisplay()">
                </div>
                <div class="shrink-0 text-sm text-muted-foreground">Dari <span id="bulkTotalMonthsTitle"></span> bulan</div>
            </div>
        </div>

        <div class="mb-6 rounded-lg border border-solid border-border bg-muted p-4">
            <div class="text-xs font-medium text-muted-foreground">Total bayar</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal" id="bulkTotalAmtDisplay">Rp 0</div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('bulkPayModal').style.display='none'">Batal</button>
            <button type="button" class="ui-btn ui-btn-primary" onclick="confirmBulkPay()">Proses pembayaran</button>
        </div>
    </div>
</div>

<script>
if (!window.CollectorPage) window.CollectorPage = {};
(function(ns){
    ns.showAddCustomerModal = function(){ const m = document.getElementById('addCustomerModal'); if(m) m.style.display = 'flex'; };
    ns.syncAddPrice = function(select){ const fee = select.options[select.selectedIndex].getAttribute('data-fee'); if(fee){ const el = document.getElementById('add_monthly_fee'); if(el) el.value = fee; } };
    ns.showCreateInvoice = function(id, name, fee){ const elId = document.getElementById('modalCustId'); if(elId) elId.value = id; const elName = document.getElementById('modalCustName'); if(elName) elName.textContent = name; const amt = document.getElementById('modalAmount'); if(amt) amt.value = fee; const modal = document.getElementById('createInvoiceModal'); if(modal) modal.style.display = 'flex'; };
    ns.showUpdateProfile = function(id, name, contact, address){ const a = document.getElementById('modalContactCustId'); if(a) a.value = id; const n = document.getElementById('modalContactCustNameInput'); if(n) n.value = name; const v = document.getElementById('modalContactValue'); if(v) v.value = contact; const addr = document.getElementById('modalContactAddrInput'); if(addr) addr.value = address || ''; const modal = document.getElementById('updateContactModal'); if(modal) modal.style.display = 'flex'; };
    ns.currentPayData = { custId: 0, monthlyFee: 0, maxMonths: 0 };
    ns.handlePay = function(custId, maxMonths, custName, monthlyFee){ ns.currentPayData = { custId, monthlyFee, maxMonths };
        if (maxMonths > 1) {
            const n = document.getElementById('bulkCustNameTitle'); if(n) n.innerText = custName;
            const t = document.getElementById('bulkTotalMonthsTitle'); if(t) t.innerText = maxMonths;
            const input = document.getElementById('bulkMonthInput');
        input.max = maxMonths;
        input.value = maxMonths;
        updateBulkTotalDisplay();
        document.getElementById('bulkPayModal').style.display = 'flex';
    } else {
        if (!confirm("Konfirmasi terima pembayaran tagihan " + custName + "?")) return;
        submitPay(custId, 1);
    }
}

function updateBulkTotalDisplay() {
    const input = document.getElementById('bulkMonthInput');
    let val = parseInt(input.value) || 1;
    if(val > currentPayData.maxMonths) { val = currentPayData.maxMonths; input.value = val; }
    if(val < 1) { val = 1; input.value = val; }
    
    const total = val * currentPayData.monthlyFee;
    const formatted = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(total).replace('IDR', 'Rp');
    document.getElementById('bulkTotalAmtDisplay').innerText = formatted;
}

function confirmBulkPay() {
    const months = document.getElementById('bulkMonthInput').value;
    submitPay(currentPayData.custId, months);
}

function submitPay(custId, months) {
    const form = document.getElementById('payFormGlobal');
    document.getElementById('globalCustId').value = custId;
    document.getElementById('globalNumMonths').value = months;
    form.submit();
}

let lastViewedCustId = 0;
if (!window.CollectorPage) window.CollectorPage = {};
(function(ns){
    ns.showCustomerDetails = async function(id){
        lastViewedCustId = id;
        // 1. Show loading state in modal
        const nameEl = document.getElementById('detCustName'); if(nameEl) nameEl.textContent = 'Memuat...';
        const histEl = document.getElementById('detHistoryList'); if(histEl) histEl.innerHTML = '<div style="text-align:center; padding:30px; opacity:0.5;\"><i class="fas fa-spinner fa-spin"></i> Sedang mengambil data...</div>';
        const modal = document.getElementById('customerDetailModal'); if(modal) modal.style.display = 'flex';
        try {
            const response = await fetch(`app/customer_history.php?id=${id}`);
            const data = await response.json();
            if(data.error) { throw new Error(data.error); }
            // 2. Populate Header & Info
            const elName = document.getElementById('detCustName'); if(elName) elName.textContent = data.customer.name;
            const elId = document.getElementById('detCustId'); if(elId) elId.textContent = 'ID: ' + (data.customer.customer_code || data.customer.id.toString().padStart(5, '0'));
            const elPkg = document.getElementById('detCustPkg'); if(elPkg) elPkg.textContent = data.customer.package_name;
            const elBill = document.getElementById('detCustBilling'); if(elBill) elBill.textContent = 'Tanggal ' + data.customer.billing_date;
            const elAddr = document.getElementById('detCustAddr'); if(elAddr) elAddr.textContent = data.customer.address || '-';
            const elPhone = document.getElementById('detCustPhone'); if(elPhone) elPhone.textContent = data.customer.contact || '-';
            const regDate = data.customer.registration_date ? new Date(data.customer.registration_date).toLocaleDateString('id-ID', {day:'2-digit', month:'long', year:'numeric'}) : '-';
            const elReg = document.getElementById('detCustRegDate'); if(elReg) elReg.textContent = regDate;
            // 3. Arrears logic
            const hasArrears = (data.history || []).some(h => h.status === 'Belum Lunas');
            const arrearsEl = document.getElementById('detCustArrearsSection'); if(arrearsEl) arrearsEl.style.display = hasArrears ? 'block' : 'none';
            // 4. Populate History
            let historyHtml = '';
            if(data.history && data.history.length > 0){
                data.history.forEach(item => {
                    const isPaid = item.status === 'Lunas';
                    const statusColor = isPaid ? '#10b981' : '#ef4444';
                    const payDate = item.payment_date ? new Date(item.payment_date).toLocaleDateString('id-ID', {day:'2-digit', month:'short', year:'numeric'}) : '-';
                    const amount = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(item.invoice_amount).replace('IDR', 'Rp');
                    historyHtml += `\n                    <div class="glass-panel" style="padding:12px; padding-left:15px; border-left:4px solid ${statusColor}; background:rgba(255,255,255,0.02); margin-bottom:8px;">\n                        <div style="display:flex; justify-content:space-between; align-items:center;">\n                            <div>\n                                <div style="font-size:13px; font-weight:800; color:${statusColor}; text-transform:uppercase;">${item.status}</div>\n                                <div style="font-size:11px; font-weight:700; margin-top:2px;">${item.description ? item.description : 'Tagihan Bulanan'}</div>\n                                <div style="font-size:10px; color:var(--text-secondary); margin-top:2px;">Jatuh Tempo: ${item.due_date}</div>\n                                ${isPaid ? `<div style="font-size:10px; color:var(--text-secondary); margin-top:2px;\"><i class="fas fa-calendar-check"></i> Dibayar: ${payDate}</div>` : ''}\n                            </div>\n                            <div style="text-align:right;">\n                                <div style="font-weight:800; font-size:15px;">${amount}</div>\n                                <div style="font-size:9px; color:var(--text-secondary);">${item.collector_name ? 'Oleh: '+item.collector_name : ''}</div>\n                            </div>\n                        </div>\n                    </div>`;
                });
            } else {
                historyHtml = '<div style="text-align:center; padding:30px; opacity:0.5;">Belum ada riwayat tagihan.</div>';
            }
            const histContainer = document.getElementById('detHistoryList'); if(histContainer) histContainer.innerHTML = historyHtml;
        } catch (error) {
            const elName = document.getElementById('detCustName'); if(elName) elName.textContent = 'Error';
            const histContainer = document.getElementById('detHistoryList'); if(histContainer) histContainer.innerHTML = `<div style="text-align:center; padding:30px; color:var(--danger);">${error.message}</div>`;
        }
    };
})(window.CollectorPage);

function openAddonModal() {
    // Get currently viewed customer data from detail state
    const name = document.getElementById('detCustName').textContent;
    document.getElementById('addonCustName').innerText = name;
    
    // We need the ID. The detail modal doesn't store ID in the DOM usually, 
    // but we can extract it from the ID display or use a global variable.
    // Looking at showCustomerDetails, it takes 'id' as param.
    // I will add a hidden input for ID in the detail modal or use the one we have.
    document.getElementById('addonCustId').value = lastViewedCustId; 
    
    document.getElementById('addAddonModal').style.display = 'flex';
}

function openFilterModal() {
    document.getElementById('filterModal').style.display = 'flex';
}

function setQuickFilter(type) {
    const from = document.getElementById('filter_date_from');
    const to = document.getElementById('filter_date_to');
    const now = new Date();
    
    switch(type) {
        case 'today':
            const todayStr = now.toISOString().split('T')[0];
            from.value = todayStr;
            to.value = todayStr;
            break;
        case 'month':
            const firstDay = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
            const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
            from.value = firstDay;
            to.value = lastDay;
            break;
        case 'week':
            const lastWeek = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
            from.value = lastWeek;
            to.value = now.toISOString().split('T')[0];
            break;
    }
}
</script>

<!-- Modal Detail Pelanggan & Riwayat Pembayaran -->
<div id="customerDetailModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden">
        <!-- Modal Header -->
        <div class="flex flex-nowrap items-start justify-between gap-4 border-b border-solid border-border px-5 py-4">
            <div class="min-w-0">
                <div id="detCustName" class="truncate text-lg font-bold leading-tight">...</div>
                <div id="detCustId" class="mt-0.5 text-xs text-muted-foreground">ID: ...</div>
            </div>
            <button type="button" onclick="document.getElementById('customerDetailModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost shrink-0" aria-label="Tutup">&times;</button>
        </div>

        <!-- Modal Content -->
        <div class="scroll-container min-h-0 flex-1 overflow-y-auto p-5">
            <!-- Customer Info Summary -->
            <div class="mb-5 grid grid-cols-2 gap-3">
                <div class="rounded-lg border border-solid border-border p-3">
                    <div class="text-xs font-medium text-muted-foreground">Paket</div>
                    <div id="detCustPkg" class="mt-0.5 text-sm font-semibold">...</div>
                </div>
                <div class="rounded-lg border border-solid border-border p-3">
                    <div class="text-xs font-medium text-muted-foreground">Siklus tagih</div>
                    <div id="detCustBilling" class="mt-0.5 text-sm font-semibold">Tanggal ...</div>
                </div>
                <div class="rounded-lg border border-solid border-border p-3">
                    <div class="text-xs font-medium text-muted-foreground">Nomor telepon / WhatsApp</div>
                    <div id="detCustPhone" class="mt-0.5 text-sm font-semibold">...</div>
                </div>
                <div class="rounded-lg border border-solid border-border p-3">
                    <div class="text-xs font-medium text-muted-foreground">Tanggal registrasi</div>
                    <div id="detCustRegDate" class="mt-0.5 text-sm font-semibold">...</div>
                </div>
                <div class="col-span-2 rounded-lg border border-solid border-border p-3">
                    <div class="text-xs font-medium text-muted-foreground">Alamat lengkap</div>
                    <div id="detCustAddr" class="mt-0.5 text-sm leading-relaxed">...</div>
                </div>
            </div>

            <!-- Arrears Alert (if any) -->
            <div id="detCustArrearsSection" class="mb-5 rounded-lg border border-solid border-danger/40 p-3 text-sm" style="display:none;">
                <span class="font-semibold text-danger">Perhatian.</span> Terdapat tagihan belum lunas.
            </div>

            <!-- Payment History -->
            <div class="mb-3 text-[15px] font-bold">Riwayat pembayaran (12 bln)</div>
            <div id="detHistoryList" class="flex flex-col">
                <!-- Dynamically populated -->
                <div class="py-8 text-center text-sm text-muted-foreground">Memuat data...</div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="flex flex-nowrap gap-2 border-t border-solid border-border px-5 py-4">
             <button type="button" onclick="openAddonModal()" class="ui-btn ui-btn-outline flex-1">
                 <i class="fas fa-plus-circle"></i> Add-on
             </button>
             <button type="button" onclick="document.getElementById('customerDetailModal').style.display='none'" class="ui-btn ui-btn-primary flex-[2]">Tutup</button>
        </div>
    </div>
</div>
<!-- Global Hidden Form for Bulk Payment Submission -->
<form id="payFormGlobal" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="customer_id" id="globalCustId">
    <input type="hidden" name="num_months" id="globalNumMonths" value="1">
</form>

<!-- Modal Tambah Pengeluaran -->
<div id="addExpenseModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <!-- Header -->
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Tambah pengeluaran</h3>
            <button type="button" onclick="document.getElementById('addExpenseModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <form action="index.php?page=collector&action=add_expense" method="POST">
<?= csrf_field() ?>
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Kategori / nama pengeluaran</span>
                    <input type="text" name="category" class="form-control" placeholder="Contoh: Bensin, Makan, dsb" required>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nominal (Rp)</span>
                    <input type="number" name="amount" class="form-control font-bold tabular-nums" placeholder="0" required>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal</span>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="form-control" required>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan / catatan</span>
                    <textarea name="description" class="form-control" placeholder="Contoh: Bensin motor keliling desa" rows="2"></textarea>
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto">Simpan pengeluaran</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Add-on (Tagihan Manual) -->
<div id="addAddonModal" class="fixed inset-0 z-[10000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <!-- Header -->
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Tambah add-on</h3>
            <button type="button" onclick="document.getElementById('addAddonModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <form action="index.php?page=collector&action=add_addon" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="customer_id" id="addonCustId">
            <p class="m-0 mb-4 rounded-lg border border-solid border-border bg-muted p-3 text-xs text-muted-foreground">
                Menambahkan tagihan baru untuk: <strong id="addonCustName" class="text-foreground">...</strong>
            </p>

            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama item / layanan</span>
                    <input type="text" name="description" class="form-control" placeholder="Contoh: Router TP-Link, Setting, dsb" required>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nominal (Rp)</span>
                    <input type="number" name="amount" class="form-control font-bold tabular-nums" placeholder="0" required>
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto">Tambahkan tagihan</button>
            </div>
        </form>
    </div>
</div>


<!-- Filter Modal (Unified) -->
<div id="filterModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h3 class="m-0 text-lg font-bold">Pengaturan filter</h3>
                <p class="m-0 mt-1 text-xs text-muted-foreground">Sesuaikan data laporan</p>
            </div>
            <button type="button" onclick="document.getElementById('filterModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <form method="GET">
            <input type="hidden" name="page" value="collector">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($coll_tab) ?>">

            <div class="mb-5">
                <div class="mb-2 text-xs font-medium text-muted-foreground">Rentang waktu</div>
                <div class="filter-quick-actions mb-3 flex flex-nowrap gap-2">
                    <button type="button" onclick="setQuickFilter('today')" class="ui-btn ui-btn-sm ui-btn-outline filter-quick-btn">Hari ini</button>
                    <button type="button" onclick="setQuickFilter('week')" class="ui-btn ui-btn-sm ui-btn-outline filter-quick-btn">7 hari</button>
                    <button type="button" onclick="setQuickFilter('month')" class="ui-btn ui-btn-sm ui-btn-outline filter-quick-btn">Bulan ini</button>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Mulai tanggal</span>
                        <input type="date" name="date_from" id="filter_date_from" class="form-control filter-control" value="<?= $date_from ?>">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Hingga tanggal</span>
                        <input type="date" name="date_to" id="filter_date_to" class="form-control filter-control" value="<?= $date_to ?>">
                    </label>
                </div>
            </div>

            <div class="mb-5">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Area wilayah</span>
                    <select name="filter_area" class="form-control filter-control">
                        <?php if($collector_area_val): ?>
                            <option value="<?= htmlspecialchars($collector_area_val) ?>" selected>Wilayah saya: <?= htmlspecialchars($collector_area_val) ?></option>
                        <?php else: ?>
                            <option value="">Semua wilayah</option>
                            <?php foreach($areas as $ar): ?>
                                <option value="<?= htmlspecialchars($ar['name']) ?>" <?= $filter_area == $ar['name'] ? 'selected' : '' ?>><?= htmlspecialchars($ar['name']) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </label>
            </div>

            <div class="form-actions-row mt-6 flex justify-end gap-2 border-t border-solid border-border pt-4">
                <button type="button" class="ui-btn ui-btn-outline" onclick="location.href='index.php?page=collector&tab=<?= $coll_tab ?>'">Reset</button>
                <button type="submit" class="ui-btn ui-btn-primary">
                    Terapkan filter
                </button>
            </div>
        </form>
    </div>
</div>
