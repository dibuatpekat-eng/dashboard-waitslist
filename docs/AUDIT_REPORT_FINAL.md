# JEDDA Waitlist & Anti-Jastip — Laporan Audit Final

**Tanggal:** 2026-07-06
**Auditor:** Independent pre-production audit (Claude)
**Lingkup:** Supabase (DB, RPC, RLS, Edge Function) · WordPress/WPCode · Customer journey (sampai pembayaran) · Dashboard Waitlist · Dashboard Jastip · Visual/UX · Mobile (dari kode)
**Environment yang diuji:** **Staging** (`beta2.jeddawear.com`, `dashboard-waitslist.vercel.app`, `dashboard-jastip.vercel.app`, Supabase `misrdmmvukdpranxnqjq`). **Produksi belum diuji** (atas permintaan — fokus amankan staging dulu).

---

## 1. Ringkasan Eksekutif

**Vonis: BELUM siap produksi (NOT production-ready).**

Secara fungsi, sistem ini dibangun rapi — gerbang produk private benar-benar mengunci, payment hook bekerja sempurna (sudah kubuktikan dengan pembayaran sandbox sungguhan), rule engine anti-jastip sesuai desain, dan pengalaman customer terasa premium. **Tapi seluruh sistem berdiri di atas satu kesalahan arsitektur yang fatal:** *anon key* Supabase yang **tertanam di JavaScript website publik** ternyata adalah kunci-baca ke **hampir seluruh database perusahaan**, dan kunci-tulis penuh ke seluruh data anti-jastip. Karena itu, semua proteksi yang dirancang (cek jastip saat daftar, sistem undangan, verifikasi HP) **bisa dilewati** oleh siapa saja yang cukup membuka "View Source".

**5 masalah paling kritis (semua sudah dibuktikan langsung, bukan teori):**
1. Anon key publik bisa membaca seluruh DB (waitlist, POS, produksi, **daftar harga & HPP**, PII customer).
2. Semua token undangan bisa "ditarik" via anon key → sistem undangan jebol total (dibuktikan: buka produk private langsung pakai token, tanpa verifikasi HP).
3. Database reseller (nama, HP, **alamat rumah**) bisa dibaca, diubah, bahkan **dihapus** siapa saja.
4. Pendaftaran bisa disuntik langsung tanpa lewat RPC → melewati semua pengecekan.
5. Pengirim email adalah **open relay tanpa autentikasi** — siapa saja bisa kirim email atas nama `shop@jeddawear.com`.

Kabar baiknya: dua "hal yang paling ditakutkan" di dokumentasi ternyata **AMAN** — gerbang produk private benar-benar mengunci (bukan cuma mencatat), dan payment hook mengonsumsi token dengan benar. Jadi fokus perbaikan bukan di situ, melainkan di lapisan izin (RLS) dan konfigurasi akses.

---

## 1b. Status Remediasi (diperbarui 2026-07-07)

**Legenda:** ✅ selesai & terverifikasi · 🟡 sebagian/proses · ⬜ belum dikerjakan · 👤 kamu sendiri · 🚫 dikerjakan dev lain

### 🔴 CRITICAL

