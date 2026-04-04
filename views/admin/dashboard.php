<?php
// Deep Cleanup: Hapus semua jejak (Invoice, Payment, Items) dari pelanggan yang sudah tidak ada
$db->exec("DELETE FROM invoices WHERE customer_id NOT IN (SELECT id FROM customers)");
$db->exec("DELETE FROM payments WHERE invoice_id NOT IN (SELECT id FROM invoices)");
$db->exec("DELETE FROM invoice_items WHERE invoice_id NOT IN (SELECT id FROM invoices)");

// Ambil statistik Mendalam
$total_customers = $db->query("SELECT COUNT(*) FROM customers WHERE type='customer'")->fetchColumn();
$total_partners = $db->query("SELECT COUNT(*) FROM customers WHERE type='partner'")->fetchColumn();
$monthly_potential = $db->query("SELECT SUM(monthly_fee) FROM customers")->fetchColumn() ?: 0;

// 1. Total Piutang (Semua yang belum lunas sepanjang masa)
$total_unpaid = $db->query("SELECT SUM(amount) FROM invoices WHERE status='Belum Lunas'")->fetchColumn() ?: 0;

// 2. Target Bulan Ini (Potensi)
$month_target = $db->query("SELECT SUM(amount) FROM invoices WHERE strftime('%Y-%m', due_date) = strftime('%Y-%m', 'now')")->fetchColumn() ?: 0;

// 3. Dana Masuk Bulan Ini (Realtime)
$total_received_month = $db->query("SELECT SUM(amount) FROM payments WHERE strftime('%Y-%m', payment_date) = strftime('%Y-%m', 'now')")->fetchColumn() ?: 0;

// 4. Koleksi Hari Ini
$today_collected = $db->query("SELECT SUM(amount) FROM payments WHERE date(payment_date) = date('now')")->fetchColumn() ?: 0;

