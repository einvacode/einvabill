<?php
$action = $_GET['action'] ?? 'list';

// Handle ADD Expense
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date = $_POST['date'];
    
    $stmt = $db->prepare("INSERT INTO expenses (category, amount, description, date) VALUES (?, ?, ?, ?)");
    $stmt->execute([$category, $amount, $description, $date]);
    header("Location: index.php?page=admin_expenses&msg=added");
    exit;
}

// Handle UPDATE Expense
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date = $_POST['date'];
    
    $stmt = $db->prepare("UPDATE expenses SET category=?, amount=?, description=?, date=? WHERE id=?");
    $stmt->execute([$category, $amount, $description, $date, $id]);
    header("Location: index.php?page=admin_expenses&msg=updated");
    exit;
}

// Handle DELETE Expense
if ($action === 'delete') {
    $id = $_GET['id'];
    $db->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
    header("Location: index.php?page=admin_expenses&msg=deleted");
    exit;
}

// Fetch Stats for current month
$start_month = date('Y-m-01');
$end_month = date('Y-m-t');
$total_expense_month = $db->query("SELECT SUM(amount) FROM expenses WHERE date BETWEEN '$start_month' AND '$end_month'")->fetchColumn() ?: 0;
?>

<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center; flex-wrap:wrap; gap:15px;">
        <h3 style="font-size:20px;"><i class="fas fa-wallet text-primary"></i> Manajemen Pengeluaran</h3>
        <div style="display:flex; gap:10px;">
            <div style="background:rgba(244, 63, 94, 0.1); padding:8px 15px; border-radius:10px; border:1px solid rgba(244, 63, 94, 0.2); display:flex; align-items:center; gap:10px;">
                <span style="font-size:12px; color:var(--text-secondary);">Bulan Ini:</span>
                <span style="font-weight:700; color:#f43f5e;">Rp <?= number_format($total_expense_month, 0, ',', '.') ?></span>
            </div>
            <button onclick="document.getElementById('addExpenseModal').style.display='flex'" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah Pengeluaran</button>
        </div>
    </div>

    <?php if(isset($_GET['msg'])): ?>
        <div class="glass-panel" style="padding:12px 20px; margin-bottom:20px; background:rgba(16, 185, 129, 0.1); border-left:4px solid var(--success); color:var(--success); font-weight:600; font-size:14px;">
            <i class="fas fa-check-circle"></i> 
            <?php 
                if($_GET['msg'] == 'added') echo "Pengeluaran berhasil ditambahkan.";
                if($_GET['msg'] == 'updated') echo "Data pengeluaran diperbarui.";
                if($_GET['msg'] == 'deleted') echo "Catatan pengeluaran dihapus.";
            ?>
        </div>
    <?php endif; ?>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Kategori</th>
                    <th>Keterangan</th>
                    <th>Jumlah</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $expenses = $db->query("SELECT * FROM expenses ORDER BY date DESC, id DESC LIMIT 100")->fetchAll();
                foreach($expenses as $e):
                    $catColor = '#3b82f6';
                    if($e['category'] == 'Operasional') $catColor = '#10b981';
                    if($e['category'] == 'Belanja Barang') $catColor = '#f59e0b';
                    if($e['category'] == 'Insentif') $catColor = '#8b5cf6';
                ?>
                <tr>
                    <td style="font-size:13px;"><?= date('d/m/Y', strtotime($e['date'])) ?></td>
                    <td>
                        <span class="badge" style="background:<?= $catColor ?>22; color:<?= $catColor ?>; border:1px solid <?= $catColor ?>44;">
                            <?= htmlspecialchars($e['category']) ?>
                        </span>
                    </td>
                    <td style="font-size:13px; color:var(--text-secondary);"><?= htmlspecialchars($e['description'] ?: '-') ?></td>
                    <td style="font-weight:700; color:#f43f5e;">Rp <?= number_format($e['amount'], 0, ',', '.') ?></td>
                    <td>
                        <div style="display:flex; gap:8px;">
                            <button onclick="editExpense(<?= htmlspecialchars(json_encode($e)) ?>)" class="btn btn-sm btn-ghost" title="Edit"><i class="fas fa-edit"></i></button>
                            <a href="index.php?page=admin_expenses&action=delete&id=<?= $e['id'] ?>" onclick="return confirm('Hapus catatan ini?')" class="btn btn-sm btn-danger" title="Hapus"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($expenses) == 0): ?>
                    <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--text-secondary);">Belum ada data pengeluaran.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div id="addExpenseModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:450px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;"><i class="fas fa-plus-circle text-primary"></i> Tambah Pengeluaran</h3>
        <form action="index.php?page=admin_expenses&action=add" method="POST">
            <div class="form-group">
                <label>Tanggal</label>
                <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Kategori</label>
                <select name="category" class="form-control" required>
                    <option value="Operasional">Operasional (Listrik, Sewa, dll)</option>
                    <option value="Belanja Barang">Belanja Barang (Alat Teknik, Kabel, dll)</option>
                    <option value="Insentif">Insentif / Gaji</option>
                    <option value="Lain-lain">Lain-lain</option>
                </select>
            </div>
            <div class="form-group">
                <label>Jumlah (Rp)</label>
                <input type="number" name="amount" class="form-control" placeholder="0" required>
            </div>
            <div class="form-group">
                <label>Keterangan</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Detail pengeluaran..."></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addExpenseModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 25px;">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editExpenseModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:450px; padding:24px; margin:20px;">
        <h3 style="margin-bottom:20px;"><i class="fas fa-edit text-warning"></i> Edit Pengeluaran</h3>
        <form action="index.php?page=admin_expenses&action=update" method="POST">
            <input type="hidden" name="id" id="editId">
            <div class="form-group">
                <label>Tanggal</label>
                <input type="date" name="date" id="editDate" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Kategori</label>
                <select name="category" id="editCategory" class="form-control" required>
                    <option value="Operasional">Operasional</option>
                    <option value="Belanja Barang">Belanja Barang</option>
                    <option value="Insentif">Insentif</option>
                    <option value="Lain-lain">Lain-lain</option>
                </select>
            </div>
            <div class="form-group">
                <label>Jumlah (Rp)</label>
                <input type="number" name="amount" id="editAmount" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Keterangan</label>
                <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('editExpenseModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-warning" style="padding:10px 25px;">Update Data</button>
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
