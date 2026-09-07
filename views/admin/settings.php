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
    
    // Stored paths may only point at our own upload folder.
    foreach (['company_logo', 'company_qris'] as $__pv) {
        if ($$__pv !== '' && !preg_match('~^public/uploads/[A-Za-z0-9_./-]+$~', $$__pv)) { $$__pv = ''; }
    }

    // Handle File Upload (validated: extension allowlist, real MIME type, random name)
    $upload_dir = __DIR__ . '/../../public/uploads';
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = save_uploaded_image($_FILES['logo_file'], $upload_dir, 'logo');
        if ($up['ok']) {
            $company_logo = 'public/uploads/' . $up['filename'];
        } else {
            $error = "Logo: " . $up['error'];
        }
    }

    // Handle QRIS Upload
    if (isset($_FILES['qris_file']) && $_FILES['qris_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = save_uploaded_image($_FILES['qris_file'], $upload_dir, 'qris');
        if ($up['ok']) {
            $company_qris = 'public/uploads/' . $up['filename'];
        } else {
            $error = "QRIS: " . $up['error'];
        }
    }

    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("UPDATE settings SET company_name=?, company_tagline=?, company_contact=?, company_address=?, site_url=?, company_logo=?, company_qris=?, wa_template=?, wa_template_paid=?, bank_account=?, router_ip=?, router_user=?, router_pass=?, router_port=?, acs_url=?, acs_user=?, acs_pass=? WHERE tenant_id=?");
    $stmt->execute([$company_name, $company_tagline, $company_contact, $company_address, $site_url, $company_logo, $company_qris, $wa_template, $wa_template_paid, $bank_account, $router_ip, $router_user, $router_pass, $router_port, $acs_url, $acs_user, $acs_pass, $tenant_id]);

    // Laporan keuangan (neraca & laba rugi): saldo awal dan parameter pajak
    $fin_num = fn($k) => (float)preg_replace('/[^0-9.\-]/', '', (string)($_POST[$k] ?? '0'));
    $fin_opening_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fin_opening_date'] ?? '') ? $_POST['fin_opening_date'] : null;
    try {
        $db->prepare("UPDATE settings SET fin_opening_date=?, fin_opening_cash=?, fin_paid_capital=?, fin_liabilities=?, fin_asset_life_years=?, fin_tax_rate=? WHERE tenant_id=?")
           ->execute([$fin_opening_date, $fin_num('fin_opening_cash'), $fin_num('fin_paid_capital'), $fin_num('fin_liabilities'), max(1, (int)$fin_num('fin_asset_life_years')), max(0, $fin_num('fin_tax_rate')), $tenant_id]);
    } catch (Exception $e) {}
    
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

<div class="mx-auto max-w-3xl">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Pengaturan aplikasi</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Profil perusahaan, template WhatsApp, integrasi router, dan sistem.</p>
        </div>
    </div>

    <?php if(isset($success)): ?>
        <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
        <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="settings-tabs mb-5 flex w-fit max-w-full flex-wrap gap-1 rounded-md border border-solid border-border bg-card p-1">
        <button type="button" class="settings-tab active cursor-pointer whitespace-nowrap rounded-sm border-0 bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground" onclick="switchTab(this, 'profil')">Profil</button>
        <button type="button" class="settings-tab cursor-pointer whitespace-nowrap rounded-sm border-0 bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground" onclick="switchTab(this, 'whatsapp')">Template WhatsApp</button>
        <button type="button" class="settings-tab cursor-pointer whitespace-nowrap rounded-sm border-0 bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground" onclick="switchTab(this, 'router')">Integrasi router</button>
        <button type="button" class="settings-tab cursor-pointer whitespace-nowrap rounded-sm border-0 bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground" onclick="switchTab(this, 'system')">Sistem</button>
    </div>

    <form method="POST" enctype="multipart/form-data" class="ui-card p-5 sm:p-6">
<?= csrf_field() ?>
        <!-- PROFIL SECTION -->
        <div id="profil" class="settings-section active-section">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama perusahaan / branding</span>
                    <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($settings['company_name']) ?>" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Slogan (tagline)</span>
                    <input type="text" name="company_tagline" class="form-control" value="<?= htmlspecialchars($settings['company_tagline']) ?>">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">WhatsApp utama (pusat bantuan)</span>
                    <input type="text" name="company_contact" class="form-control" value="<?= htmlspecialchars($settings['company_contact']) ?>" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat kantor</span>
                    <textarea name="company_address" class="form-control" rows="2"><?= htmlspecialchars($settings['company_address']) ?></textarea>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Domain / URL aplikasi (untuk link WA)</span>
                    <input type="text" name="site_url" class="form-control" value="<?= htmlspecialchars($settings['site_url'] ?? 'http://fibernodeinternet.com') ?>" placeholder="http://domainanda.com">
                    <span class="mt-1 block text-xs text-muted-foreground">Gunakan domain Anda untuk membuat link otomatis di pesan WhatsApp.</span>
                </label>

                <div class="rounded-md border border-solid border-border bg-background p-4">
                    <div class="mb-3 text-sm font-semibold">Logo perusahaan</div>
                    <div class="mb-3 flex h-40 items-center justify-center overflow-hidden rounded-md border border-solid border-border bg-card p-3">
                        <?php if(!empty($settings['company_logo'])): ?>
                            <img src="<?= htmlspecialchars($settings['company_logo']) ?>" alt="Preview" class="max-h-full max-w-full object-contain">
                        <?php else: ?>
                            <span class="text-xs text-muted-foreground">Belum ada logo</span>
                        <?php endif; ?>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-center">
                        <input type="file" name="logo_file" class="form-control" accept="image/*">
                        <button type="button" class="ui-btn ui-btn-outline" onclick="document.querySelector('[name=company_logo]').value=''" title="Hapus URL"><i class="fas fa-times"></i> Hapus URL</button>
                    </div>
                    <input type="text" name="company_logo" class="form-control mt-2" value="<?= htmlspecialchars($settings['company_logo'] ?? '') ?>" placeholder="Atau tempel URL logo di sini">
                    <p class="m-0 mt-2 text-xs text-muted-foreground">Logo kotak maupun persegi panjang akan disesuaikan otomatis.</p>
                </div>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Info rekening pembayaran</span>
                    <textarea name="bank_account" class="form-control" rows="2" placeholder="BCA: 123xxxx a/n Nama"><?= htmlspecialchars($settings['bank_account'] ?? '') ?></textarea>
                </label>

                <div class="rounded-md border border-solid border-border bg-background p-4">
                    <div class="text-sm font-semibold">Laporan keuangan untuk SPT Tahunan</div>
                    <p class="m-0 mb-3 mt-1 text-xs text-muted-foreground">Dipakai oleh Laporan Posisi Keuangan dan Laba Rugi di menu Laporan. Isi sesuai kondisi saat pembukuan di aplikasi ini dimulai.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal awal pembukuan</span>
                            <input type="date" name="fin_opening_date" class="form-control" value="<?= htmlspecialchars($settings['fin_opening_date'] ?? '') ?>">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Saldo kas awal (Rp)</span>
                            <input type="number" name="fin_opening_cash" class="form-control" min="0" step="1" value="<?= (int)($settings['fin_opening_cash'] ?? 0) ?>">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Modal disetor (Rp)</span>
                            <input type="number" name="fin_paid_capital" class="form-control" min="0" step="1" value="<?= (int)($settings['fin_paid_capital'] ?? 0) ?>">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Utang usaha / pinjaman (Rp)</span>
                            <input type="number" name="fin_liabilities" class="form-control" min="0" step="1" value="<?= (int)($settings['fin_liabilities'] ?? 0) ?>">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Masa manfaat aset tetap (tahun)</span>
                            <input type="number" name="fin_asset_life_years" class="form-control" min="1" max="20" step="1" value="<?= (int)($settings['fin_asset_life_years'] ?? 4) ?>">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Tarif PPh final (%)</span>
                            <input type="number" name="fin_tax_rate" class="form-control" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string)($settings['fin_tax_rate'] ?? 0.5)) ?>">
                        </label>
                    </div>
                    <p class="m-0 mt-3 text-xs text-muted-foreground">Kas di neraca = saldo kas awal + seluruh penerimaan − seluruh pengeluaran sejak tanggal awal. Perangkat jaringan (kelompok 1) umumnya disusutkan 4 tahun; PPh final 0,5% berlaku bagi wajib pajak yang memakai PP 55/2022.</p>
                </div>

                <div class="rounded-md border border-solid border-border bg-background p-4">
                    <div class="mb-3 text-sm font-semibold">Foto QRIS pembayaran</div>
                    <?php if(!empty($settings['company_qris'])): ?>
                        <div class="mb-3 flex max-h-52 items-center justify-center overflow-hidden rounded-md border border-solid border-border bg-card p-3">
                            <img src="<?= htmlspecialchars($settings['company_qris']) ?>" alt="QRIS" class="max-h-48 max-w-full object-contain">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="qris_file" class="form-control" accept="image/*">
                    <input type="text" name="company_qris" class="form-control mt-2" value="<?= htmlspecialchars($settings['company_qris'] ?? '') ?>" placeholder="Atau tempel URL QRIS di sini">
                    <p class="m-0 mt-2 text-xs text-muted-foreground">Unggah foto QRIS perusahaan Anda agar pelanggan bisa membayar dengan memindai.</p>
                </div>
            </div>
        </div>

        <!-- WHATSAPP SECTION -->
        <div id="whatsapp" class="settings-section" style="display:none;">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Template pesan (belum lunas)</span>
                    <textarea name="wa_template" class="form-control" rows="5"><?= htmlspecialchars($settings['wa_template'] ?? '') ?></textarea>
                    <span class="mt-1 block text-xs text-muted-foreground">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {jatuh_tempo}, {rekening}, {tunggakan}, {total_harus}, {link_tagihan}, {perusahaan}</span>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Template kuitansi (lunas / sudah bayar)</span>
                    <textarea name="wa_template_paid" class="form-control" rows="5" placeholder="Gunakan: {nama}, {tagihan}, {id_cust}, {link_tagihan}"><?= htmlspecialchars($settings['wa_template_paid'] ?? '') ?></textarea>
                    <span class="mt-1 block text-xs text-muted-foreground">Variabel: {nama}, {id_cust}, {paket}, {bulan}, {tagihan}, {total_bayar}, {tunggakan}, {sisa_tunggakan}, {status_pembayaran}, {waktu_bayar}, {admin}, {link_tagihan}</span>
                </label>
            </div>
        </div>

        <!-- ROUTER SECTION -->
        <div id="router" class="settings-section" style="display:none;">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">IP Mikrotik / host</span>
                    <input type="text" name="router_ip" class="form-control" value="<?= htmlspecialchars($settings['router_ip'] ?? '') ?>">
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Username API</span>
                        <input type="text" name="router_user" class="form-control" value="<?= htmlspecialchars($settings['router_user'] ?? '') ?>">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Port (default 8728)</span>
                        <input type="text" name="router_port" class="form-control" value="<?= htmlspecialchars($settings['router_port'] ?? '8728') ?>">
                    </label>
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Password API</span>
                    <input type="password" name="router_pass" class="form-control" value="<?= htmlspecialchars($settings['router_pass'] ?? '') ?>">
                </label>
            </div>
        </div>

        <!-- SYSTEM SECTION -->
        <div id="system" class="settings-section" style="display:none;">
            <div class="flex flex-col gap-3 rounded-md border border-solid border-border bg-background p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm font-semibold">Update aplikasi otomatis</div>
                    <p class="m-0 mt-1 text-xs text-muted-foreground">Fitur terbaru, perbaikan bug, dan optimasi performa langsung dari pusat pembaruan.</p>
                </div>
                <a href="index.php?page=admin_updater" class="ui-btn ui-btn-outline w-full sm:w-auto">Buka pengelola update</a>
            </div>

            <div class="mt-4 rounded-md border border-solid border-border bg-background p-4">
                <div class="mb-3 text-sm font-semibold">Konfigurasi TR-069 (GenieACS)</div>
                <div class="grid gap-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">GenieACS API URL</span>
                        <input type="text" name="acs_url" class="form-control" value="<?= htmlspecialchars($settings['acs_url'] ?? '') ?>" placeholder="http://1.2.3.4:7557">
                        <span class="mt-1 block text-xs text-muted-foreground">Gunakan port default GenieACS (7557).</span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Username API (opsional)</span>
                            <input type="text" name="acs_user" class="form-control" value="<?= htmlspecialchars($settings['acs_user'] ?? '') ?>">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Password API (opsional)</span>
                            <input type="password" name="acs_pass" class="form-control" value="<?= htmlspecialchars($settings['acs_pass'] ?? '') ?>">
                        </label>
                    </div>
                </div>
            </div>

            <p class="m-0 mt-4 text-xs text-muted-foreground">Versi saat ini: v1.5.0-Stable · Lisensi: <?= LICENSE_ST ?></p>
        </div>

        <div class="mt-6 flex justify-end gap-2 border-t border-solid border-border pt-4">
            <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto"><i class="fas fa-save"></i> Simpan semua</button>
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
