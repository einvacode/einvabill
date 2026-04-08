<?php
// Data collector
$user_id = $_SESSION['user_id'];
// Base filter: assigned collector id
$collector_id = $_SESSION['user_id'];

// Get collector area assignment
$collector_area = $db->query("SELECT area FROM users WHERE id = " . intval($collector_id))->fetchColumn() ?: '';

$collector_area_val = trim($collector_area);
if (!empty($collector_area_val)) {
    $area_filter = " AND (c.collector_id = " . intval($collector_id) . " OR (c.area = " . $db->quote($collector_area_val) . " AND (c.collector_id = 0 OR c.collector_id IS NULL))) ";
} else {
    $area_filter = " AND c.collector_id = " . intval($collector_id);
}

// Billing date filter
$filter_billing_date = $_GET['filter_billing_date'] ?? '';
$billing_where = "";
if ($filter_billing_date !== "") {
    $billing_where = " AND c.billing_date = " . intval($filter_billing_date);
}

// NEW: Month selector logic for comprehensive dashboard filtering
$selected_month = $_GET['month'] ?? date('Y-m');
$date_from = date('Y-m-01', strtotime($selected_month . "-01"));
$date_to = date('Y-m-t', strtotime($selected_month . "-01"));

// Override if manual dates are provided (for backwards compatibility/finer control)
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
")->fetchColumn();

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
        c.id as cust_id, c.name, c.address, c.contact, c.area as cust_area, c.customer_code, c.package_name, c.type as customer_type,
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
$settings = $db->query("SELECT company_name, wa_template, wa_template_paid, site_url, bank_account FROM settings WHERE id=1")->fetch();
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();
$wa_tpl = $settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}";
$wa_tpl_paid = $settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah lunas.";

// Fetch Banners for Collector
$banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'collector') ORDER BY created_at DESC")->fetchAll();

// Success Modal Data for Collector
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
                $settings['bank_account'],
                $total_display,
                $status_wa,
                $tunggakan_display,
                $total_display
            ], 
            $wa_tpl_paid
        );
        $success_data['wa_link_msg'] = $receipt_msg;
        $success_data['wa_num'] = $wa_num_paid;
    }
}

// Fetch Packages & Areas for Adding Customers
$packages_all = $db->query("SELECT * FROM packages ORDER BY name ASC")->fetchAll();
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
                
                // If reg was Jan, first bill is Feb. If today is Apr, create Feb, Mar, Apr.
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
                FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust $status_filter_sql ORDER BY c.id DESC LIMIT $items_per_page OFFSET $off_cust";
$area_customers = $db->query($cust_query)->fetchAll();

// Calculate Estimated Revenue (potential) for filtered customers
$total_estimasi = $db->query("SELECT COALESCE(SUM(c.monthly_fee), 0) FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust $status_filter_sql")->fetchColumn();
$total_cust_filter = $db->query("SELECT COUNT(*) FROM customers c WHERE 1=1 $area_filter $billing_where $where_search_cust $status_filter_sql")->fetchColumn();

$total_revenue_all = $db->query("SELECT COALESCE(SUM(c.monthly_fee), 0) FROM customers c WHERE 1=1 $area_filter")->fetchColumn();

$coll_tab = $_GET['tab'] ?? 'tugas';
?>

<style>
/* Custom Scrollbar for better UI */
.scroll-container::-webkit-scrollbar { width: 5px; }
.scroll-container::-webkit-scrollbar-track { background: transparent; }
.scroll-container::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb), 0.2); border-radius: 10px; }
.scroll-container { scrollbar-width: thin; scrollbar-color: rgba(var(--primary-rgb), 0.2) transparent; }

/* Responsive Visibility for Customer List */
@media (min-width: 769px) {
    .customer-list-desktop { display: block !important; }
    .customer-list-mobile { display: none !important; }
    .hide-mobile { display: inline !important; }
    .show-mobile { display: none !important; }
}
@media (max-width: 768px) {
    .customer-list-desktop { display: none !important; }
    .customer-list-mobile { display: block !important; }
    .hide-mobile { display: none !important; }
    .show-mobile { display: inline !important; }
}

/* Static Summary Bar - Flexbox approach */
.static-summary-bar {
    margin-top: 10px;
    flex-shrink: 0;
    width: 100%;
    animation: fadeInStatic 0.4s ease-out;
}
.static-summary-bar > div {
    box-shadow: 0 10px 25px rgba(0,0,0,0.1), 0 0 0 1px rgba(255,255,255,0.05);
}
@keyframes fadeInStatic {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Flexbox Tab Container */
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

.list-container-responsive {
    padding-bottom: 5px;
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
.stat-card-interactive:active {
    transform: translateY(2px) scale(0.98);
}
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

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'addon_added'): ?>
<div style="padding:12px 20px; background:rgba(37,99,235,0.15); border:1px solid rgba(37,99,235,0.4); border-radius:10px; margin-bottom:15px; color:var(--primary);">
    <i class="fas fa-plus-circle"></i> Tagihan Add-on berhasil ditambahkan!
</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'customer_added'): ?>
<div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:15px; color:var(--success);">
    <i class="fas fa-check-circle"></i> Pelanggan baru berhasil ditambahkan dan ditugaskan kepada Anda!
</div>
<?php endif; ?>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'expense_added'): ?>
<div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:15px; color:var(--success);">
    <i class="fas fa-check-circle"></i> Pengeluaran berhasil dicatat!
</div>
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
        
        $receipt_msg = str_replace(
            [
                '{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', 
                '{tunggakan}', '{waktu_bayar}', '{admin}', '{perusahaan}', '{link_tagihan}', '{status_pembayaran}', '{sisa_tunggakan}', '{total_bayar}'
            ], 
            [
                $inv_data['name'], 
                ($inv_data['customer_code'] ?: $inv_data['customer_id']), 
                'Rp ' . number_format($inv_data['monthly_fee'] ?: ($inv_data['amount']), 0, ',', '.'),
                ($inv_data['package_name'] ?: '-'),
                '1 Bulan',
                $tunggakan_display,
                date('d/m/Y H:i', strtotime($inv_data['payment_date'] ?: 'now')) . ' WIB',
                $_SESSION['user_name'],
                $settings['company_name'],
                $portal_link,
                $status_wa,
                $tunggakan_display,
                'Rp ' . number_format($inv_data['amount'], 0, ',', '.')
            ], 
            $wa_tpl_paid
        );
    $wa_msg = urlencode($receipt_msg);
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
            <button onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= $wa_msg ?>', this)" class="btn" style="background:#25D366; color:white; flex:1; min-width:140px; padding:12px; border:none; cursor:pointer; font-weight:700; border-radius:10px;">
                <i class="fab fa-whatsapp"></i> Kirim Kwitansi
            </button>
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

