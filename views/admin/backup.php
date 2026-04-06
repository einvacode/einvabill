<?php
$action = $_GET['action'] ?? 'view';
$db_path = __DIR__ . '/../../database.sqlite';
$backup_dir = __DIR__ . '/../../backups/';

// Buat folder backup jika belum ada
if (!is_dir($backup_dir)) mkdir($backup_dir, 0777, true);

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

<?php if($msg === 'saved'): ?>
    <div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:20px; color:var(--success);">
        <i class="fas fa-check-circle"></i> Backup berhasil disimpan: <strong><?= htmlspecialchars($_GET['file'] ?? '') ?></strong>
    </div>
<?php elseif($msg === 'restored'): ?>
    <div style="padding:12px 20px; background:rgba(59,130,246,0.15); border:1px solid rgba(59,130,246,0.4); border-radius:10px; margin-bottom:20px; color:var(--primary);">
        <i class="fas fa-undo"></i> Database berhasil di-restore! Data sebelumnya otomatis di-backup sebagai keamanan.
    </div>
<?php elseif($msg === 'invalid'): ?>
    <div style="padding:12px 20px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); border-radius:10px; margin-bottom:20px; color:var(--danger);">
        <i class="fas fa-times-circle"></i> File yang diupload bukan database SQLite billing yang valid!
    </div>
<?php elseif($msg === 'deleted'): ?>
    <div style="padding:12px 20px; background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.4); border-radius:10px; margin-bottom:20px; color:var(--warning);">
        <i class="fas fa-trash"></i> File backup telah dihapus.
    </div>
<?php elseif($msg === 'error'): ?>
    <div style="padding:12px 20px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); border-radius:10px; margin-bottom:20px; color:var(--danger);">
        <i class="fas fa-exclamation-triangle"></i> Terjadi kesalahan. Pastikan file valid dan coba lagi.
    </div>
<?php elseif($msg === 'reset_complete'): ?>
    <div style="padding:12px 20px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); border-radius:10px; margin-bottom:20px; color:var(--danger); font-weight:700;">
        <i class="fas fa-biohazard"></i> RESET DATA BERHASIL! Database pelanggan dan transaksi telah dibersihkan.
    </div>
<?php endif; ?>

<!-- Info Database Aktif -->
<div class="glass-panel" style="padding:24px; margin-bottom:20px;">
    <h3 style="margin-bottom:20px;"><i class="fas fa-database"></i> Informasi Database Aktif</h3>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:15px;">
        <div style="padding:16px; background:rgba(0,0,0,0.15); border-radius:10px; text-align:center;">
            <div style="font-size:28px; font-weight:700; color:var(--primary);"><?= formatSize($db_size) ?></div>
            <div style="font-size:13px; color:var(--text-secondary); margin-top:5px;">Ukuran Database</div>
        </div>
        <div style="padding:16px; background:rgba(0,0,0,0.15); border-radius:10px; text-align:center;">
            <div style="font-size:28px; font-weight:700; color:var(--success);"><?= $customer_count ?></div>
            <div style="font-size:13px; color:var(--text-secondary); margin-top:5px;">Total Pelanggan</div>
        </div>
        <div style="padding:16px; background:rgba(0,0,0,0.15); border-radius:10px; text-align:center;">
            <div style="font-size:28px; font-weight:700; color:var(--warning);"><?= $invoice_count ?></div>
            <div style="font-size:13px; color:var(--text-secondary); margin-top:5px;">Total Tagihan</div>
        </div>
    </div>
</div>

