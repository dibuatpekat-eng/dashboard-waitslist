# JEDDA — Panduan Copy Snippet Staging → Production

**Dibuat:** 2026-07-07 · **Sumber:** staging `beta2.jeddawear.com`

Dokumen ini mengelompokkan semua snippet WPCode Jedda supaya copy-paste ke produksi terstruktur, menandai **apa yang WAJIB diubah** (nilai khusus staging), dan memisahkan **satu trigger go-live**.

---

## 📁 CARA COPY: import file JSON per-grup (bukan buka-tutup satu-satu)

Export WPCode kamu (`wpcode-snippets-export-2026-07-08.json`) isinya **42 snippet campur** (21 Jedda + 21 non-Jedda milik dev lama). WPCode tidak bisa pilih-pilih saat export, jadi **sudah kupisahkan** jadi file-file berikut di folder **`migration/`**:

> **🆕 UPDATE 2026-07-08 sore:** 5 snippet diubah live (13980, 13979, 13996, 14045, 14018) + 2384 harus dinonaktifkan. **File JSON di bawah SUDAH kuregenerasi dengan kode terbaru.** Detail di bagian "PERUBAHAN TERBARU" (paling bawah dokumen).

| File | Isi | Aksi di prod |
|------|-----|--------------|
| **`jedda-1-system.json`** | **18 snippet inti** (semua Jedda always-on, termasuk 13979) — kode TERBARU | Import → **aktifkan semua** |
| **`jedda-3-backfills-RUN-ONCE-THEN-DELETE.json`** | 3 backfill (14023/14036/14037) | **Jangan import** kecuali perlu isi data lama; kalau dipakai: jalankan 1×→ hapus |
| `jedda-ALL-21.json` | semua 21 Jedda (kalau mau sekali import) — kode TERBARU | alternatif praktis |

> ⚠️ **Tidak ada lagi file "GOLIVE-TRIGGER" terpisah.** 13979 sekarang **always-on** (dia juga ngatur tombol Out-of-Stock semua produk); **trigger go-live = flag `WAITLIST_LIVE` di dalam 13979** (lihat TRIGGER GO-LIVE).

