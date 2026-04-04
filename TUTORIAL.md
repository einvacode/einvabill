# 📖 Master Tutorial: EinvaBill Setup & Deployment

Selamat datang di panduan resmi **EinvaBill**! Dokumen ini dirancang untuk membimbing Anda dari nol hingga memiliki server billing yang menyala 24 jam di infrastruktur profesional.

---

## 🛠️ TAHAP 1: Persiapan di Windows (Local Development)
Ideal untuk mencoba fitur baru sebelum diunggah ke server asli.

1.  **Instal XAMPP**: Pastikan **Apache** menyala.
2.  **Akses Browser**: Buka `http://localhost/namafolder`.
3.  **Database**: Aplikasi akan otomatis membuat file `database.sqlite`. Jangan pernah hapus file ini demi data pelanggan Anda.
4.  **Login Default**: Use `admin` / `123456`.

---

## 🚀 TAHAP 2: Mengunggah ke GitHub (Safe Backup)
Cara aman mencadangkan kode tanpa membocorkan data pribadi.

1.  **Instal Git & GitHub Desktop**: Software wajib untuk sinkronisasi.
2.  **Add Local Repo**: Pilih folder aplikasi Anda.
3.  **Konfigurasi .gitignore**: Pastikan file `database.sqlite` tercentang/ada dalam daftar abaikan.
4.  **Commit & Push**: Kode Anda sekarang aman di awan (Cloud).

---

## 🌐 TAHAP 3: Deployment ke Proxmox (Production Server)
Cara membuat server "Online" 24 Jam.

1.  **Buat Container (LXC)**: Gunakan Ubuntu 22.04 (Spek: 1 CPU, 512MB-1GB RAM).
2.  **Instal Engine**: Jalankan perintah di `DEPLOYMENT.md` (Apache, PHP, SQLite).
3.  **Clone Kode**: Tarik kode dari link GitHub Anda.
4.  **SET PERMISSION (PENTING!)**: Jalankan `chown -R www-data:www-data /var/www/html/` agar sistem bisa menyimpan data pelanggan.

---

## 🔑 TAHAP 4: Aktivasi & Penyetelan Perdana
Langkah awal setelah aplikasi menyala di server baru.

1.  **Aktivasi Lisensi**: Masukkan **Master Key** (`AG-ULTIMATE-2026`) di menu Aktivasi.
2.  **API Mikrotik**: Masukkan IP Mikrotik Anda di menu **Pengaturan > Router**.
3.  **WhatsApp**: Edit template pesan penagihan di menu **Pengaturan > WA Template**.
4.  **Landing Page**: Ubah logo dan profil perusahaan Anda di menu **Layanan Landing**.

---

## 🔄 TAHAP 5: Maintenance & Update
Bagaimana cara mendapatkan fitur baru dari saya (Antigravity)?

1.  **Update di Lokal**: Saya (AI) akan mengedit kode di komputer lokal Anda.
2.  **Push ke GitHub**: Anda mengunggah kode baru tersebut ke GitHub Anda via GitHub Desktop.
3.  **Tarik ke Server**: Di server Proxmox, buka menu **Pengaturan > System > Update Sekarang**.
4.  **Selesai!** Aplikasi Anda di server otomatis berubah mengikuti versi terbaru tanpa ribet instal ulang.

---

> [!IMPORTANT]
> **Keamanan Adalah Kunci**: Selalu gunakan password yang kuat untuk admin dan pastikan firewall Proxmox Anda terkonfigurasi dengan baik hanya membuka port 80 (HTTP) dan 443 (HTTPS) jika perlu.

Dibuat untuk membantu Anda menguasai **EinvaBill** secara total! 🇮🇩
