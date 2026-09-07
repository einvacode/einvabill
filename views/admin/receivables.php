<?php
// Umur piutang (aging): who owes the company, how much, and for how long.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-xl font-bold'>Akses ditolak</h2></div>"; return;
}
$tenant_id = intval($_SESSION['tenant_id'] ?? 1);
$today = date('Y-m-d');
$sc = cash_company_scope($db, $tenant_id); // partner-registered customers are the partner's receivables, not ours

$f_type = in_array($_GET['type'] ?? '', ['customer', 'partner']) ? $_GET['type'] : '';
$f_bucket = in_array($_GET['bucket'] ?? '', ['current', 'b30', 'b60', 'b90', 'b90p']) ? $_GET['bucket'] : '';
$f_search = trim((string)($_GET['search'] ?? ''));

// One row per customer: outstanding net of partial payments, split by age of the due date.
$sql = "SELECT c.id, c.name, c.customer_code, c.contact, c.type, c.area, c.package_name, c.monthly_fee, c.created_by,
        COUNT(i.id) AS inv_n,
        MIN(i.due_date) AS oldest_due,
        SUM(o.outstanding) AS total,
        SUM(CASE WHEN julianday(:today) - julianday(i.due_date) < 1 THEN o.outstanding ELSE 0 END) AS b_current,
        SUM(CASE WHEN julianday(:today) - julianday(i.due_date) BETWEEN 1 AND 30 THEN o.outstanding ELSE 0 END) AS b30,
        SUM(CASE WHEN julianday(:today) - julianday(i.due_date) BETWEEN 31 AND 60 THEN o.outstanding ELSE 0 END) AS b60,
        SUM(CASE WHEN julianday(:today) - julianday(i.due_date) BETWEEN 61 AND 90 THEN o.outstanding ELSE 0 END) AS b90,
        SUM(CASE WHEN julianday(:today) - julianday(i.due_date) > 90 THEN o.outstanding ELSE 0 END) AS b90p,
        (SELECT MAX(p.payment_date) FROM payments p JOIN invoices ii ON ii.id = p.invoice_id WHERE ii.customer_id = c.id) AS last_paid
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    JOIN (SELECT i2.id, (i2.amount - COALESCE(i2.discount,0)) - COALESCE((SELECT SUM(p2.amount) FROM payments p2 WHERE p2.invoice_id = i2.id),0) AS outstanding FROM invoices i2) o ON o.id = i.id
    WHERE i.tenant_id = :t AND i.status <> 'Lunas' AND o.outstanding > 0 {$sc['c']}";
$params = [':t' => $tenant_id, ':today' => $today];
if ($f_type) { $sql .= " AND c.type = :type"; $params[':type'] = $f_type; }
if ($f_search !== '') { $sql .= " AND (c.name LIKE :q OR c.customer_code LIKE :q OR c.contact LIKE :q OR c.area LIKE :q)"; $params[':q'] = '%' . $f_search . '%'; }
$sql .= " GROUP BY c.id ORDER BY MIN(i.due_date) ASC, total DESC";
$st = $db->prepare($sql); $st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$buckets = ['current' => 'Belum jatuh tempo', 'b30' => '1-30 hari', 'b60' => '31-60 hari', 'b90' => '61-90 hari', 'b90p' => 'Lebih dari 90 hari'];
$col = ['current' => 'b_current', 'b30' => 'b30', 'b60' => 'b60', 'b90' => 'b90', 'b90p' => 'b90p'];
foreach ($rows as &$r) {
    $r['age'] = max(0, (int)floor((strtotime($today) - strtotime($r['oldest_due'])) / 86400));
    $r['bucket'] = $r['age'] < 1 ? 'current' : ($r['age'] <= 30 ? 'b30' : ($r['age'] <= 60 ? 'b60' : ($r['age'] <= 90 ? 'b90' : 'b90p')));
}
unset($r);
$all = $rows;
if ($f_bucket) $rows = array_values(array_filter($rows, fn($r) => $r['bucket'] === $f_bucket));

$sum = ['total' => 0, 'b_current' => 0, 'b30' => 0, 'b60' => 0, 'b90' => 0, 'b90p' => 0, 'cust' => count($all), 'over60' => 0, 'over60_n' => 0];
foreach ($all as $r) {
    foreach (['total', 'b_current', 'b30', 'b60', 'b90', 'b90p'] as $k) $sum[$k] += (float)$r[$k];
    if ($r['b90'] + $r['b90p'] > 0) { $sum['over60'] += $r['b90'] + $r['b90p']; $sum['over60_n']++; }
}
$shown_total = array_sum(array_map(fn($r) => (float)$r['total'], $rows));