<?php if($coll_tab !== 'pelanggan'): ?>
<!-- Dashboard Header with Month Picker -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; padding:0 5px;">
    <div>
        <h2 style="margin:0; font-size:20px; font-weight:800; color:var(--text-primary);">Dashboard Penagihan</h2>
        <p style="margin:0; font-size:12px; color:var(--text-secondary);"><?= $_SESSION['user_name'] ?> | Area: <?= $collector_area ?: 'Semua' ?></p>
    </div>
    
    <div style="display:flex; align-items:center; gap:10px;">
        <!-- Month Picker -->
        <form method="GET" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="page" value="collector">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($coll_tab) ?>">
            <div style="background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); border-radius:12px; padding:6px 12px; display:flex; align-items:center;">
                <i class="fas fa-calendar-alt" style="color:var(--primary); margin-right:10px; font-size:14px;"></i>
                <input type="month" name="month" value="<?= $selected_month ?>" onchange="this.form.submit()" style="background:none; border:none; color:var(--text-primary); font-size:14px; font-weight:700; outline:none; cursor:pointer;">
            </div>
        </form>
        <!-- Quick Add-on Shortcut -->
        <button onclick="location.href='index.php?page=collector&tab=pelanggan'" class="btn" style="background:#d97706; color:white; padding:10px 15px; border-radius:12px; font-weight:700; font-size:13px; display:flex; align-items:center; gap:8px; box-shadow: 0 4px 15px rgba(217, 119, 6, 0.2);">
            <i class="fas fa-plus-circle"></i> <span class="hide-mobile">Add-on</span>
        </button>
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
        <button onclick="sendWAGateway('<?= $success_data['wa_num'] ?>', <?= htmlspecialchars(json_encode($success_data['wa_link_msg'])) ?>, 'https://api.whatsapp.com/send?phone=<?= $success_data['wa_num'] ?>&text=<?= urlencode($success_data['wa_link_msg']) ?>', this)" class="btn" style="background:#25D366; color:white; flex:1; min-width:150px; padding:12px; font-weight:700; text-align:center; border-radius:10px; border:none; cursor:pointer;">
            <i class="fab fa-whatsapp"></i> Kirim Nota WA
        </button>
        <a href="index.php?page=admin_invoices&action=print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" class="btn btn-ghost" style="flex:1; min-width:150px; padding:12px; font-weight:700; border-radius:10px; text-align:center; text-decoration:none; border:1px solid var(--glass-border);">
            <i class="fas fa-print"></i> Cetak Struk
        </a>
    </div>
