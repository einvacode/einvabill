<?php
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';

// Handle ADD Expense
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date = $_POST['date'];
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("INSERT INTO expenses (category, amount, description, date, created_by, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$category, $amount, $description, $date, $u_id, $tenant_id]);
    header("Location: index.php?page=admin_expenses&msg=added");
    exit;
}

// Handle UPDATE Expense
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date = $_POST['date'];
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Ownership Check: Admin can manage all in tenant, others only their own
    $check = $db->query("SELECT tenant_id, created_by FROM expenses WHERE id = $id")->fetch();
    $is_owner = ($u_role === 'admin') ? ($check['tenant_id'] == $tenant_id) : ($check['created_by'] == $u_id);
    
    if ($is_owner) {
        $stmt = $db->prepare("UPDATE expenses SET category=?, amount=?, description=?, date=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$category, $amount, $description, $date, $id, $tenant_id]);
        header("Location: index.php?page=admin_expenses&msg=updated");
    } else {
        header("Location: index.php?page=admin_expenses&msg=forbidden");
    }
    exit;
}

// Handle DELETE Expense
if ($action === 'delete') {
    $id = intval($_GET['id'] ?? 0);
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Ownership Check
    $check = $db->query("SELECT tenant_id, created_by FROM expenses WHERE id = $id")->fetch();
    $is_owner = ($u_role === 'admin') ? ($check['tenant_id'] == $tenant_id) : ($check['created_by'] == $u_id);
    
    if ($is_owner) {
        $db->prepare("DELETE FROM expenses WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        header("Location: index.php?page=admin_expenses&msg=deleted");
    } else {
        header("Location: index.php?page=admin_expenses&msg=forbidden");
    }
    exit;
}

// Scoping Logic
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$scope_where = ($u_role === 'admin') ? "WHERE e.tenant_id = $tenant_id" : "WHERE e.tenant_id = $tenant_id AND e.created_by = $u_id";

// Fetch Stats for current month
$start_month = date('Y-m-01');
$end_month = date('Y-m-t');
$total_expense_month = $db->query("SELECT SUM(amount) FROM expenses e $scope_where AND e.date BETWEEN '$start_month' AND '$end_month'")->fetchColumn() ?: 0;
?>

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Manajemen pengeluaran</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Catatan biaya operasional, belanja barang, dan insentif.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" onclick="document.getElementById('addExpenseModal').style.display='flex'" class="ui-btn ui-btn-primary w-full sm:w-auto"><i class="fas fa-plus"></i> Tambah pengeluaran</button>
    </div>
</div>

<?php if(isset($_GET['msg'])): ?>
    <div class="ui-card mb-5 p-4 text-sm font-semibold text-signal">
        <?php
            if($_GET['msg'] == 'added') echo "Pengeluaran berhasil ditambahkan.";
            if($_GET['msg'] == 'updated') echo "Data pengeluaran diperbarui.";
            if($_GET['msg'] == 'deleted') echo "Catatan pengeluaran dihapus.";
        ?>
    </div>
<?php endif; ?>

<!-- Stats -->
<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Pengeluaran bulan ini</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums text-danger sm:text-2xl">Rp <?= number_format($total_expense_month, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground"><?= date('d/m/Y', strtotime($start_month)) ?> - <?= date('d/m/Y', strtotime($end_month)) ?></div>
    </div>
</div>

<!-- Expense list -->
<section class="ui-card overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Daftar pengeluaran</h3>
            <p class="m-0 text-xs text-muted-foreground">Seratus catatan terakhir</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold sm:px-5">Tanggal</th>
                    <th class="px-3 py-2.5 font-semibold">Kategori</th>
                    <th class="px-3 py-2.5 font-semibold">Keterangan</th>
                    <th class="px-3 py-2.5 text-right font-semibold">Jumlah</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $expenses = $db->query("
                    SELECT e.*, u.name as creator_name, u.role as creator_role
                    FROM expenses e
                    LEFT JOIN users u ON e.created_by = u.id
                    $scope_where
                    ORDER BY e.date DESC, e.id DESC
                    LIMIT 100
                ")->fetchAll();

                foreach($expenses as $e):
                    $catColor = '#3b82f6';
                    if($e['category'] == 'Operasional') $catColor = '#10b981';
                    if($e['category'] == 'Belanja Barang') $catColor = '#f59e0b';
                    if($e['category'] == 'Insentif') $catColor = '#8b5cf6';

                    $roleLabel = ($e['creator_role'] === 'admin') ? 'Admin' : 'Petugas';
                    $roleColor = ($e['creator_role'] === 'admin') ? 'var(--primary)' : 'var(--text-secondary)';
                ?>
                <tr class="border-t border-solid border-border">
                    <td class="whitespace-nowrap px-4 py-3 sm:px-5">
                        <div class="font-semibold tabular-nums"><?= date('d/m/Y', strtotime($e['date'])) ?></div>
                        <div class="text-xs text-muted-foreground">Oleh: <?= htmlspecialchars($e['creator_name'] ?: 'System') ?></div>
                    </td>
                    <td class="px-3 py-3">
                        <span class="ui-badge ui-badge-muted"><?= htmlspecialchars($e['category']) ?></span>
                    </td>
                    <td class="px-3 py-3 text-muted-foreground"><?= htmlspecialchars($e['description'] ?: '-') ?></td>
                    <td class="whitespace-nowrap px-3 py-3 text-right font-bold tabular-nums">Rp <?= number_format($e['amount'], 0, ',', '.') ?></td>
                    <td class="px-4 py-3 sm:px-5">
                        <div class="flex justify-end gap-1.5">
                            <button type="button" onclick="editExpense(<?= htmlspecialchars(json_encode($e)) ?>)" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit"><i class="fas fa-edit"></i><span class="hidden sm:inline">Edit</span></button>
                            <a data-method="post" href="index.php?page=admin_expenses&action=delete&id=<?= $e['id'] ?>" onclick="return confirm('Hapus catatan ini?')" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus"><i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($expenses) == 0): ?>
                    <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data pengeluaran.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Add Modal -->
<div id="addExpenseModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-lg p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Tambah pengeluaran</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('addExpenseModal').style.display='none'" aria-label="Tutup">&#x2715;</button>
        </div>
        <form action="index.php?page=admin_expenses&action=add" method="POST">
<?= csrf_field() ?>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal</span>
                    <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Jumlah (Rp)</span>
                    <input type="number" name="amount" class="form-control" placeholder="0" required>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Kategori</span>
                    <select name="category" class="form-control" required>
                        <option value="Operasional">Operasional (Listrik, Sewa, dll)</option>
                        <option value="Belanja Barang">Belanja Barang (Alat Teknik, Kabel, dll)</option>
                        <option value="Insentif">Insentif / Gaji</option>
                        <option value="Lain-lain">Lain-lain</option>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan</span>
                    <textarea name="description" class="form-control" rows="3" placeholder="Detail pengeluaran..."></textarea>
                </label>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('addExpenseModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan data</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editExpenseModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-lg p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Edit pengeluaran</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('editExpenseModal').style.display='none'" aria-label="Tutup">&#x2715;</button>
        </div>
        <form action="index.php?page=admin_expenses&action=update" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="editId">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal</span>
                    <input type="date" name="date" id="editDate" class="form-control" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Jumlah (Rp)</span>
                    <input type="number" name="amount" id="editAmount" class="form-control" required>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Kategori</span>
                    <select name="category" id="editCategory" class="form-control" required>
                        <option value="Operasional">Operasional</option>
                        <option value="Belanja Barang">Belanja Barang</option>
                        <option value="Insentif">Insentif</option>
                        <option value="Lain-lain">Lain-lain</option>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan</span>
                    <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                </label>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('editExpenseModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Update data</button>
            </div>
        </form>
    </div>
</div>

<script>
function editExpense(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editDate').value = data.date;
    document.getElementById('editCategory').value = data.category;
    document.getElementById('editAmount').value = data.amount;
    document.getElementById('editDescription').value = data.description;
    document.getElementById('editExpenseModal').style.display = 'flex';
}
</script>
