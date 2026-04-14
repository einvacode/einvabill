<?php
// Auto-migrate if column is missing (prevents fatal error)
try {
    $db->exec("ALTER TABLE settings ADD COLUMN company_qris TEXT");
} catch(Exception $e) {
    // Column already exists or other error we can ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = $_POST['company_name'];
    $company_tagline = $_POST['company_tagline'];
    $company_contact = $_POST['company_contact'];
    $company_address = $_POST['company_address'];
    $site_url = rtrim($_POST['site_url'] ?? '', '/');
    $wa_template = $_POST['wa_template'] ?? '';
    $wa_template_paid = $_POST['wa_template_paid'] ?? '';
    $bank_account = $_POST['bank_account'] ?? '';
    $company_logo = $_POST['company_logo'] ?? '';
    $company_qris = $_POST['company_qris'] ?? '';
    
    // Router Config
    $router_ip = $_POST['router_ip'] ?? '';
    $router_user = $_POST['router_user'] ?? '';
    $router_pass = $_POST['router_pass'] ?? '';
    $router_port = $_POST['router_port'] ?? '8728';

    // ACS Config
    $acs_url = $_POST['acs_url'] ?? '';
    $acs_user = $_POST['acs_user'] ?? '';
    $acs_pass = $_POST['acs_pass'] ?? '';
    
    // Handle File Upload
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext)) {
            $upload_dir = __DIR__ . '/../../public/uploads/';
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_name = 'logo_' . time() . '_' . str_replace(' ', '_', basename($_FILES['logo_file']['name']));
            $target_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_path)) {
                $company_logo = 'public/uploads/' . $file_name;
            }
        } else {
            $error = "Format file logo tidak didukung! Gunakan JPG, PNG, atau WebP.";
        }
    }

    // Handle QRIS Upload
    if (isset($_FILES['qris_file']) && $_FILES['qris_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['qris_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext)) {
            $upload_dir = __DIR__ . '/../../public/uploads/';
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_name = 'qris_' . time() . '_' . str_replace(' ', '_', basename($_FILES['qris_file']['name']));
            $target_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['qris_file']['tmp_name'], $target_path)) {
                $company_qris = 'public/uploads/' . $file_name;
            }
        } else {
            $error = "Format file QRIS tidak didukung! Gunakan JPG, PNG, atau WebP.";
        }
    }

    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("UPDATE settings SET company_name=?, company_tagline=?, company_contact=?, company_address=?, site_url=?, company_logo=?, company_qris=?, wa_template=?, wa_template_paid=?, bank_account=?, router_ip=?, router_user=?, router_pass=?, router_port=?, acs_url=?, acs_user=?, acs_pass=? WHERE tenant_id=?");
    $stmt->execute([$company_name, $company_tagline, $company_contact, $company_address, $site_url, $company_logo, $company_qris, $wa_template, $wa_template_paid, $bank_account, $router_ip, $router_user, $router_pass, $router_port, $acs_url, $acs_user, $acs_pass, $tenant_id]);
    
    $success = "Pengaturan berhasil disimpan.";
}

$tenant_id = $_SESSION['tenant_id'] ?? 1;
$settings = $db->query("SELECT * FROM settings WHERE tenant_id = $tenant_id")->fetch();
if (!$settings) {
    // Should not happen due to init.php auto-insert, but for robustness:
    $db->prepare("INSERT INTO settings (company_name, tenant_id) VALUES (?, ?)")->execute(['Perusahaan Baru', $tenant_id]);
    $settings = $db->query("SELECT * FROM settings WHERE tenant_id = $tenant_id")->fetch();
}
?>

