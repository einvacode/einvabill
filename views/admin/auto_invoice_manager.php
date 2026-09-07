<?php
/**
 * Admin Auto Invoice Manager
 * Interface untuk menjalankan auto-generate invoices dan melihat history
 */

if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php?page=admin_dashboard");
    exit;
}

require_once __DIR__ . '/../../app/auto_invoice_generator.php'; // already loaded by init.php; kept for direct includes

$action = $_GET['action'] ?? 'view';
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$report = null;

// Pengaturan mode otomatis (dijalankan saat admin membuka dashboard) dan hari pembuatan sebelum jatuh tempo
$auto_msg = '';
if ($action === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled = !empty($_POST['auto_invoice_enabled']) ? 1 : 0;
    $lead = max(0, min(15, intval($_POST['auto_invoice_lead_days'] ?? 3)));
    try {
        $db->prepare("UPDATE settings SET auto_invoice_enabled = ?, auto_invoice_lead_days = ? WHERE tenant_id = ?")->execute([$enabled, $lead, $tenant_id]);
        unset($_SESSION['auto_invoice_last_run_' . $tenant_id]);
        $auto_msg = 'Pengaturan auto tagihan tersimpan.';
    } catch (Exception $e) { $auto_msg = 'Gagal menyimpan pengaturan.'; }
}
$auto_cfg = auto_invoice_settings($db, (int)$tenant_id);

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

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Auto-generate tagihan</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Otomatis buat tagihan berdasarkan tanggal tagihan pelanggan</p>
    </div>
    <a href="index.php?page=admin_dashboard" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<!-- Info Box -->
<div class="ui-card mb-5 p-4 sm:p-5">
    <h3 class="m-0 mb-1 text-[15px] font-bold">Bagaimana cara kerjanya?</h3>
    <p class="m-0 text-sm leading-relaxed text-muted-foreground">
        Setiap pelanggan dan POP punya <strong>tanggal tagihan</strong> di profilnya. Tagihan bulan berjalan dibuat otomatis
        <strong><?= (int)$auto_cfg['lead_days'] ?> hari sebelum</strong> tanggal itu, satu tagihan per pelanggan per bulan, dengan jatuh tempo pada tanggal tagihannya.
        Dengan begitu pengingat H-3 di dashboard sempat terkirim sebelum jatuh tempo. Pelanggan yang didaftarkan mitra tidak ikut; itu ditagih oleh mitra.
        <?= $auto_cfg['enabled'] ? 'Mode otomatis <strong>aktif</strong>: proses berjalan sendiri setiap admin membuka dashboard (paling sering sekali per jam).' : 'Mode otomatis <strong>nonaktif</strong>: tagihan hanya dibuat saat tombol "Jalankan sekarang" ditekan atau lewat cron.' ?>
    </p>
</div>

<?php if ($auto_msg): ?>
<div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> <?= htmlspecialchars($auto_msg) ?></div>
<?php endif; ?>

<div class="ui-card mb-5 p-4 sm:p-5">
    <h3 class="m-0 mb-3 text-[15px] font-bold">Pengaturan otomatis</h3>
    <form method="POST" action="index.php?page=admin_auto_invoice&action=settings" class="grid gap-4 sm:grid-cols-[1fr_220px_auto] sm:items-end">
<?= csrf_field() ?>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="auto_invoice_enabled" value="1" <?= $auto_cfg['enabled'] ? 'checked' : '' ?>>
            Buat tagihan otomatis saat admin membuka dashboard
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Dibuat berapa hari sebelum tanggal tagihan</span>
            <input type="number" name="auto_invoice_lead_days" class="form-control" min="0" max="15" value="<?= (int)$auto_cfg['lead_days'] ?>">
        </label>
        <button type="submit" class="ui-btn ui-btn-primary">Simpan</button>
    </form>
    <p class="m-0 mt-3 text-xs text-muted-foreground">Untuk berjalan tanpa bergantung pada login admin, tambahkan cron di server: <code>0 6 * * * php /var/www/einvabill/app/auto_invoice_generator.php</code></p>
</div>

<!-- Control Panel -->
<div class="ui-card mb-5 p-4 sm:p-5">
    <h3 class="m-0 mb-3 text-[15px] font-bold">Kontrol proses</h3>

    <form method="POST" action="index.php?page=admin_auto_invoice&action=run" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
<?= csrf_field() ?>
        <button type="submit" name="mode" value="simulate" class="ui-btn ui-btn-outline w-full sm:w-auto">
            <i class="fas fa-binoculars"></i> Uji dulu (simulasi)
        </button>
        <button type="submit" name="mode" value="run" class="ui-btn ui-btn-primary w-full sm:w-auto" onclick="return confirm('Jalankan auto-generate sekarang? Sistem akan membuat tagihan untuk semua pelanggan yang sudah mencapai tanggal tagihan mereka.')">
            <i class="fas fa-play"></i> Jalankan sekarang
        </button>
    </form>

    <p class="m-0 mt-3 text-xs text-muted-foreground">
        <strong class="text-foreground">Tips:</strong> Selalu test dengan "Simulasi" terlebih dahulu sebelum jalankan di production.
    </p>
