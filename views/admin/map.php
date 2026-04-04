<?php
// Handle Quick Add from Map
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_action'])) {
    if ($_POST['quick_action'] === 'add_asset') {
        $stmt = $db->prepare("INSERT INTO infrastructure_assets (name, type, lat, lng, total_ports, price, status, installation_date) VALUES (?, ?, ?, ?, ?, ?, 'Deployed', ?)");
        $stmt->execute([$_POST['name'], $_POST['type'], $_POST['lat'], $_POST['lng'], $_POST['ports'], $_POST['price'], date('Y-m-d')]);
        header("Location: index.php?page=admin_map&success=asset"); exit;
    }
    if ($_POST['quick_action'] === 'add_customer') {
        if (!empty($_POST['customer_id']) && $_POST['customer_id'] > 0) {
            // Update Existing Customer Location
            $stmt = $db->prepare("UPDATE customers SET lat = ?, lng = ?, odp_id = ? WHERE id = ?");
            $stmt->execute([$_POST['lat'], $_POST['lng'], $_POST['odp_id'] ?? 0, $_POST['customer_id']]);
            header("Location: index.php?page=admin_map&success=customer_update"); exit;
        } else {
            // Auto-generate code
            $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
            do {
                $customer_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                $stmt_check->execute([$customer_code]);
            } while ($stmt_check->fetchColumn() > 0);

            $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, package_name, monthly_fee, registration_date, billing_date, lat, lng, type, odp_id) VALUES (?, ?, ?, ?, 'Manual', 0, ?, 1, ?, ?, 'customer', ?)");
            $stmt->execute([$customer_code, $_POST['name'], $_POST['address'], $_POST['contact'], date('Y-m-d'), $_POST['lat'], $_POST['lng'], $_POST['odp_id'] ?? 0]);
            header("Location: index.php?page=admin_map&success=customer"); exit;
        }
    }
}

// Fetch All Assets & Customers with Coordinates
$assets = $db->query("SELECT * FROM infrastructure_assets WHERE lat IS NOT NULL AND lat != ''")->fetchAll();
$customers = $db->query("SELECT * FROM customers WHERE lat IS NOT NULL AND lat != ''")->fetchAll();

// Fetch Registered Customers without Coordinates for the Picker
$existing_customers = $db->query("SELECT id, name, customer_code FROM customers WHERE (lat IS NULL OR lat = '') ORDER BY name ASC")->fetchAll();
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
                        <label>Harga Beli (Rp)</label>
                        <input type="number" name="price" class="form-control" value="0">
                    </div>
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
                        <label>Nama Pelanggan</label>
                        <input type="text" name="name" class="form-control" placeholder="Nama Lengkap">
                    </div>
                    <div class="form-group">
                        <label>No. WhatsApp</label>
                        <input type="text" name="contact" class="form-control" placeholder="08xxx">
                    </div>
                    <div class="form-group">
                        <label>Alamat Lengkap</label>
                        <input type="text" name="address" class="form-control" placeholder="Detail Alamat">
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
                        $opts = $db->query("SELECT id, name, type FROM infrastructure_assets ORDER BY type DESC, name ASC")->fetchAll();
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
    // Initialize Map
    const map = L.map('network-map', {
        zoomControl: false // Cleaner UI
    }).setView([-6.200000, 106.816666], 13);
    
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
    const assetData = {}; // Full data for recursive trace
    const bounds = L.latLngBounds();

    // Render Assets
    <?php foreach($assets as $a): ?>
        assetData[<?= $a['id'] ?>] = <?= json_encode($a) ?>;
        var icon = subIcon;
        var type = '<?= $a['type'] ?>';
        if(['OLT', 'Server', 'Router'].includes(type)) icon = mainIcon;
        
        var marker = L.marker([<?= $a['lat'] ?>, <?= $a['lng'] ?>], {icon: icon})
            .addTo(map)
            .bindPopup(`
                <div style="font-family:inherit;">
                    <b style="font-size:14px;"><?= htmlspecialchars($a['name']) ?></b><br>
                    <span class="badge" style="background:var(--primary); font-size:9px; padding:2px 5px;"><?= $a['type'] ?></span>
                    <hr style="margin:5px 0; border:0; border-top:1px solid #eee;">
                    Price: <b>Rp <?= number_format($a['price'], 0) ?></b><br>
                    Capacity: <?= $a['total_ports'] ?> Port
                </div>
            `);
        
        assetMarkers[<?= $a['id'] ?>] = marker;
        bounds.extend(marker.getLatLng());

        <?php if($a['parent_id'] > 0): ?>
            var parent = assetData[<?= $a['parent_id'] ?>];
            if(parent) {
                // FLOW: Parent (Server/ODC) -> Child (POP/ODP)
                L.polyline([[parent.lat, parent.lng], [<?= $a['lat'] ?>, <?= $a['lng'] ?>]], {
                    color: '#0ea5e9', // Premium Cyan
                    weight: 3.5, 
                    opacity: 0.9, 
                    className: 'flowing-line'
                }).addTo(map);
            }
        <?php endif; ?>
    <?php endforeach; ?>

    // Render Customers with Recursive Topology Trace
    <?php foreach($customers as $c): ?>
        var marker = L.marker([<?= $c['lat'] ?>, <?= $c['lng'] ?>], {icon: subIcon})
            .addTo(map);

        var cPopup = `
            <div style="font-family:inherit;">
                <b style="font-size:14px;"><?= htmlspecialchars($c['name']) ?></b><br>
                ID: <?= htmlspecialchars($c['customer_code']) ?><br>
                <hr style="margin:5px 0; border:0; border-top:1px solid #eee;">
                <button class="btn btn-sm btn-primary" style="width:100%; font-size:10px;" onclick="window.open('index.php?page=admin_customers&action=details&id=<?= $c['id'] ?>', '_blank')">Detail Pelanggan</button>
            </div>
        `;
        marker.bindPopup(cPopup);
        
        bounds.extend(marker.getLatLng());

        // Recursive Line Drawing (Trace to Root)
        function drawTrace(startLatLng, currentAssetId, type) {
            if(!currentAssetId || currentAssetId == 0) return;
            var asset = assetData[currentAssetId];
            if(asset) {
                var assetLatLng = [asset.lat, asset.lng];
                var color = (type === 'cust') ? '#10b981' : '#6366f1';
                
                // FLOW: Asset -> Customer (Downlink)
                L.polyline([assetLatLng, startLatLng], {
                    color: '#0ea5e9', 
                    weight: 2.5, 
                    opacity: 0.9,
                    className: 'flowing-line'
                }).addTo(map);

                // Continue to parent handled in asset loop but for integrity we trace back
            }
        }

        <?php if(($c['odp_id'] ?? 0) > 0): ?>
            drawTrace(marker.getLatLng(), <?= $c['odp_id'] ?>, 'cust');
        <?php endif; ?>
    <?php endforeach; ?>

    if(bounds.isValid()) map.fitBounds(bounds, {padding: [50, 50]});

    function centerMap() {
        if(bounds.isValid()) map.fitBounds(bounds, {padding: [50, 50]});
    }

    setTimeout(() => { map.invalidateSize(); }, 500);
</script>

<style>
.m-icon { background:none; border:none; }
#network-map { cursor: crosshair; }
@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
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
