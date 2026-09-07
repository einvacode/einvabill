<?php
// Dashboard Mitra: per-partner customer count and billing (paid / unpaid) for admin.
if (app_scope_role() !== 'admin') {
    echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-xl font-bold'>Akses ditolak</h2></div>"; return;
}
$tenant_id = intval($_SESSION['tenant_id'] ?? 1);

// Period: a month (YYYY-MM, matched against invoice due dates) or 'all'.
$period = $_GET['period'] ?? date('Y-m');
if ($period !== 'all' && !preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
$period_sql = $period === 'all' ? '' : " AND strftime('%Y-%m', i.due_date) = :period";
$bulan_id = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$month_label = function (string $ym) use ($bulan_id): string { return $bulan_id[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4); };
$period_label = $period === 'all' ? 'semua periode' : 'jatuh tempo ' . $month_label($period);

// A "mitra" is a POP customer record (type 'partner'), the same thing the main
// dashboard counts. Its login account (users.role = 'partner') is optional:
// only accounts can register their own customers in the system. Accounts not
// linked to any POP record are listed too so nothing is hidden.
$partners = $db->prepare("SELECT c.id AS customer_id, c.name AS pop_name, c.customer_code AS pop_code, c.address AS pop_address, u.id AS id, u.name AS name
    FROM customers c LEFT JOIN users u ON u.customer_id = c.id AND u.role = 'partner' AND u.tenant_id = :t
    WHERE c.type = 'partner' AND c.tenant_id = :t
    UNION ALL
    SELECT NULL, NULL, NULL, NULL, u.id, u.name FROM users u
    WHERE u.role = 'partner' AND u.tenant_id = :t
      AND (u.customer_id IS NULL OR u.customer_id NOT IN (SELECT id FROM customers WHERE type = 'partner' AND tenant_id = :t))
    ORDER BY 2, 6");
$partners->execute([':t' => $tenant_id]);
$partners = $partners->fetchAll(PDO::FETCH_ASSOC);

// Invoices of the partner's own customers (what the partner collects).
$q_cust_inv = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN i.status = 'Lunas' THEN i.amount - COALESCE(i.discount,0) ELSE 0 END), 0) AS paid_amt,
        SUM(CASE WHEN i.status = 'Lunas' THEN 1 ELSE 0 END) AS paid_n,
        COALESCE(SUM(CASE WHEN i.status <> 'Lunas' THEN i.amount - COALESCE(i.discount,0) ELSE 0 END), 0) AS unpaid_amt,
        SUM(CASE WHEN i.status <> 'Lunas' THEN 1 ELSE 0 END) AS unpaid_n,
        COUNT(DISTINCT CASE WHEN i.status <> 'Lunas' THEN i.customer_id END) AS unpaid_cust
    FROM invoices i JOIN customers c ON c.id = i.customer_id
    WHERE c.created_by = :uid AND c.tenant_id = :tenant $period_sql");

// Collective invoices billed to the partner itself (what the partner owes the company).
$q_pop_inv = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN i.status = 'Lunas' THEN i.amount - COALESCE(i.discount,0) ELSE 0 END), 0) AS paid_amt,
        COALESCE(SUM(CASE WHEN i.status <> 'Lunas' THEN i.amount - COALESCE(i.discount,0) ELSE 0 END), 0) AS unpaid_amt,
        SUM(CASE WHEN i.status <> 'Lunas' THEN 1 ELSE 0 END) AS unpaid_n
    FROM invoices i WHERE i.customer_id = :cid AND i.tenant_id = :tenant $period_sql");

$q_cust = $db->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN strftime('%Y-%m', registration_date) = :month THEN 1 ELSE 0 END) AS new_n,
        COALESCE(SUM(monthly_fee), 0) AS mrr
    FROM customers WHERE created_by = :uid AND tenant_id = :tenant AND type = 'customer'");

