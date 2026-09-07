<?php
// Detail of one partner for admin: its customers with billing status for the
// chosen period, plus the collective invoices billed to the partner.
// Included from partner_dashboard.php (which already validated admin role,
// $tenant_id, $period, $period_sql, $month_label, $period_label, $months, rp()).
$pid = intval($_GET['id'] ?? 0);
$st = $db->prepare("SELECT u.id, u.name, u.username, u.customer_id, c.name AS pop_name, c.customer_code AS pop_code, c.contact AS pop_contact, c.address AS pop_address, c.monthly_fee AS pop_fee, c.billing_date AS pop_billing_date
    FROM users u LEFT JOIN customers c ON c.id = u.customer_id WHERE u.id = ? AND u.role = 'partner' AND u.tenant_id = ?");
$st->execute([$pid, $tenant_id]);
$partner = $st->fetch(PDO::FETCH_ASSOC);
if (!$partner) { echo "<div class='ui-card p-5 text-sm text-muted-foreground'>Mitra tidak ditemukan.</div>"; return; }

$search = trim((string)($_GET['search'] ?? ''));
$status_filter = in_array($_GET['status'] ?? '', ['lunas', 'belum', 'none']) ? $_GET['status'] : '';

// Customers of the partner with their invoices in the period (aggregated per customer).
$sql = "SELECT c.id, c.name, c.customer_code, c.package_name, c.monthly_fee, c.contact, c.area, c.registration_date,
        COUNT(i.id) AS inv_n,
        COALESCE(SUM(CASE WHEN i.status = 'Lunas' THEN i.amount - COALESCE(i.discount,0) ELSE 0 END), 0) AS paid_amt,
        COALESCE(SUM(CASE WHEN i.status <> 'Lunas' THEN i.amount - COALESCE(i.discount,0) ELSE 0 END), 0) AS unpaid_amt,
        SUM(CASE WHEN i.status <> 'Lunas' THEN 1 ELSE 0 END) AS unpaid_n,
        MAX(CASE WHEN i.status = 'Lunas' THEN (SELECT MAX(p.payment_date) FROM payments p WHERE p.invoice_id = i.id) END) AS last_paid,
        MIN(CASE WHEN i.status <> 'Lunas' THEN i.due_date END) AS oldest_due,
        (SELECT COALESCE(SUM(x.amount - COALESCE(x.discount,0)), 0) FROM invoices x WHERE x.customer_id = c.id AND x.status <> 'Lunas') AS arrears_all
    FROM customers c
    LEFT JOIN invoices i ON i.customer_id = c.id AND i.tenant_id = :tenant $period_sql
    WHERE c.created_by = :uid AND c.tenant_id = :tenant AND c.type = 'customer'";
$params = [':uid' => $pid, ':tenant' => $tenant_id];
if ($period !== 'all') $params[':period'] = $period;
if ($search !== '') { $sql .= " AND (c.name LIKE :q OR c.customer_code LIKE :q OR c.contact LIKE :q)"; $params[':q'] = '%' . $search . '%'; }
$sql .= " GROUP BY c.id ORDER BY unpaid_amt DESC, c.name ASC";
$st = $db->prepare($sql); $st->execute($params);
$customers = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($customers as &$c) {
    $c['state'] = $c['unpaid_n'] > 0 ? 'belum' : ($c['paid_amt'] > 0 ? 'lunas' : 'none');
}
unset($c);
$all_customers = $customers;
if ($status_filter) $customers = array_values(array_filter($customers, fn($c) => $c['state'] === $status_filter));

$sum = ['n' => count($all_customers), 'paid' => 0, 'unpaid' => 0, 'lunas_n' => 0, 'belum_n' => 0, 'none_n' => 0, 'mrr' => 0, 'arrears' => 0];
foreach ($all_customers as $c) {
    $sum['paid'] += $c['paid_amt']; $sum['unpaid'] += $c['unpaid_amt']; $sum['mrr'] += (float)$c['monthly_fee']; $sum['arrears'] += $c['arrears_all'];
    $sum[$c['state'] . '_n']++;
}
$billed = $sum['paid'] + $sum['unpaid'];
$rate = $billed > 0 ? round($sum['paid'] / $billed * 100) : null;

// Collective invoices billed to the partner in the period.
$pop_invoices = [];
if (!empty($partner['customer_id'])) {
    $sql = "SELECT i.id, i.amount, COALESCE(i.discount,0) AS discount, i.due_date, i.status, i.created_at,
            (SELECT MAX(p.payment_date) FROM payments p WHERE p.invoice_id = i.id) AS paid_at
        FROM invoices i WHERE i.customer_id = :cid AND i.tenant_id = :tenant $period_sql ORDER BY i.due_date DESC LIMIT 24";
    $params = [':cid' => $partner['customer_id'], ':tenant' => $tenant_id];
    if ($period !== 'all') $params[':period'] = $period;
    $st = $db->prepare($sql); $st->execute($params);
    $pop_invoices = $st->fetchAll(PDO::FETCH_ASSOC);
}
$pop_unpaid = array_sum(array_map(fn($i) => $i['status'] !== 'Lunas' ? $i['amount'] - $i['discount'] : 0, $pop_invoices));
$pop_paid = array_sum(array_map(fn($i) => $i['status'] === 'Lunas' ? $i['amount'] - $i['discount'] : 0, $pop_invoices));

$base_url = 'index.php?page=admin_partner_dashboard&action=detail&id=' . $pid;
$fmt_date = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '-';
?>

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="index.php?page=admin_partner_dashboard&period=<?= urlencode($period) ?>" class="ui-btn ui-btn-sm ui-btn-outline mb-3"><i class="fas fa-arrow-left"></i> Dashboard mitra</a>
            <h2 class="m-0 text-xl font-bold sm:text-2xl"><?= htmlspecialchars($partner['name']) ?></h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">
                <?= htmlspecialchars($partner['pop_name'] ?: 'Belum terhubung ke data POP') ?><?= !empty($partner['pop_code']) ? ' &middot; ' . htmlspecialchars($partner['pop_code']) : '' ?><?= !empty($partner['pop_contact']) ? ' &middot; ' . htmlspecialchars($partner['pop_contact']) : '' ?>
                &middot; akun <span class="font-medium text-foreground"><?= htmlspecialchars($partner['username']) ?></span>
            </p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <?php if (!empty($partner['customer_id'])): ?>
                <a class="ui-btn ui-btn-outline" href="index.php?page=admin_customers&action=details&id=<?= intval($partner['customer_id']) ?>"><i class="fas fa-handshake"></i> Tagihan kolektif</a>
            <?php endif; ?>
            <form method="get" class="block">
                <input type="hidden" name="page" value="admin_partner_dashboard">
                <input type="hidden" name="action" value="detail">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                <?php if ($status_filter): ?><input type="hidden" name="status" value="<?= $status_filter ?>"><?php endif; ?>
                <select name="period" class="form-control h-10 w-[200px]" onchange="this.form.submit()" aria-label="Periode">
                    <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Semua periode</option>
                    <?php foreach ($months as $m): ?>
                        <option value="<?= $m ?>" <?= $period === $m ? 'selected' : '' ?>><?= $month_label($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Pelanggan mitra</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= number_format($sum['n']) ?></div>
            <div class="text-xs text-muted-foreground">Estimasi <?= rp($sum['mrr']) ?> / bulan</div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Sudah bayar</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal"><?= rp($sum['paid']) ?></div>
            <div class="text-xs text-muted-foreground"><?= number_format($sum['lunas_n']) ?> pelanggan<?= $rate !== null ? ' &middot; tingkat tagih ' . $rate . '%' : '' ?></div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Belum bayar</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-danger"><?= rp($sum['unpaid']) ?></div>
            <div class="text-xs text-muted-foreground"><?= number_format($sum['belum_n']) ?> pelanggan menunggak<?= $sum['none_n'] > 0 ? ' &middot; ' . number_format($sum['none_n']) . ' tanpa tagihan' : '' ?></div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Kolektif ke perusahaan</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= rp($pop_paid + $pop_unpaid) ?></div>
            <div class="text-xs <?= $pop_unpaid > 0 ? 'text-danger' : 'text-muted-foreground' ?>"><?= $pop_unpaid > 0 ? 'Belum lunas ' . rp($pop_unpaid) : ($pop_paid > 0 ? 'Semua lunas' : 'Belum ada tagihan') ?></div>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px] xl:items-start">
        <section class="ui-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                <div>
                    <h3 class="m-0 text-[15px] font-bold">Pelanggan mitra</h3>
                    <p class="m-0 text-xs text-muted-foreground">Status tagihan <?= htmlspecialchars($period_label) ?>. Diurutkan dari tunggakan terbesar.</p>
                </div>
                <form method="get" class="flex flex-wrap gap-2">
                    <input type="hidden" name="page" value="admin_partner_dashboard">
                    <input type="hidden" name="action" value="detail">
                    <input type="hidden" name="id" value="<?= $pid ?>">
                    <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="form-control h-9 w-[180px] text-sm" placeholder="Nama, kode, atau HP">
                    <select name="status" class="form-control h-9 w-[150px] text-sm" onchange="this.form.submit()">
                        <option value="">Semua status</option>
                        <option value="lunas" <?= $status_filter === 'lunas' ? 'selected' : '' ?>>Sudah bayar</option>
                        <option value="belum" <?= $status_filter === 'belum' ? 'selected' : '' ?>>Belum bayar</option>
                        <option value="none" <?= $status_filter === 'none' ? 'selected' : '' ?>>Tanpa tagihan</option>
                    </select>
                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline">Cari</button>
                </form>
            </div>
            <?php if (empty($customers)): ?>
                <div class="px-5 py-10 text-center text-sm text-muted-foreground"><?= $sum['n'] === 0 ? 'Mitra ini belum memiliki pelanggan.' : 'Tidak ada pelanggan yang cocok dengan filter.' ?></div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold sm:px-5">Pelanggan</th>
                            <th class="px-3 py-2.5 font-semibold">Status</th>
                            <th class="px-3 py-2.5 text-right font-semibold">Sudah bayar</th>
                            <th class="px-3 py-2.5 text-right font-semibold">Belum bayar</th>
                            <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Tunggakan total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                        <tr class="border-t border-solid border-border">
                            <td class="px-4 py-3 align-top sm:px-5">
                                <div class="font-semibold"><?= htmlspecialchars($c['name']) ?></div>
                                <div class="text-xs text-muted-foreground"><?= htmlspecialchars($c['customer_code'] ?: '-') ?><?= !empty($c['contact']) ? ' &middot; ' . htmlspecialchars($c['contact']) : '' ?><?= !empty($c['area']) ? ' &middot; ' . htmlspecialchars($c['area']) : '' ?></div>
                                <div class="text-xs text-muted-foreground"><?= htmlspecialchars($c['package_name'] ?: 'Tanpa paket') ?> &middot; <span class="tabular-nums"><?= rp($c['monthly_fee']) ?></span>/bulan</div>
                            </td>
                            <td class="px-3 py-3 align-top whitespace-nowrap">
                                <?php if ($c['state'] === 'belum'): ?>
                                    <span class="ui-badge ui-badge-danger">Belum bayar</span>
                                    <div class="mt-1 text-xs text-muted-foreground">Jatuh tempo <?= $fmt_date($c['oldest_due']) ?></div>
                                <?php elseif ($c['state'] === 'lunas'): ?>
                                    <span class="ui-badge ui-badge-signal">Sudah bayar</span>
                                    <div class="mt-1 text-xs text-muted-foreground">Bayar <?= $fmt_date($c['last_paid']) ?></div>
                                <?php else: ?>
                                    <span class="ui-badge ui-badge-muted">Tanpa tagihan</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap <?= $c['paid_amt'] > 0 ? 'text-signal font-semibold' : 'text-muted-foreground' ?>"><?= rp($c['paid_amt']) ?></td>
                            <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap <?= $c['unpaid_amt'] > 0 ? 'text-danger font-semibold' : 'text-muted-foreground' ?>"><?= rp($c['unpaid_amt']) ?></td>
                            <td class="px-4 py-3 text-right align-top tabular-nums whitespace-nowrap sm:px-5 <?= $c['arrears_all'] > 0 ? 'font-semibold' : 'text-muted-foreground' ?>"><?= rp($c['arrears_all']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-solid border-border bg-muted text-sm font-semibold">
                            <td class="px-4 py-3 sm:px-5" colspan="2">Total <?= $status_filter || $search !== '' ? '(sesuai filter)' : '' ?></td>
                            <td class="px-3 py-3 text-right tabular-nums text-signal"><?= rp(array_sum(array_column($customers, 'paid_amt'))) ?></td>
                            <td class="px-3 py-3 text-right tabular-nums text-danger"><?= rp(array_sum(array_column($customers, 'unpaid_amt'))) ?></td>
                            <td class="px-4 py-3 text-right tabular-nums sm:px-5"><?= rp(array_sum(array_column($customers, 'arrears_all'))) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <aside class="grid gap-5">
            <section class="ui-card overflow-hidden">
                <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                    <h3 class="m-0 text-[15px] font-bold">Tagihan kolektif ke perusahaan</h3>
                    <p class="m-0 text-xs text-muted-foreground"><?= !empty($partner['pop_fee']) ? rp($partner['pop_fee']) . ' / bulan, tagih tanggal ' . intval($partner['pop_billing_date'] ?: 1) : 'Tagihan perusahaan ke mitra ini' ?>.</p>
                </div>
                <?php if (empty($pop_invoices)): ?>
                    <div class="px-5 py-8 text-center text-sm text-muted-foreground"><?= empty($partner['customer_id']) ? 'Akun mitra belum dihubungkan ke data POP.' : 'Tidak ada tagihan kolektif pada periode ini.' ?></div>
                <?php else: ?>
                    <table class="w-full border-collapse text-sm">
                        <tbody>
                            <?php foreach ($pop_invoices as $inv): $net = $inv['amount'] - $inv['discount']; ?>
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 align-top sm:px-5">
                                    <div class="font-medium tabular-nums">INV-<?= str_pad($inv['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                    <div class="text-xs text-muted-foreground">Jatuh tempo <?= $fmt_date($inv['due_date']) ?><?= $inv['status'] === 'Lunas' && $inv['paid_at'] ? ' &middot; bayar ' . $fmt_date($inv['paid_at']) : '' ?></div>
                                </td>
                                <td class="px-4 py-3 text-right align-top whitespace-nowrap sm:px-5">
                                    <div class="font-semibold tabular-nums"><?= rp($net) ?></div>
                                    <span class="ui-badge <?= $inv['status'] === 'Lunas' ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= $inv['status'] === 'Lunas' ? 'Lunas' : 'Belum lunas' ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="ui-card p-4 sm:p-5">
                <h3 class="m-0 text-[15px] font-bold">Ringkasan</h3>
                <dl class="m-0 mt-3 grid gap-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Pelanggan</dt><dd class="m-0 font-medium tabular-nums"><?= number_format($sum['n']) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Sudah bayar</dt><dd class="m-0 font-medium tabular-nums"><?= number_format($sum['lunas_n']) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Belum bayar</dt><dd class="m-0 font-medium tabular-nums"><?= number_format($sum['belum_n']) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Tanpa tagihan</dt><dd class="m-0 font-medium tabular-nums"><?= number_format($sum['none_n']) ?></dd></div>
                    <div class="flex justify-between gap-3 border-t border-solid border-border pt-2"><dt class="text-muted-foreground">Tunggakan total (semua periode)</dt><dd class="m-0 font-semibold tabular-nums <?= $sum['arrears'] > 0 ? 'text-danger' : '' ?>"><?= rp($sum['arrears']) ?></dd></div>
                    <?php if (!empty($partner['pop_address'])): ?>
                    <div class="border-t border-solid border-border pt-2 text-xs text-muted-foreground"><?= htmlspecialchars($partner['pop_address']) ?></div>
                    <?php endif; ?>
                </dl>
            </section>
        </aside>
    </div>
</div>
