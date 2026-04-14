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
    $upload_dir = __DIR__ . '/../../public/uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $fname = 'logo_v2_' . time() . '_' . str_replace(' ', '_', basename($_FILES['logo_file']['name']));
        $target = $upload_dir . $fname;
        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target)) {
            $path = 'public/uploads/' . $fname;
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            $db->prepare("INSERT INTO landing_logos (image_path, tenant_id) VALUES (?, ?)")->execute([$path, $tenant_id]);
        }
    }
    header("Location: index.php?page=admin_landing&msg=logo_added");
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

<div class="glass-panel" style="padding: 24px; margin-bottom:20px;">
    <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-edit"></i> Pengaturan Konten Web Profil (Landing Page)</h3>
    <form action="index.php?page=admin_landing&action=update_profile" method="POST">
        <div class="form-group">
            <label>Judul Utama (Hero Title)</label>
            <input type="text" name="landing_hero_title" class="form-control" value="<?= htmlspecialchars($site_settings['landing_hero_title'] ?? '') ?>" placeholder="Misal: Era Baru Koneksi Super Cepat & Stabil">
        </div>
        <div class="form-group">
            <label>Sub-teks (Hero Subtitle)</label>
            <input type="text" name="landing_hero_text" class="form-control" value="<?= htmlspecialchars($site_settings['landing_hero_text'] ?? '') ?>" placeholder="Misal: Menyediakan layanan internet handal...">
        </div>
        <div class="form-group">
            <label>Tentang Kami (About Us)</label>
            <textarea name="landing_about_us" class="form-control" rows="4" placeholder="Ceritakan latar belakang profil perusahaan Anda di sini..."><?= htmlspecialchars($site_settings['landing_about_us'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Profil Perusahaan</button>
        <a href="index.php?page=landing" target="_blank" class="btn btn-ghost"><i class="fas fa-external-link-alt"></i> Lihat Hasil Web</a>
    </form>
</div>

<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; margin-bottom:20px;">
        <h3 style="font-size:18px; margin:0;"><i class="fas fa-box"></i> Etalase Paket Internet / Layanan</h3>
        <button onclick="document.getElementById('modalPackage').style.display='flex';" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Tambah Layanan</button>
    </div>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">Urutan</th>
                    <th>Nama Paket/Layanan</th>
                    <th>Kecepatan</th>
                    <th>Harga (Rp)</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($packages as $pkg): ?>
                <tr>
                    <td style="text-align:center;"><?= $pkg['sort_order'] ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($pkg['name']) ?></td>
                    <td><span class="badge" style="background:rgba(59,130,246,0.2); color:#60a5fa;"><?= htmlspecialchars($pkg['speed']) ?></span></td>
                    <td style="font-weight:bold;">
                        <?= $pkg['price'] > 0 ? 'Rp ' . number_format($pkg['price'], 0, ',', '.') : 'Hubungi Kami' ?>
                    </td>
                    <td>
                        <?= $pkg['is_active'] ? '<span class="badge badge-success">Aktif / Tampil</span>' : '<span class="badge badge-danger">Disembunyikan</span>' ?>
                    </td>
                    <td>
                        <button onclick="editPackage(<?= htmlspecialchars(json_encode($pkg)) ?>)" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i> Edit</button>
                        <a href="index.php?page=admin_landing&action=delete_package&id=<?= $pkg['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus etalase layanan ini?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($packages) == 0): ?>
                    <tr><td colspan="6" style="text-align:center;">Belum ada etalase layanan/paket yang ditambahkan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Dialog -->
<div id="modalPackage" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(5px);">
    <div class="glass-panel" style="width:100%; max-width:500px; padding:24px; position:relative;">
        <h3 id="modalTitle" style="margin-bottom:20px;">Tambah Paket / Layanan</h3>
        <form action="index.php?page=admin_landing&action=save_package" method="POST">
            <input type="hidden" name="id" id="pkg_id">
            
            <div class="form-group">
                <label>Nama Paket (Misal: Paket Keluarga, atau Corporate Dedicated)</label>
                <input type="text" name="name" id="pkg_name" class="form-control" required>
            </div>
            
            <div class="flex" style="gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label>Kecepatan (Misal: 20 Mbps)</label>
                    <input type="text" name="speed" id="pkg_speed" class="form-control">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Harga per Bulan (Angka, 0=Hubungi Kami)</label>
                    <input type="number" name="price" id="pkg_price" class="form-control" value="0">
                </div>
            </div>
            
            <div class="form-group">
                <label>Fitur & Keunggulan (Pisahkan dengan Koma)</label>
                <input type="text" name="features" id="pkg_features" class="form-control" placeholder="100% Fiber Optic, Bantuan 24 Jam, Tanpa FUP">
                <small style="color:var(--text-secondary);">Contoh: Fiber Optic, Bantuan 24 Jam, Tanpa FUP</small>
            </div>
            
            <div class="flex" style="gap:15px;">
                <div class="form-group" style="flex:1;">
                    <label>Nomor Urut Tampil</label>
                    <input type="number" name="sort_order" id="pkg_sort" class="form-control" value="1">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Status Tayang</label>
                    <select name="is_active" id="pkg_active" class="form-control">
                        <option value="1">Aktif / Tampil</option>
                        <option value="0">Sembunyikan</option>
                    </select>
                </div>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalPackage').style.display='none';">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Etalase</button>
            </div>
        </form>
    </div>
</div>

<!-- Powered By Logos Panel -->
<div class="glass-panel" style="padding: 24px; margin-top: 20px;">
    <h3 style="font-size:18px; margin-bottom:8px;"><i class="fas fa-handshake"></i> Logo "Didukung Oleh" (Powered By)</h3>
    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:20px;">Upload logo mitra/vendor yang akan ditampilkan di halaman landing page publik Anda (Tidak terbatas).</p>
    
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:20px;">
        <?php foreach($partner_logos as $p): ?>
        <div style="background:rgba(0,0,0,0.2); border:1px solid var(--glass-border); border-radius:12px; padding:20px; text-align:center; position:relative;">
            <img src="<?= htmlspecialchars($p['image_path']) ?>" style="max-height:60px; max-width:100%; margin-bottom:15px; filter:none;" alt="Logo">
            <br>
            <a href="index.php?page=admin_landing&action=delete_logo&id=<?= $p['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus logo ini?')" style="font-size:11px; width:100%;">
                <i class="fas fa-trash"></i> Hapus
            </a>
        </div>
        <?php endforeach; ?>

        <!-- Form Tambah Baru -->
        <div style="background:rgba(59,130,246,0.05); border:2px dashed var(--primary); border-radius:12px; padding:20px; text-align:center; display:flex; flex-direction:column; justify-content:center; align-items:center; min-height:140px;">
            <form action="index.php?page=admin_landing&action=add_logo" method="POST" enctype="multipart/form-data" id="form-new-logo" style="width:100%;">
                <i class="fas fa-plus-circle" style="font-size:24px; color:var(--primary); margin-bottom:10px;"></i>
                <div style="font-size:12px; font-weight:700; color:var(--primary); margin-bottom:10px;">TAMBAH LOGO BARU</div>
                <input type="file" name="logo_file" accept="image/*" class="form-control" style="font-size:11px; margin-bottom:10px;" onchange="this.form.submit()">
                <div style="font-size:9px; color:var(--text-secondary);">Pilih file untuk upload otomatis</div>
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