// 5. Dari dana masuk bulan ini, berapa yang merupakan tunggakan lama?
$arrears_collected = $db->query("
    SELECT SUM(p.amount) 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    WHERE strftime('%Y-%m', p.payment_date) = strftime('%Y-%m', 'now') 
      AND strftime('%Y-%m', i.due_date) < strftime('%Y-%m', 'now')
")->fetchColumn() ?: 0;

// 6. Realisasi (Persentase terhadap target bulan ini)
$real_pct = $month_target > 0 ? round(($total_received_month / $month_target) * 100, 1) : 0;
?>
<!-- Banner Lisensi -->
<?php if(LICENSE_ST === 'TRIAL'): ?>
    <div class="glass-panel" style="padding: 12px 20px; margin-bottom: 20px; background: rgba(245, 158, 11, 0.1); border-left: 4px solid #f59e0b; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fas fa-clock" style="color: #f59e0b; font-size: 18px;"></i>
            <div style="font-size: 14px; font-weight: 600; color: #f59e0b;"><?= LICENSE_MSG ?></div>
        </div>
        <a href="index.php?page=admin_license" class="btn btn-sm" style="background: #f59e0b; color: white;">Aktivasi Sekarang</a>
    </div>
<?php elseif(LICENSE_ST === 'UNLIMITED'): ?>
    <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
        <div class="badge" style="background: rgba(59, 130, 246, 0.1); color: var(--primary); border: 1px solid var(--primary); padding: 5px 15px; border-radius: 50px; font-weight: 700;">
            <i class="fas fa-crown"></i> UNLIMITED LICENSE (MASTER)
        </div>
    </div>
<?php endif; ?>

<!-- Statistics Grid -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="glass-panel stat-card" style="border-top: 4px solid #3b82f6;">
        <div class="stat-title">Total Pelanggan</div>
        <div class="stat-value" style="color:#3b82f6;"><?= number_format($total_customers, 0) ?> <span style="font-size:14px; font-weight:normal;">User</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Akun Personal Aktif</div>
    </div>
    <div class="glass-panel stat-card" style="border-top: 4px solid #a855f7;">
        <div class="stat-title">Total Mitra (B2B)</div>
        <div class="stat-value" style="color:#a855f7;"><?= number_format($total_partners, 0) ?> <span style="font-size:14px; font-weight:normal;">Mitra</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Kontrak Kerjasama Aktif</div>
    </div>
    <div class="glass-panel stat-card" style="border-top: 4px solid #06b6d4;">
        <div class="stat-title">Potensi Bulanan</div>
        <div class="stat-value" style="color:#06b6d4;">Rp <?= number_format($monthly_potential, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Nilai Seluruh Kontrak/Bulan</div>
    </div>
    <div class="glass-panel stat-card" style="border-top: 4px solid var(--primary);">
        <div class="stat-title">Piutang (Hutang User)</div>
        <div class="stat-value" style="color:var(--danger);">Rp <?= number_format($total_unpaid, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Sisa saldo seluruh pelanggan</div>
    </div>
    <div class="glass-panel stat-card" style="border-top: 4px solid var(--warning);">
        <div class="stat-title">Target Tagihan (Bulan Ini)</div>
        <div class="stat-value">Rp <?= number_format($month_target, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Potensi tagihan terbit bulan ini</div>
    </div>
    <div class="glass-panel stat-card" style="border-top: 4px solid var(--success);">
        <div class="stat-title">Total Masuk (Bulan Ini)</div>
        <div class="stat-value" style="color:var(--success);">Rp <?= number_format($total_received_month, 0, ',', '.') ?></div>
        <div style="font-size:10px; color:var(--text-secondary); margin-top:5px; display:flex; flex-direction:column; gap:2px;">
             <div style="display:flex; justify-content:space-between;">
                 <span>Tagihan Baru:</span>
                 <span style="font-weight:600;">Rp <?= number_format($total_received_month - $arrears_collected, 0, ',', '.') ?></span>
             </div>
             <div style="display:flex; justify-content:space-between;">
                 <span>Kejar Tunggakan:</span>
                 <span style="font-weight:600; color:var(--warning);">Rp <?= number_format($arrears_collected, 0, ',', '.') ?></span>
             </div>
        </div>
        <div style="width:100%; height:4px; background:var(--glass-border); border-radius:10px; overflow:hidden; margin-top:8px;">
             <div style="width:<?= min(100, $real_pct) ?>%; height:100%; background:var(--success);"></div>
        </div>
    </div>
    <div class="glass-panel stat-card" style="border-top: 4px solid #a855f7;">
        <div class="stat-title">Koleksi Hari Ini</div>
        <div class="stat-value" style="color:#a855f7;">Rp <?= number_format($today_collected, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Keberhasilan penagihan hari ini</div>
    </div>
</div>

<?php require __DIR__ . '/../components/wa_broadcast.php'; ?>

<div class="dashboard-secondary-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
<div class="dashboard-secondary-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
    <!-- Financial Pulse Log -->
    <div class="glass-panel" style="padding: 24px;">
        <div style="font-size:18px; font-weight:600; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
             <span><i class="fas fa-satellite-dish" style="color:#2563eb;"></i> Financial Pulse (Realtime)</span>
             <span class="badge badge-primary" style="font-size:10px; animation: pulse 2s infinite;">LIVE</span>
        </div>
        
        <div class="activity-log" style="display:flex; flex-direction:column; gap:12px;">
            <?php
            // Combined Query for Payments and Invoices
            $sql_pulse = "
                (SELECT 'payment' as type, p.amount, p.payment_date as activity_date, c.name as customer_name, u.name as actor_name
                 FROM payments p 
                 JOIN invoices i ON p.invoice_id = i.id 
                 JOIN customers c ON i.customer_id = c.id 
                 LEFT JOIN users u ON p.received_by = u.id)
                UNION ALL
                (SELECT 'invoice' as type, i.amount, i.created_at as activity_date, c.name as customer_name, 'Sistem' as actor_name
                 FROM invoices i 
                 JOIN customers c ON i.customer_id = c.id 
                 WHERE strftime('%Y-%m', i.created_at) = strftime('%Y-%m', 'now'))
                ORDER BY activity_date DESC LIMIT 10
            ";
            // Check if created_on exists, if not use created_at
            try {
                $pulse = $db->query($sql_pulse)->fetchAll();
            } catch(Exception $e) {
                // Fallback if schema is slightly different
                $pulse = $db->query("
                    SELECT 'payment' as type, p.amount, p.payment_date as activity_date, c.name as customer_name, u.name as actor_name
                    FROM payments p 
                    JOIN invoices i ON p.invoice_id = i.id 
                    JOIN customers c ON i.customer_id = c.id 
                    LEFT JOIN users u ON p.received_by = u.id
                    ORDER BY activity_date DESC LIMIT 10
                ")->fetchAll();
            }

            foreach($pulse as $act):
                $is_pay = ($act['type'] === 'payment');
            ?>
            <div style="display:flex; gap:12px; align-items:flex-start; padding:10px; border-radius:12px; background:rgba(255,255,255,0.03); border-left: 3px solid <?= $is_pay ? 'var(--success)' : 'var(--primary)' ?>;">
                <div style="width:36px; height:36px; border-radius:50%; background:<?= $is_pay ? 'rgba(34,197,94,0.1)' : 'rgba(37,99,235,0.1)' ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i class="fas <?= $is_pay ? 'fa-arrow-down text-success' : 'fa-file-invoice text-primary' ?>" style="font-size:14px;"></i>
                </div>
                <div style="flex:1;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($act['customer_name']) ?></div>
                        <div style="font-size:13px; font-weight:700; color:<?= $is_pay ? 'var(--success)' : 'var(--text-primary)' ?>;">
                            <?= $is_pay ? '+' : '' ?>Rp <?= number_format($act['amount'], 0, ',', '.') ?>
                        </div>
                    </div>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:2px;">
                        <?= $is_pay ? 'Pembayaran diterima oleh ' . htmlspecialchars($act['actor_name']) : 'Tagihan baru diterbitkan' ?>
                        • <?= date('d M, H:i', strtotime($act['activity_date'])) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($pulse)): ?>
                <div style="text-align:center; padding:20px; color:var(--text-secondary); font-size:13px;">Belum ada aktivitas finansial.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Late Payers -->
    <div class="glass-panel" style="padding: 24px;">
        <div style="font-size:18px; font-weight:600; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
            <span style="color:var(--danger);"><i class="fas fa-clock"></i> Daftar Tunggakan</span>
            <a href="index.php?page=admin_invoices" class="btn btn-sm btn-ghost">Semua</a>
        </div>

        <!-- Mobile Card View (Hidden on Desktop) -->
        <div class="late-payers-mobile" style="display:none;">
            <?php
            $late_payers = $db->query("
                SELECT i.*, c.name as customer_name, c.contact 
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                WHERE i.status = 'Belum Lunas' AND date(i.due_date) < date('now')
                ORDER BY i.due_date ASC LIMIT 5
            ")->fetchAll();
            
            if(count($late_payers) == 0): ?>
                <div style="text-align:center; padding:20px; color:var(--text-secondary);">Tidak ada tunggakan</div>
            <?php endif; ?>
            
            <?php foreach($late_payers as $lp): ?>
                <div class="late-payer-card-mobile" style="padding:12px 0; border-bottom:1px solid var(--glass-border);">
                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                        <div style="font-weight:600; font-size:14px;"><?= htmlspecialchars($lp['customer_name']) ?></div>
                        <div style="font-weight:bold; font-size:14px; color:var(--danger);">Rp <?= number_format($lp['amount'], 0, ',', '.') ?></div>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div style="font-size:11px; color:var(--text-secondary);"><i class="fas fa-phone"></i> <?= htmlspecialchars($lp['contact']) ?></div>
                        <div style="font-size:11px; color:var(--danger); font-weight:600;">Tempo: <?= date('d/m/Y', strtotime($lp['due_date'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop Table View -->
        <div class="table-container late-payers-desktop">
            <table>
                <thead>
                    <tr>
                        <th>Pelanggan</th>
                        <th>Jatuh Tempo</th>
                        <th>Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($late_payers as $lp): ?>
                        <tr>
                            <td>
                                <div><?= htmlspecialchars($lp['customer_name']) ?></div>
                                <div style="font-size:12px; color:var(--text-secondary);"><i class="fas fa-phone"></i> <?= htmlspecialchars($lp['contact']) ?></div>
                            </td>
                            <td style="color:var(--danger);"><?= date('d/m/Y', strtotime($lp['due_date'])) ?></td>
                            <td style="font-weight:bold;">Rp <?= number_format($lp['amount'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
@media (max-width: 768px) {
    .dashboard-secondary-grid {
        grid-template-columns: 1fr !important;
    }
    .recent-payments-desktop, .late-payers-desktop {
        display: none !important;
    }
    .recent-payments-mobile, .late-payers-mobile {
        display: block !important;
    }
    .glass-panel {
        padding: 16px !important;
    }
}
</style>
