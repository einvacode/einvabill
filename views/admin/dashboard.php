<?php
/**
 * Dashboard Admin
 * Ringkasan operasional dan keuangan. Semua angka dihitung oleh
 * get_dashboard_stats() dan bisa diperbarui lewat ?ajax=stats.
 */
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// --- SCOPE OPTIMIZATION ---
// Pre-calculate scoped user IDs to avoid repeated subqueries in SQLite
$partner_user_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
$partner_list_str = !empty($partner_user_ids) ? implode(',', $partner_user_ids) : '0';

$scope_where = ($u_role === 'admin') ? " AND (created_by NOT IN ($partner_list_str) OR created_by = 0 OR created_by IS NULL) " : " AND (created_by = $u_id) ";
$c_scope = ($u_role === 'admin') ? " AND (c.created_by NOT IN ($partner_list_str) OR c.created_by = 0 OR c.created_by IS NULL) " : " AND (c.created_by = $u_id) ";

// Add Tenant Scoping to base filters
$scope_where = " AND tenant_id = $tenant_id " . $scope_where;
$c_scope = " AND i.tenant_id = $tenant_id " . $c_scope;

// --- CONSOLIDATED STATS ENGINE ---
function get_dashboard_stats($db, $scope_where, $c_scope) {
    // 1. Customer & Revenue Stats
    $cust_stats = $db->query("
        SELECT
            SUM(CASE WHEN type='customer' THEN 1 ELSE 0 END) as retail_count,
            SUM(CASE WHEN type='customer' THEN monthly_fee ELSE 0 END) as retail_est,
            SUM(CASE WHEN type='partner' THEN 1 ELSE 0 END) as mitra_count,
            SUM(CASE WHEN type='partner' THEN monthly_fee ELSE 0 END) as mitra_est,
            SUM(CASE WHEN strftime('%Y-%m', registration_date) = strftime('%Y-%m', 'now') THEN 1 ELSE 0 END) as baru_count
        FROM customers
        WHERE 1=1 $scope_where
    ")->fetch();

    // 2. Unpaid (Piutang) Stats
    $unpaid_stats = $db->query("
        SELECT
            SUM(CASE WHEN c.type='customer' THEN (i.amount - i.discount) ELSE 0 END) as piutang_r,
            COUNT(DISTINCT CASE WHEN c.type='customer' THEN i.customer_id ELSE NULL END) as piutang_r_c,
            SUM(CASE WHEN c.type='partner' THEN (i.amount - i.discount) ELSE 0 END) as piutang_m,
            COUNT(DISTINCT CASE WHEN c.type='partner' THEN i.customer_id ELSE NULL END) as piutang_m_c
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.status='Belum Lunas' $c_scope
    ")->fetch();

    // 3. Collection (Koleksi) & Cash Flow Stats
    $cash_stats = $db->query("
        SELECT
            SUM(CASE WHEN c.type='customer' THEN p.amount ELSE 0 END) as koleksi_r,
            SUM(CASE WHEN c.type='partner' THEN p.amount ELSE 0 END) as koleksi_m,
            SUM(CASE WHEN c.type='customer' AND strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now') THEN p.amount ELSE 0 END) as cash_r,
            SUM(CASE WHEN c.type='partner' AND strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now') THEN p.amount ELSE 0 END) as cash_m
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN customers c ON i.customer_id = c.id
        WHERE 1=1 $c_scope
    ")->fetch();

    // 4. External Invoices (created via external integrations or quick temp customers)
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $external_stats = $db->query("SELECT COUNT(*) as ext_count, COALESCE(SUM(i.amount - i.discount),0) as ext_total FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.tenant_id = $tenant_id AND (i.created_via = 'external' OR c.type IN ('note','temp')) $c_scope")->fetch();

    return array_merge($cust_stats, $unpaid_stats, $cash_stats, $external_stats ?? []);
}

function rp($n): string { return 'Rp ' . number_format((float) ($n ?: 0), 0, ',', '.'); }

// --- AJAX REFRESH ENDPOINT ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    $s = get_dashboard_stats($db, $scope_where, $c_scope);
    echo json_encode([
        'retail_count' => number_format($s['retail_count'] ?: 0, 0),
        'retail_est'   => rp($s['retail_est']),
        'mitra_count'  => number_format($s['mitra_count'] ?: 0, 0),
        'mitra_est'    => rp($s['mitra_est']),
        'baru_count'   => number_format($s['baru_count'] ?: 0, 0),
        'piutang_all'  => rp(($s['piutang_r'] ?: 0) + ($s['piutang_m'] ?: 0)),
        'piutang_c'    => number_format(($s['piutang_r_c'] ?: 0) + ($s['piutang_m_c'] ?: 0)),
        'koleksi_all'  => rp(($s['koleksi_r'] ?: 0) + ($s['koleksi_m'] ?: 0)),
        'cash_all'     => rp(($s['cash_r'] ?: 0) + ($s['cash_m'] ?: 0)),
        'ext_count'    => intval($s['ext_count'] ?? 0),
        'ext_total'    => rp($s['ext_total'] ?? 0),
        // Kept for older callers
        'piutang_r'    => rp($s['piutang_r']), 'piutang_r_c' => number_format($s['piutang_r_c'] ?: 0),
        'piutang_m'    => rp($s['piutang_m']), 'piutang_m_c' => number_format($s['piutang_m_c'] ?: 0),
        'koleksi_r'    => rp($s['koleksi_r']), 'koleksi_m'  => rp($s['koleksi_m']),
        'cash_r'       => rp($s['cash_r']),    'cash_m'     => rp($s['cash_m']),
    ]);
    exit;
}

// Initial Page Load Stats
$s = get_dashboard_stats($db, $scope_where, $c_scope);

$total_customers     = $s['retail_count'];
$est_revenue_cust    = $s['retail_est'] ?: 0;
$total_partners      = $s['mitra_count'];
$est_revenue_part    = $s['mitra_est'] ?: 0;
$new_customers_month = $s['baru_count'];
$count_unpaid_all    = ($s['piutang_r_c'] ?: 0) + ($s['piutang_m_c'] ?: 0);
$total_unpaid_all    = ($s['piutang_r'] ?: 0) + ($s['piutang_m'] ?: 0);
$total_received_all  = ($s['koleksi_r'] ?: 0) + ($s['koleksi_m'] ?: 0);
$cash_monthly_all    = ($s['cash_r'] ?: 0) + ($s['cash_m'] ?: 0);

$tenant_id = $_SESSION['tenant_id'] ?? 1;
$settings = $db->query("SELECT company_name, wa_template_paid, site_url FROM settings WHERE tenant_id = $tenant_id")->fetch();
if (!$settings) $settings = ['company_name' => 'ISP', 'wa_template_paid' => '', 'site_url' => ''];
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();

// Success panel after a quick payment
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid AND tenant_id = $tenant_id")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact'] ?? ''));
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas' AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        $receipt_msg = str_replace(
            ['{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', '{tunggakan}', '{waktu_bayar}', '{admin}', '{perusahaan}', '{link_tagihan}'],
            [$success_data['name'], ($success_data['customer_code'] ?: $success_data['id']), 'Rp ' . number_format($success_data['monthly_fee'], 0, ',', '.'), ($success_data['package_name'] ?: '-'), $months_paid, $tunggakan_display, date('d/m/Y H:i') . ' WIB', $_SESSION['user_name'], $settings['company_name'], $portal_link],
            $settings['wa_template_paid'] ?: "Halo {nama}, pembayaran {tagihan} LUNAS. Cek nota: {link_tagihan}"
        );
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}

$late_summary = $db->query("
    SELECT c.id as cust_id, c.name, c.contact, COUNT(i.id) as months_owed, SUM(i.amount - i.discount) as total_debt
    FROM invoices i JOIN customers c ON i.customer_id = c.id
    WHERE i.status = 'Belum Lunas' $c_scope
    GROUP BY c.id ORDER BY months_owed DESC, total_debt DESC LIMIT 5
")->fetchAll();

$latest = $db->query("
    SELECT p.*, c.name as customer_name, u.name as receiver_name
    FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u ON p.received_by = u.id
    WHERE 1=1 $c_scope ORDER BY p.payment_date DESC LIMIT 10
")->fetchAll();

$cash_tile = null;
if ($u_role === 'admin' && function_exists('cash_accounts_with_balances')) {
    try {
        if (function_exists('recurring_expenses_run')) recurring_expenses_run($db, (int)$tenant_id, (int)$u_id);
        cash_accounts_ensure($db, (int)$tenant_id);
        $cash_rows = array_filter(cash_accounts_with_balances($db, (int)$tenant_id), fn($a) => $a['is_active']);
        $cash_total = array_sum(array_map(fn($a) => $a['balance'], $cash_rows));
        $cash_held = array_sum(array_map(fn($a) => $a['balance'], array_filter($cash_rows, fn($a) => !empty($a['owner_user_id']) && ($a['owner_role'] ?? '') !== 'admin')));
        $cash_tile = ['id' => 'stat-cash-balance', 'label' => 'Saldo kas & bank', 'value' => rp($cash_total), 'sub_id' => '', 'sub' => $cash_held > 0 ? rp($cash_held) . ' masih di petugas' : 'Semua akun aktif', 'href' => 'index.php?page=admin_cash', 'icon' => 'fa-vault'];
    } catch (Exception $e) { $cash_tile = null; }
}
$stat_cards = [
    ['id' => 'stat-retail-count', 'label' => 'Total pelanggan', 'value' => number_format($total_customers, 0), 'sub_id' => 'stat-retail-est', 'sub' => 'Estimasi ' . rp($est_revenue_cust) . ' / bulan', 'href' => 'index.php?page=admin_customers&filter_type=customer', 'icon' => 'fa-users'],
    ['id' => 'stat-mitra-count', 'label' => 'Total mitra', 'value' => number_format($total_partners, 0), 'sub_id' => 'stat-mitra-est', 'sub' => 'Estimasi ' . rp($est_revenue_part) . ' / bulan', 'href' => 'index.php?page=admin_customers&filter_type=partner', 'icon' => 'fa-handshake'],
    ['id' => 'stat-baru-count', 'label' => 'Pelanggan baru bulan ini', 'value' => number_format($new_customers_month, 0), 'sub_id' => '', 'sub' => 'Registrasi bulan berjalan', 'href' => 'index.php?page=admin_new_customers', 'icon' => 'fa-star'],
    ['id' => 'stat-piutang-all', 'label' => 'Total piutang', 'value' => rp($total_unpaid_all), 'sub_id' => 'stat-piutang-c', 'sub' => number_format($count_unpaid_all, 0) . ' pelanggan menunggak', 'href' => 'index.php?page=admin_receivables', 'icon' => 'fa-user-clock', 'tone' => 'danger'],
    ['id' => 'stat-koleksi-all', 'label' => 'Total penerimaan', 'value' => rp($total_received_all), 'sub_id' => '', 'sub' => 'Akumulasi pembayaran masuk', 'href' => 'index.php?page=admin_reports', 'icon' => 'fa-coins'],
    ['id' => 'stat-cash-all', 'label' => 'Kas masuk bulan ini', 'value' => rp($cash_monthly_all), 'sub_id' => '', 'sub' => 'Arus kas periode berjalan', 'href' => 'index.php?page=admin_reports', 'icon' => 'fa-arrow-trend-up', 'tone' => 'signal'],
    ['id' => 'stat-inv-external', 'label' => 'Invoice eksternal', 'value' => rp($s['ext_total'] ?? 0), 'sub_id' => 'stat-ext-count', 'sub' => number_format($s['ext_count'] ?? 0) . ' invoice', 'href' => 'index.php?page=admin_invoices', 'icon' => 'fa-file-export'],
];
if ($cash_tile) $stat_cards[] = $cash_tile;
?>

<?php if ($success_data): ?>
<div class="ui-card mb-5 p-4 sm:p-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-[15px] font-bold text-signal"><i class="fas fa-check-circle"></i> Pembayaran berhasil</div>
            <p class="mt-1 text-sm text-muted-foreground">Tagihan <strong class="text-foreground"><?= htmlspecialchars($success_data['name']) ?></strong> diperbarui.</p>
        </div>
        <button type="button" class="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted bg-transparent border-0" onclick="this.closest('.ui-card').remove()" aria-label="Tutup"><i class="fas fa-times"></i></button>
    </div>
    <div class="mt-4">
        <button type="button" onclick="sendWAGateway('<?= $wa_num_paid ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $success_data['wa_link'] ?>', this)" class="ui-btn ui-btn-wa"><i class="fab fa-whatsapp"></i> Kirim notifikasi WhatsApp</button>
    </div>
</div>
<?php endif; ?>

<?php if (LICENSE_ST === 'TRIAL'): ?>
<div class="ui-card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3 text-sm font-semibold text-accent-ink"><i class="fas fa-clock text-accent"></i> <?= LICENSE_MSG ?></div>
    <a href="index.php?page=admin_license" class="ui-btn ui-btn-sm ui-btn-primary">Aktivasi sekarang</a>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Ringkasan perusahaan</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Operasional, piutang, dan arus kas <?= htmlspecialchars($settings['company_name']) ?> hari ini.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if (LICENSE_ST === 'UNLIMITED'): ?>
            <span class="ui-badge ui-badge-accent"><i class="fas fa-crown"></i> Lisensi unlimited</span>
        <?php endif; ?>
        <span class="wa-status-indicator cursor-pointer sm:hidden" onclick="location.href='index.php?page=admin_wa_gateway'"></span>
        <span id="statsUpdated" class="ui-badge ui-badge-muted" title="Angka diperbarui otomatis tiap 45 detik"><i class="fas fa-rotate"></i> Live</span>
    </div>
</div>

<!-- Quick links -->
<div class="mb-5 flex flex-wrap gap-2">
    <a href="index.php?page=admin_customers" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-users"></i> Data pelanggan</a>
    <a href="index.php?page=admin_invoices" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-file-invoice"></i> Data tagihan</a>
    <a href="index.php?page=admin_reports" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-chart-line"></i> Laporan</a>
    <a href="index.php?page=admin_expenses" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-receipt"></i> Pengeluaran</a>
    <a href="index.php?page=admin_create_invoice" class="ui-btn ui-btn-sm ui-btn-primary"><i class="fas fa-file-invoice"></i> Invoice eksternal</a>
</div>

<!-- Stats -->
<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
    <?php foreach ($stat_cards as $c):
        $tone = $c['tone'] ?? '';
        $valueCls = $tone === 'danger' ? 'text-danger' : ($tone === 'signal' ? 'text-signal' : 'text-foreground');
    ?>
    <a href="<?= htmlspecialchars($c['href']) ?>" class="ui-card group flex flex-col gap-1 p-4 no-underline text-foreground hover:border-primary/40 transition-colors">
        <div class="flex items-start justify-between gap-2">
            <span class="min-w-0 text-xs font-medium text-muted-foreground"><?= $c['label'] ?></span>
            <i class="fas <?= $c['icon'] ?> mt-0.5 shrink-0 text-[13px] text-muted-foreground/70"></i>
        </div>
        <div id="<?= $c['id'] ?>" class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl <?= $valueCls ?>"><?= $c['value'] ?></div>
        <div <?= $c['sub_id'] ? 'id="' . $c['sub_id'] . '"' : '' ?> class="text-xs text-muted-foreground"><?= $c['sub'] ?></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Reminder / broadcast widget (existing component) -->
<?php require __DIR__ . '/../components/wa_broadcast.php'; ?>

<div class="mt-6 grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">

    <!-- Arrears per customer -->
    <section class="ui-card overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
            <div>
                <h3 class="m-0 text-[15px] font-bold">Tunggakan pelanggan</h3>
                <p class="m-0 text-xs text-muted-foreground">Lima pelanggan dengan tunggakan terbanyak</p>
            </div>
            <a href="index.php?page=admin_invoices&filter_status=belum" class="ui-btn ui-btn-sm ui-btn-outline">Lihat semua</a>
        </div>
        <?php if (empty($late_summary)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Tidak ada tunggakan saat ini.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold sm:px-5">Pelanggan</th>
                        <th class="px-3 py-2.5 font-semibold">Bulan</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Total</th>
                        <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($late_summary as $ls):
                    $phone = preg_replace('/[^0-9]/', '', $ls['contact'] ?? '');
                    $remind = 'Halo ' . $ls['name'] . ', mohon segera melunasi tunggakan sebesar Rp ' . number_format($ls['total_debt'], 0, ',', '.') . '. Terima kasih.';
                ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-3 sm:px-5">
                            <div class="font-semibold"><?= htmlspecialchars($ls['name']) ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($ls['contact']) ?></div>
                        </td>
                        <td class="px-3 py-3"><span class="ui-badge ui-badge-danger"><?= (int) $ls['months_owed'] ?> bln</span></td>
                        <td class="px-3 py-3 text-right font-bold tabular-nums text-danger whitespace-nowrap"><?= rp($ls['total_debt']) ?></td>
                        <td class="px-4 py-3 sm:px-5">
                            <div class="flex justify-end gap-1.5">
                                <button type="button" onclick="quickPay(<?= (int) $ls['cust_id'] ?>, <?= htmlspecialchars(json_encode($ls['name']), ENT_QUOTES) ?>, <?= (int) $ls['months_owed'] ?>, <?= (float) $ls['total_debt'] ?>)" class="ui-btn ui-btn-sm ui-btn-primary" title="Tandai lunas"><i class="fas fa-money-bill-wave"></i><span class="hidden sm:inline">Bayar</span></button>
                                <button type="button" onclick="sendWAGateway('<?= $phone ?>', <?= htmlspecialchars(json_encode($remind), ENT_QUOTES) ?>, 'https://wa.me/<?= $phone ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim pengingat WhatsApp"><i class="fab fa-whatsapp"></i><span class="hidden sm:inline">Tagih</span></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- Latest payments -->
    <section class="ui-card overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
            <div>
                <h3 class="m-0 text-[15px] font-bold">Pembayaran terbaru</h3>
                <p class="m-0 text-xs text-muted-foreground">Sepuluh transaksi terakhir yang tercatat</p>
            </div>
            <a href="index.php?page=admin_reports" class="ui-btn ui-btn-sm ui-btn-outline">Laporan</a>
        </div>
        <?php if (empty($latest)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada pembayaran yang tercatat.</div>
        <?php else: ?>
        <ul class="m-0 list-none p-0 max-h-[480px] overflow-y-auto">
            <?php foreach ($latest as $l): ?>
            <li class="flex items-center gap-3 border-t border-solid border-border px-4 py-3 first:border-t-0 sm:px-5">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-signal-soft text-signal"><i class="fas fa-check text-xs"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold"><?= htmlspecialchars($l['customer_name']) ?></div>
                    <div class="truncate text-xs text-muted-foreground">Diterima <?= htmlspecialchars($l['receiver_name'] ?? 'Sistem') ?> · <?= date('d M, H:i', strtotime($l['payment_date'])) ?></div>
                </div>
                <div class="shrink-0 text-right text-sm font-bold tabular-nums text-signal">+<?= rp($l['amount']) ?></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>

<!-- Hidden Form for Quick Pay -->
<form id="quickPayForm" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="customer_id" id="qp_cust_id">
    <input type="hidden" name="num_months" id="qp_num_months">
    <input type="hidden" name="account_id" id="qp_account_id">
</form>

<script>
function quickPay(custId, name, months, total) {
    const formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
    const go = function (accountId) {
        document.getElementById('qp_cust_id').value = custId;
        document.getElementById('qp_num_months').value = months;
        document.getElementById('qp_account_id').value = accountId || '';
        document.getElementById('quickPayForm').submit();
    };
    // Admin chooses where the money goes (kas kantor / bank); other roles keep the plain confirm.
    if (window.chooseCashAccount) { window.chooseCashAccount(`${name}: ${formattedTotal} (${months} bulan)`, go); return; }
    if (confirm(`Proses pembayaran cepat untuk ${name}?\n\nTotal: ${formattedTotal} (${months} bulan)\n\nTindakan ini akan menandai tagihan tertua sebagai LUNAS.`)) go('');
}

// Refresh the summary numbers without reloading the page
async function updateDashboardStats() {
    try {
        const response = await fetch('index.php?page=admin_dashboard&ajax=stats');
        if (!response.ok) return;
        const data = await response.json();
        const updateEl = (id, val) => {
            const el = document.getElementById(id);
            if (!el || el.innerText === val) return;
            el.innerText = val;
            el.style.transition = 'background-color .6s ease';
            el.style.backgroundColor = '#FFF3D6';
            setTimeout(() => { el.style.backgroundColor = ''; }, 900);
        };
        updateEl('stat-retail-count', data.retail_count);
        updateEl('stat-retail-est', 'Estimasi ' + data.retail_est + ' / bulan');
        updateEl('stat-mitra-count', data.mitra_count);
        updateEl('stat-mitra-est', 'Estimasi ' + data.mitra_est + ' / bulan');
        updateEl('stat-baru-count', data.baru_count);
        updateEl('stat-piutang-all', data.piutang_all);
        updateEl('stat-piutang-c', data.piutang_c + ' pelanggan menunggak');
        updateEl('stat-koleksi-all', data.koleksi_all);
        updateEl('stat-cash-all', data.cash_all);
        updateEl('stat-inv-external', data.ext_total);
        updateEl('stat-ext-count', data.ext_count + ' invoice');
        const badge = document.getElementById('statsUpdated');
        if (badge) badge.innerHTML = '<i class="fas fa-rotate"></i> Diperbarui ' + new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    } catch (error) {
        console.error('Failed to update stats:', error);
    }
}
setInterval(updateDashboardStats, 45000);
</script>
