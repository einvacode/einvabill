# 📢 Invoice Reminder Dashboard - Dokumentasi

## 🎯 Ringkasan Fitur

Fitur **Invoice Reminder** menampilkan daftar tagihan yang **akan jatuh tempo dalam 3 hari ke depan** di dashboard Admin dan Mitra.

Dari widget ini, mereka bisa langsung **kirim reminder via WhatsApp** ke pelanggan menggunakan WhatsApp Gateway yang sudah di-scan.

---

## 🔍 Bagaimana Cara Kerjanya?

### Sistem Filter H-3

```
Hari Ini = 11 Agustus 2026

Tampilkan tagihan dengan due_date:
- Overdue (past due)
- Hari ini (11 Agustus)
- Besok (12 Agustus)
- 3 hari lagi (13-14 Agustus)

Semua harus status "Belum Lunas" (Unpaid)
```

### Widget Interface

Widget menampilkan:
1. **Stats Bar** - Overview jumlah Overdue, H-3, Upcoming
2. **List Tagihan** - Setiap tagihan dengan customer info
3. **Quick Send Button** - Kirim WA reminder langsung
4. **Footer Actions** - "Lihat Semua" & "Kirim Semua"

---

## 📊 Contoh Display

```
┌─────────────────────────────────────────────────────────┐
│ 🔔 Reminder Tagihan Jatuh Tempo                       12│
│ Tagihan yang harus ditindaklanjuti dalam 3 hari ke depan
├─────────────────────────────────────────────────────────┤
│ Stats:
│ 🔴 Overdue: 3  |  🔵 H-3: 5  |  ⏳ Upcoming: 8       
├─────────────────────────────────────────────────────────┤
│ • Budi Santoso (Overdue 2 hari)   Rp 150.000  [Kirim]
│ • Ani Wijaya (Hari ini)           Rp 200.000  [Kirim]
│ • Rudi Priyanto (Besok)           Rp 300.000  [Kirim]
│ • Siti Nurhaliza (2 hari lagi)    Rp 250.000  [Kirim]
│ • Dedi Gunawan (3 hari lagi)      Rp 180.000  [Kirim]
├─────────────────────────────────────────────────────────┤
│ [Lihat Semua]                    [Kirim Semua (Beta)]
└─────────────────────────────────────────────────────────┘
```

---

## 🚀 Fitur - Kirim WhatsApp Reminder

### Step-by-Step

1. **Lihat Widget** di Dashboard Admin / Partner
2. **Klik tombol [Kirim]** di samping customer yang ingin dikirimi
3. **Sistem check:**
   - Apakah WhatsApp Gateway online?
   - Apakah sudah di-scan di menu "Perangkat WhatsApp"?
4. **Jika OK:** Pesan terkirim, tombol berubah hijau ✅
5. **Jika error:** Alert muncul dengan solusi

### Pesan Otomatis

Sistem menggunakan template WhatsApp yang sudah dikonfigurasi:

```
Halo {nama}, tagihan internet Anda {tagihan} ({bulan}) 
jatuh tempo pada {jatuh_tempo}. 
Hubungi admin untuk info pembayaran.
```

Variabel yang di-replace otomatis:
- `{nama}` → Nama pelanggan
- `{id_cust}` → ID pelanggan
- `{tagihan}` → Nominal tagihan
- `{bulan}` → Bulan tagihan (Agustus 2026, dll)
- `{jatuh_tempo}` → Tanggal jatuh tempo (11/08/2026)
- `{paket}` → Nama paket internet
- dll...

---

## 👥 Akses per Role

### Admin
- ✅ Lihat reminder untuk SEMUA pelanggan
- ✅ Kirim WhatsApp reminder
- ✅ Buka admin_wa_gateway untuk setup WhatsApp

### Partner/Mitra
- ✅ Lihat reminder untuk PELANGGAN MEREKA SENDIRI saja
- ✅ Kirim WhatsApp reminder
- ✅ Buka partner_wa_device untuk setup WhatsApp mereka

### Collector
- ❌ Tidak ada widget (fitur hanya untuk Admin & Partner)

---

## 📍 Lokasi Widget

### Di Dashboard Admin
- File: `views/admin/dashboard.php`
- Posisi: Setelah "WA Broadcast" component
- Height: 600px

### Di Dashboard Partner
- File: `views/partner/dashboard.php`
- Posisi: Setelah success message, sebelum Summary Grid
- Height: 500px

---

## 🔧 Konfigurasi

### Mengubah Tanggal Reminder (H-3, H-5, dll)

Saat ini reminder menampilkan **H-3** (3 hari sebelum jatuh tempo).

Untuk mengubah ke H-5 atau custom:

**File:** `app/reminder_widget.php` - line 27

```php
// Edit parameter ketiga (3 = 3 hari):
render_reminder_widget($db, $tenant_id, $user_id, $user_role, 500px);
//                                                               ↑
//                                                            ubah 3 ke 5
```

