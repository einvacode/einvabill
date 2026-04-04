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
    
    // Handle File Upload
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../public/uploads/';
        if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $file_name = 'logo_' . time() . '_' . str_replace(' ', '_', basename($_FILES['logo_file']['name']));
        $target_path = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_path)) {
            $company_logo = 'public/uploads/' . $file_name;
        }
    }

    // Handle QRIS Upload
    if (isset($_FILES['qris_file']) && $_FILES['qris_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../public/uploads/';
        if(!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $file_name = 'qris_' . time() . '_' . str_replace(' ', '_', basename($_FILES['qris_file']['name']));
        $target_path = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['qris_file']['tmp_name'], $target_path)) {
            $company_qris = 'public/uploads/' . $file_name;
        }
    }
    
    $stmt = $db->prepare("UPDATE settings SET company_name=?, company_tagline=?, company_contact=?, company_address=?, company_logo=?, company_qris=?, wa_template=?, wa_template_paid=?, bank_account=?, router_ip=?, router_user=?, router_pass=?, router_port=? WHERE id=1");
    $stmt->execute([$company_name, $company_tagline, $company_contact, $company_address, $company_logo, $company_qris, $wa_template, $wa_template_paid, $bank_account, $router_ip, $router_user, $router_pass, $router_port]);
    
    $success = "Pengaturan berhasil disimpan.";
}

$settings = $db->query("SELECT * FROM settings WHERE id=1")->fetch();
?>

<div class="glass-panel" style="padding: 24px; max-width:700px; margin:0 auto; margin-bottom:40px;">
    <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-cog text-primary"></i> Pengaturan Aplikasi</h3>
    
    <?php if(isset($success)): ?>
        <div class="badge badge-success" style="display:block; margin-bottom:20px; padding:12px; border-radius:10px; font-weight:700; text-align:center;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="settings-tabs" style="display:flex; gap:10px; margin-bottom:25px; border-bottom:1px solid var(--glass-border); overflow-x:auto; padding-bottom:5px;">
        <button class="settings-tab active btn btn-sm btn-ghost" onclick="switchTab(this, 'profil')" style="border-radius:10px; white-space:nowrap; padding:10px 20px;"><i class="fas fa-building"></i> Profil</button>
        <button class="settings-tab btn btn-sm btn-ghost" onclick="switchTab(this, 'whatsapp')" style="border-radius:10px; white-space:nowrap; padding:10px 20px;"><i class="fab fa-whatsapp"></i> WA Template</button>
        <button class="settings-tab btn btn-sm btn-ghost" onclick="switchTab(this, 'router')" style="border-radius:10px; white-space:nowrap; padding:10px 20px;"><i class="fas fa-server"></i> API Router</button>
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
            <div class="form-group" style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border);">
                <label style="margin-bottom:10px; display:block;">Logo Perusahaan</label>
                <?php if(!empty($settings['company_logo'])): ?>
                    <div style="margin-bottom:12px;"><img src="<?= htmlspecialchars($settings['company_logo']) ?>" style="max-height:60px; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.2);"></div>
                <?php endif; ?>
                <input type="file" name="logo_file" class="form-control" accept="image/*">
                <input type="text" name="company_logo" class="form-control" value="<?= htmlspecialchars($settings['company_logo'] ?? '') ?>" placeholder="Atau paste URL Logo di sini" style="margin-top:10px;">
            </div>
            <div class="form-group">
                <label>Info Rekening Pembayaran</label>
                <textarea name="bank_account" class="form-control" rows="2" placeholder="BCA: 123xxxx a/n Nama"><?= htmlspecialchars($settings['bank_account'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="background:var(--hover-bg); padding:15px; border-radius:12px; border:1px solid var(--glass-border); margin-top:15px;">
                <label style="margin-bottom:10px; display:block;"><i class="fas fa-qrcode"></i> Foto QRIS Pembayaran</label>
                <?php if(!empty($settings['company_qris'])): ?>
                    <div style="margin-bottom:12px;"><img src="<?= htmlspecialchars($settings['company_qris']) ?>" style="max-height:150px; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.2);"></div>
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
                <small style="color:var(--text-secondary); margin-top:5px; display:block;">Gunakan: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {jatuh_tempo}, {rekening}, {tunggakan}, {total_harus}</small>
            </div>
            <div class="form-group">
                <label>Template Kwitansi (Lunas)</label>
                <textarea name="wa_template_paid" class="form-control" rows="5"><?= htmlspecialchars($settings['wa_template_paid'] ?? '') ?></textarea>
                <small style="color:var(--text-secondary); margin-top:5px; display:block;">Gunakan: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {tunggakan}</small>
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
