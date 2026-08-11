# 📋 Auto-Generate Tagihan - Dokumentasi Lengkap

## 🎯 Ringkasan Fitur

Fitur **Auto-Generate Tagihan** memungkinkan Admin untuk **otomatis membuat tagihan** untuk semua pelanggan berdasarkan **tanggal tagihan (billing_date)** mereka.

Sistem akan:
- ✅ Scan semua pelanggan aktif
- ✅ Cek kapan tanggal tagihan mereka (billing_date)
- ✅ Jika hari ini ≥ tanggal tagihan AND belum ada invoice bulan ini → create otomatis
- ✅ Simpan history setiap eksekusi

---

## 💡 Cara Kerja

### Konsep Billing Date

Setiap pelanggan memiliki **"Tanggal Tagihan"** (billing_date) yang disimpan di profil mereka.

**Contoh:**
| Pelanggan | Billing Date | Hari Ini | Status |
|-----------|--------------|----------|--------|
| Budi      | 10           | 8        | ❌ Belum waktunya (tunggu tgl 10) |
| Ani       | 10           | 12       | ✅ Sudah waktunya (buat invoice) |
| Rudi      | 15           | 15       | ✅ Hari ini (buat invoice) |

### Proses Otomatis

```
1. Admin klik "JALANKAN SEKARANG"
   ↓
2. Sistem iterasi semua pelanggan
   ↓
3. Untuk setiap pelanggan:
   - Cek billing_date mereka
   - Jika hari ini >= billing_date
   - Cek apakah sudah ada invoice bulan ini
   - Jika belum → CREATE invoice
   ↓
4. Tampilkan hasil:
   - Berapa invoice dibuat
   - Berapa dilewati
   - Detail setiap pelanggan
```

---

## 🚀 Cara Menggunakan

### Step 1: Akses Menu

1. Login sebagai **Admin**
2. Buka sidebar → **Pengaturan** → **Auto Tagihan**

### Step 2: TEST DULU (Simulasi)

**SELALU test dengan simulasi terlebih dahulu!**

```
Klik: "TEST DULU (Simulasi)"
```

Sistem akan menunjukkan apa yang AKAN terjadi tanpa benar-benar create invoice.

**Hasilnya:**
```
✅ Contoh hasil simulasi:
   Pelanggan Diproses: 45
   Invoice Akan Dibuat: 12
   Akan Dilewati: 33
   
   (Belum ada yang dibuat untuk real)
```

### Step 3: JALANKAN SEKARANG (Production)

Jika hasil simulasi OK, jalankan untuk real:

```
Klik: "JALANKAN SEKARANG"
Confirm: "Ya, saya yakin"
```

Sistem akan membuat invoice untuk semua pelanggan yang memenuhi kriteria.

---

## 📊 Membaca Hasil Report

### Status dalam Report

| Status | Arti | Contoh |
|--------|------|--------|
| **CREATED** / **WOULD BE CREATED** | Invoice berhasil dibuat (atau akan dibuat di simulasi) | Ani - Rp 300.000 ✅ |
| **SKIPPED** | Invoice sudah ada untuk bulan ini | Budi - (Sudah ada invoice Agustus) |
| **WAITING** | Belum waktunya (billing_date masih di depan) | Rudi - (Billing tgl 20, hari ini tgl 15) |
| **ERROR** | Terjadi kesalahan saat membuat | Mitra XYZ - Database error |

### Statistik

```
Total Pelanggan:  45 orang
Tagihan Dibuat:   12 invoice
Dilewati:         33 (sudah ada / belum waktunya)
Error:            0
```

---

## ⚙️ Konfigurasi

### Mengubah Billing Date Pelanggan

Billing date bisa diubah di **Profil Pelanggan**:

1. Admin → Pelanggan
2. Pilih pelanggan
3. Edit → Cari field "Billing Date" atau "Tanggal Tagihan"
4. Ubah ke tanggal yang diinginkan (1-28)
5. Simpan

### Contoh Skenario

**Skenario 1: Billing Awal Bulan**
```
- Setiap pelanggan billing_date = 5
- Setiap tanggal 5, jalankan Auto-Generate
- Invoices untuk bulan ini akan tercipta otomatis
```

**Skenario 2: Billing Tengah Bulan**
```
- Pelanggan punya billing_date berbeda (10, 15, 20, 25)
- Jalankan Auto-Generate setiap hari
- Sistem akan create invoice saat tanggal sesuai
```

**Skenario 3: Billing Akhir Bulan**
```
- billing_date = 28
- Tanggal 28 → jalankan Auto-Generate
- Invoice untuk Agustus tercipta pada 28 Agustus
```

---

## 📝 History & Logging

### Riwayat Eksekusi

Setiap kali Admin jalankan Auto-Generate, sistem menyimpan:
- 📅 Tanggal & waktu eksekusi
- 📊 Berapa invoice dibuat
- ⏭️ Berapa dilewati
- ⚠️ Error (jika ada)

Lihat di tab **"Riwayat Eksekusi"** untuk track history.

---

## 🛡️ Keamanan & Validasi

### Duplikasi Protection

Sistem memiliki safety check untuk mencegah duplikasi:

```sql
-- Sebelum create invoice, sistem cek:
SELECT COUNT(*) FROM invoices 
WHERE customer_id = X 
AND strftime('%Y-%m', due_date) = '2026-08'

-- Jika sudah ada → SKIP, jangan create ulang
```

