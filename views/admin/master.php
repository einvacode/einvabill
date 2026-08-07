<?php
$u_id = intval($_SESSION['user_id'] ?? 0);
$u_role = $_SESSION['user_role'] ?? 'guest';
$u_tenant = intval($_SESSION['tenant_id'] ?? 0);
$can_access_master = ($u_id === 1) || ($u_role === 'master') || ($u_role === 'admin' && $u_tenant === 1);

if (!$can_access_master) {
    echo "<div class='glass-panel' style='padding:40px; text-align:center;'><h2>Akses Ditolak</h2><p>Halaman master hanya untuk super admin.</p></div>";
    return;
}

function master_count(PDO $db, $sql, array $params = []) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

$tenant_filter = $_GET['tenant_id'] ?? 'all';
$tenant_id_filter = ($tenant_filter === 'all') ? 'all' : intval($tenant_filter);

$tenants = [];
try {
    $tenants = $db->query("SELECT tenant_id, name FROM users WHERE role = 'admin' AND tenant_id IS NOT NULL ORDER BY tenant_id ASC")->fetchAll();
} catch (Exception $e) {
    $tenants = [];
}

$tenant_summary = [];
foreach ($tenants as $ten) {
    $tid = intval($ten['tenant_id']);
    try {
        $stmt_company = $db->prepare("SELECT company_name FROM settings WHERE tenant_id = ? LIMIT 1");
        $stmt_company->execute([$tid]);
        $company_name = trim((string)$stmt_company->fetchColumn());
    } catch (Exception $e) {
        $company_name = '';
    }
    if ($company_name === '') {
        $company_name = trim((string)($ten['name'] ?? ('Tenant ' . $tid)));
    }

    $tenant_summary[] = [
        'tenant_id' => $tid,
        'company_name' => $company_name,
        'admin_name' => $ten['name'] ?? '-',
        'customers' => (int)master_count($db, "SELECT COUNT(*) FROM customers WHERE tenant_id = ?", [$tid]),
        'invoices' => (int)master_count($db, "SELECT COUNT(*) FROM invoices WHERE tenant_id = ?", [$tid]),
        'cash_in' => master_count($db, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id = ?", [$tid]),
        'expenses' => master_count($db, "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id = ?", [$tid]),
        'receivable' => master_count($db, "SELECT COALESCE(SUM(amount - discount),0) FROM invoices WHERE tenant_id = ? AND status = 'Belum Lunas'", [$tid]),
    ];
}

$all_customers = [];
try {
    if ($tenant_id_filter !== 'all') {
        $stmt = $db->prepare("SELECT c.*, COALESCE(s.company_name, u.name) as tenant_name FROM customers c LEFT JOIN users u ON u.tenant_id = c.tenant_id AND u.role = 'admin' LEFT JOIN settings s ON s.tenant_id = c.tenant_id WHERE c.tenant_id = ? ORDER BY c.name ASC");
        $stmt->execute([$tenant_id_filter]);
        $all_customers = $stmt->fetchAll();
    } else {
        $all_customers = $db->query("SELECT c.*, COALESCE(s.company_name, u.name) as tenant_name FROM customers c LEFT JOIN users u ON u.tenant_id = c.tenant_id AND u.role = 'admin' LEFT JOIN settings s ON s.tenant_id = c.tenant_id ORDER BY c.tenant_id ASC, c.name ASC")->fetchAll();
    }
} catch (Exception $e) {
    $all_customers = [];
}

$total_tenants = count($tenant_summary);
$total_customers = 0;
$total_invoices = 0;
$total_cash_in = 0;
$total_expenses = 0;
$total_receivable = 0;
foreach ($tenant_summary as $row) {
    $total_customers += (int)$row['customers'];
    $total_invoices += (int)$row['invoices'];
    $total_cash_in += (float)$row['cash_in'];
    $total_expenses += (float)$row['expenses'];
    $total_receivable += (float)$row['receivable'];
}
?>

<div class="glass-panel" style="padding:24px;">
    <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start; margin-bottom:20px;">
        <div>
            <h3 style="font-size:22px; margin:0 0 6px;"><i class="fas fa-crown text-primary"></i> Master Tenant</h3>
            <div style="color:var(--text-secondary);">Pantau customer dan keuangan semua tenant dari satu halaman.</div>
        </div>
        <form method="GET" style="min-width:220px;">
            <input type="hidden" name="page" value="admin_master">
            <label style="font-size:12px; color:var(--text-secondary);">Filter Tenant</label>
            <select name="tenant_id" class="form-control" onchange="this.form.submit()">
                <option value="all" <?= $tenant_id_filter === 'all' ? 'selected' : '' ?>>Semua Tenant</option>
                <?php foreach ($tenant_summary as $row): ?>
                    <option value="<?= intval($row['tenant_id']) ?>" <?= intval($tenant_id_filter) === intval($row['tenant_id']) ? 'selected' : '' ?>><?= htmlspecialchars($row['company_name']) ?> (ID <?= intval($row['tenant_id']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:24px;">
        <div class="glass-panel" style="padding:16px;"><div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Tenant</div><div style="font-size:26px; font-weight:800; margin-top:6px;"><?= number_format($total_tenants) ?></div></div>
        <div class="glass-panel" style="padding:16px;"><div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Pelanggan</div><div style="font-size:26px; font-weight:800; margin-top:6px;"><?= number_format($total_customers) ?></div></div>
        <div class="glass-panel" style="padding:16px;"><div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Invoice</div><div style="font-size:26px; font-weight:800; margin-top:6px;"><?= number_format($total_invoices) ?></div></div>
        <div class="glass-panel" style="padding:16px;"><div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Kas Masuk</div><div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--success);">Rp <?= number_format($total_cash_in, 0, ',', '.') ?></div></div>
        <div class="glass-panel" style="padding:16px;"><div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Pengeluaran</div><div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--danger);">Rp <?= number_format($total_expenses, 0, ',', '.') ?></div></div>
        <div class="glass-panel" style="padding:16px;"><div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Piutang</div><div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--warning);">Rp <?= number_format($total_receivable, 0, ',', '.') ?></div></div>
    </div>

    <div class="table-container" style="margin-bottom:24px;">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Pelanggan</th>
                    <th>Invoice</th>
                    <th>Kas Masuk</th>
                    <th>Pengeluaran</th>
                    <th>Piutang</th>
                    <th>Saldo Bersih</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenant_summary as $row): ?>
                    <?php $saldo = (float)$row['cash_in'] - (float)$row['expenses']; ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;"><?= htmlspecialchars($row['company_name']) ?></div>
                            <div style="font-size:11px; color:var(--text-secondary);">Admin: <?= htmlspecialchars($row['admin_name']) ?> | ID <?= intval($row['tenant_id']) ?></div>
                        </td>
                        <td><?= number_format((int)$row['customers'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$row['invoices'], 0, ',', '.') ?></td>
                        <td style="color:var(--success); font-weight:700;">Rp <?= number_format((float)$row['cash_in'], 0, ',', '.') ?></td>
                        <td style="color:var(--danger); font-weight:700;">Rp <?= number_format((float)$row['expenses'], 0, ',', '.') ?></td>
                        <td style="color:var(--warning); font-weight:700;">Rp <?= number_format((float)$row['receivable'], 0, ',', '.') ?></td>
                        <td style="font-weight:800;">Rp <?= number_format($saldo, 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-container" style="max-height:70vh; overflow:auto;">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Kode</th>
                    <th>Nama Customer</th>
                    <th>Tipe</th>
                    <th>Paket</th>
                    <th>Biaya</th>
                    <th>Kontak</th>
                    <th>Area</th>
                    <th>Registrasi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_customers as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['tenant_name'] ?: 'Unknown Tenant') ?></td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($c['customer_code'] ?: '-') ?></td>
                        <td style="font-weight:700;"><?= htmlspecialchars($c['name']) ?></td>
                        <td><?= htmlspecialchars($c['type'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($c['package_name'] ?: '-') ?></td>
                        <td>Rp <?= number_format((float)($c['monthly_fee'] ?? 0), 0, ',', '.') ?></td>
                        <td><?= htmlspecialchars($c['contact'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($c['area'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($c['registration_date'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
