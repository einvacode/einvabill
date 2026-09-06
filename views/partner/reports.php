<?php
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Only partners can access this specific view
if ($u_role !== 'partner') {
    echo "<div class='ui-card px-5 py-10 text-center'>
            <h3 class='m-0 text-lg font-bold'>Akses terbatas</h3>
            <p class='m-0 mt-1 text-sm text-muted-foreground'>Halaman ini hanya dapat diakses oleh akun mitra.</p>
          </div>";
    return;
}

// Date range filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$action = $_GET['action'] ?? 'view';

$sql_date_from = $date_from . ' 00:00:00';
$sql_date_to = $date_to . ' 23:59:59';

// Scoping: Only customers created by this partner
$scope_where = " AND c.created_by = $u_id AND c.tenant_id = $tenant_id ";
$scope_inv = " AND i.tenant_id = $tenant_id ";

// --- METRICS ---

// 1. Total Collections (Cash In)
$q_collected = $db->prepare("
    SELECT SUM(p.amount) FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id
    WHERE p.payment_date BETWEEN ? AND ? $scope_where
");
$q_collected->execute([$sql_date_from, $sql_date_to]);
$total_collected = $q_collected->fetchColumn() ?: 0;

// 2. Outstanding Receivables (Piutang)
$q_piutang = $db->prepare("
    SELECT SUM(i.amount - i.discount) FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE i.status = 'Belum Lunas' AND i.due_date <= ? $scope_where
");
$q_piutang->execute([$date_to]);
$total_piutang = $q_piutang->fetchColumn() ?: 0;

// 3. New Customers this period
$q_new_cust = $db->prepare("
    SELECT COUNT(*) FROM customers c
    WHERE c.registration_date BETWEEN ? AND ? $scope_where
");
$q_new_cust->execute([$date_from, $date_to]);
$new_customers = $q_new_cust->fetchColumn() ?: 0;

// 4. Estimasi MRR (Monthly Recurring Revenue)
$q_mrr = $db->prepare("
    SELECT SUM(monthly_fee) FROM customers c
    WHERE 1=1 $scope_where
");
$q_mrr->execute();
$est_mrr = $q_mrr->fetchColumn() ?: 0;

// --- DETAIL DATA (UNION) ---
$sql_report = "
    SELECT
        'Pembayaran' as activity_type,
        p.payment_date as activity_date,
        c.name as customer_name,
        i.id as invoice_id,
        p.amount as amount,
        'Lunas' as status
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id
    WHERE p.payment_date BETWEEN ? AND ? $scope_where

    UNION ALL

    SELECT
        'Tagihan' as activity_type,
        i.due_date as activity_date,
        c.name as customer_name,
        i.id as invoice_id,
        (i.amount - i.discount) as amount,
        i.status
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE i.due_date BETWEEN ? AND ? AND i.status = 'Belum Lunas' $scope_where

    ORDER BY activity_date DESC
";

if ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Laporan_Keuangan_Mitra_' . $date_from . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
    fputcsv($output, ['Tipe', 'Tanggal', 'Pelanggan', 'No Invoice', 'Nominal', 'Status']);

    $stmt = $db->prepare($sql_report);
    $stmt->execute([$sql_date_from, $sql_date_to, $date_from, $date_to]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['activity_type'],
            $row['activity_date'],
            $row['customer_name'],
            'INV-' . str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT),
            $row['amount'],
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}

$stmt_list = $db->prepare($sql_report);
$stmt_list->execute([$sql_date_from, $sql_date_to, $date_from, $date_to]);
$report_items = $stmt_list->fetchAll();
?>

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Laporan keuangan mitra</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Pantau arus kas dan performa penagihan pelanggan Anda.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="index.php?page=partner_reports&action=export&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="ui-btn ui-btn-outline"><i class="fas fa-file-excel"></i> Ekspor CSV</a>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[180px_180px_auto] lg:items-end">
    <input type="hidden" name="page" value="partner_reports">
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Dari tanggal</span>
        <input type="date" name="date_from" class="form-control w-full" value="<?= $date_from ?>">
    </label>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Sampai tanggal</span>
        <input type="date" name="date_to" class="form-control w-full" value="<?= $date_to ?>">
    </label>
    <div class="flex gap-2">
        <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-filter"></i> Perbarui laporan</button>
    </div>
</form>

<!-- Stats Cards -->
<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
    <!-- Pendapatan Terkumpul -->
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Pendapatan terkumpul</div>
        <div class="mt-1 text-xl font-extrabold tabular-nums text-signal sm:text-2xl">Rp <?= number_format($total_collected, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Periode terpilih</div>
    </div>

    <!-- Piutang Berjalan -->
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total piutang berjalan</div>
        <div class="mt-1 text-xl font-extrabold tabular-nums text-danger sm:text-2xl">Rp <?= number_format($total_piutang, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Dari seluruh pelanggan saya</div>
    </div>

    <!-- Estimasi MRR -->
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Potensi MRR</div>
        <div class="mt-1 text-xl font-extrabold tabular-nums sm:text-2xl">Rp <?= number_format($est_mrr, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Total biaya bulanan paket</div>
    </div>

    <!-- Pelanggan Baru -->
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Pelanggan baru</div>
        <div class="mt-1 text-xl font-extrabold tabular-nums sm:text-2xl"><?= $new_customers ?> <span class="text-sm font-semibold text-muted-foreground">member</span></div>
        <div class="text-xs text-muted-foreground">Bergabung periode ini</div>
    </div>
</div>

<!-- Transaction Table -->
<section class="ui-card overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Rincian aktivitas transaksi</h3>
            <p class="m-0 text-xs text-muted-foreground"><?= count($report_items) ?> transaksi ditemukan</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold sm:px-5">Tanggal</th>
                    <th class="px-4 py-2.5 font-semibold">Pelanggan</th>
                    <th class="px-4 py-2.5 font-semibold">Keterangan</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Nominal</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($report_items as $item): ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 tabular-nums text-muted-foreground whitespace-nowrap sm:px-5"><?= date('d/m/Y', strtotime($item['activity_date'])) ?></td>
                    <td class="px-4 py-3 font-semibold"><?= htmlspecialchars($item['customer_name']) ?></td>
                    <td class="px-4 py-3">
                        <div><?= $item['activity_type'] ?></div>
                        <div class="text-xs text-muted-foreground tabular-nums">INV-<?= str_pad($item['invoice_id'], 5, "0", STR_PAD_LEFT) ?></div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold tabular-nums whitespace-nowrap <?= $item['activity_type'] == 'Pembayaran' ? 'text-signal' : 'text-foreground' ?>">
                        <?= $item['activity_type'] == 'Pembayaran' ? '+' : '' ?>Rp <?= number_format($item['amount'], 0, ',', '.') ?>
                    </td>
                    <td class="px-4 py-3 sm:px-5">
                        <div class="flex items-center justify-end gap-1.5">
                            <span class="ui-badge <?= $item['status'] == 'Lunas' ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= htmlspecialchars($item['status']) ?></span>
                            <?php if($item['status'] == 'Lunas'): ?>
                                <a href="index.php?page=invoice_print&id=<?= $item['invoice_id'] ?>&format=thermal" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak kuitansi"><i class="fas fa-print"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($report_items)): ?>
                <tr>
                    <td colspan="5" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data transaksi untuk filter ini.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
