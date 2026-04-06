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
    
    // Ownership Check
    $check = $db->query("SELECT created_by FROM packages WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? ($check == $u_id || $check == 0 || $check === NULL) : ($check == $u_id);
    if ($is_owner) {
        $db->prepare("UPDATE packages SET name=?, fee=? WHERE id=?")->execute([$name, $fee, $id]);
    }
    header("Location: index.php?page=admin_packages");
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
?>

<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
        <h3 style="font-size:20px;"><i class="fas fa-box text-primary"></i> Manajemen Paket Internet</h3>
        <button onclick="document.getElementById('addPackageModal').style.display='flex'" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah Paket</button>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
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
                    <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
                    <td style="font-weight:bold; color:var(--primary);">Rp <?= number_format($p['fee'], 0, ',', '.') ?></td>
                    <td style="font-size:12px; color:var(--text-secondary);"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                    <td>
                        <div style="display:flex; gap:8px;">
                            <button onclick="editPackage(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', <?= $p['fee'] ?>)" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></button>
                            <a href="index.php?page=admin_packages&action=delete&id=<?= $p['id'] ?>" onclick="return confirm('Hapus paket ini?')" class="btn btn-sm btn-danger" title="Hapus"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($packages) == 0): ?>
                    <tr><td colspan="4" style="text-align:center; padding:30px;">Belum ada paket. Klik "Tambah Paket" untuk memulai.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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
    document.getElementById('editPackageModal').style.display = 'flex';
}
</script>
