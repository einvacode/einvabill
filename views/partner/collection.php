<?php
// Penagihan Lapangan for Partner (Synchronized with Collector Dashboard)
$user_id = intval($_SESSION['user_id']);
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Base filter: customers created by this partner
$partner_filter = " AND c.created_by = $user_id AND c.tenant_id = $tenant_id ";

// Billing date filter (Siklus Tagih)
$filter_billing_date = $_GET['filter_billing_date'] ?? 'all'; // Default to ALL
$billing_where = "";
if ($filter_billing_date !== "" && $filter_billing_date !== "all") {
    $billing_where = " AND c.billing_date = " . intval($filter_billing_date);
}

// Month selector logic for comprehensive dashboard filtering
$selected_month = $_GET['month'] ?? date('Y-m');
$date_from = date('Y-m-01', strtotime($selected_month . "-01"));
$date_to = date('Y-m-t', strtotime($selected_month . "-01"));

// Override if manual dates are provided
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
// Total pelanggan saya
$total_customers = $db->query("
    SELECT COUNT(*) FROM customers c WHERE 1=1 $partner_filter $where_search_cust
")->fetchColumn();

// Total tagihan belum lunas (JUMLAH PELANGGAN & NOMINAL)
$unpaid_customers_count = $db->query("
    SELECT COUNT(DISTINCT customer_id) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $partner_filter $where_search_tugas
")->fetchColumn();

$unpaid_count = $db->query("
    SELECT COUNT(*) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $partner_filter $where_search_tugas
")->fetchColumn();

$unpaid_total = $db->query("
    SELECT COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
    $partner_filter $where_search_tugas
")->fetchColumn();

// Total tagihan lunas di periode terpilih
$paid_total_range = $db->query("
    SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Lunas' 
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $partner_filter
")->fetchColumn();

// Count of successful payments in range
$paid_count_range = $db->query("
    SELECT COUNT(*) FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id 
    WHERE p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $partner_filter
")->fetchColumn();

// Net Revenue logic (Collected - Bills paid to ISP in same month)
$partner_cid = $db->query("SELECT customer_id FROM users WHERE id = $user_id AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
$bills_to_isp_paid = $db->query("
    SELECT COALESCE(SUM(amount - discount), 0) FROM invoices 
    WHERE customer_id = $partner_cid 
    AND status = 'Lunas' 
    AND strftime('%Y-%m', due_date) = '" . date('Y-m', strtotime($date_from)) . "'
")->fetchColumn();
$net_revenue = $paid_total_range - $bills_to_isp_paid;

// Pendaftaran Baru
$new_customers_range = $db->query("
    SELECT COUNT(*) FROM customers c 
    WHERE c.registration_date BETWEEN '$date_from' AND '$date_to'
    $partner_filter
")->fetchColumn();

// Pengeluaran Mitra (Partner's own expenses)
$expenses_range = $db->query("
    SELECT COALESCE(SUM(amount), 0) FROM expenses 
    WHERE date BETWEEN '$date_from' AND '$date_to'
    AND created_by = $user_id AND tenant_id = $tenant_id
")->fetchColumn();

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

// Progres Penagihan
$total_potential = $unpaid_total + $paid_total_range;
$percent_paid = $total_potential > 0 ? round(($paid_total_range / $total_potential) * 100) : 0;

// === TAB DATA FETCHING ===
$coll_tab = $_GET['tab'] ?? 'tugas';

// 1. Tugas Penagihan (Arrears)
$query_unpaid = "
    SELECT 
        c.id as cust_id, c.name, c.address, c.contact, c.customer_code, c.package_name, c.monthly_fee, c.billing_date,
        COUNT(i.id) as num_arrears,
        SUM(i.amount - COALESCE(i.discount, 0)) as total_unpaid,
        MIN(i.due_date) as oldest_due_date
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' AND i.tenant_id = $tenant_id $partner_filter $billing_where $where_search_tugas
    GROUP BY c.id
    ORDER BY c.billing_date ASC, oldest_due_date ASC
";
$unpaid_invoices = $db->query($query_unpaid)->fetchAll();

// 2. Sudah Lunas (History)
$recent_paid = $db->query("
    SELECT i.*, c.name, c.contact, c.customer_code, c.package_name, p.payment_date, p.amount as paid_amount, u.name as admin_name,
    (SELECT COALESCE(SUM(amount - COALESCE(discount, 0)), 0) FROM invoices WHERE customer_id = i.customer_id AND status = 'Belum Lunas' AND tenant_id = i.tenant_id) as total_tunggakan
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN payments p ON p.invoice_id = i.id
    LEFT JOIN users u ON p.received_by = u.id
    WHERE i.status = 'Lunas' AND i.tenant_id = $tenant_id
    AND p.payment_date BETWEEN '$sql_date_from' AND '$sql_date_to'
    $partner_filter
    ORDER BY p.id DESC
")->fetchAll();

// 3. Daftar Pelanggan (Database)
$items_per_page = 50;
$p_cust = isset($_GET['p_cust']) ? max(1, intval($_GET['p_cust'])) : 1;
$off_cust = ($p_cust - 1) * $items_per_page;
$total_cust_pages = ceil($total_customers / $items_per_page);

$cust_query = "
    SELECT c.*, 
    (SELECT COUNT(*) FROM invoices WHERE customer_id = c.id AND status = 'Belum Lunas' AND tenant_id = c.tenant_id) as unpaid_count
    FROM customers c 
    WHERE 1=1 $partner_filter $where_search_cust 
    ORDER BY c.id DESC LIMIT $items_per_page OFFSET $off_cust
";
$area_customers = $db->query($cust_query)->fetchAll();

// Settings & Banners
$settings = $db->query("SELECT * FROM settings WHERE tenant_id = $tenant_id")->fetch() ?: $db->query("SELECT * FROM settings WHERE id = 1")->fetch();
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();
$banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'partner') ORDER BY created_at DESC")->fetchAll();

// WA Templates (Partner Specific -> Global Fallback)
$p_stg = $db->query("SELECT wa_template, wa_template_paid, brand_bank, brand_rekening FROM users WHERE id = $user_id")->fetch();
$wa_tpl_paid = (!empty($p_stg['wa_template_paid'])) ? $p_stg['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah LUNAS.");
$wa_tpl_unpaid = (!empty($p_stg['wa_template'])) ? $p_stg['wa_template'] : ($settings['wa_template'] ?? "Halo {nama}, tagihan {tagihan} jatuh tempo pada {jatuh_tempo}.");
$rekening_receipt = (!empty($p_stg['brand_bank'])) ? $p_stg['brand_bank'] . " " . $p_stg['brand_rekening'] : $settings['bank_account'];

// Success Modal Data
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
        $nota_link = $portal_link . "&action=print&id=" . intval($_GET['last_id'] ?? 0);

        $receipt_msg = parse_wa_template($wa_tpl_paid, [
            'name' => $success_data['name'],
            'id_cust' => ($success_data['customer_code'] ?: $success_data['id']),
            'package' => ($success_data['package_name'] ?: '-'),
            'period' => $months_paid . ' Bulan',
            'tagihan' => $success_data['monthly_fee'],
            'total_paid' => $total_paid,
            'tunggakan' => $tunggakan_val, // previous arrears
            'sisa_tunggakan' => $tunggakan_val, // remaining after this payment
            'payment_time' => date('d/m/Y H:i') . ' WIB',
            'admin_name' => $_SESSION['user_name'],
            'portal_link' => $portal_link,
            'nota_link' => $nota_link,
            'rekening' => $rekening_receipt,
            'payment_status' => $status_wa
        ]);
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?>

<?php if($success_data): ?>
<div class="ui-card glass-panel mb-5 p-4 sm:p-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h3 class="m-0 text-[15px] font-bold text-signal">Pelunasan berhasil</h3>
            <div class="mt-1 text-sm text-muted-foreground">Tagihan <strong class="text-foreground"><?= htmlspecialchars($success_data['name']) ?></strong> telah diperbarui.</div>
        </div>
        <button type="button" onclick="this.closest('.glass-panel').style.display='none'" class="grid h-8 w-8 shrink-0 place-items-center rounded-md border-0 bg-transparent text-muted-foreground hover:bg-muted cursor-pointer" aria-label="Tutup">&times;</button>
    </div>

    <!-- Action Buttons Group -->
    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
        <button type="button" onclick="sendWAGateway('<?= $wa_num_paid ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $success_data['wa_link'] ?>', this)" class="ui-btn ui-btn-wa w-full sm:w-auto">
            <i class="fab fa-whatsapp"></i> Kirim nota ke WhatsApp
        </button>
        <a href="index.php?page=invoice_print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" class="ui-btn ui-btn-outline w-full sm:w-auto">
            <i class="fas fa-print"></i> Cetak struk pembayaran
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Header Period Selector -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Penagihan mitra</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground"><?= date('l, d/m/Y') ?></p>
    </div>
    <form method="GET" class="flex gap-2">
        <input type="hidden" name="page" value="partner_collection">
        <input type="hidden" name="tab" value="<?= $coll_tab ?>">
        <button type="button" onclick="openFilterModal()" class="ui-btn ui-btn-outline"><i class="fas fa-filter"></i> Filter</button>
    </form>
</div>

<!-- Tab Navigation -->
<div class="mb-5 flex flex-wrap gap-1 rounded-md bg-muted p-1 w-fit">
    <a href="index.php?page=partner_collection&tab=tugas&month=<?= $selected_month ?>" class="inline-flex items-center gap-2 rounded-sm px-3 py-1.5 text-sm no-underline <?= $coll_tab === 'tugas' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>" <?= $coll_tab === 'tugas' ? 'aria-current="page"' : '' ?>>
        Tugas <span class="ui-badge ui-badge-muted"><?= count($unpaid_invoices) ?></span>
    </a>
    <a href="index.php?page=partner_collection&tab=lunas&month=<?= $selected_month ?>" class="inline-flex items-center gap-2 rounded-sm px-3 py-1.5 text-sm no-underline <?= $coll_tab === 'lunas' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>" <?= $coll_tab === 'lunas' ? 'aria-current="page"' : '' ?>>
        Lunas <span class="ui-badge ui-badge-muted"><?= count($recent_paid) ?></span>
    </a>
    <a href="index.php?page=partner_collection&tab=pelanggan&month=<?= $selected_month ?>" class="inline-flex items-center gap-2 rounded-sm px-3 py-1.5 text-sm no-underline <?= $coll_tab === 'pelanggan' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>" <?= $coll_tab === 'pelanggan' ? 'aria-current="page"' : '' ?>>
        Database
    </a>
</div>

<!-- Tab Contents -->
<?php if($coll_tab === 'tugas'): ?>
<!-- TAB: Tugas Penagihan -->
<section class="ui-card p-4 sm:p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 class="m-0 text-[15px] font-bold">Daftar tugas belum lunas</h3>
        <span class="text-xs text-muted-foreground">Status piutang</span>
    </div>

    <!-- Search in Tasks -->
    <form method="GET" class="mb-4 flex gap-2">
        <input type="hidden" name="page" value="partner_collection">
        <input type="hidden" name="tab" value="tugas">
        <input type="text" name="search_tugas" class="form-control min-w-0 flex-1" placeholder="Cari nama/alamat..." value="<?= htmlspecialchars($search_tugas) ?>">
        <button type="submit" class="ui-btn ui-btn-outline" title="Cari"><i class="fas fa-search"></i></button>
    </form>

    <!-- Summary for Tasks -->
    <div class="mb-4 grid grid-cols-2 gap-3">
        <div class="rounded-md border border-solid border-border p-3">
            <div class="text-xs font-medium text-muted-foreground">Total piutang</div>
            <div class="text-lg font-extrabold leading-tight tabular-nums text-danger">Rp <?= number_format($unpaid_total, 0, ',', '.') ?></div>
        </div>
        <div class="rounded-md border border-solid border-border p-3 text-right">
            <div class="text-xs font-medium text-muted-foreground">Volume</div>
            <div class="text-lg font-extrabold leading-tight tabular-nums"><?= $unpaid_count ?> <span class="text-xs font-medium text-muted-foreground">tagihan</span></div>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach($unpaid_invoices as $ui):
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ui['contact']));
            $is_overdue = strtotime($ui['oldest_due_date']) < time();
            $initial = strtoupper(substr($ui['name'], 0, 1));
        ?>
        <div class="ui-card flex flex-col overflow-hidden">
            <!-- Card Header -->
            <div class="flex items-start justify-between gap-3 px-4 pt-4">
                <div class="min-w-0">
                    <h4 class="m-0 truncate text-sm font-bold"><?= htmlspecialchars($ui['name']) ?></h4>
                    <div class="mt-0.5 truncate text-xs text-muted-foreground"><?= htmlspecialchars($ui['address'] ?: '-') ?></div>
                </div>
                <span class="ui-badge shrink-0 <?= $is_overdue ? 'ui-badge-danger' : 'ui-badge-accent' ?>"><?= $ui['num_arrears'] ?> bulan</span>
            </div>

            <!-- Financial Brief -->
            <div class="mt-3 flex items-center justify-between gap-3 border-t border-solid border-border px-4 py-3">
                <div>
                    <div class="text-xs text-muted-foreground">Tagihan akumulasi</div>
                    <div class="text-base font-extrabold tabular-nums text-danger">Rp <?= number_format($ui['total_unpaid'], 0, ',', '.') ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-muted-foreground">Jatuh tempo terlama</div>
                    <div class="text-sm font-semibold tabular-nums"><?= date('d/m/Y', strtotime($ui['oldest_due_date'])) ?></div>
                </div>
            </div>

            <!-- Card Actions -->
            <div class="mt-auto flex gap-2 border-t border-solid border-border p-3">
                <button type="button" class="ui-btn ui-btn-primary flex-1" onclick="handlePay(<?= $ui['cust_id'] ?>, <?= $ui['num_arrears'] ?>, '<?= addslashes($ui['name']) ?>', <?= $ui['monthly_fee'] ?>)">
                    <i class="fas fa-wallet"></i> Bayar sekarang
                </button>
                <?php
                    $mon_label = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $curr_month = $mon_label[intval(date('m')) - 1] . ' ' . date('Y');
                    $portal_link_rem = $base_url . "/index.php?page=customer_portal&code=" . ($ui['customer_code'] ?: $ui['cust_id']);
                    $rem_msg = parse_wa_template($wa_tpl_unpaid, [
                        'name' => $ui['name'],
                        'id_cust' => ($ui['customer_code'] ?: $ui['cust_id']),
                        'package' => $ui['package_name'],
                        'period' => $curr_month,
                        'tagihan' => $ui['monthly_fee'], // Current period fee
                        'tunggakan' => ($ui['total_unpaid'] - $ui['monthly_fee']), // Arrears
                        'total_payment' => $ui['total_unpaid'], // Total to be paid
                        'jatuh_tempo' => date('d/m/Y', strtotime($ui['oldest_due_date'])),
                        'rekening' => $rekening_receipt,
                        'portal_link' => $portal_link_rem
                    ]);
                    $rem_wa_link = "https://api.whatsapp.com/send?phone=$wa_num&text=" . urlencode($rem_msg);
                ?>
                <button type="button" onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($rem_msg)) ?>, '<?= $rem_wa_link ?>', this)" class="ui-btn ui-btn-wa" title="Kirim pengingat WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if(empty($unpaid_invoices)): ?>
        <div class="px-5 py-10 text-center text-sm text-muted-foreground">Semua tagihan sudah tertagih.</div>
    <?php endif; ?>
</section>

<?php elseif($coll_tab === 'lunas'): ?>
<!-- TAB: Sudah Lunas -->
<section class="ui-card p-4 sm:p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h3 class="m-0 text-[15px] font-bold">Riwayat lunas</h3>
        <!-- Date Filter -->
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="page" value="partner_collection">
            <input type="hidden" name="tab" value="lunas">
            <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
            <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
            <button type="submit" class="ui-btn ui-btn-primary" title="Terapkan"><i class="fas fa-filter"></i></button>
        </form>
    </div>

    <!-- Summary for Lunas -->
    <div class="mb-4 grid grid-cols-2 gap-3">
        <div class="rounded-md border border-solid border-border p-3">
            <div class="text-xs font-medium text-muted-foreground">Total terkumpul</div>
            <div class="text-lg font-extrabold leading-tight tabular-nums text-signal">Rp <?= number_format($paid_total_range, 0, ',', '.') ?></div>
        </div>
        <div class="rounded-md border border-solid border-border p-3 text-right">
            <div class="text-xs font-medium text-muted-foreground">Tagihan</div>
            <div class="text-lg font-extrabold leading-tight tabular-nums"><?= $paid_count_range ?> <span class="text-xs font-medium text-muted-foreground">tagihan</span></div>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach($recent_paid as $rp):
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $rp['contact']));
        ?>
        <div class="ui-card flex flex-col overflow-hidden">
            <div class="px-4 pt-4">
                <div class="truncate text-sm font-bold"><?= htmlspecialchars($rp['name']) ?></div>
                <div class="mt-0.5 text-xs text-muted-foreground tabular-nums"><?= date('d/m/Y', strtotime($rp['payment_date'])) ?> · <?= date('H:i', strtotime($rp['payment_date'])) ?></div>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3 border-t border-solid border-border px-4 py-3">
                <span class="text-xs text-muted-foreground">Nominal diterima</span>
                <span class="text-base font-extrabold tabular-nums text-signal">Rp <?= number_format($rp['paid_amount'], 0, ',', '.') ?></span>
            </div>

            <div class="mt-auto flex gap-2 border-t border-solid border-border p-3">
                <a href="index.php?page=invoice_print&id=<?= $rp['id'] ?>&format=thermal" target="_blank" class="ui-btn ui-btn-outline flex-1">
                    <i class="fas fa-print"></i> Cetak kwitansi
                </a>
                <?php
                    $hist_portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($rp['customer_code'] ?: $rp['customer_id']);
                    $hist_nota_link = $hist_portal_link . "&action=print&id=" . $rp['id'];
                    $hist_receipt_msg = parse_wa_template($wa_tpl_paid, [
                        'name' => $rp['name'],
                        'id_cust' => ($rp['customer_code'] ?: $rp['customer_id']),
                        'package' => ($rp['package_name'] ?: '-'),
                        'tagihan' => $rp['amount'],
                        'total_paid' => $rp['paid_amount'],
                        'tunggakan' => $rp['total_tunggakan'],
                        'sisa_tunggakan' => $rp['total_tunggakan'],
                        'payment_time' => date('d/m/Y H:i', strtotime($rp['payment_date'])) . ' WIB',
                        'admin_name' => ($rp['admin_name'] ?: 'System'),
                        'portal_link' => $hist_portal_link,
                        'nota_link' => $hist_nota_link,
                        'rekening' => $rekening_receipt,
                        'payment_status' => 'LUNAS'
                    ]);
                    $hist_wa_link = "https://api.whatsapp.com/send?phone=$wa_num&text=" . urlencode($hist_receipt_msg);
                ?>
                <button type="button" onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($hist_receipt_msg)) ?>, '<?= $hist_wa_link ?>', this)" class="ui-btn ui-btn-wa" title="Kirim nota ke WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php elseif($coll_tab === 'pelanggan'): ?>
<!-- TAB: Database Pelanggan -->
<section class="ui-card p-4 sm:p-5">
    <form method="GET" class="mb-4 flex gap-2">
        <input type="hidden" name="page" value="partner_collection">
        <input type="hidden" name="tab" value="pelanggan">
        <input type="text" name="search_cust" class="form-control min-w-0 flex-1" placeholder="Cari pelanggan..." value="<?= htmlspecialchars($search_cust) ?>">
        <button type="submit" class="ui-btn ui-btn-primary" title="Cari"><i class="fas fa-search"></i></button>
    </form>

    <!-- Summary for Customers -->
    <div class="mb-4 grid grid-cols-2 gap-3">
        <div class="rounded-md border border-solid border-border p-3">
            <div class="text-xs font-medium text-muted-foreground">Total pelanggan</div>
            <div class="text-lg font-extrabold leading-tight tabular-nums"><?= number_format($total_customers, 0, ',', '.') ?> <span class="text-xs font-medium text-muted-foreground">user</span></div>
        </div>
        <div class="rounded-md border border-solid border-border p-3 text-right">
            <div class="text-xs font-medium text-muted-foreground">ID mitra</div>
            <div class="text-lg font-extrabold leading-tight tabular-nums"><?= $partner_cid ?></div>
        </div>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach($area_customers as $ac):
            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $ac['contact']));
            $cust_id_display = $ac['customer_code'] ?: str_pad($ac['id'], 5, "0", STR_PAD_LEFT);
            $initial = strtoupper(substr($ac['name'], 0, 1));
            $is_unpaid = $ac['unpaid_count'] > 0;
        ?>
        <div class="ui-card flex cursor-pointer flex-col overflow-hidden transition-colors hover:border-primary/40" onclick="PartnerPage.showCustomerDetails(<?= $ac['id'] ?>)">
            <!-- Header: Profile & Status -->
            <div class="flex items-start justify-between gap-3 px-4 pt-4">
                <div class="min-w-0">
                    <h4 class="m-0 truncate text-sm font-bold"><?= htmlspecialchars($ac['name']) ?></h4>
                    <div class="mt-0.5 truncate text-xs text-muted-foreground"><?= $cust_id_display ?> · <?= htmlspecialchars($ac['address'] ?: '-') ?></div>
                </div>
                <span class="ui-badge shrink-0 <?= $is_unpaid ? 'ui-badge-danger' : 'ui-badge-signal' ?>"><?= $is_unpaid ? 'Ada tagihan' : 'Lunas' ?></span>
            </div>

            <!-- Context: Service Details -->
            <div class="mt-3 flex items-center justify-between gap-3 border-t border-solid border-border px-4 py-3">
                <div class="min-w-0">
                    <div class="text-xs text-muted-foreground">Paket & biaya</div>
                    <div class="truncate text-sm font-bold tabular-nums">Rp <?= number_format($ac['monthly_fee'], 0, ',', '.') ?> <span class="text-xs font-medium text-muted-foreground">/ <?= htmlspecialchars($ac['package_name']) ?></span></div>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-xs text-muted-foreground">Siklus</div>
                    <div class="text-sm font-semibold tabular-nums">Tgl <?= $ac['billing_date'] ?></div>
                </div>
            </div>

            <!-- Footer: Actions -->
            <div class="mt-auto flex flex-wrap gap-2 border-t border-solid border-border p-3" onclick="event.stopPropagation();">
                <?php if($is_unpaid): ?>
                    <button type="button" class="ui-btn ui-btn-primary flex-1" onclick="handlePay(<?= $ac['id'] ?>, <?= $ac['unpaid_count'] ?>, '<?= addslashes($ac['name']) ?>', <?= $ac['monthly_fee'] ?>)">
                        <i class="fas fa-wallet"></i> Bayar
                    </button>
                <?php endif; ?>

                <button type="button" class="ui-btn ui-btn-outline" onclick="PartnerPage.showCustomerDetails(<?= $ac['id'] ?>)" title="Detail pelanggan">
                    <i class="fas fa-eye"></i>
                </button>
                <button type="button" class="ui-btn ui-btn-outline" onclick='PartnerPage.editCustomer(<?= json_encode([
                    "id" => $ac["id"],
                    "name" => $ac["name"],
                    "address" => $ac["address"],
                    "contact" => $ac["contact"],
                    "package_name" => $ac["package_name"],
                    "monthly_fee" => $ac["monthly_fee"],
                    "billing_date" => $ac["billing_date"],
                    "area" => $ac["area"]
                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit pelanggan">
                    <i class="fas fa-pen"></i>
                </button>
                <button type="button" class="ui-btn ui-btn-outline text-danger" onclick="PartnerPage.deleteCustomer(<?= $ac['id'] ?>, '<?= addslashes($ac['name']) ?>')" title="Hapus pelanggan">
                    <i class="fas fa-trash"></i>
                </button>
                <a href="https://api.whatsapp.com/send?phone=<?= $wa_num ?>" target="_blank" class="ui-btn ui-btn-wa" title="Chat WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <a href="tel:<?= htmlspecialchars($ac['contact']) ?>" class="ui-btn ui-btn-outline" title="Telepon">
                    <i class="fas fa-phone-alt"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- End of Tabs -->

<!-- Modal Bulk Pay (Sync with Collector) -->
<div id="bulkPayModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4">
            <h3 class="m-0 text-lg font-bold">Pelunasan tunggakan</h3>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Bayar sebagian atau seluruh tunggakan untuk <strong class="text-foreground"><span id="bulkCustNameTitle"></span></strong>.</p>
        </div>

        <div class="mb-4">
            <label for="bulkMonthInput" class="mb-1 block text-xs font-medium text-muted-foreground">Berapa bulan dijadikan lunas?</label>
            <div class="flex items-center gap-3">
                <input type="number" id="bulkMonthInput" class="form-control w-28 text-center text-lg font-bold tabular-nums" value="1" min="1" oninput="updateBulkTotalDisplay()">
                <div class="text-sm text-muted-foreground">Dari <span id="bulkTotalMonthsTitle"></span> bulan</div>
            </div>
        </div>

        <div class="mb-6 rounded-md border border-solid border-border bg-muted/50 p-4">
            <div class="text-xs font-medium text-muted-foreground">Total penerimaan</div>
            <div class="text-2xl font-extrabold tabular-nums text-signal" id="bulkTotalAmtDisplay">Rp 0</div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('bulkPayModal').style.display='none'">Batal</button>
            <button type="button" class="ui-btn ui-btn-primary" onclick="confirmBulkPay()">Konfirmasi bayar</button>
        </div>
    </div>
</div>

<form id="payFormGlobal" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="customer_id" id="globalCustId">
    <input type="hidden" name="num_months" id="globalNumMonths">
</form>

<script>
let currentPayData = { custId: 0, monthlyFee: 0, maxMonths: 0 };

function handlePay(custId, maxMonths, custName, monthlyFee) {
    currentPayData = { custId, monthlyFee, maxMonths };
    document.getElementById('bulkCustNameTitle').innerText = custName;
    document.getElementById('bulkTotalMonthsTitle').innerText = maxMonths;
    const input = document.getElementById('bulkMonthInput');
    input.max = maxMonths;
    input.value = maxMonths;
    updateBulkTotalDisplay();
    document.getElementById('bulkPayModal').style.display = 'flex';
}

function updateBulkTotalDisplay() {
    const input = document.getElementById('bulkMonthInput');
    let val = parseInt(input.value) || 1;
    if(val > currentPayData.maxMonths) { val = currentPayData.maxMonths; input.value = val; }
    if(val < 1) { val = 1; input.value = val; }
    const total = val * currentPayData.monthlyFee;
    const formatted = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
    document.getElementById('bulkTotalAmtDisplay').innerText = formatted;
}

function confirmBulkPay() {
    const months = document.getElementById('bulkMonthInput').value;
    document.getElementById('globalCustId').value = currentPayData.custId;
    document.getElementById('globalNumMonths').value = months;
    document.getElementById('payFormGlobal').submit();
}
</script>

<!-- Modal Detail Pelanggan & Riwayat Pembayaran (Synced from Collector) -->
<div id="customerDetailModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden">
        <div class="flex items-start justify-between gap-4 border-b border-solid border-border px-5 py-4">
            <div class="min-w-0">
                <div id="detCustName" class="truncate text-lg font-bold">...</div>
                <div id="detCustId" class="text-xs text-muted-foreground tabular-nums">ID: ...</div>
            </div>
            <button type="button" onclick="document.getElementById('customerDetailModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <div class="min-h-0 overflow-y-auto p-5">
            <div class="mb-5 grid grid-cols-2 gap-3">
                <div class="rounded-md border border-solid border-border p-3">
                    <div class="text-xs text-muted-foreground">Paket</div>
                    <div id="detCustPkg" class="text-sm font-semibold">...</div>
                </div>
                <div class="rounded-md border border-solid border-border p-3">
                    <div class="text-xs text-muted-foreground">Siklus tagihan</div>
                    <div id="detCustBilling" class="text-sm font-semibold">Tanggal ...</div>
                </div>
                <div class="rounded-md border border-solid border-border p-3">
                    <div class="text-xs text-muted-foreground">Nomor telepon / WhatsApp</div>
                    <div id="detCustPhone" class="text-sm font-semibold tabular-nums">...</div>
                </div>
                <div class="rounded-md border border-solid border-border p-3">
                    <div class="text-xs text-muted-foreground">Tanggal registrasi</div>
                    <div id="detCustRegDate" class="text-sm font-semibold tabular-nums">...</div>
                </div>
            </div>

            <!-- Action buttons: Edit & Delete -->
            <div class="mb-5 flex gap-2" id="detActionButtons">
                <button type="button" onclick="PartnerPage.editCustomerFromDetail()" class="ui-btn ui-btn-sm ui-btn-outline flex-1">
                    <i class="fas fa-pen"></i> Edit pelanggan
                </button>
                <button type="button" onclick="PartnerPage.deleteCustomerFromDetail()" class="ui-btn ui-btn-sm ui-btn-outline text-danger flex-1">
                    <i class="fas fa-trash"></i> Hapus pelanggan
                </button>
            </div>

            <div class="mb-2 text-xs font-medium text-muted-foreground">Riwayat pembayaran</div>
            <div id="detHistoryList"></div>
        </div>
    </div>
</div>

<script>
if (!window.PartnerPage) window.PartnerPage = {};
(function(ns){
    // Store current customer data for detail modal actions
    let currentDetailCustomer = null;

    ns.showCustomerDetails = async function(id){
        const nameEl = document.getElementById('detCustName'); if(nameEl) nameEl.textContent = 'Memuat...';
        const histEl = document.getElementById('detHistoryList'); if(histEl) histEl.innerHTML = '<div style="text-align:center; padding:30px; opacity:0.5;"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>';
        const modal = document.getElementById('customerDetailModal'); if(modal) modal.style.display = 'flex';
        try {
            const response = await fetch(`app/customer_history.php?id=${id}`);
            const data = await response.json();
            currentDetailCustomer = data.customer;
            if(nameEl) nameEl.textContent = data.customer.name;
            const idEl = document.getElementById('detCustId'); if(idEl) idEl.textContent = 'ID: ' + (data.customer.customer_code || data.customer.id);
            const pkgEl = document.getElementById('detCustPkg'); if(pkgEl) pkgEl.textContent = data.customer.package_name;
            const billEl = document.getElementById('detCustBilling'); if(billEl) billEl.textContent = 'Tanggal ' + data.customer.billing_date;
            const phoneEl = document.getElementById('detCustPhone'); if(phoneEl) phoneEl.textContent = data.customer.contact;
            const regEl = document.getElementById('detCustRegDate'); if(regEl) regEl.textContent = data.customer.registration_date;

            let historyHtml = '';
            (data.history||[]).forEach(item => {
                const isPaid = item.status === 'Lunas';
                const color = isPaid ? 'var(--success)' : 'var(--danger)';
                historyHtml += `
                <div class="glass-panel" style="padding:12px; border-left:4px solid ${color}; background:rgba(255,255,255,0.02); margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:12px; font-weight:800; color:${color};">${item.status}</div>
                            <div style="font-size:10px; color:var(--text-secondary);">${item.due_date}</div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-weight:800;">Rp${new Intl.NumberFormat('id-ID').format(item.invoice_amount)}</span>
                            ${isPaid ? `<button onclick="PartnerPage.unpayInvoice(${item.invoice_id})" class="btn btn-sm" style="background:rgba(239,68,68,0.1); color:var(--danger); padding:3px 8px; border:1px solid rgba(239,68,68,0.2); border-radius:8px; font-size:10px; font-weight:700; cursor:pointer;" title="Batalkan Pembayaran"><i class="fas fa-undo"></i></button>` : ''}
                            ${!isPaid ? `<button onclick="PartnerPage.deleteInvoice(${item.invoice_id}, ${id})" class="btn btn-sm" style="background:rgba(239,68,68,0.1); color:var(--danger); padding:3px 8px; border:1px solid rgba(239,68,68,0.2); border-radius:8px; font-size:10px; font-weight:700; cursor:pointer;" title="Hapus Tagihan"><i class="fas fa-trash"></i></button>` : ''}
                        </div>
                    </div>
                </div>`;
            });
            if(!historyHtml) historyHtml = '<div style="text-align:center; padding:20px; opacity:0.5;">Belum ada riwayat tagihan.</div>';
            if(histEl) histEl.innerHTML = historyHtml;
        } catch (e) {
            if(histEl) histEl.innerHTML = 'Error loading history.';
        }
    };

    ns.editCustomer = function(data) {
        document.getElementById('edit_cust_id').value = data.id;
        document.getElementById('edit_name').value = data.name || '';
        document.getElementById('edit_address').value = data.address || '';
        document.getElementById('edit_contact').value = data.contact || '';
        document.getElementById('edit_package_name').value = data.package_name || '';
        document.getElementById('edit_monthly_fee').value = data.monthly_fee || 0;
        document.getElementById('edit_billing_date').value = data.billing_date || 1;
        document.getElementById('edit_area').value = data.area || '';
        document.getElementById('editCustomerModalColl').style.display = 'flex';
    };
    ns.syncEditPrice = function(select){ const fee = select.options[select.selectedIndex].getAttribute('data-fee'); if(fee){ document.getElementById('edit_monthly_fee').value = fee; } };

    ns.deleteCustomer = function(id, name) {
        if (confirm(`HAPUS PELANGGAN: ${name}?\n\nSeluruh data tagihan dan pembayaran pelanggan ini akan dihapus permanen.\n\nTindakan ini TIDAK BISA dibatalkan!`)) {
            window.location.href = `index.php?page=partner&action=delete_customer&id=${id}`;
        }
    };

    ns.editCustomerFromDetail = function() {
        if (!currentDetailCustomer) return;
        document.getElementById('customerDetailModal').style.display = 'none';
        ns.editCustomer(currentDetailCustomer);
    };

    ns.deleteCustomerFromDetail = function() {
        if (!currentDetailCustomer) return;
        ns.deleteCustomer(currentDetailCustomer.id, currentDetailCustomer.name);
    };

    ns.unpayInvoice = function(invoiceId) {
        if (confirm('Batalkan pembayaran tagihan ini?\n\nTagihan akan kembali menjadi BELUM LUNAS.')) {
            window.location.href = `index.php?page=admin_invoices&action=unpay&id=${invoiceId}`;
        }
    };

    ns.deleteInvoice = function(invoiceId, custId) {
        if (confirm('Hapus tagihan ini secara permanen?\n\nTindakan ini tidak bisa dibatalkan.')) {
            window.location.href = `index.php?page=admin_invoices&action=delete&id=${invoiceId}&ref=partner_collection`;
        }
    };
})(window.PartnerPage);
</script>

<!-- Filter Modal (Synced with Collector) -->
<div id="filterModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md overflow-hidden">
        <div class="flex items-start justify-between gap-4 border-b border-solid border-border px-5 py-4">
            <div>
                <h3 class="m-0 text-lg font-bold">Filter data</h3>
                <div class="mt-0.5 text-xs text-muted-foreground">Saring data penagihan</div>
            </div>
            <button type="button" onclick="document.getElementById('filterModal').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
        </div>

        <form method="GET" class="p-5">
            <input type="hidden" name="page" value="partner_collection">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($coll_tab) ?>">

            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Pilih bulan penagihan</span>
                <select name="month" class="form-control w-full">
                    <?php
                    for ($i = 0; $i < 6; $i++) {
                        $m = date('Y-m', strtotime("-$i month"));
                        $label = date('F Y', strtotime("-$i month"));
                        echo "<option value=\"$m\" " . ($selected_month === $m ? 'selected' : '') . ">$label</option>";
                    }
                    ?>
                </select>
            </label>

            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Siklus tagihan (tanggal)</span>
                <select name="filter_billing_date" class="form-control w-full">
                    <option value="all" <?= $filter_billing_date === 'all' ? 'selected' : '' ?>>Semua tanggal</option>
                    <?php for($i=1; $i<=31; $i++): ?>
                        <option value="<?= $i ?>" <?= $filter_billing_date == $i ? 'selected' : '' ?>>Tanggal <?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>

            <?php if($coll_tab === 'lunas'): ?>
            <div class="mb-4 grid grid-cols-2 gap-3">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Dari</span>
                    <input type="date" name="date_from" class="form-control w-full" value="<?= $date_from ?>">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Sampai</span>
                    <input type="date" name="date_to" class="form-control w-full" value="<?= $date_to ?>">
                </label>
            </div>
            <?php endif; ?>

            <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-4">
                <a href="index.php?page=partner_collection&tab=<?= $coll_tab ?>&month=<?= date('Y-m') ?>" class="ui-btn ui-btn-outline">Reset</a>
                <button type="submit" class="ui-btn ui-btn-primary">Terapkan filter</button>
            </div>
        </form>
    </div>
</div>

<script>
function openFilterModal() {
    document.getElementById('filterModal').style.display = 'flex';
}

</script>

<?php
// Fetch packages for the edit modal
$packages_coll = $db->query("SELECT * FROM packages WHERE created_by = $user_id OR created_by = 0 OR created_by IS NULL ORDER BY name ASC")->fetchAll();
?>

<!-- Modal Edit Pelanggan (Collection Page) -->
<div id="editCustomerModalColl" class="fixed inset-0 z-[1001] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4 border-b border-solid border-border px-5 py-4">
            <div>
                <h3 class="m-0 text-lg font-bold">Edit pelanggan</h3>
                <p class="m-0 mt-0.5 text-xs text-muted-foreground">Perbarui data pelanggan Anda</p>
            </div>
            <button type="button" onclick="document.getElementById('editCustomerModalColl').style.display='none'" class="ui-btn ui-btn-sm ui-btn-ghost" aria-label="Tutup">&times;</button>
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
                            <?php foreach($packages_coll as $pkg): ?>
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
                </div>
            </div>

            <!-- Footer: Actions -->
            <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-4">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('editCustomerModalColl').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> Simpan perubahan</button>
            </div>
        </form>
    </div>
</div>
