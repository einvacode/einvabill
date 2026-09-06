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

    $logo_path = $user['brand_logo'];
    $qris_path = $user['brand_qris'];

    // Logo Upload Disabled (Policy)

    // Handle QRIS Upload (validated: extension allowlist, real MIME type, random name)
    if (isset($_FILES['brand_qris']) && $_FILES['brand_qris']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = save_uploaded_image($_FILES['brand_qris'], $fs_upload_dir, 'qris_' . intval($u_id));
        if ($up['ok']) {
            $qris_path = $web_upload_dir . $up['filename'];
        } else {
            $err = "QRIS: " . $up['error'];
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

<div class="mx-auto max-w-3xl">
    <div class="mb-5">
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Profil bisnis mitra</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Sesuaikan identitas bisnis Anda yang akan muncul pada nota pelanggan.</p>
    </div>

    <?php if($msg): ?>
        <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> <?= $msg ?></div>
    <?php endif; ?>
    <?php if($err): ?>
        <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> <?= $err ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>
        <section class="ui-card p-4 sm:p-5">
            <h3 class="m-0 mb-4 text-[15px] font-bold">Identitas bisnis</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <!-- Simple Fields -->
                <div class="flex flex-col gap-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama bisnis / internet</span>
                        <input type="text" name="brand_name" class="form-control w-full" value="<?= htmlspecialchars($user['brand_name'] ?? '') ?>" placeholder="Contoh: Eka Net Solutions" required>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nomor telepon bisnis (WhatsApp)</span>
                        <input type="text" name="brand_contact" class="form-control w-full" value="<?= htmlspecialchars($user['brand_contact'] ?? '') ?>" placeholder="08xxxxxx">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat bisnis</span>
                        <textarea name="brand_address" class="form-control w-full" rows="3" placeholder="Alamat lengkap kantor/usaha Anda..."><?= htmlspecialchars($user['brand_address'] ?? '') ?></textarea>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama bank</span>
                            <input type="text" name="brand_bank" class="form-control w-full" value="<?= htmlspecialchars($user['brand_bank'] ?? '') ?>" placeholder="BRI / BCA / Mandiri">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Nomor rekening</span>
                            <input type="text" name="brand_rekening" class="form-control w-full" value="<?= htmlspecialchars($user['brand_rekening'] ?? '') ?>" placeholder="Nomor rekening">
                        </label>
                    </div>
                </div>

                <!-- Upload Fields -->
                <div class="flex flex-col gap-4">
                    <div>
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Logo bisnis (ISP pusat)</span>
                        <div class="rounded-md border border-solid border-border bg-muted/50 p-4 text-center">
                            <?php
                                // Fetch Admin Logo for preview
                                $tenant_id_p = $_SESSION['tenant_id'] ?? 1;
                                $admin_logo = $db->query("SELECT company_logo FROM settings WHERE tenant_id = $tenant_id_p OR id = 1 LIMIT 1")->fetchColumn();
                                $logo_src = '';
                                if (!empty($admin_logo)) {
                                    $logo_src = preg_match('/^http/', $admin_logo) ? $admin_logo : '/' . str_replace(' ', '%20', $admin_logo);
                                }
                            ?>
                            <?php if(!empty($logo_src)): ?>
                                <img src="<?= htmlspecialchars($logo_src) ?>" class="mx-auto mb-3 max-h-[60px] rounded-sm" alt="Logo ISP pusat">
                            <?php endif; ?>
                            <div class="text-xs text-muted-foreground">Logo dikelola oleh ISP pusat.</div>
                        </div>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">File QRIS pembayaran</span>
                        <div class="rounded-md border border-dashed border-border p-4 text-center">
                            <?php
                                $qris_src = '';
                                if (!empty($user['brand_qris'])) {
                                    $qris_src = preg_match('/^http/', $user['brand_qris']) ? $user['brand_qris'] : '/' . str_replace(' ', '%20', $user['brand_qris']);
                                }
                            ?>
                            <?php if(!empty($qris_src)): ?>
                                <img src="<?= htmlspecialchars($qris_src) ?>" class="mx-auto mb-3 max-h-20 rounded-sm border border-solid border-border" alt="QRIS">
                            <?php endif; ?>
                            <input type="file" name="brand_qris" accept="image/*" class="form-control w-full text-xs">
                            <p class="m-0 mt-2 text-xs text-muted-foreground">Unggah gambar QRIS Anda di sini.</p>
                        </div>
                    </label>
                </div>
            </div>
        </section>

        <!-- WhatsApp Templates section -->
        <section class="ui-card mt-5 p-4 sm:p-5">
            <h3 class="m-0 text-[15px] font-bold">Pesan WhatsApp otomatis</h3>
            <p class="m-0 mb-4 text-xs text-muted-foreground">Jika dikosongkan, sistem akan otomatis menggunakan template standar dari ISP pusat. Anda tetap dapat melakukan branding mandiri dengan kolom-kolom profil di atas.</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Template pengingat (belum lunas)</span>
                    <textarea name="wa_template" class="form-control w-full text-[13px]" rows="6" placeholder="Gunakan: {nama}, {tagihan}, {jatuh_tempo}, {rekening}, {link_tagihan}"><?= htmlspecialchars($user['wa_template'] ?? '') ?></textarea>
                    <small class="mt-1 block text-[11px] text-muted-foreground">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {jatuh_tempo}, {rekening}, {tunggakan}, {total_harus}, {link_tagihan}, {link_nota}</small>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Template kuitansi (lunas/sudah bayar)</span>
                    <textarea name="wa_template_paid" class="form-control w-full text-[13px]" rows="6" placeholder="Gunakan: {nama}, {tagihan}, {id_cust}, {link_tagihan}"><?= htmlspecialchars($user['wa_template_paid'] ?? '') ?></textarea>
                    <small class="mt-1 block text-[11px] text-muted-foreground">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {total_bayar}, {tunggakan}, {sisa_tunggakan}, {status_pembayaran}, {waktu_bayar}, {admin}, {link_tagihan}, {link_nota}</small>
                </label>
            </div>
        </section>

        <!-- Action Button -->
        <div class="mt-6 flex justify-end gap-2">
            <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto"><i class="fas fa-save"></i> Simpan perubahan</button>
        </div>
    </form>
</div>
