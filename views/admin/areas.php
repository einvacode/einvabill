<?php
$action = $_GET['action'] ?? 'list';

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->prepare("INSERT INTO areas (name, tenant_id) VALUES (?, ?)")->execute([$name, $tenant_id]);
    header("Location: index.php?page=admin_areas");
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->prepare("UPDATE areas SET name=? WHERE id=? AND tenant_id=?")->execute([$name, $id, $tenant_id]);
    header("Location: index.php?page=admin_areas");
    exit;
}

if ($action === 'delete') {
    $id = $_GET['id'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->prepare("DELETE FROM areas WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
    header("Location: index.php?page=admin_areas");
    exit;
}
?>

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Area penagihan</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Wilayah untuk mengelompokkan pelanggan dan penagih.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button onclick="document.getElementById('addAreaModal').style.display='flex'" class="ui-btn ui-btn-primary w-full sm:w-auto"><i class="fas fa-plus"></i> Tambah area</button>
    </div>
</div>

<section class="ui-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold sm:px-5">Nama area</th>
                    <th class="px-3 py-2.5 font-semibold">Jumlah pelanggan</th>
                    <th class="px-3 py-2.5 font-semibold">Tanggal dibuat</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $areas = $db->query("
                    SELECT a.*, (SELECT COUNT(*) FROM customers WHERE area = a.name AND tenant_id = $tenant_id) as total_customers
                    FROM areas a
                    WHERE a.tenant_id = $tenant_id
                    ORDER BY a.name ASC
                ")->fetchAll();
                foreach($areas as $a):
                ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 font-semibold sm:px-5"><?= htmlspecialchars($a['name']) ?></td>
                    <td class="px-3 py-3 tabular-nums"><?= $a['total_customers'] ?> pelanggan</td>
                    <td class="px-3 py-3 text-xs text-muted-foreground"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                    <td class="px-4 py-3 text-right sm:px-5">
                        <div class="inline-flex gap-1">
                            <button onclick="editArea(<?= $a['id'] ?>, '<?= addslashes($a['name']) ?>')" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit"><i class="fas fa-edit"></i><span class="hidden sm:inline">Edit</span></button>
                            <a data-method="post" href="index.php?page=admin_areas&action=delete&id=<?= $a['id'] ?>" onclick="return confirm('Hapus area ini?')" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus"><i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($areas) == 0): ?>
                    <tr class="border-t border-solid border-border"><td colspan="4" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada area. Klik "Tambah area" untuk memulai.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Add Area Modal -->
<div id="addAreaModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Tambah area baru</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('addAreaModal').style.display='none'" aria-label="Tutup"><i class="fas fa-times"></i></button>
        </div>
        <form action="index.php?page=admin_areas&action=add" method="POST">
<?= csrf_field() ?>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama area</span>
                <input type="text" name="name" class="form-control" placeholder="Contoh: RT 01 / Blok A" required>
            </label>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('addAreaModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan area</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Area Modal -->
<div id="editAreaModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Edit area</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('editAreaModal').style.display='none'" aria-label="Tutup"><i class="fas fa-times"></i></button>
        </div>
        <form action="index.php?page=admin_areas&action=update" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="editAreaId">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama area</span>
                <input type="text" name="name" id="editAreaName" class="form-control" required>
            </label>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('editAreaModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Update area</button>
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
