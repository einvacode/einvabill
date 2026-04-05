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

// Fetch Basic Stats for non-PHP blocks
$stats_raw = $db->query("SELECT type, COUNT(*) as count FROM infrastructure_assets GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);

// Recursive Function to Build Network Tree
function buildNetworkTree($db, $parentId = 0) {
    if ($parentId == 0) {
        $stmt = $db->prepare("SELECT a.*, (SELECT COUNT(*) FROM customers WHERE odp_id = a.id) as cust_count FROM infrastructure_assets a WHERE parent_id = 0 ORDER BY type ASC, name ASC");
    } else {
        $stmt = $db->prepare("SELECT a.*, (SELECT COUNT(*) FROM customers WHERE odp_id = a.id) as cust_count FROM infrastructure_assets a WHERE parent_id = ? ORDER BY type ASC, name ASC");
    }
    
    if ($parentId == 0) $stmt->execute();
    else $stmt->execute([$parentId]);
    
    $assets = $stmt->fetchAll();
    $tree = [];
    
    foreach ($assets as $asset) {
        $children = buildNetworkTree($db, $asset['id']);
        
        // Calculate Total Active Downstream (Recursive)
        $total_child_usage = 0;
        foreach($children as $child) {
            $total_child_usage += $child['total_active_downstream'];
        }
        
        $asset['children'] = $children;
        $asset['total_active_downstream'] = $asset['cust_count'] + $total_child_usage;
        $tree[] = $asset;
    }
    return $tree;
}

// Enhanced Stats Calculation
$total_investment = $db->query("SELECT SUM(price) FROM infrastructure_assets")->fetchColumn() ?: 0;
$total_ports_capacity = $db->query("SELECT SUM(total_ports) FROM infrastructure_assets")->fetchColumn() ?: 0;
$used_by_customers = $db->query("SELECT COUNT(*) FROM customers WHERE odp_id > 0")->fetchColumn() ?: 0;
$used_by_child_assets = $db->query("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id > 0")->fetchColumn() ?: 0;
$total_ports_used = $used_by_customers + $used_by_child_assets;
$idle_ports = $total_ports_capacity - $total_ports_used;
$utilization_pct = ($total_ports_capacity > 0) ? ($total_ports_used / $total_ports_capacity) * 100 : 0;
?>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px;">
    <div class="glass-panel" style="padding:20px; border-left:4px solid var(--primary); display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Total Valuasi Aset</div>
        <div style="font-size:24px; font-weight:800; color:var(--text-primary);">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Total belanja infrastruktur</div>
    </div>
    <div class="glass-panel" style="padding:20px; border-left:4px solid var(--success); display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Utilisasi Port Global</div>
        <div style="font-size:24px; font-weight:800; color:var(--success);"><?= round($utilization_pct, 1) ?><span style="font-size:14px;">%</span></div>
        <div style="width:100%; height:4px; background:rgba(255,255,255,0.05); border-radius:10px; margin-top:10px; overflow:hidden;">
            <div style="width:<?= $utilization_pct ?>%; height:100%; background:var(--success);"></div>
        </div>
    </div>
    <div class="glass-panel" style="padding:24px; border-left:4px solid #f59e0b; display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Kapasitas PORT Idle</div>
        <div style="font-size:24px; font-weight:800; color:#f59e0b;"><?= $idle_ports ?> <span style="font-size:14px; color:var(--text-secondary); font-weight:normal;">Port</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Siap digunakan pelanggan baru</div>
    </div>
    <div class="glass-panel" style="padding:20px; border-left:4px solid #a855f7; display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Total Perangkat</div>
        <div style="font-size:24px; font-weight:800; color:#a855f7;"><?= array_sum($stats_raw) ?> <span style="font-size:14px; color:var(--text-secondary); font-weight:normal;">Unit</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">OLT, ODC, ODP & Lainnya</div>
    </div>
</div>

<div class="glass-panel" style="padding:25px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:15px;">
        <h3 style="margin:0;"><i class="fas fa-boxes text-primary"></i> Operasi Infrastruktur Jaringan</h3>
        <div style="display:flex; gap:10px;">
            <div class="view-toggle" style="background:rgba(255,255,255,0.05); padding:4px; border-radius:10px; display:flex;">
                <button class="btn btn-sm <?= ($_GET['view']??'table') == 'table' ? 'btn-primary' : 'btn-ghost' ?>" onclick="location.href='index.php?page=admin_assets&view=table'">
                    <i class="fas fa-table"></i> Daftar
                </button>
                <button class="btn btn-sm <?= ($_GET['view']??'') == 'tree' ? 'btn-primary' : 'btn-ghost' ?>" onclick="location.href='index.php?page=admin_assets&view=tree'">
                    <i class="fas fa-network-wired"></i> Topologi
                </button>
            </div>
            <button class="btn btn-primary" onclick="showAssetModal()"><i class="fas fa-plus"></i> Tambah Aset</button>
        </div>
    </div>

    <?php if(($_GET['view']??'table') === 'table'): ?>
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
    <?php else: ?>
    <!-- Network Topology Tree View -->
    <div class="network-tree-container" style="padding:10px 0;">
        <?php
        $tree = buildNetworkTree($db);

        if (!function_exists('getCustomersForAsset')) {
            function getCustomersForAsset($db, $assetId) {
                $stmt = $db->prepare("SELECT name, customer_code FROM customers WHERE odp_id = ? ORDER BY name ASC");
                $stmt->execute([$assetId]);
                return $stmt->fetchAll();
            }
        }

        if (!function_exists('renderTreeItem')) {
            function renderTreeItem($db, $item, $level = 0) {
            $usage_pct = ($item['total_ports'] > 0) ? ($item['total_active_downstream'] / $item['total_ports']) * 100 : 0;
            $color = 'var(--primary)';
            if($item['type'] == 'ODC') $color = '#a855f7';
            if($item['type'] == 'ODP') $color = '#ec4899';
            if($item['type'] == 'Router') $color = '#f59e0b';
            
            $icon = 'fa-server';
            if($item['type'] == 'ODC') $icon = 'fa-boxes-stacked';
            if($item['type'] == 'ODP') $icon = 'fa-plug-circle-bolt';
            if($item['type'] == 'Router') $icon = 'fa-router';

            echo '<div class="tree-item" style="margin-left:' . ($level * 35) . 'px; border-left: 2px solid rgba(255,255,255,0.05); padding-left: 25px; position:relative; margin-bottom:15px;">';
            if($level > 0) {
                echo '<div style="position:absolute; left:0; top:35px; width:25px; height:2px; background:rgba(255,255,255,0.05);"></div>';
            }
            
            echo '<div class="glass-panel" style="padding:15px 20px; display:flex; justify-content:space-between; align-items:center; border-left:4px solid ' . $color . '; min-height:80px; transition:all 0.2s;">';
            
            echo '<div style="display:flex; align-items:center; gap:20px;">';
            echo '<div style="width:48px; height:48px; background:' . $color . '15; color:' . $color . '; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:20px;"><i class="fas ' . $icon . '"></i></div>';
            echo '<div>';
            echo '<div style="font-weight:700; font-size:16px; color:var(--text-primary);">' . htmlspecialchars($item['name']) . ' <span style="font-size:11px; opacity:0.5; font-weight:normal; margin-left:8px; text-transform:uppercase;">' . $item['type'] . '</span></div>';
            echo '<div style="font-size:12px; color:var(--text-secondary); margin-top:4px;"><i class="fas fa-network-wired" style="font-size:10px; margin-right:5px;"></i> ' . $item['total_active_downstream'] . ' Total Jalur Aktif</div>';
            echo '</div>';
            echo '</div>';
            
            echo '<div style="display:flex; align-items:center; gap:25px;">';
            echo '<div style="text-align:right; width:120px;">';
            echo '<div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-secondary); margin-bottom:6px;">';
            echo '<span>Utilisasi Port</span>';
            echo '<span style="font-weight:800; color:' . ($usage_pct > 85 ? 'var(--danger)' : 'var(--text-primary)') . '">' . round($usage_pct) . '%</span>';
            echo '</div>';
            echo '<div style="width:100%; height:8px; background:rgba(255,255,255,0.05); border-radius:4px; overflow:hidden;">';
            echo '<div style="width:' . $usage_pct . '%; height:100%; background:' . ($usage_pct > 85 ? 'var(--danger)' : 'var(--success)') . '; box-shadow: 0 0 10px ' . ($usage_pct > 85 ? 'var(--danger)' : 'var(--success)') . '44;"></div>';
            echo '</div>';
            echo '</div>';
            
            echo '<div style="display:flex; gap:8px;">';
            if($item['lat'] && $item['lng']) {
                echo '<a href="index.php?page=admin_map&lat=' . $item['lat'] . '&lng=' . $item['lng'] . '" class="btn btn-sm btn-ghost" title="Lihat di Peta" style="color:#06b6d4;"><i class="fas fa-location-dot"></i></a>';
            }
            echo '<button class="btn btn-sm btn-ghost" style="color:var(--text-secondary);" onclick=\'editAsset(' . json_encode($item) . ')\'><i class="fas fa-edit"></i></button>';
            echo '</div>';
            echo '</div>';
            
            echo '</div>'; // end glass-panel

            // List Customers if it's an ODP or has customers
            $customers = getCustomersForAsset($db, $item['id']);
            if(!empty($customers)) {
                echo '<div style="margin-left: 68px; margin-top: -10px; margin-bottom: 20px; font-size: 11px; padding: 10px 15px; background: rgba(255,255,255,0.03); border-radius: 0 0 12px 12px; border: 1px solid rgba(255,255,255,0.05); border-top:none;">';
                echo '<div style="color:var(--text-secondary); margin-bottom:5px; font-weight:700;"><i class="fas fa-users-viewfinder"></i> PELANGGAN TERHUBUNG:</div>';
                foreach($customers as $c) {
                    echo '<div style="display:inline-block; margin-right:15px; color:var(--text-primary);"><i class="fas fa-user" style="font-size:9px; opacity:0.5;"></i> ' . htmlspecialchars($c['name']) . ' (' . $c['customer_code'] . ')</div>';
                }
                echo '</div>';
            }
            
            if(!empty($item['children'])) {
                foreach($item['children'] as $child) {
                    renderTreeItem($db, $child, $level + 1);
                }
            }
            echo '</div>'; // end tree-item
        }
    }

    foreach($tree as $root) renderTreeItem($db, $root);
        
        if(empty($tree)) {
            echo '<div style="text-align:center; padding:80px; color:var(--text-secondary); opacity:0.6;">';
            echo '<i class="fas fa-network-wired" style="font-size:60px; margin-bottom:20px; display:block; opacity:0.1;"></i> Belum ada infrastruktur terdaftar atau periksa filter Parent.';
            echo '</div>';
        }
        ?>
    </div>
    <?php endif; ?>
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
