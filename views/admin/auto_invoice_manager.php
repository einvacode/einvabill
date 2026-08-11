<?php
/**
 * Admin Auto Invoice Manager
 * Interface untuk menjalankan auto-generate invoices dan melihat history
 */

if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php?page=admin_dashboard");
    exit;
}

require_once __DIR__ . '/../../app/auto_invoice_generator.php';

$action = $_GET['action'] ?? 'view';
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$report = null;

if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'run'; // 'run' atau 'simulate'
    $simulate = ($mode === 'simulate');
    
    $report = generate_invoices_auto($db, $tenant_id, $simulate);
    
    // Simpan report ke database untuk history
    if (!$simulate) {
        try {
            $report_json = json_encode($report);
            $db->prepare("
                INSERT INTO auto_invoice_logs (tenant_id, report_json, created_at)
                VALUES (?, ?, CURRENT_TIMESTAMP)
            ")->execute([$tenant_id, $report_json]);
        } catch (Exception $e) {
            // Table might not exist yet, ignore
        }
    }
}

// Get history logs
$logs = [];
try {
    $logs = $db->query("
        SELECT * FROM auto_invoice_logs 
        WHERE tenant_id = $tenant_id
        ORDER BY created_at DESC 
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist yet
}
?>

<div class="glass-panel" style="padding:30px; margin-bottom:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
        <div>
            <h2 style="margin:0; font-size:24px; font-weight:800;"><i class="fas fa-magic text-primary"></i> Auto-Generate Tagihan</h2>
            <p style="margin:8px 0 0; font-size:13px; color:var(--text-secondary);">Otomatis buat tagihan berdasarkan tanggal tagihan pelanggan</p>
        </div>
        <a href="index.php?page=admin_dashboard" class="btn btn-sm btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <!-- Info Box -->
    <div style="background:rgba(59, 130, 246, 0.1); border:1px solid rgba(59, 130, 246, 0.3); border-radius:12px; padding:20px; margin-bottom:30px;">
        <div style="display:flex; gap:15px; align-items:flex-start;">
            <i class="fas fa-info-circle" style="font-size:20px; color:#3b82f6; margin-top:2px;"></i>
            <div>
                <h4 style="margin:0 0 8px; color:#3b82f6; font-weight:700;">Bagaimana Cara Kerjanya?</h4>
                <p style="margin:0; font-size:13px; color:var(--text-secondary); line-height:1.6;">
                    Sistem akan <strong>otomatis membuat tagihan</strong> untuk pelanggan yang sudah mencapai tanggal tagihan mereka di bulan ini. 
                    Setiap pelanggan memiliki <strong>"Tanggal Tagihan" (billing_date)</strong> yang bisa diatur di profil pelanggan. 
                    Jika hari ini ≥ tanggal tagihan pelanggan DAN belum ada tagihan bulan ini, sistem akan otomatis membuatnya.
                </p>
            </div>
        </div>
    </div>

    <!-- Control Panel -->
    <div class="glass-panel" style="background:rgba(255,255,255,0.02); border:1px solid var(--glass-border); padding:24px; margin-bottom:30px;">
        <h3 style="margin:0 0 20px; font-size:16px; font-weight:800;"><i class="fas fa-cog text-warning"></i> Kontrol Proses</h3>
        
        <form method="POST" action="index.php?page=admin_auto_invoice&action=run" style="display:flex; gap:15px; flex-wrap:wrap;">
            <button type="submit" name="mode" value="simulate" class="btn btn-info" style="font-weight:800; padding:12px 24px;">
                <i class="fas fa-binoculars"></i> TEST DULU (Simulasi)
            </button>
            <button type="submit" name="mode" value="run" class="btn btn-success" style="font-weight:800; padding:12px 24px;" onclick="return confirm('Jalankan auto-generate sekarang? Sistem akan membuat tagihan untuk semua pelanggan yang sudah mencapai tanggal tagihan mereka.')">
                <i class="fas fa-play"></i> JALANKAN SEKARANG
            </button>
        </form>
        
        <div style="margin-top:15px; padding:12px; background:rgba(250,204,21,0.1); border-radius:8px; border-left:3px solid #fcc34d; font-size:12px; color:var(--text-secondary);">
            <strong>💡 Tips:</strong> Selalu test dengan "Simulasi" terlebih dahulu sebelum jalankan di production.
        </div>
    </div>

    <?php if ($report): ?>
    <!-- Report Results -->
    <div class="glass-panel" style="background:rgba(255,255,255,0.02); border:1px solid var(--glass-border); padding:24px; margin-bottom:30px; border-top:4px solid <?= $report['simulate'] ? '#fcc34d' : '#10b981' ?>;">
        <h3 style="margin:0 0 20px; font-size:16px; font-weight:800;">
            <i class="fas fa-check-circle" style="color:<?= $report['simulate'] ? '#fcc34d' : '#10b981' ?>"></i>
            <?= $report['simulate'] ? 'Hasil Simulasi' : 'Proses Selesai' ?>
        </h3>

        <!-- Stats -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:30px;">
            <div style="background:rgba(var(--primary-rgb), 0.05); padding:15px; border-radius:10px; border-left:3px solid var(--primary);">
                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:5px;">Total Pelanggan</div>
                <div style="font-size:24px; font-weight:800;"><?= $report['customers_processed'] ?></div>
            </div>
            <div style="background:rgba(16, 185, 129, 0.05); padding:15px; border-radius:10px; border-left:3px solid #10b981;">
                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:5px;">Tagihan Dibuat</div>
                <div style="font-size:24px; font-weight:800; color:#10b981;"><?= $report['invoices_created'] ?></div>
            </div>
            <div style="background:rgba(107, 114, 128, 0.05); padding:15px; border-radius:10px; border-left:3px solid #6b7280;">
                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:5px;">Dilewati</div>
                <div style="font-size:24px; font-weight:800; color:#6b7280;"><?= $report['invoices_skipped'] ?></div>
            </div>
            <?php if (!empty($report['errors'])): ?>
            <div style="background:rgba(239, 68, 68, 0.05); padding:15px; border-radius:10px; border-left:3px solid #ef4444;">
                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:5px;">Error</div>
                <div style="font-size:24px; font-weight:800; color:#ef4444;"><?= count($report['errors']) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Error Messages -->
        <?php if (!empty($report['errors'])): ?>
        <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:10px; padding:15px; margin-bottom:20px;">
            <h4 style="margin:0 0 10px; color:#ef4444; font-weight:700;"><i class="fas fa-exclamation-circle"></i> Error Ditemukan</h4>
            <ul style="margin:0; padding-left:20px; font-size:12px;">
                <?php foreach ($report['errors'] as $err): ?>
                <li style="color:var(--text-secondary); margin-bottom:5px;"><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Details Table -->
        <div style="border-radius:10px; border:1px solid var(--glass-border); overflow:hidden;">
            <table style="width:100%; font-size:12px;">
                <thead>
                    <tr style="background:rgba(var(--primary-rgb), 0.05); border-bottom:1px solid var(--glass-border);">
                        <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Pelanggan</th>
                        <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Tgl Tagihan</th>
                        <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Jatuh Tempo</th>
                        <th style="padding:12px; text-align:right; font-weight:700; color:var(--text-secondary);">Nominal</th>
                        <th style="padding:12px; text-align:center; font-weight:700; color:var(--text-secondary);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['details'] as $detail): ?>
                    <tr style="border-bottom:1px solid var(--glass-border);">
                        <td style="padding:12px; vertical-align:middle;">
                            <strong><?= htmlspecialchars($detail['customer_name']) ?></strong>
                            <br><span style="font-size:10px; color:var(--text-secondary); opacity:0.7;">ID: <?= $detail['customer_id'] ?></span>
                        </td>
                        <td style="padding:12px; vertical-align:middle;"><?= $detail['billing_date'] ?></td>
                        <td style="padding:12px; vertical-align:middle; font-family:monospace;"><?= $detail['due_date'] ?></td>
                        <td style="padding:12px; text-align:right; vertical-align:middle; font-weight:600;">
                            Rp <?= number_format($detail['amount'], 0, ',', '.') ?>
                        </td>
                        <td style="padding:12px; text-align:center; vertical-align:middle;">
                            <?php 
                            $status_class = '';
                            $status_icon = '';
                            $status_color = '';
                            
                            if (strpos($detail['status'], 'CREATED') !== false || strpos($detail['status'], 'WOULD') !== false) {
                                $status_color = '#10b981';
                                $status_icon = 'fa-check-circle';
                                $status_class = 'SUCCESS';
                            } elseif (strpos($detail['status'], 'SKIPPED') !== false || strpos($detail['status'], 'WAITING') !== false) {
                                $status_color = '#6b7280';
                                $status_icon = 'fa-info-circle';
                                $status_class = 'SKIP';
                            } elseif (strpos($detail['status'], 'ERROR') !== false) {
                                $status_color = '#ef4444';
                                $status_icon = 'fa-exclamation-circle';
                                $status_class = 'ERROR';
                            }
                            ?>
                            <span style="background:rgba(<?= 
                                $status_color === '#10b981' ? '16, 185, 129' : 
                                ($status_color === '#ef4444' ? '239, 68, 68' : '107, 114, 128')
                            ?>, 0.1); color:<?= $status_color ?>; padding:4px 8px; border-radius:4px; font-weight:700; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fas <?= $status_icon ?>"></i>
                                <?= $status_class ?>
                            </span>
                            <?php if (isset($detail['reason'])): ?>
                            <br><span style="font-size:10px; color:var(--text-secondary); margin-top:3px; display:block; opacity:0.7;"><?= htmlspecialchars($detail['reason']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- History Logs -->
    <?php if (!empty($logs)): ?>
    <div class="glass-panel" style="background:rgba(255,255,255,0.02); border:1px solid var(--glass-border); padding:24px;">
        <h3 style="margin:0 0 20px; font-size:16px; font-weight:800;"><i class="fas fa-history"></i> Riwayat Eksekusi</h3>
        
        <div style="border-radius:10px; border:1px solid var(--glass-border); overflow:hidden; max-height:400px; overflow-y:auto;">
            <table style="width:100%; font-size:12px;">
                <thead>
                    <tr style="background:rgba(var(--primary-rgb), 0.05); border-bottom:1px solid var(--glass-border); position:sticky; top:0;">
                        <th style="padding:12px; text-align:left; font-weight:700; color:var(--text-secondary);">Waktu</th>
                        <th style="padding:12px; text-align:center; font-weight:700; color:var(--text-secondary);">Dibuat</th>
                        <th style="padding:12px; text-align:center; font-weight:700; color:var(--text-secondary);">Dilewati</th>
                        <th style="padding:12px; text-align:center; font-weight:700; color:var(--text-secondary);">Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): 
                        $log_data = json_decode($log['report_json'], true);
                    ?>
                    <tr style="border-bottom:1px solid var(--glass-border); hover-bg:rgba(255,255,255,0.02);">
                        <td style="padding:12px; vertical-align:middle; font-family:monospace;">
                            <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                        </td>
                        <td style="padding:12px; text-align:center; vertical-align:middle;">
                            <span style="background:rgba(16, 185, 129, 0.1); color:#10b981; padding:4px 8px; border-radius:4px; font-weight:700;">
                                <?= $log_data['invoices_created'] ?? 0 ?>
                            </span>
                        </td>
                        <td style="padding:12px; text-align:center; vertical-align:middle;">
                            <span style="background:rgba(107, 114, 128, 0.1); color:#6b7280; padding:4px 8px; border-radius:4px; font-weight:700;">
                                <?= $log_data['invoices_skipped'] ?? 0 ?>
                            </span>
                        </td>
                        <td style="padding:12px; text-align:center; vertical-align:middle;">
                            <?php $err_count = count($log_data['errors'] ?? []); ?>
                            <span style="background:rgba(<?= $err_count > 0 ? '239, 68, 68' : '16, 185, 129' ?>, 0.1); color:<?= $err_count > 0 ? '#ef4444' : '#10b981' ?>; padding:4px 8px; border-radius:4px; font-weight:700;">
                                <?= $err_count ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div style="text-align:center; padding:40px; background:rgba(255,255,255,0.02); border:1px dashed var(--glass-border); border-radius:10px; color:var(--text-secondary);">
        <i class="fas fa-inbox" style="font-size:32px; opacity:0.5; margin-bottom:10px; display:block;"></i>
        <p style="margin:0; font-size:13px;">Belum ada riwayat eksekusi. Jalankan proses di atas untuk melihat history.</p>
    </div>
    <?php endif; ?>
</div>

<style>
tr:hover {
    background: rgba(255,255,255,0.02) !important;
}
</style>
