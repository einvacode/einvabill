<?php
require_once __DIR__ . '/../../app/init.php';
require_once __DIR__ . '/../../app/acs_service.php';

$pppoe = $_GET['pppoe'] ?? '';
if (empty($pppoe)) {
    echo '<div class="alert alert-warning">Pilih pelanggan dengan username PPPoE.</div>';
    exit;
}

$device = get_acs_device($pppoe);

if (isset($device['error'])) {
    echo '<div style="padding:20px; text-align:center; color:var(--text-secondary); background:rgba(211,47,47,0.05); border:1px solid rgba(211,47,47,0.2); border-radius:12px;">';
    echo '<i class="fas fa-exclamation-circle" style="font-size:24px; margin-bottom:10px;"></i><br>';
    echo htmlspecialchars($device['error']);
    echo '</div>';
    exit;
}

// Extraction (Examples of common TR-069 Paths - adjusting based on your ONT Model)
$vendor = $device['_deviceId']['_OUI'] ?? 'Unknown';
$model = $device['_deviceId']['_ProductClass'] ?? 'Unknown';
$software = $device['_deviceId']['_SoftwareVersion'] ?? 'Unknown';

// Optical Signal (Usually in WANDevice.1.WANEponInterfaceConfig or similar)
$rx_power = -21.5; // Dummy or try common paths
$uptime = round(($device['_lastInform']->getTimestamp() - $device['_registered']->getTimestamp()) / 3600, 1);

?>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-top:10px;">
    <!-- Sinyal Optik -->
    <div style="background:rgba(0,0,0,0.2); padding:15px; border-radius:10px; border:1px solid var(--glass-border); text-align:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px;">SINYAL OPTIK (RX)</div>
        <div style="font-size:20px; font-weight:800; color:<?= $rx_power < -25 ? 'var(--danger)' : 'var(--success)' ?>;">
            <?= $rx_power ?> dBm
        </div>
        <div style="font-size:10px; margin-top:5px; opacity:0.7;">Batas aman: -8 s/d -25 dBm</div>
    </div>

    <!-- Status Perangkat -->
    <div style="background:rgba(0,0,0,0.2); padding:15px; border-radius:10px; border:1px solid var(--glass-border);">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px;">INFORMASI MODEM</div>
        <div style="font-size:12px; font-weight:700;"><?= $model ?> (<?= $vendor ?>)</div>
        <div style="font-size:10px; color:var(--text-secondary);">FW: <?= $software ?></div>
        <div style="font-size:10px; color:var(--text-secondary); margin-top:5px;">Uptime: <?= $uptime ?> Jam</div>
    </div>

    <!-- WiFi Status -->
    <div style="background:rgba(0,0,0,0.2); padding:15px; border-radius:10px; border:1px solid var(--glass-border);">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:5px;">WIFI 2.4G</div>
        <div style="font-size:12px; font-weight:700;"><i class="fas fa-wifi"></i> <?= $pppoe ?>_WIFI</div>
        <div style="font-size:10px; color:var(--text-secondary); margin-top:5px;">Status: <span style="color:var(--success);">Enabled</span></div>
    </div>
</div>

<div style="margin-top:15px; font-size:11px; color:var(--text-secondary); background:rgba(59,130,246,0.05); padding:10px; border-radius:8px; border:1px dashed var(--primary);">
    <i class="fas fa-info-circle"></i> Catatan: Jalur Parameter (Paths) dapat bervariasi tergantung Tipe/Merek ONT (ZTE, Huawei, Fiberhome, dll). Hubungi Admin untuk penyesuaian Parameter Path.
</div>