</div>
<style> @keyframes slideDown { from { transform: translateY(-10px); opacity:0; } to { transform: translateY(0); opacity:1; } } </style>
<?php endif; ?>

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
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap:12px; margin-bottom:16px;">
    <!-- Total Pelanggan -->
    <div class="glass-panel stat-card-interactive" style="border-top:4px solid var(--primary); background:linear-gradient(135deg, rgba(37, 99, 235, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=pelanggan&filter_status=all'">
        <i class="fas fa-users" style="font-size:22px; color:var(--primary); margin-bottom:10px;"></i>
        <div style="font-size:24px; font-weight:800; color:var(--stat-value-color);"><?= $total_customers ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Pelanggan</div>
        <div style="font-size:13px; font-weight:800; color:var(--primary); margin-top:8px;">Rp<?= number_format($total_revenue_all, 0, ',', '.') ?></div>
    </div>

    <!-- Belum Bayar -->
    <div class="glass-panel stat-card-interactive" style="border-top:4px solid var(--danger); background:linear-gradient(135deg, rgba(239, 68, 68, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=tugas'">
        <i class="fas fa-exclamation-circle" style="font-size:22px; color:var(--danger); margin-bottom:10px;"></i>
        <div style="font-size:24px; font-weight:800; color:var(--stat-value-color);"><?= $unpaid_count ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Belum Lunas</div>
        <div style="font-size:13px; font-weight:800; color:var(--danger); margin-top:8px;">Rp<?= number_format($unpaid_total, 0, ',', '.') ?></div>
    </div>

    <!-- Berhasil (Lunas Periode Ini) -->
    <div class="glass-panel stat-card-interactive" style="border-top:4px solid var(--success); background:linear-gradient(135deg, rgba(16, 185, 129, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>'">
        <i class="fas fa-check-circle" style="font-size:22px; color:var(--success); margin-bottom:10px;"></i>
        <div style="font-size:24px; font-weight:800; color:var(--stat-value-color);"><?= $paid_count_range ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Berhasil</div>
        <div style="font-size:13px; font-weight:800; color:var(--success); margin-top:8px;">Rp<?= number_format($paid_total_range, 0, ',', '.') ?></div>
    </div>

    <!-- Total Revenue (Net) -->
    <div class="glass-panel stat-card-interactive" style="border-top:4px solid var(--warning); background:linear-gradient(135deg, rgba(245, 158, 11, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&month=<?= $selected_month ?>'">
        <i class="fas fa-coins" style="font-size:22px; color:var(--warning); margin-bottom:10px;"></i>
        <div style="font-size:20px; font-weight:800; color:var(--stat-value-color);">Rp<?= number_format($net_revenue, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Pendapatan Bersih</div>
        <div style="font-size:10px; color:var(--text-secondary); margin-top:8px; font-weight:700; opacity:0.8;"><?= date('m/Y', strtotime($date_from)) ?></div>
    </div>

    <!-- Pelanggan Baru -->
    <div class="glass-panel stat-card-interactive" style="border-top:4px solid #06b6d4; background:linear-gradient(135deg, rgba(6, 182, 212, 0.1), transparent); cursor:pointer;" onclick="location.href='index.php?page=collector&tab=pelanggan&filter_status=new'">
        <i class="fas fa-user-plus" style="font-size:22px; color:#06b6d4; margin-bottom:10px;"></i>
        <div style="font-size:24px; font-weight:800; color:var(--stat-value-color);"><?= $new_customers_range ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Pendaftaran</div>
        <div style="font-size:10px; color:var(--text-secondary); margin-top:8px; font-weight:700; opacity:0; pointer-events:none;">Filler</div>
    </div>

    <!-- Pengeluaran -->
    <div class="glass-panel stat-card-interactive" style="border-top:4px solid #f97316; background:linear-gradient(135deg, rgba(249, 115, 22, 0.1), transparent); cursor:pointer;" onclick="document.getElementById('addExpenseModal').style.display='flex'">
        <i class="fas fa-receipt" style="font-size:22px; color:#f97316; margin-bottom:10px;"></i>
        <div style="font-size:20px; font-weight:800; color:var(--stat-value-color);">Rp<?= number_format($expenses_range, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:700;">Pengeluaran</div>
        <div style="font-size:10px; color:var(--text-secondary); margin-top:8px; font-weight:700; opacity:0; pointer-events:none;">Filler</div>
    </div>
</div>

<!-- Progress Bar -->
<div class="glass-panel" style="padding:14px 20px; margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <span style="font-size:12px; color:var(--text-secondary);"><i class="fas fa-chart-line"></i> Progres Penagihan <?= date('m/Y', strtotime($date_from)) ?></span>
        <span style="font-size:14px; font-weight:700; color:<?= $percent_paid >= 80 ? 'var(--success)' : ($percent_paid >= 50 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $percent_paid ?>%</span>
    </div>
    <div style="background:var(--progress-bg); border-radius:10px; height:8px; overflow:hidden;">
        <div style="width:<?= $percent_paid ?>%; height:100%; background:linear-gradient(to right, <?= $percent_paid >= 80 ? 'var(--success), #34d399' : ($percent_paid >= 50 ? 'var(--warning), #fbbf24' : 'var(--danger), #f87171') ?>); border-radius:10px; transition:width 1s ease;"></div>
    </div>
</div>
<?php endif; ?>

<!-- Tab Navigation - Segmented Control Style -->
<div style="display:flex; background:rgba(255,255,255,0.03); padding:5px; border-radius:14px; margin-bottom:20px; gap:5px;" class="desktop-tabs">
    <a href="index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&month=<?= $selected_month ?>" style="flex:1; text-align:center; text-decoration:none; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'tugas' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        <span class="hide-mobile">Tugas Penagihan</span><span class="show-mobile">Tugas</span>
    </a>
    <a href="index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&month=<?= $selected_month ?>" style="flex:1; text-align:center; text-decoration:none; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'lunas' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        <span class="hide-mobile">Sudah Lunas</span><span class="show-mobile">Lunas</span>
    </a>
    <a href="index.php?page=collector&tab=pelanggan&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&month=<?= $selected_month ?>" style="flex:1; text-align:center; text-decoration:none; padding:10px; border-radius:10px; font-size:13px; font-weight:700; transition:all 0.3s; <?= $coll_tab === 'pelanggan' ? 'background:var(--primary); color:white; box-shadow:0 4px 15px rgba(var(--primary-rgb), 0.2);' : 'color:var(--text-secondary);' ?>">
        <span class="hide-mobile">Daftar Pelanggan</span><span class="show-mobile">Pelanggan</span>
    </a>
</div>

<?php if($coll_tab === 'pelanggan'): ?>
<!-- TAB: Data Pelanggan -->
<div class="glass-panel" style="padding:15px; position:relative;">
    <div style="display:flex; align-items:center; gap:8px; margin-bottom:15px; border-bottom:1px solid var(--glass-border); padding-bottom:12px;">
        <button class="btn btn-primary" onclick="showAddCustomerModal()" style="width:42px; height:42px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:10px; flex-shrink:0;" title="Tambah Pelanggan">
            <i class="fas fa-user-plus" style="font-size:16px;"></i>
        </button>
        
        <form method="GET" style="display:flex; gap:6px; flex:1; align-items:center;">
            <input type="hidden" name="page" value="collector">
            <input type="hidden" name="tab" value="pelanggan">
            <input type="hidden" name="date_from" value="<?= $date_from ?>">
            <input type="hidden" name="date_to" value="<?= $date_to ?>">
            
            <select name="filter_billing_date" class="form-control" onchange="this.form.submit()" style="width:110px; height:40px; font-size:11px; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); padding:0 8px;">
                <option value="">Tanggal Tagihan</option>
                <?php for($d=1;$d<=28;$d++): ?>
                    <option value="<?= $d ?>" <?= $filter_billing_date == $d ? 'selected' : '' ?>>Tanggal <?= $d ?></option>
                <?php endfor; ?>
            </select>

            <div style="position:relative; flex:1;">
                <input type="text" name="search_cust" class="form-control" placeholder="Cari..." value="<?= htmlspecialchars($search_cust) ?>" style="padding:8px 35px 8px 12px; font-size:12px; height:40px; width:100%; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border);">
                <button type="submit" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--primary); cursor:pointer; padding:4px;">
                    <i class="fas fa-search" style="font-size:14px;"></i>
                </button>
            </div>
        </form>
    </div>

    <!-- Filter Status Indicator -->
    <?php if($filter_status !== 'all'): ?>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; padding:10px 15px; background:rgba(var(--primary-rgb), 0.08); border-radius:10px; border:1px solid rgba(var(--primary-rgb), 0.15);">
            <div style="font-size:12px; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:6px;">
                <i class="fas fa-filter"></i> 
                <span>Menampilkan: <strong>
                    <?php 
                        if ($filter_status === 'unpaid') echo 'Belum Lunas';
                        elseif ($filter_status === 'paid') echo 'Sudah Lunas';
                        elseif ($filter_status === 'new') echo 'Pendaftaran Baru';
                        else echo 'Semua';
                    ?>
                </strong></span>
            </div>
            <a href="index.php?page=collector&tab=pelanggan&filter_status=all" style="text-decoration:none; font-size:11px; color:var(--danger); font-weight:800; margin-left:auto; display:flex; align-items:center; gap:4px; padding:4px 8px; background:rgba(239, 68, 68, 0.1); border-radius:6px;">
                <i class="fas fa-times-circle"></i> RESET
            </a>
        </div>
    <?php endif; ?>

    
    <!-- List Container with Scroll -->
    <div class="scroll-container list-container-responsive" style="height: calc(100vh - 460px); overflow-y: auto; padding-right: 5px; margin-top: 10px;">
        <!-- Mobile-friendly card list -->
        <div class="customer-list-mobile">
        <?php foreach($area_customers as $ac): 
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
            $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
        ?>
        <div class="glass-panel" style="padding:16px; margin-bottom:10px; border-left:3px solid var(--primary); cursor:pointer; transition:transform 0.2s;" onclick="showCustomerDetails(<?= $ac['id'] ?>)">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                <div>
                    <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($ac['name']) ?></div>
                    <div style="font-size:11px; color:var(--primary); font-family:monospace; margin-bottom:4px;">ID: <?= $cust_id_display ?></div>
                    <div style="font-size:11px; color:var(--text-secondary); margin-bottom:6px;"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ac['address'] ?: '-') ?></div>
                    <div style="font-size:11px; color:var(--text-secondary);"><i class="fas fa-calendar-alt" style="color:var(--warning);"></i> Siklus Tagih: Tanggal <?= $ac['billing_date'] ?></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:15px; font-weight:800; color:var(--primary);">Rp <?= number_format($ac['monthly_fee'], 0, ',', '.') ?></div>
                    <div style="font-size:10px; color:var(--text-secondary);"><?= htmlspecialchars($ac['package_name']) ?></div>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:15px;" onclick="event.stopPropagation();">
                <?php if($ac['unpaid_count'] > 0): ?>
                <button class="btn btn-sm" style="background:#25D366; color:white; font-weight:800; border-radius:10px; padding:0 15px; height:42px; display:flex; align-items:center; gap:8px;" onclick="handlePay(<?= $ac['id'] ?>, <?= $ac['unpaid_count'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                    <i class="fas fa-wallet"></i> BAYAR
                </button>
                <?php endif; ?>
                <button class="btn btn-sm" style="background:var(--warning); color:white; width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0;" onclick="showCreateInvoice(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)" title="Buat Tagihan Manual">
                    <i class="fas fa-file-invoice-dollar" style="font-size:16px;"></i>
                </button>
                <button class="btn btn-sm btn-ghost" style="width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; border:1px solid var(--glass-border);" onclick="showUpdateContact(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', '<?= htmlspecialchars($ac['contact'] ?: '') ?>')" title="Ubah Nama/Nomor Telepon">
                    <i class="fas fa-edit" style="font-size:16px;"></i>
                </button>
                <button class="btn btn-sm" style="background:#d97706; color:white; width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; box-shadow:0 4px 10px rgba(217, 119, 6, 0.2);" onclick="showCustomerDetails(<?= $ac['id'] ?>); setTimeout(() => openAddonModal(), 500);" title="Tambah Add-on">
                    <i class="fas fa-plus-circle" style="font-size:18px;"></i>
                </button>
                <?php if($wa_num): 
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
                <button onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($rem_msg)) ?>, '<?= $rem_wa_link ?>', this)" class="btn btn-sm" style="background:#25D366; color:white; width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; border:none; cursor:pointer;" title="WhatsApp Reminder">
                    <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                </button>
                <a href="tel:<?= htmlspecialchars($ac['contact']) ?>" class="btn btn-sm btn-ghost" style="width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; border:1px solid var(--glass-border);" title="Telepon">
                    <i class="fas fa-phone" style="font-size:16px;"></i>
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
                    <th style="padding-left:15px;">Pelanggan</th>
                    <th>Detail Layanan & Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($area_customers as $ac): 
                    $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
                    $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
                ?>
                <tr style="cursor:pointer;" onclick="showCustomerDetails(<?= $ac['id'] ?>)">
                    <td style="padding:15px;">
                        <div style="font-weight:700; color:var(--text-primary); font-size:15px;"><?= htmlspecialchars($ac['name']) ?></div>
                        <div style="font-size:11px; color:var(--primary); font-family:monospace; margin-top:2px;">ID: <?= $cust_id_display ?></div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ac['address'] ?: '-') ?></div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;"><i class="fas fa-calendar-alt" style="color:var(--warning);"></i> Siklus: Tanggal <?= $ac['billing_date'] ?></div>
                    </td>
                    <td style="padding:15px;">
                        <div style="margin-bottom:8px;">
                            <div style="font-size:13px; font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($ac['package_name']) ?></div>
                            <div style="font-size:16px; font-weight:800; color:var(--primary);">Rp <?= number_format($ac['monthly_fee'], 0, ',', '.') ?></div>
                        </div>
                        <div style="margin-bottom:12px;">
                            <a href="tel:<?= htmlspecialchars($ac['contact']) ?>" style="color:var(--text-primary); text-decoration:none; display:flex; align-items:center; gap:6px; font-weight:700; font-size:13px;">
                                <i class="fas fa-phone-alt" style="color:var(--primary); font-size:12px;"></i> <?= htmlspecialchars($ac['contact'] ?: '-') ?>
                            </a>
                        </div>
                        <div style="display:flex; gap:10px;" onclick="event.stopPropagation();">
                            <?php if($ac['unpaid_count'] > 0): ?>
                            <button class="btn btn-sm" style="background:#25D366; color:white; font-weight:800; border-radius:10px; padding:0 15px; height:42px; display:flex; align-items:center; gap:8px;" onclick="handlePay(<?= $ac['id'] ?>, <?= $ac['unpaid_count'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                                <i class="fas fa-wallet"></i> BAYAR (<?= $ac['unpaid_count'] ?>)
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm" style="background:var(--warning); color:white; width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; box-shadow:0 4px 10px rgba(245, 158, 11, 0.2);" onclick="showCreateInvoice(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)" title="Buat Tagihan Manual">
                                <i class="fas fa-file-invoice-dollar" style="font-size:16px;"></i>
                            </button>
                            <button class="btn btn-sm btn-ghost" style="width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; border:1px solid var(--glass-border); background:rgba(255,255,255,0.05);" onclick="showUpdateContact(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>', '<?= htmlspecialchars($ac['contact'] ?: '') ?>')" title="Ubah Nomor Telepon">
                                <i class="fas fa-edit" style="font-size:16px;"></i>
                            </button>
                            <button class="btn btn-sm" style="background:#d97706; color:white; width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; box-shadow:0 4px 10px rgba(217, 119, 6, 0.2);" onclick="showCustomerDetails(<?= $ac['id'] ?>); setTimeout(() => openAddonModal(), 500);" title="Tambah Add-on">
                                <i class="fas fa-plus-circle" style="font-size:18px;"></i>
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
                                <button onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($rem_msg)) ?>, '<?= $rem_wa_link ?>', this)" class="btn btn-sm" style="background:#25D366; color:white; width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; box-shadow:0 4px 10px rgba(37, 211, 102, 0.2); border:none; cursor:pointer;" title="Kirim Pesan Tagihan">
                                    <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div> <!-- end .table-container -->
    </div> <!-- end .scroll-container -->

    <!-- Estimasi Pendapatan Card (Static Bottom) -->
    <div class="static-summary-bar">
        <div class="glass-panel" style="padding:15px 20px; border-left:4px solid var(--primary); background:linear-gradient(to right, rgba(var(--primary-rgb), 0.15), rgba(var(--primary-rgb), 0.05)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:18px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:42px; height:42px; border-radius:12px; background:var(--primary); color:white; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 4px 10px rgba(var(--primary-rgb),0.3); flex-shrink:0;">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div>
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; font-weight:800;">Estimasi Pendapatan</div>
                    <div style="font-size:18px; font-weight:800; color:var(--text-primary); line-height:1.2;">Rp<?= number_format($total_estimasi, 0, ',', '.') ?></div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:10px; color:var(--text-secondary); font-weight:800; text-transform:uppercase; letter-spacing:1px;">Target</div>
                <div style="font-size:18px; font-weight:800; color:var(--text-primary); line-height:1.2;"><?= number_format($total_cust_filter, 0, ',', '.') ?> <small style="font-size:10px; opacity:0.8;">PELANGGAN</small></div>
            </div>
        </div>
    </div>
