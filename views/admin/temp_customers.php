<?php
// CRUD sederhana untuk Pelanggan Baru (type 'note' atau 'temp')
$u_role = $_SESSION['user_role'] ?? 'guest';
if ($u_role !== 'admin') { echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-lg font-bold'>Akses ditolak</h2></div>"; return; }

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
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Pelanggan baru (sementara)</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Kelola pelanggan yang dibuat cepat lewat fitur invoice cepat.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a class="ui-btn ui-btn-outline" href="index.php?page=admin_customers">Kembali ke daftar</a>
    </div>
</div>

<form method="POST" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_220px_1fr_auto] lg:items-end">
<?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama pelanggan</span>
        <input type="text" name="name" class="form-control" placeholder="Nama pelanggan" required>
    </label>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Kontak / HP</span>
        <input type="text" name="contact" class="form-control" placeholder="Kontak / HP">
    </label>
    <label class="block">
        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat</span>
        <input type="text" name="address" class="form-control" placeholder="Alamat">
    </label>
    <div class="flex gap-2">
        <button class="ui-btn ui-btn-primary w-full sm:w-auto">Tambah</button>
    </div>
</form>

<section class="ui-card overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Daftar pelanggan sementara</h3>
            <p class="m-0 text-xs text-muted-foreground">Maksimal 200 data terbaru</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold">ID</th>
                    <th class="px-4 py-2.5 font-semibold">Nama</th>
                    <th class="px-4 py-2.5 font-semibold">Kontak</th>
                    <th class="px-4 py-2.5 font-semibold">Alamat</th>
                    <th class="px-4 py-2.5 font-semibold">Terdaftar</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($temps)): ?>
                <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</td></tr>
            <?php endif; ?>
            <?php foreach($temps as $t): ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 tabular-nums text-muted-foreground"><?= intval($t['id']) ?></td>
                    <td class="px-4 py-3 font-semibold"><?= htmlspecialchars($t['name']) ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars($t['contact'] ?: '-') ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars($t['address'] ?: '-') ?></td>
                    <td class="px-4 py-3 text-xs text-muted-foreground"><?= htmlspecialchars($t['registration_date']) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-1.5">
                            <a class="ui-btn ui-btn-sm ui-btn-outline" href="index.php?page=admin_customers&action=edit&id=<?= intval($t['id']) ?>">Edit</a>
                            <form method="POST" class="m-0 inline-block">
<?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= intval($t['id']) ?>">
                                <button class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus pelanggan sementara ini?')">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
