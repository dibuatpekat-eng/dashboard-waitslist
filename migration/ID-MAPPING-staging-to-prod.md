# Mapping ID Produk — Staging → Production

Isi kolom **ID PROD** dengan ID produk di jeddawear.com (lihat di WP Admin → Products → hover produk → angka `post=XXXX` di URL edit). Setelah diisi, edit 3 snippet sesuai baris **"Diganti di"** di bawah.

---

## 1. Produk PUBLIK (tombol Join Waitlist) — snippet **13979**

| Produk | ID staging | **ID PROD (isi)** |
|--------|-----------|-------------------|
| Rhea (publik) | `13006` | `__________` |
| Phi (publik)  | `13042` | `__________` |

**Diganti di 13979:**
```js
var waitlistProductIds = ['13006', '13042']; // → ganti ke ['ID_RHEA_PROD', 'ID_PHI_PROD']
```

---

## 2. Produk PRIVATE (gate + payment) — snippet **13980** & **14010**

| Produk | ID staging | **ID PROD (isi)** |
|--------|-----------|-------------------|
| Phi Private  | `13934` | `__________` |
| Rhea Private | `13939` | `__________` |
| **`13937` (?)** — cek dulu ini produk apa | `13937` | `__________` atau HAPUS |

**Diganti di 13980** (gate — cuma 2 ID):
```php
$private_ids = [ 13934, 13939 ]; // → ganti ke [ ID_PHI_PRIV, ID_RHEA_PRIV ]
```

**Diganti di 14010** (payment — 3 ID):
```php
return [ 13934, 13937, 13939 ]; // → ganti ke [ ID_PHI_PRIV, (ID_13937?), ID_RHEA_PRIV ]
```

> ⚠️ **Soal 13937:** ada di payment (14010) tapi TIDAK di gate (13980). Cek di WP Admin produk ID 13937 itu apa:
> - Kalau **produk private aktif** (punya halaman sendiri) → cari padanannya di prod, isi di kedua snippet (tambahkan juga ke daftar 13980 biar halamannya kejaga).
> - Kalau **produk lama/duplikat/nggak dipakai** → boleh dihapus dari daftar 14010 supaya bersih.

---

## 3. Slug halaman (pastikan ADA di prod, tak perlu diisi ID)

| Slug | Dipakai di | Cek |
|------|-----------|-----|
| `/early-access/` | 13979, 13948, 13978, 13982 | Halaman form waitlist |
| `/jacket-access/` | 13980, 14010, 13948, 13982 | Halaman landing token |

Kalau slug di prod beda (mis. `/waitlist/`), ganti juga string slug-nya di snippet terkait.

---

## Checklist sebelum aktifkan
- [ ] ID publik (13979) sudah diganti
- [ ] ID private (13980 **dan** 14010) sudah diganti + konsisten
- [ ] 13937 sudah diputuskan (dipakai / dihapus)
- [ ] Slug `/early-access/` & `/jacket-access/` ada di prod
- [ ] Tes 1 alur undangan penuh sebelum aktifkan trigger 13979
