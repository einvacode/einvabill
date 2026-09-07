<?php
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = app_scope_role();

// Expense categories (tab "Kategori"); managed by admin only, used by everyone.
$tenant_id = $_SESSION['tenant_id'] ?? 1;
expense_categories_ensure($db, (int)$tenant_id);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cat_action'])) {
    $redirect = 'index.php?page=admin_expenses&tab=categories';
    if ($u_role !== 'admin') { header('Location: ' . $redirect . '&err=' . urlencode('Hanya admin yang boleh mengubah kategori.')); exit; }
    $err = $_POST['cat_action'] === 'delete'
        ? expense_categories_delete($db, (int)$tenant_id, intval($_POST['cat_id'] ?? 0))
        : expense_categories_save($db, (int)$tenant_id, intval($_POST['cat_id'] ?? 0), (string)($_POST['cat_name'] ?? ''), (string)($_POST['cat_description'] ?? ''));
    header('Location: ' . $redirect . ($err ? '&err=' . urlencode($err) : '&msg=' . ($_POST['cat_action'] === 'delete' ? 'cat_deleted' : 'cat_saved')));
    exit;
}
$categories = expense_categories_list($db, (int)$tenant_id);
$active_tab = in_array($_GET['tab'] ?? '', ['categories', 'recurring']) ? $_GET['tab'] : 'expenses';

// Biaya berulang (tab "Biaya berulang"); admin only. Due templates are posted on every admin visit.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rec_action'])) {
    $redirect = 'index.php?page=admin_expenses&tab=recurring';
    if ($u_role !== 'admin') { header('Location: ' . $redirect . '&err=' . urlencode('Hanya admin yang boleh mengubah biaya berulang.')); exit; }
    $rid = intval($_POST['rec_id'] ?? 0);
    if ($_POST['rec_action'] === 'delete') { recurring_expense_delete($db, (int)$tenant_id, $rid); header('Location: ' . $redirect . '&msg=rec_deleted'); exit; }
    if ($_POST['rec_action'] === 'run') { $n = recurring_expenses_run($db, (int)$tenant_id, (int)$u_id, $rid); header('Location: ' . $redirect . '&msg=rec_run&n=' . $n); exit; }
    $err = recurring_expense_save($db, (int)$tenant_id, $rid, $_POST);
    header('Location: ' . $redirect . ($err ? '&err=' . urlencode($err) : '&msg=rec_saved'));
    exit;
}
if ($u_role === 'admin') $recurring_posted = recurring_expenses_run($db, (int)$tenant_id, (int)$u_id);
$recurring = $u_role === 'admin' ? recurring_expenses_list($db, (int)$tenant_id) : [];

// Bukti struk: gambar di public/uploads/receipts, disimpan sebagai path relatif.
$receipt_dir = __DIR__ . '/../../public/uploads/receipts';
$receipt_upload = function () use ($receipt_dir): array {
    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] === UPLOAD_ERR_NO_FILE) return ['path' => null, 'error' => ''];
    $up = save_uploaded_image($_FILES['receipt'], $receipt_dir, 'struk');
    return $up['ok'] ? ['path' => 'public/uploads/receipts/' . $up['filename'], 'error' => ''] : ['path' => null, 'error' => 'Struk: ' . $up['error']];
};
$receipt_unlink = function (?string $path): void {
    if ($path && preg_match('~^public/uploads/receipts/[A-Za-z0-9_.-]+$~', $path)) @unlink(__DIR__ . '/../../' . $path);
};

// Akun kas yang menanggung pengeluaran (Kas & Bank). Non-admin hanya boleh memakai dompetnya sendiri.
cash_accounts_ensure($db, (int)$tenant_id);
$cash_accounts = array_values(array_filter(cash_accounts_with_balances($db, (int)$tenant_id), fn($a) => $a['is_active'] && ($u_role === 'admin' || intval($a['owner_user_id']) === intval($u_id))));
$cash_default_id = cash_default_account_id($db, (int)$tenant_id);
$cash_names = []; foreach (cash_accounts_with_balances($db, (int)$tenant_id) as $ca) $cash_names[intval($ca['id'])] = $ca['name'];
$cash_pick_account = function (int $wanted) use ($cash_accounts, $cash_default_id): ?int {
    foreach ($cash_accounts as $a) if (intval($a['id']) === $wanted) return $wanted;
    return $cash_accounts ? intval($cash_accounts[0]['id']) : ($cash_default_id ?: null);
};

