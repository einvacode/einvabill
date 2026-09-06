<?php
$msg_status = '';
$msg_error = '';
$generated_code = '';

if ($page === 'admin_license_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['license_key'] ?? '');
    $MASTER_KEY = getenv('MASTER_KEY') ?: "EB-ULTIMATE-2026";
    
    if ($key === $MASTER_KEY) {
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $db->prepare("UPDATE settings SET license_key = ?, license_type = 'unlimited' WHERE tenant_id = ?")->execute([$key, $tenant_id]);
        header("Location: index.php?page=admin_license&msg=activated");
        exit;
    } elseif (preg_match('/^EXP-(\d{8})-([A-Z0-9]{4})$/', $key, $matches)) {
        $date_str = $matches[1]; // YYYYMMDD
        $crc_str = $matches[2];
        $formatted_date = substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
        
        // Simple CRC Check (prefer environment secret)
        $salt = getenv('EINVABILL_SALT') ?: "EINVABILL_SECRET";
        $expected_crc = strtoupper(substr(md5($date_str . $salt), 0, 4));
        
        if ($crc_str === $expected_crc) {
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            $db->prepare("UPDATE settings SET license_key = ?, license_expiry = ?, license_type = 'annual' WHERE tenant_id = ?")->execute([$key, $formatted_date, $tenant_id]);
            header("Location: index.php?page=admin_license&msg=activated");
            exit;
        } else {
            $msg_error = "Kode Lisensi tidak valid (Kesalahan Checksum).";
        }
    } else {
        $msg_error = "Format Kode Lisensi salah.";
    }
}

if ($page === 'admin_license_generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // SECURITY: Only Super Admin (ID 1) can generate new license codes
    if (($_SESSION['user_id'] ?? 0) != 1) {
        $msg_error = "Hanya Super Admin yang dapat membuat kode lisensi baru.";
    } else {
        $MASTER_KEY = getenv('MASTER_KEY') ?: "EB-ULTIMATE-2026";
    $salt = getenv('EINVABILL_SALT') ?: "EINVABILL_SECRET";
    $type = $_POST['gen_type'] ?? 'trial';

    if ($type === 'unlimited') {
        $generated_code = $MASTER_KEY;
        $msg_status = "Kunci UNLIMITED dibuat.";
    } else {
        if ($type === 'trial') {
            $days = max(1, intval($_POST['trial_days'] ?? 30));
            $expiry = date('Y-m-d', strtotime("+{$days} days"));
        } else {
            $expiry = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+365 days'));
        }

        $date_str = date('Ymd', strtotime($expiry)); // YYYYMMDD
        $crc = strtoupper(substr(md5($date_str . $salt), 0, 4));
        $generated_code = "EXP-{$date_str}-{$crc}";
        $msg_status = "License EXP dibuat untuk kedaluwarsa: {$expiry}";
    }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'activated') {
    $msg_status = "Aktivasi Berhasil! Lisensi Anda sekarang aktif.";
}
?>

<div class="mx-auto max-w-lg">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Aktivasi lisensi</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Sistem memerlukan lisensi aktif untuk menggunakan seluruh fitur.</p>
        </div>
    </div>

    <?php if($msg_status): ?>
        <div class="ui-card mb-5 p-4 text-sm">
            <span class="font-semibold text-signal">Berhasil.</span> <?= $msg_status ?>
            <div class="mt-3"><a href="index.php" class="ui-btn ui-btn-sm ui-btn-outline">Kembali ke dashboard</a></div>
        </div>
    <?php endif; ?>

    <?php if($msg_error): ?>
        <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> <?= $msg_error ?></div>
    <?php endif; ?>

    <section class="ui-card p-5 sm:p-6">
        <div class="rounded-md border border-solid border-border bg-background p-4">
            <div class="text-xs font-medium text-muted-foreground">Status saat ini</div>
            <div class="mt-1 text-lg font-bold <?= LICENSE_ST === 'EXPIRED' ? 'text-danger' : 'text-foreground' ?>">
                <?= LICENSE_ST === 'UNLIMITED' ? 'Akses unlimited' : (LICENSE_ST === 'TRIAL' ? 'Masa percobaan' : (LICENSE_ST === 'ACTIVE' ? 'Aktif' : 'Lisensi habis')) ?>
            </div>
            <?php if(LICENSE_MSG): ?>
                <div class="mt-1 text-xs text-muted-foreground"><?= LICENSE_MSG ?></div>
            <?php endif; ?>
        </div>

        <?php if(LICENSE_ST !== 'UNLIMITED' && LICENSE_ST !== 'ACTIVE' || isset($_GET['reauth'])): ?>
            <form action="index.php?page=admin_license_post" method="POST" class="mt-5">
<?= csrf_field() ?>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Masukkan kode lisensi</span>
                    <input type="text" name="license_key" class="form-control text-center font-mono" placeholder="XXXX-XXXX-XXXX-XXXX" required>
                </label>
                <button type="submit" class="ui-btn ui-btn-primary mt-4 w-full">Aktifkan sekarang</button>
            </form>
            <p class="m-0 mt-4 text-center text-sm text-muted-foreground">
                Belum punya lisensi? <a href="https://wa.me/6282346268845?text=Halo,%20saya%20ingin%20memesan%20lisensi%20EinvaBill" target="_blank" class="font-medium text-primary underline">Hubungi sales: 0823-4626-8845</a>
            </p>
        <?php else: ?>
            <a href="index.php" class="ui-btn ui-btn-outline mt-5 w-full">Kembali ke utama</a>
        <?php endif; ?>
    </section>

    <!-- Admin: License Generator -->
    <section class="ui-card mt-5 p-5 sm:p-6">
        <h3 class="m-0 text-[15px] font-bold">Buat kode lisensi (admin)</h3>
        <form action="index.php?page=admin_license_generate" method="POST" id="genForm">
<?= csrf_field() ?>
            <div class="flex flex-wrap items-center gap-2">
                <select name="gen_type" id="gen_type" onchange="toggleGenFields()" class="form-control w-auto flex-1">
                    <option value="trial">Trial (hari)</option>
                    <option value="annual">Annual / tanggal khusus</option>
                    <option value="unlimited">Unlimited (master)</option>
                </select>
                <input type="number" name="trial_days" id="trial_days" value="30" min="1" class="form-control w-28" />
                <input type="date" name="expiry_date" id="expiry_date" class="form-control w-44" style="display:none;" />
                <button type="submit" class="ui-btn ui-btn-outline ml-auto">Buat</button>
            </div>
        </form>

        <?php if(!empty($generated_code)): ?>
            <div class="mt-4 rounded-md border border-solid border-border bg-background p-4">
                <div class="text-xs font-medium text-muted-foreground">Kode lisensi</div>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <code class="font-mono text-base font-bold"><?= htmlspecialchars($generated_code) ?></code>
                    <a href="https://wa.me/6282346268845?text=Halo,%20saya%20mau%20mengirim%20kode%20lisensi%20<?= urlencode($generated_code) ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-wa"><i class="fab fa-whatsapp"></i> Kirim WA</a>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
function toggleGenFields(){
    var t = document.getElementById('gen_type').value;
    document.getElementById('trial_days').style.display = t === 'trial' ? 'inline-block' : 'none';
    document.getElementById('expiry_date').style.display = t === 'annual' ? 'inline-block' : 'none';
}
document.addEventListener('DOMContentLoaded', function(){ toggleGenFields(); });
</script>
