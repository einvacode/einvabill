<?php
/**
 * Dashboard Admin Sederhana & Modern
 * Fokus pada 6 Parameter Utama sesuai permintaan User.
 */

// 1. Total Pelanggan (User) - Count & Est. Revenue
$res_cust = $db->query("SELECT COUNT(*) as jml, SUM(monthly_fee) as est FROM customers WHERE type='customer'")->fetch();
$total_customers = $res_cust['jml'];
$est_revenue_cust = $res_cust['est'] ?: 0;

// 2. Total Mitra (B2B) - Count & Est. Revenue
$res_part = $db->query("SELECT COUNT(*) as jml, SUM(monthly_fee) as est FROM customers WHERE type='partner'")->fetch();
$total_partners = $res_part['jml'];
$est_revenue_part = $res_part['est'] ?: 0;

// 3. Pelanggan Baru Bulan Ini
$new_customers_month = $db->query("SELECT COUNT(*) FROM customers WHERE strftime('%Y-%m', registration_date) = strftime('%Y-%m', 'now')")->fetchColumn();

// 4. Belum Bayar (Piutang) - Count & Net Total
$res_unpaid = $db->query("SELECT COUNT(DISTINCT customer_id) as jml, SUM(amount - discount) as total FROM invoices WHERE status='Belum Lunas'")->fetch();
$count_unpaid = $res_unpaid['jml'];
$total_unpaid = $res_unpaid['total'] ?: 0;

// 5. Lunas Bayar (Total Pendapatan Terkumpul)
$total_received_all = $db->query("SELECT SUM(amount) FROM payments")->fetchColumn() ?: 0;