### Tenant Isolation

Setiap tenant terisolasi:
- Admin Tenant A hanya bisa auto-generate untuk customernya
- Admin Tenant B tidak akan kelihatan customer Tenant A

### Billing Date Validation

Sistem handle edge cases:
- Tanggal 29, 30, 31 → dikonvert ke hari terakhir bulan
- Februari 30 → jadi 28/29 (sesuai tahun)
- Invalid dates → automatic fallback

---

## 🔧 Troubleshooting

### Problem: "Invoice Sudah Ada"

**Penyebab:** Invoices untuk bulan itu sudah dibuat sebelumnya

**Solusi:** 
```
Lihat riwayat → cek berapa yang sudah dibuat
Atau cek di menu Tagihan → filter bulan tersebut
```

### Problem: "Simulasi Tapi Tidak Ada yang Dibuat"

**Penyebab:** Mungkin:
1. Semua pelanggan sudah punya invoice bulan ini
2. Belum ada pelanggan yang mencapai billing_date mereka
3. Pelanggan dalam status inactive

**Solusi:**
```
1. Cek profil pelanggan → billing_date mereka
2. Cek menu Tagihan → sudah ada invoice bulan ini?
3. Filter pelanggan aktif di daftar
```

### Problem: "Error saat Creating Invoice"

**Penyebab:** Database error atau data validation gagal

**Solusi:**
1. Hubungi tech support
2. Lihat error message di report
3. Check database logs di `/logs/` (jika ada)

---

## 📋 API Command Line (Optional)

Untuk automation lebih lanjut, bisa jalankan via CLI:

```bash
# Test/simulasi
php app/auto_invoice_generator.php --simulate

# Production (benar-benar create)
php app/auto_invoice_generator.php

# Jalankan dari cron job (Automatic)
# Tambahkan ke crontab:
0 1 * * * cd /path/to/einvabill && php app/auto_invoice_generator.php
```

---

## 🎯 Best Practices

### ✅ DO's

- ✅ **Test dulu** sebelum jalankan di production
- ✅ **Jalankan rutin** (misalnya tiap hari jam 1 pagi)
- ✅ **Monitor history** untuk memastikan berjalan lancar
- ✅ **Atur billing_date** dengan konsisten per customer
- ✅ **Backup database** sebelum bulk operation

### ❌ DON'Ts

- ❌ Jangan jalankan berkali-kali dalam sehari (duplikasi)
- ❌ Jangan ubah billing_date sambil proses berjalan
- ❌ Jangan hapus invoices yang baru dibuat (duplikasi lagi)
- ❌ Jangan abaikan error messages

---

## 📞 Support & FAQ

### Q: Berapa lama proses berjalan?

**A:** Tergantung jumlah pelanggan:
- 100 pelanggan: ~2-5 detik
- 1000 pelanggan: ~10-30 detik
- 5000 pelanggan: ~1-2 menit

### Q: Apa bedanya dengan "Tagih Masal" di Invoice?

| Fitur | Auto-Generate | Tagih Masal |
|-------|---------------|-----------|
| Trigger | Otomatis per billing_date | Manual, pilih bulan |
| Cakupan | Semua pelanggan sesuai schedule | Filter tertentu |
| Frequency | Setiap hari / sesuai cron | Kapan saja Admin mau |
| Use Case | Daily automation | Bulk create untuk bulan tertentu |

### Q: Bisa di-schedule otomatis?

**A:** Ya! Dengan Cron Job atau Task Scheduler:
- Linux: `crontab -e` → tambah `0 1 * * * php app/auto_invoice_generator.php`
- Windows: Task Scheduler → jalankan PHP script tiap hari

### Q: Apakah menghapus invoice lama?

**A:** Tidak! Hanya create invoice BARU. Invoice lama tetap ada.

---

## 📈 Contoh Skenario Real

### Skenario: ISP dengan 200 Pelanggan

**Kebutuhan:**
- Pelanggan dengan billing_date berbeda-beda
- Otomatis create invoice setiap hari sesuai billing_date mereka
- Mengurangi manual work

**Implementasi:**

1. **Setup Billing Date**
   ```
   200 pelanggan di-setup dengan billing_date tertentu
   - Group A (50 orang): billing_date = 5
   - Group B (75 orang): billing_date = 15
   - Group C (75 orang): billing_date = 25
   ```

2. **Setup Cron Job**
   ```bash
   # Jalankan tiap hari jam 1 pagi
   0 1 * * * cd /einvabill && php app/auto_invoice_generator.php >> /var/log/auto_invoice.log 2>&1
   ```

3. **Hasil**
   ```
   Setiap hari sistem secara otomatis:
   - Tgl 5: Create 50 invoices untuk Group A
   - Tgl 15: Create 75 invoices untuk Group B
   - Tgl 25: Create 75 invoices untuk Group C
   
   Total: 200 invoices per bulan, TANPA manual action
   ```

---

## 📝 Changelog

**v1.0 (11 Agustus 2026)**
- ✅ Initial release
- ✅ Auto-generate berdasarkan billing_date
- ✅ Simulasi mode
- ✅ History logging
- ✅ Tenant isolation
- ✅ Duplikasi protection

---

**Siap digunakan! Hubungi support jika ada pertanyaan.** 🚀

