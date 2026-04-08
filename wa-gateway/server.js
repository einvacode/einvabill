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
});

client.on('authenticated', () => {
    console.log('Authenticated successfully.');
});

client.on('disconnected', (reason) => {
    console.log('Client was logged out:', reason);
    isConnected = false;
});

client.initialize();

app.get('/status', (req, res) => {
    res.json({
        connected: isConnected,
        qr_available: qrCodeData !== ''
    });
});

app.get('/qr', (req, res) => {
    if (isConnected) {
        return res.json({ error: false, message: 'Already connected', qr: null });
    }
    if (qrCodeData) {
        res.json({ error: false, qr: qrCodeData });
    } else {
        res.json({ error: true, message: 'QR Code not prepared yet. Please wait a moment.' });
    }
});

app.post('/send', async (req, res) => {
    if (!isConnected) {
        return res.status(400).json({ error: true, message: 'WhatsApp Client is not connected.' });
    }

    const { phone, message } = req.body;
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

const PORT = 3000;
app.listen(PORT, () => {
    console.log(`WhatsApp API Gateway is running on http://localhost:${PORT}`);
});