<div class="glass-panel" style="padding: 24px; max-width:700px; margin:0 auto; margin-bottom:40px;">
    <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-cog text-primary"></i> Pengaturan Aplikasi</h3>
    
    <?php if(isset($success)): ?>
        <div class="badge badge-success" style="display:block; margin-bottom:20px; padding:12px; border-radius:10px; font-weight:700; text-align:center;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="settings-tabs" style="display:flex; gap:10px; margin-bottom:25px; border-bottom:1px solid var(--glass-border); overflow-x:auto; padding-bottom:5px;">
        <button type="button" class="settings-tab active btn btn-sm btn-ghost" onclick="switchTab(this, 'profil')" style="border-radius:10px; white-space:nowrap; padding:10px 20px;"><i class="fas fa-building"></i> Profil</button>
        <button type="button" class="settings-tab btn btn-sm btn-ghost" onclick="switchTab(this, 'whatsapp')" style="border-radius:10px; white-space:nowrap; padding:10px 20px;"><i class="fab fa-whatsapp"></i> Template WhatsApp</button>
        <button type="button" class="settings-tab btn btn-sm btn-ghost" onclick="switchTab(this, 'router')" style="border-radius:10px; white-space:nowrap; padding:10px 20px;"><i class="fas fa-server"></i> API Router</button>
        <button type="button" class="settings-tab btn btn-sm btn-ghost" onclick="switchTab(this, 'system')" style="border-radius:10px; white-space:nowrap; padding:10px 20px;"><i class="fas fa-microchip"></i> System</button>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <!-- PROFIL SECTION -->
        <div id="profil" class="settings-section active-section">
            <div class="form-group">
                <label>Nama Perusahaan / Branding</label>
                <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Slogan (Tagline)</label>
                <input type="text" name="company_tagline" class="form-control" value="<?= htmlspecialchars($settings['company_tagline']) ?>">
            </div>
            <div class="form-group">
                <label>WhatsApp Utama (Pusat Bantuan)</label>
                <input type="text" name="company_contact" class="form-control" value="<?= htmlspecialchars($settings['company_contact']) ?>" required>
            </div>
            <div class="form-group">
                <label>Alamat Kantor</label>
                <textarea name="company_address" class="form-control" rows="2"><?= htmlspecialchars($settings['company_address']) ?></textarea>
            </div>
            <div class="form-group">
                <label>Domain / URL Aplikasi (Untuk Link WA)</label>
                <input type="text" name="site_url" class="form-control" value="<?= htmlspecialchars($settings['site_url'] ?? 'http://fibernodeinternet.com') ?>" placeholder="http://domainanda.com">
                <small style="color:var(--text-secondary);">Gunakan domain Anda untuk membuat link otomatis di pesan WhatsApp.</small>
            </div>
            <div class="form-group" style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border);">
                <label style="margin-bottom:10px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-image"></i> Logo Perusahaan (Auto-Fit)
                </label>
                
                <div class="logo-preview-area" style="overflow: hidden; pointer-events: none; height: 160px; display: flex; align-items: center; justify-content: center;">
                    <?php if(!empty($settings['company_logo'])): ?>
                        <div class="brand-logo-wrapper" style="width:100%; height:100%; max-height: 100%;">
                            <img src="<?= htmlspecialchars($settings['company_logo']) ?>" alt="Preview" style="max-height: 100%; object-fit: contain;">
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; color:var(--text-secondary); opacity:0.5;">
                            <i class="fas fa-plus-circle" style="font-size:30px; margin-bottom:5px;"></i><br>
                            <span style="font-size:12px;">Belum Ada Logo</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center;">
                    <input type="file" name="logo_file" class="form-control" accept="image/*" style="padding:10px; height:auto; background:var(--input-bg);">
                    <button type="button" class="btn btn-sm btn-ghost" onclick="document.querySelector('[name=company_logo]').value=''" title="Hapus URL" style="height:44px;"><i class="fas fa-times"></i></button>
                </div>
                <input type="text" name="company_logo" class="form-control" value="<?= htmlspecialchars($settings['company_logo'] ?? '') ?>" placeholder="Atau paste URL Logo di sini" style="margin-top:10px; font-size:12px; padding:12px; height:auto;">
                <small style="color:var(--text-secondary); margin-top:8px; display:block; font-size:11px; line-height:1.4;">
                    <i class="fas fa-info-circle"></i> Sistem akan otomatis menyesuaikan logo <strong>Kotak</strong> atau <strong>Persegi Panjang</strong> agar tetap terlihat profesional.
                </small>
            </div>
            <div class="form-group">
                <label>Info Rekening Pembayaran</label>
                <textarea name="bank_account" class="form-control" rows="2" placeholder="BCA: 123xxxx a/n Nama"><?= htmlspecialchars($settings['bank_account'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); margin-top:15px;">
                <label style="margin-bottom:10px; display:block;"><i class="fas fa-qrcode"></i> Foto QRIS Pembayaran</label>
                <?php if(!empty($settings['company_qris'])): ?>
                    <div class="logo-preview-area" style="max-height: 200px; height: auto; overflow: hidden; pointer-events: none; margin-bottom: 12px; border-style: solid; border-width: 1px;">
                        <img src="<?= htmlspecialchars($settings['company_qris']) ?>" style="max-height:100%; max-width: 100%; object-fit: contain; border-radius: 8px;">
                    </div>
                <?php endif; ?>
                <input type="file" name="qris_file" class="form-control" accept="image/*">
                <input type="text" name="company_qris" class="form-control" value="<?= htmlspecialchars($settings['company_qris'] ?? '') ?>" placeholder="Atau paste URL QRIS di sini" style="margin-top:10px;">
                <small style="color:var(--text-secondary); margin-top:5px; display:block;">Unggah foto QRIS PT Anda agar pelanggan bisa melakukan scan pembayaran.</small>
            </div>
        </div>

        <!-- WHATSAPP SECTION -->
        <div id="whatsapp" class="settings-section" style="display:none;">
            <div class="form-group">
                <label>Template Pesan (Belum Lunas)</label>
                <textarea name="wa_template" class="form-control" rows="5"><?= htmlspecialchars($settings['wa_template'] ?? '') ?></textarea>
                <small style="color:var(--text-secondary); margin-top:5px; display:block; font-size:11px;">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {jatuh_tempo}, {rekening}, {tunggakan}, {total_harus}, {link_tagihan}, {perusahaan}</small>
            </div>
            <div class="form-group">
                <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Template Kuitansi (Lunas/Sudah Bayar)</label>
                <textarea name="wa_template_paid" class="form-control" style="height: 120px; font-size: 13px;" placeholder="Gunakan: {nama}, {tagihan}, {id_cust}, {link_tagihan}"><?= htmlspecialchars($settings['wa_template_paid'] ?? '') ?></textarea>
                <small style="color:var(--text-secondary); margin-top:5px; display:block; font-size:11px;">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {total_bayar}, {tunggakan}, {sisa_tunggakan}, {status_pembayaran}, {waktu_bayar}, {admin}, {link_tagihan}</small>
            </div>
        </div>

        <!-- ROUTER SECTION -->
        <div id="router" class="settings-section" style="display:none;">
            <div class="form-group">
                <label>IP Mikrotik / Host</label>
                <input type="text" name="router_ip" class="form-control" value="<?= htmlspecialchars($settings['router_ip'] ?? '') ?>">
            </div>
            <div class="flex" style="gap:10px;">
                 <div class="form-group" style="flex:1;">
                    <label>Username API</label>
                    <input type="text" name="router_user" class="form-control" value="<?= htmlspecialchars($settings['router_user'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Port (Default 8728)</label>
                    <input type="text" name="router_port" class="form-control" value="<?= htmlspecialchars($settings['router_port'] ?? '8728') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Password API</label>
                <input type="password" name="router_pass" class="form-control" value="<?= htmlspecialchars($settings['router_pass'] ?? '') ?>">
            </div>
        </div>

        <!-- SYSTEM SECTION -->
        <div id="system" class="settings-section" style="display:none;">
            <div style="background: rgba(59, 130, 246, 0.05); padding: 25px; border-radius: 15px; border: 1px dashed var(--primary); text-align: center;">
                <i class="fas fa-sync-alt" style="font-size: 32px; color: var(--primary); margin-bottom: 15px;"></i>
                <h4 style="margin-bottom: 10px;">Update Aplikasi Otomatis</h4>
                <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: 20px;">
                    Dapatkan fitur terbaru, perbaikan bug, dan optimasi performa langsung dari pusat pembaruan.
                </p>
                <a href="index.php?page=admin_updater" class="btn btn-primary" style="width: 100%; padding: 12px;">Buka Pengelola Update</a>
            </div>

            <div style="margin-top:20px; background: rgba(59, 130, 246, 0.05); padding: 20px; border-radius: 12px; border: 1px solid var(--glass-border);">
                <h5 style="margin-bottom:15px;"><i class="fas fa-satellite-dish"></i> Konfigurasi TR-069 (GenieACS)</h5>
                <div class="form-group">
                    <label>GenieACS API URL</label>
                    <input type="text" name="acs_url" class="form-control" value="<?= htmlspecialchars($settings['acs_url'] ?? '') ?>" placeholder="http://1.2.3.4:7557">
                    <small style="color:var(--text-secondary);">Gunakan port default GenieACS (7557).</small>
                </div>
                <div class="flex" style="gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Username API (Opsional)</label>
                        <input type="text" name="acs_user" class="form-control" value="<?= htmlspecialchars($settings['acs_user'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Password API (Opsional)</label>
                        <input type="password" name="acs_pass" class="form-control" value="<?= htmlspecialchars($settings['acs_pass'] ?? '') ?>">
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 20px; text-align: center; font-size: 11px; color: var(--text-secondary);">
                Versi Saat Ini: v1.5.0-Stable <br>
                License: <?= LICENSE_ST ?>
            </div>
        </div>

        <div style="margin-top: 30px; padding-top:20px; border-top:1px solid var(--glass-border);">
            <button type="submit" class="btn btn-primary" style="width:100%; font-weight:800; font-size:18px;"><i class="fas fa-save"></i> SIMPAN SEMUA</button>
        </div>
    </form>
</div>

<script>
function switchTab(btn, id) {
    document.querySelectorAll('.settings-tab').forEach(b => {
        b.classList.remove('active');
        b.style.background = 'var(--btn-ghost-bg)';
        b.style.color = 'var(--text-secondary)';
    });
    document.querySelectorAll('.settings-section').forEach(s => s.style.display = 'none');
    
    btn.classList.add('active');
    btn.style.background = 'var(--primary)';
    btn.style.color = 'white';
    document.getElementById(id).style.display = 'block';
}

// Set initial active state correctly
document.addEventListener("DOMContentLoaded", () => {
    let activeBtn = document.querySelector('.settings-tab.active');
    if(activeBtn) {
        activeBtn.style.background = 'var(--primary)';
        activeBtn.style.color = 'white';
    }
});
</script>