**Langkah import di produksi** (WordPress prod → **WPCode → Tools → Import**):
1. Upload **`jedda-1-system.json`** → Import → aktifkan semua.
2. **NONAKTIFKAN snippet 2384** ("Out of Stock Button (Agif)") kalau ada di prod — sudah digantikan 13979.
3. Ganti **ID produk** (#1, #2) + set `WAITLIST_LIVE=false` dulu.
4. (opsional) backfill hanya kalau perlu — lihat Grup E.

> ⚠️ **Penting soal status Active:** file export WPCode **TIDAK menyimpan status aktif/nonaktif**. Semua snippet masuk **INACTIVE** setelah import → kamu **harus aktifkan manual**.
>
> ⚠️ Setelah import, **ganti ID produk** (#1, #2 di tabel bawah) sebelum mengaktifkan — kalau tidak, gate & token tidak nyambung ke produk prod.

---

## ⚠️ RINGKASAN: yang WAJIB diubah sebelum/saat go-live

| # | Hal | Di snippet | Kenapa |
|---|-----|-----------|--------|
| 1 | **ID produk private** `[13934, 13937, 13939]` | **14010** (`jd_waitlist_private_product_ids()`), **13980** | Ini ID produk parent Phi/Rhea di staging. Di produksi ID-nya beda → **kalau tidak diganti, gate & konsumsi token TIDAK jalan** |
| 2 | **ID produk waitlist** `['13006','13042']` | **13979** (`waitlistProductIds`) | ID produk publik Rhea/Phi di staging → tombol Join Waitlist salah target |
| 3 | **Slug halaman** `/jacket-access/`, `/early-access/` | 14010, 13980, 13948, 13979, 13982 | Pastikan halaman dgn slug ini **ada di produksi** (relatif, jadi otomatis ikut domain — hanya slug-nya yg wajib ada) |
| 4 | **Supabase URL + anon key** | 13948, 13980, 14024, 14034 + dashboard | **TIDAK perlu diubah** — Supabase-nya SATU project (dipakai staging & prod). *(Catatan: ini juga celah keamanan C1 — anon key publik.)* |
| 5 | **Snippet BACKFILL run-once** | 14023, 14036, 14037 | **JANGAN dicopy** ke prod (atau copy → jalankan 1×→ hapus). Ini utility isi data lama, bukan logic live |

> **Tidak ada URL `beta2.jeddawear.com` yang di-hardcode** di snippet mana pun (semua pakai path relatif / `home_url()`), jadi domain otomatis mengikuti. Yang perlu diperhatikan cuma **ID produk** (#1, #2) dan **slug** (#3).

### 🔒 CRITICAL — dampak fix keamanan C2 (Supabase shared)
Fix C2 (2026-07-07) mengubah RLS di Supabase (`launch_tokens` anon SELECT → `USING(false)`) + menambah RPC `launch_token_status` & `launch_consume_token`. **Karena Supabase 1 project (shared), perubahan RLS ini SUDAH berlaku utk produksi juga.** Konsekuensinya:
- **14010, 13948, DAN 13980 di prod WAJIB versi terbaru** (baca token via RPC `launch_token_status`). Kalau masih baca `launch_tokens` langsung, hasilnya **kosong** → private flow RUSAK.
- **⚠️ 13980 (gate) BARU diperbaiki 2026-07-08.** Sebelumnya 13980 KELEWAT saat C2 — masih `wp_remote_get(launch_tokens?token=eq...)` → sejak lockdown, gate selalu dapat kosong → `token_not_found` → `wp_safe_redirect(home_url('/'))` → **SEMUA token private mental ke HOMEPAGE**. Sudah diganti ke `wp_remote_post(rpc/launch_token_status)`. **Prod HARUS pakai 13980 versi baru ini** (kalau tidak, semua undangan private di prod mental ke homepage juga).
- RPC & policy sudah live (tak perlu migrasi DB) — cukup pastikan snippet-nya sinkron.

### Snippet BARU sejak audit (ikut dicopy)
- **14045** — "Buy Now goes straight to Checkout" (M10). PHP, Run Everywhere.
- **14043** — "Prevent Duplicate WC Error Notices" (M7c). PHP/JS.
- **14044** — "Jastip Order Status Sync" (buatan dev). Run Everywhere.
- **14010 & 13948** — versi C2 (RPC). **Wajib** (lihat catatan C2 di atas).

---

## 📦 KATEGORI SNIPPET (biar copy per-grup, bukan satu-satu)

### GRUP A — Inti Sistem Private/Undangan  *(copy semua, aktifkan bareng)*
| ID | Nama | Ubah utk prod? |
|----|------|----------------|
| 13948 | Launch — Form & Token | Slug (#3) |
| 13980 | Private Product Gate (ENFORCE) | **ID produk #1** + slug |
| 14010 | Payment Hook v2 | **ID produk #1** |
| 13981 | Hide Reset Variations on Locked Product | — (portable) |
| 13982 | Hide Page Title on Jacket/Early Access | Slug (#3) |

### GRUP B — Pintu Masuk Waitlist  🚦 *(lihat TRIGGER GO-LIVE di bawah)*
| ID | Nama | Ubah utk prod? |
|----|------|----------------|
| 13979 | **Product Stock & Button State** (gabungan, dulu "Join Waitlist Button") | **ID #1 & #2** + slug + flag `WAITLIST_LIVE` |
| 13978 | Early Access Waitlist Intro Popup | cek copy/slug |

> **⚠️ 13979 sudah di-refactor (2026-07-08).** Isinya diganti jadi **satu handler gabungan** yang menggantikan **2384 (Agif — Out of Stock Button)** + logic Join Waitlist lama. Kode final ada di **`migration/NEW-snippet-stock-button-state.php`**. Fungsinya:
> - Private (jacket-access) → tidak disentuh.
> - Waitlist (Rhea/Phi) habis → "Out of Stock" + "JOIN WAITLIST" (hanya jika `WAITLIST_LIVE=true`).
> - Reguler habis total → 1 tombol "OUT OF STOCK" + semua opsi dicoret & tak bisa diklik.
> - Reguler sebagian habis → hanya opsi yang habis dicoret; tombol normal.
>
> **Saat migrasi:** pakai kode dari `NEW-snippet-stock-button-state.php` (bukan versi lama di export), ganti `WAITLIST_IDS`+`PRIVATE_IDS` ke ID prod, dan **NONAKTIFKAN 2384** di prod (sudah digantikan). 13979 ini **always-on** (bukan lagi cuma "tombol waitlist").

### GRUP C — UX Cart / Checkout / Notifikasi  *(copy semua, portable)*
| ID | Nama | Ubah? |
|----|------|-------|
| 14000 | Full Cart Drawer on Add to Cart | — |
| 14001 | Mobile Cart Menu Icon (butuh 14000) | — |
| 14018 | Cart Drawer Visual Fixes (+ rebuild item private) | — |
| 13996 | Blocked Add-to-Cart Notice (toast) | — |
| 14043 | Prevent Duplicate WC Error Notices | — |
| 13994 | Fix Stuck Add to Cart / Buy Now Hover | — |
| 14003 | Fix Theme JS Errors (jQuery shim) | — |
| 14045 | Buy Now goes straight to Checkout (M10) | — |

### GRUP D — Anti-Jastip + Sync  *(background, portable)*
| ID | Nama | Ubah? |
|----|------|-------|
| 14024 | Web Purchases Live Sync | Nulis `web_purchases` (H2). Pastikan versi prod = versi terbaru yg sudah sync |
| 14034 | Jastip Live Detector | Nulis via **RPC `jastip_ingest_order`** (SECURITY DEFINER) → **aman** walau `jastip_*` nanti dikunci ke auth |
| 14044 | Jastip Order Status Sync | ⚠️ Nulis `jastip_orders` **langsung (anon)**. Kalau C3 (kunci `jastip_*`) dijalankan, snippet ini **ikut rusak** — koordinasi dgn dev jastip |

### GRUP E — BACKFILL sekali-jalan  🛑 *(JANGAN aktif permanen di prod)*
| ID | Nama |
|----|------|
| 14023 | Web Purchases Backfill (one-time, order 13275+) |
| 14036 | Jastip Backfill Utility (run once) |
| 14037 | Jastip Email Backfill (run once) |

### BUKAN Jedda (jangan diutak-atik / sudah ada) — Agif dkk
2384, 2385, 2386, 2366, 2393, 2531, 4969

---

## 🚦 TRIGGER GO-LIVE (flag, bukan aktif/nonaktif snippet lagi)

Karena 13979 sekarang **always-on** (dia juga ngatur tombol Out-of-Stock produk reguler), trigger go-live **bukan lagi "aktifkan 13979"**, tapi **flag di dalam 13979**:

```js
var WAITLIST_LIVE = true;   // baris paling atas di snippet 13979
```
- `WAITLIST_LIVE = false` → Rhea/Phi yang habis tampil seperti produk biasa (1 tombol **"OUT OF STOCK"**). Belum ada pintu masuk waitlist publik.
- `WAITLIST_LIVE = true` → Rhea/Phi habis tampil **"Out of Stock" + "JOIN WAITLIST"** → publik bisa daftar. **Ini saklar go-live.**

**Urutan aman go-live:**
1. Copy & aktifkan **Grup A, C, D** + **13979** (dengan `WAITLIST_LIVE=false`), **nonaktifkan 2384**.
2. Ganti semua **ID produk** (#1, #2) ke ID produksi + pastikan slug (#3) ada.
3. Cek toko: produk reguler habis tampil "OUT OF STOCK" 1 tombol; Rhea/Phi habis juga "OUT OF STOCK" (belum waitlist).
4. Tes 1 alur undangan lengkap di prod (mint token → jacket-access → beli).
5. **Terakhir**, set **`WAITLIST_LIVE = true`** di 13979 → resmi live.
6. Jangan lupa: **14023/14036/14037 (Grup E) jangan dibiarkan aktif**.

---

## ✅ Hasil verifikasi logic semua 21 snippet (2026-07-08)

Kucek satu-satu dari file export:
- **Syntax**: 21/21 kurung `{}` & `()` **seimbang** — tidak ada yang kepotong/rusak.
- **URL staging**: **NOL** hardcode `beta2.jeddawear.com` di semua snippet (semua path relatif / `home_url()`) → domain otomatis ikut prod.
- **Diverifikasi live** (alur bayar sungguhan + edit langsung sesi ini): 14010, 13980, 13948, 14018, 13996, 14043, 14045 — sehat.

### ⚠️ 2 hal yang perlu kamu konfirmasi saat mapping ID prod
1. **ID `13937` beda antara gate & payment.**
   - Gate (13980) jaga `[13934, 13939]` — 2 produk.
   - Payment (14010) enforce `[13934, 13937, 13939]` — 3 produk (ada 13937).
   - Efek: kalau **13937 itu produk private beneran**, dia **tidak dijaga gate** (halamannya bisa dibuka tanpa token via URL langsung), walau tetap ditolak saat checkout. **Cek 13937 di staging itu produk apa** — kalau produk private aktif, tambahkan ke daftar 13980 juga. Saat ganti ke ID prod, samakan kedua daftar.
2. **14044 vs 14034 (jastip write path).** 14034 aman (via RPC definer); 14044 tulis langsung `jastip_orders` sebagai anon → akan mati kalau tabel `jastip_*` dikunci auth (C3). Sudah dicatat di JEDDA_OPS_SECURITY_RECOMMENDATION.md.

### Nilai numerik lain (aman, bukan ID produk)
- 13948 punya `10000` = timeout/expiry ms (bukan ID produk). 14023 punya `13275` = ambang order backfill (one-time). 14037 punya 22 ID order = target backfill staging (one-time, jangan copy).

### ⚠️ Gotcha key backfill 14023 (kalau nanti dipakai di prod)
Key tiap snippet sudah dicek: 13948/13980/14010/14034/14036 = **anon** (aman, karena baca/tulis lewat RPC SECURITY DEFINER), **14024 = service_role** (benar, `web_purchases` terkunci). **TAPI 14023** (backfill web_purchases) masih pakai **anon** — padahal `web_purchases` cuma bisa ditulis `service_role`. Efek: **kalau 14023 dijalankan di prod buat backfill, dia diam-diam TIDAK menulis apa-apa** (anon ditolak RLS). Kalau memang perlu backfill order lama di prod: **ganti key 14023 ke service_role dulu** (samakan dgn 14024), jalankan, baru hapus. Semua backfill sudah ada guard `current_user_can('manage_options')` + query-param, jadi tak bisa kepicu sembarangan.

### Verifikasi logic Tier 3 (dibaca baris-per-baris 2026-07-08)
13978, 13981, 13982, 13994, 14000, 14001, 14003, 14024, 14034, 14044, 14023, 14036, 14037 — **semua logic sehat**. Catatan: 13981 pakai `$_GET['jd_token']` yang SUDAH benar (halaman produk pakai `jd_token`; halaman landing `/jacket-access/` pakai `token` — dua param sengaja beda & konsisten). 14044 & 14037 nulis `jastip_orders` langsung sbg anon → akan mati kalau C3 (kunci jastip) dijalankan; 14034 & 14036 nulis via RPC `jastip_ingest_order` → aman.

---

## 🖥️ Komponen lain (di luar snippet) — perlu adjustment prod?

| Komponen | Lokasi | Perlu diubah utk prod? |
|----------|--------|------------------------|
| **Dashboard Waitlist** | Vercel (`dibuatpekat-eng/dashboard-waitslist`) | **Tidak** — 1 deploy dipakai bareng, Supabase shared. Sudah authenticated (C1/C2). |
| **Dashboard Jastip** | Vercel (`dashboard-jastip.vercel.app`) | **Tidak** utk go-live; tapi **C3 belum**: masih anon tanpa login. Rekomendasi migrasi ke auth ada di JEDDA_OPS_SECURITY_RECOMMENDATION.md. |
| **Supabase** (DB/RLS/RPC/edge) | project `misrdmmvukdpranxnqjq` | **Tidak ada migrasi DB** — 1 project shared staging+prod. Semua RPC (`launch_token_status`, `launch_consume_token`, `jastip_ingest_order`) + policy C1/C2 **sudah live utk prod juga**. |
| **jedda-ops** | repo terpisah (dev lain) | Di luar scope waitlist. Rekomendasi RLS sudah diserahkan. |

**Konsekuensi Supabase shared:** karena DB satu, begitu prod pakai snippet C2 (14010/13948 versi RPC), semuanya langsung nyambung — **tidak perlu setup DB apa pun di prod**. Yang wajib cuma: snippet prod = versi terbaru (RPC), dan ID produk sudah diganti.

---

## Ringkas urutan eksekusi (URUTAN TERBARU)
1. **Import** `jedda-1-system.json` di WPCode prod (semua masuk inactive).
2. **NONAKTIFKAN 2384** ("Out of Stock Button (Agif)") — digantikan 13979.
3. **Ganti ID produk**: 13979 (`WAITLIST_IDS`=publik Rhea/Phi, `PRIVATE_IDS`=private), 13980 (`$private_ids`), 14010 (`jd_waitlist_private_product_ids()`) → ID prod; samakan daftar 13937 kalau relevan.
4. Di 13979, set **`WAITLIST_LIVE = false`** dulu (belum buka waitlist ke publik).
5. **Aktifkan semua** snippet Grup A/B/C/D.
6. **Tes 1 alur undangan penuh** di prod (mint token → `/jacket-access/?token=` → produk private → add to cart → checkout → bayar → token jadi used). Cek juga: produk reguler habis → "OUT OF STOCK"; cart-lock dua arah jalan.
7. **Go-live:** set **`WAITLIST_LIVE = true`** di 13979 → Rhea/Phi habis mulai tampil "JOIN WAITLIST".
8. Backfill (Grup E) hanya kalau perlu data lama: import → jalankan 1× → **hapus**.

---

## 🆕 DETAIL PERUBAHAN TERBARU (2026-07-08 sore) — 5 snippet + 1 deactivate

File JSON di `migration/` sudah berisi kode terbaru ini (aku regenerasi dari export lama + edit yang kupasang live). Kode sumber tiap perubahan juga ada sebagai file terpisah di `migration/`:

| Snippet | Type | Perubahan | File sumber |
|---------|------|-----------|-------------|
| **13980** | php | Baca token via RPC `launch_token_status` (dulu `wp_remote_get(launch_tokens?...)`) — **kelewat pas C2**, bikin semua token private mental ke homepage | (di dalam jedda-1-system.json) |
| **13979** | php | Handler gabungan stock/tombol: strike per-opsi via `<style>` global (bukan mutasi swatch `<li>`), fix stuck-loading, tombol OOS/Join Waitlist. **Menggantikan 2384** | `NEW-snippet-stock-button-state.php` |
| **13996** | js | Proactive **bidirectional** cart-lock (GET Store API cart, no XHR-wrap): produk biasa keblok kalau cart ada private; produk private keblok kalau cart tidak kosong | `NEW-snippet-cart-lock.js` |
| **14045** | php | `woocommerce_add_to_cart_redirect` skip kalau request XHR — cuma Buy Now (classic) yg ke checkout, Add-to-Cart AJAX aman | `NEW-snippet-buynow-checkout.php` |
| **14018** | js | Enhance item private di drawer **langsung + tiap drawer dibuka** (kurangi kedip) | (di dalam jedda-1-system.json) |

### 🔴 DEACTIVATE di prod
- **2384** "Product Page - Out of Stock Button (Agif)" → **matikan**. Digantikan 13979. Kalau dua-duanya aktif → tombol konflik.

### Kenapa ini penting
- **13980 (RPC)** & tetap wajib di prod bareng 14010 + 13948 (semua baca token via RPC, karena Supabase shared & `launch_tokens` sudah dikunci C2). Tanpa ini: **semua undangan private mental ke homepage**.
- **13996/14045/13979** memperbaiki serangkaian bug add-to-cart & drawer yang sempat muncul (XHR-wrap, redirect, mutasi swatch). Jangan pakai versi lama.

### Catatan performa (bukan blocker migrasi)
Loading cart lambat (~1.2 dtk) itu karena tema pakai `admin-ajax.php` (bootstrap semua plugin tiap request) + 28 plugin aktif — **bukan** dari snippet. Percepat via **Redis object cache** (Hostinger) + audit plugin.
