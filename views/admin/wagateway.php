<div class="glass-panel" style="padding: 30px; max-width:600px; margin:0 auto; text-align:center;">
    <h3 style="font-size:22px; margin-bottom:10px;"><i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp Gateway API</h3>
    <p style="color:var(--text-secondary); margin-bottom:30px; line-height:1.5;">
        Fitur ini membutuhkan layanan NodeJS <code>wa-gateway</code> yang sudah dijalankan di komputer Anda pada <strong>Port 3000</strong>. Jika belum berjalan, status di bawah akan Gagal.
    </p>

    <div id="waStatusArea" style="padding:20px; background:rgba(15,23,42,0.6); border:1px solid var(--glass-border); border-radius:12px; margin-bottom:20px;">
        <i class="fas fa-circle-notch fa-spin fa-3x" style="color:var(--primary);"></i>
        <div style="margin-top:15px; font-weight:600;">Menghubungkan ke API Lokal...</div>
    </div>

    <!-- QR Code Area -->
    <div id="waQrArea" style="display:none; margin-top:20px;">
        <div style="background:#fff; padding:20px; border-radius:12px; display:inline-block; border: 4px solid #25D366;">
            <img id="waQrImage" src="" style="width:250px; height:250px; display:none;">
            <div id="waQrLoading" style="color:#000; padding:40px 20px; font-weight:bold;">Menyiapkan QR Code...<br><small>Mohon Tunggu</small></div>
        </div>
        <div style="margin-top:15px; color:var(--text-secondary);">Buka WhatsApp di HP Anda > Perangkat Tertaut > Tautkan Perangkat<br>Lalu scan kode QR ini.</div>
    </div>
</div>

<script>
    let checkInterval = null;

    function checkGatewayStatus() {
        fetch('http://localhost:3000/status')
            .then(response => response.json())
            .then(data => {
                if (data.connected) {
                    // Berhasil login!
                    document.getElementById('waStatusArea').innerHTML = `
                        <i class="fas fa-check-circle fa-4x" style="color:#25D366; margin-bottom:15px;"></i>
                        <h4 style="color:#25D366; margin:0;">WhatsApp API TERSAMBUNG!</h4>
                        <div style="margin-top:10px; color:var(--text-secondary);">Sistem siap mengirim notifikasi tagihan massal.</div>
                    `;
                    document.getElementById('waStatusArea').style.borderColor = '#25D366';
                    document.getElementById('waQrArea').style.display = 'none';
                    if (checkInterval) clearInterval(checkInterval);
                } else {
                    // Masih belum login, fetch QR
                    getQRCode();
                }
            })
            .catch(error => {
                // Gateway mati / gagal dihubungi
                document.getElementById('waStatusArea').innerHTML = `
                    <i class="fas fa-exclamation-triangle fa-3x" style="color:var(--danger); margin-bottom:15px;"></i>
                    <h4 style="color:var(--danger); margin:0;">API Gateway Tidak Merespon</h4>
                    <div style="margin-top:10px; color:var(--text-secondary); font-size:14px; text-align:left;">
                        <strong>Solusi:</strong><br>
                        1. Pastikan NodeJS / NPM sudah diinstal di Windows.<br>
                        2. Buka Terminal / CMD di folder <code>wa-gateway/</code>.<br>
                        3. Ketik perintah <code>npm install</code> untuk konfigurasi awal.<br>
                        4. Ketik perintah <code>node server.js</code> untuk menjalankan gateway.
                    </div>
                `;
                document.getElementById('waStatusArea').style.borderColor = 'var(--danger)';
                document.getElementById('waQrArea').style.display = 'none';
            });
    }

    function getQRCode() {
        fetch('http://localhost:3000/qr')
            .then(response => response.json())
            .then(data => {
                if (!data.error && data.qr) {
                    // Kita render QR ini menggunakan API
                    document.getElementById('waStatusArea').innerHTML = `
                        <div style="color:var(--warning); font-weight:600;"><i class="fas fa-mobile-alt"></i> Silakan Scan QR Code</div>
                    `;
                    document.getElementById('waStatusArea').style.borderColor = 'var(--warning)';
                    
                    document.getElementById('waQrArea').style.display = 'block';
                    document.getElementById('waQrLoading').style.display = 'none';
                    document.getElementById('waQrImage').style.display = 'block';
                    document.getElementById('waQrImage').src = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(data.qr);
                } else if(data.error && data.message) {
                    document.getElementById('waStatusArea').innerHTML = `<div style="color:var(--warning);">${data.message}</div>`;
                }
            })
            .catch(error => console.log('Wait QR Error:', error));
    }

    // Refresh every 3 seconds
    document.addEventListener("DOMContentLoaded", function() {
        checkGatewayStatus();
        checkInterval = setInterval(checkGatewayStatus, 3000);
    });
</script>
