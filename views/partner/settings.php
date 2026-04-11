<?php
$u_id = $_SESSION['user_id'];
$msg = '';
$err = '';

// Fetch current profile
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$u_id]);
$user = $stmt->fetch();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand_name = $_POST['brand_name'] ?? '';
    $brand_address = $_POST['brand_address'] ?? '';
    $brand_contact = $_POST['brand_contact'] ?? '';
    $brand_bank = $_POST['brand_bank'] ?? '';
    $brand_rekening = $_POST['brand_rekening'] ?? '';
    $wa_template = $_POST['wa_template'] ?? '';
    $wa_template_paid = $_POST['wa_template_paid'] ?? '';
    
    $web_upload_dir = 'uploads/partner/';
    $fs_upload_dir = __DIR__ . '/../../public/' . $web_upload_dir;
    if (!is_dir($fs_upload_dir)) {
        mkdir($fs_upload_dir, 0777, true);
    }

    $logo_path = $user['brand_logo'];
    $qris_path = $user['brand_qris'];
    
    // Handle Logo Upload
    if (!empty($_FILES['brand_logo']['name'])) {
        $ext = pathinfo($_FILES['brand_logo']['name'], PATHINFO_EXTENSION);
        $logo_filename = 'logo_' . $u_id . '_' . time() . '.' . $ext;
        $new_logo_fs = $fs_upload_dir . $logo_filename;
        if (move_uploaded_file($_FILES['brand_logo']['tmp_name'], $new_logo_fs)) {
            $logo_path = $web_upload_dir . $logo_filename;
        }
    }
    
    // Handle QRIS Upload
    if (!empty($_FILES['brand_qris']['name'])) {
        $ext = pathinfo($_FILES['brand_qris']['name'], PATHINFO_EXTENSION);
        $qris_filename = 'qris_' . $u_id . '_' . time() . '.' . $ext;
        $new_qris_fs = $fs_upload_dir . $qris_filename;
        if (move_uploaded_file($_FILES['brand_qris']['tmp_name'], $new_qris_fs)) {
            $qris_path = $web_upload_dir . $qris_filename;
        }
    }
    
    try {
        $stmt = $db->prepare("UPDATE users SET brand_name = ?, brand_address = ?, brand_contact = ?, brand_logo = ?, brand_qris = ?, brand_bank = ?, brand_rekening = ?, wa_template = ?, wa_template_paid = ? WHERE id = ?");
        $stmt->execute([$brand_name, $brand_address, $brand_contact, $logo_path, $qris_path, $brand_bank, $brand_rekening, $wa_template, $wa_template_paid, $u_id]);
        
        $msg = "Profil berhasil diperbarui!";
        
        // Refresh user data
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$u_id]);
        $user = $stmt->fetch();
    } catch (Exception $e) {
        $err = "Gagal memperbarui profil: " . $e->getMessage();
    }
}
?>

