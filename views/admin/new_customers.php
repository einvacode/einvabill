<?php
/**
 * New Customers This Month
 * Display only customers registered in the current month
 */

$action = $_GET['action'] ?? 'list';
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$u_role = app_scope_role();

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

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Pelanggan baru</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Pelanggan yang terdaftar di <?= date('F Y', strtotime($current_month . '-01')) ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="index.php?page=admin_dashboard" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-left"></i> Kembali</a>
        <a href="index.php?page=admin_customers&filter_month=<?= date('Y-m') ?>" class="ui-btn ui-btn-outline"><i class="fas fa-filter"></i> Filter di manajemen pelanggan</a>
    </div>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total pelanggan baru</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= $total ?></div>
        <div class="text-xs text-muted-foreground">Bulan <?= date('M Y', strtotime($current_month . '-01')) ?></div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Ditampilkan</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= min($items_per_page, count($new_customers)) ?></div>
        <div class="text-xs text-muted-foreground">dari <?= $total ?> pelanggan</div>
    </div>
</div>

<section class="ui-card overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Daftar pelanggan baru</h3>
            <p class="m-0 text-xs text-muted-foreground">Urut dari tanggal daftar terbaru</p>
        </div>
    </div>

    <?php if (empty($new_customers)): ?>
    <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada pelanggan yang terdaftar di bulan ini.</div>
    <?php else: ?>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold">Kode</th>
                    <th class="px-4 py-2.5 font-semibold">Nama pelanggan</th>
                    <th class="px-4 py-2.5 font-semibold">Kontak</th>
                    <th class="px-4 py-2.5 font-semibold">Paket</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Biaya</th>
                    <th class="px-4 py-2.5 text-center font-semibold">Tgl daftar</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($new_customers as $cust): ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 font-mono text-xs text-muted-foreground"><?= htmlspecialchars($cust['customer_code']) ?></td>
                    <td class="px-4 py-3">
                        <div class="font-semibold"><?= htmlspecialchars($cust['name']) ?></div>
                        <div class="text-xs text-muted-foreground">ID: <?= $cust['id'] ?></div>
                    </td>
                    <td class="px-4 py-3"><?= htmlspecialchars($cust['contact'] ?? '-') ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars($cust['package_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums">Rp <?= number_format($cust['monthly_fee'] ?? 0, 0, ',', '.') ?></td>
                    <td class="px-4 py-3 text-center tabular-nums"><?= date('d/m/Y', strtotime($cust['registration_date'])) ?></td>
                    <td class="px-4 py-3 text-right">
                        <a href="index.php?page=admin_customers&action=details&id=<?= $cust['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Detail"><i class="fas fa-eye"></i><span class="hidden sm:inline">Detail</span></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="flex flex-wrap items-center justify-center gap-1.5 border-t border-solid border-border px-4 py-3">
        <?php if ($page > 1): ?>
        <a href="index.php?page=admin_new_customers&page=1" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-chevron-left"></i> Pertama</a>
        <a href="index.php?page=admin_new_customers&page=<?= $page - 1 ?>" class="ui-btn ui-btn-sm ui-btn-outline">Sebelumnya</a>
        <?php endif; ?>

        <span class="px-3 text-xs text-muted-foreground">Halaman <?= $page ?> dari <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
        <a href="index.php?page=admin_new_customers&page=<?= $page + 1 ?>" class="ui-btn ui-btn-sm ui-btn-outline">Berikutnya</a>
        <a href="index.php?page=admin_new_customers&page=<?= $total_pages ?>" class="ui-btn ui-btn-sm ui-btn-outline">Terakhir <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</section>
