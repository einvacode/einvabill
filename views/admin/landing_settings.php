<?php
$action = $_GET['action'] ?? 'list';

// ONE-TIME MIGRATION: Create landing_logos if not exists
try {
    $db->query("SELECT id FROM landing_logos LIMIT 1");
} catch (Exception $e) {
    echo "<div style='padding:20px; background:#10b981; color:white; border-radius:12px; margin-bottom:20px;'>Updating system for unlimited logos...</div>";
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->exec("CREATE TABLE IF NOT EXISTS landing_logos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        image_path TEXT,
        sort_order INTEGER DEFAULT 0,
        tenant_id INTEGER DEFAULT $tenant_id,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Migrate existing 1-4 from settings
    $s = $db->query("SELECT powered_logo_1, powered_logo_2, powered_logo_3, powered_logo_4 FROM settings WHERE id=1")->fetch();
    for($i=1; $i<=4; $i++) {
        $logo = $s['powered_logo_'.$i] ?? '';
        if(!empty($logo)) {
            $db->prepare("INSERT INTO landing_logos (image_path, sort_order) VALUES (?, ?)")->execute([$logo, $i]);
        }
    }
}

// Handle Setting Update
if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['landing_hero_title'] ?? '';
    $text = $_POST['landing_hero_text'] ?? '';
    $about = $_POST['landing_about_us'] ?? '';
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("UPDATE settings SET landing_hero_title=?, landing_hero_text=?, landing_about_us=? WHERE tenant_id=?");
    $stmt->execute([$title, $text, $about, $tenant_id]);
    header("Location: index.php?page=admin_landing");
    exit;
}

