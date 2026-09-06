<?php
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $fee = $_POST['fee'];
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $db->prepare("INSERT INTO packages (name, fee, created_by, tenant_id) VALUES (?, ?, ?, ?)")->execute([$name, $fee, $u_id, $tenant_id]);
    header("Location: index.php?page=admin_packages");
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $name = $_POST['name'];
    $fee = $_POST['fee'];
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Ownership Check & Get Old Data
    $old_pkg = $db->query("SELECT name, created_by, tenant_id FROM packages WHERE id = $id AND tenant_id = $tenant_id")->fetch();
    $is_owner = ($u_role === 'admin') ? ($old_pkg['tenant_id'] == $tenant_id) : ($old_pkg['created_by'] == $u_id);
    
    if ($is_owner && $old_pkg) {
        $old_name = $old_pkg['name'];
        // Update package
        $db->prepare("UPDATE packages SET name=?, fee=? WHERE id=? AND tenant_id=?")->execute([$name, $fee, $id, $tenant_id]);
        
        // SYNC CUSTOMERS: Update all customers using this package name
        $db->prepare("UPDATE customers SET package_name = ?, monthly_fee = ? WHERE package_name = ? AND tenant_id = ?")
           ->execute([$name, $fee, $old_name, $tenant_id]);
    }
    header("Location: index.php?page=admin_packages&msg=updated_sync");
    exit;
}

if ($action === 'delete') {
    $id = intval($_GET['id'] ?? 0);
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Ownership Check
    $check = $db->query("SELECT tenant_id FROM packages WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? ($check == $tenant_id) : (/* restricted */ false);
    if ($is_owner) {
        $db->prepare("DELETE FROM packages WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
    }
    header("Location: index.php?page=admin_packages");
    exit;
}

if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    if (!empty($ids)) {
        $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $scope_cond = ($u_role === 'admin') ? "(created_by = ? OR created_by = 0 OR created_by IS NULL) AND tenant_id = ?" : "created_by = ? AND tenant_id = ?";
        $stmt = $db->prepare("DELETE FROM packages WHERE id IN ($id_placeholders) AND $scope_cond");
        $params = array_merge($ids, [$u_id, $tenant_id]);
        $stmt->execute($params);
        
        header("Location: index.php?page=admin_packages&msg=bulk_deleted");
        exit;
    }
    header("Location: index.php?page=admin_packages");
    exit;
}

if ($action === 'sync_all') {
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Ownership Check
    $packages = $db->query("SELECT name, fee FROM packages WHERE tenant_id = $tenant_id")->fetchAll();
    $count = 0;
    foreach($packages as $p) {
        $stmt = $db->prepare("UPDATE customers SET monthly_fee = ? WHERE package_name = ? AND tenant_id = ?");
        $stmt->execute([$p['fee'], $p['name'], $tenant_id]);
        $count += $stmt->rowCount();
    }
    header("Location: index.php?page=admin_packages&msg=all_synced&count=$count");
    exit;
}
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated_sync'): ?>
<div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> Paket diperbarui dan tagihan pelanggan terkait disesuaikan otomatis.</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'all_synced'): ?>
<div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Selesai.</span> Harga <strong><?= intval($_GET['count']) ?></strong> pelanggan diselaraskan dengan paket mereka saat ini.</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_deleted'): ?>
<div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Terhapus.</span> Paket yang dipilih berhasil dihapus.</div>
<?php endif; ?>

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Paket layanan</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Daftar paket dan biaya bulanan yang dipakai pelanggan.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button id="btnBulkDelete" onclick="submitBulkDelete()" class="ui-btn ui-btn-outline text-danger" style="display:none;"><i class="fas fa-trash"></i> Hapus terpilih (<span id="selectedCount">0</span>)</button>
        <a href="index.php?page=admin_packages&action=sync_all" class="ui-btn ui-btn-outline" onclick="return confirm('Sinkronkan SEMUA harga pelanggan dengan harga paket terbaru?')" title="Selaraskan semua harga"><i class="fas fa-sync"></i> Sinkronkan harga</a>
        <button onclick="document.getElementById('addPackageModal').style.display='flex'" class="ui-btn ui-btn-primary"><i class="fas fa-plus"></i> Tambah paket</button>
    </div>
</div>

<section class="ui-card overflow-hidden">
    <form id="bulkDeleteForm" action="index.php?page=admin_packages&action=bulk_delete" method="POST">
<?= csrf_field() ?>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="w-10 px-4 py-2.5 text-center font-semibold"><input type="checkbox" id="checkAll" class="h-4 w-4 cursor-pointer align-middle"></th>
                    <th class="px-3 py-2.5 font-semibold">Nama paket</th>
                    <th class="px-3 py-2.5 font-semibold">Biaya bulanan</th>
                    <th class="px-3 py-2.5 font-semibold">Tanggal dibuat</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $scope_where = "WHERE tenant_id = $tenant_id";
                $packages = $db->query("SELECT * FROM packages $scope_where ORDER BY fee ASC")->fetchAll();
                foreach($packages as $p):
                ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 text-center">
                        <input type="checkbox" name="ids[]" value="<?= $p['id'] ?>" class="package-checkbox h-4 w-4 cursor-pointer align-middle">
                    </td>
                    <td class="px-3 py-3 font-semibold"><?= htmlspecialchars($p['name']) ?></td>
                    <td class="px-3 py-3 font-semibold tabular-nums">Rp <?= number_format($p['fee'], 0, ',', '.') ?></td>
                    <td class="px-3 py-3 text-xs text-muted-foreground"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                    <td class="px-4 py-3 text-right sm:px-5">
                        <div class="inline-flex gap-1">
                            <button type="button" onclick="editPackage(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', <?= $p['fee'] ?>)" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit"><i class="fas fa-edit"></i><span class="hidden sm:inline">Edit</span></button>
                            <a data-method="post" href="index.php?page=admin_packages&action=delete&id=<?= $p['id'] ?>" onclick="return confirm('Hapus paket ini?')" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus"><i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($packages) == 0): ?>
                    <tr class="border-t border-solid border-border"><td colspan="5" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada paket. Klik "Tambah paket" untuk memulai.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </form>
</section>

<!-- Add Package Modal -->
<div id="addPackageModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Tambah paket baru</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('addPackageModal').style.display='none'" aria-label="Tutup"><i class="fas fa-times"></i></button>
        </div>
        <form action="index.php?page=admin_packages&action=add" method="POST">
<?= csrf_field() ?>
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama paket</span>
                    <input type="text" name="name" class="form-control" placeholder="Contoh: 10 Mbps" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Biaya bulanan (Rp)</span>
                    <input type="number" name="fee" class="form-control" placeholder="Contoh: 150000" required>
                </label>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('addPackageModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan paket</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Package Modal -->
<div id="editPackageModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Edit paket</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('editPackageModal').style.display='none'" aria-label="Tutup"><i class="fas fa-times"></i></button>
        </div>
        <form action="index.php?page=admin_packages&action=update" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="editPkgId">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama paket</span>
                    <input type="text" name="name" id="editPkgName" class="form-control" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Biaya bulanan (Rp)</span>
                    <input type="number" name="fee" id="editPkgFee" class="form-control" required>
                </label>
            </div>
            <div class="mt-4 rounded-md border border-solid border-danger/40 bg-danger-soft p-3 text-xs text-danger">
                <strong>Penting:</strong> mengubah paket ini akan otomatis memperbarui biaya bulanan seluruh pelanggan yang memakai paket ini.
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('editPackageModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Update paket</button>
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