$rows = [];
$tot = ['partners' => count(array_filter($partners, fn($p) => !empty($p['customer_id']))), 'orphans' => count(array_filter($partners, fn($p) => empty($p['customer_id']))), 'accounts' => count(array_filter($partners, fn($p) => !empty($p['id']) && !empty($p['customer_id']))), 'customers' => 0, 'paid' => 0, 'unpaid' => 0, 'pop_paid' => 0, 'pop_unpaid' => 0];
foreach ($partners as $p) {
    $cust = ['total' => 0, 'new_n' => 0, 'mrr' => 0];
    $ci = ['paid_amt' => 0, 'paid_n' => 0, 'unpaid_amt' => 0, 'unpaid_n' => 0, 'unpaid_cust' => 0];
    if (!empty($p['id'])) {
        $q_cust->execute([':uid' => $p['id'], ':tenant' => $tenant_id, ':month' => $period === 'all' ? date('Y-m') : $period]);
        $cust = $q_cust->fetch(PDO::FETCH_ASSOC) ?: $cust;

        $params = [':uid' => $p['id'], ':tenant' => $tenant_id];
        if ($period !== 'all') $params[':period'] = $period;
        $q_cust_inv->execute($params);
        $ci = $q_cust_inv->fetch(PDO::FETCH_ASSOC) ?: $ci;
    }

    $pi = ['paid_amt' => 0, 'unpaid_amt' => 0, 'unpaid_n' => 0];
    if (!empty($p['customer_id'])) {
        $params = [':cid' => $p['customer_id'], ':tenant' => $tenant_id];
        if ($period !== 'all') $params[':period'] = $period;
        $q_pop_inv->execute($params);
        $pi = $q_pop_inv->fetch(PDO::FETCH_ASSOC) ?: $pi;
    }

    $billed = $ci['paid_amt'] + $ci['unpaid_amt'];
    $rows[] = array_merge($p, [
        'cust_total' => (int)$cust['total'], 'cust_new' => (int)$cust['new_n'], 'mrr' => (float)$cust['mrr'],
        'paid_amt' => (float)$ci['paid_amt'], 'paid_n' => (int)$ci['paid_n'],
        'unpaid_amt' => (float)$ci['unpaid_amt'], 'unpaid_n' => (int)$ci['unpaid_n'], 'unpaid_cust' => (int)$ci['unpaid_cust'],
        'rate' => $billed > 0 ? round($ci['paid_amt'] / $billed * 100) : null,
        'pop_paid' => (float)$pi['paid_amt'], 'pop_unpaid' => (float)$pi['unpaid_amt'], 'pop_unpaid_n' => (int)$pi['unpaid_n'],
    ]);
    $tot['customers'] += (int)$cust['total'];
    $tot['paid'] += (float)$ci['paid_amt'];
    $tot['unpaid'] += (float)$ci['unpaid_amt'];
    $tot['pop_paid'] += (float)$pi['paid_amt'];
    $tot['pop_unpaid'] += (float)$pi['unpaid_amt'];
}
usort($rows, fn($a, $b) => [$b['unpaid_amt'], $b['cust_total']] <=> [$a['unpaid_amt'], $a['cust_total']]);

$tot_billed = $tot['paid'] + $tot['unpaid'];
$tot_rate = $tot_billed > 0 ? round($tot['paid'] / $tot_billed * 100) : null;
if (!function_exists('rp')) { function rp($n): string { return 'Rp ' . number_format((float)($n ?: 0), 0, ',', '.'); } }

// Month options: last 12 months plus any month that has partner invoices.
$months = [];
for ($i = 0; $i < 12; $i++) $months[] = date('Y-m', strtotime("first day of -$i month"));
$months = array_values(array_unique($months));

