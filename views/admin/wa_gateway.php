<?php
// Protection: Only Admin can access
if ($_SESSION['user_role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
?>

<div class="glass-panel" style="padding:30px; margin-bottom:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
        <div style="display:flex; align-items:center; gap:15px;">
            <div style="width:50px; height:50px; background:rgba(37, 211, 102, 0.1); border-radius:12px; display:flex; align-items:center; justify-content:center;">
                <i class="fab fa-whatsapp" style="font-size:28px; color:#25D366;"></i>
            </div>
            <div>
                <h2 style="margin:0; font-size:22px;">WhatsApp Gateway</h2>
                <div class="wa-status-indicator" style="margin-top:4px;">Mengecek Status...</div>
            </div>
        </div>
        <a href="index.php?page=admin_dashboard" class="btn btn-sm btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:25px;">
        <!-- QR Code Section -->
        <div class="glass-panel" style="padding:25px; border:1px solid var(--glass-border); background:rgba(255,255,255,0.02); text-align:center;">
            <div id="qr-container" style="background:#fff; padding:20px; border-radius:15px; display:inline-block; margin-bottom:20px; min-width:240px; min-height:240px; border:4px solid #f1f5f9;">
                <div id="qrcode" style="display:flex; justify-content:center; align-items:center; height:200px;">
                    <div style="color:#64748b; font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Memuat QR Code...</div>
                </div>
            </div>
            
            <div id="wa-connection-tip" style="color:var(--text-secondary); font-size:13px; line-height:1.6; max-width:280px; margin:0 auto;">
                <p><i class="fas fa-camera"></i> Silakan scan QR Code di atas menggunakan menu <strong>Perangkat Tertaut</strong> pada WhatsApp HP Admin.</p>
            </div>
            
            <div id="wa-connected-box" style="display:none; padding:20px;">
                <i class="fas fa-check-circle" style="font-size:64px; color:#10b981; margin-bottom:15px;"></i>
                <h4 style="color:#10b981; font-weight:800; margin-bottom:10px;">TERHUBUNG!</h4>
                <p style="font-size:13px; color:var(--text-secondary);">Sistem siap mengirim tagihan otomatis.</p>
                <button onclick="logoutWA()" class="btn btn-danger btn-sm" style="margin-top:20px;"><i class="fas fa-sign-out-alt"></i> Putuskan Koneksi</button>
            </div>
        </div>

        <!-- Info & Stats Section -->
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="glass-panel" style="padding:20px; background:rgba(var(--primary-rgb), 0.05);">
                <h5 style="margin:0 0 12px; font-weight:800; font-size:14px; text-transform:uppercase; letter-spacing:1px;"><i class="fas fa-info-circle"></i> Cara Kerja Gateway</h5>
                <ul style="padding-left:20px; font-size:12px; color:var(--text-secondary); line-height:1.8; margin:0;">
                    <li>Gateway berfungsi sebagai "WhatsApp Web" bagi sistem.</li>
                    <li>Sistem tidak menyimpan nomor tujuan atau pesan selain untuk keperluan pengiriman sementara.</li>
                    <li><strong>Setiap pesan masal akan diberikan jeda 10 detik otomatis</strong> untuk menjaga keamanan nomor Anda dari blokir.</li>
                </ul>
            </div>

            <div class="glass-panel" style="padding:20px; background:rgba(0,0,0,0.2);">
                <h5 style="margin:0 0 12px; font-weight:800; font-size:14px; text-transform:uppercase; letter-spacing:1px;"><i class="fas fa-list-ul"></i> Aktivitas Terakhir</h5>
                <div id="wa-logs" style="font-family:monospace; font-size:11px; color:#10b981; max-height:150px; overflow-y:auto; line-height:1.6;">
                    <div style="opacity:0.6;">> Menunggu aktivitas gateway...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Load QRCode.js from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let qrcodeObj = null;
let currentQR = "";

async function refreshGateway() {
    try {
        const gatewayUrl = `http://${window.location.hostname}:3000`;
        const response = await fetch(`${gatewayUrl}/status`);
        const data = await response.json();
        
        if (data.connected) {
            document.getElementById('qr-container').style.display = 'none';
            document.getElementById('wa-connection-tip').style.display = 'none';
            document.getElementById('wa-connected-box').style.display = 'block';
        } else {
            document.getElementById('qr-container').style.display = 'inline-block';
            document.getElementById('wa-connection-tip').style.display = 'block';
            document.getElementById('wa-connected-box').style.display = 'none';
            
            // Try fetch QR
            const qrResp = await fetch(`${gatewayUrl}/qr`);
            const qrData = await qrResp.json();
            
            if (qrData.qr && qrData.qr !== currentQR) {
                currentQR = qrData.qr;
                document.getElementById('qrcode').innerHTML = "";
                qrcodeObj = new QRCode(document.getElementById('qrcode'), {
                    text: currentQR,
                    width: 200,
                    height: 200,
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.H
                });
            } else if (!qrData.qr) {
                document.getElementById('qrcode').innerHTML = '<div style="color:#64748b; font-size:13px; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Menyiapkan QR Code...<br><span style="font-size:10px;">(Pesan: '+ (qrData.message || 'Harap Tunggu') +')</span></div>';
            }
        }
    } catch (e) {
        document.getElementById('qrcode').innerHTML = '<div style="color:#ef4444; font-size:12px; font-weight:700;"><i class="fas fa-exclamation-triangle"></i> GATEWAY OFFLINE<br><span style="font-weight:400; opacity:0.7;">Harap nyalakan node server.js</span></div>';
    }
}

async function logoutWA() {
    if (!confirm('Apakah Anda yakin ingin memutuskan koneksi WhatsApp?')) return;
    try {
        const gatewayUrl = `http://${window.location.hostname}:3000`;
        await fetch(`${gatewayUrl}/logout`, { method: 'POST' });
        location.reload();
    } catch (e) {
        alert('Gagal memutuskan koneksi. Gateway mungkin sedang offline.');
    }
}

// Start Polling
setInterval(refreshGateway, 5000);
refreshGateway();
</script>
