# Handoff — Jastip status backfill (one-time cleanup)

> **Status update 2026-07-10:** partially cleaned. `jastip_orders` now has **42** rows
> still `processing` (was ~395). Separately, **`web_purchases` has ~615 `processing`**
> rows with the same stale-status problem — resync that too. WooCommerce is authoritative.

**Untuk:** dev yang lanjutin
**Konteks singkat:** dashboard jastip (Supabase, project `misrdmmvukdpranxnqjq`, tabel `jastip_orders`) nampilin ~395 order status **`processing`** padahal di WooCommerce order-order itu udah **completed / cancelled / refunded**. Ini cuma butuh **satu kali update** — bukan bug sistem.

---

## Kenapa bisa stuck di `processing`

- `jastip_ingest_order` RPC itu idempotent: kalau `order_no` udah ada, dia **cuma update `order_status` + `order_date`** dari nilai yang dikirim WP snippet (WP snippet baca status WC lalu kirim ke RPC).
- 395 order ini di-ingest waktu masih `processing` (25–26 Juni 2026, order_no **#13172–#13902**). Sejak itu status WC-nya berubah tapi belum pernah di-resync.
- Snippet backfill (WPCode **14028** "Jastip Backfill Utility") niatnya nge-resync dengan re-ingest per order, tapi **timeout** karena tiap batch kerjanya berat (re-run semua rule anti-jastip per order). Itu sebabnya "ngeblank putih" tiap beberapa batch.

Solusi yang benar & ringan: **update `order_status` langsung**, gak usah re-ingest.

---

## Data kebenaran (udah diverifikasi dari WC, HPOS `wp_wc_orders`)

Range order **#13100–#13950** (800 order, mencakup semua 395 yang stuck) sekarang di WC:

| status WC | jumlah |
|---|---|
| completed | 533 |
| cancelled | 266 |
| refunded  | 1 (order **#13152**) |
| processing | **0** ← penting: gak ada lagi yg processing di WC pd range ini |

Karena **0 order di range itu yang masih `processing` di WC**, maka aturannya aman:
> Order jastip yg masih `processing` DAN order_no di range 13100–13950 → statusnya pasti salah satu dari completed/cancelled/refunded.

---

## Cara fix (paling simpel, 2 menit)

### Langkah 1 — ambil daftar id:status lengkap
Buka di browser (login admin `jeddawear.com`):
```
https://jeddawear.com/wp-admin/?jd_dump_jastip_status=1
```
Snippet **14028** punya blok read-only `jd_dump_jastip_status` yang nge-dump `order_no:status` untuk range 13100–13950 (plain text, gak ada tulisan sensitif). Kamu bakal liat SEMUA pasangan lengkap (aku sendiri kepotong di ~170 baris per baca, makanya lama — kamu di browser gak kepotong).

Dari situ ambil daftar **cancelled** (266 id) dan **refunded** (`13152`).

### Langkah 2 — jalankan di Supabase SQL editor
Urutan penting: cancelled + refunded DULU, baru completed (biar completed nyapu sisanya).

```sql
-- 1) yang cancelled
UPDATE jastip_orders SET order_status='cancelled'
WHERE order_status='processing'
  AND order_no = ANY(ARRAY[ /* tempel 266 id cancelled di sini, dipisah koma */ ]::text[]);

-- 2) yang refunded
UPDATE jastip_orders SET order_status='refunded'
WHERE order_status='processing' AND order_no = '13152';

-- 3) sisanya di range ini = completed
UPDATE jastip_orders SET order_status='completed'
WHERE order_status='processing'
  AND order_no::int BETWEEN 13100 AND 13950;
```

> Catatan tipe: `order_no` disimpan sebagai text. Sesuaikan cast kalau perlu (`order_no::int` / bandingin string). Cek 1 baris dulu sblm commit kalau ragu.

### Langkah 3 — verifikasi
```sql
SELECT order_status, count(*) FROM jastip_orders GROUP BY order_status;
```
`processing` harusnya turun dari 395 ke ~0 (atau hanya nyisa order di luar range 13100–13950 kalau ada).

### Langkah 4 — bersih-bersih
Setelah beres, **nonaktifkan / hapus** snippet WPCode backfill yang udah gak dipakai:
- **14028** Jastip Backfill Utility (ada blok temp `jd_dump_jastip_status` — hapus/deaktif)
- **14027**, **14029** (backfill sekali-pakai lain)

Pastikan **"Jastip Live Detector"** (WPCode ~**14023**) tetap **AKTIF** — itu yang nge-handle order baru ke depan, jd cleanup ini beneran sekali aja.

---

## Yang JANGAN diubah (permintaan owner)
Di prod cuma sentuh yang berkaitan sama task ini. Jangan ubah snippet lain.
Anon key Supabase ada ke-embed di beberapa snippet — jangan ditampilin / commit ke mana-mana.
