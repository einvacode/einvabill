<?php
$action = $_GET['action'] ?? 'list';

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $name = $_POST['name'];
    $area = $_POST['area'] ?? null;
    $customer_id = !empty($_POST['customer_id']) ? $_POST['customer_id'] : null;

    $stmt = $db->prepare("INSERT INTO users (username, password, role, name, area, customer_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$username, $password, $role, $name, $area, $customer_id]);
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
    
    if (!empty($_POST['password'])) {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET username=?, password=?, role=?, name=?, area=?, customer_id=? WHERE id=?");
        $stmt->execute([$username, $password, $role, $name, $area, $customer_id, $id]);
    } else {
        $stmt = $db->prepare("UPDATE users SET username=?, role=?, name=?, area=?, customer_id=? WHERE id=?");
        $stmt->execute([$username, $role, $name, $area, $customer_id, $id]);
    }
    
    header("Location: index.php?page=admin_users");
    exit;
}

if ($action === 'delete') {
    $id = $_GET['id'];
    // Prevent deleting self or primary admin (id=1)
    if ($id != 1 && $id != $_SESSION['user_id']) {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
    header("Location: index.php?page=admin_users");
    exit;
}

// Fetch customers (Rumahan) for linking to Collector accounts
$customers_list = $db->query("SELECT id, name FROM customers WHERE type='customer' ORDER BY name ASC")->fetchAll();
// Fetch partners (Mitra) for linking to Mitra accounts
$partners_list = $db->query("SELECT id, name FROM customers WHERE type='partner' ORDER BY name ASC")->fetchAll();

// Fetch all areas for dropdown
$areas_all = $db->query("SELECT * FROM areas ORDER BY name ASC")->fetchAll();
?>

<?php if ($action === 'list'): ?>
<div class="glass-panel" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
        <h3 style="font-size:20px;"><i class="fas fa-user-shield"></i> Daftar Pengguna / Akses Login</h3>
        <a href="index.php?page=admin_users&action=create" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Tambah Pengguna</a>
    </div>
    
    <div class="table-container">
        <table class="table" style="width:100%">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Nama Pengguna</th>
                    <th>Hak Akses / Role</th>
                    <th>Area (Penagih) / Link (Mitra)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $users = $db->query("SELECT u.*, c.name as partner_name FROM users u LEFT JOIN customers c ON u.customer_id = c.id ORDER BY u.id DESC")->fetchAll();
                foreach($users as $u):
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td>
                        <?php if($u['role']=='admin'): ?>
                            <span class="badge badge-primary">Admin</span>
                        <?php elseif($u['role']=='collector'): ?>
                            <span class="badge badge-warning">Penagih</span>
                        <?php else: ?>
                            <span class="badge badge-success">Mitra</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($u['role']=='collector'): ?>
                            <div style="font-size:11px; margin-bottom:4px;"><i class="fas fa-map-marker-alt"></i> Area: <?= htmlspecialchars($u['area'] && trim($u['area']) != '' ? $u['area'] : 'Semua Area') ?></div>
                        <?php endif; ?>
                        
                        <?php if($u['role']=='partner' || $u['role']=='collector'): ?>
                            <div style="font-size:11px; color:var(--primary); font-weight:600;"><i class="fas fa-link"></i> Link: <?= htmlspecialchars($u['partner_name'] ?? 'Belum terhubung') ?></div>
                        <?php else: ?>
                            <div style="font-size:11px; color:var(--text-secondary);">-</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="index.php?page=admin_users&action=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                        <?php if($u['id'] != 1 && $u['id'] != $_SESSION['user_id']): ?>
                            <a href="index.php?page=admin_users&action=delete&id=<?= $u['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus user ini?')"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'create' || $action === 'edit'): 
    $is_edit = ($action === 'edit');
    $u = null;
    if ($is_edit) {
        $id = $_GET['id'];
        $u = $db->query("SELECT * FROM users WHERE id = " . intval($id))->fetch();
    }
