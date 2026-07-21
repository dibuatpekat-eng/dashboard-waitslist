-- =====================================================================
-- Deteksi pendaftaran ulang di waitlist
-- =====================================================================
-- Masalah: form memblokir 1 email + 1 HP, jadi orang yang mau daftar lagi
-- pakai email/HP baru. Terbukti dari pola daftar selisih 1-3 menit dengan
-- email nyaris identik (ganti domain, tambah angka, tambah titik).
--
-- Ini deteksi otomatis yang sifatnya INDIKASI, bukan vonis. Operator yang
-- memutuskan -- sama seperti chip Member.
--
-- Objek yang dibuat:
--   launch_dup_pairs   (matview) pasangan entry + alasan + jarak waktu
--   launch_dup_lookup  (matview) ringkasan per entry, buat join cepat
--   launch_waitlist_view  + kolom dup_count, dup_bobot, dup_claimed
--   launch_refresh_dups() RPC untuk refresh manual
--   launch_waitlist_lpa_trgm_idx  index trigram (WAJIB, lihat catatan performa)
--
-- CATATAN PERFORMA
-- Cabang "nama mirip" itu self-join yang biayanya kuadratik. Kalau ditulis
-- menjoin CTE, index trigram tidak bisa dipakai: 4,9 juta perbandingan, 6,8 detik
-- pada 2.226 entry -- dan itu akan jadi ~4x lipat setiap waitlist berlipat ganda.
-- Karena itu cabang tersebut menyentuh launch_waitlist langsung, dan ekspresinya
-- ditulis panjang (bukan lewat CTE atau fungsi wrapper) supaya sama persis dengan
-- ekspresi index. Hasilnya refresh 6.885ms -> 734ms. Jangan "dirapikan" jadi CTE.
-- =====================================================================

CREATE INDEX launch_waitlist_lpa_trgm_idx ON public.launch_waitlist
USING gin ((regexp_replace(split_part(public.jd_normalize_email(email),'@',1),'[^a-z]','','g')) gin_trgm_ops);

