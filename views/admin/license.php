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

if (isset($_GET['msg']) && $_GET['msg'] === 'activated') {
    $msg_status = "Aktivasi Berhasil! Lisensi Anda sekarang aktif.";
}
?>

<div style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
    <div class="glass-panel" style="max-width: 500px; width: 100%; padding: 40px; text-align: center;">
        <div style="width: 80px; height: 80px; background: rgba(59, 130, 246, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px;">
            <i class="fas fa-key" style="font-size: 32px; color: var(--primary);"></i>
        </div>
        
        <h2 style="font-size: 24px; margin-bottom: 10px;">Aktivasi Lisensi</h2>
        <p style="color: var(--text-secondary); margin-bottom: 30px;">
            Sistem memerlukan lisensi aktif untuk melanjutkan penggunaan seluruh fitur.
        </p>

        <?php if($msg_status): ?>
            <div style="padding: 15px; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); color: var(--success); border-radius: 12px; margin-bottom: 20px; font-weight: 600;">
                <i class="fas fa-check-circle"></i> <?= $msg_status ?>
                <div style="margin-top:10px;"><a href="index.php" class="btn btn-sm btn-primary">Kembali ke Dashboard</a></div>
            </div>
        <?php endif; ?>

        <?php if($msg_error): ?>
            <div style="padding: 15px; background: rgba(244, 63, 94, 0.1); border: 1px solid var(--danger); color: var(--danger); border-radius: 12px; margin-bottom: 20px; font-weight: 600;">
                <i class="fas fa-exclamation-triangle"></i> <?= $msg_error ?>
            </div>
        <?php endif; ?>

        <div style="background: rgba(0,0,0,0.2); padding: 20px; border-radius: 16px; margin-bottom: 30px; border: 1px solid var(--glass-border);">
            <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 5px;">Status Saat Ini:</div>
            <div style="font-size: 18px; font-weight: 700; color: <?= LICENSE_ST === 'EXPIRED' ? 'var(--danger)' : 'var(--primary)' ?>;">
                <?= LICENSE_ST === 'UNLIMITED' ? 'UNLIMITED ACCESS' : (LICENSE_ST === 'TRIAL' ? 'MASA PERCOBAAN' : (LICENSE_ST === 'ACTIVE' ? 'AKTIF' : 'LISENSI HABIS')) ?>
            </div>
            <?php if(LICENSE_MSG): ?>
                <div style="font-size: 12px; margin-top: 8px; font-style: italic; color: var(--text-secondary);"><?= LICENSE_MSG ?></div>
            <?php endif; ?>
        </div>
        <?php if(LICENSE_ST !== 'UNLIMITED' && LICENSE_ST !== 'ACTIVE' || isset($_GET['reauth'])): ?>
            <form action="index.php?page=admin_license_post" method="POST">
                <div class="form-group" style="text-align: left;">
                    <label style="font-size: 12px; margin-left: 5px;">Masukkan Kode Lisensi</label>
                    <input type="text" name="license_key" class="form-control" placeholder="XXXX-XXXX-XXXX-XXXX" required style="text-align: center; font-family: monospace; letter-spacing: 2px; font-size: 18px; padding: 15px;">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 16px; font-weight: 700; border-radius: 12px; margin-top: 10px;">
                    AKTIFKAN SEKARANG
                </button>
            </form>
            <p style="font-size: 13px; color: var(--text-secondary); margin-top: 18px;">
                Belum punya lisensi? <a href="https://wa.me/6282346268845?text=Halo,%20saya%20ingin%20memesan%20lisensi%20EinvaBill" target="_blank" style="color: var(--primary);">Hubungi Sales: 0823-4626-8845</a>
            </p>
        <?php else: ?>
            <a href="index.php" class="btn btn-ghost" style="width: 100%; padding: 15px; border-radius: 12px;">Kembali ke Utama</a>
        <?php endif; ?>

        <!-- Admin: License Generator -->
        <div style="margin-top:28px; padding:18px; background: rgba(255,255,255,0.02); border-radius:12px; border:1px solid var(--glass-border);">
            <h3 style="margin:0 0 12px; font-size:16px;">Buat Kode Lisensi (Admin)</h3>
            <form action="index.php?page=admin_license_generate" method="POST" id="genForm">
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px;">
                    <select name="gen_type" id="gen_type" onchange="toggleGenFields()" style="padding:8px; border-radius:8px;">
                        <option value="trial">Trial (hari)</option>
                        <option value="annual">Annual / Custom Date</option>
                        <option value="unlimited">Unlimited (MASTER)</option>
                    </select>
                    <input type="number" name="trial_days" id="trial_days" value="30" min="1" style="width:120px; padding:8px; border-radius:8px;" />
                    <input type="date" name="expiry_date" id="expiry_date" style="display:none; padding:8px; border-radius:8px;" />
                    <button type="submit" class="btn btn-primary" style="margin-left:auto;">Buat</button>
                </div>
            </form>

            <?php if(!empty($generated_code)): ?>
                <div style="margin-top:12px; padding:12px; background: rgba(59,130,246,0.06); border-radius:8px;">
                    <div style="font-size:13px; color:var(--text-secondary); margin-bottom:6px;">Kode Lisensi:</div>
                    <div style="font-family: monospace; font-size:18px; font-weight:700; display:flex; gap:12px; align-items:center;">
                        <div><?= htmlspecialchars($generated_code) ?></div>
                        <a href="https://wa.me/6282346268845?text=Halo,%20saya%20mau%20mengirim%20kode%20lisensi%20<?= urlencode($generated_code) ?>" target="_blank" class="btn btn-ghost">Kirim WA</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleGenFields(){
    var t = document.getElementById('gen_type').value;
    document.getElementById('trial_days').style.display = t === 'trial' ? 'inline-block' : 'none';
    document.getElementById('expiry_date').style.display = t === 'annual' ? 'inline-block' : 'none';
}
document.addEventListener('DOMContentLoaded', function(){ toggleGenFields(); });
</script>
