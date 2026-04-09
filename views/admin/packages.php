<?php
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $fee = $_POST['fee'];
    $db->prepare("INSERT INTO packages (name, fee, created_by) VALUES (?, ?, ?)")->execute([$name, $fee, $u_id]);
    header("Location: index.php?page=admin_packages");
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $fee = $_POST['fee'];
    
    // Ownership Check & Get Old Data
    $old_pkg = $db->query("SELECT name, created_by FROM packages WHERE id = $id")->fetch();
    $is_owner = ($u_role === 'admin') ? ($old_pkg['created_by'] == $u_id || $old_pkg['created_by'] == 0 || $old_pkg['created_by'] === NULL) : ($old_pkg['created_by'] == $u_id);
    
    if ($is_owner && $old_pkg) {
        $old_name = $old_pkg['name'];
        // Update package
        $db->prepare("UPDATE packages SET name=?, fee=? WHERE id=?")->execute([$name, $fee, $id]);
        
        // SYNC CUSTOMERS: Update all customers using this package name
        $db->prepare("UPDATE customers SET package_name = ?, monthly_fee = ? WHERE package_name = ? AND (created_by = ? OR created_by = 0 OR created_by IS NULL)")
           ->execute([$name, $fee, $old_name, $u_id]);
    }
    header("Location: index.php?page=admin_packages&msg=updated_sync");
    exit;
}

if ($action === 'delete') {
    $id = $_GET['id'];
    
    // Ownership Check
    $check = $db->query("SELECT created_by FROM packages WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? ($check == $u_id || $check == 0 || $check === NULL) : ($check == $u_id);
    if ($is_owner) {
        $db->prepare("DELETE FROM packages WHERE id = ?")->execute([$id]);
    }
    header("Location: index.php?page=admin_packages");
    exit;
}

if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    if (!empty($ids)) {
        $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Ownership Check & Filter
        $scope_cond = ($u_role === 'admin') ? "(created_by = ? OR created_by = 0 OR created_by IS NULL)" : "created_by = ?";
        $stmt = $db->prepare("DELETE FROM packages WHERE id IN ($id_placeholders) AND $scope_cond");
        $params = array_merge($ids, [$u_id]);
        $stmt->execute($params);
        
        header("Location: index.php?page=admin_packages&msg=bulk_deleted");
        exit;
    }
    header("Location: index.php?page=admin_packages");
    exit;
}

if ($action === 'sync_all') {
    // Ownership Check
    $packages = $db->query("SELECT name, fee FROM packages WHERE created_by = $u_id OR created_by = 0 OR created_by IS NULL")->fetchAll();
    $count = 0;
    foreach($packages as $p) {
        $stmt = $db->prepare("UPDATE customers SET monthly_fee = ? WHERE package_name = ? AND (created_by = ? OR created_by = 0 OR created_by IS NULL)");
        $stmt->execute([$p['fee'], $p['name'], $u_id]);
        $count += $stmt->rowCount();
    }
    header("Location: index.php?page=admin_packages&msg=all_synced&count=$count");
    exit;
}
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated_sync'): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--success); padding:15px; background:rgba(16,185,129,0.1); color:var(--success); display:flex; align-items:center; gap:10px;">
    <i class="fas fa-check-circle"></i> Berhasil! Paket telah diperbarui dan seluruh tagihan pelanggan terkait telah disesuaikan otomatis.
</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'all_synced'): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--info); padding:15px; background:rgba(59,130,246,0.1); color:var(--info); display:flex; align-items:center; gap:10px;">
    <i class="fas fa-info-circle"></i> Berhasil menyelaraskan harga untuk <strong><?= intval($_GET['count']) ?></strong> pelanggan sesuai paket mereka saat ini.
</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_deleted'): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--danger); padding:15px; background:rgba(239,68,68,0.1); color:var(--danger); display:flex; align-items:center; gap:10px;">
    <i class="fas fa-trash-alt"></i> Berhasil menghapus paket yang dipilih.
</div>
<?php endif; ?>

