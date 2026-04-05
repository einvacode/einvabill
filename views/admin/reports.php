<?php
$action = $_GET['action'] ?? 'view';

$filter_month = $_GET['month'] ?? date('m');
$filter_year = $_GET['year'] ?? date('Y');
$filter_user = $_GET['user_id'] ?? 'all';
$period = sprintf("%04d-%02d", $filter_year, $filter_month);

// Fetch all admins and collectors for filter (Excluding partners as they are not collectors)
$available_users = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'collector') ORDER BY name ASC")->fetchAll();

$months = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// Metrics Queries
$sql_lunas_tepat = "
    SELECT SUM(p.amount) as total
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    WHERE strftime('%Y-%m', p.payment_date) = ?
      AND strftime('%Y-%m', i.due_date) = ?
";
$params_lunas_tepat = [$period, $period];
if ($filter_user !== 'all') {
    $sql_lunas_tepat .= " AND p.received_by = ?";
    $params_lunas_tepat[] = $filter_user;
}
$q_lunas_tepat = $db->prepare($sql_lunas_tepat);
$q_lunas_tepat->execute($params_lunas_tepat);
$lunas_tepat = $q_lunas_tepat->fetchColumn() ?: 0;

$sql_tunggakan_dibayar = "
    SELECT SUM(p.amount) as total
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    WHERE strftime('%Y-%m', p.payment_date) = ?
      AND strftime('%Y-%m', i.due_date) < ?
";
$params_tunggakan_dibayar = [$period, $period];
if ($filter_user !== 'all') {
    $sql_tunggakan_dibayar .= " AND p.received_by = ?";
    $params_tunggakan_dibayar[] = $filter_user;
}
$q_tunggakan_dibayar = $db->prepare($sql_tunggakan_dibayar);
$q_tunggakan_dibayar->execute($params_tunggakan_dibayar);
$tunggakan_dibayar = $q_tunggakan_dibayar->fetchColumn() ?: 0;

