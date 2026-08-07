<?php
$u_id = $_SESSION['user_id'] ?? 0;
$u_role = $_SESSION['user_role'] ?? 'guest';
$u_tenant = intval($_SESSION['tenant_id'] ?? 0);
$can_access_master = ($u_id == 1) || ($u_role === 'master') || ($u_role === 'admin' && $u_tenant === 1);

if (!$can_access_master) {
    echo "<div class='glass-panel' style='padding:40px; text-align:center;'><h2>Akses Ditolak</h2><p>Halaman master hanya untuk super admin.</p></div>";
    return;
}

$tenant_filter = $_GET['tenant_id'] ?? 'all';
$tenant_id_filter = ($tenant_filter === 'all') ? 'all' : intval($tenant_filter);

try {
    $tenants = $db->query("SELECT u.tenant_id, u.name as admin_name, COALESCE(s.company_name, u.name) as company_name FROM users u LEFT JOIN settings s ON s.tenant_id = u.tenant_id WHERE u.role = 'admin' GROUP BY u.tenant_id ORDER BY u.tenant_id ASC")->fetchAll();
} catch (Exception $e) {
    $tenants = [];
}

if ($tenant_id_filter !== 'all') {
    $tenant_ids = [$tenant_id_filter];
    $tenant_where = " WHERE tenant_id = " . intval($tenant_id_filter);
} else {
    $tenant_ids = array_map(function($row) { return intval($row['tenant_id']); }, $tenants);
    $tenant_where = "";
}

$selected_tenant_name = 'Semua Tenant';
foreach ($tenants as $ten) {
    if (intval($ten['tenant_id']) === intval($tenant_id_filter)) {
        $selected_tenant_name = $ten['company_name'] ?: $ten['admin_name'];
        break;
    }
}

$tenant_summary = [];
foreach ($tenants as $ten) {
    $tid = intval($ten['tenant_id']);
    try {
        $tenant_summary[] = [
            'tenant_id' => $tid,
            'company_name' => $ten['company_name'] ?: $ten['admin_name'],
            'admin_name' => $ten['admin_name'],
            'customers' => (int)$db->query("SELECT COUNT(*) FROM customers WHERE tenant_id = $tid")->fetchColumn(),
            'invoices' => (int)$db->query("SELECT COUNT(*) FROM invoices WHERE tenant_id = $tid")->fetchColumn(),
            'cash_in' => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id = $tid")->fetchColumn(),
            'expenses' => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id = $tid")->fetchColumn(),
            'receivable' => (float)$db->query("SELECT COALESCE(SUM(amount - discount),0) FROM invoices WHERE tenant_id = $tid AND status = 'Belum Lunas'")->fetchColumn(),
            'paid' => (float)$db->query("SELECT COALESCE(SUM(amount - discount),0) FROM invoices WHERE tenant_id = $tid AND status = 'Lunas'")->fetchColumn(),
        ];
    } catch (Exception $e) {
        $tenant_summary[] = [
            'tenant_id' => $tid,
            'company_name' => $ten['company_name'] ?: $ten['admin_name'],
            'admin_name' => $ten['admin_name'],
            'customers' => 0,
            'invoices' => 0,
            'cash_in' => 0,
            'expenses' => 0,
            'receivable' => 0,
            'paid' => 0,
        ];
    }
}

$total_tenants = count($tenant_summary);
$total_customers = 0;
$total_invoices = 0;
$total_cash_in = 0;
$total_expenses = 0;
$total_receivable = 0;
foreach ($tenant_summary as $row) {
    $total_customers += $row['customers'];
    $total_invoices += $row['invoices'];
    $total_cash_in += $row['cash_in'];
    $total_expenses += $row['expenses'];
    $total_receivable += $row['receivable'];
}

if ($tenant_id_filter !== 'all') {
    $customer_sql = "SELECT c.*, COALESCE(s.company_name, u.name) as tenant_name FROM customers c LEFT JOIN users u ON u.tenant_id = c.tenant_id AND u.role = 'admin' LEFT JOIN settings s ON s.tenant_id = c.tenant_id WHERE c.tenant_id = ? ORDER BY c.name ASC";
    $stmt_customers = $db->prepare($customer_sql);
    $stmt_customers->execute([$tenant_id_filter]);
    $all_customers = $stmt_customers->fetchAll();
} else {
    $customer_sql = "SELECT c.*, COALESCE(s.company_name, u.name) as tenant_name FROM customers c LEFT JOIN users u ON u.tenant_id = c.tenant_id AND u.role = 'admin' LEFT JOIN settings s ON s.tenant_id = c.tenant_id ORDER BY c.tenant_id ASC, c.name ASC";
    $all_customers = $db->query($customer_sql)->fetchAll();
}
?>

<div class="glass-panel" style="padding:24px;">
    <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start; margin-bottom:20px;">
        <div>
            <h3 style="font-size:22px; margin:0 0 6px;"><i class="fas fa-crown text-primary"></i> Master Tenant</h3>
            <div style="color:var(--text-secondary);">Pantau seluruh customer dan keuangan lintas tenant dari satu halaman.</div>
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
        <div class="glass-panel" style="padding:16px;">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Tenant</div>
            <div style="font-size:26px; font-weight:800; margin-top:6px;"><?= number_format($total_tenants) ?></div>
        </div>
        <div class="glass-panel" style="padding:16px;">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Pelanggan</div>
            <div style="font-size:26px; font-weight:800; margin-top:6px;"><?= number_format($total_customers) ?></div>
        </div>
        <div class="glass-panel" style="padding:16px;">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Invoice</div>
            <div style="font-size:26px; font-weight:800; margin-top:6px;"><?= number_format($total_invoices) ?></div>
        </div>
        <div class="glass-panel" style="padding:16px;">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Kas Masuk</div>
            <div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--success);">Rp <?= number_format($total_cash_in, 0, ',', '.') ?></div>
        </div>
        <div class="glass-panel" style="padding:16px;">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Pengeluaran</div>
            <div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--danger);">Rp <?= number_format($total_expenses, 0, ',', '.') ?></div>
        </div>
        <div class="glass-panel" style="padding:16px;">
            <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Piutang</div>
            <div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--warning);">Rp <?= number_format($total_receivable, 0, ',', '.') ?></div>
        </div>
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
                    <?php $saldo = $row['cash_in'] - $row['expenses']; ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;"><?= htmlspecialchars($row['company_name']) ?></div>
                            <div style="font-size:11px; color:var(--text-secondary);">Admin: <?= htmlspecialchars($row['admin_name']) ?> | ID <?= intval($row['tenant_id']) ?></div>
                        </td>
                        <td><?= number_format($row['customers'], 0, ',', '.') ?></td>
                        <td><?= number_format($row['invoices'], 0, ',', '.') ?></td>
                        <td style="color:var(--success); font-weight:700;">Rp <?= number_format($row['cash_in'], 0, ',', '.') ?></td>
                        <td style="color:var(--danger); font-weight:700;">Rp <?= number_format($row['expenses'], 0, ',', '.') ?></td>
                        <td style="color:var(--warning); font-weight:700;">Rp <?= number_format($row['receivable'], 0, ',', '.') ?></td>
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