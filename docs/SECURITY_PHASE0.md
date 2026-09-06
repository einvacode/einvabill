# Pengamanan Fase 0 — Catatan Deploy

Perubahan ini menutup celah yang bisa dieksploitasi dari luar tanpa
mengubah fitur. Setelah menarik versi ini ke server, lakukan langkah di
bawah **sekali**.

## 1. Apache: pastikan `.htaccess` dibaca

Aturan pemblokiran ada di `.htaccess` (root, `app/`, `data/`,
`wa-gateway/`, dua folder upload). Apache hanya membacanya jika
direktori punya `AllowOverride All`:

```apache
<Directory /var/www/einvabill>
    AllowOverride All
    Require all granted
</Directory>
```

Lalu `sudo systemctl reload apache2`. Uji: buka
`https://domain-anda/database.sqlite` dan `https://domain-anda/app/init.php`,
keduanya harus **403**.

Untuk Nginx, tambahkan blok `location` yang menolak `/data/`, `/app/`,
`/wa-gateway/`, `*.sqlite*`, `*.log`, `.env`, dan matikan eksekusi PHP
di `/public/uploads/` dan `/uploads/`.

## 2. Pindahkan database ke `data/`

Instalasi lama tetap jalan dengan `database.sqlite` di root (sudah
diblokir dari HTTP). Untuk memindahkannya, hentikan web server sebentar:

```bash
cd /var/www/einvabill
sudo systemctl stop apache2
mkdir -p data && mv database.sqlite* data/
chown -R www-data:www-data data
sudo systemctl start apache2
```

Backup sekarang tersimpan di `data/backups/`, bukan `backups/`. Salin
backup lama ke sana jika masih diperlukan.

## 3. Restart WhatsApp gateway

`wa-gateway/server.js` kini hanya menerima permintaan dengan token dan
mendengarkan di `127.0.0.1`. Restart prosesnya (pm2/systemd). Saat
pertama jalan ia membuat `wa-gateway/.gateway_token`; pastikan file itu
bisa dibaca oleh user web server (`www-data`):

```bash
chown www-data:www-data wa-gateway/.gateway_token
```

Jika PHP dan Node berjalan di mesin berbeda, set `WA_GATEWAY_HOST=0.0.0.0`
pada proses Node dan batasi port 3000 lewat firewall.

## 4. Password bawaan

Akun yang masih memakai password `123456` akan dipaksa mengganti
password saat login berikutnya. Beri tahu petugas tagih dan mitra.
Lima kali salah password mengunci akun/IP selama 15 menit.

## 5. Jangan pakai `php -S` di produksi

`start.bat` sekarang memakai `router.php` agar server bawaan PHP ikut
memblokir path sensitif, tetapi itu hanya untuk uji coba lokal.

## Ringkasan perubahan teknis

| Area | Perubahan |
|---|---|
| Skrip berbahaya | `cleanup_partner_customers.php`, `tmp/`, `scratch/`, `tools/` dihapus |
| Data runtime | database, sesi, backup, log pindah ke `data/` (dilindungi) |
| Header | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` |
| Upload | allowlist ekstensi + cek MIME + nama acak, PHP tidak dieksekusi di folder upload |
| SQL injection | sembilan `id` dari form di-`intval` |
| Login | `session_regenerate_id`, throttle 5x/15 menit, paksa ganti password bawaan, logout hapus cookie |
| CSRF | token di semua form POST dan `fetch()`, aksi ubah data wajib POST |
| WhatsApp gateway | wajib login + CSRF, cid dari sesi, token bersama, bind 127.0.0.1 |
| Skema DB | v25: `login_attempts`, `users.must_change_password`, kolom invoice yang dulu dibuat di halaman aset |
