<?php
// Partner view
$user_id = intval($_SESSION['user_id']);
$stmt_u = $db->prepare("SELECT customer_id FROM users WHERE id = ?");
$stmt_u->execute([$user_id]);
$u = $stmt_u->fetch();
$partner_cid = $u['customer_id'] ?? 0;

$company_wa = $db->query("SELECT company_contact FROM settings WHERE id=1")->fetchColumn();

// Date filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

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

// Fetch stats if partner is linked
$partner_stats = null;
$partner_invoices = [];
if ($partner_cid) {
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
    
    $stmt_inv = $db->prepare("
        SELECT i.*, c.name FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE c.id = ? $date_where $status_where
        ORDER BY i.id DESC
    ");
    $stmt_inv->execute($params);
    $partner_invoices = $stmt_inv->fetchAll();

    // Fetch items for these invoices
    $partner_invoice_items = [];
    if (!empty($partner_invoices)) {
        $p_inv_ids = array_column($partner_invoices, 'id');
        $p_ids_str = implode(',', $p_inv_ids);
        $items_raw = $db->query("SELECT * FROM invoice_items WHERE invoice_id IN ($p_ids_str)")->fetchAll();
        foreach ($items_raw as $item) {
            $partner_invoice_items[$item['invoice_id']][] = $item;
        }
    }
}

// Fetch Active Banners
$active_banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'partner') ORDER BY created_at DESC")->fetchAll();

// NEW: My Own Business Stats (Scoping) - Only count PAST DUE as Tunggakan
$today = date('Y-m-d');
$my_cust_count = $db->query("SELECT COUNT(*) FROM customers WHERE created_by = $user_id")->fetchColumn();
$my_inv_unpaid = $db->query("SELECT COALESCE(SUM(amount), 0) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas' AND i.due_date < '$today'")->fetchColumn();
$count_unpaid = $db->query("SELECT COUNT(DISTINCT i.customer_id) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas' AND i.due_date < '$today'")->fetchColumn();
$my_income_month = $db->query("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND p.payment_date LIKE '" . date('Y-m') . "%'")->fetchColumn();

// Extra Metrics for Partner
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$three_days_later = date('Y-m-d', strtotime('+3 days'));
$due_today = $db->query("SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.due_date = '$today' AND i.status = 'Belum Lunas'")->fetchColumn();
$due_soon = $db->query("SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas' AND i.due_date >= '$tomorrow' AND i.due_date <= '$three_days_later'")->fetchColumn();
$new_this_month = $db->query("SELECT COUNT(*) FROM customers WHERE created_by = $user_id AND strftime('%Y-%m', registration_date) = '" . date('Y-m') . "'")->fetchColumn();

