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

<div class="glass-panel" style="padding: 24px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; background: rgba(var(--primary-rgb), 0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-sync-alt" style="font-size: 18px; color: var(--primary);"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 18px; font-weight: 800;">Update Sistem</h3>
                <p style="color: var(--text-secondary); font-size: 12px; margin: 0; opacity: 0.7;">Sinkronisasi otomatis dengan repository GitHub</p>
            </div>
        </div>
        <a href="index.php?page=admin_settings" class="btn btn-sm btn-ghost" style="font-size: 12px;"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <?php if (!$git_available): ?>
        <div style="padding: 16px; background: rgba(244, 63, 94, 0.1); border: 1px solid rgba(244, 63, 94, 0.2); color: var(--danger); border-radius: 12px; font-size: 13px;">
            <i class="fas fa-exclamation-triangle"></i> <strong>Git Tidak Terdeteksi!</strong> Update otomatis tidak tersedia.
        </div>
    <?php else: ?>
        <div class="glass-panel" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); padding: 20px; border-radius: 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
                <div style="display: flex; align-items: center; gap: 25px;">
                    <div>
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 4px;">Versi Aplikasi</div>
                        <div style="font-size: 20px; font-weight: 900; font-family: monospace; letter-spacing: 1px;">v<?= APP_VERSION ?></div>
                    </div>
                    
                    <div style="height: 30px; border-left: 1px solid var(--glass-border);"></div>
                    
                    <div>
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 6px;">Status Sistem</div>
                        <?php if ($update_available): ?>
                            <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); padding: 4px 10px; font-size: 10px; font-weight: 800;">
                                <i class="fas fa-arrow-circle-up"></i> UPDATE TERSEDIA
                            </span>
                        <?php elseif ($up_to_date): ?>
                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); padding: 4px 10px; font-size: 10px; font-weight: 800;">
                                <i class="fas fa-check-circle"></i> VERSI TERBARU
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(255,255,255,0.05); color: var(--text-secondary); border: 1px solid var(--glass-border); padding: 4px 10px; font-size: 10px;">
                                MENGECEK...
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <?php if ($update_available): ?>
                        <form action="index.php?page=admin_updater_run" method="POST">
                            <button type="submit" class="btn btn-primary btn-sm" style="font-weight: 800; padding: 10px 20px;">
                                <i class="fas fa-download"></i> UPDATE SEKARANG
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <form action="index.php?page=admin_updater" method="POST">
                        <button type="submit" class="btn btn-ghost btn-sm" style="border: 1px solid var(--glass-border); padding: 10px 15px; font-weight: 700;">
                            <i class="fas fa-sync-alt" style="font-size: 11px;"></i> CEK UPDATE
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($update_output): ?>
            <div style="margin-top: 24px;">
                <div style="font-size: 11px; font-weight: 800; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-terminal"></i> Log Konsol Pembaruan
                </div>
                <div style="background: #000; color: #0f0; padding: 16px; border-radius: 12px; font-family: 'Consolas', monospace; font-size: 12px; line-height: 1.5; overflow-x: auto; border: 1px solid #333; opacity: 0.9;">
                    <span style="color: #666;">$ git pull origin main</span><br>
                    <?= nl2br(htmlspecialchars($update_output)) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($update_error): ?>
            <div style="margin-top: 20px; padding: 12px 16px; background: rgba(244, 63, 94, 0.05); border: 1px dashed rgba(244, 63, 94, 0.3); color: var(--danger); border-radius: 10px; font-size: 12px; font-family: monospace;">
                <i class="fas fa-info-circle"></i> DEBUG: <?= htmlspecialchars($update_error) ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 25px; padding: 16px; background: rgba(var(--primary-rgb), 0.03); border-radius: 12px; border: 1px solid rgba(var(--primary-rgb), 0.1); display: flex; gap: 12px; align-items: flex-start;">
            <i class="fas fa-lightbulb" style="color: var(--primary); margin-top: 3px;"></i>
            <div style="font-size: 12px; color: var(--text-secondary); line-height: 1.6;">
                <strong style="color: var(--text-primary);">Tentang Update:</strong> 
                Fitur ini akan menarik kode terbaru dari repository resmi secara aman. Perbaikan bug dan fitur baru akan langsung diterapkan tanpa menghapus data Anda.
            </div>
        </div>
    <?php endif; ?>
</div>
