<?php
$action = $_GET['action'] ?? 'list';

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $name = $_POST['name'];
    $area = $_POST['area'] ?? null;
    $customer_id = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;

    // Super Admin (ID 1) can assign specific tenant_id, others use their own session tenant_id
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    if ($_SESSION['user_id'] == 1 && !empty($_POST['target_tenant_id'])) {
        $tenant_id = intval($_POST['target_tenant_id']);
    }
    
    // Safety: only Super Admin can create Admin role
    if ($role === 'admin' && $_SESSION['user_id'] != 1) {
        $role = 'collector';
    }

    $stmt = $db->prepare("INSERT INTO users (username, password, role, name, area, customer_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$username, $password, $role, $name, $area, $customer_id, $tenant_id]);
    header("Location: index.php?page=admin_users");
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $username = $_POST['username'];
    $role = $_POST['role'];
    $name = $_POST['name'];
    $area = $_POST['area'] ?? null;
    $customer_id = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    if ($_SESSION['user_id'] == 1 && !empty($_POST['target_tenant_id'])) {
        $tenant_id = intval($_POST['target_tenant_id']);
    }

    // Role safety: Only Super Admin can promote someone to Admin
    if ($role === 'admin' && $_SESSION['user_id'] != 1) {
        $old_role_stmt = $db->prepare("SELECT role FROM users WHERE id = ? AND tenant_id = ?");
        $old_role_stmt->execute([$id, $_SESSION['tenant_id']]);
        $role = $old_role_stmt->fetchColumn() ?: 'collector';
    }

    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET username=?, password=?, role=?, name=?, area=?, customer_id=?, tenant_id=? WHERE id=? AND (tenant_id=? OR " . ($_SESSION['user_id'] == 1 ? "1=1" : "1=0") . ")");
        $stmt->execute([$username, $password, $role, $name, $area, $customer_id, $tenant_id, $id, $_SESSION['tenant_id']]);
    } else {
        $stmt = $db->prepare("UPDATE users SET username=?, role=?, name=?, area=?, customer_id=?, tenant_id=? WHERE id=? AND (tenant_id=? OR " . ($_SESSION['user_id'] == 1 ? "1=1" : "1=0") . ")");
        $stmt->execute([$username, $role, $name, $area, $customer_id, $tenant_id, $id, $_SESSION['tenant_id']]);
    }
    
    header("Location: index.php?page=admin_users");
    exit;
}

