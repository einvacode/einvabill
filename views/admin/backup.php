<?php
$action = $_GET['action'] ?? 'view';
$db_path = DB_PATH;
// Backups live next to the database in the protected data directory,
// never in a web-served folder.
$backup_dir = app_data_dir() . '/backups/';

// Buat folder backup jika belum ada
if (!is_dir($backup_dir)) mkdir($backup_dir, 0750, true);

// === BACKUP: Download database ===
if ($action === 'download') {
    $filename = 'backup_billing_' . date('Y-m-d_His') . '.sqlite';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($db_path));
    readfile($db_path);
    exit;
}

// === BACKUP: Simpan ke server ===
if ($action === 'save_local') {
    $filename = 'backup_' . date('Y-m-d_His') . '.sqlite';
    copy($db_path, $backup_dir . $filename);
    header("Location: index.php?page=admin_backup&msg=saved&file=" . urlencode($filename));
    exit;
}

// === RESTORE: Upload file ===
if ($action === 'restore_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['restore_file']) && $_FILES['restore_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['restore_file']['tmp_name'];
        
        // Validasi: cek apakah file SQLite yang valid
        try {
            $test_db = new PDO('sqlite:' . $tmp);
            $test_db->query("SELECT COUNT(*) FROM customers");
            $test_db = null;
        } catch(Exception $e) {
            header("Location: index.php?page=admin_backup&msg=invalid");
            exit;
        }
        
        // Backup dulu sebelum restore
        $pre_restore = 'pre_restore_' . date('Y-m-d_His') . '.sqlite';
        copy($db_path, $backup_dir . $pre_restore);
        
        // Replace database
        copy($tmp, $db_path);
        
        header("Location: index.php?page=admin_backup&msg=restored");
        exit;
    }
    header("Location: index.php?page=admin_backup&msg=error");
    exit;
}

// === RESTORE: Dari backup server ===
if ($action === 'restore_local') {
    $file = basename($_GET['file'] ?? '');
    $filepath = $backup_dir . $file;
    if ($file && file_exists($filepath)) {
        // Backup dulu sebelum restore
        $pre_restore = 'pre_restore_' . date('Y-m-d_His') . '.sqlite';
        copy($db_path, $backup_dir . $pre_restore);
        
        copy($filepath, $db_path);
        header("Location: index.php?page=admin_backup&msg=restored");
        exit;
    }
    header("Location: index.php?page=admin_backup&msg=error");
    exit;
}

// === HAPUS backup ===
if ($action === 'delete_backup') {
    $file = basename($_GET['file'] ?? '');
    $filepath = $backup_dir . $file;
    if ($file && file_exists($filepath)) {
        unlink($filepath);
    }
    header("Location: index.php?page=admin_backup&msg=deleted");
    exit;
}

// === RESET DATA: Danger Zone ===
if ($action === 'reset_data' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Pre-reset Backup for safety
    $pre_reset = 'pre_reset_' . date('Y-m-d_His') . '.sqlite';
    copy($db_path, $backup_dir . $pre_reset);

    // 2. Clear Tables
    $tables = [
        'customers', 'invoices', 'payments', 'invoice_items', 
        'expenses', 'areas', 'packages', 'infrastructure_assets',
        'banners', 'landing_packages', 'landing_logos'
    ];
    
    foreach ($tables as $t) {
        $db->exec("DELETE FROM $t");
        $db->exec("DELETE FROM sqlite_sequence WHERE name='$t'"); // Reset AI counters
    }

    header("Location: index.php?page=admin_backup&msg=reset_complete");
    exit;
}

// === VIEW ===
$msg = $_GET['msg'] ?? '';
$backups = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '*.sqlite');
    usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
    foreach ($files as $f) {
        $backups[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'date' => date('d M Y H:i:s', filemtime($f))
        ];
    }
}

$db_size = file_exists($db_path) ? filesize($db_path) : 0;
$customer_count = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$invoice_count = $db->query("SELECT COUNT(*) FROM invoices")->fetchColumn();

function formatSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Backup dan restore</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Salin, pulihkan, atau bersihkan database aplikasi.</p>
    </div>
</div>

<?php if($msg === 'saved'): ?>
    <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> Backup disimpan sebagai <strong><?= htmlspecialchars($_GET['file'] ?? '') ?></strong>.</div>
<?php elseif($msg === 'restored'): ?>
    <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> Database dipulihkan. Data sebelumnya otomatis di-backup sebagai pengaman.</div>
<?php elseif($msg === 'invalid'): ?>
    <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> File yang diunggah bukan database SQLite billing yang valid.</div>
<?php elseif($msg === 'deleted'): ?>
    <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold">Terhapus.</span> File backup telah dihapus.</div>
<?php elseif($msg === 'error'): ?>
    <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> Terjadi kesalahan. Pastikan file valid dan coba lagi.</div>
<?php elseif($msg === 'reset_complete'): ?>
    <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Reset selesai.</span> Database pelanggan dan transaksi telah dibersihkan.</div>
<?php endif; ?>

<!-- Info Database Aktif -->
<div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Ukuran database</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= formatSize($db_size) ?></div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total pelanggan</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= $customer_count ?></div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total tagihan</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= $invoice_count ?></div>
    </div>
</div>

