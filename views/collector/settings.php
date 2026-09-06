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

<div class="mx-auto max-w-3xl">
    <div class="mb-5">
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Profil &amp; WhatsApp collector</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Kelola identitas penagihan dan template WhatsApp Anda sendiri.</p>
    </div>

    <?php if($msg): ?>
        <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> <?= $msg ?></div>
    <?php endif; ?>
    <?php if($err): ?>
        <div class="ui-card mb-5 border-danger/40 p-4 text-sm"><span class="font-semibold text-danger">Gagal.</span> <?= $err ?></div>
    <?php endif; ?>

    <form method="POST" class="ui-card p-5 sm:p-6">
<?= csrf_field() ?>
        <div class="grid gap-4">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama lengkap petugas</span>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name'] ?? '') ?>" placeholder="Nama Anda" required>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama bank (untuk penagihan)</span>
                    <input type="text" name="brand_bank" class="form-control" value="<?= htmlspecialchars($user['brand_bank'] ?? '') ?>" placeholder="BRI / BCA / Mandiri">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nomor rekening</span>
                    <input type="text" name="brand_rekening" class="form-control" value="<?= htmlspecialchars($user['brand_rekening'] ?? '') ?>" placeholder="Nomor rekening">
                </label>
            </div>
        </div>

        <!-- WhatsApp Templates section -->
        <div class="mt-6 border-t border-solid border-border pt-5">
            <h3 class="m-0 text-[15px] font-bold">Template pesan WhatsApp Anda</h3>

            <div class="mt-4 grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Template pengingat (belum lunas)</span>
                    <textarea name="wa_template" class="form-control h-28 text-[13px]" placeholder="Gunakan: {nama}, {tagihan}, {jatuh_tempo}, {rekening}, {link_tagihan}"><?= htmlspecialchars($user['wa_template'] ?? '') ?></textarea>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Template kuitansi (lunas / sudah bayar)</span>
                    <textarea name="wa_template_paid" class="form-control h-28 text-[13px]" placeholder="Gunakan: {nama}, {tagihan}, {id_cust}, {link_tagihan}"><?= htmlspecialchars($user['wa_template_paid'] ?? '') ?></textarea>
                </label>
            </div>
            <p class="m-0 mt-3 text-xs leading-relaxed text-muted-foreground">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {jatuh_tempo}, {rekening}, {tunggakan}, {total_harus}, {total_bayar}, {sisa_tunggakan}, {link_tagihan}, {admin}, {waktu_bayar}</p>
        </div>

        <!-- Action Button -->
        <div class="mt-6 flex justify-end gap-2">
            <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto">Simpan perubahan</button>
        </div>
    </form>
</div>