?>
<div class="glass-panel" style="padding: 24px; max-width:500px; margin:0 auto;">
    <h3 style="font-size:20px; margin-bottom:20px;"><?= $is_edit ? 'Edit Pengguna' : 'Tambah Pengguna Baru' ?></h3>
    <form action="index.php?page=admin_users&action=<?= $is_edit ? 'update' : 'add' ?>" method="POST">
        <?php if($is_edit): ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><?php endif; ?>
        
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($u['username'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label>Password <?= $is_edit ? '<small style="color:var(--warning-color)">(Kosongkan jika tidak ingin mengubah password)</small>' : '' ?></label>
            <input type="password" name="password" class="form-control" <?= $is_edit ? '' : 'required' ?>>
        </div>
        <div class="form-group">
            <label>Nama Pengguna / Pegawai</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($u['name'] ?? '') ?>" required>
        </div>
        
        <?php
            $current_role = $u['role'] ?? 'collector';
        ?>
        <div class="form-group">
            <label>Hak Akses / Role</label>
            <select name="role" id="roleSelect" class="form-control" required style="background:rgba(15,23,42,0.8);" onchange="toggleRoleFields()">
                <option value="admin" <?= $current_role=='admin'?'selected':'' ?>>Administrator</option>
                <option value="collector" <?= $current_role=='collector'?'selected':'' ?>>Penagih / Collector</option>
                <option value="partner" <?= $current_role=='partner'?'selected':'' ?>>Mitra (Akses Mandiri)</option>
            </select>
        </div>
        
        <div id="field_area" class="form-group" style="display:none; border:1px dashed var(--border-color); padding: 15px; border-radius:8px;">
            <label style="color:var(--warning-color);"><i class="fas fa-map-marker-alt"></i> Target Area Penagihan</label>
            <select name="area" class="form-control" style="background:rgba(15,23,42,0.8);">
                <option value="">-- Semua Area (Akses Penuh) --</option>
                <?php foreach($areas_all as $a): ?>
                    <option value="<?= htmlspecialchars($a['name']) ?>" <?= ($u['area'] ?? '') == $a['name'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                <?php endforeach; ?>
                <?php if(!empty($u['area']) && !in_array($u['area'], array_column($areas_all, 'name'))): ?>
                    <option value="<?= htmlspecialchars($u['area']) ?>" selected><?= htmlspecialchars($u['area']) ?> (Legacy)</option>
                <?php endif; ?>
            </select>
            <small style="color:var(--text-secondary); display:block; margin-top:5px;">Hanya penagih dengan area yang sama dengan data pelanggan yang dapat menagih pelanggan tersebut. Pilih "Semua Area" jika ingin ia bisa menagih di MANA SAJA.</small>
        </div>
        
        <div id="field_customer_link" class="form-group" style="display:none; border:1px dashed var(--glass-border); padding: 15px; border-radius:8px;">
            <label style="color:var(--primary);" id="link_label"><i class="fas fa-link"></i> Tautkan ke Data Pelanggan</label>
            <select name="customer_id" id="customerIdSelect" class="form-control" style="background:rgba(15,23,42,0.8);">
                <option value="">-- Pilih --</option>
            </select>
            <small id="link_hint" style="color:var(--text-secondary); display:block; margin-top:5px;">Tautkan akun ini ke satu profil pelanggan.</small>
        </div>
        <script>
        var _customersList = <?= json_encode(array_map(function($c){ return ['id'=>$c['id'],'name'=>$c['name']]; }, $customers_list)) ?>;
        var _partnersList = <?= json_encode(array_map(function($p){ return ['id'=>$p['id'],'name'=>$p['name']]; }, $partners_list)) ?>;
        var _currentLinkedId = <?= json_encode($u['customer_id'] ?? '') ?>;
        </script>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
            <a href="index.php?page=admin_users" class="btn btn-ghost">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
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
