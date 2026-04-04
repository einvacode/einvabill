<?php
// Partner view
$user_id = $_SESSION['user_id'];
$u = $db->query("SELECT customer_id FROM users WHERE id = " . intval($user_id))->fetch();
$partner_cid = $u['customer_id'] ?? 0;
$company_wa = $db->query("SELECT company_contact FROM settings WHERE id=1")->fetchColumn();

// Date filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

$date_where = '';
if ($date_from && $date_to) {
    $date_where = " AND i.due_date BETWEEN " . $db->quote($date_from) . " AND " . $db->quote($date_to);
} elseif ($date_from) {
    $date_where = " AND i.due_date >= " . $db->quote($date_from);
} elseif ($date_to) {
    $date_where = " AND i.due_date <= " . $db->quote($date_to);
}

$status_where = '';
if ($filter_status === 'lunas') $status_where = " AND i.status = 'Lunas'";
elseif ($filter_status === 'belum') $status_where = " AND i.status = 'Belum Lunas'";

// Fetch stats if partner is linked
$partner_stats = null;
$partner_invoices = [];
if ($partner_cid) {
    $partner_stats = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN i.status='Lunas' THEN 1 ELSE 0 END) as lunas,
            SUM(CASE WHEN i.status='Belum Lunas' THEN 1 ELSE 0 END) as belum,
            COALESCE(SUM(CASE WHEN i.status='Lunas' THEN i.amount ELSE 0 END),0) as total_lunas,
            COALESCE(SUM(CASE WHEN i.status='Belum Lunas' THEN i.amount ELSE 0 END),0) as total_belum
        FROM invoices i WHERE i.customer_id = " . intval($partner_cid) . " $date_where $status_where
    ")->fetch();
    
    $partner_invoices = $db->query("
        SELECT i.*, c.name FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE c.id = " . intval($partner_cid) . " $date_where $status_where
        ORDER BY id DESC
    ")->fetchAll();
}
?>

<div class="glass-panel" style="padding: 24px; margin-bottom:20px;">
    <h3 style="font-size:20px; margin-bottom:10px;"><i class="fas fa-handshake" style="color:var(--primary);"></i> Area Mitra Reseller</h3>
    <p style="color:var(--text-secondary); margin-bottom:20px;">Selamat datang di portal kemitraan. Anda dapat melihat riwayat pembayaran bandwidth borongan Anda di sini.</p>
    
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom:20px;">
        <div class="glass-panel" style="padding:20px; text-align:center; border-top:3px solid var(--primary);">
            <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">Status Layanan</div>
            <div style="font-size:24px; font-weight:bold; color:var(--success);"><i class="fas fa-check-circle"></i> AKTIF</div>
        </div>
        <?php if($partner_stats): ?>
        <div class="glass-panel" style="padding:20px; text-align:center; border-top:3px solid var(--success);">
            <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">Lunas</div>
            <div style="font-size:28px; font-weight:800; color:var(--success);"><?= $partner_stats['lunas'] ?></div>
            <div style="font-size:13px; color:var(--success); margin-top:3px;">Rp <?= number_format($partner_stats['total_lunas'], 0, ',', '.') ?></div>
        </div>
        <div class="glass-panel" style="padding:20px; text-align:center; border-top:3px solid var(--danger);">
            <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">Belum Lunas</div>
            <div style="font-size:28px; font-weight:800; color:var(--danger);"><?= $partner_stats['belum'] ?></div>
            <div style="font-size:13px; color:var(--danger); margin-top:3px;">Rp <?= number_format($partner_stats['total_belum'], 0, ',', '.') ?></div>
        </div>
        <?php endif; ?>
        <div class="glass-panel" style="padding:20px; text-align:center; border-top:3px solid var(--warning);">
            <div style="font-size:14px; color:var(--text-secondary); margin-bottom:8px;">Kontak Support</div>
            <div style="font-size:18px; font-weight:bold;"><i class="fab fa-whatsapp" style="color:#25D366;"></i> <?= htmlspecialchars($company_wa ?: 'Belum diatur') ?></div>
        </div>
    </div>
</div>

<!-- Filter Rentang Tanggal -->
<div class="glass-panel" style="padding:20px 24px; margin-bottom:20px;">
    <h4 style="margin-bottom:12px;"><i class="fas fa-search"></i> Filter Riwayat Tagihan</h4>
    <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="page" value="partner">
        <div>
            <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:4px;">Dari Tanggal</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" style="padding:8px 12px; font-size:13px;">
        </div>
        <div>
            <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:4px;">Sampai Tanggal</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" style="padding:8px 12px; font-size:13px;">
        </div>
        <div>
            <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:4px;">Status</label>
            <select name="filter_status" class="form-control" style="padding:8px 12px; font-size:13px;">
                <option value="">Semua</option>
                <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum Lunas</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 16px; height:fit-content;"><i class="fas fa-filter"></i> Filter</button>
        <?php if($date_from || $date_to || $filter_status): ?>
            <a href="index.php?page=partner" class="btn btn-sm btn-ghost" style="padding:8px 16px; height:fit-content;"><i class="fas fa-times"></i> Reset</a>
        <?php endif; ?>
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
    <div class="table-container">
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
                <tr>
                    <td style="font-family:monospace; color:var(--text-secondary);">INV-<?= str_pad($p_inv['id'], 5, "0", STR_PAD_LEFT) ?></td>
                    <td><?= date('d M Y', strtotime($p_inv['due_date'])) ?></td>
                    <td style="font-weight:bold;">Rp <?= number_format($p_inv['amount'], 0, ',', '.') ?></td>
                    <td>
                        <?php if($p_inv['status'] == 'Lunas'): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> Lunas</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,0.2); color:var(--danger); border:1px solid rgba(239,68,68,0.4);">Belum Lunas</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="index.php?page=admin_invoices&action=print&id=<?= $p_inv['id'] ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-print"></i> Struk</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($partner_invoices) == 0 && $partner_cid): ?>
                    <tr><td colspan="5" style="text-align:center; color:var(--text-secondary); padding:30px;">
                        <i class="fas fa-inbox" style="font-size:24px; opacity:0.3; display:block; margin-bottom:8px;"></i>
                        <?= ($date_from || $date_to) ? 'Tidak ada tagihan dalam rentang tanggal ini.' : 'Belum ada riwayat tagihan.' ?>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