<!-- Backup Actions -->
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
    <!-- Backup -->
    <div class="glass-panel" style="padding:24px;">
        <h3 style="margin-bottom:5px; color:var(--success);"><i class="fas fa-download"></i> Backup</h3>
        <p style="color:var(--text-secondary); font-size:13px; margin-bottom:20px;">Simpan salinan database Anda agar bisa dipulihkan sewaktu-waktu.</p>
        
        <div style="display:flex; flex-direction:column; gap:10px;">
            <a href="index.php?page=admin_backup&action=download" class="btn btn-primary" style="border-radius:10px; display:flex; align-items:center; justify-content:center; gap:8px;">
                <i class="fas fa-cloud-download-alt"></i> Download ke Perangkat
            </a>
            <a href="index.php?page=admin_backup&action=save_local" class="btn btn-success" style="border-radius:10px; display:flex; align-items:center; justify-content:center; gap:8px;" onclick="return confirm('Simpan backup ke folder server?')">
                <i class="fas fa-server"></i> Simpan di Server
            </a>
        </div>
        <small style="color:var(--text-secondary); display:block; margin-top:10px; font-size:11px;"><i class="fas fa-info-circle"></i> Disarankan backup rutin sebelum melakukan perubahan besar.</small>
    </div>

    <!-- Restore -->
    <div class="glass-panel" style="padding:24px;">
        <h3 style="margin-bottom:5px; color:var(--warning);"><i class="fas fa-upload"></i> Restore</h3>
        <p style="color:var(--text-secondary); font-size:13px; margin-bottom:20px;">Pulihkan database dari file backup yang sudah pernah disimpan.</p>
        
        <form action="index.php?page=admin_backup&action=restore_upload" method="POST" enctype="multipart/form-data" onsubmit="return confirm('PERINGATAN!\n\nData saat ini akan DITIMPA dengan file backup yang Anda upload.\nData sekarang akan di-backup otomatis sebagai pengaman.\n\nLanjutkan restore?')">
            <div style="background:rgba(0,0,0,0.15); border:2px dashed var(--glass-border); border-radius:10px; padding:20px; text-align:center; margin-bottom:10px; transition:border-color 0.3s;" onmouseover="this.style.borderColor='var(--warning)'" onmouseout="this.style.borderColor='var(--glass-border)'">
                <i class="fas fa-file-upload" style="font-size:24px; color:var(--text-secondary); margin-bottom:8px;"></i>
                <div style="font-size:13px; color:var(--text-secondary); margin-bottom:10px;">Pilih file .sqlite backup</div>
                <input type="file" name="restore_file" accept=".sqlite,.db" required class="form-control" style="font-size:13px;">
            </div>
            <button type="submit" class="btn btn-warning" style="width:100%; border-radius:10px; display:flex; align-items:center; justify-content:center; gap:8px;">
                <i class="fas fa-undo"></i> Restore dari File
            </button>
        </form>
    </div>
</div>

<!-- Daftar Backup Server -->
<div class="glass-panel" style="padding:24px;">
    <h3 style="margin-bottom:20px;"><i class="fas fa-history"></i> Riwayat Backup di Server (<?= count($backups) ?>)</h3>
    
    <?php if(count($backups) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nama File</th>
                    <th>Ukuran</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($backups as $b): ?>
                <tr>
                    <td>
                        <i class="fas fa-file" style="color:var(--primary); margin-right:8px;"></i>
                        <strong><?= htmlspecialchars($b['name']) ?></strong>
                        <?php if(strpos($b['name'], 'pre_restore') !== false): ?>
                            <span class="badge" style="background:rgba(245,158,11,0.15); color:var(--warning); border:1px solid rgba(245,158,11,0.3); font-size:10px; margin-left:5px;">Otomatis</span>
                        <?php endif; ?>
                    </td>
                    <td><?= formatSize($b['size']) ?></td>
                    <td style="font-size:13px; color:var(--text-secondary);"><?= $b['date'] ?></td>
                    <td style="white-space:nowrap;">
                        <a href="index.php?page=admin_backup&action=restore_local&file=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-warning" onclick="return confirm('Restore database dari backup ini?\n\n<?= htmlspecialchars($b['name']) ?>\n\nData saat ini akan di-backup otomatis sebelum ditimpa.')">
                            <i class="fas fa-undo"></i> Restore
                        </a>
                        <a href="index.php?page=admin_backup&action=delete_backup&file=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus file backup ini permanen?')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align:center; padding:30px; color:var(--text-secondary);">
        <i class="fas fa-inbox" style="font-size:40px; opacity:0.3; margin-bottom:10px;"></i>
        <div>Belum ada file backup tersimpan di server.</div>
        <div style="font-size:12px; margin-top:5px;">Klik "Simpan di Server" untuk membuat backup pertama Anda.</div>
    </div>
    <?php endif; ?>
<!-- Danger Zone -->
<div class="glass-panel" style="padding:24px; border: 1px solid rgba(239,68,68,0.3); background: rgba(239,68,68,0.05); margin-top:20px;">
    <h3 style="margin-bottom:15px; color:#ef4444;"><i class="fas fa-biohazard"></i> Zona Bahaya (Danger Zone)</h3>
    <div style="display:grid; grid-template-columns: 1fr 250px; gap:20px; align-items:center;">
        <div style="font-size:13px; color:var(--text-secondary); line-height:1.6;">
            Fitur ini akan menghapus <strong>SELURUH</strong> data pelanggan, tagihan, pembayaran, pengeluaran, area, dan paket layanan.<br>
            <span style="color:#ef4444; font-weight:700;">Tindakan ini tidak dapat dibatalkan, namun sistem akan membuat backup otomatis sebelum penghapusan.</span><br>
            <br>
            <strong>Yang tetap aman:</strong> Akun Admin, License Key, Profil Perusahaan, dan Konfigurasi Router.
        </div>
        <div>
            <form id="resetForm" action="index.php?page=admin_backup&action=reset_data" method="POST">
                <button type="button" class="btn btn-danger" style="width:100%; padding:15px; border-radius:12px; font-weight:700; gap:8px;" onclick="handleReset()">
                    <i class="fas fa-trash-alt"></i> RESET SEMUA DATA
                </button>
            </form>
        </div>
    </div>
</div>

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

<style>
@media (max-width: 768px) {
    div[style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}
</style>
