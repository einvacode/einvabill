<?php
/**
 * Partner WhatsApp Device Management
 * Allows partner to scan WhatsApp Web and send messages to their customers
 */

if ($_SESSION['user_role'] !== 'partner') {
    header("Location: index.php?page=partner");
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
                <h2 style="margin:0; font-size:22px;">WhatsApp Perangkat Saya</h2>
                <div class="wa-status-indicator" style="margin-top:4px;">Mengecek Status...</div>
            </div>
        </div>
        <a href="index.php?page=partner" class="btn btn-sm btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
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
                <p><i class="fas fa-camera"></i> Silakan scan QR Code di atas menggunakan menu <strong>Perangkat Tertaut</strong> pada WhatsApp HP Anda.</p>
            </div>
            
            <div id="wa-connected-box" style="display:none; padding:20px;">
                <i class="fas fa-check-circle" style="font-size:64px; color:#10b981; margin-bottom:15px;"></i>
                <h4 style="color:#10b981; font-weight:800; margin-bottom:10px;">TERHUBUNG!</h4>
                <p style="font-size:13px; color:var(--text-secondary);">Anda sekarang bisa mengirim tagihan ke pelanggan melalui WhatsApp.</p>
                <button onclick="logoutWA()" class="btn btn-danger btn-sm" style="margin-top:20px;"><i class="fas fa-sign-out-alt"></i> Putuskan Koneksi</button>
            </div>
        </div>

        <!-- Info & Stats Section -->
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="glass-panel" style="padding:20px; background:rgba(37, 211, 102, 0.08);">
                <h5 style="margin:0 0 12px; font-weight:800; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:#25D366;"><i class="fas fa-info-circle"></i> Cara Kerja</h5>
                <ul style="padding-left:20px; font-size:12px; color:var(--text-secondary); line-height:1.8; margin:0;">
                    <li>Scan QR Code untuk menghubungkan WhatsApp Anda dengan portal.</li>
                    <li>Setelah terhubung, Anda bisa mengirim tagihan ke pelanggan melalui WhatsApp.</li>
                    <li>Sistem otomatis menambahkan delay 10 detik antar pesan untuk keamanan nomor Anda.</li>
                    <li>Koneksi aman - sistem tidak menyimpan password WhatsApp Anda.</li>
                </ul>
            </div>

            <div class="glass-panel" style="padding:20px; background:rgba(0,0,0,0.2);">
                <h5 style="margin:0 0 12px; font-weight:800; font-size:14px; text-transform:uppercase; letter-spacing:1px;"><i class="fas fa-list-ul"></i> Aktivitas Terakhir</h5>
                <style>
                    #wa-logs::-webkit-scrollbar { width: 4px; }
                    #wa-logs::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
                    #wa-logs::-webkit-scrollbar-thumb { background: rgba(37, 211, 102, 0.3); border-radius: 10px; }
                    #wa-logs::-webkit-scrollbar-thumb:hover { background: rgba(37, 211, 102, 0.5); }
                </style>
                <div id="wa-logs" style="font-family:monospace; font-size:11px; color:#25D366; max-height:200px; overflow-y:auto; line-height:1.6; padding-right:5px;">
                    <div style="opacity:0.6;">> Menunggu aktivitas perangkat...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let qrcodeObj = null;
let currentQR = "";

async function refreshGateway() {
    const qrcodeEl = document.getElementById('qrcode');
    const logsEl = document.getElementById('wa-logs');
    const tipEl = document.getElementById('wa-connection-tip');
    const qrCont = document.getElementById('qr-container');
    const connBox = document.getElementById('wa-connected-box');
    const statusEl = document.querySelector('.wa-status-indicator');

    try {
        const response = await fetch(WAApiProxy + 'status&cid=' + WAGatewayCID);
        const data = await response.json();
        
        if (data.error) {
            qrcodeEl.innerHTML = `<div style="color:#ef4444; font-size:12px; font-weight:700;"><i class="fas fa-exclamation-triangle"></i> GATEWAY OFFLINE<br><span style="font-weight:400; opacity:0.7;">${data.debug?.curl_error || 'Node.js server not responding'}</span></div>`;
            statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#ef4444;"></i> <span style="color:#ef4444;">Gateway Offline</span>';
            return;
        }

        if (data.connected) {
            qrCont.style.display = 'none';
            tipEl.style.display = 'none';
            connBox.style.display = 'block';
            statusEl.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;"></i> <span style="color:#10b981;">Terhubung</span>';
        } else {
            qrCont.style.display = 'inline-block';
            tipEl.style.display = 'block';
            connBox.style.display = 'none';

            if (data.qr_available) {
                const qrResponse = await fetch(WAApiProxy + 'qr&cid=' + WAGatewayCID);
                const qrData = await qrResponse.json();
                
                if (qrData.qr) {
                    qrcodeEl.innerHTML = '';
                    if (!qrcodeObj) {
                        qrcodeObj = new QRCode(qrcodeEl, { width: 200, height: 200 });
                    }
                    qrcodeObj.makeCode(qrData.qr);
                    currentQR = qrData.qr;
                    statusEl.innerHTML = '<i class="fas fa-clock" style="color:#f59e0b;"></i> <span style="color:#f59e0b;">Menunggu Scan QR</span>';
                }
            } else {
                qrcodeEl.innerHTML = '<div style="color:#64748b; font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Inisialisasi...</div>';
                statusEl.innerHTML = '<i class="fas fa-hourglass-start" style="color:#f59e0b;"></i> <span style="color:#f59e0b;">Inisialisasi</span>';
            }
        }

        // Fetch logs
        const logsResponse = await fetch(WAApiProxy + 'logs&cid=' + WAGatewayCID);
        const logsData = await logsResponse.json();
        if (logsData.logs && Array.isArray(logsData.logs)) {
            const logHTML = logsData.logs.slice(-10).map(log => 
                `<div>[${log.timestamp}] ${log.msg}</div>`
            ).join('');
            logsEl.innerHTML = logHTML || '<div style="opacity:0.6;">> Tidak ada aktivitas</div>';
        }
    } catch (err) {
        qrcodeEl.innerHTML = `<div style="color:#ef4444; font-size:12px;"><i class="fas fa-exclamation-circle"></i><br>Gagal Terhubung<br><span style="font-weight:400; opacity:0.7; font-size:11px;">${err.message}</span></div>`;
        statusEl.innerHTML = '<i class="fas fa-times-circle" style="color:#ef4444;"></i> <span style="color:#ef4444;">Error</span>';
    }
}

async function logoutWA() {
    if (confirm('Yakin ingin memutuskan koneksi WhatsApp?')) {
        try {
            const response = await fetch(WAApiProxy + 'logout&cid=' + WAGatewayCID, { method: 'POST' });
            const data = await response.json();
            if (!data.error) {
                alert('Koneksi terputus. Silakan scan QR lagi untuk menghubungkan.');
                refreshGateway();
            }
        } catch (err) {
            alert('Error: ' + err.message);
        }
    }
}

// Auto-refresh setiap 3 detik
setInterval(refreshGateway, 3000);
refreshGateway();
</script>
