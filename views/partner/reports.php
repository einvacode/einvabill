<?php
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Only partners can access this specific view
if ($u_role !== 'partner') {
    echo "<div class='glass-panel' style='padding:40px; text-align:center;'>
            <i class='fas fa-user-lock fa-3x' style='opacity:0.3; margin-bottom:15px;'></i>
            <h3>Akses Terbatas</h3>
            <p>Halaman ini hanya dapat diakses oleh akun mitra.</p>
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

<div style="margin-bottom: 25px; display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h2 style="margin:0; font-weight:800; color:var(--text-primary);"><i class="fas fa-chart-pie text-primary"></i> Laporan Keuangan Mitra</h2>
        <p style="margin:5px 0 0; font-size:13px; color:var(--text-secondary);">Pantau arus kas dan performa penagihan pelanggan Anda.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="index.php?page=partner_reports&action=export&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="btn btn-sm btn-ghost" style="color:var(--success); border-color:var(--success);">
            <i class="fas fa-file-excel"></i> Export CSV
        </a>
    </div>
</div>

<!-- Filter Bar -->
<div class="glass-panel" style="padding:20px; margin-bottom:25px; border:1px solid var(--glass-border);">
    <form method="GET" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="page" value="partner_reports">
        <div style="flex:1; min-width:200px;">
            <label style="font-size:11px; font-weight:800; color:var(--text-secondary); display:block; margin-bottom:8px;">DARI TANGGAL</label>
            <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>" style="height:42px;">
        </div>
        <div style="flex:1; min-width:200px;">
            <label style="font-size:11px; font-weight:800; color:var(--text-secondary); display:block; margin-bottom:8px;">SAMPAI TANGGAL</label>
            <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>" style="height:42px;">
        </div>
        <button type="submit" class="btn btn-primary" style="height:42px; padding:0 30px;"><i class="fas fa-filter"></i> UPDATE LAPORAN</button>
    </form>
</div>

<!-- Stats Cards -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-bottom:30px;">
    <!-- Pendapatan Terkumpul -->
    <div class="glass-panel" style="padding:20px; border-left:5px solid var(--success); background:linear-gradient(135deg, rgba(16,185,129,0.1), transparent);">
        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Pendapatan Terkumpul</div>
        <div style="font-size:24px; font-weight:900; color:var(--success); margin:8px 0;">Rp <?= number_format($total_collected, 0, ',', '.') ?></div>
        <div style="font-size:11px; opacity:0.7;">Periode terpilih</div>
    </div>
    
    <!-- Piutang Berjalan -->
    <div class="glass-panel" style="padding:20px; border-left:5px solid var(--danger); background:linear-gradient(135deg, rgba(239,68,68,0.1), transparent);">
        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Total Piutang Berjalan</div>
        <div style="font-size:24px; font-weight:900; color:var(--danger); margin:8px 0;">Rp <?= number_format($total_piutang, 0, ',', '.') ?></div>
        <div style="font-size:11px; opacity:0.7;">Dari seluruh pelanggan saya</div>
    </div>

    <!-- Estimasi MRR -->
    <div class="glass-panel" style="padding:20px; border-left:5px solid var(--primary); background:linear-gradient(135deg, rgba(37,99,235,0.1), transparent);">
        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Potensi MRR</div>
        <div style="font-size:24px; font-weight:900; color:var(--primary); margin:8px 0;">Rp <?= number_format($est_mrr, 0, ',', '.') ?></div>
        <div style="font-size:11px; opacity:0.7;">Total biaya bulanan paket</div>
    </div>

    <!-- Pelanggan Baru -->
    <div class="glass-panel" style="padding:20px; border-left:5px solid var(--warning); background:linear-gradient(135deg, rgba(245,158,11,0.1), transparent);">
        <div style="font-size:11px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px;">Pelanggan Baru</div>
        <div style="font-size:24px; font-weight:900; color:var(--warning); margin:8px 0;"><?= $new_customers ?> <span style="font-size:14px; font-weight:600;">Member</span></div>
        <div style="font-size:11px; opacity:0.7;">Bergabung periode ini</div>
    </div>
</div>

<!-- Transaction Table -->
<div class="glass-panel" style="padding:0; overflow:hidden; border:1px solid var(--glass-border);">
    <div style="padding:20px; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
        <h4 style="margin:0;"><i class="fas fa-list-ul text-primary"></i> Rincian Aktivitas Transaksi</h4>
        <span style="font-size:12px; color:var(--text-secondary); font-weight:600;"><?= count($report_items) ?> Transaksi Ditemukan</span>
    </div>
    <div class="table-container">
        <table style="width:100%;">
            <thead>
                <tr style="background:rgba(255,255,255,0.02);">
                    <th style="padding:15px; font-size:11px; text-align:left;">TANGGAL</th>
                    <th style="padding:15px; font-size:11px; text-align:left;">PELANGGAN</th>
                    <th style="padding:15px; font-size:11px; text-align:left;">KETERANGAN</th>
                    <th style="padding:15px; font-size:11px; text-align:right;">NOMINAL</th>
                    <th style="padding:15px; font-size:11px; text-align:center;">STATUS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($report_items as $item): ?>
                <tr style="border-bottom:1px solid var(--glass-border); transition:all 0.2s;">
                    <td style="padding:15px; font-size:13px;"><?= date('d/m/Y', strtotime($item['activity_date'])) ?></td>
                    <td style="padding:15px;">
                        <div style="font-weight:700; font-size:14px;"><?= htmlspecialchars($item['customer_name']) ?></div>
                    </td>
                    <td style="padding:15px; font-size:13px;">
                        <span style="color:var(--text-secondary); font-weight:600;"><?= $item['activity_type'] ?></span><br>
                        <small style="opacity:0.6;">INV-<?= str_pad($item['invoice_id'], 5, "0", STR_PAD_LEFT) ?></small>
                    </td>
                    <td style="padding:15px; text-align:right; font-weight:800; font-size:14px; color:<?= $item['activity_type'] == 'Pembayaran' ? 'var(--success)' : 'var(--text-primary)' ?>;">
                        <?= $item['activity_type'] == 'Pembayaran' ? '+' : '' ?>Rp <?= number_format($item['amount'], 0, ',', '.') ?>
                    </td>
                    <td style="padding:15px; text-align:center; display:flex; justify-content:center; gap:5px;">
                        <span class="badge <?= $item['status'] == 'Lunas' ? 'badge-success' : 'badge-danger' ?>" style="font-size:10px; padding:4px 10px; border-radius:6px;">
                            <?= strtoupper($item['status']) ?>
                        </span>
                        <?php if($item['status'] == 'Lunas'): ?>
                            <a href="index.php?page=invoice_print&id=<?= $item['invoice_id'] ?>&format=thermal" target="_blank" class="btn btn-ghost" style="width:30px; height:30px; padding:0; border-radius:6px; display:flex; align-items:center; justify-content:center; border:1px solid rgba(var(--primary-rgb), 0.2);" title="Cetak Kuitansi">
                                <i class="fas fa-print" style="font-size:12px;"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($report_items)): ?>
                <tr>
                    <td colspan="5" style="padding:60px; text-align:center;">
                        <i class="fas fa-inbox fa-3x" style="opacity:0.1; margin-bottom:15px; display:block;"></i>
                        <span style="opacity:0.5;">Belum ada data transaksi untuk filter ini.</span>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