if (($_GET['action'] ?? '') === 'detail') { require __DIR__ . '/partner_detail.php'; return; }
?>

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Dashboard mitra</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Jumlah pelanggan dan tagihan setiap mitra, <?= htmlspecialchars($period_label) ?>.</p>
        </div>
        <form method="get" class="flex items-end gap-2">
            <input type="hidden" name="page" value="admin_partner_dashboard">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Periode</span>
                <select name="period" class="form-control h-10 w-[200px]" onchange="this.form.submit()">
                    <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Semua periode</option>
                    <?php foreach ($months as $m): ?>
                        <option value="<?= $m ?>" <?= $period === $m ? 'selected' : '' ?>><?= $month_label($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Total mitra</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= number_format($tot['partners']) ?></div>
            <div class="text-xs text-muted-foreground"><?= number_format($tot['accounts']) ?> punya akun &middot; <?= number_format($tot['customers']) ?> pelanggan tercatat<?= $tot['orphans'] > 0 ? ' &middot; ' . number_format($tot['orphans']) . ' akun belum terhubung ke POP' : '' ?></div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Sudah bayar (pelanggan mitra)</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal"><?= rp($tot['paid']) ?></div>
            <div class="text-xs text-muted-foreground"><?= $tot_rate === null ? 'Belum ada tagihan' : 'Tingkat tagih ' . $tot_rate . '%' ?></div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Belum bayar (pelanggan mitra)</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-danger"><?= rp($tot['unpaid']) ?></div>
            <div class="text-xs text-muted-foreground">Piutang yang masih ditagih mitra</div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Tagihan kolektif ke perusahaan</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= rp($tot['pop_paid'] + $tot['pop_unpaid']) ?></div>
            <div class="text-xs text-muted-foreground">Lunas <?= rp($tot['pop_paid']) ?> &middot; belum <?= rp($tot['pop_unpaid']) ?></div>
        </div>
    </div>

    <section class="ui-card overflow-hidden">
        <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
            <h3 class="m-0 text-[15px] font-bold">Rincian per mitra</h3>
            <p class="m-0 text-xs text-muted-foreground">Diurutkan dari tunggakan terbesar. Tagihan pelanggan adalah tagihan yang dibuat mitra untuk pelanggannya; tagihan kolektif adalah tagihan perusahaan ke mitra.</p>
        </div>
        <?php if (empty($rows)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data mitra. Tambahkan lewat menu Kemitraan (B2B).</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="min-w-[220px] px-4 py-2.5 font-semibold sm:px-5">Mitra</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Pelanggan</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Sudah bayar</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Belum bayar</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Tingkat tagih</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Kolektif ke perusahaan</th>
                        <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-3 align-top sm:px-5">
                            <div class="font-semibold"><?= htmlspecialchars($r['pop_name'] ?: $r['name']) ?></div>
                            <div class="text-xs text-muted-foreground"><?= !empty($r['pop_code']) ? htmlspecialchars($r['pop_code']) . ' &middot; ' : '' ?><?= !empty($r['id']) ? ($r['pop_name'] ? 'akun ' . htmlspecialchars($r['name']) : 'Akun belum terhubung ke data POP') : 'Belum punya akun mitra' ?></div>
                        </td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap">
                            <div class="font-semibold"><?= number_format($r['cust_total']) ?></div>
                            <div class="text-xs text-muted-foreground"><?= $r['cust_new'] > 0 ? '+' . $r['cust_new'] . ' baru' : ($r['cust_total'] > 0 ? 'Est. ' . rp($r['mrr']) . '/bln' : 'Belum ada pelanggan') ?></div>
                        </td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap">
                            <div class="font-semibold text-signal"><?= rp($r['paid_amt']) ?></div>
                            <div class="text-xs text-muted-foreground"><?= number_format($r['paid_n']) ?> tagihan</div>
                        </td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap">
                            <div class="font-semibold <?= $r['unpaid_amt'] > 0 ? 'text-danger' : '' ?>"><?= rp($r['unpaid_amt']) ?></div>
                            <div class="text-xs text-muted-foreground"><?= $r['unpaid_n'] > 0 ? number_format($r['unpaid_n']) . ' tagihan, ' . number_format($r['unpaid_cust']) . ' pelanggan' : 'Tidak ada tunggakan' ?></div>
                        </td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap">
                            <?php if ($r['rate'] === null): ?>
                                <span class="text-muted-foreground">-</span>
                            <?php else: ?>
                                <span class="ui-badge <?= $r['rate'] >= 90 ? 'ui-badge-signal' : ($r['rate'] >= 60 ? 'ui-badge-accent' : 'ui-badge-danger') ?>"><?= $r['rate'] ?>%</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap">
                            <?php if (empty($r['customer_id']) || ($r['pop_paid'] + $r['pop_unpaid']) <= 0): ?>
                                <span class="text-xs text-muted-foreground">Belum ada tagihan</span>
                            <?php else: ?>
                                <div class="font-semibold"><?= rp($r['pop_paid'] + $r['pop_unpaid']) ?></div>
                                <div class="text-xs <?= $r['pop_unpaid'] > 0 ? 'text-danger' : 'text-muted-foreground' ?>"><?= $r['pop_unpaid'] > 0 ? 'Belum lunas ' . rp($r['pop_unpaid']) : 'Lunas' ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 align-top sm:px-5">
                            <div class="flex justify-end gap-1.5">
                                <a class="ui-btn ui-btn-sm ui-btn-primary" href="index.php?page=admin_partner_dashboard&action=detail&<?= !empty($r['id']) ? 'id=' . intval($r['id']) : 'cid=' . intval($r['customer_id']) ?>&period=<?= urlencode($period) ?>" title="Detail pelanggan mitra"><i class="fas fa-eye"></i><span class="hidden sm:inline">Detail</span></a>
                                <?php if (!empty($r['customer_id'])): ?>
                                    <a class="ui-btn ui-btn-sm ui-btn-outline" href="index.php?page=admin_customers&action=details&id=<?= intval($r['customer_id']) ?>" title="Tagihan kolektif mitra"><i class="fas fa-handshake"></i><span class="hidden sm:inline">Kolektif</span></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t border-solid border-border bg-muted text-sm font-semibold">
                        <td class="px-4 py-3 sm:px-5">Total</td>
                        <td class="px-3 py-3 text-right tabular-nums"><?= number_format($tot['customers']) ?></td>
                        <td class="px-3 py-3 text-right tabular-nums text-signal"><?= rp($tot['paid']) ?></td>
                        <td class="px-3 py-3 text-right tabular-nums <?= $tot['unpaid'] > 0 ? 'text-danger' : '' ?>"><?= rp($tot['unpaid']) ?></td>
                        <td class="px-3 py-3 text-right tabular-nums"><?= $tot_rate === null ? '-' : $tot_rate . '%' ?></td>
                        <td class="px-3 py-3 text-right tabular-nums"><?= rp($tot['pop_paid'] + $tot['pop_unpaid']) ?></td>
                        <td class="px-4 py-3 sm:px-5"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
