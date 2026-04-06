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
Setelah masuk ke terminal LXC Anda (via console atau SSH), jalankan perintah berikut:

```bash
# Update sistem
apt update && apt upgrade -y

# Instal Apache, PHP, SQLite, dan Git
apt install apache2 php libapache2-mod-php php-sqlite3 git -y

# Aktifkan Mod rewrite untuk URL yang cantik (Opsional)
a2enmod rewrite
systemctl restart apache2
```

---

## 3. Clone Repository dari GitHub
Pindah ke folder web server dan tarik kode Anda:

```bash
# Pindah ke folder web
cd /var/www/html

# Clone dari repository Anda
git clone https://github.com/einvacode/einvabill.git einvabill

# (Opsional) Hapus file index default apache jika ada
rm index.html
```

---

## 4. Pengaturan Izin File (PENTING! ⚠️)
Di Linux, server web (Apache) perlu izin khusus untuk menulis database dan file upload. Jika langkah ini dilewati, aplikasi tidak bisa menyimpan data.

```bash
# Berikan kepemilikan folder ke user web (www-data)
# SANGAT PENTING: Jika tidak dilakukan, data akan 'kosong' karena database tidak bisa menyimpan perubahan.
chown -R www-data:www-data /var/www/html/einvabill

# Berikan izin tulis (Write Access)
chmod -R 775 /var/www/html/einvabill
```

> [!IMPORTANT]
> **Data Kosong di Proxmox/Linux?**
> Jika Anda login dan data tidak tersimpan (selalu kembali ke default), pastikan user `www-data` memiliki izin akses **TULIS** ke folder aplikasi DAN file `database.sqlite`. Perintah `chown` di atas adalah kunci utama untuk memperbaiki masalah ini.

---

## 5. Konfigurasi VirtualHost (Agar Akses Lebih Rapi)
Edit file konfigurasi apache:
`nano /etc/apache2/sites-available/000-default.conf`

Ubah `DocumentRoot` menjadi:
`DocumentRoot /var/www/html/einvabill`

Simpan (CTRL+O, Enter, CTRL+X) lalu restart:
`systemctl restart apache2`

---

## 6. Selesai! 🎉
Akses aplikasi melalui IP address Proxmox LXC Anda di browser.
Contoh: `http://192.168.1.100`

> [!TIP]
> **HTTPS/SSL:** Untuk produksi publik, sangat disarankan memasang **Certbot (Let's Encrypt)** agar data pelanggan Anda terenkripsi dengan aman (SSL).