</div> <!-- end .glass-panel (Tab: Data Pelanggan) -->
<?php elseif($coll_tab === 'tugas'): ?>
<!-- TAB: Tugas Penagihan -->
<div class="glass-panel tab-flex-container" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h3 style="font-size:16px; margin:0; color:var(--danger);"><i class="fas fa-tasks"></i> Daftar Tugas Belum Lunas</h3>
        <div style="font-size:12px; color:var(--text-secondary); font-weight:700;">Total: <?= count($unpaid_invoices) ?> Pelanggan</div>
    </div>

    <!-- Search in Tasks -->
    <form method="GET" style="margin-bottom:15px; display:flex; gap:10px;">
        <input type="hidden" name="page" value="collector">
        <input type="hidden" name="tab" value="tugas">
        <div style="position:relative; flex:1;">
            <input type="text" name="search_tugas" class="form-control" placeholder="Cari nama/alamat tugas..." value="<?= htmlspecialchars($search_tugas) ?>" style="padding:10px 40px 10px 15px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); width:100%;">
            <button type="submit" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--primary); cursor:pointer;">
                <i class="fas fa-search"></i>
            </button>
        </div>
    </form>

    <div class="scroll-container list-container-responsive" style="flex: 1; overflow-y:auto; padding-right:5px;">
        <!-- Desktop Mode: Table -->
        <div class="customer-list-desktop glass-panel" style="display:none; padding:0; overflow:hidden; border:1px solid var(--glass-border);">
            <table class="table" style="width:100%; border-collapse:collapse; color:var(--text-primary);">
                <thead style="background:rgba(255,255,255,0.03); border-bottom:1px solid var(--glass-border);">
                    <tr>
                        <th style="padding:15px; text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Pelanggan</th>
                        <th style="padding:15px; text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Area / Alamat</th>
                        <th style="padding:15px; text-align:center; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Tunggakan</th>
                        <th style="padding:15px; text-align:right; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Total Tagihan</th>
                        <th style="padding:15px; text-align:center; font-size:12px; text-transform:uppercase; letter-spacing:1px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($unpaid_invoices as $ui): 
                        $wa_num_t = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ui['contact']));
                        $cust_id_t = $ui['customer_code'] ?: str_pad($ui['cust_id'], 5, "0", STR_PAD_LEFT);
                    ?>
                    <tr style="border-bottom:1px solid var(--glass-border); transition:background 0.2s; cursor:pointer;" onclick="showCustomerDetails(<?= $ui['cust_id'] ?>)">
                        <td style="padding:15px;">
                            <div style="font-weight:700; font-size:14px;"><?= htmlspecialchars($ui['name']) ?></div>
                            <div style="font-size:11px; color:var(--primary); font-family:monospace;">ID: <?= $cust_id_t ?></div>
                        </td>
                        <td style="padding:15px;">
                            <div style="font-size:12px; color:var(--text-primary);"><i class="fas fa-map-marker-alt" style="color:var(--text-secondary); width:15px;"></i> <?= htmlspecialchars($ui['cust_area'] ?: '-') ?></div>
                            <div style="font-size:11px; color:var(--text-secondary); margin-top:2px; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($ui['address'] ?: '-') ?></div>
                        </td>
                        <td style="padding:15px; text-align:center;">
                            <span style="background:rgba(239, 68, 68, 0.1); color:var(--danger); padding:4px 10px; border-radius:6px; font-size:11px; font-weight:800; border:1px solid rgba(239, 68, 68, 0.2);">
                                <i class="fas fa-exclamation-circle"></i> <?= $ui['num_arrears'] ?> BULAN
                            </span>
                        </td>
                        <td style="padding:15px; text-align:right;">
                            <div style="font-weight:800; color:var(--danger); font-size:15px;">Rp <?= number_format($ui['total_unpaid'], 0, ',', '.') ?></div>
                            <div style="font-size:10px; color:var(--text-secondary);"><?= htmlspecialchars($ui['package_name']) ?></div>
                        </td>
                        <td style="padding:15px; text-align:center;" onclick="event.stopPropagation();">
                            <div style="display:flex; gap:6px; justify-content:center;">
                                <button class="btn btn-sm" style="background:#25D366; color:white; font-weight:800; border-radius:8px; padding:6px 12px; font-size:11px; border:none;" onclick="handlePay(<?= $ui['cust_id'] ?>, <?= $ui['num_arrears'] ?>, '<?= addslashes($ui['name']) ?>', <?= ($ui['total_unpaid'] / $ui['num_arrears']) ?>)">
                                    <i class="fas fa-wallet"></i> BAYAR
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
                                <button onclick="sendWAGateway('<?= $wa_num_t ?>', <?= htmlspecialchars(json_encode($rem_msg_t)) ?>, '<?= $rem_wa_link_t ?>', this)" class="btn btn-sm" style="background:#25D366; color:white; width:34px; height:34px; display:flex; align-items:center; justify-content:center; border-radius:8px; padding:0; border:none; cursor:pointer;" title="WhatsApp Reminder">
                                    <i class="fab fa-whatsapp" style="font-size:16px;"></i>
                                </button>
                                <a href="tel:<?= htmlspecialchars($ui['contact']) ?>" class="btn btn-sm btn-ghost" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center; border-radius:8px; padding:0; border:1px solid var(--glass-border);" title="Telepon">
                                    <i class="fas fa-phone" style="font-size:14px;"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Mode: Cards -->
        <div class="customer-list-mobile">
        <?php foreach($unpaid_invoices as $ui): 
            $wa_num_t = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ui['contact']));
            $cust_id_t = $ui['customer_code'] ?: str_pad($ui['cust_id'], 5, "0", STR_PAD_LEFT);
        ?>
        <div class="glass-panel" style="margin-bottom:12px; padding:15px; border-left:4px solid var(--danger); cursor:pointer;" onclick="showCustomerDetails(<?= $ui['cust_id'] ?>)">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div style="flex:1;">
                    <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($ui['name']) ?></div>
                    <div style="font-size:11px; color:var(--primary); font-family:monospace; margin-top:2px;">ID: <?= $cust_id_t ?></div>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($ui['address'] ?: '-') ?></div>
                    <div style="font-size:11px; color:var(--danger); font-weight:700; margin-top:5px;"><i class="fas fa-exclamation-circle"></i> Tunggakan: <?= $ui['num_arrears'] ?> Bulan</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:16px; font-weight:800; color:var(--danger);">Rp <?= number_format($ui['total_unpaid'], 0, ',', '.') ?></div>
                    <div style="font-size:10px; color:var(--text-secondary);"><?= htmlspecialchars($ui['package_name']) ?></div>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:15px;" onclick="event.stopPropagation();">
                <button class="btn btn-sm" style="background:#25D366; color:white; font-weight:800; border-radius:10px; padding:0 15px; height:42px; display:flex; align-items:center; gap:8px;" onclick="handlePay(<?= $ui['cust_id'] ?>, <?= $ui['num_arrears'] ?>, '<?= addslashes($ui['name']) ?>', <?= ($ui['total_unpaid'] / $ui['num_arrears']) ?>)">
                    <i class="fas fa-wallet"></i> BAYAR
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
                <button onclick="sendWAGateway('<?= $wa_num_t ?>', <?= htmlspecialchars(json_encode($rem_msg_t)) ?>, '<?= $rem_wa_link_t ?>', this)" class="btn btn-sm" style="background:#25D366; color:white; width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; border:none; cursor:pointer;" title="WhatsApp Reminder">
                    <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                </button>
                <a href="tel:<?= htmlspecialchars($ui['contact']) ?>" class="btn btn-sm btn-ghost" style="width:45px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; border:1px solid var(--glass-border);" title="Telepon">
                    <i class="fas fa-phone" style="font-size:16px;"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div> <!-- end .customer-list-mobile -->
    </div> <!-- end .scroll-container -->

    <!-- Summary Static for Tasks -->
    <div class="static-summary-bar">
        <div class="glass-panel" style="padding:12px 18px; border-left:4px solid var(--danger); background:linear-gradient(to right, rgba(239, 68, 68, 0.12), rgba(239, 68, 68, 0.04)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:15px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:var(--danger); color:white; display:flex; align-items:center; justify-content:center; font-size:18px; box-shadow:0 4px 10px rgba(239, 68, 68, 0.2); flex-shrink:0;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <div style="font-size:9px; color:var(--text-secondary); text-transform:uppercase; font-weight:800; letter-spacing:1px;">Total Tunggakan</div>
                    <div style="font-size:16px; font-weight:800; color:var(--text-primary); line-height:1.1;">Rp<?= number_format($unpaid_total, 0, ',', '.') ?></div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:9px; color:var(--text-secondary); font-weight:800; text-transform:uppercase; letter-spacing:1px;">Terhutang</div>
                <div style="font-size:16px; font-weight:800; color:var(--text-primary); line-height:1.1;"><?= $unpaid_count ?> <small style="font-size:9px; opacity:0.8;">TAGIHAN</small></div>
            </div>
        </div>
    </div>