Lalu di call:
```php
render_reminder_widget($db, $tenant_id, $user_id, $user_role, '500px', 5);
```

### Mengubah Template WhatsApp

Admin/Partner bisa custom template di:
1. Admin: **Settings** → Profil & Apps → WA Template
2. Partner: **Partner Settings** → WhatsApp Template

---

## ⚠️ Prerequisite

### WhatsApp Gateway Harus Online

Widget memerlukan WhatsApp Gateway berjalan:

```bash
cd wa-gateway
npm start
```

Jika tidak berjalan, tombol [Kirim] akan show error:
```
"Gateway Offline - Harap nyalakan node server.js"
```

### WhatsApp Harus Di-Scan

Sebelum bisa kirim reminder:

1. Admin: Buka **Pengaturan** → **WA Perangkat**
2. Partner: Buka **Perangkat WhatsApp**
3. Scan QR Code dengan HP WhatsApp
4. Tunggu status menjadi "TERHUBUNG"

---

## 🎨 Styling & Responsive

Widget dirancang responsive:
- **Desktop**: Full width, 2-3 reminders visible
- **Tablet**: Full width, 1-2 reminders visible
- **Mobile**: Full width, scrollable list

---

## 📈 Performance

### Database Queries

Widget menggunakan optimized queries:
- 1 query untuk get_invoices_reminder()
- 5 queries untuk get_reminder_statistics()

Total: **~6 queries per load**, minimal impact.

### Caching

Tidak ada caching (real-time data). Data direfresh setiap kali user membuka dashboard.

---

## 🔌 API Integration

### WhatsApp Gateway API

Widget menggunakan:
```javascript
fetch(window.WAApiProxy + 'send', {
    method: 'POST',
    body: JSON.stringify({ 
        cid: window.WAGatewayCID, 
        phone: phone, 
        message: message 
    })
})
```

Endpoint: `/wa_proxy.php?path=/send`

---

## 🆘 Troubleshooting

### Problem: Widget tidak muncul di dashboard

**Solusi:**
1. Clear browser cache (Ctrl+Shift+Delete)
2. Refresh page (F5)
3. Check console untuk error (F12)
4. Pastikan reminder_widget.php ter-include

### Problem: Tombol [Kirim] disabled

**Solusi:**
1. Pastikan WhatsApp Gateway running (`npm start`)
2. Pastikan WhatsApp sudah di-scan
3. Lihat browser console untuk error detail

### Problem: Pesan tidak terkirim

**Penyebab & Solusi:**
- ❌ Gateway offline → Jalankan `npm start`
- ❌ WhatsApp belum scanned → Scan di "Perangkat WhatsApp"
- ❌ Nomor customer invalid → Check format (08xx atau 62xx)
- ❌ WhatsApp gateway error → Check server logs

### Problem: List kosong (Tidak ada reminder)

**Penyebab:**
1. Semua tagihan sudah pembayaran (status "Lunas")
2. Belum ada tagihan yang mendekati jatuh tempo
3. Semua jatuh tempo masih >3 hari lagi

**Solusi:**
- Tambahlah test invoice dengan due_date hari ini/besok
- Atau ubah filter H-3 → H-7 untuk lihat lebih banyak

---

## 📝 Files Modified

| File | Perubahan |
|------|-----------|
| `app/invoice_reminder.php` | ✅ Dibuat - Helper functions |
| `app/reminder_widget.php` | ✅ Dibuat - Widget component |
| `views/admin/dashboard.php` | ✅ Tambah widget render |
| `views/partner/dashboard.php` | ✅ Tambah widget render |

---

## 🚀 Future Enhancements

Fitur yang bisa ditambah di masa depan:

- [ ] Kirim reminder otomatis setiap jam (scheduled job)
- [ ] Bulk send ke semua reminder sekaligus
- [ ] History log pengiriman reminder
- [ ] A/B testing template messages
- [ ] Retry mechanism untuk failed sends
- [ ] Slack/Email notifications (tidak hanya WA)
- [ ] Customizable reminder schedule per customer
- [ ] Multi-language support

---

## 📞 Support

Untuk pertanyaan atau issue:
1. Baca dokumentasi ini
2. Check troubleshooting section
3. Lihat browser console untuk error details
4. Hubungi tech support dengan:
   - Screenshot error
   - Browser console log
   - Server logs (/logs/wa_gateway.log)

---

**Status: ✅ READY FOR PRODUCTION**

Invoice Reminder siap digunakan! 🎉

Jangan lupa:
1. ✅ Jalankan WhatsApp Gateway (`npm start`)
2. ✅ Scan WhatsApp di "Perangkat WhatsApp"
3. ✅ Configure template messages
4. ✅ Test kirim reminder ke 1 customer
5. ✅ Baru gunakan di production

---

*Last Updated: 11 Agustus 2026*
