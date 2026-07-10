# Rekomendasi Keamanan — jedda-ops (untuk developer ops)

**Konteks:** audit keamanan sistem Jedda menemukan `jedda-ops` mengekspos data bisnis ke publik. Ini rekomendasi untuk menutupnya. Supabase project **dishare** dengan sistem waitlist (`misrdmmvukdpranxnqjq`).

---

## Masalah

`jedda-ops` (Admin_Tool, Produksi, Bahan Baku, Owner Dashboard, dll) mengakses Supabase memakai **anon key + TANPA login**. Anon key itu tertanam di HTML publik → siapa pun yang buka **View Source / DevTools** di halaman jedda-ops (atau website Jedda lain yang pakai key sama) bisa mengambilnya.

Karena RLS tabel-tabel ini `anon_all USING(true) CHECK(true)` (ALL command), siapa pun dengan anon key bisa **baca, ubah, DAN hapus**:

- `app_settings` — **HPP, daftar harga, margin**
- `prod_orders`, `prod_articles`, `prod_skus`, `prod_bom_items`, `prod_plans`, `prod_allocations`, `prod_*`
- `bb_materials`, `bb_material_variants`, `bb_stock_logs`, `bb_suppliers`, `bb_supplier_pos`
- `dist_sku_stock`, `dist_web_orders`, `dist_*`, `fin_logs`, `setup_vendors`

**Contoh serangan:** kompetitor buka DevTools → ambil anon key → `GET .../app_settings` (lihat HPP), atau `DELETE .../prod_orders` (hapus data produksi). Tanpa login, tanpa dashboard link.

> Catatan: tabel `pos_customers`, `pos_sales`, `launch_tokens`, `launch_waitlist` sudah dikunci ke authenticated/RPC dari sisi audit waitlist — **jangan** diutak-atik.

---

## Fix yang disarankan

Akar masalahnya: jedda-ops **authenticate sebagai `anon`** (publik). Solusinya = jadikan jedda-ops **authenticate sebagai `authenticated`** (staff login), lalu batasi RLS ke `authenticated` saja.

### Langkah 1 — Tambah login (Supabase Auth) ke jedda-ops
- Pakai **Supabase Auth** (email+password staff, atau magic link). Buat 1–beberapa akun staff.
- Di tiap file HTML: `supabase.auth.signInWithPassword(...)` → gate seluruh app di balik sesi login. Tanpa sesi → tampilkan form login, jangan render data.
- Supabase client otomatis pakai token `authenticated` untuk semua query setelah login (anon key tetap dipakai sebagai `apikey`, tapi Authorization jadi Bearer <user-jwt>).

### Langkah 2 — Baru setelah login jalan, ketatkan RLS (JALANKAN SETELAH Langkah 1)
> ⚠️ **URUTAN PENTING:** jangan jalankan SQL ini sebelum login aktif — kalau anon dicabut sementara app masih anon, app langsung mati. (Ini pelajaran dari fix waitlist: RLS shared, begitu diketatkan langsung berdampak.)

Untuk tiap tabel ops, ubah policy `anon_all` → `authenticated` saja + cabut grant anon. Contoh (ulangi untuk semua tabel di daftar atas):

```sql
-- ganti nama policy & tabel sesuai yang ada
ALTER POLICY <nama_policy_all> ON public.<tabel> TO authenticated;
REVOKE ALL ON public.<tabel> FROM anon;
```

Untuk melihat nama policy tiap tabel:
```sql
SELECT c.relname, p.polname, array_to_string(p.polroles::regrole[],',') roles, p.polcmd
FROM pg_class c JOIN pg_policy p ON p.polrelid=c.oid
WHERE c.relname LIKE 'prod_%' OR c.relname LIKE 'bb_%' OR c.relname LIKE 'dist_%'
   OR c.relname LIKE 'fin_%' OR c.relname IN ('app_settings','setup_vendors');
```

### Langkah 3 — Verifikasi
- Login ke jedda-ops → semua fitur (baca/tulis produksi, HPP) tetap jalan (sekarang sbg authenticated).
- Tanpa login, tes anon key: `GET .../app_settings` harus **401** (seperti pos_customers sekarang).

### Opsi lebih ketat (kalau mau)
- Kalau jedda-ops nanti punya backend/server, pertimbangkan **service_role key di server** (bukan di HTML publik) untuk operasi sensitif.
- Untuk data super-sensitif (HPP/margin), bisa dipisah ke RPC `SECURITY DEFINER` yang cuma balikin yang perlu.

---

## Ringkas untuk dev
1. **Tambah Supabase Auth login** ke semua halaman jedda-ops (gate data di balik sesi).
2. **Setelah login jalan**, ubah RLS tabel `app_settings` + `prod_*` + `bb_*` + `dist_*` + `fin_*` + `setup_vendors` dari `anon` → `authenticated` + `REVOKE ... FROM anon`.
3. **Jangan sentuh** `pos_*`, `launch_*`, `jastip_*`, `web_purchases` (sudah dihandle audit waitlist).
4. Urutan wajib: **login dulu, baru RLS** — jangan kebalik.

---

## TAMBAHAN: dashboard Jastip (`jastip_*`) — masih terbuka (C3)

Setelah ops tables beres, **satu-satunya data sensitif yang masih anon-open** adalah tabel `jastip_*` (`jastip_orders`, `jastip_suspects`, `jastip_flags`, `jastip_watched_products`, `jastip_batches`, `jastip_address_links`) — semua `USING(true)` ALL command. Isinya **data reseller: nama, HP, alamat** + 3.896 order. Artinya siapa pun dengan anon key bisa **baca, ubah, hapus** seluruh data anti-jastip.

Penyebabnya sama: **dashboard Jastip standalone** (`dashboard-jastip.vercel.app`) masih pakai anon key tanpa login. (Tab Jastip di dashboard waitlist sudah pakai authenticated.)

**Fix = sama persis seperti ops:**
1. Tambah Supabase Auth login ke dashboard Jastip standalone.
2. Setelah login jalan, ubah RLS `jastip_*` dari `anon` → `authenticated` + `REVOKE ... FROM anon`.
3. **Hati-hati:** snippet WPCode `14034` (Jastip Live Detector) menulis `jastip_orders` — pastikan dia menulis via RPC `SECURITY DEFINER` (bukan anon langsung), atau dia ikut rusak saat anon dicabut. Cek dulu sebelum flip.
4. Verifikasi anon `GET jastip_orders` → 401, dashboard authenticated tetap jalan.