// NEW: Dashboard Summary (Recent Revenue & High-level tasks)
$recent_revenue = $db->query("
    SELECT i.*, c.name as customer_name, p.amount as paid_amount, p.payment_date 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    JOIN customers c ON i.customer_id = c.id 
    WHERE c.created_by = $user_id 
    ORDER BY p.payment_date DESC 
    LIMIT 5
")->fetchAll();

// Global Settings
$settings = $db->query("SELECT * FROM settings WHERE id = 1")->fetch();
$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();

// Partner Branding & Custom Templates
$u_id = $_SESSION['user_id'];
$p_stg = $db->query("SELECT wa_template_paid, brand_bank, brand_rekening FROM users WHERE id = $u_id")->fetch();

$wa_tpl_paid = (!empty($p_stg['wa_template_paid'])) ? $p_stg['wa_template_paid'] : ($settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sebesar {nominal} sudah LUNAS. Terima kasih telah berlangganan.");
$rekening_receipt = (!empty($p_stg['brand_bank'])) ? $p_stg['brand_bank'] . " " . $p_stg['brand_rekening'] : $settings['bank_account'];

// Success Modal Data for Partner Dashboard
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact']));
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas'")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $status_wa = ($tunggakan_val > 0) ? "LUNAS SEBAGIAN (Masih ada sisa tunggakan)" : "LUNAS SEPENUHNYA";
        
        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        // Replace Tags
        $receipt_msg = str_replace(
            [
                '{nama}', '{id_cust}', '{tagihan}', '{paket}', '{bulan}', 
                '{tunggakan}', '{waktu_bayar}', '{admin}', '{link_tagihan}', '{rekening}', '{nominal}', '{status_pembayaran}', '{sisa_tunggakan}', '{total_bayar}'
            ], 
            [
                $success_data['name'], 
                ($success_data['customer_code'] ?: $success_data['id']), 
                'Rp ' . number_format($success_data['monthly_fee'], 0, ',', '.'),
                ($success_data['package_name'] ?: '-'),
                $months_paid . ' Bulan',
                $tunggakan_display,
                date('d/m/Y H:i') . ' WIB',
                $_SESSION['user_name'],
                $portal_link,
                $rekening_receipt,
                $total_display,
                $status_wa,
                $tunggakan_display,
                $total_display
            ], 
            $wa_tpl_paid
        );
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?>

<?php if($success_data): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--success); padding:20px; animation: slideDown 0.4s ease-out; background:rgba(16,185,129,0.1);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
        <div>
            <h3 style="margin:0; color:var(--success); font-size:18px;"><i class="fas fa-check-circle"></i> Pembayaran Berhasil!</h3>
            <p style="margin:5px 0 0; font-size:13px; color:var(--text-secondary);">Tagihan untuk <strong><?= htmlspecialchars($success_data['name']) ?></strong> telah diperbarui.</p>
        </div>
        <button onclick="this.parentElement.parentElement.style.display='none'" style="background:none; border:none; color:var(--text-secondary); cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="<?= $success_data['wa_link'] ?>" target="_blank" class="btn" style="background:#25D366; color:white; flex:1; min-width:150px; padding:12px; font-weight:700; text-align:center; text-decoration:none; border-radius:10px;">
            <i class="fab fa-whatsapp"></i> Kirim Notifikasi WA
        </a>
        <a href="index.php?page=admin_invoices&action=print&id=<?= intval($_GET['last_id'] ?? 0) ?>&format=thermal" target="_blank" class="btn btn-ghost" style="flex:1; min-width:150px; padding:12px; font-weight:700; border-radius:10px; text-align:center; text-decoration:none; border:1px solid var(--glass-border);">
            <i class="fas fa-print"></i> Cetak Struk
        </a>
    </div>
</div>
<?php endif; ?>

<?php if(count($active_banners) > 0): ?>
<div class="banner-container" style="margin-bottom:20px;">
    <?php foreach($active_banners as $banner): ?>
        <div class="glass-panel banner-item" style="padding:20px; border-radius:16px; border-left:4px solid var(--primary); display:flex; gap:20px; align-items:center; margin-bottom:12px; position:relative; overflow:hidden;">
            <?php if($banner['image_path']): ?>
                <div class="banner-img" style="flex-shrink:0;">
                    <img src="<?= $banner['image_path'] ?>" style="width:120px; height:80px; object-fit:cover; border-radius:12px; cursor:zoom-in;" onclick="openImagePreview(this.src)" title="Klik untuk perbesar">
                </div>
            <?php endif; ?>
            <div class="banner-text">
                <h4 style="margin:0 0 5px 0; color:var(--text-primary); font-size:16px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-bullhorn" style="color:var(--primary); font-size:14px;"></i> <?= htmlspecialchars($banner['title']) ?>
                </h4>
                <p style="margin:0; font-size:13px; color:var(--text-secondary); line-height:1.5;"><?= nl2br(htmlspecialchars($banner['content'])) ?></p>
                <div style="font-size:10px; color:var(--text-secondary); opacity:0.5; margin-top:8px;">
                    <i class="far fa-clock"></i> Diposting pada <?= date('d M Y', strtotime($banner['created_at'])) ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="glass-panel" style="padding: 24px; margin-bottom:20px;">
    <div class="grid-header" style="margin-bottom:15px;">
        <h3 style="font-size:20px; margin:0;"><i class="fas fa-handshake text-primary"></i> Area Mitra Reseller</h3>
        <span class="badge badge-success" style="padding:6px 12px; font-weight:700;"><i class="fas fa-check-circle"></i> LAYANAN AKTIF</span>
    </div>
    <p style="color:var(--text-secondary); margin-bottom:20px; font-size:14px; opacity:0.8;">Selamat datang di portal kemitraan. Pantau billing dan kelola penagihan pelanggan Anda secara realtime.</p>
    
    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
        <?php if($partner_stats): ?>
        <div class="stat-card glass-panel" style="border-top:4px solid var(--success); background:linear-gradient(135deg, rgba(16, 185, 129, 0.1), transparent);">
            <div class="stat-title" style="color:var(--success); font-weight:700;">TERBAYAR KE ISP</div>
            <div class="stat-value" style="color:var(--success); font-size:22px;"><?= $partner_stats['lunas'] ?></div>
            <div style="font-size:13px; font-weight:800; margin-top:5px;">Rp <?= number_format($partner_stats['total_lunas'], 0, ',', '.') ?></div>
        </div>
        <div class="stat-card glass-panel" style="border-top:4px solid var(--danger); background:linear-gradient(135deg, rgba(239, 68, 68, 0.1), transparent);">
            <div class="stat-title" style="color:var(--danger); font-weight:700;">BELUM BAYAR KE ISP</div>
            <div class="stat-value" style="color:var(--danger); font-size:22px;"><?= $partner_stats['belum'] ?></div>
            <div style="font-size:13px; font-weight:800; margin-top:5px;">Rp <?= number_format($partner_stats['total_belum'], 0, ',', '.') ?></div>
        </div>
        <?php endif; ?>
        <div class="stat-card glass-panel" style="border-top:4px solid var(--warning); background:linear-gradient(135deg, rgba(245, 158, 11, 0.1), transparent);">
            <div class="stat-title" style="color:var(--warning); font-weight:700;">SUPPORT ISP</div>
            <div class="stat-value" style="font-size:16px; margin-top:10px;"><i class="fab fa-whatsapp" style="color:#25D366;"></i> <?= htmlspecialchars($company_wa ?: 'N/A') ?></div>
            <div style="font-size:10px; color:var(--text-secondary); margin-top:5px;">Bantuan & Teknis</div>
        </div>
    </div>
</div>

<!-- NEW: My Business Summary -->
<div class="glass-panel" style="padding: 24px; margin-bottom:20px; border-left:4px solid var(--success);">
    <!-- NEW: Progress Bar (Collector Style) -->
    <?php
    $total_unpaid_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND i.status = 'Belum Lunas'")->fetchColumn();
    $total_paid_val = $db->query("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id WHERE c.created_by = $user_id AND strftime('%Y-%m', p.payment_date) = '" . date('Y-m') . "'")->fetchColumn();
    $total_potential = $total_unpaid_val + $total_paid_val;
    $percent_paid = $total_potential > 0 ? round(($total_paid_val / $total_potential) * 100) : 0;
    ?>
    <div style="margin-bottom:20px; padding:15px; background:rgba(255,255,255,0.03); border-radius:12px; border:1px solid var(--glass-border);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <span style="font-size:12px; font-weight:700; color:var(--text-secondary);"><i class="fas fa-chart-line text-success"></i> Progres Penagihan Bulan Ini</span>
            <span style="font-size:14px; font-weight:900; color:<?= $percent_paid >= 80 ? 'var(--success)' : ($percent_paid >= 50 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $percent_paid ?>%</span>
        </div>
        <div style="background:var(--progress-bg); border-radius:10px; height:8px; overflow:hidden;">
            <div style="width:<?= $percent_paid ?>%; height:100%; background:linear-gradient(to right, <?= $percent_paid >= 80 ? 'var(--success), #34d399' : ($percent_paid >= 50 ? 'var(--warning), #fbbf24' : 'var(--danger), #f87171') ?>); border-radius:10px; transition:width 1s ease;"></div>
        </div>
    </div>

    <div class="grid-header" style="margin-bottom:20px;">
        <h3 style="font-size:18px; margin:0;"><i class="fas fa-chart-pie text-success"></i> Ringkasan Bisnis Saya</h3>
        <a href="index.php?page=admin_customers&action=create" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Pelanggan Baru</a>
    </div>

    <div class="grid-items" style="gap:15px;">
        <a href="index.php?page=admin_customers" style="text-decoration:none; display:block; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); height:100%;">
                <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.5px;">Pelanggan Saya</div>
                <div style="font-size:24px; font-weight:800; color:var(--text-primary);"><?= $my_cust_count ?></div>
                <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Total Database <i class="fas fa-chevron-right" style="font-size:9px; margin-left:5px; opacity:0.5;"></i></div>
            </div>
        </a>
        
        <a href="index.php?page=admin_invoices&date_from=<?= date('Y-m-d') ?>&date_to=<?= date('Y-m-d') ?>&filter_status=belum" style="text-decoration:none; display:block; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); height:100%;">
                <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.5px;">Tagihan Hari Ini</div>
                <div style="font-size:24px; font-weight:800; color:var(--warning);"><?= $due_today ?></div>
                <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Jatuh Tempo Hari Ini <i class="fas fa-chevron-right" style="font-size:9px; margin-left:5px; opacity:0.5;"></i></div>
            </div>
        </a>

        <a href="index.php?page=admin_invoices&date_from=<?= $tomorrow ?>&date_to=<?= $three_days_later ?>&filter_status=belum" style="text-decoration:none; display:block; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); height:100%;">
                <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.5px;">Akan Jatuh Tempo</div>
                <div style="font-size:24px; font-weight:800; color:var(--primary);"><?= $due_soon ?></div>
                <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Mendekati (H+3) <i class="fas fa-chevron-right" style="font-size:9px; margin-left:5px; opacity:0.5;"></i></div>
            </div>
        </a>

        <a href="index.php?page=admin_invoices&filter_status=belum" style="text-decoration:none; display:block; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); height:100%;">
                <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.5px;">Pelanggan Menunggak</div>
                <div style="font-size:20px; font-weight:800; color:var(--danger);"><?= $count_unpaid ?></div>
                <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Rp <?= number_format($my_inv_unpaid, 0, ',', '.') ?> <i class="fas fa-chevron-right" style="font-size:9px; margin-left:5px; opacity:0.5;"></i></div>
            </div>
        </a>

        <a href="index.php?page=admin_customers&filter_month=<?= date('Y-m') ?>" style="text-decoration:none; display:block; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); height:100%;">
                <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.5px;">Pendaftaran Baru</div>
                <div style="font-size:24px; font-weight:800; color:var(--primary);"><?= $new_this_month ?></div>
                <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Bulan <?= date('F') ?> <i class="fas fa-chevron-right" style="font-size:9px; margin-left:5px; opacity:0.5;"></i></div>
            </div>
        </a>

        <a href="index.php?page=admin_reports&date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-t') ?>" style="text-decoration:none; display:block; transition:transform 0.2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
            <div style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); height:100%;">
                <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.5px;">Penerimaan Tunai</div>
                <div style="font-size:20px; font-weight:800; color:var(--success);">Rp <?= number_format($my_income_month, 0, ',', '.') ?></div>
                <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Setoran Terkumpul <i class="fas fa-chevron-right" style="font-size:9px; margin-left:5px; opacity:0.5;"></i></div>
            </div>
        </a>
    </div>
