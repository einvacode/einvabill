const express = require('express');
const cors = require('cors');
const { Client, LocalAuth } = require('whatsapp-web.js');

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

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

let qrCodeData = '';
let isConnected = false;

client.on('qr', (qr) => {
    qrCodeData = qr;
    console.log('QR Code generated. Please fetch from API to scan.');
});

client.on('ready', () => {
    console.log('WhatsApp Gateway is Ready!');
    isConnected = true;
    qrCodeData = '';
    lastQR = qr;
    isReady = false;
    addLog('QR Code diperbarui, menunggu scan...');
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
});

client.initialize();

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
        return res.status(400).json({ error: true, message: 'Phone and message body are required.' });
    }

    // Sanitize phone number (strip spaces/symbols)
    let formattedPhone = phone.replace(/[^0-9]/g, '');
    
    // Auto convert prefix 0 to 62
    if (formattedPhone.startsWith('0')) {
        formattedPhone = '62' + formattedPhone.slice(1);
    }
    // Append WA domain
    const target = formattedPhone + '@c.us';

    try {
        await client.sendMessage(target, message);
        res.json({ error: false, message: 'Message sent successfully.' });
    } catch (err) {
        res.status(500).json({ error: true, message: 'Failed to send message: ' + err.toString() });
    }
});

app.post('/logout', async (req, res) => {
    try {
        await client.logout();
        isConnected = false;
        qrCodeData = '';
        res.json({ error: false, message: 'Logged out successfully.' });
        // Re-initialize to get a new QR
        client.initialize();
    } catch (err) {
        res.status(500).json({ error: true, message: 'Failed to logout: ' + err.toString() });
    }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, '0.0.0.0', () => {
    console.log(`WhatsApp API Gateway is running on http://0.0.0.0:${PORT}`);
});
