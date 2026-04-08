const express = require('express');
const cors = require('cors');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Global States
let lastQR = null;
let isReady = false;
let logs = [];

// Logger Function
function addLog(msg) {
    const timestamp = new Date().toLocaleString('id-ID');
    logs.unshift({ timestamp, msg });
    if (logs.length > 50) logs.pop();
    console.log(`[${timestamp}] ${msg}`);
}

const client = new Client({
    authStrategy: new LocalAuth(),
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

// WhatsApp Events
client.on('qr', (qr) => {
    lastQR = qr;
    isReady = false;
    addLog('QR Code diperbarui, silakan scan...');
});

client.on('authenticated', () => {
    addLog('WhatsApp Terautentikasi (Sesi Ditemukan)');
});

client.on('ready', () => {
    isReady = true;
    lastQR = null;
    addLog('GATEWAY READY: WhatsApp Terhubung!');
});

client.on('disconnected', (reason) => {
    isReady = false;
    addLog(`WhatsApp Terputus: ${reason}`);
    // Delay re-init to avoid loop on network down
    setTimeout(() => client.initialize(), 5000);
});

client.initialize();

// API Endpoints
app.get('/status', (req, res) => {
    res.json({ 
        connected: isReady, 
        qr_available: !!lastQR,
        message: isReady ? 'Connected' : (lastQR ? 'QR Ready' : 'Initializing')
    });
});

app.get('/qr', (req, res) => {
    res.json({ qr: lastQR });
});

app.get('/logs', (req, res) => {
    res.json(logs);
});

app.post('/send', async (req, res) => {
    const { phone, message } = req.body;
    
    if (!isReady) {
        addLog(`Gagal mengirim ke ${phone}: Gateway belum siap`);
        return res.status(400).json({ error: true, message: 'Gateway belum siap' });
    }

    if (!phone || !message) {
        return res.status(400).json({ error: true, message: 'Nomor dan pesan wajib diisi' });
    }

    try {
        // Sanitize & Format
        let formattedPhone = phone.replace(/[^0-9]/g, '');
        if (formattedPhone.startsWith('0')) {
            formattedPhone = '62' + formattedPhone.slice(1);
        }
        const target = formattedPhone + '@c.us';

        await client.sendMessage(target, message);
        addLog(`Pesan berhasil dikirim ke: ${formattedPhone}`);
        res.json({ error: false, message: 'Pesan terkirim' });
    } catch (err) {
        addLog(`Error mengirim ke ${phone}: ${err.message}`);
        res.status(500).json({ error: true, message: 'Gagal mengirim: ' + err.message });
    }
});

app.post('/logout', async (req, res) => {
    try {
        await client.logout();
        isReady = false;
        lastQR = null;
        addLog('Sesi diputus manual oleh admin');
        res.json({ error: false, message: 'Logged out' });
        client.initialize();
    } catch (err) {
        res.status(500).json({ error: true, message: 'Gagal logout: ' + err.message });
    }
});

const PORT = 3000;
app.listen(PORT, '0.0.0.0', () => {
    addLog(`Server Gateway berjalan di http://0.0.0.0:${PORT}`);
});