$settings_wa = $db->query("SELECT company_name, wa_template, bank_account, site_url FROM settings WHERE tenant_id = $tenant_id")->fetch(PDO::FETCH_ASSOC) ?: [];
$wa_tpl = $settings_wa['wa_template'] ?? "Halo {nama}, tagihan internet Anda {tagihan} ({bulan}) jatuh tempo pada {jatuh_tempo}. Hubungi admin untuk info pembayaran.";
$base_url = !empty($settings_wa['site_url']) ? rtrim($settings_wa['site_url'], '/') : get_app_url();
if (!function_exists('rp')) { function rp($n): string { return 'Rp ' . number_format((float)($n ?: 0), 0, ',', '.'); } }
$bucket_tone = ['current' => 'ui-badge-muted', 'b30' => 'ui-badge-accent', 'b60' => 'ui-badge-accent', 'b90' => 'ui-badge-danger', 'b90p' => 'ui-badge-danger'];
?>

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Umur piutang</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Tagihan perusahaan yang belum dibayar, dikelompokkan menurut lama keterlambatan per <?= date('d/m/Y') ?>. Piutang pelanggan mitra tidak termasuk.</p>
        </div>
        <a href="index.php?page=admin_invoices&filter_status=belum" class="ui-btn ui-btn-outline">Daftar tagihan belum lunas</a>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <a href="index.php?page=admin_receivables<?= $f_type ? '&type=' . $f_type : '' ?>" class="ui-card p-4 no-underline <?= $f_bucket === '' ? 'ring-2 ring-primary' : '' ?>">
            <div class="text-xs font-medium text-muted-foreground">Total piutang</div>
            <div class="mt-1 text-xl font-extrabold tabular-nums text-danger"><?= rp($sum['total']) ?></div>
            <div class="text-xs text-muted-foreground"><?= number_format($sum['cust']) ?> pelanggan</div>
        </a>
        <?php foreach ($buckets as $k => $label): $v = $sum[$col[$k]]; ?>
        <a href="index.php?page=admin_receivables&bucket=<?= $k ?><?= $f_type ? '&type=' . $f_type : '' ?>" class="ui-card p-4 no-underline <?= $f_bucket === $k ? 'ring-2 ring-primary' : '' ?>">
            <div class="text-xs font-medium text-muted-foreground"><?= $label ?></div>
            <div class="mt-1 text-xl font-extrabold tabular-nums <?= in_array($k, ['b90', 'b90p']) && $v > 0 ? 'text-danger' : '' ?>"><?= rp($v) ?></div>
            <div class="text-xs text-muted-foreground"><?= $sum['total'] > 0 ? round($v / $sum['total'] * 100) : 0 ?>% dari total</div>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_180px_200px_auto] lg:items-end">
        <input type="hidden" name="page" value="admin_receivables">
        <label class="block"><span class="mb-1 block text-xs font-medium text-muted-foreground">Cari</span><input type="text" name="search" class="form-control" value="<?= htmlspecialchars($f_search) ?>" placeholder="Nama, kode, HP, atau area"></label>
        <label class="block"><span class="mb-1 block text-xs font-medium text-muted-foreground">Jenis</span>
            <select name="type" class="form-control"><option value="">Semua</option><option value="customer" <?= $f_type === 'customer' ? 'selected' : '' ?>>Pelanggan</option><option value="partner" <?= $f_type === 'partner' ? 'selected' : '' ?>>Mitra (POP)</option></select>
        </label>
        <div class="block"><span class="mb-1 block text-xs font-medium text-muted-foreground">Kelompok umur</span>
            <select name="bucket" class="form-control" onchange="this.form.submit()"><option value="">Semua</option><?php foreach ($buckets as $k => $label): ?><option value="<?= $k ?>" <?= $f_bucket === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        </div>
        <button type="submit" class="ui-btn ui-btn-primary">Terapkan</button>
    </form>

    <section class="ui-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
            <div>
                <h3 class="m-0 text-[15px] font-bold">Rincian per pelanggan</h3>
                <p class="m-0 text-xs text-muted-foreground">Diurutkan dari tagihan tertua. <?= $sum['over60_n'] > 0 ? '<span class="font-semibold text-danger">' . number_format($sum['over60_n']) . ' pelanggan menunggak lebih dari 60 hari (' . rp($sum['over60']) . ').</span>' : 'Tidak ada tunggakan di atas 60 hari.' ?></p>
            </div>
            <div class="text-sm text-muted-foreground">Ditampilkan <span class="font-semibold tabular-nums text-foreground"><?= rp($shown_total) ?></span></div>
        </div>
        <?php if (empty($rows)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground"><?= empty($all) ? 'Tidak ada piutang. Semua tagihan sudah lunas.' : 'Tidak ada pelanggan pada filter ini.' ?></div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="min-w-[220px] px-4 py-2.5 font-semibold sm:px-5">Pelanggan</th>
                        <th class="px-3 py-2.5 font-semibold">Umur</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Piutang</th>
                        <th class="px-3 py-2.5 text-right font-semibold">1-30</th>
                        <th class="px-3 py-2.5 text-right font-semibold">31-60</th>
                        <th class="px-3 py-2.5 text-right font-semibold">&gt;60</th>
                        <th class="px-4 py-2.5 text-right font-semibold sm:px-5"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $over60 = $r['b90'] + $r['b90p'];
                        $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', (string)$r['contact']));
                        $portal_link = $base_url . '/index.php?page=customer_portal&code=' . urlencode($r['customer_code'] ?: $r['id']);
                        $msg = parse_wa_template($wa_tpl, ['name' => $r['name'], 'id_cust' => $r['customer_code'] ?: $r['id'], 'package' => $r['package_name'] ?: '-', 'period' => date('M Y', strtotime($r['oldest_due'])), 'tagihan' => $r['total'], 'due_date' => date('d/m/Y', strtotime($r['oldest_due'])), 'rekening' => trim((string)($settings_wa['bank_account'] ?? '')), 'tunggakan' => $r['total'], 'total_payment' => $r['total'], 'portal_link' => $portal_link]);
                    ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-3 align-top sm:px-5">
                            <div class="flex flex-wrap items-center gap-2"><span class="font-semibold"><?= htmlspecialchars($r['name']) ?></span><?php if ($r['type'] === 'partner'): ?><span class="ui-badge ui-badge-muted">Mitra</span><?php endif; ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($r['customer_code'] ?: '-') ?><?= !empty($r['contact']) ? ' &middot; ' . htmlspecialchars($r['contact']) : '' ?><?= !empty($r['area']) ? ' &middot; ' . htmlspecialchars($r['area']) : '' ?></div>
                            <div class="text-xs text-muted-foreground"><?= number_format($r['inv_n']) ?> tagihan &middot; tertua <?= date('d/m/Y', strtotime($r['oldest_due'])) ?><?= !empty($r['last_paid']) ? ' &middot; terakhir bayar ' . date('d/m/Y', strtotime($r['last_paid'])) : ' &middot; belum pernah bayar' ?></div>
                        </td>
                        <td class="px-3 py-3 align-top whitespace-nowrap">
                            <span class="ui-badge <?= $bucket_tone[$r['bucket']] ?>"><?= $r['age'] < 1 ? 'Belum jatuh tempo' : $r['age'] . ' hari' ?></span>
                        </td>
                        <td class="px-3 py-3 text-right align-top font-bold tabular-nums whitespace-nowrap text-danger"><?= rp($r['total']) ?></td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap <?= $r['b30'] > 0 ? '' : 'text-muted-foreground' ?>"><?= $r['b30'] > 0 ? rp($r['b30']) : '-' ?></td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap <?= $r['b60'] > 0 ? '' : 'text-muted-foreground' ?>"><?= $r['b60'] > 0 ? rp($r['b60']) : '-' ?></td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap <?= $over60 > 0 ? 'font-semibold text-danger' : 'text-muted-foreground' ?>"><?= $over60 > 0 ? rp($over60) : '-' ?></td>
                        <td class="px-4 py-3 align-top sm:px-5">
                            <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                <?php if ($wa_num): ?>
                                <button type="button" class="ui-btn ui-btn-sm ui-btn-wa" title="Ingatkan via WhatsApp" onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($msg)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_num ?>&text=<?= urlencode($msg) ?>', this)"><i class="fab fa-whatsapp"></i></button>
                                <?php endif; ?>
                                <a class="ui-btn ui-btn-sm ui-btn-outline" href="index.php?page=admin_customers&action=details&id=<?= intval($r['id']) ?>" title="Detail & bayar"><i class="fas fa-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t border-solid border-border bg-muted font-semibold">
                        <td class="px-4 py-3 sm:px-5" colspan="2">Total ditampilkan</td>
                        <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap text-danger"><?= rp($shown_total) ?></td>
                        <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap"><?= rp(array_sum(array_column($rows, 'b30'))) ?></td>
                        <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap"><?= rp(array_sum(array_column($rows, 'b60'))) ?></td>
                        <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap text-danger"><?= rp(array_sum(array_map(fn($r) => $r['b90'] + $r['b90p'], $rows))) ?></td>
                        <td class="px-4 py-3 sm:px-5"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>
