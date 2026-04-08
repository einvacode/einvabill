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
    $name = $_POST['name'] ?? '';
    $brand_bank = $_POST['brand_bank'] ?? '';
    $brand_rekening = $_POST['brand_rekening'] ?? '';
    $wa_template = $_POST['wa_template'] ?? '';
    $wa_template_paid = $_POST['wa_template_paid'] ?? '';
    
    try {
        $stmt = $db->prepare("UPDATE users SET name = ?, brand_bank = ?, brand_rekening = ?, wa_template = ?, wa_template_paid = ? WHERE id = ?");
        $stmt->execute([$name, $brand_bank, $brand_rekening, $wa_template, $wa_template_paid, $u_id]);
        
        $_SESSION['user_name'] = $name; // Update session name
        $msg = "Profil & WhatsApp berhasil diperbarui!";
        
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
            <i class="fas fa-user-cog" style="font-size: 24px;"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-size: 20px; font-weight: 800;">Profil & WhatsApp Collector</h2>
            <p style="margin: 5px 0 0; font-size: 13px; color: var(--text-secondary);">Kelola identitas penagihan dan template WhatsApp Anda sendiri.</p>
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

    <form method="POST" class="grid-form">
        <div style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr; gap: 20px;">
            <div class="form-group">
                <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Nama Lengkap Petugas</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name'] ?? '') ?>" placeholder="Nama Anda" required>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Nama Bank (Untuk Penagihan)</label>
                    <input type="text" name="brand_bank" class="form-control" value="<?= htmlspecialchars($user['brand_bank'] ?? '') ?>" placeholder="BRI / BCA / Mandiri">
                </div>
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Nomor Rekening</label>
                    <input type="text" name="brand_rekening" class="form-control" value="<?= htmlspecialchars($user['brand_rekening'] ?? '') ?>" placeholder="Nomor Rekening">
                </div>
            </div>
        </div>

        <!-- WhatsApp Templates section -->
        <div style="grid-column: 1 / -1; margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--glass-border);">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px;">
                <div style="background: rgba(37, 211, 102, 0.1); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #25D366;">
                    <i class="fab fa-whatsapp" style="font-size: 20px;"></i>
                </div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Template Pesan WhatsApp Anda</h3>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Template Pengingat (Belum Lunas)</label>
                    <textarea name="wa_template" class="form-control" style="height: 100px; font-size: 13px;" placeholder="Gunakan: {nama}, {tagihan}, {jatuh_tempo}, {rekening}, {link_tagihan}"><?= htmlspecialchars($user['wa_template'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label style="font-weight: 700; font-size: 13px; margin-bottom: 8px; display: block;">Template Kuitansi (Lunas/Sudah Bayar)</label>
                    <textarea name="wa_template_paid" class="form-control" style="height: 100px; font-size: 13px;" placeholder="Gunakan: {nama}, {tagihan}, {id_cust}, {link_tagihan}"><?= htmlspecialchars($user['wa_template_paid'] ?? '') ?></textarea>
                </div>
            </div>
            <div style="background: rgba(var(--primary-rgb), 0.05); border-radius: 10px; padding: 15px; margin-top: 15px; border: 1px solid rgba(var(--primary-rgb), 0.1); display: flex; align-items: flex-start; gap: 10px;">
                <i class="fas fa-info-circle text-primary" style="margin-top: 3px;"></i> 
                <span style="font-size: 11px; color: var(--text-secondary); line-height: 1.5;">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {jatuh_tempo}, {rekening}, {tunggakan}, {total_harus}, {total_bayar}, {sisa_tunggakan}, {link_tagihan}, {admin}, {waktu_bayar}</span>
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
    margin-bottom: 8px;
    display: block;
}
</style>