$q_belum_bayar = $db->prepare("
    SELECT SUM(amount - discount) as total
    FROM invoices 
    WHERE strftime('%Y-%m', due_date) = ? 
      AND status = 'Belum Lunas'
");
$q_belum_bayar->execute([$period]);
$belum_bayar = $q_belum_bayar->fetchColumn() ?: 0;

$q_tertunggak_lama = $db->prepare("
    SELECT SUM(amount - discount) as total
    FROM invoices 
    WHERE strftime('%Y-%m', due_date) < ? 
      AND status = 'Belum Lunas'
");
$q_tertunggak_lama->execute([$period]);
$tertunggak_lama = $q_tertunggak_lama->fetchColumn() ?: 0;

// NEW: Realized Discounts (Discounts on Invoices PAID this month)
$sql_discount = "
    SELECT SUM(i.discount) 
    FROM invoices i
    JOIN payments p ON i.id = p.invoice_id
    WHERE strftime('%Y-%m', p.payment_date) = ?
";
$params_discount = [$period];
if ($filter_user !== 'all') {
    $sql_discount .= " AND p.received_by = ?";
    $params_discount[] = $filter_user;
}
$q_discount = $db->prepare($sql_discount);
$q_discount->execute($params_discount);
$total_discount = $q_discount->fetchColumn() ?: 0;

// NEW: Expenses for the period
$q_expenses = $db->prepare("SELECT SUM(amount) FROM expenses WHERE strftime('%Y-%m', date) = ?");
$q_expenses->execute([$period]);
$total_expenses = $q_expenses->fetchColumn() ?: 0;


// Table Data Query (UNION of Payments received this month AND Unpaid Invoices due this month)
$sql_table_p = "
    SELECT 
        'Pembayaran Masuk' as activity_type,
        p.payment_date as activity_date,
        c.name as customer_name,
        c.type as customer_type,
        c.area,
        i.id as invoice_id,
        i.due_date,
        p.amount as amount,
        'Lunas' as status
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN customers c ON i.customer_id = c.id
    WHERE strftime('%Y-%m', p.payment_date) = ?
";
$params_table = [$period];
if ($filter_user !== 'all') {
    $sql_table_p .= " AND p.received_by = ?";
    $params_table[] = $filter_user;
}

$sql_table = $sql_table_p . "
    UNION ALL
    
    SELECT
        'Tagihan Piutang' as activity_type,
        i.due_date as activity_date,
        c.name as customer_name,
        c.type as customer_type,
        c.area,
        i.id as invoice_id,
        i.due_date,
        (i.amount - i.discount) as amount,
        'Belum Lunas' as status
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE strftime('%Y-%m', i.due_date) = ? AND i.status = 'Belum Lunas'
    
    ORDER BY activity_date DESC
";
$params_table[] = $period;

$q_table = $db->prepare($sql_table);
$q_table->execute($params_table);
$report_data = $q_table->fetchAll();

if ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Laporan_Keuangan_' . $period . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Add BOM to fix UTF-8 in Excel
    fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
    
    fputcsv($output, ['Tipe Transaksi', 'Tanggal Aktivitas', 'Nama Pelanggan / Mitra', 'Area', 'No. Invoice', 'Tgl Jatuh Tempo', 'Nominal (Rp)', 'Status Pembayaran']);
    
    foreach ($report_data as $row) {
        fputcsv($output, [
            $row['activity_type'],
            $row['activity_date'],
            $row['customer_name'] . ($row['customer_type'] == 'partner' ? ' (Mitra)' : ''),
            $row['area'] ?: '-',
            'INV-' . str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT),
            $row['due_date'],
            $row['amount'],
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}

if ($action === 'print') {
    $company = $db->query("SELECT * FROM settings WHERE id=1")->fetch();
    $total_income = $lunas_tepat + $tunggakan_dibayar;
    
    // Expenses for the printed period
    $q_expenses_print = $db->prepare("SELECT * FROM expenses WHERE strftime('%Y-%m', date) = ? ORDER BY date ASC");
    $q_expenses_print->execute([$period]);
    $expenses_list = $q_expenses_print->fetchAll();
    $total_expenses_print = 0;
    foreach($expenses_list as $e) $total_expenses_print += $e['amount'];
    
    // Logo processing logic
    $logo_src = '';
    if(!empty($company['company_logo'])) {
        $logo_src = preg_match('/^http/', $company['company_logo']) ? $company['company_logo'] : '/' . str_replace(' ', '%20', $company['company_logo']);
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Laporan Keuangan - <?= $months[$filter_month] ?> <?= $filter_year ?></title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
            body { 
                font-family: 'Inter', system-ui, -apple-system, sans-serif; 
                color: #1e293b; 
                line-height: 1.6; 
                padding: 40px 60px; 
                background: #fff; 
                max-width: 1000px; 
                margin: 0 auto; 
            }
            
            /* Page Break Logic */
            .page-break { page-break-after: always; }
            
            /* Professional Header */
            .report-header { 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                padding-bottom: 25px; 
                margin-bottom: 40px; 
                border-bottom: 4px double #334155; 
            }
            .header-left { display: flex; align-items: center; gap: 20px; }
            .header-logo img { max-height: 80px; max-width: 180px; object-fit: contain; }
            .company-info h1 { margin: 0; font-size: 26px; font-weight: 800; color: #0f172a; text-transform: uppercase; }
            .company-info p { margin: 4px 0 0; font-size: 13px; color: #64748b; max-width: 350px; line-height: 1.4; }
            
            .header-right { text-align: right; }
            .report-brand { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.01em; }
            .period-label { font-size: 14px; font-weight: 600; color: #64748b; margin-top: 5px; background: #f1f5f9; padding: 4px 12px; border-radius: 6px; display: inline-block; }
            
            /* Summary Grid */
            .summary-grid { 
                display: grid; 
                grid-template-columns: repeat(2, 1fr); 
                gap: 30px; 
                margin-bottom: 50px; 
            }
            .summary-box { 
                padding: 24px; 
                border: 1px solid #e2e8f0; 
                border-radius: 12px; 
                background: #f8fafc; 
                position: relative;
                overflow: hidden;
            }
            .summary-box::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 6px; background: #334155; }
            .summary-box.danger::before { background: #ef4444; }
            .summary-box h3 { margin: 0 0 12px; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
            .summary-box .val { font-size: 28px; font-weight: 800; color: #0f172a; }
            .summary-box .subtext { font-size: 11px; color: #94a3b8; margin-top: 6px; }
            
            /* Table Styling */
            .section-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; color: #0f172a; border-left: 5px solid #334155; padding-left: 15px; }
            
            table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            th { background: #f8fafc; padding: 14px 12px; text-align: left; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; border-bottom: 2px solid #cbd5e1; }
            td { padding: 14px 12px; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
            tr:nth-child(even) { background: #fcfdfe; }
            
            .type-in { color: #10b981; font-weight: 700; }
            .type-out { color: #f59e0b; font-weight: 700; }
            
            /* Signature Section */
            .signature-area { 
                margin-top: 50px; 
                display: flex; 
                justify-content: space-between; 
                gap: 50px; 
            }
            .sig-box { flex: 1; text-align: center; }
            .sig-label { font-size: 14px; margin-bottom: 100px; color: #475569; }
            .sig-line { border-bottom: 2px solid #0f172a; width: 220px; margin: 0 auto 10px; }
            .sig-name { font-weight: 700; font-size: 14px; text-transform: uppercase; }
            
            .footer { margin-top: 50px; border-top: 1px solid #e2e8f0; padding-top: 20px; font-size: 11px; color: #94a3b8; display: flex; justify-content: space-between; }

            @media print {
                body { padding: 0; font-size: 12pt; }
                @page { margin: 1.5cm; }
                .no-print { display: none; }
                .page-break { page-break-after: always; display: block; clear: both; }
            }
        </style>
    </head>
    <body>
        <!-- Page 1: Financial Overview -->
        <div class="page-break">
            <div class="report-header">
                <div class="header-left">
                    <?php if($logo_src): ?>
                        <div class="header-logo">
                            <img src="<?= $logo_src ?>" alt="Logo">
                        </div>
                    <?php endif; ?>
                    <div class="company-info">
                        <h1><?= htmlspecialchars($company['company_name']) ?></h1>
                        <p><?= htmlspecialchars($company['company_address']) ?><br>Telp: <?= htmlspecialchars($company['company_contact']) ?></p>
                    </div>
                </div>
                <div class="header-right">
                    <div class="report-brand">IKHTISAR KEUANGAN</div>
                    <div class="period-label">PERIODE: <?= strtoupper($months[$filter_month]) ?> <?= $filter_year ?></div>
                    <?php if($filter_user !== 'all'): 
                        $uname = 'Unknown';
                        foreach($available_users as $u) { if($u['id'] == $filter_user) { $uname = $u['name']; break; } }
                    ?>
                        <div style="margin-top:5px; font-size:12px; font-weight:700; color:#1e293b; text-transform:uppercase;">DITERIMA OLEH: <?= $uname ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-box">
                    <h3>Total Pendapatan Terkumpul</h3>
                    <div class="val">Rp <?= number_format($total_income, 0, ',', '.') ?></div>
                    <div style="margin-top:10px; border-top:1px solid #e2e8f0; padding-top:10px;">
                        <div style="display:flex; justify-content:space-between; font-size:13px; color:#475569;">
                            <span>Lunas Tepat Waktu:</span>
                            <span>Rp <?= number_format($lunas_tepat, 0, ',', '.') ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:13px; color:#475569; margin-top:4px;">
                            <span>Pelunasan Tunggakan:</span>
                            <span>Rp <?= number_format($tunggakan_dibayar, 0, ',', '.') ?></span>
                        </div>
                    </div>
                </div>
                <div class="summary-box danger">
                    <h3>Total Piutang Berjalan</h3>
                    <div class="val" style="color:#ef4444;">Rp <?= number_format($belum_bayar, 0, ',', '.') ?></div>
                    <div class="subtext">Tagihan Belum Lunas (Bulan Ini)</div>
                </div>
                <div class="summary-box danger" style="border-top:4px solid #ef4444;">
                    <h3>Total Pengeluaran</h3>
                    <div class="val" style="color:#ef4444;">Rp <?= number_format($total_expenses_print, 0, ',', '.') ?></div>
                    <div class="subtext">Operasional & Belanja</div>
                </div>
                <div class="summary-box" style="border-top:4px solid #f59e0b;">
                    <h3>Total Potongan / Diskon</h3>
                    <div class="val" style="color:#f59e0b;">Rp <?= number_format($total_discount, 0, ',', '.') ?></div>
                    <div class="subtext">Restitusi & Pengurangan</div>
                </div>
                <div class="summary-box" style="border-top:4px solid #10b981; background:#f0fdf4;">
                    <h3>Estimasi Laba Bersih</h3>
                    <div class="val" style="color:#10b981;">Rp <?= number_format($total_income - $total_expenses_print, 0, ',', '.') ?></div>
                    <div class="subtext">Income - Pengeluaran</div>
                </div>
            </div>

            <div style="margin-top:20px; padding:20px; border-radius:10px; background:#f1f5f9; text-align:center; font-size:14px; color:#475569;">
                <i class="fas fa-info-circle"></i> Rincian lengkap seluruh transaksi tercatat pada halaman berikutnya.
            </div>
        </div>

        <!-- Page 2+: Transaction Details -->
        <div class="report-header no-print-top" style="border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; padding-bottom: 15px;">
            <div class="header-left">
                <div class="company-info">
                    <h1 style="font-size:18px;"><?= htmlspecialchars($company['company_name']) ?></h1>
                </div>
            </div>
            <div class="header-right">
                <div class="report-brand" style="font-size: 16px;">DETAIL AKTIVITAS TRANSAKSI</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="100">TANGGAL</th>
                    <th>AKTIVITAS</th>
                    <th>PELANGGAN / MITRA</th>
                    <th width="120">NO. INVOICE</th>
                    <th width="140" style="text-align:right;">NOMINAL</th>
                    <th width="100">STATUS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($report_data as $row): ?>
                <tr>
                    <td style="color:#64748b; font-weight:500;"><?= date('d/m/y', strtotime($row['activity_date'])) ?></td>
                    <td class="<?= $row['status'] == 'Lunas' ? 'type-in' : 'type-out' ?>"><?= strtoupper($row['activity_type']) ?></td>
                    <td>
                        <div style="font-weight:700;"><?= htmlspecialchars($row['customer_name']) . ($row['customer_type'] == 'partner' ? ' (Mitra)' : '') ?></div>
                    </td>
                    <td style="font-family:'Courier New', Courier, monospace; font-weight:700;">INV-<?= str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT) ?></td>
                    <td style="font-weight:800; text-align:right; font-size:14px;">Rp <?= number_format($row['amount'], 0, ',', '.') ?></td>
                    <td style="font-weight:700; <?= $row['status']=='Lunas'?'color:#10b981;':'color:#ef4444;' ?>"><?= strtoupper($row['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if(!empty($expenses_list)): ?>
        <div class="report-header" style="border-bottom: 2px solid #e2e8f0; margin: 40px 0 20px 0; padding-bottom: 15px;">
            <div class="header-left">
                <div class="company-info">
                    <h1 style="font-size:18px;">RINCIAN PENGELUARAN</h1>
                </div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th width="100">TANGGAL</th>
                    <th>KATEGORI</th>
                    <th>KETERANGAN</th>
                    <th width="140" style="text-align:right;">NOMINAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($expenses_list as $e): ?>
                <tr>
                    <td style="color:#64748b; font-weight:500;"><?= date('d/m/y', strtotime($e['date'])) ?></td>
                    <td style="font-weight:700; color:#ef4444;"><?= strtoupper($e['category']) ?></td>
                    <td><?= htmlspecialchars($e['description'] ?: '-') ?></td>
                    <td style="font-weight:800; text-align:right; font-size:14px; color:#ef4444;">Rp <?= number_format($e['amount'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Signature Section at the Very End -->
        <div class="signature-area">
            <div class="sig-box">
                <div class="sig-label">Finance,</div>
                <div class="sig-line"></div>
                <div class="sig-name">&nbsp;</div>
            </div>
            <div class="sig-box">
                <div class="sig-label">Direktur,</div>
                <div class="sig-line"></div>
                <div class="sig-name">&nbsp;</div>
            </div>
        </div>

        <div class="footer">
            <div>Dicetak oleh: <?= htmlspecialchars($_SESSION['user_name'] ?? 'System') ?></div>
            <div>Waktu Cetak: <?= date('d/m/Y H:i:s') ?> WIB • Antigravity Billing System</div>
        </div>

        <script>
            window.onload = function() { window.print(); }
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>

<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
        <h3 style="font-size:20px; margin:0;"><i class="fas fa-chart-line"></i> Laporan Keuangan</h3>
        
        <form method="GET" action="index.php" style="display:flex; gap:10px; align-items:flex-end;">
            <input type="hidden" name="page" value="admin_reports">
            <div>
                <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:5px;">Pilih Bulan</label>
                <select name="month" class="form-control" style="padding:8px 12px; width:150px;">
                    <?php foreach($months as $m_num => $m_name): ?>
                        <option value="<?= $m_num ?>" <?= $filter_month == $m_num ? 'selected' : '' ?>><?= $m_name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:5px;">Pilih Tahun</label>
                <select name="year" class="form-control" style="padding:8px 12px; width:100px;">
                    <?php for($i = date('Y') - 2; $i <= date('Y') + 1; $i++): ?>
                        <option value="<?= $i ?>" <?= $filter_year == $i ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:5px;">Diterima Oleh</label>
                <select name="user_id" class="form-control" style="padding:8px 12px; width:160px;">
                    <option value="all">-- Semua --</option>
                    <?php foreach($available_users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>><?= $u['name'] ?> (<?= ucfirst($u['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="height: 38px;"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="stat-card glass-panel" style="background:linear-gradient(135deg, rgba(37, 99, 235, 0.1), rgba(37, 99, 235, 0.05)); border: 2px solid var(--primary);">
            <div class="stat-title" style="color:var(--primary); font-weight:800;"><i class="fas fa-wallet"></i> Total Pendapatan Terkumpul</div>
            <div class="stat-value" style="color:var(--primary);">Rp <?= number_format($lunas_tepat + $tunggakan_dibayar, 0, ',', '.') ?></div>
            <div style="font-size:12px; color:var(--text-secondary); margin-top:10px;">Total uang masuk bulan ini.</div>
        </div>

        <div class="stat-card glass-panel" style="background:rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3);">
            <div class="stat-title" style="color:var(--success)"><i class="fas fa-check-circle"></i> Tepat Waktu</div>
            <div class="stat-value text-success">Rp <?= number_format($lunas_tepat, 0, ',', '.') ?></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:10px;">Dari tagihan <?= $months[$filter_month] ?>.</div>
        </div>
        
        <div class="stat-card glass-panel" style="background:rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.3);">
            <div class="stat-title" style="color:#60a5fa"><i class="fas fa-hand-holding-usd"></i> Tunggakan</div>
            <div class="stat-value" style="color:#60a5fa">Rp <?= number_format($tunggakan_dibayar, 0, ',', '.') ?></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:10px;">Pelunasan hutang lama.</div>
        </div>

        <div class="stat-card glass-panel" style="background:rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3);">
            <div class="stat-title" style="color:var(--warning)"><i class="fas fa-clock"></i> Belum Lunas</div>
            <div class="stat-value text-warning">Rp <?= number_format($belum_bayar, 0, ',', '.') ?></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:10px;">Piutang bulan ini.</div>
        </div>

        <div class="stat-card glass-panel" style="background:rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3);">
            <div class="stat-title" style="color:var(--danger)"><i class="fas fa-wallet"></i> Pengeluaran</div>
            <div class="stat-value text-danger">Rp <?= number_format($total_expenses, 0, ',', '.') ?></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:10px;">Biaya operasional & barang.</div>
        </div>

        <div class="stat-card glass-panel" style="background:rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.3);">
            <div class="stat-title" style="color:var(--warning)"><i class="fas fa-gift"></i> Total Potongan</div>
            <div class="stat-value text-warning">Rp <?= number_format($total_discount, 0, ',', '.') ?></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:10px;">Total diskon yang diberikan.</div>
        </div>

        <div class="stat-card glass-panel" style="background:rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3);">
            <div class="stat-title" style="color:var(--success)"><i class="fas fa-hand-holding-usd"></i> Laba Bersih</div>
            <div class="stat-value text-success">Rp <?= number_format(($lunas_tepat + $tunggakan_dibayar) - $total_expenses, 0, ',', '.') ?></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:10px;">Dana Masuk - Pengeluaran.</div>
        </div>
    </div>
    
    <!-- Table Activity -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin: 30px 0 15px 0; flex-wrap:wrap; gap:10px;">
        <h4 style="margin:0;">Rincian Transaksi - <?= $months[$filter_month] ?> <?= $filter_year ?></h4>
        <div style="display:flex; gap:10px;">
            <a href="index.php?page=admin_report_assets" class="btn btn-sm btn-ghost" style="border: 1px solid var(--glass-border);"><i class="fas fa-boxes"></i> Laporan Aset</a>
            <a href="index.php?page=admin_reports&action=print&month=<?= $filter_month ?>&year=<?= $filter_year ?>&user_id=<?= $filter_user ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-print"></i> Cetak Laporan (HTML)</a>
            <a href="index.php?page=admin_reports&action=export&month=<?= $filter_month ?>&year=<?= $filter_year ?>&user_id=<?= $filter_user ?>" class="btn btn-sm btn-success"><i class="fas fa-file-excel"></i> Export Excel (CSV)</a>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Waktu / Transaksi</th>
                    <th>Nama Pelanggan</th>
                    <th>No. Invoice</th>
                    <th>Nominal</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($report_data as $row): ?>
                <tr>
                    <td>
                        <div style="font-weight:bold; color: <?= $row['activity_type'] == 'Pembayaran Masuk' ? 'var(--success)' : 'var(--warning)' ?>;"><?= $row['activity_type'] ?></div>
                        <div style="font-size:12px; color:var(--text-secondary);"><?= date('d M Y', strtotime($row['activity_date'])) ?></div>
                        <?php 
                        // Check if this payment is for an older debt
                        if($row['activity_type'] == 'Pembayaran Masuk' && date('Y-m', strtotime($row['due_date'])) < date('Y-m', strtotime($row['activity_date']))): 
                        ?>
                            <div style="margin-top:4px;"><span class="badge" style="background:#fff7ed; color:#c2410c; border:1px solid #fdba74; font-size:9px; padding:2px 5px;">PELUNASAN TUNGGAKAN</span></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($row['customer_name']) ?> <?php if($row['customer_type']=='partner') echo '<span class="badge badge-warning" style="font-size:10px;">Mitra</span>'; ?></div>
                        <div style="font-size:12px; color:var(--text-secondary);"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($row['area'] ?: '-') ?></div>
                    </td>
                    <td>
                        <div style="font-family:monospace;">INV-<?= str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT) ?></div>
                        <div style="font-size:12px; color:var(--text-secondary);">Jt: <?= date('d M', strtotime($row['due_date'])) ?></div>
                    </td>
                    <td style="font-weight:bold; font-size:15px;">
                        Rp <?= number_format($row['amount'], 0, ',', '.') ?>
                    </td>
                    <td>
                        <?php if($row['status'] == 'Lunas'): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> Lunas</span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="background:rgba(239,68,68,0.2); color:#ef4444; border:1px solid #ef4444;">Belum Lunas</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($report_data) == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 30px;">Tidak ada transaksi pembayaran atau tagihan di bulan ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