</div> <!-- end glass-panel (Tab: Tugas) -->
<?php elseif($coll_tab === 'lunas'): ?>
<!-- TAB: Sudah Lunas -->
<div class="glass-panel tab-flex-container" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
        <h3 style="font-size:16px; margin:0; color:var(--success);">
            Riwayat Pembayaran Lunas
        </h3>
        <!-- Date Filter Form -->
        <form method="GET" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <input type="hidden" name="page" value="collector">
            <input type="hidden" name="tab" value="lunas">
            <div style="display:flex; align-items:center; gap:5px; background:rgba(255,255,255,0.03); padding:4px 10px; border-radius:8px; border:1px solid var(--glass-border);">
                <input type="date" name="date_from" value="<?= $date_from ?>" style="background:none; border:none; color:var(--text-primary); font-size:12px; outline:none;">
                <span style="color:var(--text-secondary); font-size:12px;">sampai dengan</span>
                <input type="date" name="date_to" value="<?= $date_to ?>" style="background:none; border:none; color:var(--text-primary); font-size:12px; outline:none;">
            </div>
            <button type="submit" class="btn btn-sm" style="background:var(--primary); color:white; border-radius:8px; padding:6px 12px; font-weight:700;">FILTER</button>
        </form>
    </div>
    <div class="scroll-container list-container-responsive" style="flex: 1; overflow-y:auto; padding-right:5px; margin-top:10px;">
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
                </div>
                <div style="display:flex; gap:6px;">
                    <?php 
                        $wa_num_rp = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $rp['contact'] ?? ''));
                        // Fix for redirecting directly to admin_invoices?action=print
                        $receipt_link = "index.php?page=admin_invoices&action=print&id=" . $rp['id'] . "&format=thermal";
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
                        <button onclick="sendWAGateway('<?= $wa_num_rp ?>', <?= htmlspecialchars(json_encode($parsed_msg_rp)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_num_rp ?>&text=<?= $wa_msg_rp ?>', this)" class="btn btn-sm btn-ghost" style="color:#25D366; border:1px solid #25D36633; cursor:pointer;" title="Kirim WA">
                            <i class="fab fa-whatsapp"></i>
                        </button>
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
    
    <!-- Summary Static for Lunas -->
    <div class="static-summary-bar">
        <div class="glass-panel" style="padding:12px 18px; border-left:4px solid var(--success); background:linear-gradient(to right, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.04)); backdrop-filter:blur(15px); display:flex; justify-content:space-between; align-items:center; border-radius:15px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:38px; height:38px; border-radius:10px; background:var(--success); color:white; display:flex; align-items:center; justify-content:center; font-size:18px; box-shadow:0 4px 10px rgba(16, 185, 129, 0.2); flex-shrink:0;">
                    <i class="fas fa-coins"></i>
                </div>
                <div>
                    <div style="font-size:9px; color:var(--text-secondary); text-transform:uppercase; font-weight:800; letter-spacing:1px;">Pendapatan Terkumpul</div>
                    <div style="font-size:16px; font-weight:800; color:var(--text-primary); line-height:1.1;">Rp<?= number_format($paid_total_range, 0, ',', '.') ?></div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:9px; color:var(--text-secondary); font-weight:800; text-transform:uppercase; letter-spacing:1px;">Lunas</div>
                <div style="font-size:16px; font-weight:800; color:var(--text-primary); line-height:1.1;"><?= count($recent_paid) ?> <small style="font-size:9px; opacity:0.8;">PELANGGAN</small></div>
            </div>
        </div>
    </div>
