<?php
// Fetch All Assets & Customers with Coordinates
$assets = $db->query("SELECT * FROM infrastructure_assets WHERE lat IS NOT NULL AND lat != ''")->fetchAll();
$customers = $db->query("SELECT * FROM customers WHERE lat IS NOT NULL AND lat != ''")->fetchAll();
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="glass-panel" style="padding:0; overflow:hidden; position:relative; height: calc(100vh - 150px); min-height: 500px;">
    <!-- Map Control HUD -->
    <div style="position:absolute; top:20px; right:20px; z-index:1000; background:rgba(0,0,0,0.6); padding:15px; border-radius:12px; backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.1); width:220px;">
        <h4 style="margin:0 0 10px 0; font-size:14px;"><i class="fas fa-layer-group"></i> Legend Jaringan</h4>
        <div style="display:flex; flex-direction:column; gap:8px; font-size:12px;">
            <div style="display:flex; align-items:center; gap:8px;"><i class="fas fa-satellite-dish" style="color:#3b82f6; width:15px;"></i> OLT (Pusat)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i class="fas fa-server" style="color:#a855f7; width:15px;"></i> ODC (Cabinet)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i class="fas fa-box" style="color:#ec4899; width:15px;"></i> ODP (Kotak)</div>
            <div style="display:flex; align-items:center; gap:8px;"><i class="fas fa-user" style="color:#10b981; width:15px;"></i> Pelanggan</div>
            <hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:5px 0;">
            <div style="display:flex; align-items:center; gap:8px;"><span style="width:15px; height:2px; background:#6366f1;"></span> Jalur Kabel</div>
        </div>
        <div style="margin-top:15px;">
            <button class="btn btn-sm btn-primary" style="width:100%; font-size:11px;" onclick="centerMap()">Center Map</button>
        </div>
    </div>

    <!-- Map Container -->
    <div id="network-map" style="width:100%; height:100%;"></div>
</div>

<script>
    // Initialize Map
    const map = L.map('network-map').setView([-6.200000, 106.816666], 13); // Default Jakarta or dynamic

    // Add Tiles (OpenStreetMap)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Custom Icons
    const oltIcon = L.divIcon({ html: '<i class="fas fa-satellite-dish" style="color:#3b82f6; font-size:24px; text-shadow:0 0 5px #000;"></i>', className: 'm-icon', iconSize:[30,30], iconAnchor:[15,15] });
    const odcIcon = L.divIcon({ html: '<i class="fas fa-server" style="color:#a855f7; font-size:20px; text-shadow:0 0 5px #000;"></i>', className: 'm-icon', iconSize:[25,25], iconAnchor:[12.5,12.5] });
    const odpIcon = L.divIcon({ html: '<i class="fas fa-box" style="color:#ec4899; font-size:18px; text-shadow:0 0 5px #000;"></i>', className: 'm-icon', iconSize:[20,20], iconAnchor:[10,10] });
    const custIcon = L.divIcon({ html: '<i class="fas fa-user-circle" style="color:#10b981; font-size:16px; text-shadow:0 0 5px #000;"></i>', className: 'm-icon', iconSize:[18,18], iconAnchor:[9,9] });

    const assetMarkers = {};
    const bounds = L.latLngBounds();

    // Render Assets (OLT, ODC, ODP)
    <?php foreach($assets as $a): ?>
        var icon = odpIcon;
        <?php if($a['type'] == 'OLT') echo "icon = oltIcon;"; ?>
        <?php if($a['type'] == 'ODC') echo "icon = odcIcon;"; ?>
        
        var marker = L.marker([<?= $a['lat'] ?>, <?= $a['lng'] ?>], {icon: icon})
            .addTo(map)
            .bindPopup("<b><?= htmlspecialchars($a['name']) ?></b><br>Tipe: <?= $a['type'] ?><br>Capacity: <?= $a['total_ports'] ?> Port");
        
        assetMarkers[<?= $a['id'] ?>] = marker;
        bounds.extend(marker.getLatLng());

        // Draw Line to Parent
        <?php if($a['parent_id'] > 0): ?>
            var parent = assetMarkers[<?= $a['parent_id'] ?>];
            if(parent) {
                L.polyline([marker.getLatLng(), parent.getLatLng()], {color: '#6366f1', weight: 2, opacity: 0.5, dashArray: '5, 10'}).addTo(map);
            }
        <?php endif; ?>
    <?php endforeach; ?>

    // Render Customers
    <?php foreach($customers as $c): ?>
        var marker = L.marker([<?= $c['lat'] ?>, <?= $c['lng'] ?>], {icon: custIcon})
            .addTo(map)
            .bindPopup("<b><?= htmlspecialchars($c['name']) ?></b><br>ID: <?= htmlspecialchars($c['customer_code']) ?><br>Port: <?= $c['odp_port'] ?: '-' ?>");
        
        bounds.extend(marker.getLatLng());

        // Draw Line to ODP
        <?php if(($c['odp_id'] ?? 0) > 0): ?>
            var odp = assetMarkers[<?= $c['odp_id'] ?>];
            if(odp) {
                L.polyline([marker.getLatLng(), odp.getLatLng()], {color: '#10b981', weight: 1.5, opacity: 0.4}).addTo(map);
            }
        <?php endif; ?>
    <?php endforeach; ?>

    if(bounds.isValid()) map.fitBounds(bounds, {padding: [50, 50]});

    function centerMap() {
        if(bounds.isValid()) map.fitBounds(bounds, {padding: [50, 50]});
    }

    // Small fix for map resizing in tabs or dynamic layout
    setTimeout(() => { map.invalidateSize(); }, 500);
</script>

<style>
.m-icon { background:none; border:none; }
</style>
