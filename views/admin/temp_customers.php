<?php
// CRUD sederhana untuk Pelanggan Baru (type 'note' atau 'temp')
$u_role = $_SESSION['user_role'] ?? 'guest';
if ($u_role !== 'admin') { echo "<div class='glass-panel' style='padding:40px; text-align:center;'><h2>Akses Ditolak</h2></div>"; return; }

// Actions: add, edit, delete
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
require_once __DIR__ . '/../../app/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        if ($name !== '') {
            $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, type, created_by, registration_date) VALUES (?, ?, ?, ?, 'note', 0, datetime('now'))");
            $stmt->execute([null, $name, $address, $contact]);
        }
        header('Location: index.php?page=admin_temp_customers'); exit;
    }
    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        if ($id > 0 && $name !== '') {
            $db->prepare("UPDATE customers SET name=?, address=?, contact=? WHERE id=? AND type IN ('note','temp')")->execute([$name, $address, $contact, $id]);
        }
        header('Location: index.php?page=admin_temp_customers'); exit;
    }
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            // hanya hapus customer, jangan hapus invoice otomatis — biar aman
            $db->prepare("DELETE FROM customers WHERE id = ? AND type IN ('note','temp')")->execute([$id]);
        }
        header('Location: index.php?page=admin_temp_customers'); exit;
    }
}

$temps = $db->query("SELECT id, name, address, contact, registration_date FROM customers WHERE type IN ('note','temp') ORDER BY registration_date DESC LIMIT 200")->fetchAll();
?>
<div class="glass-panel" style="padding:16px;">
    <h3>Pelanggan Baru (Sementara)</h3>
    <p style="color:var(--text-secondary);">Kelola pelanggan yang dibuat cepat lewat fitur invoice cepat.</p>

    <div style="margin-top:12px; display:flex; gap:12px;">
        <form method="POST" style="flex:1;">
            <input type="hidden" name="action" value="add">
            <div style="display:grid; grid-template-columns:1fr 220px; gap:8px;">
                <input type="text" name="name" class="form-control" placeholder="Nama pelanggan" required>
                <input type="text" name="contact" class="form-control" placeholder="Kontak / HP">
                <input type="text" name="address" class="form-control" placeholder="Alamat">
                <div style="display:flex; gap:8px;"><button class="btn btn-primary">Tambah</button><a class="btn btn-ghost" href="index.php?page=admin_customers">Kembali ke Daftar</a></div>
            </div>
        </form>
    </div>

    <div style="margin-top:18px; overflow:auto;">
        <table class="table" style="width:100%; font-size:13px;">
            <thead><tr><th>ID</th><th>Nama</th><th>Kontak</th><th>Alamat</th><th>Terdaftar</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach($temps as $t): ?>
                <tr>
                    <td><?= intval($t['id']) ?></td>
                    <td><?= htmlspecialchars($t['name']) ?></td>
                    <td><?= htmlspecialchars($t['contact'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($t['address'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($t['registration_date']) ?></td>
                    <td>
                        <form method="POST" style="display:inline-block; margin-right:6px;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= intval($t['id']) ?>">
                            <button class="btn btn-xs btn-danger" onclick="return confirm('Hapus pelanggan sementara ini?')">Hapus</button>
                        </form>
                        <a class="btn btn-xs btn-ghost" href="index.php?page=admin_customers&action=edit&id=<?= intval($t['id']) ?>">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