</div> <!-- end glass-panel (Tab: Lunas) -->
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

<!-- Modal Ubah Nomor Telepon -->
<div id="updateContactModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;"><i class="fas fa-edit"></i> Ubah Nomor Telepon</h3>
        <div style="font-size:14px; color:var(--text-secondary); margin-bottom:15px;">Pelanggan: <strong id="modalContactCustName"></strong></div>
        <form action="index.php?page=collector&action=update_contact" method="POST">
            <input type="hidden" name="customer_id" id="modalContactCustId">
            <div class="form-group">
                <label>Nomor Telepon Baru</label>
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
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">WhatsApp / Telepon</label>
                        <input type="text" name="contact" class="form-control" placeholder="0812..." required style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                    </div>
                    <div class="form-group">
                        <input type="hidden" name="type" value="customer">
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
                                <option value="<?= htmlspecialchars($pkg['name']) ?>" data-fee="<?= $pkg['fee'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rp<?= number_format($pkg['fee'],0,',','.') ?>)</option>
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
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Tanggal Registrasi</label>
                        <input type="date" name="registration_date" value="<?= date('Y-m-d') ?>" class="form-control" required style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Tunggakan / Hutang Awal (Rp)</label>
                        <input type="number" name="previous_debt" placeholder="Jika ada" class="form-control" style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px;">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label style="font-size:13px; color:var(--text-secondary); margin-bottom:8px; display:block;">Alamat Lengkap</label>
                        <textarea name="address" class="form-control" rows="3" placeholder="Jl. Contoh Nomor 1, Desa/Dusun, RT/RW..." style="padding:12px 16px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;"></textarea>
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

