# EinvaBill UI Guide

Panduan tampilan untuk semua halaman di dalam kerangka admin (`views/layout.php`).
Tujuannya: tampilan yang tenang, rapat, dan konsisten seperti alat kerja
profesional, bukan halaman marketing. Semua kelas di bawah tersedia dari
`public/tw-app.css` (Tailwind, dikompilasi dengan `npm run build:app`) dan
`public/ui.css`.

## Prinsip

1. **Satu aksen.** Petrol `primary` (#0F3A47) hanya untuk aksi utama dan
   status aktif. Warna lain hanya untuk makna: `signal` hijau = lunas/berhasil,
   `danger` merah = tunggakan/hapus, `accent` amber = perhatian/lisensi.
2. **Tanpa hiasan.** Tidak ada gradien, efek kaca, bayangan besar, ikon
   warna-warni per baris, emoji, huruf kapital semua dengan letter-spacing,
   animasi masuk, atau hover yang mengangkat elemen.
3. **Teks kalimat biasa.** Judul dan label dalam sentence case: "Daftar
   tagihan", bukan "DAFTAR TAGIHAN" atau "Daftar Tagihan".
4. **Angka rapi.** Semua nominal memakai `tabular-nums`, format
   `Rp 1.250.000` (spasi setelah Rp).
5. **Ikon hanya bila menggantikan kata.** Tombol aksi ikon di tabel boleh,
   ikon dekoratif di depan judul tidak.
6. **Radius 8 px untuk kontrol, 12 px untuk kartu.** Jangan 20 px, jangan pil
   kecuali badge status.
7. **Responsif.** Tabel lebar dibungkus `overflow-x-auto`; grid memakai
   `grid-cols-1 sm:grid-cols-2 xl:grid-cols-4`; tombol aksi utama penuh lebar di
   layar kecil (`w-full sm:w-auto`).

## Komponen

### Kepala halaman

```html
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h2 class="m-0 text-xl font-bold sm:text-2xl">Daftar tagihan</h2>
    <p class="m-0 mt-1 text-sm text-muted-foreground">Layanan rumahan, periode September 2026.</p>
  </div>
  <div class="flex flex-wrap gap-2">
    <a href="..." class="ui-btn ui-btn-outline">Ekspor</a>
    <a href="..." class="ui-btn ui-btn-primary"><i class="fas fa-plus"></i> Buat invoice</a>
  </div>
</div>
```

### Tab / navigasi sumber

```html
<div class="mb-5 flex flex-wrap gap-1 rounded-md bg-muted p-1 w-fit">
  <a href="..." class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground">Pelanggan</a>
  <a href="..." class="rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground shadow-card" aria-current="page">Tagihan</a>
</div>
```

### Ubin statistik

```html
<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
  <div class="ui-card p-4">
    <div class="text-xs font-medium text-muted-foreground">Total piutang</div>
    <div class="mt-1 text-2xl font-extrabold tabular-nums text-danger">Rp 7.000.000</div>
    <div class="text-xs text-muted-foreground">12 pelanggan menunggak</div>
  </div>
</div>
```

Warna hanya pada angka, dan hanya bila bermakna (merah untuk piutang, hijau
untuk penerimaan). Latar ubin selalu putih.

### Bilah filter

```html
<form method="get" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_180px_180px_auto] lg:items-end">
  <input type="hidden" name="page" value="admin_invoices">
  <label class="block">
    <span class="mb-1 block text-xs font-medium text-muted-foreground">Cari</span>
    <input name="q" class="form-control" placeholder="Nama, kode, atau nomor HP">
  </label>
  <label class="block">
    <span class="mb-1 block text-xs font-medium text-muted-foreground">Status</span>
    <select name="status" class="form-control">...</select>
  </label>
  <div class="flex gap-2">
    <button class="ui-btn ui-btn-primary">Terapkan</button>
    <a href="..." class="ui-btn ui-btn-outline">Reset</a>
  </div>
</form>
```

### Kartu berisi tabel

```html
<section class="ui-card overflow-hidden">
  <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
    <div>
      <h3 class="m-0 text-[15px] font-bold">Tunggakan pelanggan</h3>
      <p class="m-0 text-xs text-muted-foreground">Lima pelanggan dengan tunggakan terbanyak</p>
    </div>
    <a href="..." class="ui-btn ui-btn-sm ui-btn-outline">Lihat semua</a>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-sm">
      <thead><tr class="text-left text-[11px] font-semibold text-muted-foreground">
        <th class="px-4 py-2.5 font-semibold">Pelanggan</th> ...
      </tr></thead>
      <tbody>
        <tr class="border-t border-solid border-border">
          <td class="px-4 py-3">...</td>
        </tr>
      </tbody>
    </table>
  </div>
</section>
```

Baris: nama tebal 14 px, baris kedua 12 px `text-muted-foreground`. Aksi di
kolom paling kanan sebagai grup tombol ikon `ui-btn ui-btn-sm ui-btn-outline`
(ikon saja di layar kecil, ikon + teks di `sm:`).

### Badge status

`<span class="ui-badge ui-badge-signal">Lunas</span>` ·
`ui-badge-danger` Belum lunas / Terlambat · `ui-badge-accent` Menunggu ·
`ui-badge-muted` Nonaktif. Teks sentence case, tanpa ikon.

### Tombol

`ui-btn ui-btn-primary` satu per halaman untuk aksi utama. `ui-btn-outline`
untuk sekunder. `ui-btn-wa` hanya untuk kirim WhatsApp. Hapus memakai
`ui-btn-outline text-danger`. Ukuran kecil `ui-btn-sm`.

### Form

Label di atas input, `text-xs font-medium text-muted-foreground mb-1`.
Input memakai `form-control` (sudah digayakan). Kelompok dua kolom:
`grid gap-4 sm:grid-cols-2`. Tombol simpan di kanan bawah form:
`<div class="mt-6 flex justify-end gap-2">`.

### Modal

```html
<div id="..." class="fixed inset-0 z-[1000] hidden items-center justify-center bg-black/50 p-4">
  <div class="ui-card w-full max-w-lg p-5 sm:p-6">
    <div class="mb-4 flex items-start justify-between gap-4">
      <h3 class="m-0 text-lg font-bold">Edit invoice</h3>
      <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="...">✕</button>
    </div>
    ...
  </div>
</div>
```

Buka/tutup tetap memakai JavaScript yang ada; hanya ganti `display:flex`
menjadi juga menambah kelas `flex` bila perlu, atau biarkan `style.display`
karena `hidden` kalah oleh inline style.

### Keadaan kosong

`<div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data.</div>`
Tanpa ikon besar, tanpa emoji.

### Pesan sukses / error

```html
<div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Tersimpan.</span> Pengaturan berhasil diperbarui.</div>
<div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> Nomor rekening wajib diisi.</div>
```

## Aturan saat mengubah halaman lama

- Jangan ubah PHP di atas markup (query, POST handler, variabel).
- Pertahankan semua `id`, `name`, `onclick`, `data-*`, `href`, `action`,
  dan kelas yang dirujuk JavaScript (cari `getElementById`, `querySelector`,
  `classList` di file yang sama sebelum mengganti kelas).
- Hapus `<style>` sebaris hanya bila seluruh kelasnya sudah tidak dipakai.
- Setelah selesai: `php -l` harus bersih, halaman harus kembali 200 di
  server lokal, dan tidak ada `PHP Fatal`/`Warning` baru di log.
