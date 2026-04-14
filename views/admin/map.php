<?php
// Fetch all packages for dropdowns
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$packages_all = $db->query("SELECT * FROM packages WHERE tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();

// Handle Quick Actions from Map
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_action'])) {
    if ($_POST['quick_action'] === 'add_asset') {
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $stmt = $db->prepare("INSERT INTO infrastructure_assets (name, type, parent_id, lat, lng, total_ports, price, status, installation_date, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'Deployed', ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['type'], $_POST['parent_id'] ?? 0, $_POST['lat'], $_POST['lng'], $_POST['ports'], $_POST['price'], date('Y-m-d'), $tenant_id]);
        header("Location: index.php?page=admin_map&success=asset"); exit;
    }
        if (!empty($_POST['customer_id']) && $_POST['customer_id'] > 0) {
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            // Update Existing Customer Location
            $stmt = $db->prepare("UPDATE customers SET lat = ?, lng = ?, odp_id = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$_POST['lat'], $_POST['lng'], $_POST['odp_id'] ?? 0, $_POST['customer_id'], $tenant_id]);
            header("Location: index.php?page=admin_map&success=customer_update"); exit;
        } else {
            // Fetch Package Details
            $pkg_name = 'Manual';
            $pkg_fee = 0;
            if(!empty($_POST['package_id'])) {
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $pkg = $db->prepare("SELECT name, fee FROM packages WHERE id = ? AND tenant_id = ?");
                $pkg->execute([$_POST['package_id'], $tenant_id]);
                $p = $pkg->fetch();
                if($p) {
                    $pkg_name = $p['name'];
                    $pkg_fee = $p['fee'];
                }
            }
            $billing_date = intval($_POST['billing_date'] ?? 1);
            $type = $_POST['type'] ?? 'customer';

            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, registration_date, billing_date, lat, lng, type, odp_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$customer_code, $_POST['name'], $_POST['address'], $_POST['contact'], $pkg_name, $pkg_fee, date('Y-m-d'), $billing_date, $_POST['lat'], $_POST['lng'], $type, $_POST['odp_id'] ?? 0, $tenant_id]);
            $new_cust_id = $db->lastInsertId();

            // SELECTIVE AUTOMATIC PAYMENT ON REGISTRATION
            if($pkg_fee > 0) {
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                if($type === 'customer') {
                    // RUMAHAN: Tagihan Terbit di Hari Registrasi (Belum Lunas - perlu konfirmasi manual)
                    $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', ?, ?)");
                    $reg_date = date('Y-m-d');
                    $stmt_inv->execute([$new_cust_id, $pkg_fee, $reg_date, date('Y-m-d H:i:s'), $tenant_id]);
                } else {
                    // MITRA: Belum Lunas (Bulan Depan)
                    $next_month = date('Y-m', strtotime("+1 month"));
                    $bday = str_pad($billing_date, 2, '0', STR_PAD_LEFT);
                    $due_date = "{$next_month}-{$bday}";
                    $stmt_inv = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', ?, ?)");
                    $stmt_inv->execute([$new_cust_id, $pkg_fee, $due_date, date('Y-m-d H:i:s'), $tenant_id]);
                }
            }

            header("Location: index.php?page=admin_map&success=customer"); exit;
        }
    }
    
    // Position Updates (AJAX)
    if ($_POST['quick_action'] === 'update_pos_asset' || $_POST['quick_action'] === 'update_pos_customer') {
        $id = $_POST['id'];
        $lat = $_POST['lat'];
        $lng = $_POST['lng'];
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        
        if ($_POST['quick_action'] === 'update_pos_asset') {
            $stmt = $db->prepare("UPDATE infrastructure_assets SET lat = ?, lng = ? WHERE id = ? AND tenant_id = ?");
        } else {
            $stmt = $db->prepare("UPDATE customers SET lat = ?, lng = ? WHERE id = ? AND tenant_id = ?");
        }
        $stmt->execute([$lat, $lng, $id, $tenant_id]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    // Uplink Re-routing (AJAX)
    if ($_POST['quick_action'] === 'change_uplink_asset' || $_POST['quick_action'] === 'change_uplink_customer') {
        $id = $_POST['id'];
        $new_parent = $_POST['new_parent'];
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        
        if ($_POST['quick_action'] === 'change_uplink_asset') {
            $stmt = $db->prepare("UPDATE infrastructure_assets SET parent_id = ? WHERE id = ? AND tenant_id = ?");
        } else {
            $stmt = $db->prepare("UPDATE customers SET odp_id = ? WHERE id = ? AND tenant_id = ?");
        }
        $stmt->execute([$new_parent, $id, $tenant_id]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }

    // Path Vertices Update (AJAX)
    if ($_POST['quick_action'] === 'update_path_asset' || $_POST['quick_action'] === 'update_path_customer') {
        $id = $_POST['id'];
        $path = $_POST['path_json']; // Expecting JSON string of [lat,lng] arrays
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        
        if ($_POST['quick_action'] === 'update_path_asset') {
            $stmt = $db->prepare("UPDATE infrastructure_assets SET path_json = ? WHERE id = ? AND tenant_id = ?");
        } else {
            $stmt = $db->prepare("UPDATE customers SET path_json = ? WHERE id = ? AND tenant_id = ?");
        }
        $stmt->execute([$path, $id, $tenant_id]);
        
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// Pre-generate Uplink Options for JS Popups
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$asset_opts_raw = $db->query("SELECT id, name, type FROM infrastructure_assets WHERE type != 'ONU' AND tenant_id = $tenant_id ORDER BY type DESC, name ASC")->fetchAll();
$asset_options_html = "<option value='0'>Pusat / Root</option>";
foreach($asset_opts_raw as $opt) {
    $asset_options_html .= "<option value='{$opt['id']}'>{$opt['type']}: {$opt['name']}</option>";
}
$odp_opts_raw = $db->query("SELECT id, name, type FROM infrastructure_assets WHERE type NOT IN ('ONU') AND tenant_id = $tenant_id ORDER BY type DESC, name ASC")->fetchAll();
$odp_options_html = "<option value='0'>Tanpa Jalur Terpusat</option>";
foreach($odp_opts_raw as $opt) {
    if($opt['type'] == 'ODP') {
        $odp_options_html .= "<option value='{$opt['id']}'>ODP: {$opt['name']}</option>";
    } else {
        $odp_options_html .= "<option value='{$opt['id']}'>PtP ({$opt['type']}): {$opt['name']}</option>";
    }
}
$u_id_map = $_SESSION['user_id'];
$u_role_map = $_SESSION['user_role'] ?? 'admin';
$tenant_id_map = $_SESSION['tenant_id'] ?? 1;
$scope_where_map = " AND tenant_id = $tenant_id_map ";

$assets = $db->query("SELECT * FROM infrastructure_assets WHERE lat IS NOT NULL AND lat != '' AND tenant_id = $tenant_id_map")->fetchAll();
$customers = $db->query("SELECT * FROM customers WHERE lat IS NOT NULL AND lat != '' $scope_where_map")->fetchAll();

// Fetch Registered Customers without Coordinates for the Picker
$existing_customers = $db->query("SELECT id, name, customer_code FROM customers WHERE (lat IS NULL OR lat = '') $scope_where_map ORDER BY name ASC")->fetchAll();
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<?php if(isset($_GET['success'])): ?>
    <div id="notif" class="glass-panel" style="position:fixed; top:80px; right:20px; z-index:2000; padding:15px 25px; background:var(--success); color:white; border-radius:12px; animation: slideIn 0.3s ease;">
        <i class="fas fa-check-circle"></i> Berhasil menambahkan <?= $_GET['success'] == 'asset' ? 'Aset' : 'Pelanggan' ?> baru! 
    </div>
    <script>setTimeout(() => { document.getElementById('notif').style.display='none'; }, 3000);</script>
<?php endif; ?>

<div class="glass-panel" style="padding:0; overflow:hidden; position:relative; height: calc(100vh - 150px); min-height: 500px;">
    <!-- Map Control HUD -->
    <div style="position:absolute; top:20px; right:20px; z-index:1000; background:rgba(0,0,0,0.6); padding:15px; border-radius:12px; backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.1); width:220px;">
        <h4 style="margin:0 0 10px 0; font-size:14px; color:white;"><i class="fas fa-layer-group"></i> Legend Jaringan</h4>
        <div style="display:flex; flex-direction:column; gap:8px; font-size:12px; color:rgba(255,255,255,0.9);">
                <div style="display:flex; align-items:center; gap:8px;"><span style="width:18px; height:18px; background:#ef4444; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:10px; color:white; border:1.5px solid white;">M</span> Pusat (OLT/Router)</div>
                <div style="display:flex; align-items:center; gap:8px;"><span style="width:18px; height:18px; background:#0ea5e9; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:10px; color:white; border:1.5px solid white;">S</span> Sub (ODP/Pelanggan)</div>
            <hr style="border:0; border-top:1px solid rgba(255,255,255,0.2); margin:5px 0;">
            <div style="display:flex; align-items:center; gap:8px;"><span style="width:15px; height:3px; background:#0ea5e9; box-shadow: 0 0 5px #0ea5e9;"></span> Jalur Aktif</div>
        </div>
        <div style="margin-top:15px; font-size:10px; color:rgba(255,255,255,0.6); text-align:center;">
            Mode Satelit Hybrid
        </div>
        <div style="margin-top:10px;">
            <button class="btn btn-sm" style="width:100%; font-size:11px; background:rgba(255,255,255,0.2); color:white; border:none;" onclick="centerMap()">Center Map</button>
        </div>
    </div>

    <!-- Map Container -->
    <div id="network-map" style="width:100%; height:100%;"></div>
</div>

<!-- Modal Quick Add -->
<div id="quickModal" class="modal" style="display:none; position:fixed; z-index:2001; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); backdrop-filter:blur(5px);">
    <div class="glass-panel" style="width:90%; max-width:400px; margin:10% auto; padding:25px;">
        <h3 id="mTitle" style="margin-bottom:15px; font-size:18px;">Tambah Objek</h3>
        <form method="POST" id="qForm">
            <input type="hidden" name="quick_action" id="qAction">
            <input type="hidden" name="lat" id="qLat">
            <input type="hidden" name="lng" id="qLng">
            
            <div style="font-size:10px; color:var(--text-secondary); margin-bottom:15px; padding:8px; background:rgba(255,255,255,0.05); border-radius:6px; border-left:3px solid var(--primary);">
                <i class="fas fa-info-circle"></i> Tips: Setelah dipasang, anda bisa menggeser posisi ikon di peta untuk mencari lokasi yang lebih pas.
            </div>
            
            <div id="fields_asset">
                <div class="form-group">
                    <label>Nama Aset (OLT/ODC/ODP)</label>
                    <input type="text" name="name" class="form-control" placeholder="Contoh: ODP-01">
                </div>
                <div class="form-group">
                    <label>Tipe</label>
                    <select name="type" class="form-control">
                        <option value="ODP">ODP (Kotak)</option>
                        <option value="ODC">ODC (Cabinet)</option>
                        <option value="OLT">OLT (Pusat)</option>
                        <option value="Router">Router (MikroTik/Lainnya)</option>
                        <option value="Switch">Switch (L2/L3)</option>
                        <option value="Wireless">Wireless (AP/Radio)</option>
                        <option value="Server">Server</option>
                        <option value="ONU">ONU (Modem)</option>
                    </select>
                </div>
                <div class="flex" style="gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Port</label>
                        <input type="number" name="ports" class="form-control" value="8">
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label>Uplink (Sumber)</label>
                        <select name="parent_id" class="form-control">
                            <option value="0">Pusat / Root</option>
                            <?php 
                            $tenant_id = $_SESSION['tenant_id'] ?? 1;
                            $parents = $db->query("SELECT id, name, type FROM infrastructure_assets WHERE type != 'ODP' AND tenant_id = $tenant_id ORDER BY type DESC, name ASC")->fetchAll();
                            foreach($parents as $p) echo "<option value='{$p['id']}'>{$p['type']}: {$p['name']}</option>";
                            ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Harga Beli (Rp)</label>
                    <input type="number" name="price" class="form-control" value="0">
                </div>
            </div>

            <div id="fields_customer" style="display:none;">
                <div style="background:rgba(255,255,255,0.05); padding:10px; border-radius:8px; margin-bottom:15px; border:1px solid rgba(255,255,255,0.1); display:flex; gap:15px;">
                    <label style="cursor:pointer; display:flex; align-items:center; gap:5px; font-size:12px;">
                        <input type="radio" name="cust_mode" value="new" checked onclick="toggleCustMode('new')"> Daftar Baru
                    </label>
                    <label style="cursor:pointer; display:flex; align-items:center; gap:5px; font-size:12px;">
                        <input type="radio" name="cust_mode" value="existing" onclick="toggleCustMode('existing')"> Ambil dari Data
                    </label>
                </div>

                <div id="new_cust_fields">
                    <div class="form-group">
                        <label>Nama Pelanggan / Mitra</label>
                        <input type="text" name="name" class="form-control" placeholder="Nama Lengkap">
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:15px;">
                        <div class="form-group">
                            <label>Tipe</label>
                            <select name="type" class="form-control">
                                <option value="customer">Rumahan</option>
                                <option value="partner">Mitra</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Nomor WhatsApp</label>
                            <input type="text" name="contact" class="form-control" placeholder="08xxx">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Alamat Lengkap</label>
                        <input type="text" name="address" class="form-control" placeholder="Detail Alamat">
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:15px;">
                        <div class="form-group">
                            <label>Paket Layanan</label>
                            <select name="package_id" class="form-control">
                                <option value="">-- Pilih Paket --</option>
                                <?php foreach($packages_all as $pkg): ?>
                                    <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rp <?= number_format($pkg['fee'],0,',','.') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tanggal Tagihan (1-28)</label>
                            <input type="number" name="billing_date" class="form-control" min="1" max="28" value="1">
                        </div>
                    </div>
                </div>

                <div id="existing_cust_fields" style="display:none;">
                    <div class="form-group">
                        <label>Pilih Pelanggan</label>
                        <select name="customer_id" class="form-control">
                            <option value="0">-- Pilih Pelanggan --</option>
                            <?php foreach($existing_customers as $ec): ?>
                                <option value="<?= $ec['id'] ?>"><?= htmlspecialchars($ec['name']) ?> (<?= $ec['customer_code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Sumber Koneksi (Sumber Alat)</label>
                    <select name="odp_id" class="form-control">
                        <option value="0">-- Pilih Sumber --</option>
                        <?php 
                        $tenant_id = $_SESSION['tenant_id'] ?? 1;
                        $opts = $db->query("SELECT id, name, type FROM infrastructure_assets WHERE tenant_id = $tenant_id ORDER BY type DESC, name ASC")->fetchAll();
                        foreach($opts as $o) echo "<option value='{$o['id']}'>{$o['type']}: {$o['name']}</option>";
                        ?>
                    </select>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-ghost" onclick="closeQuickModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Sekarang</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Initial View (Handle Redirects from Assets)
    const initialLat = <?= $_GET['lat'] ?? -6.200000 ?>;
    const initialLng = <?= $_GET['lng'] ?? 106.816666 ?>;
    const initialZoom = <?= isset($_GET['lat']) ? 18 : 13 ?>;

    const map = L.map('network-map', {
        zoomControl: false // Cleaner UI
    }).setView([initialLat, initialLng], initialZoom);
    
    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // Satellite Hybrid Layer
    const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EBP, and the GIS User Community'
    }).addTo(map);

    const labels = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Labels &copy; Esri'
    }).addTo(map);

    // Context Menu (Right Click)
    map.on('contextmenu', function(e) {
        const lat = e.latlng.lat;
        const lng = e.latlng.lng;
        
        L.popup()
            .setLatLng(e.latlng)
            .setContent(`
                <div style="padding:5px;">
                    <button class="btn btn-sm btn-primary" style="width:100%; margin-bottom:5px; font-size:11px;" onclick="openQuick('asset', ${lat}, ${lng})"><i class="fas fa-boxes"></i> Pasang Aset Baru</button>
                    <button class="btn btn-sm btn-ghost" style="width:100%; font-size:11px; border:1px solid var(--primary);" onclick="openQuick('customer', ${lat}, ${lng})"><i class="fas fa-user-plus"></i> Pasang Pelanggan</button>
                </div>
            `)
            .openOn(map);
    });

    function openQuick(type, lat, lng) {
        document.getElementById('quickModal').style.display = 'block';
        document.getElementById('qLat').value = lat;
        document.getElementById('qLng').value = lng;
        
        if(type === 'asset') {
            document.getElementById('mTitle').innerHTML = '<i class="fas fa-boxes text-primary"></i> Registrasi Aset Lapangan';
            document.getElementById('qAction').value = 'add_asset';
            document.getElementById('fields_asset').style.display = 'block';
            document.getElementById('fields_customer').style.display = 'none';
        } else {
            document.getElementById('mTitle').innerHTML = '<i class="fas fa-user-plus text-success"></i> Posisi Pelanggan';
            document.getElementById('qAction').value = 'add_customer';
            document.getElementById('fields_asset').style.display = 'none';
            document.getElementById('fields_customer').style.display = 'block';
            toggleCustMode('new'); // Always reset to new when opening
        }
    }

    function toggleCustMode(mode) {
        const newFields = document.getElementById('new_cust_fields');
        const existingFields = document.getElementById('existing_cust_fields');
        
        if(mode === 'new') {
            newFields.style.display = 'block';
            existingFields.style.display = 'none';
        } else {
            newFields.style.display = 'none';
            existingFields.style.display = 'block';
        }
    }

    function closeQuickModal() {
        document.getElementById('quickModal').style.display = 'none';
    }

    // Premium SVG Icons (M & S Style)
    function createCircleIcon(label, color, size = 30) {
        return L.divIcon({
            html: `<div style="width:${size}px; height:${size}px; background:${color}; border-radius:50%; border:2px solid white; display:flex; align-items:center; justify-content:center; color:white; font-weight:800; font-family:sans-serif; font-size:${size/2.5}px; box-shadow:0 0 10px rgba(0,0,0,0.5);">${label}</div>`,
            className: 'premium-marker',
            iconSize: [size, size],
            iconAnchor: [size/2, size/2]
        });
    }

    const mainIcon = createCircleIcon('M', '#ef4444', 30); // Red
    const subIcon = createCircleIcon('S', '#0ea5e9', 24);  // Azure/Cyan

    const assetMarkers = {};
    const customerMarkers = {};
    const assetData = {}; 
    const customerData = {};
    const bounds = L.latLngBounds();
    const connectionLayer = L.layerGroup().addTo(map);

    function updatePosition(type, id, latlng) {
        // ... (existing updatePosition logic) ...
        let formData = new FormData();
        formData.append('quick_action', type === 'asset' ? 'update_pos_asset' : 'update_pos_customer');
        formData.append('id', id);
        formData.append('lat', latlng.lat);
        formData.append('lng', latlng.lng);

        // Update Local Cache
        if(type === 'asset') {
            assetData[id].lat = latlng.lat;
            assetData[id].lng = latlng.lng;
        } else {
            customerData[id].lat = latlng.lat;
            customerData[id].lng = latlng.lng;
        }

        fetch('index.php?page=admin_map', {
            method: 'POST',
            body: formData
        }).then(res => res.json()).then(data => {
            if(data.status === 'success') {
                showToast("Posisi berhasil disimpan!");
                drawAllConnections();
            }
        });
    }

    function changeUplink(type, id, newParentId) {
        let formData = new FormData();
        formData.append('quick_action', type === 'asset' ? 'change_uplink_asset' : 'change_uplink_customer');
        formData.append('id', id);
        formData.append('new_parent', newParentId);

        fetch('index.php?page=admin_map', {
            method: 'POST',
            body: formData
        }).then(res => res.json()).then(data => {
            if(data.status === 'success') {
                // Update Local Cache
                if(type === 'asset') {
                    assetData[id].parent_id = newParentId;
                } else {
                    customerData[id].odp_id = newParentId;
                }
                showToast("Jalur berhasil diubah!");
                drawAllConnections();
            }
        });
    }

    function showToast(msg) {
        const toast = document.createElement('div');
        toast.className = 'glass-panel';
        toast.style.cssText = 'position:fixed; bottom:30px; left:50%; transform:translateX(-50%); z-index:5000; padding:10px 20px; background:rgba(16,185,129,0.9); color:white; font-size:12px; border-radius:50px; box-shadow:0 10px 25px rgba(0,0,0,0.3); font-weight:700; border:1px solid rgba(255,255,255,0.2); animation: fadeInUp 0.3s ease;';
        toast.innerHTML = `<i class="fas fa-check-circle"></i> ${msg}`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'fadeOutDown 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }

    // Phase 1: Create Markers & Populate assetData
    <?php foreach($assets as $a): ?>
        assetData[<?= $a['id'] ?>] = <?= json_encode($a) ?>;
        var icon = subIcon;
        var type = '<?= $a['type'] ?>';
        if(['OLT', 'Server', 'Router'].includes(type)) icon = mainIcon;
        
        var marker = L.marker([<?= $a['lat'] ?>, <?= $a['lng'] ?>], {
            icon: icon, 
            draggable: true,
            bubblingMouseEvents: false,
            zIndexOffset: 1000
        })
            .addTo(map)
            .bindPopup(`
                <div style="font-family:inherit; color:black;">
                    <b style="font-size:14px;"><?= htmlspecialchars($a['name']) ?></b><br>
                    <span class="badge" style="background:var(--primary); font-size:9px; padding:2px 5px;"><?= $a['type'] ?></span>
                    <hr style="margin:5px 0; border:0; border-top:1px solid #eee;">
                    Price: <b>Rp <?= number_format($a['price'], 0) ?></b><br>
                    Capacity: <?= $a['total_ports'] ?> Port
                    <hr style="margin:5px 0; border:0; border-top:1px solid #eee;">
                    <div style="font-size:10px; color:#666; margin-bottom:5px;"><b>Ubah Jalur (Uplink):</b></div>
                    <select class="form-control" style="font-size:10px; height:24px; padding:2px; margin-bottom:5px;" onchange="changeUplink('asset', <?= $a['id'] ?>, this.value)">
                        <?= str_replace("value='{$a['parent_id']}'", "value='{$a['parent_id']}' selected", $asset_options_html) ?>
                    </select>
                    <button class="btn btn-sm btn-ghost" style="width:100%; font-size:10px; color:var(--primary); border:1px solid var(--primary); padding:2px; margin-top:5px;" onclick="startEditPath('asset', <?= $a['id'] ?>)"><i class="fas fa-route"></i> Edit Rute Kabel</button>
                    <div style="margin-top:5px; font-size:10px; color:#666; text-align:center;">Klik & Tahan ikon untuk menggeser posisi</div>
                </div>
            `);
        
        marker.on('dragend', function(e) {
            updatePosition('asset', <?= $a['id'] ?>, e.target.getLatLng());
        });

        // Ensure marker remains on top while dragging
        marker.on('dragstart', () => { marker.setZIndexOffset(2000); });
        marker.on('dragend', () => { marker.setZIndexOffset(1000); });

        assetMarkers[<?= $a['id'] ?>] = marker;
        bounds.extend(marker.getLatLng());
    <?php endforeach; ?>

    // Phase 2: Draw Connections (After all data loaded)
    const activeEditing = { type: null, id: null, vertices: [] };
    const vertexMarkers = L.layerGroup().addTo(map);

    function drawAllConnections() {
        connectionLayer.clearLayers();

        // Assets to Assets
        Object.keys(assetData).forEach(id => {
            const a = assetData[id];
            if(a.parent_id > 0) {
                const parent = assetData[a.parent_id];
                if(parent) {
                    let path = [[parent.lat, parent.lng]];
                    if(a.path_json) {
                        try {
                            const midPoints = JSON.parse(a.path_json);
                            if(Array.isArray(midPoints)) path = path.concat(midPoints);
                        } catch(e) {}
                    }
                    path.push([a.lat, a.lng]);

                    L.polyline(path, {
                        color: '#0ea5e9',
                        weight: 3.5,
                        opacity: 0.9,
                        className: 'flowing-line'
                    }).addTo(connectionLayer);
                }
            }
        });

        // Customers to Assets
        Object.keys(customerData).forEach(id => {
            const c = customerData[id];
            if(c.odp_id > 0) {
                const asset = assetData[c.odp_id];
                if(asset) {
                    let path = [[asset.lat, asset.lng]];
                    if(c.path_json) {
                        try {
                            const midPoints = JSON.parse(c.path_json);
                            if(Array.isArray(midPoints)) path = path.concat(midPoints);
                        } catch(e) {}
                    }
                    path.push([c.lat, c.lng]);

                    L.polyline(path, {
                        color: '#0ea5e9', 
                        weight: 2.5, 
                        opacity: 0.9,
                        className: 'flowing-line'
                    }).addTo(connectionLayer);
                }
            }
        });
    }

    function startEditPath(type, id) {
        map.closePopup();
        activeEditing.type = type;
        activeEditing.id = id;
        
        const data = (type === 'asset') ? assetData[id] : customerData[id];
        try {
            activeEditing.vertices = JSON.parse(data.path_json || '[]');
        } catch(e) { activeEditing.vertices = []; }

        showVertexEditor();
    }

    function showVertexEditor() {
        vertexMarkers.clearLayers();
        
        // Show current vertices as draggable small dots
        activeEditing.vertices.forEach((v, index) => {
            L.circleMarker(v, {
                radius: 6,
                color: '#f59e0b',
                fillColor: '#fbbf24',
                fillOpacity: 1,
                draggable: true,
                interactive: true
            }).addTo(vertexMarkers)
              .on('mousedown', (e) => {
                  L.DomEvent.stopPropagation(e);
                  map.dragging.disable();
                  const onMouseMove = (ev) => {
                      const newPos = map.mouseEventToLatLng(ev);
                      activeEditing.vertices[index] = [newPos.lat, newPos.lng];
                      e.target.setLatLng(newPos);
                      drawAllConnections();
                      drawTempPath();
                  };
                  const onMouseUp = () => {
                      map.dragging.enable();
                      window.removeEventListener('mousemove', onMouseMove);
                      window.removeEventListener('mouseup', onMouseUp);
                  };
                  window.addEventListener('mousemove', onMouseMove);
                  window.addEventListener('mouseup', onMouseUp);
              })
              .on('contextmenu', (e) => {
                  L.DomEvent.stopPropagation(e);
                  activeEditing.vertices.splice(index, 1);
                  showVertexEditor();
                  drawAllConnections();
              });
        });

        // UI Panel for editing
        let panel = document.getElementById('path-editor-ui');
        if(!panel) {
            panel = document.createElement('div');
            panel.id = 'path-editor-ui';
            panel.className = 'glass-panel';
            panel.style.cssText = 'position:absolute; bottom:100px; left:20px; z-index:1100; padding:15px; width:220px;';
            document.querySelector('.glass-panel[style*="height"]').appendChild(panel);
        }
        
        panel.innerHTML = `
            <h5 style="margin:0 0 10px 0; font-size:13px; color:white;"><i class="fas fa-route"></i> Edit Rute Kabel</h5>
            <p style="font-size:10px; color:rgba(255,255,255,0.7); margin-bottom:10px;">
                1. Klik peta untuk tambah titik belokan.<br>
                2. Geser titik kuning untuk ubah rute.<br>
                3. Klik kanan titik untuk hapus.
            </p>
            <div style="display:flex; gap:5px;">
                <button class="btn btn-sm btn-primary" style="flex:1; font-size:11px;" onclick="savePath()">Simpan</button>
                <button class="btn btn-sm btn-ghost" style="flex:1; font-size:11px; background:rgba(255,255,255,0.2);" onclick="cancelPath()">Batal</button>
            </div>
        `;

        map.on('click', onMapClickForPath);
    }

    function onMapClickForPath(e) {
        activeEditing.vertices.push([e.latlng.lat, e.latlng.lng]);
        showVertexEditor();
        drawAllConnections();
    }

    function savePath() {
        let formData = new FormData();
        formData.append('quick_action', activeEditing.type === 'asset' ? 'update_path_asset' : 'update_path_customer');
        formData.append('id', activeEditing.id);
        formData.append('path_json', JSON.stringify(activeEditing.vertices));

        fetch('index.php?page=admin_map', {
            method: 'POST',
            body: formData
        }).then(res => res.json()).then(data => {
            if(data.status === 'success') {
                if(activeEditing.type === 'asset') {
                    assetData[activeEditing.id].path_json = JSON.stringify(activeEditing.vertices);
                } else {
                    customerData[activeEditing.id].path_json = JSON.stringify(activeEditing.vertices);
                }
                showToast("Rute rute berhasil disimpan!");
                cancelPath();
            }
        });
    }

    function cancelPath() {
        activeEditing.type = null;
        activeEditing.id = null;
        activeEditing.vertices = [];
        vertexMarkers.clearLayers();
        const panel = document.getElementById('path-editor-ui');
        if(panel) panel.remove();
        map.off('click', onMapClickForPath);
        drawAllConnections();
    }

    // Initialize Connections
    drawAllConnections();

    // Render Customers
    <?php foreach($customers as $c): ?>
        customerData[<?= $c['id'] ?>] = <?= json_encode($c) ?>;
        var marker = L.marker([<?= $c['lat'] ?>, <?= $c['lng'] ?>], {
            icon: subIcon, 
            draggable: true,
            bubblingMouseEvents: false,
            zIndexOffset: 500
        })
            .addTo(map);

        var cPopup = `
            <div style="font-family:inherit; color:black;">
                <b style="font-size:14px;"><?= htmlspecialchars($c['name']) ?></b><br>
                ID: <?= htmlspecialchars($c['customer_code']) ?><br>
                <hr style="margin:5px 0; border:0; border-top:1px solid #eee;">
                <div style="font-size:10px; color:#666; margin-bottom:5px;"><b>Ubah Jalur (ODP):</b></div>
                <select class="form-control" style="font-size:10px; height:24px; padding:2px; margin-bottom:10px;" onchange="changeUplink('customer', <?= $c['id'] ?>, this.value)">
                    <?= str_replace("value='{$c['odp_id']}'", "value='{$c['odp_id']}' selected", $odp_options_html) ?>
                </select>
                <div style="display:flex; gap:5px; margin-bottom:10px;">
                    <button class="btn btn-sm btn-primary" style="flex:2; font-size:10px;" onclick="window.open('index.php?page=admin_customers&action=details&id=<?= $c['id'] ?>', '_blank')">Detail</button>
                    <button class="btn btn-sm btn-ghost" style="flex:3; font-size:10px; color:var(--primary); border:1px solid var(--primary); padding:2px;" onclick="startEditPath('customer', <?= $c['id'] ?>)"><i class="fas fa-route"></i> Edit Rute</button>
                </div>
                <div style="margin-top:5px; font-size:9px; color:#999; text-align:center;">Klik & Tahan ikon untuk menggeser posisi</div>
            </div>
        `;
        marker.bindPopup(cPopup);

        marker.on('dragend', function(e) {
            updatePosition('customer', <?= $c['id'] ?>, e.target.getLatLng());
        });

        marker.on('dragstart', () => { marker.setZIndexOffset(2000); });
        marker.on('dragend', () => { marker.setZIndexOffset(500); });
        
        customerMarkers[<?= $c['id'] ?>] = marker;
        bounds.extend(marker.getLatLng());
    <?php endforeach; ?>
    
    // Initial redraw to catch customer connections
    drawAllConnections();

    if(bounds.isValid()) map.fitBounds(bounds, {padding: [50, 50]});

    function centerMap() {
        if(bounds.isValid()) map.fitBounds(bounds, {padding: [50, 50]});
    }

    setTimeout(() => { map.invalidateSize(); }, 500);
</script>

<style>
#network-map { cursor: crosshair; }
@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
@keyframes fadeInUp { from { transform: translate(-50%, 20px); opacity:0; } to { transform: translate(-50%, 0); opacity:1; } }
@keyframes fadeOutDown { from { transform: translate(-50%, 0); opacity:1; } to { transform: translate(-50%, 20px); opacity:0; } }
.leaflet-popup-content-wrapper { border-radius:12px; padding:5px; background:rgba(0,0,0,0.9); color:white; backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.1); }
.leaflet-popup-tip { background:rgba(0,0,0,0.9); }

/* Connection Flow Animation */
.flowing-line {
    stroke-dasharray: 10, 15 !important;
    stroke-dashoffset: 200 !important;
    animation: flow 3s linear infinite !important;
}

@keyframes flow {
    to {
        stroke-dashoffset: 0 !important;
    }
}
</style>
