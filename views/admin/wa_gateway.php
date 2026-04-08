<?php
// Protection: Any logged-in user can access their own gateway
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
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
                <h2 style="margin:0; font-size:22px;">WhatsApp <?= ($_SESSION['user_role'] === 'admin' ? 'Gateway' : 'Perangkat') ?></h2>
                <div class="wa-status-indicator" style="margin-top:4px;">Mengecek Status...</div>
            </div>
        </div>
        <a href="index.php?page=<?= $_SESSION['user_role'] === 'admin' ? 'admin_dashboard' : ($_SESSION['user_role'] === 'partner' ? 'partner' : 'collector') ?>" class="btn btn-sm btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
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
                <style>
                    #wa-logs::-webkit-scrollbar { width: 4px; }
                    #wa-logs::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
                    #wa-logs::-webkit-scrollbar-thumb { background: rgba(16, 185, 129, 0.3); border-radius: 10px; }
                    #wa-logs::-webkit-scrollbar-thumb:hover { background: rgba(16, 185, 129, 0.5); }
                </style>
                <div id="wa-logs" style="font-family:monospace; font-size:11px; color:#10b981; max-height:200px; overflow-y:auto; line-height:1.6; padding-right:5px;">
                    <div style="opacity:0.6;">> Menunggu aktivitas gateway...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
let qrcodeObj = null;
let currentQR = "";

/** 
 * REFRESH GATEWAY STATUS
 * Standardized to use the WAApiProxy (wa_proxy.php)
 */
async function refreshGateway() {
    const qrcodeEl = document.getElementById('qrcode');
    const logsEl = document.getElementById('wa-logs');
    const tipEl = document.getElementById('wa-connection-tip');
    const qrCont = document.getElementById('qr-container');
    const connBox = document.getElementById('wa-connected-box');

    try {
        // 1. Fetch Status
        const response = await fetch(WAApiProxy + 'status&cid=' + WAGatewayCID);
        const data = await response.json();
        
        if (data.error) {
            qrcodeEl.innerHTML = `<div style="color:#ef4444; font-size:12px; font-weight:700;"><i class="fas fa-exclamation-triangle"></i> GATEWAY OFFLINE<br><span style="font-weight:400; opacity:0.7;">${data.debug?.curl_error || 'Node.js server not responding'}</span></div>`;
            return;
        }

        if (data.connected) {
            qrCont.style.display = 'none';
            tipEl.style.display = 'none';
            connBox.style.display = 'block';
        } else {
            qrCont.style.display = 'inline-block';
            tipEl.style.display = 'block';
            connBox.style.display = 'none';
            
            // 2. Fetch QR if disconnected
            const qrResp = await fetch(WAApiProxy + 'qr&cid=' + WAGatewayCID);
            const qrData = await qrResp.json();
            
            if (qrData.qr && qrData.qr !== currentQR) {
                currentQR = qrData.qr;
                qrcodeEl.innerHTML = "";
                qrcodeObj = new QRCode(qrcodeEl, {
                    text: currentQR, width: 200, height: 200,
                    colorDark : "#000000", colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.H
                });
            } else if (!qrData.qr && !currentQR) {
                qrcodeEl.innerHTML = `<div style="color:#64748b; font-size:13px; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Menyiapkan QR Code...<br><span style="font-size:10px;">(Pesan: ${data.message || 'Mempersiapkan sesi'})</span></div>`;
            }
        }

        // 3. Fetch Logs
        const logResp = await fetch(WAApiProxy + 'logs&cid=' + WAGatewayCID);
        const logData = await logResp.json();
        if (logData && logData.length > 0) {
            const isAtBottom = logsEl.scrollHeight - logsEl.clientHeight <= logsEl.scrollTop + 20;
            logsEl.innerHTML = logData.map(l => `<div><span style="opacity:0.6;">[${l.timestamp}]</span> ${l.msg}</div>`).join('');
            if (isAtBottom) logsEl.scrollTop = logsEl.scrollHeight;
        }
    } catch (e) {
        qrcodeEl.innerHTML = '<div style="color:#ef4444; font-size:12px; font-weight:700;"><i class="fas fa-exclamation-triangle"></i> PROXY ERROR<br><span style="font-weight:400; opacity:0.7;">Gagal menghubungi wa_proxy.php</span></div>';
    }
}

async function logoutWA() {
    if (!confirm('Apakah Anda yakin ingin memutuskan koneksi WhatsApp?')) return;
    try {
        await fetch(WAApiProxy + 'logout', { 
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cid: WAGatewayCID })
        });
        location.reload();
    } catch (e) {
        alert('Gagal memutuskan koneksi. Proxy mungkin sedang offline.');
    }
}

// Start Polling every 5 seconds
setInterval(refreshGateway, 5000);
refreshGateway();
</script>