</div>

<?php if ($report): ?>
<!-- Report Results -->
<section class="ui-card mb-5 overflow-hidden">
    <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
        <h3 class="m-0 text-[15px] font-bold"><?= $report['simulate'] ? 'Hasil simulasi' : 'Proses selesai' ?></h3>
    </div>

    <div class="p-4 sm:p-5">
        <!-- Stats -->
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="ui-card p-4">
                <div class="text-xs font-medium text-muted-foreground">Total pelanggan</div>
                <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= $report['customers_processed'] ?></div>
            </div>
            <div class="ui-card p-4">
                <div class="text-xs font-medium text-muted-foreground">Tagihan dibuat</div>
                <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal"><?= $report['invoices_created'] ?></div>
            </div>
            <div class="ui-card p-4">
                <div class="text-xs font-medium text-muted-foreground">Dilewati</div>
                <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= $report['invoices_skipped'] ?></div>
            </div>
            <?php if (!empty($report['errors'])): ?>
            <div class="ui-card p-4">
                <div class="text-xs font-medium text-muted-foreground">Error</div>
                <div class="mt-1 text-2xl font-extrabold tabular-nums text-danger"><?= count($report['errors']) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Error Messages -->
        <?php if (!empty($report['errors'])): ?>
        <div class="ui-card mb-5 border-danger/40 p-4 text-sm">
            <div class="mb-2 font-semibold text-danger">Error ditemukan</div>
            <ul class="m-0 pl-5 text-xs text-muted-foreground">
                <?php foreach ($report['errors'] as $err): ?>
                <li class="mb-1"><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Details Table -->
        <div class="overflow-x-auto rounded-md border border-solid border-border">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold">Pelanggan</th>
                        <th class="px-4 py-2.5 font-semibold">Tgl tagihan</th>
                        <th class="px-4 py-2.5 font-semibold">Jatuh tempo</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Nominal</th>
                        <th class="px-4 py-2.5 text-center font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['details'] as $detail): ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-3 align-middle">
                            <div class="font-semibold"><?= htmlspecialchars($detail['customer_name']) ?></div>
                            <div class="text-xs text-muted-foreground">ID: <?= $detail['customer_id'] ?></div>
                        </td>
                        <td class="px-4 py-3 align-middle tabular-nums"><?= $detail['billing_date'] ?></td>
                        <td class="px-4 py-3 align-middle tabular-nums"><?= $detail['due_date'] ?></td>
                        <td class="px-4 py-3 text-right align-middle font-semibold tabular-nums">
                            Rp <?= number_format($detail['amount'], 0, ',', '.') ?>
                        </td>
                        <td class="px-4 py-3 text-center align-middle">
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
                            <span class="ui-badge <?= $status_class === 'SUCCESS' ? 'ui-badge-signal' : ($status_class === 'ERROR' ? 'ui-badge-danger' : 'ui-badge-muted') ?>">
                                <?= ucfirst(strtolower($status_class)) ?>
                            </span>
                            <?php if (isset($detail['reason'])): ?>
                            <span class="mt-1 block text-[11px] text-muted-foreground"><?= htmlspecialchars($detail['reason']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($report['details_hidden'])): ?>
        <p class="mt-3 text-xs text-muted-foreground">Menampilkan 
            <?= number_format(count($report['details'])) ?> baris pertama; <?= number_format($report['details_hidden']) ?> pelanggan lain tidak ditampilkan agar halaman tetap ringan.
            Angka ringkasan di atas tetap menghitung seluruh pelanggan.</p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- History Logs -->
<?php if (!empty($logs)): ?>
<section class="ui-card overflow-hidden">
    <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
        <h3 class="m-0 text-[15px] font-bold">Riwayat eksekusi</h3>
    </div>

    <div class="max-h-[400px] overflow-x-auto overflow-y-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="sticky top-0 bg-card text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold">Waktu</th>
                    <th class="px-4 py-2.5 text-center font-semibold">Dibuat</th>
                    <th class="px-4 py-2.5 text-center font-semibold">Dilewati</th>
                    <th class="px-4 py-2.5 text-center font-semibold">Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log):
                    $log_data = json_decode($log['report_json'], true);
                ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 align-middle tabular-nums">
                        <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                    </td>
                    <td class="px-4 py-3 text-center align-middle">
                        <span class="ui-badge ui-badge-signal tabular-nums">
                            <?= $log_data['invoices_created'] ?? 0 ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center align-middle">
                        <span class="ui-badge ui-badge-muted tabular-nums">
                            <?= $log_data['invoices_skipped'] ?? 0 ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center align-middle">
                        <?php $err_count = count($log_data['errors'] ?? []); ?>
                        <span class="ui-badge <?= $err_count > 0 ? 'ui-badge-danger' : 'ui-badge-muted' ?> tabular-nums">
                            <?= $err_count ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php else: ?>
<div class="ui-card px-5 py-10 text-center text-sm text-muted-foreground">
    Belum ada riwayat eksekusi. Jalankan proses di atas untuk melihat history.
</div>
<?php endif; ?>
