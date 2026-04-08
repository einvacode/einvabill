<?php
// Partner view for ISP Invoices
$user_id = intval($_SESSION['user_id']);
$stmt_u = $db->prepare("SELECT customer_id FROM users WHERE id = ?");
$stmt_u->execute([$user_id]);
$u = $stmt_u->fetch();
$partner_cid = $u['customer_id'] ?? 0;

if (!$partner_cid) {
    echo "<div class='glass-panel' style='padding:40px; text-align:center;'>
            <i class='fas fa-user-slash fa-3x' style='opacity:0.3; margin-bottom:15px;'></i>
            <h3>Akun Belum Tertaut</h3>
            <p>Akun mitra Anda belum ditautkan ke data pelanggan pusat sebagai reseller.</p>
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

<div class="glass-panel" style="padding:24px; margin-bottom:20px; border-left:5px solid var(--primary);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
        <div>
            <h3 style="margin:0; font-size:20px; font-weight:800; color:var(--text-primary);"><i class="fas fa-file-invoice-dollar text-primary"></i> Tagihan Kemitraan Saya Ke ISP</h3>
            <p style="margin:5px 0 0; font-size:13px; color:var(--text-secondary);">Berikut adalah riwayat tagihan bulanan dari ISP pusat untuk akun reseller Anda.</p>
        </div>
        <div style="display:flex; gap:15px;">
            <div class="stat-mini" style="text-align:right;">
                <div style="font-size:10px; font-weight:800; color:var(--text-secondary);">TOTAL TUNGGAKAN</div>
                <div style="font-size:18px; font-weight:900; color:var(--danger);">Rp <?= number_format($partner_stats['total_belum'], 0, ',', '.') ?></div>
            </div>
            <div class="stat-mini" style="text-align:right;">
                <div style="font-size:10px; font-weight:800; color:var(--text-secondary);">TOTAL TERBAYAR</div>
                <div style="font-size:18px; font-weight:900; color:var(--success);">Rp <?= number_format($partner_stats['total_lunas'], 0, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div style="background:rgba(255,255,255,0.02); padding:20px; border-radius:15px; border:1px solid var(--glass-border); margin-bottom:25px;">
        <form method="GET" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:15px; align-items:flex-end;">
            <input type="hidden" name="page" value="partner_isp_invoices">
            <div>
                <label style="font-size:11px; font-weight:800; color:var(--text-secondary); display:block; margin-bottom:8px;">DARI TANGGAL JATUH TEMPO</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" style="font-size:13px;">
            </div>
            <div>
                <label style="font-size:11px; font-weight:800; color:var(--text-secondary); display:block; margin-bottom:8px;">SAMPAI TANGGAL</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" style="font-size:13px;">
            </div>
            <div>
                <label style="font-size:11px; font-weight:800; color:var(--text-secondary); display:block; margin-bottom:8px;">STATUS PEMBAYARAN</label>
                <select name="filter_status" class="form-control" style="font-size:13px;">
                    <option value="semua" <?= $filter_status === 'semua' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum Lunas</option>
                    <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas Terbayar</option>
                </select>
            </div>
            <div>
                <label style="font-size:11px; font-weight:800; color:var(--text-secondary); display:block; margin-bottom:8px;">URUTAN</label>
                <select name="sort_date" class="form-control" style="font-size:13px;">
                    <option value="desc" <?= $sort_date === 'desc' ? 'selected' : '' ?>>Terbaru (Desc)</option>
                    <option value="asc" <?= $sort_date === 'asc' ? 'selected' : '' ?>>Terlama (Asc)</option>
                </select>
            </div>
            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary" style="flex:1; height:42px; font-weight:800; border-radius:10px;"><i class="fas fa-filter"></i> CARI</button>
                <a href="index.php?page=partner_isp_invoices" class="btn btn-ghost" style="width:42px; height:42px; display:flex; align-items:center; justify-content:center; border-radius:10px; border:1px solid var(--glass-border);"><i class="fas fa-redo"></i></a>
            </div>
        </form>
    </div>

    <!-- Data List -->
    <?php if(empty($partner_invoices)): ?>
        <div style="text-align:center; padding:60px 20px; border:1px dashed var(--glass-border); border-radius:20px;">
            <i class="fas fa-receipt fa-4x" style="opacity:0.1; margin-bottom:20px;"></i>
            <h4 style="margin:0; opacity:0.5;">Tidak Ada Tagihan Ditemukan</h4>
            <p style="font-size:13px; color:var(--text-secondary);">Silakan ubah filter atau hubungi ISP pusat jika ada kendala.</p>
        </div>
    <?php else: ?>
        <!-- Desktop Mode -->
        <div class="table-container desktop-only" style="border:1px solid var(--glass-border); border-radius:15px; overflow:hidden;">
            <table style="width:100%;">
                <thead style="background:rgba(var(--primary-rgb), 0.05);">
                    <tr>
                        <th style="padding:15px; font-size:11px; text-transform:uppercase; text-align:left;">Nomor Invoice</th>
                        <th style="padding:15px; font-size:11px; text-transform:uppercase; text-align:left;">Jatuh Tempo</th>
                        <th style="padding:15px; font-size:11px; text-transform:uppercase; text-align:left;">Nominal</th>
                        <th style="padding:15px; font-size:11px; text-transform:uppercase; text-align:left;">Status</th>
                        <th style="padding:15px; font-size:11px; text-transform:uppercase; text-align:right;">Opsi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($partner_invoices as $p_inv): ?>
                    <tr style="border-bottom:1px solid var(--glass-border); transition:all 0.2s;">
                        <td style="padding:15px;">
                            <div style="font-size:14px; font-weight:800; color:var(--text-primary);">#<?= str_pad($p_inv['id'], 5, "0", STR_PAD_LEFT) ?></div>
                        </td>
                        <td style="padding:15px;">
                            <div style="font-size:13px; font-weight:600; color:var(--text-secondary);"><?= date('d/m/Y', strtotime($p_inv['due_date'])) ?></div>
                        </td>
                        <td style="padding:15px;">
                            <div style="font-size:15px; font-weight:900; color:<?= $p_inv['status'] == 'Lunas' ? 'var(--success)' : 'var(--text-primary)' ?>">Rp<?= number_format($p_inv['amount'], 0, ',', '.') ?></div>
                        </td>
                        <td style="padding:15px;">
                            <span class="badge <?= $p_inv['status'] == 'Lunas' ? 'badge-success' : 'badge-danger' ?>" style="font-size:10px; font-weight:800; padding:4px 12px; border-radius:8px;"><?= strtoupper($p_inv['status']) ?></span>
                        </td>
                        <td style="padding:15px; text-align:right;">
                            <a href="index.php?page=admin_invoices&action=print&id=<?= $p_inv['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" style="width:36px; height:36px; padding:0; border-radius:10px; border:1px solid var(--glass-border);"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Mode -->
        <div class="mobile-only">
            <?php foreach($partner_invoices as $p_inv): ?>
            <div class="glass-panel" style="padding:20px; margin-bottom:15px; border-left:5px solid <?= $p_inv['status'] == 'Lunas' ? 'var(--success)' : 'var(--danger)' ?>; position:relative;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
                    <div>
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Nomor Invoice</div>
                        <div style="font-size:16px; font-weight:900; color:var(--text-primary);">#INV-<?= str_pad($p_inv['id'], 5, "0", STR_PAD_LEFT) ?></div>
                    </div>
                    <span class="badge <?= $p_inv['status'] == 'Lunas' ? 'badge-success' : 'badge-danger' ?>" style="font-size:10px; padding:4px 12px; border-radius:8px;"><?= strtoupper($p_inv['status']) ?></span>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px; padding-bottom:15px; border-bottom:1px solid var(--glass-border);">
                    <div>
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Tanggal Jatuh Tempo</div>
                        <div style="font-size:13px; font-weight:700; color:var(--text-primary);"><?= date('d/m/Y', strtotime($p_inv['due_date'])) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Total Tagihan</div>
                        <div style="font-size:16px; font-weight:900; color:<?= $p_inv['status'] == 'Lunas' ? 'var(--success)' : 'var(--text-primary)' ?>;">Rp<?= number_format($p_inv['amount'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div style="display:flex; gap:10px;">
                    <a href="index.php?page=admin_invoices&action=print&id=<?= $p_inv['id'] ?>" target="_blank" style="flex:1; display:flex; align-items:center; justify-content:center; gap:8px; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--text-primary); text-decoration:none; padding:12px; border-radius:12px; font-weight:700; font-size:13px;">
                        <i class="fas fa-print"></i> CETAK STRUK PEMBAYARAN
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
