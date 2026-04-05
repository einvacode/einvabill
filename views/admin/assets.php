<?php
// Handle Asset Actions
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add' || $action === 'edit') {
        $name = $_POST['name'];
        $type = $_POST['type'];
        $parent_id = $_POST['parent_id'] ?? 0;
        $lat = $_POST['lat'] ?? '';
        $lng = $_POST['lng'] ?? '';
        $total_ports = $_POST['total_ports'] ?? 8;
        $brand = $_POST['brand'] ?? '';
        $description = $_POST['description'] ?? '';
        $price = $_POST['price'] ?? 0;
        $status = $_POST['status'] ?? 'Deployed';
        $installation_date = $_POST['installation_date'] ?? date('Y-m-d');

        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO infrastructure_assets (name, type, parent_id, lat, lng, total_ports, brand, description, price, status, installation_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $parent_id, $lat, $lng, $total_ports, $brand, $description, $price, $status, $installation_date]);
            $success = "Aset berhasil ditambahkan.";
        } else {
            $id = $_POST['id'];
            $stmt = $db->prepare("UPDATE infrastructure_assets SET name=?, type=?, parent_id=?, lat=?, lng=?, total_ports=?, brand=?, description=?, price=?, status=?, installation_date=? WHERE id=?");
            $stmt->execute([$name, $type, $parent_id, $lat, $lng, $total_ports, $brand, $description, $price, $status, $installation_date, $id]);
            $success = "Aset berhasil diperbarui.";
        }
    }
}

if ($action === 'delete') {
    $id = $_GET['id'];
    $db->prepare("DELETE FROM infrastructure_assets WHERE id = ?")->execute([$id]);
    header("Location: index.php?page=admin_assets");
    exit;
}

