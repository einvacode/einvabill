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

<div class="mx-auto max-w-3xl">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Update sistem</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Sinkronisasi otomatis dengan repository GitHub.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="index.php?page=admin_settings" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <?php if (!$git_available): ?>
        <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Git tidak terdeteksi.</span> Update otomatis tidak tersedia di server ini.</div>
    <?php else: ?>
        <section class="ui-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-6">
                    <div>
                        <div class="text-xs font-medium text-muted-foreground">Versi aplikasi</div>
                        <div class="mt-1 font-mono text-lg font-bold">v<?= APP_VERSION ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-muted-foreground">Status sistem</div>
                        <div class="mt-1.5">
                            <?php if ($update_available): ?>
                                <span class="ui-badge ui-badge-accent">Update tersedia</span>
                            <?php elseif ($up_to_date): ?>
                                <span class="ui-badge ui-badge-signal">Versi terbaru</span>
                            <?php else: ?>
                                <span class="ui-badge ui-badge-muted">Mengecek...</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <?php if ($update_available): ?>
                        <form action="index.php?page=admin_updater_run" method="POST">
<?= csrf_field() ?>
                            <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-download"></i> Update sekarang</button>
                        </form>
                    <?php endif; ?>

                    <form action="index.php?page=admin_updater" method="POST">
<?= csrf_field() ?>
                        <button type="submit" class="ui-btn ui-btn-outline"><i class="fas fa-sync-alt"></i> Cek update</button>
                    </form>
                </div>
            </div>

            <?php if ($update_output): ?>
                <div class="mt-5">
                    <div class="mb-2 text-xs font-medium text-muted-foreground">Log pembaruan</div>
                    <div class="overflow-x-auto rounded-md border border-solid border-border bg-muted p-4 font-mono text-xs leading-relaxed text-foreground">
                        <span class="text-muted-foreground">$ git pull origin main</span><br>
                        <?= nl2br(htmlspecialchars($update_output)) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($update_error): ?>
                <div class="mt-4 overflow-x-auto rounded-md border border-solid border-danger/40 bg-danger-soft p-3 font-mono text-xs text-danger">Debug: <?= htmlspecialchars($update_error) ?></div>
            <?php endif; ?>

            <p class="m-0 mt-5 border-t border-solid border-border pt-4 text-xs leading-relaxed text-muted-foreground">
                <strong class="text-foreground">Tentang update:</strong> fitur ini menarik kode terbaru dari repository resmi secara aman. Perbaikan bug dan fitur baru langsung diterapkan tanpa menghapus data Anda.
            </p>
        </section>
    <?php endif; ?>
</div>