// 6. Transaksi Cash Bulanan (Hanya tagihan bulan ini yang dibayar bulan ini)
$cash_monthly = $db->query("
    SELECT SUM(p.amount) 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    WHERE strftime('%Y-%m', i.due_date) = strftime('%Y-%m', 'now')
      AND strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now')
")->fetchColumn() ?: 0;
?>

<!-- Banner Lisensi -->
<?php if(LICENSE_ST === 'TRIAL'): ?>
    <div class="glass-panel" style="padding: 12px 20px; margin-bottom: 25px; background: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fas fa-clock" style="color: #f59e0b; font-size: 18px;"></i>
            <div style="font-size: 14px; font-weight: 600; color: #f59e0b;"><?= LICENSE_MSG ?></div>
        </div>
        <a href="index.php?page=admin_license" class="btn btn-sm" style="background: #f59e0b; color: white; border-radius: 8px;">Aktivasi Sekarang</a>
    </div>
<?php elseif(LICENSE_ST === 'UNLIMITED'): ?>
    <div style="margin-bottom: 25px; display: flex; justify-content: flex-end;">
        <div class="badge" style="background: rgba(59, 130, 246, 0.1); color: var(--primary); border: 1px solid var(--primary); padding: 8px 20px; border-radius: 50px; font-weight: 700; font-size: 12px;">
            <i class="fas fa-crown"></i> UNLIMITED MASTER LICENSE
        </div>
    </div>
<?php endif; ?>

<!-- Dashboard Title -->
<div style="margin-bottom: 25px;">
    <h2 style="font-size: 24px; font-weight: 800; color: var(--text-primary);"><i class="fas fa-th-large text-primary" style="margin-right: 10px;"></i> Ringkasan Bisnis</h2>
    <p style="color: var(--text-secondary); font-size: 14px;">Pantau performa operasional dan finansial Anda dalam sekejap.</p>
</div>

<!-- Main Statistics Grid -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px;">
    <style>
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(2, 1fr) !important; } }
        @media (max-width: 640px) { .stats-grid { grid-template-columns: 1fr !important; } }
    </style>
    
    <!-- 1. Pelanggan -->
    <div class="glass-panel" style="border-top: 4px solid #3b82f6; display:flex; align-items:center; gap:12px; padding:12px 15px;">
        <div style="background:rgba(59, 130, 246, 0.1); color:#3b82f6; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-users"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Retail</div>
            <div style="font-size:18px; font-weight:800; line-height:1.2;"><?= number_format($total_customers, 0) ?> <small style="font-size:10px; opacity:0.6;">User</small></div>
            <div style="font-size:10px; color:#3b82f6; font-weight:700; margin-top:2px;">Est: Rp<?= number_format($est_revenue_cust/1000, 0) ?>k</div>
        </div>
    </div>

    <!-- 2. Mitra -->
    <div class="glass-panel" style="border-top: 4px solid #a855f7; display:flex; align-items:center; gap:12px; padding:12px 15px;">
        <div style="background:rgba(168, 85, 247, 0.1); color:#a855f7; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-handshake"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Mitra</div>
            <div style="font-size:18px; font-weight:800; line-height:1.2;"><?= number_format($total_partners, 0) ?> <small style="font-size:10px; opacity:0.6;">B2B</small></div>
            <div style="font-size:10px; color:#a855f7; font-weight:700; margin-top:2px;">Est: Rp<?= number_format($est_revenue_part/1000, 0) ?>k</div>
        </div>
    </div>

    <!-- 3. Pelanggan Baru -->
    <div class="glass-panel" style="border-top: 4px solid #06b6d4; display:flex; align-items:center; gap:12px; padding:12px 15px;">
        <div style="background:rgba(6, 182, 212, 0.1); color:#06b6d4; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-user-plus"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Baru</div>
            <div style="font-size:18px; font-weight:800; line-height:1.2;"><?= number_format($new_customers_month, 0) ?> <small style="font-size:10px; opacity:0.6;">Bln Ini</small></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Growth</div>
        </div>
    </div>

    <!-- 4. Belum Bayar -->
    <div class="glass-panel" style="border-top: 4px solid #ef4444; display:flex; align-items:center; gap:12px; padding:12px 15px;">
        <div style="background:rgba(239, 68, 68, 0.1); color:#ef4444; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Piutang</div>
            <div style="font-size:16px; font-weight:800; color:#ef4444; line-height:1.2;">Rp<?= number_format($total_unpaid, 0, ',', '.') ?></div>
            <div style="font-size:10px; color:#ef4444; font-weight:700; margin-top:2px;"><?= number_format($count_unpaid) ?> User Belum Lunas</div>
        </div>
    </div>

    <!-- 5. Koleksi Dana -->
    <div class="glass-panel" style="border-top: 4px solid #10b981; display:flex; align-items:center; gap:12px; padding:12px 15px;">
        <div style="background:rgba(16, 185, 129, 0.1); color:#10b981; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-coins"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Koleksi</div>
            <div style="font-size:16px; font-weight:800; color:#10b981; line-height:1.2;">Rp<?= number_format($total_received_all, 0, ',', '.') ?></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Total Masuk</div>
        </div>
    </div>

    <!-- 6. Arus Kas -->
    <div class="glass-panel" style="border-top: 4px solid #f59e0b; display:flex; align-items:center; gap:12px; padding:12px 15px;">
        <div style="background:rgba(245, 158, 11, 0.1); color:#f59e0b; width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;">
            <i class="fas fa-money-check-alt"></i>
        </div>
        <div style="flex:1; overflow:hidden;">
            <div style="text-transform:uppercase; font-size:9px; font-weight:800; opacity:0.7; margin-bottom:2px;">Kas</div>
            <div style="font-size:16px; font-weight:800; color:#f59e0b; line-height:1.2;">Rp<?= number_format($cash_monthly, 0, ',', '.') ?></div>
            <div style="font-size:10px; opacity:0.6; margin-top:2px;">Bulan Berjalan</div>
        </div>
    </div>

</div>

<!-- Secondary Components -->
<?php require __DIR__ . '/../components/wa_broadcast.php'; ?>