<!-- Modal Pelunasan Tunggakan (Bulk Pay) -->
<div id="bulkPayModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px; border-top:4px solid var(--success);">
        <h3 style="margin-bottom:10px; font-weight:800;"><i class="fas fa-layer-group text-success"></i> Pelunasan Tunggakan</h3>
        <p style="font-size:14px; color:var(--text-secondary); margin-bottom:20px;">Bayar sebagian atau seluruh tunggakan untuk <strong><span id="bulkCustNameTitle"></span></strong>.</p>
        
        <div class="form-group mb-4">
            <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Berapa Bulan?</label>
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="flex:1;">
                    <input type="number" id="bulkMonthInput" class="form-control" value="1" min="1" oninput="updateBulkTotalDisplay()" onchange="updateBulkTotalDisplay()" style="padding:12px; border-radius:10px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); width:100%; font-weight:800; font-size:18px; text-align:center;">
                </div>
                <div style="font-weight:600; color:var(--text-secondary);">Dari <span id="bulkTotalMonthsTitle"></span> Bulan</div>
            </div>
        </div>

        <div style="padding:15px; background:rgba(16, 185, 129, 0.05); border:1px solid rgba(16, 185, 129, 0.2); border-radius:12px; margin-bottom:24px;">
            <div style="font-size:11px; color:var(--text-secondary); font-weight:700; margin-bottom:4px;">TOTAL BAYAR:</div>
            <div style="font-size:24px; font-weight:800; color:var(--success);" id="bulkTotalAmtDisplay">Rp 0</div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulkPayModal').style.display='none'">Batal</button>
            <button type="button" class="btn btn-success" style="font-weight:800; padding:10px 25px;" onclick="confirmBulkPay()">PROSES PEMBAYARAN</button>
        </div>
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

let currentPayData = { custId: 0, monthlyFee: 0, maxMonths: 0 };