| # | Masalah | Contoh Kasus | Status |
|---|---------|--------------|--------|
| C1 | Anon key baca/**tulis/hapus** DB perusahaan (bukan cuma baca) | View Source → ambil key → baca HPP + PII + **ubah/hapus** data | ✅ **(baru)** SEMUA tabel bisnis dikunci anon→401: waitlist+token (audit), `pos_*` (customers/sales/points/sale_items — PII), `event_*` (bazaar), `profiles` (staff PIN/role) — olehku; `app_settings`+`prod_*`+`bb_*`+`dist_*`+`fin_*`+`setup_vendors` (ops) — oleh **dev ops** (verified live). Sisa anon cuma operasional non-sensitif (rate_limits/shadow_log insert-only, variations publik). Verified: seluruh probe anon → 401 |
| C2 | Dump semua token → bypass undangan | Reseller tarik semua token → buka produk private langsung → borong | ✅ **(baru)** anon SELECT `launch_tokens` → `USING(false)` (dump balik `[]`, terverifikasi). Semua baca token via RPC SECURITY DEFINER: gate/client 13948 & payment 14010 → `launch_token_status`; konsumsi → `launch_consume_token` (mark-used + waitlist purchased). Dashboard (authenticated) tetap bisa baca. Dites: dump kosong, add-to-cart, jacket-access, konsumsi semua jalan |
| C3 | DB jastip world-read/write/delete | Siapa pun ambil anon key → baca nama+HP+alamat reseller / **hapus semua** | 🟡 **masih terbuka** (verified: `jastip_*` `USING(true)` ALL). Ini satu-satunya data sensitif yang masih anon-open. Fix = migrasi-auth dashboard jastip (sama seperti jedda-ops) — dev lain. Ditambahkan ke `JEDDA_OPS_SECURITY_RECOMMENDATION.md` |
| C4 | Pendaftaran disuntik langsung, lewati RPC | Reseller ter-flag daftar langsung `is_flagged=false`, lolos cek | ✅ |
| C5 | Email relay terbuka tanpa login | Kirim phishing "dari shop@jeddawear.com" ke siapa saja | ✅ (anon → 401) |

### 🟠 HIGH

| # | Masalah | Contoh Kasus | Status |
|---|---------|--------------|--------|
| H1 | Password admin `202020` | 6 angka gampang di-brute-force → jebol pipeline undangan | ✅ kamu ganti |
| H2 | Sync `web_purchases` berhenti | Pembeli baru nggak ke-detect "sudah beli" → admin salah undang | ✅ beres (jalur tulis service/definer; tabel tumbuh 601→606, sync live) |
| H3 | Normalizer HP ganda di RPC | HP format aneh → cek jastip meleset diam-diam | ✅ |
| H4 | Tab "Database Jastip" kosong | Admin buka tab cross-check reseller → selalu kosong | ✅ (hard-refresh dashboard) |
| H5 | WooCommerce versi rentan | Celah versi lama bisa kasih akses admin tanpa izin | ✅ kamu update |
| H6 | Checkout produk BIASA terblokir "private access" | Customer habis pakai undangan → beli produk reguler → keblokir | ✅ **(baru)** guard di 14010, dites live |

### 🟡 MEDIUM

| # | Masalah | Contoh Kasus | Status |
|---|---------|--------------|--------|
| M1 | Tak ada validasi email/HP | Data ngawur (`...gmail.com` tanpa @, HP `123`) lolos & diundang | ✅ (server-side + typo-guard email diperluas) |
| M2 | Rate limit "3x/jam per IP" mati | Website nggak kirim IP → spam daftar tanpa batas | ⬜ (edit front-end snippet) |
| M3 | SECURITY DEFINER view | View `launch_waitlist_view` bypass RLS | ✅ |
| M4 | Overload legacy `jastip_ingest_order` 9-arg | Pemanggil lupa email → kena logika lama | ✅ dev lain (tinggal 1 signature kanonik 12-param; overload di-drop) |
| M5 | Cakupan email jastip | Coverage kini **74,9%** (2919/3896, naik dari ~10% lewat backfill REST). Sisa 977 (peninggalan PDF) — **email-nya ADA di WooCommerce**, bisa ditarik via REST lalu rescan → ~100% | 🟡 sebagian (bisa di-100%-kan via REST backfill + rescan; BUKAN keputusan produk) |
| M6 | Tombol "+ Tambah Alokasi" mati | Klik pas section ketutup → nggak terjadi apa-apa | ✅ live |
| M7 | Cart drawer private bermasalah | Harga hilang, gabisa remove, font/layout beda dari item reguler di mini-cart drawer | ✅ **(baru, disempurnakan)** 14018 membangun ulang baris private pakai **markup tema persis** (`.de-cart__list-item-inner > list-media + list-meta`) → font (Overpass 12px), posisi harga kanan, qty "1" statis (sengaja tanpa +/− krn item dikunci 1 unit/token), REMOVE gaya tema — identik dgn item reguler. Remove berfungsi via Store API. Mobile: drawer memang tak dipakai tema (header desktop hidden); cart page mobile diverifikasi render sempurna @390px. Label "(Private)" dibiarkan (keputusan brand) |
| M7b | Larangan checkout item lain bareng private | Ini sengaja & bagus — cuma pesannya perlu diperjelas | ✅ **(baru)** wording `cart_locked` + banner diperhalus di 14010 (jelaskan "menjaga slot") |
| M7c | Notif merah dobel / telat / numpuk pas refresh | Akar (diverifikasi live): AJAX add tema balikin shell notice KOSONG, pesan asli ngantre di session WooCommerce → numpuk & tumpah pas refresh; Buy Now tak ter-handle sama sekali | ✅ **(baru)** 2 lapis: snippet baru 14043 (dedup antrean error generik — produk biasa & purchase-limit ikut kebenahi) + 13996 ditulis ulang (sniffer respons AJAX + click-fallback Add to Cart & Buy Now + pull antrean + dedup tampilan). Dioptimasi utk traffic tinggi: add sukses = 0 request ekstra. Visual toast di-restyle ke bahasa Jedda (krem #faf8f5, garis kiri #9f4d3f, teks #333). Dites live: 1 klik = tepat 1 toast, refresh bersih |
| M8 | Alokasi cuma advisory | Nggak ada kunci stok → 2 orang rebutan unit terakhir | 🚫 dev lain (desain) |
| M9 | Hook order blocking ke Supabase | Supabase lambat/down → order processing ketahan | ✅ (14024+14034) |
| M10 | Checkout private mampir /cart/ dulu | "Buy Now" mendarat di /cart/ (langkah ekstra) | ✅ **(baru)** snippet 14045 — filter `woocommerce_add_to_cart_redirect` → classic add (=Buy Now) langsung ke /checkout/; AJAX Add-to-Cart (drawer) tak terpengaruh. Dites: classic add → /checkout/ ✓ |

### 🟢 LOW

| # | Masalah | Contoh Kasus | Status |
|---|---------|--------------|--------|
| L1 | Baris suspect near-duplikat | 1 orang jadi 3 baris karena alamat beda tipis | ✅ dev lain (kolom `address_normalized` ditambah; `jastip_suspects` 0 baris/rescan bersih) |
| L2 | Catatan "Restore sesuai flag" aneh | Teks janggal muncul di kartu suspect | ✅ dev lain (teks sudah dihapus di repo) |
| L3 | Filter "Perlu Ditinjau" badge campur | Angka benar tapi badge beda-beda, membingungkan | ✅ dev lain (badge status order + tag ditata ulang) |
| L4 | Preview token kepotong | Email panjang → token di modal kepotong | ✅ live |
| L5 | Edit Entry nggak normalize HP | Admin ketik `08...` → kesimpan salah format | ✅ live |
| L6 | Tambah Manual tanpa kolom email | Admin tahu email reseller tapi nggak bisa input | ✅ live |
| L7 | "Link not found" tanpa WhatsApp | State error token invalid nggak kasih kontak bantuan | ✅ **(baru)** live |
| L8 | Nama depan email ditebak | `z.rianda@` → sapaan "Z" janggal | ✅ **(baru)** ganti ke "Dear Guest," (netral, dipush ke repo) |
| L9 | Dashboard file tunggal, tanpa test | Susah diubah aman saat tumbuh | 🚫 dev lain (tech debt) |
| L10 | Enum produk hardcoded | Nambah produk = edit banyak tempat | 🚫 dev lain (tech debt) |

**Rekap:** ✅ 27 selesai · 🟡 2 (**C3** jastip auth-migration · M5→100% ditunda) · 🚫 3 dev lain — **satu-satunya data sensitif masih anon-open: `jastip_*` (C3)**

---

## 2. Metodologi & Cakupan

**Yang dilakukan:**
- Membaca seluruh source code kedua dashboard (identik dengan repo `github.com/dibuatpekat-eng/dashboard-waitslist`) + dokumentasi engineering.
- Verifikasi DB langsung: skema, seluruh RPC (`SECURITY DEFINER`), RLS, view, Edge Function, integritas data.
- Probing keamanan sebagai penyerang anonim memakai anon key publik terhadap REST API.
- Membaca PHP WPCode di staging (snippet 13980 private gate, 14010 payment hook, 14018 cart drawer) via wp-admin.
- Menjalankan **customer journey penuh**: daftar → verifikasi HP (salah & benar) → produk private → keranjang → checkout → **bayar via Midtrans sandbox (BCA VA) sampai LUNAS**.
- Uji langsung kedua dashboard sambil login: statistik, filter, tab, token, kunci produksi, alokasi, semua modal, error state.

**Data uji:** semua baris/token uji yang kubuat sudah dihapus bersih. Yang tersisa: **order test #14039** di WooCommerce staging (bayar sandbox, bukan uang asli) — silakan hapus kapan saja.

**Belum diuji / terbatas:**
- **Tampilan mobile** — tool otomasi tidak bisa emulasi viewport HP (keterbatasan tool, bukan akses). Temuan mobile di bawah berasal dari pembacaan CSS.
- **Produksi** — sesuai keputusan, diamankan setelah staging beres.
- Pembayaran non-sandbox (uang asli) — tidak dilakukan (tidak perlu).

---

## 3. Temuan KRITIS (wajib sebelum launch)

### C1 — Anon key publik membaca seluruh database perusahaan
Anon key yang sama yang ada di JS website publik (dan kedua dashboard) berhasil kupakai lewat REST API tanpa login untuk membaca: seluruh `launch_waitlist` (email, HP, IP, `is_flagged`, `flag_reason`), seluruh `launch_tokens`, dan — karena ini proyek Supabase **bersama** — juga `pos_customers`, `pos_sales`, `prod_orders`, `bb_materials`, dan `app_settings` (**HPP/modal & daftar harga**). GRANT tabel memberi seluruh privilege ke role `anon`, jadi **RLS adalah satu-satunya pengaman**, dan kebijakan SELECT pada `launch_waitlist`/`launch_tokens` adalah `USING (true)` (buka penuh).
**Dampak:** kebocoran data pelanggan & data bisnis level perusahaan. **Bukti:** query REST langsung mengembalikan 24+ baris waitlist & data POS/produksi.

### C2 — Sistem undangan bisa dilewati total (dump token + gerbang berbasis kepemilikan)
Kebijakan `launch_tokens_select_by_token` bernama seakan membatasi per-token, tapi `USING`-nya `true` → anon bisa **menarik SEMUA token** lengkap dengan nilai `token`, `phone`, `is_used`, `variation_id`. Gerbang produk private (13980) hanya mengecek **token + variation**, **tidak** mengecek HP. **Dibuktikan langsung:** aku buka URL produk private hanya dengan token (tanpa halaman verifikasi HP) → tembus, produk siap dibeli.
**Dampak:** reseller cukup menarik daftar token → memborong stok terbatas. Verifikasi 5-digit HP jadi tak berarti (HP-nya pun ikut terbaca dari token).

### C3 — Database jastip world-readable / writable / deletable
Enam tabel `jastip_*` punya kebijakan `anon_all` (`ALL`, `USING true`, `WITH CHECK true`). Aku membaca nama lengkap, HP, dan **alamat rumah** suspect terkonfirmasi; menyisipkan baris uji; lalu **menghapusnya** — semua dengan anon key. Dashboard Jastip pun **tanpa login**.
**Dampak:** siapa pun yang tahu URL bisa membaca PII reseller, meracuni data, atau **menghapus seluruh database deteksi**.

### C4 — Pendaftaran bisa disuntik langsung, melewati RPC
`launch_waitlist_insert_only` untuk anon punya `WITH CHECK (true)`. **Dibuktikan:** aku INSERT baris waitlist langsung (tanpa lewat `launch_submit_waitlist`) dengan `is_flagged=false` — **melewati** rate-limit, dedupe, dan cek jastip.
**Dampak:** reseller yang sudah di-flag tinggal daftar langsung tanpa lewat form → lolos semua pengecekan. Titik pertemuan dua sistem (cek jastip saat signup) jadi opsional bagi penyerang.

### C5 — Open email relay tanpa autentikasi dari domain resmi
Edge Function `send-launch-email` di-set `verify_jwt:false` dan **tidak** mengecek autentikasi apa pun (kubuktikan: request tanpa header auth sekalipun mencapai handler → balas 400 "missing fields"). Isi `{to, subject, html}` dikirim sebagai `Jedda <shop@jeddawear.com>` via Resend.
**Dampak:** siapa pun di internet bisa mengirim email HTML apa pun (phishing/spam) atas nama Jedda ke siapa saja, sekaligus menghabiskan kuota Resend. *(Aku hanya membuktikan pintunya terbuka; tidak mengirim email.)*

---

## 4. Temuan TINGGI (High)

### H1 — Password admin sangat lemah (`202020`)
Akun `admin@thewaitlist.com` (role `authenticated` yang berkuasa penuh atas pipeline undangan) memakai password **6 angka** → ~1 juta kombinasi, brute-force-able, apalagi endpoint auth Supabase publik & email admin gampang ditebak. Password ini juga sudah sempat dibagikan via chat (plaintext).
**Rekomendasi:** ganti ke password panjang & acak (12–16+ karakter), anggap `202020` sudah bocor.

### H2 — Sinkronisasi `web_purchases` berhenti (deteksi "pernah beli" bolong)
Order lunas **#14039** (pembayaran sandbox-ku) **tidak** masuk `web_purchases`; order tersinkron terakhir adalah **#14033** — artinya #14034–#14039 semua hilang, padahal payment hook menyala di order yang sama. **Dampak:** kolom "pernah beli / perlu ditinjau" di Dashboard Waitlist buta untuk pembeli terbaru → admin bisa mengundang orang yang sebetulnya sudah membeli. **Bukti:** DB `max(order_id)` di `web_purchases` = 14033 walau order #14039 sudah "Paid" di WooCommerce.

### H3 — Normalizer HP ganda (divergen) di `launch_submit_waitlist`
Langkah 3 menormalkan HP dengan logika inline (`regexp_replace` + `LIKE '08%'/'8%'`), **bukan** `jd_normalize_phone()` kanonik, lalu membandingkannya ke `jastip_suspects.phone_normalized` (yang ditulis pakai fungsi kanonik). Untuk HP `08...` (mayoritas) keduanya sama, tapi **divergen** untuk format `021...`, `0361...`, `8...` pendek. Melanggar aturan "satu normalizer" dan berisiko diam-diam meleset. **Rekomendasi:** panggil `jd_normalize_phone()` di sini juga.

### H4 — Tab "Database Jastip" kosong untuk admin login (RLS role mismatch)
Tabel `jastip_*` hanya punya kebijakan `anon_all` **`TO anon`** — tidak ada kebijakan untuk role `authenticated`. Dashboard Waitlist (sudah login) query sebagai `authenticated` → ditolak RLS → tab selalu tampil **"Belum ada suspect jastip terkonfirmasi"** walau ada **6 suspect confirmed**. Tombol "Whitelist" di tab itu juga gagal diam-diam. **Bukti:** dashboard Jastip (anon key) menampilkan ke-6 suspect dengan normal. **Catatan penting:** ini sepaket dengan perbaikan C3 — saat membatasi akses anon, **wajib menambah kebijakan `authenticated`** atau tab ini tetap rusak.

### H6 — Checkout produk BIASA bisa terblokir "could not verify private access" (session cross-contamination) — DIBUKTIKAN LANGSUNG
Snippet **14010** ("Payment Hook v2", 669 baris) sebenarnya berisi seluruh penjaga checkout private, bukan sekadar payment hook. Fungsi `jd_waitlist_get_cart_token()` (baris 98–111) membaca token dari item keranjang **ATAU dari WC session**, dan `jd_scrub_invalid_waitlist_cart_on_page_load()` (baris 370) menjalankan validasi di halaman cart/checkout.
**Masalah:** setelah pembelian private selesai, token **tidak dibersihkan dari WC session**. Saat customer yang sama menaruh **produk biasa** (non-waitlist) ke keranjang lalu ke cart/checkout, penjaga tetap mengambil token lama dari session, mencoba memvalidasinya terhadap keranjang normal, gagal, lalu menampilkan notice error **"We could not verify your private access. Please return to your invitation link and try again."** → **checkout produk biasa terblokir.** Ini persis kelas bug "session cross-contamination" yang namanya diklaim sudah diperbaiki.
**Bukti:** setelah menyelesaikan pembelian private (order #14039) di sesi ini, aku menambahkan produk biasa (Kiro Cropped Vest) → halaman cart menampilkan error private-access itu dan checkout tertahan ("There are some issues with the items in your cart").
**Scope (jujur):**
- **Customer baru** yang belum pernah pakai undangan: kemungkinan besar **AMAN** (tanpa token di session, penjaga early-return). Ini didukung fakta banyak order produk biasa historis berhasil masuk `web_purchases`. **Namun belum bisa kuverifikasi 100%** karena aku tak bisa membuat sesi benar-benar bersih (cookie session WC bersifat httpOnly).
- **Customer yang PERNAH pakai undangan** lalu beli produk biasa di sesi/browser yang sama: **kena blokir** dengan pesan yang membingungkan.
- Tes-ku sedikit memperparah karena aku menghapus token saat cleanup (pasti invalid); token "used" milik customer asli kemungkinan menempuh jalur gagal yang sama, tapi ini perlu satu konfirmasi lagi.
**Rekomendasi:** **bersihkan token private dari WC session saat pembelian selesai** (dan/atau saat cart tak lagi berisi item private); pastikan `jd_scrub...`/penjaga checkout hanya berjalan jika keranjang benar-benar berisi item private (`jd_waitlist_cart_has_private_item()`), dan **abaikan token session untuk keranjang tanpa item private**. **Wajib dites ulang** dengan (a) sesi benar-benar baru beli produk biasa, dan (b) customer yang beli private lalu beli produk biasa. *(Ini menjawab langsung kekhawatiran "snippet waitlist mengganggu checkout produk biasa" — sebagian terbukti.)*

> **Catatan perbaikan (dicoba 2026-07-06, DI-REVERT):** menambahkan guard `if (! jd_waitlist_cart_has_private_item()) return;` di awal `jd_scrub_invalid_waitlist_cart_on_page_load`, `jd_scrub_waitlist_cart_after_add`, dan `jd_validate_private_checkout_has_token` **TIDAK menyelesaikan** gejalanya — notice "could not verify private access" tetap di-add ulang tiap load halaman cart untuk keranjang produk biasa. `jd_waitlist_cart_item_is_private` berbasis product_id (Kiro bukan private, jadi `cart_has_private` = false), sehingga guard-nya benar tapi tidak menangkap kasus ini. Artinya **kontaminasinya ada di WC SESSION / token yang menempel, bukan di isi keranjang** — fix harus di `jd_waitlist_get_cart_token` (jangan fallback ke session untuk keranjang non-private) / `jd_add_waitlist_token_to_cart_item` (jangan pasang token ke produk non-private) / `jd_handle_store_token` (bersihkan token pada waktu yang tepat). **Perlu dev yang bisa membaca isi penuh snippet 14010** (669 baris) — automation content-filter memblokir pembacaan isinya karena mengandung pola token. Guard sudah di-revert; snippet 14010 kembali byte-for-byte seperti semula (27761 char).

### H5 — WooCommerce versi rentan
wp-admin menampilkan peringatan resmi: versi WooCommerce (5.4–10.5.2) punya celah yang bisa memberi **akses admin tanpa izin**. Di luar sistem waitlist, tapi toko yang sama → **segera update**.

---

## 5. Temuan SEDANG (Medium)

- **M1 — Tidak ada validasi format email/HP server-side.** Data ngawur lolos: baris `dibuatpekatgmail.com` (tanpa `@`) dengan HP `123` bahkan sempat berstatus "Undangan Terkirim".
- **M2 — Rate limit "3x/jam per IP" tidak aktif.** Front-end tidak mengirim IP (`ip_address` = null), sedangkan RPC hanya membatasi jika IP ada → praktis tanpa batas.
- **M3 — `launch_waitlist_view` adalah SECURITY DEFINER view** (bypass RLS, berjalan sebagai owner). Belum memperluas paparan sekarang, tapi cacat hardening; set `security_invoker = true`.
- **M4 — Overload legacy `jastip_ingest_order` 9-arg** masih ada → ambiguitas PostgREST; pemanggil yang lupa kirim email diam-diam kena logika lama.
- **M5 — Cakupan email jastip tipis:** hanya 105 dari 1.082 `jastip_orders` punya email → pencocokan berbasis email buta untuk ~90% riwayat.
- **M6 — "+ Tambah Alokasi" jadi tombol mati** saat section "Alokasi Stok" masih ketutup (form di-set tampil tapi ketutup parent). **Bukti:** DOM `form_visible=false`. Harus buka header dulu.
- **M7 — Cart drawer produk private beda & bermasalah (visual/UX):** (a) **harga per-item hilang** (padahal reguler menampilkan Rp 899.000); (b) label **"(Private)"** terlihat customer (istilah internal — untuk brand premium sebaiknya nama bersih atau "Reserved"); (c) **tidak bisa di-remove** — ini bukan proteksi keamanan (hapus item cuma mengosongkan cart, customer bisa klik ulang link undangan), justru UX buruk karena customer bisa terjebak. Snippet penyebab: **14018** (khusus memproses item private + `display:none` beberapa elemen) + produk di-set "sold individually" (menghapus stepper qty). Rekomendasi: tampilkan harga, ganti label, izinkan remove.
- **M7b — Cart mengunci checkout item private agar sendirian (keputusan desain, bukan bug).** Saat cart berisi item private, menambah item lain diblokir (via snippet 13996 "purchase-limit notice" + "sold individually"). **Rekomendasi: PERTAHANKAN larangan ini** — lebih aman karena menjamin "1 undangan = 1 order bersih = 1 token", menghindari kelas bug "session cross-contamination" (yang dulu diperbaiki di Payment Hook v2), dan mencegah kasus berbahaya **2 item private (2 token) dalam 1 order** yang bisa membuat payment hook cuma mengonsumsi 1 token dan menyisakan slot lain tetap "hidup". Ruginya cuma customer harus 2x checkout (wajar untuk drop eksklusif). **Yang perlu diperbaiki bukan larangannya, tapi cara menyampaikannya:** ganti "gagal diam-diam / notice purchase-limit yang membingungkan" dengan pesan lembut & jelas, mis. *"Item reserved kamu di-checkout terpisah untuk menjaga slot-mu. Selesaikan pesanan ini dulu, lalu belanja item lain."*
- **M9 — Hook order membuat panggilan HTTP blocking ke Supabase.** `14034` (Jastip Live Detector) melakukan `wp_remote_post` ke Supabase **tanpa `blocking => false`** (jadi default = blocking, hanya dibatasi `timeout`) pada transisi `order_status_processing/completed/refunded`. Karena jalan **setelah** pembayaran, tidak menahan klik checkout customer, tapi **latensi/downtime Supabase bisa menahan pemrosesan status order** hingga timeout. **`14024` (web purchases sync) DIKONFIRMASI juga blocking** (tanpa `blocking=>false`, hanya timeout) dan jalan di **setiap** order (tidak digate). Bandingkan dengan `13980` yang sudah benar (`blocking => false, timeout => 3`). **Rekomendasi:** pakai `blocking => false` (fire-and-forget) untuk semua panggilan Supabase di jalur pemrosesan order.
  > **Kaitan ke H2:** `14024` inilah yang seharusnya menulis ke `web_purchases`. Karena dia Active tapi order #14034–#14039 tetap tak tersinkron, kemungkinan besar **panggilan upsert-nya gagal/timeout diam-diam** (blocking + timeout → order tetap lanjut, tapi sync tak terjadi). Ini lead kuat untuk akar penyebab H2 — cek `get_logs` Supabase / respons upsert 14024.
- **M8 — Alokasi hanya advisory** (tidak ada penguncian stok keras). Dua customer terundang bisa berebut unit terakhir; tidak ada reservasi stok saat token dibuat vs saat checkout.

---

## 6. Temuan RENDAH / Polish (Low)

- **L1** — Baris suspect near-duplikat (mis. "Tewi mardiyanti" 3 baris: HP sama, alamat beda tipis "jakarta barat") karena `jd_normalize_address` belum menyamakan. Clustering menutupinya, tapi data redundan.
- **L2** — Catatan aneh **"Restore sesuai flag"** tampil di kartu suspect — cek apakah memang catatan yang diinginkan.
- **L3** — Filter kartu "Perlu Ditinjau" menampilkan baris ber-badge campur (Undangan Terkirim/Sudah Beli) — angka benar, badge membingungkan.
- **L4** — Preview token di modal Generate kepotong untuk email panjang (tidak wrap).
- **L5** — Modal Edit Entry menampilkan HP format `62...` (tabel `0...`) dan **tidak menormalisasi ulang saat simpan** → admin bisa tak sengaja menyimpan HP salah format.
- **L6** — Modal "Tambah Manual" (Jastip) **tanpa kolom email**, padahal email kunci pencocokan.
- **L7** — Halaman "Link not found" (token invalid) tidak menawarkan kontak WhatsApp, sedangkan state "sudah dipakai" menawarkan — kurang konsisten.
- **L8** — Nama depan di email undangan ditebak dari local-part email (mis. `z.rianda@` → "Z") → sapaan bisa janggal.
- **L9** — Dashboard file tunggal (1993 + 910 baris), tanpa build/modul/test — akan sulit diubah dengan aman saat tumbuh.
- **L10** — Peta enum produk/warna/ukuran (rhea/phi, breen/auburn, sm/lxl) hardcoded & terduplikasi di dashboard dan RPC — nambah produk = ubah banyak tempat.

---

## 7. Mobile (dari pembacaan kode — belum diverifikasi visual)

- **MO1 (perlu perhatian) — Dashboard Jastip: navigasi hilang di HP.** Aturan `@media (max-width: 900px) { .nav { display:none } }` menyembunyikan tab Flags/Database/Watched **tanpa pengganti (tak ada hamburger)** → user HP terjebak di tab Flags, tak bisa pindah.
- **MO2 — Dashboard Waitlist punya CSS mobile yang matang** (tabel jadi kartu, checkbox disembunyikan, expand "detail", dropdown "Kelola" direposisi) — terlihat disengaja & rapi, tapi **butuh konfirmasi visual**.
- **MO3 — Cart drawer** (lihat M7) tampil beda di HP; screenshot customer mengonfirmasi harga hilang & tombol qty/remove tak ada untuk private.

*Verifikasi visual mobile penuh belum bisa dilakukan karena keterbatasan tool otomasi (bukan masalah akses).*

---

## 8. Yang Sudah BENAR (terverifikasi — jangan diutak-atik)

- **Gerbang produk private (13980) BENAR-BENAR MENGUNCI** — walau nama fungsinya `_shadow`, kode live-nya `wp_safe_redirect(home)+exit` untuk kasus tanpa-token / tidak-ketemu / expired / **variation mismatch**, dan redirect ke `/jacket-access/?token=` untuk token terpakai. Hanya token valid+belum dipakai+belum expired+variation cocok yang lolos. **(Menjawab pertanyaan terbuka #1 dokumentasi — aman.)**
- **Payment Hook (14010) BENAR** — pada pembayaran sukses, `is_used=true` + `woo_order_id` + waitlist `purchased` di-set **tanpa syarat**, dan variation mismatch dicatat **terpisah** sebagai sinyal review (bukan menggantikan penandaan used). **Terverifikasi end-to-end lewat pembayaran sandbox sungguhan (order #14039).** **(Menjawab pertanyaan terbuka #3.)**
- **Rule engine `jastip_ingest_order`** sesuai spesifikasi: Rule 1 (HP/email/alamat≥0.85→high, tak digate watched), Rule 2 (qty≥2 produk sama→high), Rule 4 (cross-order, produk watched sama persis, confidence by kekuatan match **bukan umur**), pakai normalizer kanonik.
- **`launch_verify_phone`** solid: validasi input, rate-limit 5/15 menit, tolak used/expired/mismatch.
- **Integritas data bersih:** tak ada token bervariasi publik, tak ada token yatim, tak ada duplikat email/HP (unique constraint jalan), konsistensi payment-hook terjaga.
- **Kunci token di Production JALAN** (token "Dipakai" → "TIDAK ADA AKSI") — terverifikasi langsung.
- **Clustering identitas Jastip JALAN** (union-find) — "Tewi 3 identitas", "Rahmat 2 identitas" (HP beda, 1 alamat) jadi 1 kartu.
- **Pengalaman customer premium & bilingual** (EN/ID); **error state sangat baik** (invalid = "Link not found" kalem; used = penjelasan fairness + WhatsApp).
- **Variation dikunci** di produk private ("locked to your registered selection") — anti-tamper ukuran/warna.
- **Peringatan alamat bersama** di modal Confirm Jastip (cegah over-flag gedung/kompleks).
- Tidak ada `pg_cron`; normalisasi HP konsisten di seluruh suspect; Resend key aman di server (tidak di browser).

---

## 9. Rencana Perbaikan (urut prioritas)

**Tahap 1 — Keamanan (WAJIB sebelum launch):**
1. **Perketat RLS** (C1–C4): hapus `USING(true)` baca di `launch_waitlist`/`launch_tokens`; batasi SELECT token hanya per-nilai-token (atau lewat RPC `SECURITY DEFINER`); ubah `WITH CHECK` INSERT waitlist agar anon tak bisa set `is_flagged`; pindahkan `jastip_*` ke belakang auth **dan tambahkan kebijakan `authenticated`** (sekaligus memperbaiki H4).
2. **`verify_jwt:true`** pada `send-launch-email` + wajibkan pemanggil terautentikasi (C5).
3. **Ganti password admin** ke yang kuat (H1).
4. Berhenti menganggap anon key sebagai rahasia; ia publik dan hanya boleh dibatasi RLS.

**Tahap 2 — Fungsi/data:**
5. **Perbaiki sinkron `web_purchases`** (H2) — cek snippet 14024, pastikan menyala di transisi status order.
6. **Satukan normalizer HP** ke `jd_normalize_phone()` (H3).
7. **Update WooCommerce** (H5).
8. Validasi format email/HP (M1); aktifkan/relokasi rate-limit IP (M2); `security_invoker` pada view (M3); hapus overload 9-arg (M4).

**Tahap 3 — UX/polish:**
9. Cart drawer private (harga, label, remove — M7); tombol Alokasi (M6); preview token (L4); Edit Entry normalize (L5); kolom email di Tambah Manual (L6); WhatsApp di "Link not found" (L7).
10. **Mobile:** perbaiki nav Dashboard Jastip di HP (MO1); konfirmasi visual layout mobile lain.

**Tahap 4 — Verifikasi ulang:**
11. Setelah Tahap 1, **jalankan ulang probe anon REST** untuk memastikan lubang tertutup (aku bisa berikan daftar request-nya).

---

## 10. Catatan Penutup

Sistem ini **konsep & eksekusi fungsionalnya bagus** — logika bisnis di RPC, gerbang & payment hook yang benar, UX premium. Yang menahannya dari produksi adalah **lapisan izin/akses**, bukan logikanya. Begitu Tahap 1 (RLS + edge function + password) selesai dan diverifikasi ulang, profil risikonya berubah drastis. Sampai saat itu, satu "View Source" sudah cukup untuk membongkar sistem undangan dan mengekspos data pelanggan & bisnis.

Semua kesimpulan di atas didukung bukti langsung (probe REST, isi RPC/RLS/view, pembacaan PHP, dan customer journey + pembayaran sungguhan di sandbox), bukan asumsi.

*— Selesai.*
