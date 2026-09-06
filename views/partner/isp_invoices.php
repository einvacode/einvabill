<?php
// Partner view for ISP Invoices
$user_id = intval($_SESSION['user_id']);
$stmt_u = $db->prepare("SELECT customer_id FROM users WHERE id = ?");
$stmt_u->execute([$user_id]);
$u = $stmt_u->fetch();
$partner_cid = $u['customer_id'] ?? 0;

if (!$partner_cid) {
    echo "<div class='ui-card px-5 py-10 text-center'>
            <h3 class='m-0 text-lg font-bold'>Akun belum tertaut</h3>
            <p class='m-0 mt-1 text-sm text-muted-foreground'>Akun mitra Anda belum ditautkan ke data pelanggan pusat sebagai reseller.</p>
          </div>";
    return;
}

// Date filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'belum';
$sort_date = $_GET['sort_date'] ?? 'desc';

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

// Fetch stats
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
?>

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Tagihan kemitraan saya ke ISP</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Riwayat tagihan bulanan dari ISP pusat untuk akun reseller Anda.</p>
    </div>
</div>

<!-- Stats -->
<div class="mb-5 grid grid-cols-2 gap-3">
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total tunggakan</div>
        <div class="mt-1 text-xl font-extrabold tabular-nums text-danger sm:text-2xl">Rp <?= number_format($partner_stats['total_belum'], 0, ',', '.') ?></div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total terbayar</div>
        <div class="mt-1 text-xl font-extrabold tabular-nums text-signal sm:text-2xl">Rp <?= number_format($partner_stats['total_lunas'], 0, ',', '.') ?></div>
    </div>
</div>

<!-- Filter Form -->
<form method="GET" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_1fr_auto] lg:items-end">
    <input type="hidden" name="page" value="partner_isp_invoices">
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Dari tanggal jatuh tempo</span>
        <input type="date" name="date_from" class="form-control w-full" value="<?= htmlspecialchars($date_from) ?>">
    </label>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Sampai tanggal</span>
        <input type="date" name="date_to" class="form-control w-full" value="<?= htmlspecialchars($date_to) ?>">
    </label>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Status pembayaran</span>
        <select name="filter_status" class="form-control w-full">
            <option value="semua" <?= $filter_status === 'semua' ? 'selected' : '' ?>>Semua status</option>
            <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum lunas</option>
            <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas terbayar</option>
        </select>
    </label>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Urutan</span>
        <select name="sort_date" class="form-control w-full">
            <option value="desc" <?= $sort_date === 'desc' ? 'selected' : '' ?>>Terbaru</option>
            <option value="asc" <?= $sort_date === 'asc' ? 'selected' : '' ?>>Terlama</option>
        </select>
    </label>
    <div class="flex gap-2">
        <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-filter"></i> Cari</button>
        <a href="index.php?page=partner_isp_invoices" class="ui-btn ui-btn-outline filter-reset-btn" title="Reset filter"><i class="fas fa-redo"></i></a>
    </div>
</form>

<!-- Data List -->
<?php if(empty($partner_invoices)): ?>
    <div class="ui-card px-5 py-10 text-center text-sm text-muted-foreground">
        <div class="font-semibold text-foreground">Tidak ada tagihan ditemukan</div>
        <div class="mt-1">Silakan ubah filter atau hubungi ISP pusat jika ada kendala.</div>
    </div>
<?php else: ?>
    <!-- Desktop Mode -->
    <section class="ui-card overflow-hidden desktop-only">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold">Nomor invoice</th>
                        <th class="px-4 py-2.5 font-semibold">Jatuh tempo</th>
                        <th class="px-4 py-2.5 font-semibold">Nominal</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Opsi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($partner_invoices as $p_inv): ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-3 font-semibold tabular-nums">#<?= str_pad($p_inv['id'], 5, "0", STR_PAD_LEFT) ?></td>
                        <td class="px-4 py-3 tabular-nums text-muted-foreground"><?= date('d/m/Y', strtotime($p_inv['due_date'])) ?></td>
                        <td class="px-4 py-3 font-bold tabular-nums whitespace-nowrap <?= $p_inv['status'] == 'Lunas' ? 'text-signal' : 'text-foreground' ?>">Rp <?= number_format($p_inv['amount'], 0, ',', '.') ?></td>
                        <td class="px-4 py-3">
                            <span class="ui-badge <?= $p_inv['status'] == 'Lunas' ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= htmlspecialchars($p_inv['status']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="index.php?page=invoice_print&id=<?= $p_inv['id'] ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Mobile Mode -->
    <div class="mobile-only">
        <?php foreach($partner_invoices as $p_inv): ?>
        <div class="ui-card mb-3 p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-xs text-muted-foreground">Nomor invoice</div>
                    <div class="text-[15px] font-bold tabular-nums">#INV-<?= str_pad($p_inv['id'], 5, "0", STR_PAD_LEFT) ?></div>
                </div>
                <span class="ui-badge <?= $p_inv['status'] == 'Lunas' ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= htmlspecialchars($p_inv['status']) ?></span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 border-t border-solid border-border pt-3">
                <div>
                    <div class="text-xs text-muted-foreground">Tanggal jatuh tempo</div>
                    <div class="text-sm font-semibold tabular-nums"><?= date('d/m/Y', strtotime($p_inv['due_date'])) ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-muted-foreground">Total tagihan</div>
                    <div class="text-[15px] font-bold tabular-nums <?= $p_inv['status'] == 'Lunas' ? 'text-signal' : 'text-foreground' ?>">Rp <?= number_format($p_inv['amount'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="mt-3">
                <a href="index.php?page=invoice_print&id=<?= $p_inv['id'] ?>" target="_blank" class="ui-btn ui-btn-outline w-full"><i class="fas fa-print"></i> Cetak struk pembayaran</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