<!-- Backup Actions -->
<div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2">
    <!-- Backup -->
    <section class="ui-card p-5">
        <h3 class="m-0 text-[15px] font-bold">Backup</h3>
        <p class="m-0 mt-1 mb-4 text-sm text-muted-foreground">Simpan salinan database agar bisa dipulihkan sewaktu-waktu.</p>
        <div class="flex flex-col gap-2">
            <a data-method="post" href="index.php?page=admin_backup&action=download" class="ui-btn ui-btn-primary w-full"><i class="fas fa-download"></i> Download ke perangkat</a>
            <a data-method="post" href="index.php?page=admin_backup&action=save_local" class="ui-btn ui-btn-outline w-full" onclick="return confirm('Simpan backup ke folder server?')"><i class="fas fa-server"></i> Simpan di server</a>
        </div>
        <p class="m-0 mt-3 text-xs text-muted-foreground">Disarankan backup rutin sebelum melakukan perubahan besar.</p>
    </section>

    <!-- Restore -->
    <section class="ui-card p-5">
        <h3 class="m-0 text-[15px] font-bold">Restore</h3>
        <p class="m-0 mt-1 mb-4 text-sm text-muted-foreground">Pulihkan database dari file backup yang pernah disimpan.</p>
        <form action="index.php?page=admin_backup&action=restore_upload" method="POST" enctype="multipart/form-data" onsubmit="return confirm('PERINGATAN!\n\nData saat ini akan DITIMPA dengan file backup yang Anda upload.\nData sekarang akan di-backup otomatis sebagai pengaman.\n\nLanjutkan restore?')">
<?= csrf_field() ?>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">File backup (.sqlite)</span>
                <input type="file" name="restore_file" accept=".sqlite,.db" required class="form-control">
            </label>
            <button type="submit" class="ui-btn ui-btn-outline mt-3 w-full"><i class="fas fa-undo"></i> Restore dari file</button>
        </form>
    </section>
</div>

<!-- Daftar Backup Server -->
<section class="ui-card mb-5 overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Riwayat backup di server</h3>
            <p class="m-0 text-xs text-muted-foreground"><?= count($backups) ?> file tersimpan</p>
        </div>
    </div>

    <?php if(count($backups) > 0): ?>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold sm:px-5">Nama file</th>
                    <th class="px-3 py-2.5 font-semibold">Ukuran</th>
                    <th class="px-3 py-2.5 font-semibold">Tanggal</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($backups as $b): ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 sm:px-5">
                        <span class="font-semibold"><?= htmlspecialchars($b['name']) ?></span>
                        <?php if(strpos($b['name'], 'pre_restore') !== false): ?>
                            <span class="ui-badge ui-badge-muted ml-1">Otomatis</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 tabular-nums"><?= formatSize($b['size']) ?></td>
                    <td class="px-3 py-3 text-xs text-muted-foreground"><?= $b['date'] ?></td>
                    <td class="px-4 py-3 text-right sm:px-5 whitespace-nowrap">
                        <div class="inline-flex gap-1">
                            <a data-method="post" href="index.php?page=admin_backup&action=restore_local&file=<?= urlencode($b['name']) ?>" class="ui-btn ui-btn-sm ui-btn-outline" onclick="return confirm('Restore database dari backup ini?\n\n<?= htmlspecialchars($b['name']) ?>\n\nData saat ini akan di-backup otomatis sebelum ditimpa.')"><i class="fas fa-undo"></i><span class="hidden sm:inline">Restore</span></a>
                            <a data-method="post" href="index.php?page=admin_backup&action=delete_backup&file=<?= urlencode($b['name']) ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" onclick="return confirm('Hapus file backup ini permanen?')"><i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="px-5 py-10 text-center text-sm text-muted-foreground">
        <div>Belum ada file backup tersimpan di server.</div>
        <div class="mt-1 text-xs">Klik "Simpan di server" untuk membuat backup pertama Anda.</div>
    </div>
    <?php endif; ?>
</section>

<!-- Danger Zone -->
<section class="ui-card border-danger/40 p-5">
    <h3 class="m-0 text-[15px] font-bold text-danger">Zona bahaya</h3>
    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-[1fr_auto] md:items-center">
        <div class="text-sm text-muted-foreground leading-relaxed">
            Fitur ini menghapus <strong>seluruh</strong> data pelanggan, tagihan, pembayaran, pengeluaran, area, dan paket layanan.
            <span class="font-semibold text-danger">Tindakan ini tidak dapat dibatalkan, namun sistem membuat backup otomatis sebelum penghapusan.</span>
            <br><strong>Yang tetap aman:</strong> akun admin, license key, profil perusahaan, dan konfigurasi router.
        </div>
        <div>
            <form id="resetForm" action="index.php?page=admin_backup&action=reset_data" method="POST">
<?= csrf_field() ?>
                <button type="button" class="ui-btn ui-btn-outline text-danger w-full md:w-auto" onclick="handleReset()"><i class="fas fa-trash-alt"></i> Reset semua data</button>
            </form>
        </div>
    </div>
</section>

<script>
function handleReset() {
    const confirmPhrase = "HAPUS";
    const userConfirm = prompt("PERINGATAN KRITIKAL!\n\nSeluruh data pelanggan & transaksi akan DIHAPUS PERMANEN.\n\nKetik kata '" + confirmPhrase + "' di bawah ini untuk melanjutkan:");
    
    if (userConfirm === confirmPhrase) {
        if (confirm("KONFIRMASI TERAKHIR: Anda yakin 100% ingin memulai ulang database dari nol?")) {
            document.getElementById('resetForm').submit();
        }
    } else if (userConfirm !== null) {
        alert("Konfirmasi gagal. Kata kunci yang Anda masukkan salah.");
    }
}
</script>