function handlePay(custId, maxMonths, custName, monthlyFee) {
    currentPayData = { custId, monthlyFee, maxMonths };
    
    if (maxMonths > 1) {
        document.getElementById('bulkCustNameTitle').innerText = custName;
        document.getElementById('bulkTotalMonthsTitle').innerText = maxMonths;
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
async function showCustomerDetails(id) {
    lastViewedCustId = id;
    // 1. Show loading state in modal
    document.getElementById('detCustName').textContent = 'Memuat...';
    document.getElementById('detHistoryList').innerHTML = '<div style="text-align:center; padding:30px; opacity:0.5;"><i class="fas fa-spinner fa-spin"></i> Sedang mengambil data...</div>';
    document.getElementById('customerDetailModal').style.display = 'flex';
    
    try {
        const response = await fetch(`app/customer_history.php?id=${id}`);
        const data = await response.json();
        
        if(data.error) { throw new Error(data.error); }
        
        // 2. Populate Header & Info
        document.getElementById('detCustName').textContent = data.customer.name;
        document.getElementById('detCustId').textContent = 'ID: ' + (data.customer.customer_code || data.customer.id.toString().padStart(5, '0'));
        document.getElementById('detCustPkg').textContent = data.customer.package_name;
        document.getElementById('detCustBilling').textContent = 'Tanggal ' + data.customer.billing_date;
        document.getElementById('detCustAddr').textContent = data.customer.address || '-';
        document.getElementById('detCustPhone').textContent = data.customer.contact || '-';
        
        // Format Registration Date
        const regDate = data.customer.registration_date ? new Date(data.customer.registration_date).toLocaleDateString('id-ID', {day:'2-digit', month:'long', year:'numeric'}) : '-';
        document.getElementById('detCustRegDate').textContent = regDate;
        
        // 3. Arrears logic
        const hasArrears = data.history.some(h => h.status === 'Belum Lunas');
        document.getElementById('detCustArrearsSection').style.display = hasArrears ? 'block' : 'none';

        // 4. Populate History
        let historyHtml = '';
        if(data.history && data.history.length > 0) {
            data.history.forEach(item => {
                const isPaid = item.status === 'Lunas';
                const statusColor = isPaid ? '#10b981' : '#ef4444';
                const statusIcon = isPaid ? 'fa-check-circle' : 'fa-clock';
                const payDate = item.payment_date ? new Date(item.payment_date).toLocaleDateString('id-ID', {day:'2-digit', month:'short', year:'numeric'}) : '-';
                const amount = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(item.invoice_amount).replace('IDR', 'Rp');

                historyHtml += `
                    <div class="glass-panel" style="padding:12px; padding-left:15px; border-left:4px solid ${statusColor}; background:rgba(255,255,255,0.02); margin-bottom:8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <div style="font-size:13px; font-weight:800; color:${statusColor}; text-transform:uppercase;">${item.status}</div>
                                <div style="font-size:11px; font-weight:700; margin-top:2px;">${item.description ? item.description : 'Tagihan Bulanan'}</div>
                                <div style="font-size:10px; color:var(--text-secondary); margin-top:2px;">Jatuh Tempo: ${item.due_date}</div>
                                ${isPaid ? `<div style="font-size:10px; color:var(--text-secondary); margin-top:2px;"><i class="fas fa-calendar-check"></i> Dibayar: ${payDate}</div>` : ''}
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:800; font-size:15px;">${amount}</div>
                                <div style="font-size:9px; color:var(--text-secondary);">${item.collector_name ? 'Oleh: '+item.collector_name : ''}</div>
                            </div>
                        </div>
                    </div>
                `;
            });
        } else {
            historyHtml = '<div style="text-align:center; padding:30px; opacity:0.5;">Belum ada riwayat tagihan.</div>';
        }
        document.getElementById('detHistoryList').innerHTML = historyHtml;

    } catch (error) {
        document.getElementById('detCustName').textContent = 'Error';
        document.getElementById('detHistoryList').innerHTML = `<div style="text-align:center; padding:30px; color:var(--danger);">${error.message}</div>`;
    }
}

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
</script>

<!-- Modal Detail Pelanggan & Riwayat Pembayaran -->
<div id="customerDetailModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; z-index:9999; backdrop-filter: blur(10px);">
    <div class="glass-panel" style="width:95%; max-width:500px; padding:0; overflow:hidden; border-radius:20px; box-shadow:0 25px 50px rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.1);">
        <!-- Modal Header -->
        <div style="padding:20px; background:linear-gradient(to right, var(--primary), #1e293b); color:white; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center; font-size:20px;">
                    <i class="fas fa-user-tag"></i>
                </div>
                <div>
                    <div id="detCustName" style="font-weight:800; font-size:17px; line-height:1.2;">...</div>
                    <div id="detCustId" style="font-size:11px; opacity:0.8; font-family:monospace; margin-top:2px;">ID: ...</div>
                </div>
            </div>
            <button onclick="document.getElementById('customerDetailModal').style.display='none'" style="background:none; border:none; color:white; font-size:24px; cursor:pointer; opacity:0.7; padding:10px;">&times;</button>
        </div>
        
        <!-- Modal Content -->
        <div class="scroll-container" style="max-height:65vh; overflow-y:auto; padding:20px;">
            <!-- Customer Info Summary -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:20px;">
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700; margin-bottom:4px;">Paket</div>
                    <div id="detCustPkg" style="font-weight:700; font-size:13px;">...</div>
                </div>
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700; margin-bottom:4px;">Siklus Tagih</div>
                    <div id="detCustBilling" style="font-weight:700; font-size:13px;">Tanggal ...</div>
                </div>
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700; margin-bottom:4px;">Nomor Telepon / WhatsApp</div>
                    <div id="detCustPhone" style="font-weight:700; font-size:13px;">...</div>
                </div>
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border);">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700; margin-bottom:4px;">Tanggal Registrasi</div>
                    <div id="detCustRegDate" style="font-weight:700; font-size:13px;">...</div>
                </div>
                <div class="glass-panel" style="padding:12px; background:rgba(var(--primary-rgb), 0.05); border:1px solid var(--glass-border); grid-column: span 2;">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700; margin-bottom:4px;">Alamat Lengkap</div>
                    <div id="detCustAddr" style="font-weight:600; font-size:13px; line-height:1.4;">...</div>
                </div>
            </div>

            <!-- Arrears Alert (if any) -->
            <div id="detCustArrearsSection" style="display:none; margin-bottom:20px; padding:12px; border-radius:10px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.2); color:var(--danger);">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div style="font-weight:800; font-size:13px;">Terdapat Tagihan Belum Lunas!</div>
                </div>
            </div>

            <!-- Payment History -->
            <div style="font-weight:800; font-size:15px; margin-bottom:15px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-history" style="color:var(--primary);"></i> Riwayat Pembayaran (12 bln)
            </div>
            <div id="detHistoryList" style="display:flex; flex-direction:column;">
                <!-- Dynamically populated -->
                <div style="text-align:center; padding:30px; opacity:0.5;">Memuat data...</div>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div style="padding:15px 20px; border-top:1px solid var(--glass-border); background:rgba(255,255,255,0.02); display:flex; gap:10px;">
             <button onclick="openAddonModal()" class="btn btn-warning" style="flex:1; border-radius:10px; padding:12px; font-weight:700; background:rgba(217,119,6,0.1); color:#d97706; border:1px solid rgba(217,119,6,0.3);">
                 <i class="fas fa-plus-circle"></i> ADD-ON
             </button>
             <button onclick="document.getElementById('customerDetailModal').style.display='none'" class="btn btn-primary" style="flex:2; border-radius:10px; padding:12px; font-weight:700;">TUTUP</button>
        </div>
    </div>
</div>
<!-- Global Hidden Form for Bulk Payment Submission -->
<form id="payFormGlobal" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
    <input type="hidden" name="customer_id" id="globalCustId">
    <input type="hidden" name="num_months" id="globalNumMonths" value="1">
</form>

<!-- Modal Tambah Pengeluaran -->
<div id="addExpenseModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter: blur(8px);">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:0; margin:20px; border-radius:18px; border:1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">
        <!-- Header -->
        <div style="padding:20px; background:linear-gradient(to right, #f97316, #ea580c); color:white; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i class="fas fa-receipt"></i>
                <h3 style="margin:0; font-size:16px; font-weight:700;">Tambah Pengeluaran</h3>
            </div>
            <button onclick="document.getElementById('addExpenseModal').style.display='none'" style="background:none; border:none; color:white; cursor:pointer; font-size:18px;">&times;</button>
        </div>
        
        <form action="index.php?page=collector&action=add_expense" method="POST" style="padding:20px;">
            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-size:12px; color:var(--text-secondary); margin-bottom:5px; display:block;">Kategori / Nama Pengeluaran</label>
                <input type="text" name="category" class="form-control" placeholder="Contoh: Bensin, Makan, dsb" required style="padding:10px; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
            </div>
            
            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-size:12px; color:var(--text-secondary); margin-bottom:5px; display:block;">Nominal (Rp)</label>
                <input type="number" name="amount" class="form-control" placeholder="0" required style="padding:12px; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:16px; font-weight:800; width:100%;">
            </div>
            
            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-size:12px; color:var(--text-secondary); margin-bottom:5px; display:block;">Tanggal</label>
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" class="form-control" required style="padding:10px; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
            </div>
            
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-size:12px; color:var(--text-secondary); margin-bottom:5px; display:block;">Keterangan / Catatan</label>
                <textarea name="description" class="form-control" placeholder="Contoh: Bensin motor keliling desa" rows="2" style="padding:10px; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;"></textarea>
            </div>
            
            <button type="submit" class="btn" style="width:100%; background:#f97316; color:white; padding:12px; border-radius:10px; font-weight:800; font-size:14px; border:none; box-shadow: 0 4px 15px rgba(249, 115, 22, 0.2);">SIMPAN PENGELUARAN</button>
        </form>
    </div>
</div>

<!-- Modal Tambah Add-on (Tagihan Manual) -->
<div id="addAddonModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:10000; backdrop-filter: blur(8px);">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:0; margin:20px; border-radius:18px; border:1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">
        <!-- Header -->
        <div style="padding:20px; background:linear-gradient(to right, #d97706, #b45309); color:white; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i class="fas fa-plus-circle"></i>
                <h3 style="margin:0; font-size:16px; font-weight:700;">Tambah Add-on</h3>
            </div>
            <button onclick="document.getElementById('addAddonModal').style.display='none'" style="background:none; border:none; color:white; cursor:pointer; font-size:18px;">&times;</button>
        </div>
        
        <form action="index.php?page=collector&action=add_addon" method="POST" style="padding:20px;">
            <input type="hidden" name="customer_id" id="addonCustId">
            <p style="font-size:12px; color:var(--text-secondary); margin-bottom:15px; background:rgba(217,119,6,0.05); padding:10px; border-radius:8px; border:1px solid rgba(217,119,6,0.1);">
                Menambahkan tagihan baru untuk: <strong id="addonCustName" style="color:var(--text-primary);">...</strong>
            </p>

            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-size:12px; color:var(--text-secondary); margin-bottom:5px; display:block;">Nama Item / Layanan</label>
                <input type="text" name="description" class="form-control" placeholder="Contoh: Router TP-Link, Setting, dsb" required style="padding:10px; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:14px; width:100%;">
            </div>
            
            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-size:12px; color:var(--text-secondary); margin-bottom:5px; display:block;">Nominal (Rp)</label>
                <input type="number" name="amount" class="form-control" placeholder="0" required style="padding:12px; border-radius:8px; background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); font-size:16px; font-weight:800; width:100%;">
            </div>
            
            <button type="submit" class="btn" style="width:100%; background:#d97706; color:white; padding:12px; border-radius:10px; font-weight:800; font-size:14px; border:none; box-shadow: 0 4px 15px rgba(217, 119, 6, 0.2);">TAMBAHKAN TAGIHAN</button>
        </form>
    </div>
</div>