<!-- Daftar Tunggakan Teragregasi (Per Customer) -->
<div class="glass-panel" style="padding: 24px; margin-top:20px; border-left: 5px solid #ef4444;">
    <div style="font-size:18px; font-weight:800; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-user-clock text-danger"></i> Daftar Tunggakan Pelanggan (Teragregasi)
        </div>
        <a href="index.php?page=admin_invoices&filter_status=belum" class="btn btn-sm btn-ghost" style="font-size:11px;">Lihat Semua</a>
    </div>
    
    <div class="table-container">
        <table style="width:100%;">
            <thead>
                <tr>
                    <th style="padding:12px; font-size:11px;">PELANGGAN</th>
                    <th style="padding:12px; font-size:11px;">PERIODE</th>
                    <th style="padding:12px; font-size:11px;">TOTAL HUTANG</th>
                    <th style="padding:12px; font-size:11px; text-align:right;">AKSI</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $late_summary = $db->query("
                    SELECT 
                        c.id as cust_id, c.name, c.contact, 
                        COUNT(i.id) as months_owed,
                        SUM(i.amount - i.discount) as total_debt
                    FROM invoices i
                    JOIN customers c ON i.customer_id = c.id
                    WHERE i.status = 'Belum Lunas'
                    GROUP BY c.id
                    ORDER BY months_owed DESC, total_debt DESC
                    LIMIT 5
                ")->fetchAll();
                
                foreach($late_summary as $ls):
                ?>
                <tr style="border-bottom:1px solid var(--glass-border);">
                    <td style="padding:12px;">
                        <div style="font-weight:700; font-size:14px; color:var(--text-primary);"><?= htmlspecialchars($ls['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary);"><?= htmlspecialchars($ls['contact']) ?></div>
                    </td>
                    <td style="padding:12px;">
                        <span class="badge" style="background:rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.3); font-size:11px;">
                             <i class="fas fa-history"></i> <?= $ls['months_owed'] ?> Bulan
                        </span>
                    </td>
                    <td style="padding:12px;">
                        <div style="font-weight:800; color:#ef4444; font-size:14px;">Rp <?= number_format($ls['total_debt'], 0, ',', '.') ?></div>
                    </td>
                    <td style="padding:12px; text-align:right;">
                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $ls['contact']) ?>" target="_blank" class="btn btn-sm btn-success" style="padding:5px 10px; font-size:11px;">
                            <i class="fab fa-whatsapp"></i> Tagih
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($late_summary)): ?>
                <tr>
                    <td colspan="4" style="text-align:center; padding:30px; color:var(--text-secondary);">🎉 Tidak ada tunggakan saat ini.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Financial Activity Pulse (Live Monitor) -->
<div class="glass-panel" style="padding: 24px; margin-top:20px;">
    <div style="font-size:18px; font-weight:800; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
        <div style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-satellite-dish text-primary"></i> Monitoring Penagihan (Live)
        </div>
        <span class="badge" style="background:rgba(16, 185, 129, 0.1); color:#10b981; border:1px solid #10b981; font-size:10px; animation: pulse 2s infinite;">• LIVE PULSE</span>
    </div>
    
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap:15px;">
        <?php
        $latest = $db->query("
            SELECT p.*, c.name as customer_name, u.name as receiver_name
            FROM payments p 
            JOIN invoices i ON p.invoice_id = i.id 
            JOIN customers c ON i.customer_id = c.id 
            LEFT JOIN users u ON p.received_by = u.id
            ORDER BY p.payment_date DESC LIMIT 10
        ")->fetchAll();
        
        foreach($latest as $l):
            $is_admin = strpos(strtolower($l['receiver_name'] ?? ''), 'admin') !== false;
        ?>
        <div style="display:flex; align-items:center; gap:15px; padding:15px; background:rgba(255,255,255,0.03); border-radius:15px; border:1px solid var(--glass-border); border-left:4px solid #10b981;">
            <div style="width:45px; height:45px; border-radius:12px; background:rgba(16,185,129,0.1); color:#10b981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="fas fa-check-double"></i>
            </div>
            <div style="flex:1;">
                <div style="font-weight:700; font-size:14px; color:var(--text-primary);"><?= htmlspecialchars($l['customer_name']) ?></div>
                <div style="font-size:11px; color:var(--text-secondary);">
                    Diterima oleh: <span style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($l['receiver_name'] ?? 'Sistem') ?></span>
                    <br>
                    <?= date('d M, H:i', strtotime($l['payment_date'])) ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-weight:900; color:#10b981; font-size:16px;">+Rp <?= number_format($l['amount'], 0, ',', '.') ?></div>
                <div style="font-size:9px; text-transform:uppercase; font-weight:800; color:var(--text-secondary);">Lunas</div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($latest)): ?>
            <div style="grid-column: 1/-1; text-align:center; padding:40px; color:var(--text-secondary); font-size:14px;">
                <i class="fas fa-inbox fa-2x" style="display:block; margin-bottom:10px; opacity:0.3;"></i>
                Belum ada aktivitas penagihan yang tercatat.
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.6; }
    100% { opacity: 1; }
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.05);
}
</style>
