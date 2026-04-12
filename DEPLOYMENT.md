# 🌐 DEPLOYMENT: EinvaBill on Proxmox (LXC)

Panduan ini menjelaskan cara memindahkan aplikasi **EinvaBill** dari XAMPP/Windows ke server produksi berbasis **Proxmox**.

---

## 1. Persiapan LXC Container
Disarankan menggunakan **LXC** (Linux Container) karena jauh lebih hemat resource dibanding VM.
-   **Template**: Ubuntu 22.04 LTS atau Debian 12.
-   **Resource Minim**: 1 Core CPU, 1GB RAM, 10GB Storage.
-   **Network**: Bridge (Statik atau DHCP).

---

## 2. Instalasi Web Server & PHP
Setelah masuk ke terminal LXC Anda, jalankan perintah berikut:

```bash
# Update sistem
apt update && apt upgrade -y

# Instal Web Server, PHP, dan semua ekstensi yang dibutuhkan
# Apache Version:
apt install apache2 php libapache2-mod-php php-sqlite3 php-curl php-gd php-mbstring php-xml php-zip git -y

# Nginx Version (Alternative):
# apt install nginx php-fpm php-sqlite3 php-curl php-gd php-mbstring php-xml php-zip git -y

# Aktifkan Mod rewrite untuk Apache
a2enmod rewrite
systemctl restart apache2
```

---

## 3. Clone & Diagnostik
Pindah ke folder web server dan tarik kode Anda:

```bash
cd /var/www/html
git clone https://github.com/einvacode/einvabill.git einvabill
rm index.html # Hapus file default apache
```

> [!IMPORTANT]
> **Langkah Pertama: Diagnostik**
> Buka browser dan akses `http://ip-server/einvabill/check_env.php`. 
> Alat ini akan memberitahu Anda secara detail jika ada izin folder atau ekstensi PHP yang kurang.

---

## 4. Pengaturan Izin File (PENTING! ⚠️)
Di Linux/Proxmox, user `www-data` harus memiliki akses tulis.

```bash
# Berikan kepemilikan folder ke user web
chown -R www-data:www-data /var/www/html/einvabill

# Berikan izin tulis (Write Access)
chmod -R 775 /var/www/html/einvabill
```

---

## 5. Konfigurasi Web Server

### A. Jika menggunakan Apache
Edit file: `/etc/apache2/sites-available/000-default.conf`
Ubah `DocumentRoot` menjadi `/var/www/html/einvabill`
Lalu tambahkan blok berikut di bawah DocumentRoot:
```apache
<Directory /var/www/html/einvabill>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```
`systemctl restart apache2`

### B. Jika menggunakan Nginx
Buat file konfigurasi: `/etc/nginx/sites-available/einvabill`
```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/einvabill;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock; # Sesuaikan versi PHP
    }
}
```
`ln -s /etc/nginx/sites-available/einvabill /etc/nginx/sites-enabled/`
`systemctl restart nginx`

---

## 6. Selesai! 🎉
Akses aplikasi melalui IP address Proxmox LXC Anda.

> [!TIP]
> **Common Issues di Proxmox:**
> 1. **Permission Denied**: Selalu jalankan `chown -R www-data:www-data` setelah melakukan update/git pull.
> 2. **SQLite Locked**: Pastikan container tidak kehabisan storage (disk full).

---

## 💼 Pembelian Lisensi & Dukungan Berbayar

Jika Anda ingin membeli lisensi aplikasi ini, meminta bantuan instalasi di server produksi, atau membutuhkan kustomisasi fitur, silakan hubungi:

- **Kontak Penjualan / Dukungan:** 0823-4626-8845

Tim kami akan membantu proses pembelian lisensi dan penjadwalan layanan dukungan teknis.
