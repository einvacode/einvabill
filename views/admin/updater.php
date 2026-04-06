<?php
$update_output = "";
$update_error = "";
$git_available = false;

// Check if Git is available
$git_version = shell_exec('git --version');
if ($git_version) {
    $git_version = trim($git_version);
    $git_available = true;
}

// Action: Fetch updates to see status
if ($git_available) {
    $fetch_res = shell_exec('git fetch origin main 2>&1');
    $git_status = shell_exec('git status -uno 2>&1');
    
    // Detect if we can even fetch (network or permissions)
    if (stripos($fetch_res, 'fatal') !== false || stripos($fetch_res, 'error') !== false) {
        $update_error = "Fetch Failed: " . $fetch_res;
        $update_available = false;
        $up_to_date = false;
    } else {
        // Case-insensitive checks for better reliability
        $update_available = (stripos($git_status, 'behind') !== false || stripos($git_status, 'diverged') !== false);
        $up_to_date = (stripos($git_status, 'up to date') !== false || (stripos($git_status, 'ahead') !== false && stripos($git_status, 'diverged') === false));
        
        // Debug: If neither, show the raw status to admin
        if (!$update_available && !$up_to_date && !empty($git_status)) {
            $update_error = "Git Status Debug: " . $git_status;
        } else {
            $update_error = ""; // Clear error if resolved
        }
    }
}

// Action: Perform Update
if ($page === 'admin_updater_run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($git_available) {
        $update_output = shell_exec('git pull origin main 2>&1');
        // Refresh status after pull
        $git_status = shell_exec('git status -uno 2>&1');
        $update_available = false;
        $up_to_date = true;
    } else {
        $update_error = "Perintah 'git' tidak ditemukan di server ini.";
    }
}
?>

<div class="glass-panel" style="padding: 30px; margin-bottom: 30px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; gap: 20px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 50px; height: 50px; background: rgba(59, 130, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-sync-alt" style="font-size: 24px; color: var(--primary);"></i>
            </div>
            <div>
                <h2 style="margin: 0; font-size: 24px;">Update Sistem</h2>
                <p style="color: var(--text-secondary); font-size: 14px; margin: 0;">Sinkronisasi otomatis dengan repository GitHub</p>
            </div>
        </div>
        <a href="index.php?page=admin_settings" class="btn btn-sm btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <?php if (!$git_available): ?>
        <div style="padding: 20px; background: rgba(244, 63, 94, 0.1); border: 1px solid var(--danger); color: var(--danger); border-radius: 12px;">
            <i class="fas fa-exclamation-triangle"></i> <strong>Error: Git Tidak Terdeteksi!</strong><br>
            Aplikasi tidak dapat melakukan update otomatis karena software 'Git' tidak terdeteksi di server ini. Silakan instal Git atau hubungi tim IT Anda.
        </div>
    <?php else: ?>
        <?php if ($update_error): ?>
            <div style="padding: 15px; background: rgba(244, 63, 94, 0.05); border: 1px dashed var(--danger); color: var(--danger); border-radius: 12px; margin-bottom: 25px; font-size: 13px; font-family: monospace;">
                <i class="fas fa-info-circle"></i> <?= nl2br(htmlspecialchars($update_error)) ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <div style="background: rgba(0,0,0,0.2); padding: 25px; border-radius: 15px; border: 1px solid var(--glass-border);">
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">Versi Lokal:</div>
                <div style="font-size: 15px; font-weight: 600; font-family: monospace;"><?= $git_version ?></div>
                <div style="margin-top: 15px;">
                    <?php if ($update_available): ?>
                        <div class="badge" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid #f59e0b; padding: 5px 12px; font-size: 11px;">
                            <i class="fas fa-arrow-circle-up"></i> UPDATE TERSEDIA
                        </div>
                    <?php elseif ($up_to_date): ?>
                        <div class="badge" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid var(--success); padding: 5px 12px; font-size: 11px;">
                            <i class="fas fa-check-circle"></i> VERSI TERBARU
                        </div>
                    <?php else: ?>
                        <div class="badge" style="background: rgba(255,255,255,0.05); color: var(--text-secondary); border: 1px solid var(--glass-border); padding: 5px 12px; font-size: 11px;">
                            MENGECEK...
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; justify-content: center; gap: 15px;">
                <?php if ($update_available): ?>
                    <form action="index.php?page=admin_updater_run" method="POST">
                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 18px; font-weight: 700; font-size: 16px;">
                            <i class="fas fa-download"></i> UPDATE SEKARANG
                        </button>
                    </form>
                    <p style="font-size: 11px; color: var(--text-secondary); text-align: center;">
                        <i class="fas fa-info-circle"></i> Harap backup database sebelum melakukan update besar.
                    </p>
                <?php else: ?>
                    <form action="index.php?page=admin_updater" method="POST">
                        <button type="submit" class="btn btn-ghost" style="width: 100%; border: 1px solid var(--glass-border); padding: 18px;">
                            <i class="fas fa-sync-alt"></i> CEK ULANG PEMBARUAN
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($update_output): ?>
            <div style="margin-top: 30px;">
                <h4 style="margin-bottom: 10px; font-size: 14px; color: var(--text-secondary);">Log Pembaruan:</h4>
                <div style="background: #000; color: #0f0; padding: 20px; border-radius: 12px; font-family: 'Consolas', monospace; font-size: 13px; line-height: 1.6; overflow-x: auto; border: 1px solid #333;">
                    <span style="color: #666;">$ git pull origin main</span><br>
                    <?= nl2br(htmlspecialchars($update_output)) ?>
                </div>
            </div>
        <?php endif; ?>

        <div style="margin-top: 30px; background: rgba(59, 130, 246, 0.05); padding: 20px; border-radius: 12px; border: 1px dashed var(--primary);">
            <h5 style="margin: 0 0 10px 0; font-size: 14px;"><i class="fas fa-lightbulb" style="color: var(--primary);"></i> Tentang Update</h5>
            <p style="margin: 0; font-size: 12px; color: var(--text-secondary); line-height: 1.5;">
                Fitur ini akan menarik kode terbaru dari repository resmi **EinvaBill**. Seluruh perbaikan bug, fitur baru, dan optimasi akan langsung diterapkan ke aplikasi ini tanpa menghapus data pelanggan Anda.
            </p>
        </div>
    <?php endif; ?>
</div>
