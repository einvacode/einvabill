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

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Perangkat WhatsApp saya</h2>
        <div class="wa-status-indicator mt-1 text-sm text-muted-foreground">Mengecek status...</div>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="index.php?page=partner" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>
</div>

<div class="grid gap-5 lg:grid-cols-2">
    <!-- QR Code Section -->
    <section class="ui-card p-5 text-center">
        <div id="qr-container" class="mb-4 inline-block min-h-[240px] min-w-[240px] rounded-md border border-solid border-border bg-white p-5">
            <div id="qrcode" class="flex h-[200px] items-center justify-center">
                <div class="text-sm text-muted-foreground"><i class="fas fa-spinner fa-spin"></i> Memuat QR code...</div>
            </div>
        </div>

        <div id="wa-connection-tip" class="mx-auto max-w-[280px] text-sm leading-relaxed text-muted-foreground">
            <p class="m-0">Silakan scan QR code di atas menggunakan menu <strong class="text-foreground">Perangkat tertaut</strong> pada WhatsApp HP Anda.</p>
        </div>

        <div id="wa-connected-box" class="p-5" style="display:none;">
            <h4 class="m-0 mb-2 text-lg font-bold text-signal">Terhubung</h4>
            <p class="m-0 text-sm text-muted-foreground">Anda sekarang bisa mengirim tagihan ke pelanggan melalui WhatsApp.</p>
            <button onclick="logoutWA()" class="ui-btn ui-btn-outline text-danger mt-5"><i class="fas fa-sign-out-alt"></i> Putuskan koneksi</button>
        </div>
    </section>

    <!-- Info & Stats Section -->
    <div class="flex flex-col gap-5">
        <section class="ui-card p-5">
            <h3 class="m-0 mb-3 text-[15px] font-bold">Cara kerja</h3>
            <ul class="m-0 list-disc pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>Scan QR code untuk menghubungkan WhatsApp Anda dengan portal.</li>
                <li>Setelah terhubung, Anda bisa mengirim tagihan ke pelanggan melalui WhatsApp.</li>
                <li>Sistem otomatis menambahkan delay 10 detik antar pesan untuk keamanan nomor Anda.</li>
                <li>Koneksi aman: sistem tidak menyimpan password WhatsApp Anda.</li>
            </ul>
        </section>

        <section class="ui-card p-5">
            <h3 class="m-0 mb-3 text-[15px] font-bold">Aktivitas terakhir</h3>
            <div id="wa-logs" class="max-h-[200px] overflow-y-auto rounded-md bg-muted p-3 font-mono text-[11px] leading-relaxed text-foreground">
                <div class="text-muted-foreground">> Menunggu aktivitas perangkat...</div>
            </div>
        </section>
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
