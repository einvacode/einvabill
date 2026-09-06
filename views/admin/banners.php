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
    
    // Handle Image Upload (Optional). Only a previously stored upload path
    // may be kept; anything else in existing_image is discarded.
    $image_path = $_POST['existing_image'] ?? '';
    if ($image_path !== '' && !preg_match('~^public/uploads/banners/[A-Za-z0-9_.-]+$~', $image_path)) {
        $image_path = '';
    }

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = save_uploaded_image($_FILES['image'], __DIR__ . '/../../public/uploads/banners', 'banner');
        if ($up['ok']) {
            $image_path = 'public/uploads/banners/' . $up['filename'];
        } else {
            $error = $up['error'];
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
    <?php if($action === 'add' || $action === 'edit'): ?>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="m-0 text-xl font-bold sm:text-2xl"><?= $action === 'edit' ? 'Edit banner' : 'Buat banner baru' ?></h2>
            </div>
            <a href="index.php?page=admin_banners" class="ui-btn ui-btn-outline">Batal</a>
        </div>

        <div class="ui-card mx-auto max-w-3xl p-5 sm:p-6">
            <form action="index.php?page=admin_banners&action=save" method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">
                <input type="hidden" name="existing_image" value="<?= $editing['image_path'] ?? '' ?>">

                <div class="grid gap-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Judul banner</span>
                        <input type="text" name="title" class="form-control" placeholder="Contoh: Pemeliharaan jaringan rutin" value="<?= htmlspecialchars($editing['title'] ?? '') ?>" required>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Isi informasi</span>
                        <textarea name="content" class="form-control resize-none" rows="4" placeholder="Tulis rincian informasi di sini..." required><?= htmlspecialchars($editing['content'] ?? '') ?></textarea>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Target portal</span>
                            <select name="target_role" class="form-control" required>
                                <option value="all" <?= ($editing['target_role'] ?? '') == 'all' ? 'selected' : '' ?>>Semua (Pelanggan, Mitra & Penagih)</option>
                                <option value="partner" <?= ($editing['target_role'] ?? '') == 'partner' ? 'selected' : '' ?>>Hanya Mitra</option>
                                <option value="customer" <?= ($editing['target_role'] ?? '') == 'customer' ? 'selected' : '' ?>>Hanya Pelanggan</option>
                                <option value="collector" <?= ($editing['target_role'] ?? '') == 'collector' ? 'selected' : '' ?>>Hanya Tukang Tagih</option>
                            </select>
                        </label>

                        <div class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Status aktif</span>
                            <div class="flex h-10 items-center">
                                <label class="switch">
                                    <input type="checkbox" name="is_active" <?= (!isset($editing) || ($editing['is_active'] ?? 0)) ? 'checked' : '' ?>>
                                    <span class="slider round"></span>
                                </label>
                                <span class="ml-3 text-sm text-muted-foreground">Tampilkan di dashboard</span>
                            </div>
                        </div>
                    </div>

                    <div class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Gambar banner (opsional)</span>
                        <?php if(!empty($editing['image_path'])): ?>
                            <div class="mb-2">
                                <img src="<?= $editing['image_path'] ?>" class="h-[60px] w-[100px] cursor-pointer rounded-md border border-solid border-border object-cover" onclick="openImagePreview(this.src)" title="Perbesar">
                                <p class="m-0 mt-1 text-[11px] text-muted-foreground">Gambar saat ini</p>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <p class="m-0 mt-1 text-xs text-muted-foreground">Rekomendasi ukuran: 1200x400 (aspek rasio lebar).</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto">Simpan banner</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="m-0 text-xl font-bold sm:text-2xl">Banner informasi</h2>
            </div>
            <a href="index.php?page=admin_banners&action=add" class="ui-btn ui-btn-primary w-full sm:w-auto">
                <i class="fas fa-plus"></i> Tambah banner baru
            </a>
        </div>

        <section class="ui-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold">Informasi</th>
                            <th class="px-4 py-2.5 font-semibold">Target</th>
                            <th class="px-4 py-2.5 font-semibold">Status</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($banners as $b): ?>
                        <tr class="border-t border-solid border-border">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <?php if($b['image_path']): ?>
                                        <img src="<?= $b['image_path'] ?>" class="h-10 w-[60px] shrink-0 cursor-pointer rounded-md border border-solid border-border object-cover" onclick="openImagePreview(this.src)" title="Perbesar">
                                    <?php else: ?>
                                        <div class="flex h-10 w-[60px] shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="min-w-0">
                                        <div class="font-semibold text-foreground"><?= htmlspecialchars($b['title']) ?></div>
                                        <div class="max-w-[300px] truncate text-xs text-muted-foreground">
                                            <?= htmlspecialchars($b['content']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <?php if($b['target_role'] == 'all'): ?>
                                    <span class="ui-badge ui-badge-muted">Semua</span>
                                <?php elseif($b['target_role'] == 'partner'): ?>
                                    <span class="ui-badge ui-badge-muted">Mitra</span>
                                <?php elseif($b['target_role'] == 'customer'): ?>
                                    <span class="ui-badge ui-badge-muted">Pelanggan</span>
                                <?php elseif($b['target_role'] == 'collector'): ?>
                                    <span class="ui-badge ui-badge-muted">Tukang tagih</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <a data-method="post" href="index.php?page=admin_banners&action=toggle&id=<?= $b['id'] ?>" class="no-underline" title="Ubah status">
                                    <?php if($b['is_active']): ?>
                                        <span class="ui-badge ui-badge-signal">Aktif</span>
                                    <?php else: ?>
                                        <span class="ui-badge ui-badge-muted">Nonaktif</span>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="compact-action-icons inline-flex gap-1">
                                    <a href="index.php?page=admin_banners&action=edit&id=<?= $b['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit">
                                        <i class="fas fa-edit"></i><span class="hidden sm:inline">Edit</span>
                                    </a>
                                    <a data-method="post" href="index.php?page=admin_banners&action=delete&id=<?= $b['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" onclick="return confirm('Hapus banner ini?')">
                                        <i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($banners)): ?>
                            <tr class="border-t border-solid border-border">
                                <td colspan="4" class="px-5 py-10 text-center text-sm text-muted-foreground">
                                    Belum ada banner yang dibuat.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<style>
/* Switch toggle */
.switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #D9E0E2; transition: background-color .15s ease; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: transform .15s ease; }
input:checked + .slider { background-color: var(--primary); }
input:checked + .slider:before { transform: translateX(20px); }
.slider.round { border-radius: 34px; }
.slider.round:before { border-radius: 50%; }
</style>