// Handle Add/Edit Package
if ($action === 'save_package' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'];
    $speed = $_POST['speed'];
    $price = $_POST['price'];
    $features = $_POST['features'];
    $is_active = $_POST['is_active'] ?? 1;
    $sort_order = $_POST['sort_order'] ?? 0;
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    if($id) {
        $stmt = $db->prepare("UPDATE landing_packages SET name=?, speed=?, price=?, features=?, is_active=?, sort_order=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$name, $speed, $price, $features, $is_active, $sort_order, $id, $tenant_id]);
    } else {
        $stmt = $db->prepare("INSERT INTO landing_packages (name, speed, price, features, is_active, sort_order, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $speed, $price, $features, $is_active, $sort_order, $tenant_id]);
    }
    header("Location: index.php?page=admin_landing");
    exit;
}

// Handle Delete Package
if ($action === 'delete_package') {
    $id = $_GET['id'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->prepare("DELETE FROM landing_packages WHERE id=? AND tenant_id=?")->execute([$id, $tenant_id]);
    header("Location: index.php?page=admin_landing");
    exit;
}

// Handle Powered By Logo Upload (UNLIMITED)
if ($action === 'add_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['logo_file'])) {
        $up = save_uploaded_image($_FILES['logo_file'], __DIR__ . '/../../public/uploads', 'logo', 800);
        if ($up['ok']) {
            $path = 'public/uploads/' . $up['filename'];
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            $db->prepare("INSERT INTO landing_logos (image_path, tenant_id) VALUES (?, ?)")->execute([$path, $tenant_id]);
            header("Location: index.php?page=admin_landing&msg=logo_added");
            exit;
        }
        header("Location: index.php?page=admin_landing&msg=logo_error&reason=" . urlencode($up['error']));
        exit;
    }
    header("Location: index.php?page=admin_landing");
    exit;
}

// Handle Powered By Logo Delete (UNLIMITED)
if ($action === 'delete_logo') {
    $id = intval($_GET['id'] ?? 0);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $old = $db->query("SELECT image_path FROM landing_logos WHERE id=$id AND tenant_id = $tenant_id")->fetchColumn();
    if ($old && file_exists(__DIR__ . '/../../' . $old)) {
        @unlink(__DIR__ . '/../../' . $old);
    }
    $db->prepare("DELETE FROM landing_logos WHERE id=? AND tenant_id = ?")->execute([$id, $tenant_id]);
    header("Location: index.php?page=admin_landing&msg=logo_deleted");
    exit;
}

$tenant_id = $_SESSION['tenant_id'] ?? 1;
$site_settings = $db->query("SELECT landing_hero_title, landing_hero_text, landing_about_us FROM settings WHERE tenant_id=$tenant_id")->fetch();
$packages = $db->query("SELECT * FROM landing_packages WHERE tenant_id=$tenant_id ORDER BY sort_order ASC, id ASC")->fetchAll();
$partner_logos = $db->query("SELECT * FROM landing_logos WHERE tenant_id=$tenant_id ORDER BY sort_order ASC, id ASC")->fetchAll();
?>

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Pengaturan konten web profil (landing page)</h2>
    </div>
    <a href="index.php?page=landing" target="_blank" class="ui-btn ui-btn-outline"><i class="fas fa-external-link-alt"></i> Lihat hasil web</a>
</div>

<div class="ui-card mb-5 p-5 sm:p-6">
    <form action="index.php?page=admin_landing&action=update_profile" method="POST">
<?= csrf_field() ?>
        <div class="grid gap-4">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Judul utama (hero title)</span>
                <input type="text" name="landing_hero_title" class="form-control" value="<?= htmlspecialchars($site_settings['landing_hero_title'] ?? '') ?>" placeholder="Misal: Era Baru Koneksi Super Cepat & Stabil">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Sub-teks (hero subtitle)</span>
                <input type="text" name="landing_hero_text" class="form-control" value="<?= htmlspecialchars($site_settings['landing_hero_text'] ?? '') ?>" placeholder="Misal: Menyediakan layanan internet handal...">
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Tentang kami (about us)</span>
                <textarea name="landing_about_us" class="form-control" rows="4" placeholder="Ceritakan latar belakang profil perusahaan Anda di sini..."><?= htmlspecialchars($site_settings['landing_about_us'] ?? '') ?></textarea>
            </label>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto"><i class="fas fa-save"></i> Simpan profil perusahaan</button>
        </div>
    </form>
</div>

<section class="ui-card mb-5 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <h3 class="m-0 text-[15px] font-bold">Etalase paket internet / layanan</h3>
        <button onclick="document.getElementById('modalPackage').style.display='flex';" class="ui-btn ui-btn-sm ui-btn-outline"><i class="fas fa-plus"></i> Tambah layanan</button>
    </div>

    <div class="table-container overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="w-[60px] px-4 py-2.5 text-center font-semibold">Urutan</th>
                    <th class="px-4 py-2.5 font-semibold">Nama paket/layanan</th>
                    <th class="px-4 py-2.5 font-semibold">Kecepatan</th>
                    <th class="px-4 py-2.5 font-semibold">Harga (Rp)</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($packages as $pkg): ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 text-center tabular-nums"><?= $pkg['sort_order'] ?></td>
                    <td class="px-4 py-3 font-semibold"><?= htmlspecialchars($pkg['name']) ?></td>
                    <td class="px-4 py-3"><span class="ui-badge ui-badge-muted"><?= htmlspecialchars($pkg['speed']) ?></span></td>
                    <td class="px-4 py-3 font-semibold tabular-nums">
                        <?= $pkg['price'] > 0 ? 'Rp ' . number_format($pkg['price'], 0, ',', '.') : 'Hubungi Kami' ?>
                    </td>
                    <td class="px-4 py-3">
                        <?= $pkg['is_active'] ? '<span class="ui-badge ui-badge-signal">Aktif / tampil</span>' : '<span class="ui-badge ui-badge-muted">Disembunyikan</span>' ?>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="inline-flex gap-1">
                            <button onclick="editPackage(<?= htmlspecialchars(json_encode($pkg)) ?>)" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit"><i class="fas fa-edit"></i><span class="hidden sm:inline">Edit</span></button>
                            <a data-method="post" href="index.php?page=admin_landing&action=delete_package&id=<?= $pkg['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" onclick="return confirm('Hapus etalase layanan ini?')"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($packages) == 0): ?>
                    <tr class="border-t border-solid border-border"><td colspan="6" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada etalase layanan/paket yang ditambahkan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal Dialog -->
<div id="modalPackage" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-lg p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 id="modalTitle" class="m-0 text-lg font-bold">Tambah paket / layanan</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('modalPackage').style.display='none';" aria-label="Tutup">✕</button>
        </div>
        <form action="index.php?page=admin_landing&action=save_package" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="pkg_id">

            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama paket (misal: Paket Keluarga, atau Corporate Dedicated)</span>
                    <input type="text" name="name" id="pkg_name" class="form-control" required>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Kecepatan (misal: 20 Mbps)</span>
                        <input type="text" name="speed" id="pkg_speed" class="form-control">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Harga per bulan (angka, 0 = hubungi kami)</span>
                        <input type="number" name="price" id="pkg_price" class="form-control" value="0">
                    </label>
                </div>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Fitur & keunggulan (pisahkan dengan koma)</span>
                    <input type="text" name="features" id="pkg_features" class="form-control" placeholder="100% Fiber Optic, Bantuan 24 Jam, Tanpa FUP">
                    <small class="mt-1 block text-xs text-muted-foreground">Contoh: Fiber Optic, Bantuan 24 Jam, Tanpa FUP</small>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nomor urut tampil</span>
                        <input type="number" name="sort_order" id="pkg_sort" class="form-control" value="1">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Status tayang</span>
                        <select name="is_active" id="pkg_active" class="form-control">
                            <option value="1">Aktif / Tampil</option>
                            <option value="0">Sembunyikan</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="form-actions-row mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('modalPackage').style.display='none';">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan etalase</button>
            </div>
        </form>
    </div>
</div>

<!-- Powered By Logos Panel -->
<div class="ui-card p-5 sm:p-6">
    <h3 class="m-0 text-[15px] font-bold">Logo "Didukung oleh" (powered by)</h3>
    <p class="m-0 mb-4 mt-1 text-xs text-muted-foreground">Upload logo mitra/vendor yang akan ditampilkan di halaman landing page publik Anda (tidak terbatas).</p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach($partner_logos as $p): ?>
        <div class="flex flex-col items-center rounded-lg border border-solid border-border p-4 text-center">
            <img src="<?= htmlspecialchars($p['image_path']) ?>" class="mb-4 max-h-[60px] max-w-full" alt="Logo">
            <a data-method="post" href="index.php?page=admin_landing&action=delete_logo&id=<?= $p['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger mt-auto w-full" onclick="return confirm('Hapus logo ini?')">
                <i class="fas fa-trash"></i> Hapus
            </a>
        </div>
        <?php endforeach; ?>

        <!-- Form Tambah Baru -->
        <div class="flex min-h-[140px] flex-col items-center justify-center rounded-lg border border-dashed border-border p-4 text-center">
            <form action="index.php?page=admin_landing&action=add_logo" method="POST" enctype="multipart/form-data" id="form-new-logo" class="w-full">
<?= csrf_field() ?>
                <div class="mb-2 text-xs font-semibold text-foreground">Tambah logo baru</div>
                <input type="file" name="logo_file" accept="image/*" class="form-control mb-2 text-xs" onchange="this.form.submit()">
                <div class="text-[11px] text-muted-foreground">Pilih file untuk upload otomatis</div>
            </form>
        </div>
    </div>
</div>

<script>
function editPackage(data) {
    document.getElementById('modalTitle').innerText = 'Edit Paket/Layanan';
    document.getElementById('pkg_id').value = data.id;
    document.getElementById('pkg_name').value = data.name;
    document.getElementById('pkg_speed').value = data.speed;
    document.getElementById('pkg_price').value = data.price;
    document.getElementById('pkg_features').value = data.features;
    document.getElementById('pkg_sort').value = data.sort_order;
    document.getElementById('pkg_active').value = data.is_active;
    
    document.getElementById('modalPackage').style.display = 'flex';
}
</script>
