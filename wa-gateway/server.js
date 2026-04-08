const express = require('express');
const cors = require('cors');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

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
    const cid = req.query.cid || 'admin';
    const instance = getClientInstance(cid);
    res.json({ 
        connected: instance.isReady, 
        qr_available: !!instance.lastQR,
        message: instance.isReady ? 'Connected' : (instance.lastQR ? 'QR Ready' : 'Initializing')
    });
});

app.get('/qr', (req, res) => {
    const cid = req.query.cid || 'admin';
    const instance = getClientInstance(cid);
    res.json({ qr: instance.lastQR });
});

app.get('/logs', (req, res) => {
    const cid = req.query.cid || 'admin';
    const instance = getClientInstance(cid);
    res.json(instance.logs);
});

app.post('/send', async (req, res) => {
    const { cid, phone, message } = req.body;
    const clientId = cid || 'admin';
    const instance = getClientInstance(clientId);
    
    if (!instance.isReady) {
        instance.addLog(`Gagal mengirim ke ${phone}: Perangkat belum siap`);
        return res.status(400).json({ error: true, message: 'Gateway belum siap' });
    }

    if (!phone || !message) {
        return res.status(400).json({ error: true, message: 'Nomor dan pesan wajib diisi' });
    }

    try {
        let formattedPhone = phone.replace(/[^0-9]/g, '');
        if (formattedPhone.startsWith('0')) {
            formattedPhone = '62' + formattedPhone.slice(1);
        }
        const target = formattedPhone + '@c.us';

        await instance.client.sendMessage(target, message);
        instance.addLog(`Pesan berhasil dikirim ke: ${formattedPhone}`);
        res.json({ error: false, message: 'Pesan terkirim' });
    } catch (err) {
        instance.addLog(`Error mengirim ke ${phone}: ${err.message}`);
        res.status(500).json({ error: true, message: 'Gagal mengirim: ' + err.message });
    }
});

app.post('/logout', async (req, res) => {
    const { cid } = req.body;
    const clientId = cid || 'admin';
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

const PORT = 3000;
app.listen(PORT, '0.0.0.0', () => {
    console.log(`WhatsApp Multi-Instance Gateway running on port ${PORT}`);
});