-- --- 1. Pasangan yang terindikasi satu orang ------------------------
CREATE MATERIALIZED VIEW public.launch_dup_pairs AS
WITH b AS (
  SELECT w.id, w.email, w.phone, w.status, w.registered_at, w.product,
         public.jd_normalize_email(w.email) AS e,
         public.jd_normalize_phone(w.phone)  AS p,
         -- bagian depan email, angka & titik dibuang: "dina93" dan "dina.a" -> "dina"
         regexp_replace(split_part(public.jd_normalize_email(w.email),'@',1),'[^a-z]','','g') AS lpa
  FROM public.launch_waitlist w
),
wk AS (
  SELECT b.*, COALESCE(me.canon_key, mp.canon_key) AS ck
  FROM b
  LEFT JOIN public.launch_identity_map me ON me.identity_value = b.e
  LEFT JOIN public.launch_identity_map mp ON mp.identity_value = b.p
),
raw AS (
  -- bukti terkuat: dua entry ini satu orang menurut riwayat order
  SELECT a.id AS ia, z.id AS iz, 'riwayat order sama'::text AS alasan, 100 AS bobot
  FROM wk a JOIN wk z ON a.id < z.id
  WHERE a.ck IS NOT NULL AND a.ck = z.ck
  UNION ALL
  -- bagian depan email identik: beda domain, atau cuma beda angka/titik
  SELECT a.id, z.id, 'email inti sama'::text, 90
  FROM wk a JOIN wk z ON a.id < z.id
  WHERE length(a.lpa) >= 5 AND a.lpa = z.lpa
  UNION ALL
  -- nama mirip; jauh lebih kuat kalau daftarnya berdekatan waktu.
  -- Lewat tabel langsung supaya launch_waitlist_lpa_trgm_idx terpakai.
  SELECT a.id, z.id, 'nama mirip'::text,
         CASE WHEN abs(extract(epoch FROM a.registered_at - z.registered_at))/60 <= 60
              THEN 80 ELSE 55 END
  FROM public.launch_waitlist a
  JOIN public.launch_waitlist z
    ON a.id < z.id
   AND regexp_replace(split_part(public.jd_normalize_email(a.email),'@',1),'[^a-z]','','g')
     % regexp_replace(split_part(public.jd_normalize_email(z.email),'@',1),'[^a-z]','','g')
  WHERE length(regexp_replace(split_part(public.jd_normalize_email(a.email),'@',1),'[^a-z]','','g')) >= 5
    AND length(regexp_replace(split_part(public.jd_normalize_email(z.email),'@',1),'[^a-z]','','g')) >= 5
    AND regexp_replace(split_part(public.jd_normalize_email(a.email),'@',1),'[^a-z]','','g')
     <> regexp_replace(split_part(public.jd_normalize_email(z.email),'@',1),'[^a-z]','','g')
    AND similarity(
          regexp_replace(split_part(public.jd_normalize_email(a.email),'@',1),'[^a-z]','','g'),
          regexp_replace(split_part(public.jd_normalize_email(z.email),'@',1),'[^a-z]','','g')) >= 0.62
    AND abs(extract(epoch FROM a.registered_at - z.registered_at))/60 <= 1440
  UNION ALL
  -- nomor HP hanya beda digit terakhir
  SELECT a.id, z.id, 'HP beda 1 digit'::text, 70
  FROM wk a JOIN wk z ON a.id < z.id
  WHERE length(a.p) >= 10 AND left(a.p, length(a.p)-1) = left(z.p, length(z.p)-1)
    AND abs(extract(epoch FROM a.registered_at - z.registered_at))/60 <= 1440
),
agg AS (
  SELECT ia, iz, string_agg(DISTINCT alasan, ' + ') AS alasan, max(bobot) AS bobot
  FROM raw GROUP BY ia, iz
),
-- disimpan dua arah supaya lookup dari entry mana pun sama cepatnya
sym AS (
  SELECT ia AS a, iz AS z, alasan, bobot FROM agg
  UNION ALL
  SELECT iz, ia, alasan, bobot FROM agg
)
SELECT s.a AS waitlist_id, s.z AS twin_id, s.alasan, s.bobot,
       t.email AS twin_email, t.phone AS twin_phone, t.status AS twin_status,
       t.product AS twin_product, t.registered_at AS twin_registered_at,
       round((abs(extract(epoch FROM m.registered_at - t.registered_at))/60)::numeric, 1) AS jarak_menit
FROM sym s JOIN b m ON m.id = s.a JOIN b t ON t.id = s.z;

CREATE INDEX launch_dup_pairs_wid_idx ON public.launch_dup_pairs (waitlist_id);

-- --- 2. Ringkasan per entry (join cepat ke view) ---------------------
CREATE MATERIALIZED VIEW public.launch_dup_lookup AS
SELECT waitlist_id,
       count(*)::int  AS dup_count,
       max(bobot)::int AS dup_bobot,
       bool_or(twin_status IN ('invited','purchased')) AS dup_claimed
FROM public.launch_dup_pairs
GROUP BY waitlist_id;

CREATE UNIQUE INDEX launch_dup_lookup_wid_idx ON public.launch_dup_lookup (waitlist_id);

GRANT SELECT ON public.launch_dup_pairs  TO authenticated;
GRANT SELECT ON public.launch_dup_lookup TO authenticated;

-- --- 3. RPC refresh --------------------------------------------------
CREATE OR REPLACE FUNCTION public.launch_refresh_dups()
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO 'public'
AS $$
BEGIN
  REFRESH MATERIALIZED VIEW public.launch_dup_pairs;
  REFRESH MATERIALIZED VIEW CONCURRENTLY public.launch_dup_lookup;
END;
$$;

GRANT EXECUTE ON FUNCTION public.launch_refresh_dups() TO authenticated;