// Fetch Stats
$stats_raw = $db->query("SELECT type, COUNT(*) as count FROM infrastructure_assets GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
$total_ports_used = $db->query("SELECT (SELECT COUNT(*) FROM customers WHERE odp_id > 0) + (SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id > 0)")->fetchColumn();
$total_investment = $db->query("SELECT SUM(price) FROM infrastructure_assets")->fetchColumn() ?: 0;
?>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:15px; margin-bottom:30px;">
    <div class="glass-panel" style="padding:15px; text-align:center; border-left:4px solid var(--primary);">
        <div style="font-size:10px; color:var(--text-secondary); margin-bottom:5px;">OLT / ODC / ODP</div>
        <div style="font-size:20px; font-weight:800;"><?= ($stats_raw['OLT']??0) + ($stats_raw['ODC']??0) + ($stats_raw['ODP']??0) ?></div>
    </div>
    <div class="glass-panel" style="padding:15px; text-align:center; border-left:4px solid #f59e0b;">
        <div style="font-size:10px; color:var(--text-secondary); margin-bottom:5px;">ROUTER & SWITCH</div>
        <div style="font-size:20px; font-weight:800;"><?= ($stats_raw['Router']??0) + ($stats_raw['Switch']??0) ?></div>
    </div>
    <div class="glass-panel" style="padding:15px; text-align:center; border-left:4px solid #06b6d4;">
        <div style="font-size:10px; color:var(--text-secondary); margin-bottom:5px;">WIRELESS & SERVER</div>
        <div style="font-size:20px; font-weight:800;"><?= ($stats_raw['Wireless']??0) + ($stats_raw['Server']??0) ?></div>
    </div>
    <div class="glass-panel" style="padding:15px; text-align:center; border-left:4px solid var(--success);">
        <div style="font-size:10px; color:var(--text-secondary); margin-bottom:5px;">PORT TERPAKAI</div>
        <div style="font-size:20px; font-weight:800;"><?= $total_ports_used ?></div>
    </div>
    <div class="glass-panel" style="padding:15px; text-align:center; border-left:4px solid #ec4899;">
        <div style="font-size:10px; color:var(--text-secondary); margin-bottom:5px;">TOTAL INVESTASI</div>
        <div style="font-size:18px; font-weight:800;">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
    </div>
</div>

<div class="glass-panel" style="padding:25px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3 style="margin:0;"><i class="fas fa-boxes text-primary"></i> Daftar Inventaris Aset</h3>
        <button class="btn btn-primary" onclick="showAssetModal()"><i class="fas fa-plus"></i> Tambah Aset</button>
    </div>

    <div class="table-container shadow-sm">
        <table>
            <thead>
                <tr>
                    <th>Nama Aset</th>
                    <th>Tipe / Status</th>
                    <th>Induk (Uplink)</th>
                    <th>Kapasitas</th>
                    <th>Nilai Aset (Rp)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id ORDER BY a.type DESC, a.name ASC")->fetchAll();
                foreach($assets as $a):
                    // Hitung Penggunaan Port Fisik (Hanya 1 Tingkat / Direct)
                    $usage_cust = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ?");
                    $usage_cust->execute([$a['id']]);
                    $direct_cust_count = $usage_cust->fetchColumn();
                    
                    $usage_child = $db->prepare("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id = ?");
                    $usage_child->execute([$a['id']]);
                    $direct_asset_count = $usage_child->fetchColumn();
                    
                    $current_usage = $direct_cust_count + $direct_asset_count;
                    $usage_pct = ($a['total_ports'] > 0) ? ($current_usage / $a['total_ports']) * 100 : 0;
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;"><?= htmlspecialchars($a['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary);"><?= htmlspecialchars($a['brand'] ?: 'Generic') ?></div>
                    </td>
                    <td>
                        <?php 
                        $bgColor = 'var(--primary)';
                        if($a['type'] == 'ODC') $bgColor = '#a855f7';
                        if($a['type'] == 'ODP') $bgColor = '#ec4899';
                        if($a['type'] == 'Router') $bgColor = '#f59e0b';
                        if($a['type'] == 'Switch') $bgColor = '#6366f1';
                        if($a['type'] == 'Wireless') $bgColor = '#06b6d4';
                        if($a['type'] == 'Server') $bgColor = '#4b5563';
                        if($a['type'] == 'ONU') $bgColor = '#10b981';
                        ?>
                        <span class="badge" style="background:<?= $bgColor ?>; color:white;">
                            <?= $a['type'] ?>
                        </span>
                    </td>
                    <td style="font-size:13px;"><?= htmlspecialchars($a['parent_name'] ?: 'ROOT') ?></td>
                    <td>
                        <div style="width:100px; height:8px; background:rgba(255,255,255,0.1); border-radius:4px; margin-bottom:5px; overflow:hidden;">
                            <div style="width:<?= $usage_pct ?>%; height:100%; background:<?= $usage_pct > 90 ? 'var(--danger)' : 'var(--success)' ?>;"></div>
                        </div>
                        <div style="font-size:11px; font-weight:700;">Penggunaan: <?= $current_usage ?> / <?= $a['total_ports'] ?> Port</div>
                        <div style="font-size:10px; color:var(--text-secondary); margin-top:2px;">
                            <i class="fas fa-link"></i> <?= $direct_asset_count ?> Cabang, <?= $direct_cust_count ?> Pelanggan
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:700; color:var(--success);">Rp <?= number_format($a['price'], 0, ',', '.') ?></div>
                        <div style="font-size:10px; color:var(--text-secondary);"><?= $a['installation_date'] ? 'Pasang: '.$a['installation_date'] : '-' ?></div>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-ghost" onclick='editAsset(<?= json_encode($a) ?>)'><i class="fas fa-edit"></i></button>
                        <a href="index.php?page=admin_assets&action=delete&id=<?= $a['id'] ?>" class="btn btn-sm btn-ghost" style="color:var(--danger);" onclick="return confirm('Hapus aset ini?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($assets)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:50px; color:var(--text-secondary);"><i class="fas fa-info-circle"></i> Belum ada aset terdaftar.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Asset Modal -->
<div id="assetModal" class="modal" style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); backdrop-filter:blur(10px);">
    <div class="glass-panel" style="width:90%; max-width:500px; margin:5% auto; padding:30px;">
        <h3 id="modalTitle" style="margin-bottom:20px;">Tambah Aset Baru</h3>
        <form method="POST" id="assetForm">
            <input type="hidden" name="id" id="asset_id">
            <div class="form-group">
                <label>Nama Perangkat (Contoh: ODP-JL-MAWAR-01)</label>
                <input type="text" name="name" id="asset_name" class="form-control" required>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Tipe</label>
                    <select name="type" id="asset_type" class="form-control" required>
                        <option value="OLT">OLT (Pusat)</option>
                        <option value="ODC">ODC (Cabinet)</option>
                        <option value="ODP">ODP (Pelanggan)</option>
                        <option value="Router">Router (MikroTik/Lainnya)</option>
                        <option value="Switch">Switch (L2/L3)</option>
                        <option value="Wireless">Wireless (AP/Radio)</option>
                        <option value="Server">Server</option>
                        <option value="ONU">ONU (Modem Pelanggan)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Uplink (Parent)</label>
                    <select name="parent_id" id="asset_parent" class="form-control">
                        <option value="0">TIDAK ADA / ROOT</option>
                        <?php 
                        $parents = $db->query("SELECT id, name, type FROM infrastructure_assets WHERE type != 'ODP' ORDER BY type DESC")->fetchAll();
                        foreach($parents as $p) echo "<option value='{$p['id']}'>{$p['type']} - {$p['name']}</option>";
                        ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Total Port</label>
                    <input type="number" name="total_ports" id="asset_ports" class="form-control" value="8">
                </div>
                <div class="form-group">
                    <label>Brand/Merk</label>
                    <input type="text" name="brand" id="asset_brand" class="form-control" placeholder="ZTE / Huawei">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Latitude</label>
                    <input type="text" name="lat" id="asset_lat" class="form-control">
                </div>
                <div class="form-group">
                    <label>Longitude</label>
                    <input type="text" name="lng" id="asset_lng" class="form-control">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Harga Beli (Rp)</label>
                    <input type="number" name="price" id="asset_price" class="form-control" value="0">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="asset_status" class="form-control">
                        <option value="Deployed">Terpasang (Deployed)</option>
                        <option value="Stock">Gudang (Stock)</option>
                        <option value="Repair">Rusak (Repair)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Tanggal Pemasangan</label>
                <input type="date" name="installation_date" id="asset_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-ghost" onclick="closeAssetModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Simpan Aset</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAssetModal() {
// ...
// ...
    document.getElementById('assetForm').action = 'index.php?page=admin_assets&action=add';
    document.getElementById('modalTitle').innerText = 'Tambah Aset Baru';
    document.getElementById('asset_id').value = '';
    document.getElementById('assetForm').reset();
    document.getElementById('assetModal').style.display = 'block';
}
function closeAssetModal() {
    document.getElementById('assetModal').style.display = 'none';
}
function editAsset(a) {
    document.getElementById('assetForm').action = 'index.php?page=admin_assets&action=edit';
    document.getElementById('modalTitle').innerText = 'Edit Aset';
    document.getElementById('asset_id').value = a.id;
    document.getElementById('asset_name').value = a.name;
    document.getElementById('asset_type').value = a.type;
    document.getElementById('asset_parent').value = a.parent_id;
    document.getElementById('asset_ports').value = a.total_ports;
    document.getElementById('asset_brand').value = a.brand;
    document.getElementById('asset_lat').value = a.lat;
    document.getElementById('asset_lng').value = a.lng;
    document.getElementById('asset_price').value = a.price;
    document.getElementById('asset_status').value = a.status;
    document.getElementById('asset_date').value = a.installation_date;
    document.getElementById('assetModal').style.display = 'block';
}
</script>