if ($action === 'delete') {
    $id = intval($_GET['id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Prevent deleting self or primary admin (id=1)
    if ($id > 1 && $id != $_SESSION['user_id']) {
        $before = $db->query("SELECT username, name, role FROM users WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: [];
        $db->prepare("DELETE FROM users WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        audit_log($db, 'user_delete', 'users', $id, 'Menghapus akun ' . ($before['name'] ?? '-') . ' (' . ($before['username'] ?? '-') . ', ' . ($before['role'] ?? '-') . ')', $before);
    }
    header("Location: index.php?page=admin_users");
    exit;
}

// Fetch customers (Rumahan) for linking to Collector accounts
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$customers_list = $db->query("SELECT id, name FROM customers WHERE type='customer' AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
// Fetch partners (Mitra) for linking to Mitra accounts
$partners_list = $db->query("SELECT id, name FROM customers WHERE type='partner' AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();

// Fetch all administrative users (tenants) for the Super Admin selector
if ($_SESSION['user_id'] == 1) {
    $all_tenants = $db->query("SELECT id, name, (SELECT company_name FROM settings WHERE tenant_id = users.id LIMIT 1) as company_name FROM users WHERE role = 'admin' ORDER BY id ASC")->fetchAll();
}

// Fetch all areas for dropdown
$areas_all = $db->query("SELECT * FROM areas WHERE tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
if ($_SESSION['user_id'] == 1 && $action !== 'list') {
    // Super admin might need a hybrid or all-area view, for now just show default tenant's areas 
    // or we could ajax-load them when tenant is changed.
}
?>

<?php if ($action === 'list'): ?>
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Pengguna dan akses login</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Akun admin, penagih, dan mitra yang dapat masuk ke aplikasi.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="index.php?page=admin_users&action=create" class="ui-btn ui-btn-primary w-full sm:w-auto"><i class="fas fa-plus"></i> Tambah pengguna</a>
    </div>
</div>

<section class="ui-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold sm:px-5">Username</th>
                    <th class="px-3 py-2.5 font-semibold">Nama pengguna</th>
                    <th class="px-3 py-2.5 font-semibold">Hak akses</th>
                    <?= $_SESSION['user_id'] == 1 ? '<th class="px-3 py-2.5 font-semibold">Tenant / organisasi</th>' : '' ?>
                    <th class="px-3 py-2.5 font-semibold">Area (penagih) / link (mitra)</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $u_id_session = $_SESSION['user_id'];
                $tenant_id_session = $_SESSION['tenant_id'] ?? 1;

                $sql = "SELECT u.*, c.name as partner_name, (SELECT company_name FROM settings s WHERE s.tenant_id = u.tenant_id LIMIT 1) as org_name
                        FROM users u
                        LEFT JOIN customers c ON u.customer_id = c.id
                        WHERE " . ($u_id_session == 1 ? "1=1" : "u.tenant_id = $tenant_id_session") . "
                        ORDER BY u.id DESC";
                $users = $db->query($sql)->fetchAll();
                foreach($users as $u):
                ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 font-semibold sm:px-5"><?= htmlspecialchars($u['username']) ?></td>
                    <td class="px-3 py-3"><?= htmlspecialchars($u['name']) ?></td>
                    <td class="px-3 py-3">
                        <?php if($u['role']=='admin'): ?>
                            <span class="ui-badge border-transparent bg-primary-soft text-primary">Admin</span>
                        <?php elseif($u['role']=='collector'): ?>
                            <span class="ui-badge ui-badge-accent">Penagih</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-signal">Mitra</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($_SESSION['user_id'] == 1): ?>
                        <td class="px-3 py-3">
                            <div class="font-semibold"><?= htmlspecialchars($u['org_name'] ?? 'Default Tenant') ?></div>
                            <div class="text-xs text-muted-foreground">T-ID: <?= $u['tenant_id'] ?></div>
                        </td>
                    <?php endif; ?>
                    <td class="px-3 py-3">
                        <?php if($u['role']=='collector'): ?>
                            <div class="text-xs">Area: <?= htmlspecialchars($u['area'] && trim($u['area']) != '' ? $u['area'] : 'Semua area') ?></div>
                        <?php endif; ?>

                        <?php if($u['role']=='partner' || $u['role']=='collector'): ?>
                            <div class="text-xs text-muted-foreground">Link: <?= htmlspecialchars($u['partner_name'] ?? 'Belum terhubung') ?></div>
                        <?php else: ?>
                            <div class="text-xs text-muted-foreground">-</div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right sm:px-5">
                        <div class="inline-flex gap-1">
                            <a href="index.php?page=admin_users&action=edit&id=<?= $u['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline" title="Edit"><i class="fas fa-edit"></i><span class="hidden sm:inline">Edit</span></a>
                            <?php if($u['id'] != 1 && $u['id'] != $_SESSION['user_id']): ?>
                                <a data-method="post" href="index.php?page=admin_users&action=delete&id=<?= $u['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" onclick="return confirm('Hapus user ini?')"><i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($action === 'create' || $action === 'edit'):
    $is_edit = ($action === 'edit');
    $u = null;
    if ($is_edit) {
        $id = $_GET['id'];
        $u = $db->query("SELECT * FROM users WHERE id = " . intval($id))->fetch();
    }
?>
<div class="mx-auto max-w-lg">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl"><?= $is_edit ? 'Edit pengguna' : 'Tambah pengguna baru' ?></h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Akun login beserta hak akses dan penempatannya.</p>
        </div>
    </div>

    <form action="index.php?page=admin_users&action=<?= $is_edit ? 'update' : 'add' ?>" method="POST" class="ui-card p-5 sm:p-6">
        <?php if($is_edit): ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><?php endif; ?>

        <div class="grid gap-4">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Username</span>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($u['username'] ?? '') ?>" required>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Password<?= $is_edit ? ' <span class="font-normal">(kosongkan jika tidak ingin mengubah password)</span>' : '' ?></span>
                <input type="password" name="password" class="form-control" <?= $is_edit ? '' : 'required' ?>>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama pengguna / pegawai</span>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($u['name'] ?? '') ?>" required>
            </label>

            <?php
                $current_role = $u['role'] ?? 'collector';
            ?>
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Hak akses / role</span>
                <select name="role" id="roleSelect" class="form-control" required onchange="toggleRoleFields()">
                    <?php if ($_SESSION['user_id'] == 1): ?>
                        <option value="admin" <?= $current_role=='admin'?'selected':'' ?>>Administrator (pemilik tenant)</option>
                    <?php endif; ?>
                    <option value="bendahara" <?= $current_role=='bendahara'?'selected':'' ?>>Bendahara (keuangan, tanpa hapus data)</option>
                    <option value="collector" <?= $current_role=='collector'?'selected':'' ?>>Penagih / collector</option>
                    <option value="partner" <?= $current_role=='partner'?'selected':'' ?>>Mitra (akses mandiri)</option>
                </select>
            </label>

            <?php if ($_SESSION['user_id'] == 1): ?>
            <div id="field_tenant_select" class="rounded-md border border-solid border-border bg-background p-4">
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Penempatan tenant</label>
                <select name="target_tenant_id" class="form-control">
                    <?php foreach($all_tenants as $ten): ?>
                        <option value="<?= $ten['id'] ?>" <?= ($u['tenant_id'] ?? '') == $ten['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ten['company_name'] ?: ($ten['name'] . ' (Root)')) ?> [ID: <?= $ten['id'] ?>]</option>
                    <?php endforeach; ?>
                </select>
                <p class="m-0 mt-2 text-xs text-muted-foreground">Pilih organisasi tempat user ini bernaung. Jika user adalah admin baru, pilih dirinya sendiri atau parent admin-nya.</p>
            </div>
            <?php endif; ?>

            <div id="field_area" class="rounded-md border border-solid border-border bg-background p-4" style="display:none;">
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Target area penagihan</label>
                <select name="area" class="form-control">
                    <option value="">-- Semua area (akses penuh) --</option>
                    <?php foreach($areas_all as $a): ?>
                        <option value="<?= htmlspecialchars($a['name']) ?>" <?= ($u['area'] ?? '') == $a['name'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if(!empty($u['area']) && !in_array($u['area'], array_column($areas_all, 'name'))): ?>
                        <option value="<?= htmlspecialchars($u['area']) ?>" selected><?= htmlspecialchars($u['area']) ?> (Legacy)</option>
                    <?php endif; ?>
                </select>
                <p class="m-0 mt-2 text-xs text-muted-foreground">Hanya penagih dengan area yang sama dengan data pelanggan yang dapat menagih pelanggan tersebut. Pilih "Semua area" agar ia bisa menagih di mana saja.</p>
            </div>

            <div id="field_customer_link" class="rounded-md border border-solid border-border bg-background p-4" style="display:none;">
                <label class="mb-1 block text-xs font-medium text-muted-foreground" id="link_label"><i class="fas fa-link"></i> Tautkan ke Data Pelanggan</label>
                <select name="customer_id" id="customerIdSelect" class="form-control">
                    <option value="">-- Pilih --</option>
                </select>
                <p id="link_hint" class="m-0 mt-2 text-xs text-muted-foreground">Tautkan akun ini ke satu profil pelanggan.</p>
            </div>
        </div>
        <script>
        var _customersList = <?= json_encode(array_map(function($c){ return ['id'=>$c['id'],'name'=>$c['name']]; }, $customers_list)) ?>;
        var _partnersList = <?= json_encode(array_map(function($p){ return ['id'=>$p['id'],'name'=>$p['name']]; }, $partners_list)) ?>;
        var _currentLinkedId = <?= json_encode($u['customer_id'] ?? '') ?>;
        </script>

        <div class="mt-6 flex justify-end gap-2">
            <a href="index.php?page=admin_users" class="ui-btn ui-btn-outline">Batal</a>
            <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> Simpan</button>
        </div>
    </form>
</div>

<script>
function toggleRoleFields() {
    var role = document.getElementById('roleSelect').value;
    document.getElementById('field_area').style.display = (role === 'collector') ? 'block' : 'none';
    document.getElementById('field_customer_link').style.display = (role === 'partner' || role === 'collector') ? 'block' : 'none';
    
    // Populate correct customer/partner list based on role
    var sel = document.getElementById('customerIdSelect');
    var label = document.getElementById('link_label');
    var hint = document.getElementById('link_hint');
    var list = (role === 'partner') ? _partnersList : _customersList;
    var placeholder = (role === 'partner') ? '-- Pilih Data Mitra --' : '-- Pilih Data Pelanggan Rumahan --';
    
    sel.innerHTML = '<option value="">' + placeholder + '</option>';
    list.forEach(function(item) {
        var opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = item.name;
        if (String(item.id) === String(_currentLinkedId)) opt.selected = true;
        sel.appendChild(opt);
    });
    
    if (role === 'partner') {
        label.innerHTML = '<i class="fas fa-link"></i> Tautkan ke Data Mitra';
        hint.textContent = 'Tautkan akun login mitra ini ke profil data mitra (B2B) agar bisa melihat tagihan ke ISP.';
    } else {
        label.innerHTML = '<i class="fas fa-link"></i> Tautkan ke Data Pelanggan';
        hint.textContent = 'Tautkan akun ini ke satu profil pelanggan rumahan untuk monitoring tagihan mandiri.';
    }
}
// Run immediately (script is at bottom, DOM already available) + backup listener
toggleRoleFields();
document.addEventListener('DOMContentLoaded', toggleRoleFields);
</script>
<?php endif; ?>