</div>

<!-- Dashboard Summary: Recent Revenue -->
<div class="glass-panel" style="padding: 24px; margin-bottom:20px; border-left:4px solid var(--success);">
    <div class="grid-header" style="margin-bottom:15px;">
        <h3 style="font-size:18px; margin:0;"><i class="fas fa-history text-success"></i> Pendapatan Terbaru Saya</h3>
        <a href="index.php?page=partner_collection" class="btn btn-primary btn-sm"><i class="fas fa-motorcycle"></i> Mulai Penagihan</a>
    </div>

    <div class="table-container">
        <table style="width:100%; font-size:13px;">
            <thead>
                <tr style="border-bottom:1px solid var(--glass-border); text-align:left;">
                    <th style="padding:12px 0;">Pelanggan</th>
                    <th style="padding:12px 0;">Nominal</th>
                    <th style="padding:12px 0; text-align:right;">Waktu Bayar</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($recent_revenue)): ?>
                    <tr><td colspan="3" style="padding:30px; text-align:center; color:var(--text-secondary);">Belum ada riwayat pembayaran bulan ini.</td></tr>
                <?php else: ?>
                    <?php foreach($recent_revenue as $rev): ?>
                    <tr style="border-bottom:1px solid var(--glass-border);">
                        <td style="padding:15px 0;">
                            <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($rev['customer_name']) ?></div>
                        </td>
                        <td style="padding:15px 0; font-weight:800; color:var(--success);">+ Rp <?= number_format($rev['paid_amount'], 0, ',', '.') ?></td>
                        <td style="text-align:right; color:var(--text-secondary); font-size:12px;">
                            <i class="fas fa-check-circle text-success"></i> <?= date('d/m H:i', strtotime($rev['payment_date'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Filter Rentang Tanggal -->
<div class="glass-panel" style="padding:24px; margin-bottom:20px;">
    <h4 style="margin-bottom:15px; font-size:16px; font-weight:800;"><i class="fas fa-search text-primary"></i> Filter Riwayat Tagihan Utama</h4>
    <form method="GET" class="grid-filters">
        <input type="hidden" name="page" value="partner">
        <div class="filter-group">
            <label>Jatuh Tempo</label>
            <div class="filter-date-range">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" style="font-size:12px; flex:1; min-width:0;">
                <span style="opacity:0.3;">s/d</span>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" style="font-size:12px; flex:1; min-width:0;">
            </div>
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="filter_status" class="form-control" style="font-size:13px;">
                <option value="">Semua Status</option>
                <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum Lunas</option>
            </select>
        </div>
        <div class="grid-actions">
            <button type="submit" class="btn btn-primary" style="height:46px; font-weight:700; width:100%;"><i class="fas fa-filter"></i> Apply Filter</button>
            <?php if($date_from || $date_to || $filter_status): ?>
                <a href="index.php?page=partner" class="btn btn-ghost" style="height:46px; border:1px solid var(--glass-border); line-height:44px;"><i class="fas fa-times"></i> Reset</a>
            <?php endif; ?>
        </div>
    </form>
    
    <?php if($date_from || $date_to): ?>
    <div style="display:flex; gap:20px; margin-top:12px; padding-top:12px; border-top:1px solid var(--glass-border); flex-wrap:wrap;">
        <div style="font-size:13px;">📊 Ditemukan: <strong><?= count($partner_invoices) ?></strong> tagihan</div>
        <?php if($partner_stats): ?>
        <div style="font-size:13px; color:var(--success);">✅ Lunas: <strong><?= $partner_stats['lunas'] ?></strong> (Rp <?= number_format($partner_stats['total_lunas'], 0, ',', '.') ?>)</div>
        <div style="font-size:13px; color:var(--danger);">🔴 Belum: <strong><?= $partner_stats['belum'] ?></strong> (Rp <?= number_format($partner_stats['total_belum'], 0, ',', '.') ?>)</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Daftar Tagihan -->
<div class="glass-panel" style="padding: 24px;">
    <h4 style="margin-bottom:15px;"><i class="fas fa-file-invoice-dollar"></i> Daftar Tagihan Anda ke ISP Induk</h4>
    <div class="table-container desktop-only">
        <table>
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Jatuh Tempo</th>
                    <th>Nominal</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$partner_cid): ?>
                    <tr><td colspan='5' style='text-align:center; color:var(--danger);'>Akun Anda belum dipasangkan dengan data Mitra manapun oleh Admin.</td></tr>
                <?php endif; ?>
                
                <?php foreach($partner_invoices as $p_inv): ?>
                <tr style="border-bottom:1px solid var(--glass-border);">
                    <td style="padding:15px 12px; vertical-align:top;">
                        <div style="font-family:monospace; color:var(--text-secondary); font-size:12px;">INV-<?= str_pad($p_inv['id'], 5, "0", STR_PAD_LEFT) ?></div>
                        <div style="font-size:11px; color:var(--primary); margin-top:4px;"><i class="fas fa-user-shield"></i> Oleh: Admin Utama</div>
                    </td>
                    <td style="padding:15px 12px; vertical-align:top;">
                        <div style="font-size:13px; font-weight:600;"><?= date('d M Y', strtotime($p_inv['due_date'])) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary);"><?= date('H:i', strtotime($p_inv['created_at'])) ?> WIB</div>
                    </td>
                    <td style="padding:15px 12px; vertical-align:top;">
                        <div style="font-weight:bold; color:var(--text-primary); font-size:14px;">Rp <?= number_format($p_inv['amount'], 0, ',', '.') ?></div>
                        <?php if($p_inv['discount'] > 0): ?>
                            <div style="font-size:11px; color:var(--danger);">- Rp <?= number_format($p_inv['discount'], 0, ',', '.') ?> (Potongan)</div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:15px 12px; vertical-align:top;">
                        <?php if($p_inv['status'] == 'Lunas'): ?>
                            <span class="badge badge-success" style="font-size:10px;"><i class="fas fa-check"></i> Lunas</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,0.15); color:var(--danger); border:1px solid rgba(239,68,68,0.3); font-size:10px;"><i class="fas fa-clock"></i> Menunggu Bayar</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:15px 12px; vertical-align:top; text-align:right;">
                        <a href="index.php?page=admin_invoices&action=print&id=<?= $p_inv['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" style="padding:6px 12px; font-size:11px;"><i class="fas fa-print"></i> Cetak</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile View: Card-based list to prevent overflow -->
    <div class="mobile-invoice-card-container mobile-invoice-card" style="display:none;">
        <?php foreach($partner_invoices as $p_inv): ?>
            <div class="mobile-invoice-card" style="display:block; padding:18px; margin-bottom:15px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div>
                        <div style="font-family:monospace; color:var(--text-secondary); font-size:11px;">#INV-<?= str_pad($p_inv['id'], 5, "0", STR_PAD_LEFT) ?></div>
                        <div style="font-weight:700; font-size:13px; margin-top:2px;">Kewajiban Ke ISP Induk</div>
                    </div>
                    <?php if($p_inv['status'] == 'Lunas'): ?>
                        <span class="badge badge-success" style="font-size:10px; padding:3px 10px;"><i class="fas fa-check-circle"></i> LUNAS</span>
                    <?php else: ?>
                        <span class="badge badge-danger" style="font-size:10px; padding:3px 10px;"><i class="fas fa-clock"></i> BELUM BAYAR</span>
                    <?php endif; ?>
                </div>

                <div style="background:rgba(255,255,255,0.03); border-radius:12px; padding:12px; margin-bottom:15px; display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div>
                        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Jatuh Tempo</div>
                        <div style="font-size:13px; font-weight:600;"><?= date('d M Y', strtotime($p_inv['due_date'])) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Total Tagihan</div>
                        <div style="font-size:14px; font-weight:800; color:var(--primary);">Rp <?= number_format($p_inv['amount'], 0, ',', '.') ?></div>
                    </div>
                </div>

                <div style="display:flex; gap:10px;">
                    <a href="index.php?page=admin_invoices&action=print&id=<?= $p_inv['id'] ?>" target="_blank" class="btn btn-ghost" style="flex:1; border-radius:10px; font-size:12px;">
                        <i class="fas fa-print"></i> Cetak Nota
                    </a>
                    <div style="flex:1; background:rgba(var(--primary-rgb), 0.1); color:var(--primary); font-size:10px; font-weight:700; border-radius:10px; display:flex; align-items:center; justify-content:center; border:1px solid var(--glass-border); padding:5px; text-align:center;">
                         Konfirmasi Admin Utama
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if(count($partner_invoices) == 0 && $partner_cid): ?>
            <div style="text-align:center; color:var(--text-secondary); padding:40px;">
                <i class="fas fa-inbox" style="font-size:32px; opacity:0.2; display:block; margin-bottom:15px;"></i>
                Belum ada data tagihan.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden Form for Quick Pay -->
<form id="quickPayForm" action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST" style="display:none;">
    <input type="hidden" name="customer_id" id="qp_cust_id">
    <input type="hidden" name="num_months" id="qp_num_months">
</form>

<script>
function quickPay(custId, name, months, total) {
    const formattedTotal = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(total);
    if (confirm(`Konfirmasi pembayaran dari ${name}?\n\nTotal: ${formattedTotal} (${months} Bulan)\n\nTindakan ini akan menandai tagihan sebagai LUNAS.`)) {
        document.getElementById('qp_cust_id').value = custId;
        document.getElementById('qp_num_months').value = months;
        document.getElementById('quickPayForm').submit();
    }
}
</script>