// Handle ADD Expense
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date = $_POST['date'];

    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $up = $receipt_upload();
    if ($up['error']) { header("Location: index.php?page=admin_expenses&err=" . urlencode($up['error'])); exit; }
    $account_id = $cash_pick_account(intval($_POST['account_id'] ?? 0));
    $stmt = $db->prepare("INSERT INTO expenses (category, amount, description, date, created_by, tenant_id, receipt_path, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$category, $amount, $description, $date, $u_id, $tenant_id, $up['path'], $account_id]);
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
    $check = $db->query("SELECT tenant_id, created_by, receipt_path FROM expenses WHERE id = $id")->fetch();
    $is_owner = ($u_role === 'admin') ? ($check['tenant_id'] == $tenant_id) : ($check['created_by'] == $u_id);

    if ($is_owner) {
        $receipt_path = $check['receipt_path'] ?? null;
        $up = $receipt_upload();
        if ($up['error']) { header("Location: index.php?page=admin_expenses&err=" . urlencode($up['error'])); exit; }
        if ($up['path']) { $receipt_unlink($receipt_path); $receipt_path = $up['path']; }
        elseif (!empty($_POST['remove_receipt'])) { $receipt_unlink($receipt_path); $receipt_path = null; }
        $account_id = $cash_pick_account(intval($_POST['account_id'] ?? 0));
        $stmt = $db->prepare("UPDATE expenses SET category=?, amount=?, description=?, date=?, receipt_path=?, account_id=? WHERE id=? AND tenant_id=?");
        $stmt->execute([$category, $amount, $description, $date, $receipt_path, $account_id, $id, $tenant_id]);
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
    $check = $db->query("SELECT tenant_id, created_by, receipt_path FROM expenses WHERE id = $id")->fetch();
    $is_owner = ($u_role === 'admin') ? ($check['tenant_id'] == $tenant_id) : ($check['created_by'] == $u_id);

    if ($is_owner) {
        $before = $db->query("SELECT category, amount, date, description FROM expenses WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: [];
        $receipt_unlink($check['receipt_path'] ?? null);
        $db->prepare("DELETE FROM expenses WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        audit_log($db, 'expense_delete', 'expenses', $id, 'Menghapus pengeluaran ' . ($before['category'] ?? '-') . ' Rp ' . number_format((float)($before['amount'] ?? 0), 0, ',', '.') . ' (' . ($before['date'] ?? '-') . ')' . (!empty($before['description']) ? ': ' . $before['description'] : ''), $before);
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

<?php
$tab_active = 'rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground shadow-card no-underline';
$tab_idle = 'rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground no-underline';
?>
<div class="mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1" role="tablist">
    <a href="index.php?page=admin_expenses" class="<?= $active_tab === 'expenses' ? $tab_active : $tab_idle ?>">Pengeluaran</a>
    <a href="index.php?page=admin_expenses&tab=categories" class="<?= $active_tab === 'categories' ? $tab_active : $tab_idle ?>">Kategori <span class="tabular-nums text-muted-foreground">(<?= count($categories) ?>)</span></a>
    <?php if ($u_role === 'admin'): ?>
    <a href="index.php?page=admin_expenses&tab=recurring" class="<?= $active_tab === 'recurring' ? $tab_active : $tab_idle ?>">Biaya berulang<?= $recurring ? ' <span class="tabular-nums text-muted-foreground">(' . count($recurring) . ')</span>' : '' ?></a>
    <?php endif; ?>
</div>

<?php
$flash = ['added' => 'Pengeluaran berhasil ditambahkan.', 'updated' => 'Data pengeluaran diperbarui.', 'deleted' => 'Catatan pengeluaran dihapus.', 'cat_saved' => 'Kategori tersimpan.', 'cat_deleted' => 'Kategori dihapus.',
          'rec_saved' => 'Biaya berulang tersimpan.', 'rec_deleted' => 'Biaya berulang dihapus. Catatan yang sudah dibuat tetap ada.', 'rec_run' => intval($_GET['n'] ?? 0) > 0 ? intval($_GET['n']) . ' catatan pengeluaran dibuat.' : 'Tidak ada yang perlu dibuat; bulan ini sudah tercatat.'][$_GET['msg'] ?? ''] ?? '';
if (!empty($recurring_posted) && !$flash) $flash = $recurring_posted . ' biaya berulang jatuh tempo dan sudah dicatat otomatis.';
$flash_err = trim((string)($_GET['err'] ?? ''));
if ($flash): ?>
    <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> <?= htmlspecialchars($flash) ?></div>
<?php elseif ($flash_err): ?>
    <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> <?= htmlspecialchars($flash_err) ?></div>
<?php endif; ?>

<?php if ($active_tab === 'categories'): ?>
<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
    <section class="ui-card overflow-hidden">
        <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
            <h3 class="m-0 text-[15px] font-bold">Kategori pengeluaran</h3>
            <p class="m-0 text-xs text-muted-foreground">Pilihan kategori pada form pengeluaran. Mengubah nama ikut memperbarui semua catatan yang memakainya.</p>
        </div>
        <?php if (empty($categories)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada kategori.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold sm:px-5">Kategori</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Dipakai</th>
                        <?php if ($u_role === 'admin'): ?><th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr class="border-t border-solid border-border" id="catRow-<?= intval($cat['id']) ?>">
                        <td class="px-4 py-3 sm:px-5">
                            <div class="font-semibold"><?= htmlspecialchars($cat['name']) ?></div>
                            <?php if (!empty($cat['description'])): ?><div class="text-xs text-muted-foreground"><?= htmlspecialchars($cat['description']) ?></div><?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap"><?= number_format($cat['used']) ?> catatan</td>
                        <?php if ($u_role === 'admin'): ?>
                        <td class="px-4 py-3 sm:px-5">
                            <div class="flex justify-end gap-1.5">
                                <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" title="Ubah" onclick="editCategory(<?= intval($cat['id']) ?>)"><i class="fas fa-edit"></i><span class="hidden sm:inline">Ubah</span></button>
                                <form method="POST" action="index.php?page=admin_expenses" class="m-0" onsubmit="return confirm('Hapus kategori ini?')">
<?= csrf_field() ?>
                                    <input type="hidden" name="cat_action" value="delete">
                                    <input type="hidden" name="cat_id" value="<?= intval($cat['id']) ?>">
                                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline text-danger<?= $cat['used'] > 0 ? ' cursor-not-allowed opacity-50' : '' ?>" title="<?= $cat['used'] > 0 ? 'Masih dipakai catatan pengeluaran' : 'Hapus' ?>" <?= $cat['used'] > 0 ? 'disabled' : '' ?>><i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span></button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($u_role === 'admin'): ?>
    <aside class="lg:sticky lg:top-24">
        <section class="ui-card p-4 sm:p-5">
            <h3 class="m-0 text-[15px] font-bold" id="catFormTitle">Tambah kategori</h3>
            <p class="m-0 mt-1 text-xs text-muted-foreground">Kategori yang masih dipakai catatan tidak bisa dihapus, tetapi bisa diubah namanya.</p>
            <form method="POST" action="index.php?page=admin_expenses" id="catForm" class="mt-4 grid gap-4">
<?= csrf_field() ?>
                <input type="hidden" name="cat_action" value="save">
                <input type="hidden" name="cat_id" id="cat_id" value="0">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama kategori <span class="text-danger">*</span></span>
                    <input type="text" name="cat_name" id="cat_name" class="form-control" placeholder="Contoh: Transportasi" maxlength="60" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan</span>
                    <input type="text" name="cat_description" id="cat_description" class="form-control" placeholder="Opsional, contoh: BBM dan tol teknisi" maxlength="200">
                </label>
                <div class="grid gap-2">
                    <button type="submit" class="ui-btn ui-btn-primary w-full" id="catSubmitBtn">Simpan kategori</button>
                    <button type="button" class="ui-btn ui-btn-outline w-full hidden" id="catCancelBtn" onclick="resetCategoryForm()">Batal ubah</button>
                </div>
            </form>
        </section>
    </aside>
    <?php endif; ?>
</div>

<script>
const EXPENSE_CATEGORIES = <?= json_encode(array_map(fn($c) => ['id' => intval($c['id']), 'name' => $c['name'], 'description' => (string)$c['description']], $categories)) ?>;
function editCategory(id) {
    const c = EXPENSE_CATEGORIES.find(x => x.id === id); if (!c) return;
    document.getElementById('cat_id').value = c.id;
    document.getElementById('cat_name').value = c.name;
    document.getElementById('cat_description').value = c.description;
    document.getElementById('catFormTitle').innerText = 'Ubah kategori';
    document.getElementById('catSubmitBtn').innerText = 'Simpan perubahan';
    document.getElementById('catCancelBtn').classList.remove('hidden');
    document.querySelectorAll('[id^="catRow-"]').forEach(r => r.classList.toggle('bg-muted', r.id === 'catRow-' + id));
    document.getElementById('cat_name').focus();
}
function resetCategoryForm() {
    document.getElementById('cat_id').value = 0;
    document.getElementById('cat_name').value = '';
    document.getElementById('cat_description').value = '';
    document.getElementById('catFormTitle').innerText = 'Tambah kategori';
    document.getElementById('catSubmitBtn').innerText = 'Simpan kategori';
    document.getElementById('catCancelBtn').classList.add('hidden');
    document.querySelectorAll('[id^="catRow-"]').forEach(r => r.classList.remove('bg-muted'));
}
</script>
<?php elseif ($active_tab === 'recurring'): ?>
<?php
$bulan_id = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$rec_month_total = array_sum(array_map(fn($r) => $r['is_active'] ? (float)$r['amount'] : 0, $recurring));
?>
<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
    <section class="ui-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
            <div>
                <h3 class="m-0 text-[15px] font-bold">Biaya berulang</h3>
                <p class="m-0 text-xs text-muted-foreground">Dicatat otomatis ke daftar pengeluaran setiap bulan pada tanggalnya. Total aktif <span class="font-semibold tabular-nums text-foreground">Rp <?= number_format($rec_month_total, 0, ',', '.') ?></span> / bulan.</p>
            </div>
        </div>
        <?php if (empty($recurring)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada biaya berulang. Tambahkan sewa upstream, listrik, gaji, atau cicilan lewat form di samping.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="min-w-[220px] px-4 py-2.5 font-semibold sm:px-5">Biaya</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Jumlah</th>
                        <th class="px-3 py-2.5 font-semibold">Jadwal</th>
                        <th class="px-4 py-2.5 text-right font-semibold sm:px-5"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recurring as $r): $next = $r['is_active'] ? recurring_next_due($r) : null; $this_month_done = !empty($r['last_date']) && substr($r['last_date'], 0, 7) === date('Y-m'); ?>
                    <tr class="border-t border-solid border-border <?= $r['is_active'] ? '' : 'opacity-60' ?>" id="recRow-<?= intval($r['id']) ?>">
                        <td class="px-4 py-3 align-top sm:px-5">
                            <div class="flex flex-wrap items-center gap-2"><span class="font-semibold"><?= htmlspecialchars($r['name']) ?></span><?php if (!$r['is_active']): ?><span class="ui-badge ui-badge-muted">Nonaktif</span><?php endif; ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($r['category']) ?><?= !empty($r['account_name']) ? ' &middot; dari ' . htmlspecialchars($r['account_name']) : '' ?><?= !empty($r['description']) ? ' &middot; ' . htmlspecialchars($r['description']) : '' ?></div>
                        </td>
                        <td class="px-3 py-3 text-right align-top font-semibold tabular-nums whitespace-nowrap">Rp <?= number_format($r['amount'], 0, ',', '.') ?></td>
                        <td class="px-3 py-3 align-top whitespace-nowrap">
                            <div>Tiap tanggal <span class="font-medium tabular-nums"><?= intval($r['day_of_month']) ?></span></div>
                            <div class="text-xs text-muted-foreground"><?= $next ? 'Berikutnya ' . date('d/m/Y', strtotime($next)) : 'Selesai' ?><?= !empty($r['end_date']) ? ' &middot; sampai ' . date('d/m/Y', strtotime($r['end_date'])) : '' ?></div>
                            <div class="text-xs text-muted-foreground"><?= !empty($r['last_date']) ? 'Terakhir ' . date('d/m/Y', strtotime($r['last_date'])) . ' &middot; ' . number_format($r['generated_n']) . ' catatan' : 'Belum pernah dibuat' ?></div>
                        </td>
                        <td class="px-4 py-3 align-top sm:px-5">
                            <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                <?php if ($r['is_active'] && !$this_month_done): ?>
                                <form method="POST" action="index.php?page=admin_expenses" class="m-0" onsubmit="return confirm('Catat biaya ini untuk bulan <?= $bulan_id[(int)date('n')] ?> sekarang?')">
<?= csrf_field() ?>
                                    <input type="hidden" name="rec_action" value="run"><input type="hidden" name="rec_id" value="<?= intval($r['id']) ?>">
                                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline" title="Catat bulan ini sekarang"><i class="fas fa-bolt"></i></button>
                                </form>
                                <?php endif; ?>
                                <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" title="Ubah" onclick="editRecurring(<?= intval($r['id']) ?>)"><i class="fas fa-edit"></i></button>
                                <form method="POST" action="index.php?page=admin_expenses" class="m-0" onsubmit="return confirm('Hapus biaya berulang ini? Catatan yang sudah dibuat tetap ada.')">
<?= csrf_field() ?>
                                    <input type="hidden" name="rec_action" value="delete"><input type="hidden" name="rec_id" value="<?= intval($r['id']) ?>">
                                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <aside class="lg:sticky lg:top-24">
        <section class="ui-card p-4 sm:p-5">
            <h3 class="m-0 text-[15px] font-bold" id="recFormTitle">Tambah biaya berulang</h3>
            <p class="m-0 mt-1 text-xs text-muted-foreground">Contoh: Sewa bandwidth upstream tiap tanggal 5.</p>
            <form method="POST" action="index.php?page=admin_expenses" id="recForm" class="mt-4 grid gap-4">
<?= csrf_field() ?>
                <input type="hidden" name="rec_action" value="save">
                <input type="hidden" name="rec_id" id="rec_id" value="0">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama biaya <span class="text-danger">*</span></span>
                    <input type="text" name="name" id="rec_name" class="form-control" placeholder="Sewa bandwidth upstream" maxlength="100" required>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Kategori</span>
                        <select name="category" id="rec_category" class="form-control" required>
                            <?php foreach ($categories as $cat): ?><option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Jumlah (Rp) <span class="text-danger">*</span></span>
                        <input type="number" name="amount" id="rec_amount" class="form-control" min="1" step="1" required>
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tiap tanggal</span>
                        <input type="number" name="day_of_month" id="rec_day" class="form-control" min="1" max="31" value="1" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Dibayar dari</span>
                        <select name="account_id" id="rec_account" class="form-control">
                            <?php foreach ($cash_accounts as $ca): ?><option value="<?= intval($ca['id']) ?>" <?= intval($ca['id']) === intval($cash_default_id) ? 'selected' : '' ?>><?= htmlspecialchars($ca['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Mulai bulan</span>
                        <input type="date" name="start_date" id="rec_start" class="form-control" value="<?= date('Y-m-01') ?>" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Selesai (opsional)</span>
                        <input type="date" name="end_date" id="rec_end" class="form-control">
                    </label>
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan</span>
                    <input type="text" name="description" id="rec_desc" class="form-control" placeholder="Opsional, contoh: ID pelanggan PLN" maxlength="200">
                </label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" id="rec_active" value="1" checked> Aktif</label>
                <div class="grid gap-2">
                    <button type="submit" class="ui-btn ui-btn-primary w-full" id="recSubmitBtn">Simpan biaya berulang</button>
                    <button type="button" class="ui-btn ui-btn-outline w-full hidden" id="recCancelBtn" onclick="resetRecurringForm()">Batal ubah</button>
                </div>
                <p class="m-0 text-xs text-muted-foreground">Bulan yang sudah lewat sejak tanggal mulai akan dicatat sekaligus saat disimpan. Untuk cicilan, isi tanggal selesai.</p>
            </form>
        </section>
    </aside>
</div>

<script>
const RECURRING = <?= json_encode(array_map(fn($r) => ['id' => intval($r['id']), 'name' => $r['name'], 'category' => $r['category'], 'amount' => (float)$r['amount'], 'day_of_month' => intval($r['day_of_month']), 'account_id' => intval($r['account_id'] ?? 0), 'start_date' => (string)$r['start_date'], 'end_date' => (string)$r['end_date'], 'description' => (string)$r['description'], 'is_active' => (int)$r['is_active']], $recurring)) ?>;
function editRecurring(id) {
    const r = RECURRING.find(x => x.id === id); if (!r) return;
    document.getElementById('rec_id').value = r.id;
    document.getElementById('rec_name').value = r.name;
    const cat = document.getElementById('rec_category'); if (r.category && ![...cat.options].some(o => o.value === r.category)) cat.add(new Option(r.category, r.category)); cat.value = r.category;
    document.getElementById('rec_amount').value = r.amount;
    document.getElementById('rec_day').value = r.day_of_month;
    if (r.account_id) document.getElementById('rec_account').value = r.account_id;
    document.getElementById('rec_start').value = r.start_date;
    document.getElementById('rec_end').value = r.end_date;
    document.getElementById('rec_desc').value = r.description;
    document.getElementById('rec_active').checked = !!r.is_active;
    document.getElementById('recFormTitle').innerText = 'Ubah biaya berulang';
    document.getElementById('recSubmitBtn').innerText = 'Simpan perubahan';
    document.getElementById('recCancelBtn').classList.remove('hidden');
    document.querySelectorAll('[id^="recRow-"]').forEach(x => x.classList.toggle('bg-muted', x.id === 'recRow-' + id));
    document.getElementById('rec_name').focus();
}
function resetRecurringForm() {
    document.getElementById('recForm').reset();
    document.getElementById('rec_id').value = 0;
    document.getElementById('recFormTitle').innerText = 'Tambah biaya berulang';
    document.getElementById('recSubmitBtn').innerText = 'Simpan biaya berulang';
    document.getElementById('recCancelBtn').classList.add('hidden');
    document.querySelectorAll('[id^="recRow-"]').forEach(x => x.classList.remove('bg-muted'));
}
</script>
<?php else: ?>

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
                        <div class="mt-1 text-[11px] text-muted-foreground">dari <?= htmlspecialchars($cash_names[$e['account_id'] ?? $cash_default_id] ?? 'Kas utama') ?></div>
                    </td>
                    <td class="px-3 py-3 text-muted-foreground">
                        <div><?= htmlspecialchars($e['description'] ?: '-') ?></div>
                        <?php if (!empty($e['receipt_path'])): ?>
                            <button type="button" class="mt-1 inline-flex items-center gap-1.5 rounded-md border border-solid border-border bg-card px-2 py-1 text-xs font-medium text-foreground hover:bg-muted" onclick="openReceipt(<?= htmlspecialchars(json_encode($e['receipt_path'])) ?>)"><i class="fas fa-receipt text-muted-foreground"></i> Lihat struk</button>
                        <?php else: ?>
                            <div class="mt-1 text-[11px] text-muted-foreground">Tanpa struk</div>
                        <?php endif; ?>
                    </td>
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
        <form action="index.php?page=admin_expenses&action=add" method="POST" enctype="multipart/form-data">
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
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Dibayar dari</span>
                    <select name="account_id" class="form-control">
                        <?php foreach ($cash_accounts as $ca): ?><option value="<?= intval($ca['id']) ?>" <?= intval($ca['id']) === intval($cash_default_id) ? 'selected' : '' ?>><?= htmlspecialchars($ca['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Kategori</span>
                    <select name="category" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?><?= !empty($cat['description']) ? ' (' . htmlspecialchars($cat['description']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan</span>
                    <textarea name="description" class="form-control" rows="3" placeholder="Detail pengeluaran..."></textarea>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Bukti struk / nota (opsional)</span>
                    <input type="file" name="receipt" class="form-control" accept="image/*" capture="environment">
                    <span class="mt-1 block text-xs text-muted-foreground">Foto JPG/PNG/WEBP maksimal 5 MB.</span>
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
        <form action="index.php?page=admin_expenses&action=update" method="POST" enctype="multipart/form-data">
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
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Dibayar dari</span>
                    <select name="account_id" id="editAccount" class="form-control">
                        <?php foreach ($cash_accounts as $ca): ?><option value="<?= intval($ca['id']) ?>"><?= htmlspecialchars($ca['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Kategori</span>
                    <select name="category" id="editCategory" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan</span>
                    <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                </label>
                <div class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Bukti struk / nota</span>
                    <div id="editReceiptCurrent" class="mb-2 hidden items-center gap-3">
                        <img id="editReceiptThumb" src="" alt="Struk" class="h-14 w-20 cursor-pointer rounded-md border border-solid border-border object-cover" onclick="openReceipt(this.src)">
                        <label class="flex items-center gap-2 text-xs text-muted-foreground"><input type="checkbox" name="remove_receipt" value="1" id="editRemoveReceipt"> Hapus struk ini</label>
                    </div>
                    <input type="file" name="receipt" class="form-control" accept="image/*" capture="environment">
                    <span class="mt-1 block text-xs text-muted-foreground">Unggah foto baru untuk mengganti struk lama.</span>
                </div>
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
    const sel = document.getElementById('editCategory');
    if (data.category && ![...sel.options].some(o => o.value === data.category)) sel.add(new Option(data.category, data.category));
    sel.value = data.category;
    document.getElementById('editAmount').value = data.amount;
    const accSel = document.getElementById('editAccount'); if (accSel) accSel.value = data.account_id || <?= intval($cash_default_id) ?>;
    document.getElementById('editDescription').value = data.description;
    const cur = document.getElementById('editReceiptCurrent');
    if (data.receipt_path) { document.getElementById('editReceiptThumb').src = data.receipt_path; cur.classList.remove('hidden'); cur.classList.add('flex'); }
    else { cur.classList.add('hidden'); cur.classList.remove('flex'); }
    document.getElementById('editRemoveReceipt').checked = false;
    document.getElementById('editExpenseModal').style.display = 'flex';
}
function openReceipt(src) {
    document.getElementById('receiptPreviewImg').src = src;
    document.getElementById('receiptPreviewLink').href = src;
    document.getElementById('receiptPreviewModal').style.display = 'flex';
}
</script>
<?php endif; ?>
<?php if ($active_tab !== 'categories'): ?>
<!-- Receipt preview -->
<div id="receiptPreviewModal" class="fixed inset-0 z-[1001] items-center justify-center bg-black/70 p-4" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
    <div class="ui-card max-h-full w-full max-w-2xl overflow-auto p-3">
        <div class="mb-2 flex items-center justify-between gap-3 px-1">
            <div class="text-sm font-semibold">Bukti struk</div>
            <div class="flex gap-2">
                <a id="receiptPreviewLink" href="#" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline">Buka asli</a>
                <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('receiptPreviewModal').style.display='none'" aria-label="Tutup">&#x2715;</button>
            </div>
        </div>
        <img id="receiptPreviewImg" src="" alt="Struk" class="mx-auto max-h-[75vh] max-w-full rounded-md object-contain">
    </div>
</div>
<?php endif; ?>
