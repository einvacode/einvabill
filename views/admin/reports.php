<?php
$action = $_GET['action'] ?? 'view';
$u_id = $_SESSION['user_id'];
$u_role = app_scope_role();

// Admin delete handler for report transactions (payments, invoices, expenses)
if ($action === 'delete_tx' && ($u_role === 'admin')) {
    $tx = $_GET['tx'] ?? '';
    $del_id = intval($_GET['id'] ?? 0);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    if ($del_id > 0) {
        try {
            if ($tx === 'payment') {
                $before = $db->query("SELECT p.amount, p.payment_date, c.name FROM payments p LEFT JOIN invoices i ON i.id = p.invoice_id LEFT JOIN customers c ON c.id = i.customer_id WHERE p.id = " . $del_id)->fetch(PDO::FETCH_ASSOC) ?: [];
                $db->prepare("DELETE FROM payments WHERE id = ? AND tenant_id = ?")->execute([$del_id, $tenant_id]);
                audit_log($db, 'payment_delete', 'payments', $del_id, 'Menghapus pembayaran Rp ' . number_format((float)($before['amount'] ?? 0), 0, ',', '.') . ' dari ' . ($before['name'] ?? 'pelanggan') . ' (' . ($before['payment_date'] ?? '-') . ')', $before);
            } elseif ($tx === 'invoice') {
                $before = $db->query("SELECT i.amount, i.due_date, i.status, c.name FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = " . $del_id)->fetch(PDO::FETCH_ASSOC) ?: [];
                // cascade delete: payments, items, invoice
                $db->prepare("DELETE FROM payments WHERE invoice_id = ? AND tenant_id = ?")->execute([$del_id, $tenant_id]);
                $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$del_id]);
                $db->prepare("DELETE FROM invoices WHERE id = ? AND tenant_id = ?")->execute([$del_id, $tenant_id]);
                audit_log($db, 'invoice_delete', 'invoices', $del_id, 'Menghapus tagihan INV-' . str_pad((string)$del_id, 5, '0', STR_PAD_LEFT) . ' Rp ' . number_format((float)($before['amount'] ?? 0), 0, ',', '.') . ' atas nama ' . ($before['name'] ?? '-') . ', beserta pembayarannya', $before);
            } elseif ($tx === 'expense') {
                $before = $db->query("SELECT category, amount, date, description FROM expenses WHERE id = " . $del_id)->fetch(PDO::FETCH_ASSOC) ?: [];
                $db->prepare("DELETE FROM expenses WHERE id = ? AND tenant_id = ?")->execute([$del_id, $tenant_id]);
                audit_log($db, 'expense_delete', 'expenses', $del_id, 'Menghapus pengeluaran ' . ($before['category'] ?? '-') . ' Rp ' . number_format((float)($before['amount'] ?? 0), 0, ',', '.') . ' (' . ($before['date'] ?? '-') . ')', $before);
            }
            header('Location: index.php?page=admin_reports&msg=deleted');
            exit;
        } catch (Exception $e) {
            header('Location: index.php?page=admin_reports&msg=delete_error&err=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

$filter_month = $_GET['month'] ?? date('m');
$filter_year = $_GET['year'] ?? date('Y');
$filter_user = $_GET['user_id'] ?? 'all';

// Default to a full year when the user selects a year and doesn't provide explicit dates.
$has_date_filter = !empty($_GET['date_from']) || !empty($_GET['date_to']);
if ($filter_year && !$has_date_filter) {
    $date_from = $filter_year . '-01-01';
    $date_to = $filter_year . '-12-31';
} else {
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
}

// Scoping Logic (Multi-tenancy & Hierarchical Isolation)
$tenant_id = $_SESSION['tenant_id'] ?? 1;

// Identify partners in this tenant (to exclude their customers from Admin view)
$partner_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
$partner_list = !empty($partner_ids) ? implode(',', $partner_ids) : '0';

$scope_inner = " c.tenant_id = $tenant_id ";
$scope_where = " AND " . $scope_inner . " ";

// Apply hierarchical isolation for Admin/Collector
if ($u_role === 'admin' || $u_role === 'collector') {
    $scope_where .= " AND (c.created_by NOT IN ($partner_list) OR c.created_by = 0 OR c.created_by IS NULL) ";
} elseif ($u_role === 'partner') {
    $scope_where .= " AND c.created_by = $u_id ";
}

// For admin include external/temporary invoices; for partners/collectors only their own customers
$scope_with_external = " AND i.tenant_id = $tenant_id ";

$exp_scope = " AND tenant_id = $tenant_id ";
if ($u_role === 'partner') {
    $exp_scope .= " AND created_by = $u_id ";
}

// Date range filters
$period_display = date('d/m/Y', strtotime($date_from)) . ' - ' . date('d/m/Y', strtotime($date_to));

$sql_date_from = $date_from . ' 00:00:00';
$sql_date_to = $date_to . ' 23:59:59';

// Available users for filter (Admin only)
$available_users = [];
if ($u_role === 'admin') {
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $available_users = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'collector') AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
}

    // 1. Tepat Waktu
    $sql_lunas_tepat = "
        SELECT SUM(p.amount) as total
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN customers c ON i.customer_id = c.id
        WHERE p.payment_date BETWEEN ? AND ?
        AND datetime(p.payment_date) <= datetime(i.due_date)
        $scope_with_external
        $scope_where
    ";
    $params_lunas_tepat = [$sql_date_from, $sql_date_to];
    if ($filter_user !== 'all' && $u_role === 'admin') {
        $sql_lunas_tepat .= " AND p.received_by = ? ";
        $params_lunas_tepat[] = $filter_user;
    }
    $q_lunas_tepat = $db->prepare($sql_lunas_tepat);
    $q_lunas_tepat->execute($params_lunas_tepat);
    $lunas_tepat = $q_lunas_tepat->fetchColumn() ?: 0;

    // 2. Pembayaran Tunggakan (Terlambat)
    $sql_tunggakan_dibayar = "
        SELECT SUM(p.amount) as total
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN customers c ON i.customer_id = c.id
        WHERE p.payment_date BETWEEN ? AND ?
        AND datetime(p.payment_date) > datetime(i.due_date)
        $scope_with_external
        $scope_where
    ";
    $params_tunggakan_dibayar = [$sql_date_from, $sql_date_to];
    if ($filter_user !== 'all' && $u_role === 'admin') {
        $sql_tunggakan_dibayar .= " AND p.received_by = ? ";
        $params_tunggakan_dibayar[] = $filter_user;
    }
    $q_tunggakan_dibayar = $db->prepare($sql_tunggakan_dibayar);
    $q_tunggakan_dibayar->execute($params_tunggakan_dibayar);
    $tunggakan_dibayar = $q_tunggakan_dibayar->fetchColumn() ?: 0;

    // 3. Belum Bayar (Piutang Periode Ini)
    $q_belum_bayar = $db->prepare("
        SELECT SUM(i.amount - i.discount) as total
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE ( (i.due_date BETWEEN ? AND ?) OR ((i.created_via IS NOT NULL AND i.created_via <> '') AND (i.created_at BETWEEN ? AND ?)) ) 
        AND i.status = 'Belum Lunas'
        $scope_with_external
        $scope_where
    ");
    $q_belum_bayar->execute([$date_from, $date_to, $sql_date_from, $sql_date_to]);
    $belum_bayar = $q_belum_bayar->fetchColumn() ?: 0;

    // 4. Tertunggak Lama (Total Piutang Berjalan)
    $q_tertunggak_lama = $db->prepare("
        SELECT SUM(i.amount - i.discount) as total
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.due_date < ? 
        AND i.status = 'Belum Lunas'
        $scope_with_external
        $scope_where
    ");
    $q_tertunggak_lama->execute([$date_from]);
    $tertunggak_lama = $q_tertunggak_lama->fetchColumn() ?: 0;

    // 5. Total Discount Scoped
    $sql_discount = "
        SELECT SUM(t.d) as total_discount FROM (
            SELECT i.id, COALESCE(i.discount,0) as d
            FROM invoices i
            JOIN payments p ON i.id = p.invoice_id
            JOIN customers c ON i.customer_id = c.id
            WHERE p.payment_date BETWEEN ? AND ?
            $scope_where
    ";
    $params_discount = [$sql_date_from, $sql_date_to];
    if ($filter_user !== 'all' && $u_role === 'admin') {
        $sql_discount .= " AND p.received_by = ? ";
        $params_discount[] = $filter_user;
    }
    $sql_discount .= " GROUP BY i.id ) t ";
    $q_discount = $db->prepare($sql_discount);
    $q_discount->execute($params_discount);
    $total_discount = $q_discount->fetchColumn() ?: 0;

$q_expenses = $db->prepare("SELECT SUM(amount) FROM expenses WHERE date BETWEEN ? AND ? $exp_scope");
$q_expenses->execute([$date_from, $date_to]);
$total_expenses = $q_expenses->fetchColumn() ?: 0;

    // Table Scoping
    $sql_table_p = "
        SELECT 
            'Pembayaran Masuk' as activity_type,
            p.payment_date as activity_date,
            c.name as customer_name,
            c.type as customer_type,
            c.area,
            c.contact,
            c.customer_code,
            c.package_name,
            c.monthly_fee,
            i.id as invoice_id,
            p.id as payment_id,
            i.due_date,
            p.amount as amount,
            'Lunas' as status
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN customers c ON i.customer_id = c.id
        WHERE p.payment_date BETWEEN ? AND ?
        $scope_with_external
        $scope_where
        " . (($filter_user !== 'all' && $u_role === 'admin') ? " AND p.received_by = ? " : "") . "
    ";

    $items_per_page = 50;
    $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    $sql_count = "
        SELECT COUNT(*) FROM (
            SELECT 1 FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id 
            WHERE p.payment_date BETWEEN ? AND ? $scope_with_external $scope_where " . (($filter_user !== 'all' && $u_role === 'admin') ? " AND p.received_by = ?" : "") . "
            UNION ALL
            SELECT 1 FROM invoices i JOIN customers c ON i.customer_id = c.id 
            WHERE ( (i.due_date BETWEEN ? AND ?) OR ((i.created_via IS NOT NULL AND i.created_via <> '') AND (i.created_at BETWEEN ? AND ?)) )
            AND i.status = 'Belum Lunas' $scope_with_external $scope_where
        ) AS total
    ";
    $params_count = array_merge([$sql_date_from, $sql_date_to], (($filter_user !== 'all' && $u_role === 'admin') ? [$filter_user] : []), [$date_from, $date_to, $sql_date_from, $sql_date_to]);
    $total_rows = $db->prepare($sql_count);
    $total_rows->execute($params_count);
    $total_count = $total_rows->fetchColumn() ?: 0;
    $total_pages = ceil($total_count / $items_per_page);

    $limit_sql = ($action === 'view') ? " LIMIT $items_per_page OFFSET $offset " : "";

    $sql_table = $sql_table_p . "
        UNION ALL
        
        SELECT
            'Tagihan Piutang' as activity_type,
            i.due_date as activity_date,
            c.name as customer_name,
            c.type as customer_type,
            c.area,
            c.contact,
            c.customer_code,
            c.package_name,
            c.monthly_fee,
            i.id as invoice_id,
            NULL as payment_id,
            i.due_date,
            (i.amount - i.discount) as amount,
            'Belum Lunas' as status
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE ( (i.due_date BETWEEN ? AND ?) OR ((i.created_via IS NOT NULL AND i.created_via <> '') AND (i.created_at BETWEEN ? AND ?)) ) 
        AND i.status = 'Belum Lunas'
        $scope_with_external
        $scope_where
        
        ORDER BY activity_date DESC
        $limit_sql
    ";

    $params_final = array_merge([
        $sql_date_from,
        $sql_date_to
    ], (($filter_user !== 'all' && $u_role === 'admin') ? [$filter_user] : []), [
        $date_from,
        $date_to,
        $sql_date_from,
        $sql_date_to
    ]);
$q_table = $db->prepare($sql_table);
$q_table->execute($params_final);
$report_data = $q_table->fetchAll();

if ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Laporan_Keuangan_' . $date_from . '_sd_' . $date_to . '.csv"');
    $output = fopen('php://output', 'w');
    
    // Add BOM to fix UTF-8 in Excel
    fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
    
    if ($u_role === 'admin') {
        fputcsv($output, ['Tipe Transaksi', 'Tanggal Aktivitas', 'Nama Pelanggan / Mitra', 'Area', 'Nomor Invoice', 'Tanggal Jatuh Tempo', 'Nominal (Rp)', 'Status Pembayaran']);
    } else {
        fputcsv($output, ['Tipe Transaksi', 'Tanggal Aktivitas', 'Nama Pelanggan / Mitra', 'Nomor Invoice', 'Tanggal Jatuh Tempo', 'Nominal (Rp)', 'Status Pembayaran']);
    }
    
    foreach ($report_data as $row) {
        if ($u_role === 'admin') {
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
        } else {
            fputcsv($output, [
                $row['activity_type'],
                $row['activity_date'],
                $row['customer_name'] . ($row['customer_type'] == 'partner' ? ' (Mitra)' : ''),
                'Invoice-' . str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT),
                $row['due_date'],
                $row['amount'],
                $row['status']
            ]);
        }
    }
    fclose($output);
    exit;
}

// Laporan keuangan untuk SPT Tahunan: dua berkas terpisah, dihitung di app/finance_report.php.
if ($action === 'print_profit_loss' || $action === 'print_position') {
    if ($u_role !== 'admin') { header('Location: index.php?page=admin_reports'); exit; }
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $fs = finance_statements($db, (int)$tenant_id, $date_from, $date_to, $scope_where, $scope_with_external);
    $company = $fs['meta']['company'] ?: ['company_name' => 'ISP', 'company_address' => '', 'company_contact' => '', 'company_logo' => ''];
    $pl = $fs['pl']; $bs = $fs['bs'];
    $periode = 'Untuk periode ' . fin_tanggal($date_from) . ' sampai ' . fin_tanggal($date_to);

    if ($action === 'print_profit_loss') {
        fin_print_open($company, 'Laporan Laba Rugi', $periode);
        ?>
        <div class="section">Pendapatan usaha</div>
        <table>
            <?php foreach ($pl['revenue_lines'] as $r): ?>
            <tr><td class="indent"><?= htmlspecialchars($r['jenis']) ?></td><td class="num"><?= fin_rp($r['total']) ?></td></tr>
            <?php endforeach; if (empty($pl['revenue_lines'])): ?>
            <tr><td class="indent muted">Tidak ada penerimaan dalam periode ini</td><td class="num">Rp 0</td></tr>
            <?php endif; ?>
            <tr class="sub"><td>Jumlah pendapatan usaha</td><td class="num"><?= fin_rp($pl['revenue']) ?></td></tr>
        </table>

        <div class="section">Beban usaha</div>
        <table>
            <?php foreach ($pl['expense_lines'] as $e): ?>
            <tr><td class="indent"><?= htmlspecialchars($e['kategori']) ?></td><td class="num"><?= fin_rp($e['total']) ?></td></tr>
            <?php endforeach; ?>
            <tr><td class="indent">Beban penyusutan aset tetap</td><td class="num"><?= fin_rp($pl['depreciation']) ?></td></tr>
            <tr class="sub"><td>Jumlah beban usaha</td><td class="num"><?= fin_rp($pl['expenses'] + $pl['depreciation']) ?></td></tr>
        </table>

        <table>
            <tr class="sub"><td>Laba (rugi) usaha sebelum pajak</td><td class="num"><?= fin_rp($pl['operating_profit']) ?></td></tr>
            <tr><td class="indent">PPh final <?= rtrim(rtrim(number_format($fs['meta']['tax_rate'], 2, ',', '.'), '0'), ',') ?>% dari peredaran bruto (PP 55/2022)</td><td class="num">(<?= fin_rp($pl['tax']) ?>)</td></tr>
            <tr class="total"><td>Laba (rugi) bersih setelah pajak</td><td class="num"><?= fin_rp($pl['net_profit']) ?></td></tr>
        </table>

        <div class="note">
            Catatan: pendapatan diakui saat pembayaran diterima (basis kas); beban diakui saat dibayar. Penyusutan aset tetap memakai metode garis lurus
            <?= (int)$fs['meta']['life_years'] ?> tahun tanpa nilai sisa, dihitung dari harga perolehan pada menu Aset Perusahaan.
            PPh final dihitung dari peredaran bruto untuk wajib pajak yang memakai tarif PP 55/2022 dan bersifat informasi; sesuaikan dengan status perpajakan perusahaan.
        </div>
        <?php
        fin_print_close($company);
        exit;
    }

    fin_print_open($company, 'Laporan Posisi Keuangan', 'Per ' . fin_tanggal($date_to));
    ?>
    <div class="section">Aset</div>
    <table>
        <tr><td colspan="2"><strong>Aset lancar</strong></td></tr>
        <tr><td class="indent">Kas dan setara kas</td><td class="num"><?= fin_rp($bs['cash']) ?></td></tr>
        <tr><td class="indent">Piutang usaha</td><td class="num"><?= fin_rp($bs['receivables']) ?></td></tr>
        <tr class="sub"><td>Jumlah aset lancar</td><td class="num"><?= fin_rp($bs['cash'] + $bs['receivables']) ?></td></tr>
        <tr><td colspan="2"><strong>Aset tetap</strong></td></tr>
        <tr><td class="indent">Harga perolehan</td><td class="num"><?= fin_rp($bs['fixed_cost']) ?></td></tr>
        <tr><td class="indent">Akumulasi penyusutan</td><td class="num">(<?= fin_rp($bs['fixed_accum']) ?>)</td></tr>
        <tr class="sub"><td>Nilai buku aset tetap</td><td class="num"><?= fin_rp($bs['fixed_net']) ?></td></tr>
        <tr class="total"><td>Jumlah aset</td><td class="num"><?= fin_rp($bs['total_assets']) ?></td></tr>
    </table>

    <div class="section">Liabilitas dan ekuitas</div>
    <table>
        <tr><td colspan="2"><strong>Liabilitas</strong></td></tr>
        <tr><td class="indent">Utang usaha / pinjaman</td><td class="num"><?= fin_rp($bs['liabilities']) ?></td></tr>
        <tr class="sub"><td>Jumlah liabilitas</td><td class="num"><?= fin_rp($bs['liabilities']) ?></td></tr>
        <tr><td colspan="2"><strong>Ekuitas</strong></td></tr>
        <tr><td class="indent">Modal disetor</td><td class="num"><?= fin_rp($bs['paid_capital']) ?></td></tr>
        <tr><td class="indent">Laba ditahan (termasuk laba periode berjalan)</td><td class="num"><?= fin_rp($bs['retained']) ?></td></tr>
        <tr class="sub"><td>Jumlah ekuitas</td><td class="num"><?= fin_rp($bs['paid_capital'] + $bs['retained']) ?></td></tr>
        <tr class="total"><td>Jumlah liabilitas dan ekuitas</td><td class="num"><?= fin_rp($bs['total_liab_equity']) ?></td></tr>
    </table>

    <?php if (!empty($bs['asset_lines'])): ?>
    <div class="section">Rincian aset tetap</div>
    <table>
        <tr><th>Aset</th><th>Perolehan</th><th class="num">Harga perolehan</th><th class="num">Akum. penyusutan</th><th class="num">Nilai buku</th></tr>
        <?php foreach ($bs['asset_lines'] as $a): ?>
        <tr><td><?= htmlspecialchars($a['name']) ?> <span class="muted">(<?= htmlspecialchars($a['type']) ?>)</span></td><td><?= date('d/m/Y', strtotime($a['acquired'])) ?></td><td class="num"><?= fin_rp($a['cost']) ?></td><td class="num"><?= fin_rp($a['accum']) ?></td><td class="num"><?= fin_rp($a['book']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <div class="note">
        Catatan: kas dan setara kas adalah jumlah saldo seluruh akun pada menu Kas &amp; Bank per tanggal laporan (saldo awal tiap akun ditambah penerimaan, dikurangi pengeluaran dan transfer keluar), termasuk uang yang masih dipegang petugas. Piutang usaha adalah tagihan jatuh tempo yang belum dilunasi per tanggal laporan.
        Modal disetor dan utang diisi pada menu Pengaturan; laba ditahan merupakan selisih yang menyeimbangkan neraca.
    </div>
    <?php
    fin_print_close($company);
    exit;
}

if ($action === 'print') {
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $company = $db->query("SELECT * FROM settings WHERE tenant_id = $tenant_id")->fetch();
    if (!$company) $company = ['company_name' => 'ISP', 'company_address' => '', 'company_contact' => '', 'company_logo' => ''];
    $total_income = $lunas_tepat + $tunggakan_dibayar;
    
    // Expenses for the printed period (scoped: non-admin only sees own expenses)
    $exp_scope_print = ($u_role === 'admin') ? "" : " AND e.created_by = " . intval($u_id);
    $q_expenses_print = $db->prepare("
        SELECT e.*, u.name as creator_name, u.role as creator_role 
        FROM expenses e
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.date BETWEEN ? AND ? $exp_scope_print
        ORDER BY e.date ASC
    ");
    $q_expenses_print->execute([$date_from, $date_to]);
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
        <title>Laporan Keuangan - <?= $period_display ?></title>
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
                        <p><?= htmlspecialchars($company['company_address']) ?><br>Telepon: <?= htmlspecialchars($company['company_contact']) ?></p>
                    </div>
                </div>
                <div class="header-right">
                    <div class="report-brand">IKHTISAR KEUANGAN</div>
                    <div class="period-label">PERIODE: <?= strtoupper($period_display) ?></div>
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
                            <span style="color:var(--primary);"><?= date('m/Y') ?></span>
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
                    <div class="subtext">Tagihan Belum Lunas (Jatuh Tempo Periode Ini)</div>
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
                    <th width="120">NOMOR INVOICE</th>
                    <th width="140" style="text-align:right;">NOMINAL</th>
                    <th width="100">STATUS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($report_data as $row): ?>
                <tr>
                    <td style="color:#64748b; font-weight:500;"><?= date('d/m/Y', strtotime($row['activity_date'])) ?></td>
                    <td class="<?= $row['status'] == 'Lunas' ? 'type-in' : 'type-out' ?>"><?= strtoupper($row['activity_type']) ?></td>
                    <td>
                        <div style="font-weight:700;"><?= htmlspecialchars($row['customer_name']) . ($row['customer_type'] == 'partner' ? ' (Mitra)' : '') ?></div>
                    </td>
                    <td style="font-family:'Courier New', Courier, monospace; font-weight:700;">Invoice-<?= str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT) ?></td>
                    <td style="font-weight:800; text-align:right; font-size:14px;">Rp <?= number_format($row['amount'], 0, ',', '.') ?></td>
                    <td style="font-weight:700; <?= $row['status']=='Lunas'?'color:#10b981;':'color:#ef4444;' ?>">
                        <?= strtoupper($row['status']) ?>
                        <span class="compact-inline-actions">
                        <?php if($row['status'] == 'Lunas'): 
                            $settings = $db->query("SELECT wa_template_paid, company_name, site_url FROM settings WHERE id=1")->fetch();
                            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $row['contact']));
                            $cust_id_display = $row['customer_code'] ?: str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT);
                            $portal_link = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . $cust_id_display;
                            
                            $receipt_msg = str_replace(
                                ['{nama}', '{total_bayar}', '{bulan}', '{link_tagihan}', '{perusahaan}'], 
                                [$row['customer_name'], 'Rp ' . number_format($row['amount'], 0, ',', '.'), date('m/Y', strtotime($row['due_date'])), $portal_link, $settings['company_name']], 
                                $settings['wa_template_paid'] ?: "Halo {nama}, pembayaran {total_bayar} sudah lunas. Cek nota: {link_tagihan}"
                            );
                            $wa_link = "https://api.whatsapp.com/send?phone=$wa_num&text=" . urlencode($receipt_msg);
                        ?>
                            <button onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $wa_link ?>', this)" class="btn btn-xs btn-ghost" style="color:#25D366; padding:0 5px; margin-left:5px;" title="Kirim Ulang Nota">
                                <i class="fab fa-whatsapp"></i>
                            </button>
                            <a href="index.php?page=invoice_print&id=<?= $row['invoice_id'] ?>" target="_blank" class="btn btn-xs btn-ghost" style="color:var(--primary); padding:0 5px; margin-left:5px;" title="Cetak Kuitansi">
                                <i class="fas fa-print"></i>
                            </a>
                            <?php if($_SESSION['user_role'] === 'admin'): ?>
                                <?php if($row['activity_type'] == 'Pembayaran Masuk' && !empty($row['payment_id'])): ?>
                                    <a data-method="post" href="index.php?page=admin_reports&action=delete_tx&tx=payment&id=<?= intval($row['payment_id']) ?>" class="btn btn-xs btn-danger" style="margin-left:6px; padding:0 5px;" onclick="return confirm('Hapus pembayaran ini? Semua perubahan akan permanent.')" title="Hapus Pembayaran"><i class="fas fa-trash"></i></a>
                                <?php else: ?>
                                    <a data-method="post" href="index.php?page=admin_reports&action=delete_tx&tx=invoice&id=<?= intval($row['invoice_id']) ?>" class="btn btn-xs btn-danger" style="margin-left:6px; padding:0 5px;" onclick="return confirm('Hapus invoice ini beserta item dan pembayaran terkait?')" title="Hapus Invoice"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        </span>
                    </td>
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
                    <th>USER</th>
                    <th>KATEGORI</th>
                    <th>KETERANGAN</th>
                    <th width="140" style="text-align:right;">NOMINAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($expenses_list as $e): ?>
                <tr>
                    <td style="color:#64748b; font-weight:500;"><?= date('d/m/Y', strtotime($e['date'])) ?></td>
                    <td style="font-weight:600; font-size:11px;"><?= htmlspecialchars($e['creator_name'] ?: 'System') ?></td>
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
            <div>Waktu Cetak: <?= date('d/m/Y H:i:s') ?> WIB • EinvaBill Billing System</div>
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

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Laporan keuangan</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Ringkasan performa bisnis dan arus kas periode <?= $period_display ?>.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="index.php?page=admin_reports&action=export&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&user_id=<?= $filter_user ?>" class="ui-btn ui-btn-outline"><i class="fas fa-file-excel"></i> Ekspor</a>
        <?php if ($u_role === 'admin'): ?>
        <a href="index.php?page=admin_reports&action=print_profit_loss&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&year=<?= $filter_year ?>" target="_blank" class="ui-btn ui-btn-outline" title="Laporan Laba Rugi untuk SPT Tahunan">Laba rugi</a>
        <a href="index.php?page=admin_reports&action=print_position&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&year=<?= $filter_year ?>" target="_blank" class="ui-btn ui-btn-outline" title="Laporan Posisi Keuangan (neraca) untuk SPT Tahunan">Posisi keuangan</a>
        <?php endif; ?>
        <?php if ($u_role === 'admin'): ?>
        <a href="index.php?page=admin_report_assets" class="ui-btn ui-btn-outline">Aset</a>
        <?php endif; ?>
        <a href="index.php?page=admin_reports&action=print&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&user_id=<?= $filter_user ?>" target="_blank" class="ui-btn ui-btn-primary"><i class="fas fa-print"></i> Cetak</a>
    </div>
</div>

<!-- Source navigation -->
<div class="mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1">
    <a href="index.php?page=admin_customers" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Pelanggan</a>
    <a href="index.php?page=admin_invoices" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Tagihan</a>
    <a href="index.php?page=admin_reports" class="rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground no-underline shadow-card" aria-current="page">Laporan</a>
</div>

<!-- Filter bar -->
<form method="GET" action="index.php" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_140px_1fr_auto] lg:items-end">
    <input type="hidden" name="page" value="admin_reports">

    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Dari tanggal</span>
        <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
    </label>

    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Sampai tanggal</span>
        <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
    </label>

    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tahun</span>
        <select name="year" class="form-control">
            <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $filter_year == $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </label>

    <?php if ($u_role === 'admin'): ?>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Diterima oleh</span>
        <select name="user_id" class="form-control">
            <option value="all">Semua</option>
            <?php foreach($available_users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>><?= $u['name'] ?> (<?= ucfirst($u['role']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php else: ?>
    <div class="hidden lg:block"></div>
    <?php endif; ?>

    <div class="flex gap-2">
        <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto">Terapkan filter</button>
    </div>
</form>

<!-- Stats -->
<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3">
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total pendapatan terkumpul</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums text-signal sm:text-2xl">Rp <?= number_format($lunas_tepat + $tunggakan_dibayar, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Total uang masuk periode ini.</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Pelunasan berjalan</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl">Rp <?= number_format($lunas_tepat, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Tagihan jatuh tempo saat ini.</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Pelunasan tunggakan</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl">Rp <?= number_format($tunggakan_dibayar, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Pembayaran atas hutang lama.</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total pengeluaran</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums text-danger sm:text-2xl">Rp <?= number_format($total_expenses, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Biaya operasional &amp; belanja.</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Piutang berjalan</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums text-danger sm:text-2xl">Rp <?= number_format($belum_bayar, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Tagihan yang belum terselesaikan.</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Estimasi laba bersih</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl <?= (($lunas_tepat + $tunggakan_dibayar) - $total_expenses) < 0 ? 'text-danger' : 'text-signal' ?>">Rp <?= number_format(($lunas_tepat + $tunggakan_dibayar) - $total_expenses, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Income bersih (terkumpul - belanja).</div>
    </div>
</div>

<!-- Activity list -->
<section class="ui-card overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Rincian aktivitas</h3>
            <p class="m-0 text-xs text-muted-foreground">Periode <?= $period_display ?></p>
        </div>
        <span class="ui-badge ui-badge-muted"><?= count($report_data) ?> transaksi</span>
    </div>

    <!-- Mobile view: stacked rows -->
    <div class="md:hidden">
        <?php foreach($report_data as $row):
            $is_incoming = ($row['activity_type'] == 'Pembayaran Masuk');
            $is_debt = ($is_incoming && date('Y-m', strtotime($row['due_date'])) < date('Y-m', strtotime($row['activity_date'])));
        ?>
        <div class="border-t border-solid border-border px-4 py-3">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-xs font-medium text-muted-foreground"><?= $row['activity_type'] ?> · <?= date('d/m/Y', strtotime($row['activity_date'])) ?></div>
                    <div class="mt-0.5 truncate text-sm font-semibold"><?= htmlspecialchars($row['customer_name']) ?></div>
                    <div class="mt-0.5 font-mono text-xs text-muted-foreground">INV-<?= str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT) ?></div>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-sm font-bold tabular-nums <?= $is_incoming ? 'text-signal' : '' ?>">Rp <?= number_format($row['amount'], 0, ',', '.') ?></div>
                    <div class="mt-1 flex flex-wrap justify-end gap-1">
                        <?php if($is_debt): ?>
                            <span class="ui-badge ui-badge-accent">Tunggakan</span>
                        <?php endif; ?>
                        <span class="ui-badge <?= $row['status'] == 'Lunas' ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= $row['status'] == 'Lunas' ? 'Lunas' : 'Belum lunas' ?></span>
                    </div>
                </div>
            </div>
            <?php if($row['status'] == 'Lunas'):
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $settings = $db->query("SELECT wa_template_paid, company_name, site_url FROM settings WHERE tenant_id = $tenant_id")->fetch();
                if (!$settings) $settings = ['wa_template_paid' => '', 'company_name' => 'ISP', 'site_url' => ''];
                $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $row['contact']));
                $cust_id_display = $row['customer_code'] ?: str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT);
                $portal_link = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . $cust_id_display;
                $receipt_msg = str_replace(
                    ['{nama}', '{total_bayar}', '{bulan}', '{link_tagihan}', '{perusahaan}'],
                    [$row['customer_name'], 'Rp ' . number_format($row['amount'], 0, ',', '.'), date('m/Y', strtotime($row['due_date'])), $portal_link, $settings['company_name']],
                    $settings['wa_template_paid'] ?: "Halo {nama}, pembayaran {total_bayar} sudah lunas. Cek nota: {link_tagihan}"
                );
                $wa_link = "https://api.whatsapp.com/send?phone=$wa_num&text=" . urlencode($receipt_msg);
            ?>
            <div class="mt-2 flex justify-end gap-1.5">
                <button type="button" onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $wa_link ?>', this)" class="ui-btn ui-btn-sm ui-btn-outline text-wa" title="Kirim ulang nota"><i class="fab fa-whatsapp"></i></button>
                <a href="index.php?page=invoice_print&id=<?= $row['invoice_id'] ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak kuitansi"><i class="fas fa-print"></i></a>
                <?php if($_SESSION['user_role'] === 'admin'): ?>
                    <?php if($row['activity_type'] == 'Pembayaran Masuk' && !empty($row['payment_id'])): ?>
                        <a data-method="post" href="index.php?page=admin_reports&action=delete_tx&tx=payment&id=<?= intval($row['payment_id']) ?>" onclick="return confirm('Hapus pembayaran ini?')" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus pembayaran"><i class="fas fa-trash"></i></a>
                    <?php else: ?>
                        <a data-method="post" href="index.php?page=admin_reports&action=delete_tx&tx=invoice&id=<?= intval($row['invoice_id']) ?>" onclick="return confirm('Hapus invoice ini beserta item dan pembayaran terkait?')" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus invoice"><i class="fas fa-trash"></i></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if(count($report_data) == 0): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Tidak ada transaksi pembayaran atau tagihan di periode ini.</div>
        <?php endif; ?>
    </div>

    <!-- Desktop view: table -->
    <div class="hidden overflow-x-auto md:block">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold sm:px-5">Waktu / transaksi</th>
                    <th class="px-3 py-2.5 font-semibold">Nama pelanggan / mitra</th>
                    <th class="px-3 py-2.5 font-semibold">Nomor invoice</th>
                    <th class="px-3 py-2.5 text-right font-semibold">Nominal</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($report_data as $row): ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 sm:px-5">
                        <div class="font-semibold"><?= $row['activity_type'] ?></div>
                        <div class="text-xs text-muted-foreground"><?= date('d/m/Y', strtotime($row['activity_date'])) ?></div>
                        <?php if($row['activity_type'] == 'Pembayaran Masuk' && date('Y-m', strtotime($row['due_date'])) < date('Y-m', strtotime($row['activity_date']))): ?>
                            <div class="mt-1"><span class="ui-badge ui-badge-accent">Pelunasan tunggakan</span></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3">
                        <div class="font-semibold"><?= htmlspecialchars($row['customer_name']) ?></div>
                        <div class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                            <?php if($row['customer_type']=='partner') echo '<span class="ui-badge ui-badge-muted">Mitra</span>'; ?>
                            <?php if($u_role === 'admin'): ?>
                                <span><?= htmlspecialchars($row['area'] ?: '-') ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <div class="font-mono text-[13px] font-semibold">Invoice-<?= str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT) ?></div>
                        <div class="text-xs text-muted-foreground">Jatuh tempo: <?= date('d/m/Y', strtotime($row['due_date'])) ?></div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-3 text-right font-bold tabular-nums <?= $row['activity_type'] == 'Pembayaran Masuk' ? 'text-signal' : '' ?>">
                        Rp <?= number_format($row['amount'], 0, ',', '.') ?>
                    </td>
                    <td class="px-4 py-3 sm:px-5">
                        <div class="flex items-center justify-end gap-1.5">
                        <span class="ui-badge <?= $row['status'] == 'Lunas' ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= $row['status'] == 'Lunas' ? 'Lunas' : 'Belum lunas' ?></span>
                        <?php if($row['status'] == 'Lunas'):
                            $settings = $db->query("SELECT wa_template_paid, company_name, site_url FROM settings WHERE id=1")->fetch();
                            $wa_num = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $row['contact']));
                            $cust_id_display = $row['customer_code'] ?: str_pad($row['invoice_id'], 5, "0", STR_PAD_LEFT);
                            $portal_link = ($settings['site_url'] ?? 'http://fibernodeinternet.com') . "/index.php?page=customer_portal&code=" . $cust_id_display;
                            $receipt_msg = str_replace(
                                ['{nama}', '{total_bayar}', '{bulan}', '{link_tagihan}', '{perusahaan}'],
                                [$row['customer_name'], 'Rp ' . number_format($row['amount'], 0, ',', '.'), date('m/Y', strtotime($row['due_date'])), $portal_link, $settings['company_name']],
                                $settings['wa_template_paid'] ?: "Halo {nama}, pembayaran {total_bayar} sudah lunas. Cek nota: {link_tagihan}"
                            );
                            $wa_link = "https://api.whatsapp.com/send?phone=$wa_num&text=" . urlencode($receipt_msg);
                        ?>
                            <button type="button" onclick="sendWAGateway('<?= $wa_num ?>', <?= htmlspecialchars(json_encode($receipt_msg)) ?>, '<?= $wa_link ?>', this)" class="ui-btn ui-btn-sm ui-btn-outline text-wa" title="Kirim ulang nota"><i class="fab fa-whatsapp"></i></button>
                            <a href="index.php?page=invoice_print&id=<?= $row['invoice_id'] ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak kuitansi"><i class="fas fa-print"></i></a>
                                <?php if($_SESSION['user_role'] === 'admin'): ?>
                                    <?php if($row['activity_type'] == 'Pembayaran Masuk' && !empty($row['payment_id'])): ?>
                                        <a data-method="post" href="index.php?page=admin_reports&action=delete_tx&tx=payment&id=<?= intval($row['payment_id']) ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus pembayaran ini? Semua perubahan akan permanent.')" title="Hapus pembayaran"><i class="fas fa-trash"></i></a>
                                    <?php else: ?>
                                        <a data-method="post" href="index.php?page=admin_reports&action=delete_tx&tx=invoice&id=<?= intval($row['invoice_id']) ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus invoice ini beserta item dan pembayaran terkait?')" title="Hapus invoice"><i class="fas fa-trash"></i></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                        <?php endif; ?>
                        <?php if($row['status'] == 'Belum Lunas'): ?>
                            <a href="index.php?page=invoice_print&id=<?= $row['invoice_id'] ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak tagihan"><i class="fas fa-print"></i></a>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($report_data) == 0): ?>
                    <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-muted-foreground">Tidak ada transaksi pembayaran atau tagihan di periode ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="flex flex-wrap items-center justify-center gap-1.5 border-t border-solid border-border px-4 py-3">
            <?php
                $params = $_GET;
                unset($params['p']);
                $query_str = http_build_query($params);
                $base_p_url = "index.php?" . $query_str . "&p=";
            ?>

            <?php if($current_page > 1): ?>
                <a href="<?= $base_p_url . ($current_page - 1) ?>" class="ui-btn ui-btn-sm ui-btn-outline">&laquo;</a>
            <?php endif; ?>

            <?php
                $start_p = max(1, $current_page - 2);
                $end_p = min($total_pages, $current_page + 2);
                for($i = $start_p; $i <= $end_p; $i++):
            ?>
                <a href="<?= $base_p_url . $i ?>" class="ui-btn ui-btn-sm <?= $i == $current_page ? 'ui-btn-primary' : 'ui-btn-outline' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if($current_page < $total_pages): ?>
                <a href="<?= $base_p_url . ($current_page + 1) ?>" class="ui-btn ui-btn-sm ui-btn-outline">&raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<script>
    // Function to send message via Gateway
    async function sendWAGateway(phone, message, fallback, btn) {
    // A missing fallback used to open about:blank when the gateway failed.
    fallback = fallback || ('https://api.whatsapp.com/send?phone=' + encodeURIComponent(phone) + '&text=' + encodeURIComponent(message));
        if (btn) {
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            try {
                // Using relative proxy URL for security and mobile compatibility
                const response = await fetch('/waapi/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phone, message })
                });
                const data = await response.json();
                
                if (data.error) throw new Error(data.message);
                
                // Success
                btn.style.color = '#10b981';
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.style.color = '#25D366';
                    btn.disabled = false;
                }, 2000);
            } catch (e) {
                console.error('Gateway failed, using fallback:', e);
                window.open(fallback, '_blank');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }
    }
</script>
