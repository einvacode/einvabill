<?php
$action = $_GET['action'] ?? 'list';

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $db->prepare("INSERT INTO areas (name) VALUES (?)")->execute([$name]);
    header("Location: index.php?page=admin_areas");
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $db->prepare("UPDATE areas SET name=? WHERE id=?")->execute([$name, $id]);
    header("Location: index.php?page=admin_areas");
    exit;
}

if ($action === 'delete') {
    $id = $_GET['id'];
    $db->prepare("DELETE FROM areas WHERE id = ?")->execute([$id]);
    header("Location: index.php?page=admin_areas");
    exit;
}
?>

<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
        <h3 style="font-size:20px;"><i class="fas fa-map-marker-alt text-primary"></i> Manajemen Area Penagihan</h3>
        <button onclick="document.getElementById('addAreaModal').style.display='flex'" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah Area</button>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nama Area</th>
                    <th>Jumlah Pelanggan</th>
                    <th>Tanggal Dibuat</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $areas = $db->query("
                    SELECT a.*, (SELECT COUNT(*) FROM customers WHERE area = a.name) as total_customers 
                    FROM areas a 
                    ORDER BY a.name ASC
                ")->fetchAll();
                foreach($areas as $a):
                ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($a['name']) ?></td>
                    <td>
                        <span class="badge badge-success"><?= $a['total_customers'] ?> Pelanggan</span>
                    </td>
                    <td style="font-size:12px; color:var(--text-secondary);"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                    <td>
                        <div style="display:flex; gap:8px;">
                            <button onclick="editArea(<?= $a['id'] ?>, '<?= addslashes($a['name']) ?>')" class="btn btn-sm btn-ghost" title="Edit"><i class="fas fa-edit"></i></button>
                            <a href="index.php?page=admin_areas&action=delete&id=<?= $a['id'] ?>" onclick="return confirm('Hapus area ini?')" class="btn btn-sm btn-danger" title="Hapus"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($areas) == 0): ?>
                    <tr><td colspan="4" style="text-align:center; padding:30px;">Belum ada area. Klik "Tambah Area" untuk memulai.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Area Modal -->
<div id="addAreaModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;">Tambah Area Baru</h3>
        <form action="index.php?page=admin_areas&action=add" method="POST">
            <div class="form-group">
                <label>Nama Area (Contoh: RT 01 / Blok A)</label>
                <input type="text" name="name" class="form-control" placeholder="Contoh: Blok A" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('addAreaModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-sm btn-primary">Simpan Area</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Area Modal -->
<div id="editAreaModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;">Edit Area</h3>
        <form action="index.php?page=admin_areas&action=update" method="POST">
            <input type="hidden" name="id" id="editAreaId">
            <div class="form-group">
                <label>Nama Area</label>
                <input type="text" name="name" id="editAreaName" class="form-control" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('editAreaModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-sm btn-primary">Update Area</button>
            </div>
        </form>
    </div>
</div>

<script>
function editArea(id, name) {
    document.getElementById('editAreaId').value = id;
    document.getElementById('editAreaName').value = name;
    document.getElementById('editAreaModal').style.display = 'flex';
}
</script>
