<?php
// Protection: Any logged-in user can access their own gateway
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit;
}
?>


<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl"><?= ($_SESSION['user_role'] === 'admin' ? 'WhatsApp gateway' : 'Perangkat WhatsApp') ?></h2>
        <div class="wa-status-indicator mt-1 text-sm text-muted-foreground">Mengecek status...</div>
    </div>
    <a href="index.php?page=<?= $_SESSION['user_role'] === 'admin' ? 'admin_dashboard' : ($_SESSION['user_role'] === 'partner' ? 'partner' : 'collector') ?>" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <!-- QR Code Section -->
    <div class="ui-card p-5 text-center sm:p-6">
        <div id="qr-container" class="mb-4 inline-block min-h-[240px] min-w-[240px] rounded-md border border-solid border-border bg-white p-4">
            <div id="qrcode" style="display:flex; justify-content:center; align-items:center; height:200px;">
                <div class="text-[13px] text-muted-foreground"><i class="fas fa-spinner fa-spin"></i> Memuat QR code...</div>
            </div>
        </div>

        <div id="wa-connection-tip" class="mx-auto max-w-[280px] text-[13px] leading-relaxed text-muted-foreground">
            <p class="m-0">Silakan scan QR code di atas menggunakan menu <strong>Perangkat tertaut</strong> pada WhatsApp HP Anda.</p>
        </div>

        <div id="wa-connected-box" class="p-5" style="display:none;">
            <div class="text-lg font-bold text-signal">Terhubung</div>
            <p class="m-0 mt-1 text-[13px] text-muted-foreground">Sistem siap mengirim tagihan otomatis.</p>
            <button onclick="logoutWA()" class="ui-btn ui-btn-sm ui-btn-outline text-danger mt-5"><i class="fas fa-sign-out-alt"></i> Putuskan koneksi</button>
        </div>
    </div>

    <!-- Info & Stats Section -->
    <div class="flex flex-col gap-4">
        <div class="ui-card p-5">
            <h3 class="m-0 mb-3 text-[15px] font-bold">Cara kerja gateway</h3>
            <ul class="m-0 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>Gateway berfungsi sebagai "WhatsApp Web" bagi sistem.</li>
                <li>Sistem tidak menyimpan nomor tujuan atau pesan selain untuk keperluan pengiriman sementara.</li>
                <li><strong>Setiap pesan masal akan diberikan jeda 10 detik otomatis</strong> untuk menjaga keamanan nomor Anda dari blokir.</li>
            </ul>
        </div>

        <div class="ui-card p-5">
            <h3 class="m-0 mb-3 text-[15px] font-bold">Aktivitas terakhir</h3>
            <style>
                #wa-logs { scrollbar-width: thin; }
                #wa-logs::-webkit-scrollbar { width: 6px; }
                #wa-logs::-webkit-scrollbar-thumb { background: #D9E0E2; border-radius: 3px; }
            </style>
            <div id="wa-logs" class="max-h-[200px] overflow-y-auto rounded-md bg-muted p-3 font-mono text-[11px] leading-relaxed text-foreground">
                <div class="text-muted-foreground">> Menunggu aktivitas gateway...</div>
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