<div class="glass-panel" style="padding: 30px; max-width: 800px; margin: 0 auto;">
    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 25px;">
        <div style="background: rgba(var(--primary-rgb), 0.1); width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary);">
            <i class="fas fa-id-card-alt" style="font-size: 24px;"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-size: 20px; font-weight: 800;">Pengaturan Profil Bisnis Mitra</h2>
            <p style="margin: 5px 0 0; font-size: 13px; color: var(--text-secondary);">Sesuaikan identitas bisnis Anda yang akan muncul pada nota pelanggan.</p>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="glass-panel" style="padding:15px; margin-bottom:20px; background:rgba(16, 185, 129, 0.1); border-left:4px solid var(--success); color:var(--success); font-weight:600;">
            <i class="fas fa-check-circle"></i> <?= $msg ?>
        </div>
    <?php endif; ?>
    <?php if($err): ?>
        <div class="glass-panel" style="padding:15px; margin-bottom:20px; background:rgba(239, 68, 68, 0.1); border-left:4px solid var(--danger); color:var(--danger); font-weight:600;">
            <i class="fas fa-exclamation-circle"></i> <?= $err ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="grid-form">
        <div style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
            <!-- Simple Fields -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Nama Bisnis / Internet</label>
                    <input type="text" name="brand_name" class="form-control" value="<?= htmlspecialchars($user['brand_name'] ?? '') ?>" placeholder="Contoh: Eka Net Solutions" required>
                </div>
                
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Nomor Telepon Bisnis (WhatsApp)</label>
                    <input type="text" name="brand_contact" class="form-control" value="<?= htmlspecialchars($user['brand_contact'] ?? '') ?>" placeholder="08xxxxxx">
                </div>

                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Alamat Bisnis</label>
                    <textarea name="brand_address" class="form-control" style="height: 60px;" placeholder="Alamat lengkap kantor/usaha Anda..."><?= htmlspecialchars($user['brand_address'] ?? '') ?></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Nama Bank</label>
                        <input type="text" name="brand_bank" class="form-control" value="<?= htmlspecialchars($user['brand_bank'] ?? '') ?>" placeholder="BRI / BCA / Mandiri">
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Nomor Rekening</label>
                        <input type="text" name="brand_rekening" class="form-control" value="<?= htmlspecialchars($user['brand_rekening'] ?? '') ?>" placeholder="Nomor Rekening">
                    </div>
                </div>
            </div>

            <!-- Upload Fields -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Logo Bisnis</label>
                    <div style="background: rgba(255,255,255,0.02); border: 2px dashed var(--glass-border); border-radius: 12px; padding: 15px; text-align: center;">
                        <?php if(!empty($user['brand_logo'])): ?>
                            <img src="<?= htmlspecialchars($user['brand_logo']) ?>" style="max-height: 60px; margin-bottom: 15px; border-radius: 5px;">
                        <?php else: ?>
                            <div style="font-size: 24px; opacity: 0.2; margin-bottom: 10px;"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                        <input type="file" name="brand_logo" accept="image/*" class="form-control" style="font-size: 12px;">
                        <p style="font-size: 11px; color: var(--text-secondary); margin-top: 8px;">Format: JPG, PNG. Maks 2MB.</p>
                    </div>
                </div>

                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">File QRIS Pembayaran</label>
                    <div style="background: rgba(255,255,255,0.02); border: 2px dashed var(--glass-border); border-radius: 12px; padding: 15px; text-align: center;">
                        <?php if(!empty($user['brand_qris'])): ?>
                            <img src="<?= htmlspecialchars($user['brand_qris']) ?>" style="max-height: 80px; margin-bottom: 15px; border-radius: 5px; border: 1px solid #ddd;">
                        <?php else: ?>
                            <div style="font-size: 24px; opacity: 0.2; margin-bottom: 10px;"><i class="fas fa-qrcode"></i></div>
                        <?php endif; ?>
                        <input type="file" name="brand_qris" accept="image/*" class="form-control" style="font-size: 12px;">
                        <p style="font-size: 11px; color: var(--text-secondary); margin-top: 8px;">Upload gambar QRIS Anda di sini.</p>
                    </div>
                </div>
            </div>
        </div>

            <!-- WhatsApp Templates section -->
        <div style="grid-column: 1 / -1; margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--glass-border);">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                <div style="background: rgba(37, 211, 102, 0.1); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #25D366;">
                    <i class="fab fa-whatsapp" style="font-size: 20px;"></i>
                </div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Pengaturan Pesan WhatsApp (Otomatis)</h3>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Template Pengingat (Belum Lunas)</label>
                    <textarea name="wa_template" class="form-control" style="height: 120px; font-size: 13px;" placeholder="Gunakan: {nama}, {tagihan}, {jatuh_tempo}, {rekening}, {link_tagihan}"><?= htmlspecialchars($user['wa_template'] ?? '') ?></textarea>
                    <small style="color:var(--text-secondary); margin-top:5px; display:block; font-size:11px;">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {jatuh_tempo}, {rekening}, {tunggakan}, {total_harus}, {link_tagihan}</small>
                </div>
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Template Kuitansi (Lunas/Sudah Bayar)</label>
                    <textarea name="wa_template_paid" class="form-control" style="height: 120px; font-size: 13px;" placeholder="Gunakan: {nama}, {tagihan}, {id_cust}, {link_tagihan}"><?= htmlspecialchars($user['wa_template_paid'] ?? '') ?></textarea>
                    <small style="color:var(--text-secondary); margin-top:5px; display:block; font-size:11px;">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {total_bayar}, {tunggakan}, {sisa_tunggakan}, {status_pembayaran}, {waktu_bayar}, {admin}, {link_tagihan}</small>
                </div>
            </div>
            <div style="background: rgba(var(--primary-rgb), 0.05); border-radius: 10px; padding: 15px; margin-top: 15px; border: 1px solid rgba(var(--primary-rgb), 0.1); display: flex; align-items: flex-start; gap: 10px;">
                <i class="fas fa-info-circle text-primary" style="margin-top: 3px;"></i> 
                <span style="font-size: 12px; color: var(--text-secondary); line-height: 1.5;">Jika dikosongkan, sistem akan otomatis menggunakan template standar dari ISP Pusat. Anda tetap dapat melakukan branding mandiri dengan kolom-kolom profil di atas.</span>
            </div>
        </div>

        <!-- Action Button -->
        <div style="grid-column: 1 / -1; margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--glass-border); display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary" style="padding: 12px 40px; font-weight: 800; border-radius: 12px; box-shadow: 0 4px 14px rgba(var(--primary-rgb), 0.3);">
                <i class="fas fa-save" style="margin-right: 8px;"></i> Simpan Perubahan
            </button>
        </div>
    </form>
</div>

<style>
.grid-form {
    display: grid;
    gap: 20px;
}
.form-group label {
    color: var(--text-primary);
}
</style>
