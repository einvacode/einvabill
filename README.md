# 🚀 EinvaBill - RT/RW Net Management

**EinvaBill** adalah aplikasi manajemen penagihan (billing) mandiri berkekuatan tinggi yang dirancang khusus untuk pengelola RT/RW Net, ISP lokal, dan penyedia layanan IT. Dibangun dengan fokus pada kecepatan, transparansi keuangan, dan kemudahan operasional.

![Versi](https://img.shields.io/badge/Versi-1.5.0--Stable-blue?style=for-the-badge)
![Lisensi](https://img.shields.io/badge/SaaS--Ready-True-green?style=for-the-badge)

---

## ✨ Fitur Unggulan

-   **📡 Integrasi MikroTik API**: Kelola status pelanggan langsung dari router secara real-time.
-   **💳 Manajemen Tunggakan Cerdas**: Rincian tagihan otomatis mencakup piutang lama agar penagihan lebih transparan.
-   **💬 Template WhatsApp Dinamis**: Kirim nota dan pengingat via WA dengan variabel kustom (`{nama}`, `{total_harus}`, dll).
-   **🎯 Landing Page Dinamis**: Ubah profil perusahaan, layanan, dan logo mitra tanpa batas langsung dari dashboard.
-   **🔐 Sistem Lisensi Built-in**: Mendukung mode Trial 7 Hari, lisensi Tahunan, serta Master Key untuk Administrator Utama.
-   **📊 Laporan Keuangan Audit-Ready**: Bedakan pendapatan dari Tagihan Baru vs Kejar Tunggakan.
-   **📥 Ekspor Data**: Cadangkan data pelanggan ke format CSV/Excel dalam satu klik.
-   **🔄 Self-Updater**: Perbarui aplikasi secara otomatis langsung dari repository Git di server pusat.

---

## 🛠️ Persyaratan Sistem

-   **Web Server**: XAMPP / Apache / Nginx.
-   **PHP Version**: 7.4 atau lebih tinggi.
-   **Database**: SQLite (Sudah terintegrasi, tidak perlu setting database manual).
-   **Extensi PHP**: `pdo_sqlite`, `curl` (untuk API).

---

## 📦 Panduan Instalasi (Lokal / Web)

1.  **Download / Clone**:
    ```bash
    git clone https://github.com/einvacode/einvabilling.git
    ```
2.  **Pindahkan ke htdocs**: Masukkan seluruh folder ke dalam root server Anda (misal: `C:\xampp\htdocs\antigravity`).
3.  **Buka di Browser**: Akses melalui `http://localhost/antigravity`.
4.  **Selesai!** Database akan dibuat secara otomatis pada kunjungan pertama.

---

## 🔑 Akun Default (Login Pertama)

| Akses | Username | Password |
| :--- | :--- | :--- |
| **Administrator** | `admin` | `123456` |
| **Petugas Tagih** | `tagih` | `123456` |

> [!CAUTION]
> **KEAMANAN:** Segera ganti password default Anda setelah berhasil login pertama kali demi keamanan data.

---

## 📜 Info Lisensi & Master Key

Aplikasi ini menggunakan sistem proteksi lisensi. Untuk akses penuh selamanya (Unlimited), Anda dapat menggunakan **Master Key** pada menu **Aktivasi Lisensi**.

Dapatkan dukungan penuh untuk instalasi atau kustomisasi fitur dengan menghubungi pengelola repository ini.

---

## 🤝 Kontribusi

Tertarik untuk mengembangkan fitur bersama? Silakan lakukan **Fork** dan kirimkan **Pull Request** Anda. Mari bangun ekosistem internet Indonesia yang lebih baik! 🇮🇩

---
*Developed with ❤️ by [Einva Code](https://github.com/einvacode)*