<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
        <h3 style="font-size:20px;"><i class="fas fa-box text-primary"></i> Manajemen Paket Internet</h3>
        <div style="display:flex; gap:10px;">
            <button id="btnBulkDelete" onclick="submitBulkDelete()" class="btn btn-danger btn-sm" style="display:none;"><i class="fas fa-trash"></i> Hapus Masal (<span id="selectedCount">0</span>)</button>
            <a href="index.php?page=admin_packages&action=sync_all" class="btn btn-ghost btn-sm" onclick="return confirm('Sinkronkan SEMUA harga pelanggan dengan harga paket terbaru?')" title="Selaraskan Semua Harga"><i class="fas fa-sync"></i> Sync Semua</a>
            <button onclick="document.getElementById('addPackageModal').style.display='flex'" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah Paket</button>
        </div>
    </div>

    <div class="table-container">
        <form id="bulkDeleteForm" action="index.php?page=admin_packages&action=bulk_delete" method="POST">
        <table>
            <thead>
                <tr>
                    <th style="width:40px; text-align:center;"><input type="checkbox" id="checkAll" style="transform:scale(1.2); cursor:pointer;"></th>
                    <th>Nama Paket</th>
                    <th>Biaya Bulanan</th>
                    <th>Tanggal Dibuat</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $scope_where = ($u_role === 'admin') ? "WHERE created_by = $u_id OR created_by = 0 OR created_by IS NULL" : "WHERE created_by = $u_id";
                $packages = $db->query("SELECT * FROM packages $scope_where ORDER BY fee ASC")->fetchAll();
                foreach($packages as $p):
                ?>
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" name="ids[]" value="<?= $p['id'] ?>" class="package-checkbox" style="transform:scale(1.2); cursor:pointer;">
                    </td>
                    <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
                    <td style="font-weight:bold; color:var(--primary);">Rp <?= number_format($p['fee'], 0, ',', '.') ?></td>
                    <td style="font-size:12px; color:var(--text-secondary);"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                    <td>
                        <div style="display:flex; gap:8px;">
                            <button type="button" onclick="editPackage(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', <?= $p['fee'] ?>)" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></button>
                            <a href="index.php?page=admin_packages&action=delete&id=<?= $p['id'] ?>" onclick="return confirm('Hapus paket ini?')" class="btn btn-sm btn-danger" title="Hapus"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($packages) == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding:30px;">Belum ada paket. Klik "Tambah Paket" untuk memulai.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </form>
    </div>
</div>

<!-- Add Package Modal -->
<div id="addPackageModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;">Tambah Paket Baru</h3>
        <form action="index.php?page=admin_packages&action=add" method="POST">
            <div class="form-group">
                <label>Nama Paket (Contoh: 10 Mbps)</label>
                <input type="text" name="name" class="form-control" placeholder="Contoh: 10 Mbps" required>
            </div>
            <div class="form-group">
                <label>Biaya Bulanan (Rp)</label>
                <input type="number" name="fee" class="form-control" placeholder="Contoh: 150000" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('addPackageModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-sm btn-primary">Simpan Paket</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Package Modal -->
<div id="editPackageModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;">Edit Paket</h3>
        <form action="index.php?page=admin_packages&action=update" method="POST">
            <input type="hidden" name="id" id="editPkgId">
            <div class="form-group">
                <label>Nama Paket</label>
                <input type="text" name="name" id="editPkgName" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Biaya Bulanan (Rp)</label>
                <input type="number" name="fee" id="editPkgFee" class="form-control" required>
            </div>
            <div style="font-size:11px; color:var(--danger); background:rgba(239, 68, 68, 0.1); padding:10px; border-radius:8px; margin-top:15px; border-left:3px solid var(--danger);">
                <i class="fas fa-exclamation-triangle"></i> <strong>PENTING:</strong> Mengubah paket ini akan otomatis memperbarui biaya bulanan seluruh pelanggan yang terdaftar menggunakan paket ini.
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('editPackageModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-sm btn-primary">Update Paket</button>
            </div>
        </form>
    </div>
</div>

<script>
function editPackage(id, name, fee) {
    document.getElementById('editPkgId').value = id;
    document.getElementById('editPkgName').value = name;
    document.getElementById('editPkgFee').value = fee;
    document.getElementById('editPkgFee').value = fee;
    document.getElementById('editPackageModal').style.display = 'flex';
}

const checkAll = document.getElementById('checkAll');
const checkboxes = document.querySelectorAll('.package-checkbox');
const btnBulkDelete = document.getElementById('btnBulkDelete');
const selectedCount = document.getElementById('selectedCount');

checkAll?.addEventListener('change', function() {
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateBulkButton();
});

checkboxes.forEach(cb => {
    cb.addEventListener('change', updateBulkButton);
});

function updateBulkButton() {
    const checkedCount = document.querySelectorAll('.package-checkbox:checked').length;
    selectedCount.textContent = checkedCount;
    btnBulkDelete.style.display = checkedCount > 0 ? 'inline-flex' : 'none';
}

function submitBulkDelete() {
    if (confirm('Hapus semua paket yang dipilih?')) {
        document.getElementById('bulkDeleteForm').submit();
    }
}
</script>
