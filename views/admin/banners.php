<?php
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$action = $_GET['action'] ?? 'list';

// Handle Actions
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $title = $_POST['title'] ?? '';
    $content = $_POST['content'] ?? '';
    $target_role = $_POST['target_role'] ?? 'all';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Handle Image Upload (Optional)
    $image_path = $_POST['existing_image'] ?? '';
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_ext)) {
            $upload_dir = 'public/uploads/banners/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $filename = 'banner_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename);
            $image_path = $upload_dir . $filename;
        } else {
            $error = "Format file gambar tidak didukung! Gunakan JPG, PNG, atau WebP.";
        }
    }

    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    if ($id) {
        $db->prepare("UPDATE banners SET title=?, content=?, image_path=?, target_role=?, is_active=? WHERE id=? AND tenant_id=?")
           ->execute([$title, $content, $image_path, $target_role, $is_active, $id, $tenant_id]);
    } else {
        $db->prepare("INSERT INTO banners (title, content, image_path, target_role, is_active, tenant_id) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$title, $content, $image_path, $target_role, $is_active, $tenant_id]);
    }
    header("Location: index.php?page=admin_banners&msg=success");
    exit;
}

if ($action === 'delete') {
    $id = $_GET['id'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->prepare("DELETE FROM banners WHERE id=? AND tenant_id=?")->execute([$id, $tenant_id]);
    header("Location: index.php?page=admin_banners&msg=deleted");
    exit;
}

if ($action === 'toggle') {
    $id = $_GET['id'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->prepare("UPDATE banners SET is_active = NOT is_active WHERE id=? AND tenant_id=?")->execute([$id, $tenant_id]);
    header("Location: index.php?page=admin_banners");
    exit;
}

// Fetch Banners
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$banners = $db->query("SELECT * FROM banners WHERE tenant_id = $tenant_id ORDER BY created_at DESC")->fetchAll();

$editing = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("SELECT * FROM banners WHERE id=? AND tenant_id=?");
    $stmt->execute([$id, $tenant_id]);
    $editing = $stmt->fetch();
}
?>

<div class="container-fluid">
    <div style="display:flex; justify-content:flex-end; align-items:center; margin-bottom:25px;">
        <?php if($action !== 'add' && $action !== 'edit'): ?>
            <a href="index.php?page=admin_banners&action=add" class="btn btn-primary">
                <i class="fas fa-plus"></i> Tambah Banner Baru
            </a>
        <?php endif; ?>
    </div>

    <?php if($action === 'add' || $action === 'edit'): ?>
        <div class="glass-panel" style="max-width:800px; margin: 0 auto; padding:30px; border-radius:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                <h3 style="font-size:20px; font-weight:600;"><?= $action === 'edit' ? 'Edit Banner' : 'Buat Banner Baru' ?></h3>
                <a href="index.php?page=admin_banners" class="btn btn-ghost btn-sm">Batal</a>
            </div>

            <form action="index.php?page=admin_banners&action=save" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">
                <input type="hidden" name="existing_image" value="<?= $editing['image_path'] ?? '' ?>">
                
                <div class="form-group mb-4">
                    <label style="display:block; margin-bottom:8px; font-weight:500;">Judul Banner</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Pemeliharaan Jaringan Rutin" value="<?= htmlspecialchars($editing['title'] ?? '') ?>" required>
                </div>

                <div class="form-group mb-4">
                    <label style="display:block; margin-bottom:8px; font-weight:500;">Isi Informasi</label>
                    <textarea name="content" class="form-control" rows="4" placeholder="Tulis rincian informasi di sini..." required style="resize:none;"><?= htmlspecialchars($editing['content'] ?? '') ?></textarea>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div class="form-group mb-4">
                        <label style="display:block; margin-bottom:8px; font-weight:500;">Target Portal</label>
                        <select name="target_role" class="form-control" required>
                            <option value="all" <?= ($editing['target_role'] ?? '') == 'all' ? 'selected' : '' ?>>Semua (Pelanggan, Mitra & Penagih)</option>
                            <option value="partner" <?= ($editing['target_role'] ?? '') == 'partner' ? 'selected' : '' ?>>Hanya Mitra</option>
                            <option value="customer" <?= ($editing['target_role'] ?? '') == 'customer' ? 'selected' : '' ?>>Hanya Pelanggan</option>
                            <option value="collector" <?= ($editing['target_role'] ?? '') == 'collector' ? 'selected' : '' ?>>Hanya Tukang Tagih</option>
                        </select>
                    </div>

                    <div class="form-group mb-4">
                        <label style="display:block; margin-bottom:8px; font-weight:500;">Status Aktif</label>
                        <div style="display:flex; align-items:center; height:45px;">
                            <label class="switch">
                                <input type="checkbox" name="is_active" <?= (!isset($editing) || ($editing['is_active'] ?? 0)) ? 'checked' : '' ?>>
                                <span class="slider round"></span>
                            </label>
                            <span style="margin-left:12px; font-size:14px; color:var(--text-secondary);">Tampilkan di dashboard</span>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label style="display:block; margin-bottom:8px; font-weight:500;">Gambar Banner (Opsional)</label>
                    <?php if(!empty($editing['image_path'])): ?>
                        <div style="margin-bottom:10px;">
                            <img src="<?= $editing['image_path'] ?>" style="width:100px; height:60px; object-fit:cover; border-radius:8px; border:1px solid var(--glass-border); cursor:pointer;" onclick="openImagePreview(this.src)" title="Perbesar">
                            <p style="font-size:11px; color:var(--text-secondary);">Gambar saat ini</p>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="image" class="form-control" accept="image/*">
                    <p style="font-size:12px; color:var(--text-secondary); margin-top:5px;">Rekomendasi ukuran: 1200x400 (aspek rasio lebar).</p>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:14px;">Simpan Banner</button>
            </form>
        </div>
    <?php else: ?>
        <div class="glass-panel" style="padding:0; border-radius:20px; overflow:hidden;">
            <table class="table" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th style="padding:15px 24px;">Informasi</th>
                        <th style="padding:15px 24px;">Target</th>
                        <th style="padding:15px 24px;">Status</th>
                        <th style="padding:15px 24px; text-align:right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($banners as $b): ?>
                    <tr>
                        <td style="padding:15px 24px;">
                            <div style="display:flex; gap:15px; align-items:center;">
                                <?php if($b['image_path']): ?>
                                    <img src="<?= $b['image_path'] ?>" style="width:60px; height:40px; object-fit:cover; border-radius:6px; cursor:pointer;" onclick="openImagePreview(this.src)" title="Perbesar">
                                <?php else: ?>
                                    <div style="width:60px; height:40px; background:rgba(255,255,255,0.05); border-radius:6px; display:flex; align-items:center; justify-content:center;">
                                        <i class="fas fa-image" style="opacity:0.3;"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($b['title']) ?></div>
                                    <div style="font-size:12px; color:var(--text-secondary); max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?= htmlspecialchars($b['content']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td style="padding:15px 24px;">
                            <?php if($b['target_role'] == 'all'): ?>
                                <span class="badge" style="background:rgba(59, 130, 246, 0.1); color:#3b82f6; border:1px solid rgba(59,130,246,0.2);">Semua</span>
                            <?php elseif($b['target_role'] == 'partner'): ?>
                                <span class="badge" style="background:rgba(168, 85, 247, 0.1); color:#a855f7; border:1px solid rgba(168,85,247,0.2);">Mitra</span>
                            <?php elseif($b['target_role'] == 'customer'): ?>
                                <span class="badge" style="background:rgba(52, 211, 153, 0.1); color:#10b981; border:1px solid rgba(52,211,153,0.2);">Pelanggan</span>
                            <?php elseif($b['target_role'] == 'collector'): ?>
                                <span class="badge" style="background:rgba(245, 158, 11, 0.1); color:#f59e0b; border:1px solid rgba(245,158,11,0.2);">Tukang Tagih</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:15px 24px;">
                            <a href="index.php?page=admin_banners&action=toggle&id=<?= $b['id'] ?>" style="text-decoration:none;">
                                <?php if($b['is_active']): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Aktif</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(148,163,184,0.1); color:#94a3b8; border:1px solid rgba(148,163,184,0.2);">Non-Aktif</span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td style="padding:15px 24px; text-align:right;">
                            <div style="display:flex; justify-content:flex-end; gap:8px;">
                                <a href="index.php?page=admin_banners&action=edit&id=<?= $b['id'] ?>" class="btn btn-sm btn-ghost" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="index.php?page=admin_banners&action=delete&id=<?= $b['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus banner ini?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($banners)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:50px; color:var(--text-secondary);">
                                <i class="fas fa-scroll" style="font-size:40px; opacity:0.1; display:block; margin-bottom:15px;"></i>
                                Belum ada banner yang dibuat.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
/* Modern Switch Toggle */
.switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255,255,255,0.1); transition: .4s; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; }
input:checked + .slider { background-color: var(--primary); }
input:checked + .slider:before { transform: translateX(20px); }
.slider.round { border-radius: 34px; }
.slider.round:before { border-radius: 50%; }

.mb-4 { margin-bottom: 1.5rem; }
</style>
