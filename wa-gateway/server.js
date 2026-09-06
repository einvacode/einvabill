const express = require('express');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// ---------------------------------------------------------------------
// Access control
//
// The gateway is only ever called by wa_proxy.php on the same machine.
// It listens on loopback and requires a shared secret that is generated
// on first start and stored next to this file (.gateway_token). The PHP
// proxy reads the same file. Set WA_GATEWAY_HOST=0.0.0.0 only if PHP
// runs on another host, and then firewall port 3000 accordingly.
// ---------------------------------------------------------------------
const TOKEN_FILE = path.join(__dirname, '.gateway_token');
let GATEWAY_TOKEN = process.env.WA_GATEWAY_TOKEN || '';
if (!GATEWAY_TOKEN) {
    try {
        GATEWAY_TOKEN = fs.readFileSync(TOKEN_FILE, 'utf8').trim();
    } catch (e) {
        GATEWAY_TOKEN = crypto.randomBytes(32).toString('hex');
        fs.writeFileSync(TOKEN_FILE, GATEWAY_TOKEN + '\n', { mode: 0o600 });
        console.log(`Token gateway baru dibuat di ${TOKEN_FILE}`);
    }
}

function timingSafeEqual(a, b) {
    const ba = Buffer.from(String(a));
    const bb = Buffer.from(String(b));
    return ba.length === bb.length && crypto.timingSafeEqual(ba, bb);
}

app.use((req, res, next) => {
    const given = req.get('X-Gateway-Token') || '';
    if (!given || !timingSafeEqual(given, GATEWAY_TOKEN)) {
        return res.status(401).json({ error: true, message: 'Unauthorized gateway request' });
    }
    next();
});

// Client ids are constrained to the format wa_proxy.php produces.
function safeCid(raw) {
    const cid = String(raw || 'admin');
    return /^[A-Za-z0-9_-]{1,64}$/.test(cid) ? cid : 'admin';
}

// Global Client Registry
const clients = new Map();

// Helper: Get or Initialize Client
function getClientInstance(cid) {
    if (clients.has(cid)) return clients.get(cid);

    const client = new Client({
        authStrategy: new LocalAuth({ clientId: cid }),
        puppeteer: {
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--disable-gpu'
            ]
        }
    });

    const instance = {
        client,
        isReady: false,
        lastQR: null,
        logs: [],
        addLog: function(msg) {
            const timestamp = new Date().toLocaleString('id-ID');
            this.logs.push({ timestamp, msg });
            if (this.logs.length > 50) this.logs.shift();
            console.log(`[${cid}][${timestamp}] ${msg}`);
        }
    };

    clients.set(cid, instance);

    client.on('qr', (qr) => {
        instance.lastQR = qr;
        instance.isReady = false;
        instance.addLog('QR Code diperbarui, silakan scan...');
    });

    client.on('authenticated', () => {
        instance.addLog('WhatsApp Terautentikasi (Sesi Ditemukan)');
    });

    client.on('ready', () => {
        instance.isReady = true;
        instance.lastQR = null;
        instance.addLog('GATEWAY READY: Berhasil Terhubung!');
    });

    client.on('disconnected', (reason) => {
        instance.isReady = false;
        instance.addLog(`WhatsApp Terputus: ${reason}`);
        // Optional: delete instances with fatal disconnection to save RAM
        // clients.delete(cid);
    });

    instance.addLog('Memulai inisialisasi perangkat...');
    client.initialize().catch(err => {
        instance.addLog(`Gagal Inisialisasi: ${err.message}`);
    });

    return instance;
}

// API Endpoints
app.get('/status', (req, res) => {
    const instance = getClientInstance(safeCid(req.query.cid));
    res.json({
        connected: instance.isReady,
        qr_available: !!instance.lastQR,
        message: instance.isReady ? 'Connected' : (instance.lastQR ? 'QR Ready' : 'Initializing')
    });
});

app.get('/qr', (req, res) => {
    const instance = getClientInstance(safeCid(req.query.cid));
    res.json({ qr: instance.lastQR });
});

app.get('/logs', (req, res) => {
    const instance = getClientInstance(safeCid(req.query.cid));
    res.json(instance.logs);
});

app.post('/send', async (req, res) => {
    const { phone, message } = req.body;
    const clientId = safeCid(req.body.cid);
    const instance = getClientInstance(clientId);

    if (!instance.isReady) {
        instance.addLog(`Gagal mengirim ke ${phone}: Perangkat belum siap`);
        return res.status(400).json({ error: true, message: 'Gateway belum siap' });
    }

    if (!phone || !message) {
        return res.status(400).json({ error: true, message: 'Nomor dan pesan wajib diisi' });
    }

    try {
        let formattedPhone = String(phone).replace(/[^0-9]/g, '');
        if (formattedPhone.startsWith('0')) {
            formattedPhone = '62' + formattedPhone.slice(1);
        }
        const target = formattedPhone + '@c.us';

        await instance.client.sendMessage(target, String(message));
        instance.addLog(`Pesan berhasil dikirim ke: ${formattedPhone}`);
        res.json({ error: false, message: 'Pesan terkirim' });
    } catch (err) {
        instance.addLog(`Error mengirim ke ${phone}: ${err.message}`);
        res.status(500).json({ error: true, message: 'Gagal mengirim: ' + err.message });
    }
});

app.post('/logout', async (req, res) => {
    const clientId = safeCid(req.body.cid);
    if (!clients.has(clientId)) return res.json({ success: true });

    const instance = clients.get(clientId);
    try {
        await instance.client.logout();
        instance.isReady = false;
        instance.lastQR = null;
        instance.addLog('Sesi diputus manual oleh pengguna');
        res.json({ error: false, message: 'Logged out' });
        // Re-init to get a new QR if needed, or just let users re-refresh
        instance.client.initialize();
    } catch (err) {
        res.status(500).json({ error: true, message: 'Gagal logout: ' + err.message });
    }
});

const PORT = parseInt(process.env.WA_GATEWAY_PORT || '3000', 10);
const HOST = process.env.WA_GATEWAY_HOST || '127.0.0.1';
app.listen(PORT, HOST, () => {
    console.log(`WhatsApp Multi-Instance Gateway running on ${HOST}:${PORT}`);
});
