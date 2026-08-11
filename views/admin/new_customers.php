<?php
/**
 * New Customers This Month
 * Display only customers registered in the current month
 */

$action = $_GET['action'] ?? 'list';
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$u_role = $_SESSION['user_role'] ?? 'admin';

// RBAC: Get partner IDs for scoping
$partner_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
$partner_list = !empty($partner_ids) ? implode(',', $partner_ids) : '0';

// Determine scope for current user
$scope_where = ($u_role === 'admin' || $u_role === 'collector')
    ? " AND (created_by NOT IN ($partner_list) OR created_by = 0 OR created_by IS NULL) "
    : " AND (created_by = " . $_SESSION['user_id'] . ") ";

// Filter: Current month only
$current_month = date('Y-m');
$month_filter = " AND strftime('%Y-%m', registration_date) = " . $db->quote($current_month);

// Get total count
$total = $db->query("
    SELECT COUNT(*) FROM customers 
    WHERE tenant_id = $tenant_id 
    $scope_where
    $month_filter
")->fetchColumn();

// Pagination
$items_per_page = 20;
$page = intval($_GET['page'] ?? 1);
$offset = ($page - 1) * $items_per_page;
$total_pages = ceil($total / $items_per_page);

// Fetch new customers this month
$new_customers = $db->query("
    SELECT id, customer_code, name, type, contact, address, package_name, 
           monthly_fee, area, registration_date, collector_id, created_by
    FROM customers 
    WHERE tenant_id = $tenant_id
    $scope_where
    $month_filter
    ORDER BY registration_date DESC 
    LIMIT $items_per_page OFFSET $offset
")->fetchAll();

// Get collector names for display
$collectors = [];
try {
    $coll_result = $db->query("SELECT id, username FROM users WHERE role = 'collector' AND tenant_id = $tenant_id")->fetchAll();
    foreach ($coll_result as $c) {
        $collectors[$c['id']] = $c['username'];
    }
} catch (Exception $e) {}
?>

<div class="glass-panel" style="padding:30px; margin-bottom:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
        <div>
            <h2 style="margin:0; font-size:24px; font-weight:800;"><i class="fas fa-star text-warning"></i> Pelanggan Baru</h2>
            <p style="margin:8px 0 0; font-size:13px; color:var(--text-secondary);">Pelanggan yang terdaftar di <?= date('F Y', strtotime($current_month . '-01')) ?></p>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="index.php?page=admin_customers&filter_month=<?= date('Y-m') ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-filter"></i> Filter di Manajemen Pelanggan
            </a>
            <a href="index.php?page=admin_dashboard" class="btn btn-sm btn-ghost">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div style="background:rgba(59, 130, 246, 0.1); border:1px solid rgba(59, 130, 246, 0.3); border-radius:12px; padding:20px; margin-bottom:30px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:5px;">Total Pelanggan Baru</div>
                <div style="font-size:32px; font-weight:800; color:#3b82f6;"><?= $total ?></div>
            </div>
            <div style="text-align:right; font-size:12px; color:var(--text-secondary);">
                Bulan: <strong><?= date('M Y', strtotime($current_month . '-01')) ?></strong><br>
                Menampilkan <strong><?= min($items_per_page, count($new_customers)) ?></strong> dari <strong><?= $total ?></strong>
            </div>
        </div>
    </div>

    <?php if (empty($new_customers)): ?>
    <div style="text-align:center; padding:60px 30px; color:var(--text-secondary);">
        <i class="fas fa-inbox" style="font-size:48px; opacity:0.3; display:block; margin-bottom:15px;"></i>
        <h3 style="margin:0 0 8px; color:var(--text-secondary);">Tidak Ada Pelanggan Baru</h3>
        <p style="margin:0; font-size:13px;">Belum ada pelanggan yang terdaftar di bulan ini.</p>
    </div>
    <?php else: ?>

    <!-- Table -->
    <div style="border-radius:10px; border:1px solid var(--glass-border); overflow-x:auto;">
        <table style="width:100%; font-size:12px;">
            <thead>
                <tr style="background:rgba(var(--primary-rgb), 0.05); border-bottom:1px solid var(--glass-border);">
                    <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Kode</th>
                    <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Nama Pelanggan</th>
                    <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Kontak</th>
                    <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Paket</th>
                    <th style="padding:12px; text-align:right; font-weight:700; color:var(--text-secondary);">Biaya</th>
                    <th style="padding:12px; text-align:center; font-weight:700; color:var(--text-secondary);">Tgl Daftar</th>
                    <th style="padding:12px; text-align:center; font-weight:700; color:var(--text-secondary);">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($new_customers as $cust): ?>
                <tr style="border-bottom:1px solid var(--glass-border); hover:background:rgba(var(--primary-rgb), 0.02);">
                    <td style="padding:12px; font-family:monospace; font-weight:600; color:var(--primary);">
                        <?= htmlspecialchars($cust['customer_code']) ?>
                    </td>
                    <td style="padding:12px;">
                        <strong><?= htmlspecialchars($cust['name']) ?></strong>
                        <br><span style="font-size:10px; color:var(--text-secondary); opacity:0.7;">ID: <?= $cust['id'] ?></span>
                    </td>
                    <td style="padding:12px; font-size:11px;">
                        <?= htmlspecialchars($cust['contact'] ?? '-') ?>
                    </td>
                    <td style="padding:12px; font-size:11px;">
                        <?= htmlspecialchars($cust['package_name'] ?? '-') ?>
                    </td>
                    <td style="padding:12px; text-align:right; font-weight:600;">
                        Rp <?= number_format($cust['monthly_fee'] ?? 0, 0, ',', '.') ?>
                    </td>
                    <td style="padding:12px; text-align:center; font-size:11px;">
                        <?= date('d/m/Y', strtotime($cust['registration_date'])) ?>
                    </td>
                    <td style="padding:12px; text-align:center;">
                        <a href="index.php?page=admin_customers&action=details&id=<?= $cust['id'] ?>" class="btn btn-xs btn-primary" style="padding:5px 8px; font-size:11px;">
                            <i class="fas fa-eye"></i> Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="margin-top:20px; display:flex; justify-content:center; gap:5px;">
        <?php if ($page > 1): ?>
        <a href="index.php?page=admin_new_customers&page=1" class="btn btn-sm btn-ghost">
            <i class="fas fa-chevron-left"></i> Pertama
        </a>
        <a href="index.php?page=admin_new_customers&page=<?= $page - 1 ?>" class="btn btn-sm btn-ghost">
            <i class="fas fa-chevron-left"></i> Sebelumnya
        </a>
        <?php endif; ?>

        <span style="padding:8px 12px; color:var(--text-secondary); font-size:12px;">
            Halaman <?= $page ?> dari <?= $total_pages ?>
        </span>

        <?php if ($page < $total_pages): ?>
        <a href="index.php?page=admin_new_customers&page=<?= $page + 1 ?>" class="btn btn-sm btn-ghost">
            Berikutnya <i class="fas fa-chevron-right"></i>
        </a>
        <a href="index.php?page=admin_new_customers&page=<?= $total_pages ?>" class="btn btn-sm btn-ghost">
            Terakhir <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
