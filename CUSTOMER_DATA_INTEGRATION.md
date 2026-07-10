# CUSTOMER_DATA_INTEGRATION.md

**Purpose:** integration reference for the developer building JEDDA Atelier's
**central customer database + POS**. It describes where customer data lives in
the existing Waitlist + Anti-Jastip system, how identities are matched, what is
authoritative vs. derived, and what should (and should not) feed a future
central customer profile.

**Scope note:** This does **not** design the central customer DB / POS. It is a
map of the *existing* data so the new system can integrate safely.

**Verification legend:**
`✅ CONFIRMED` = verified against the live database or code this session (2026‑07‑10).
`⚠️ INFERRED` = deduced from names/comments/behavior, not fully read.
`❓ FOUNDER` = a product/business decision that needs founder confirmation.

> Data volumes and status counts in this doc are live snapshots from
> **2026‑07‑10** on Supabase project `misrdmmvukdpranxnqjq` (`jedda-atelier`).

---

## 0. TL;DR for the POS/CRM builder

- The **authoritative record of purchases/spend is WooCommerce itself**
  (`wp_wc_orders`, HPOS, on Hostinger). Supabase only holds **partial, derived
  mirrors** (`web_purchases`, `jastip_orders`) that can lag reality. Do not treat
  Supabase purchase tables as ground truth for spend. `✅ CONFIRMED`
- **Identity key = normalized phone first, email second.** Name is unreliable
  (aliases). This matches the existing anti-jastip design. `✅ CONFIRMED`
- Two normalizer functions already exist and should be reused verbatim so the new
  system matches the old one: `jd_normalize_phone`, `jd_normalize_email`.
- A **POS customer/loyalty scaffold already exists** in Supabase (`pos_customers`,
  `pos_sales`, `pos_points_log`, `pos_tier_config`) — the new system may extend or
  replace it, but should not ignore it. `✅ CONFIRMED`
- There are **known data-quality issues** that will corrupt tier/spend math if
  ignored: stale order statuses (`processing` that are really completed/cancelled),
  raw (un-normalized) phone in `web_purchases`, and per-line-item rows. See §8.

---

## 1. The customer-related tables (Supabase `public` schema)

All are in Supabase project `misrdmmvukdpranxnqjq`. RLS is enabled on every table;
see §9 for exposure. Row counts are 2026‑07‑10.

| Table | Rows | What it is | Grain (1 row = ) |
|---|---|---|---|
| `launch_waitlist` | 1,276 | Waitlist / early-access registrations | one registrant (deduped) |
| `launch_tokens` | 3 | Personal invitation access links | one issued invite token |
| `launch_variations` | 8 | Product/colour/size → Woo variation map (public ↔ private) | one variation |
| `launch_allocations` | 8 | Planned stock per variation/batch (advisory) | one allocation line |
| `web_purchases` | 724 | Derived mirror of recent Woo orders (id ≥ ~13275) | **one order LINE ITEM** |
| `jastip_orders` | 3,938 | Derived mirror of orders containing a *watched* product | one order |
| `jastip_flags` | 1,436 | Anti-jastip rule hits awaiting/after review | one flag |
| `jastip_suspects` | 0 | Confirmed/suspected resellers (identity clusters) | one suspect identity |
| `jastip_watched_products` | 8 | Keywords that scope jastip rules | one keyword |
| `jastip_address_links` | 0 | Manually linked equivalent addresses | one address pair |
| `pos_customers` | 1 | **Existing** POS/loyalty customer record | one customer |
| `pos_sales` | 2 | **Existing** POS transactions | one sale |
| `pos_points_log` | 0 | **Existing** loyalty points ledger | one points event |
| `app_settings` | 7 | Key/value config (incl. `pos_tier_config`, `pos_points_config`) | one setting |

> The same Supabase project also contains a full manufacturing/ERP domain
> (`prod_*`, `bb_*`, `dist_*`, `setup_*`, `fin_*`, `event_*`). Those are **out of
> scope** for customer data and should be treated as a separate system that merely
> shares the database. `✅ CONFIRMED`

---

## 2. Where each customer attribute lives

| Attribute | Authoritative source | Also copied/derived in | Notes |
|---|---|---|---|
| **Email** | WooCommerce billing (WordPress) | `launch_waitlist.email`, `launch_tokens.email`, `web_purchases.email`, `jastip_orders.email`/`email_normalized`, `pos_customers.email` | Waitlist email is lowercased + deduped; others vary. `✅` |
| **Phone** | WooCommerce billing (WordPress) | `launch_waitlist.phone` (normalized `62…`), `launch_tokens.phone`, `web_purchases.phone` (**raw**), `jastip_orders.phone` + `phone_normalized`, `pos_customers.phone` | Only `launch_waitlist`, `jastip_orders.phone_normalized`, and the POS tables are consistently normalized. `web_purchases.phone` is raw (`08…`). `✅` |
| **Name** | WooCommerce billing / POS entry | `jastip_orders.recipient_name`, `jastip_suspects.known_names[]`, `pos_customers.nama` | **Not** stored on `launch_waitlist`. Treated as unreliable (aliases) — never an identity key. `✅` |
| **Product interest** | `launch_waitlist.product/color/size/variation_id` | `launch_tokens.*`, `launch_variations` | Waitlist rows carry the intended launch variation. `✅` |
| **Variation** | `launch_variations` (map) | `launch_waitlist.variation_id`, `launch_tokens.variation_id`/`private_variation_id`, `web_purchases.variation_id`, WooCommerce | Public vs **private** variation distinction matters (see §6). `✅` |
| **Registration date** | `launch_waitlist.registered_at` | — | Waitlist join time. `✅` |
| **Invitation status** | `launch_waitlist.status` (`pending`/`invited`/`purchased`) + `launch_tokens` (`is_used`, `expires_at`, `used_at`) | derived `effective_status` in `launch_waitlist_view` | See state model §6. `✅` |
| **Purchase status** | **WooCommerce order status** | `web_purchases.order_status`, `jastip_orders.order_status`, `launch_waitlist.status='purchased'` | Supabase mirrors of status **can be stale** — see §8.1. `✅` |
| **Order ID** | WooCommerce order id | `web_purchases.order_id` (bigint), `jastip_orders.order_no` (text), `launch_tokens.woo_order_id` (text) | Same underlying Woo order id, three column types/names. `✅` |
| **Flags (waitlist)** | `launch_waitlist.is_flagged` + `flag_reason` | set at registration by `launch_submit_waitlist` | Internal only; never shown to customer. `✅` |
| **Jastip signals** | `jastip_flags` (rule hits) + `jastip_suspects` (confirmed) | referenced by `launch_waitlist` flagging | See §5, §7. `✅` |
| **Loyalty tier / spend / points** | `pos_customers.tier/total_spend/points_balance` + `pos_points_log` | `pos_tier_config`, `pos_points_config` in `app_settings` | Existing scaffold; currently ~empty (1 customer). `✅` |

---

## 3. Table-by-table field reference (customer-relevant columns)

### 3.1 `launch_waitlist` — **authoritative for "registered interest"** `✅`
`id uuid` · `email text NOT NULL` (lowercased) · `phone text NOT NULL` (normalized `62…`) ·
`registered_at timestamptz` · `is_flagged bool` · `flag_reason text` · `ip_address text` ·
`status text` (`pending`|`invited`|`purchased`, default `pending`) ·
`product text` · `color text` · `size text` · `variation_id int` ·
`review_cleared bool` · `review_cleared_at timestamptz` · `mark text`.

- **1,276 rows, fully deduped:** 1,276 distinct emails AND 1,276 distinct normalized
  phones — i.e. one row per email and per phone, enforced by the submit RPC. `✅`
- `status` distribution today: `pending` 1,273 · `purchased` 2 · `invited` 1. `✅`
- No `name` column by design.

### 3.2 `launch_tokens` — invitation links `✅`
`token text` (12-char) · `waitlist_id uuid` (FK→launch_waitlist) · `email` · `phone` ·
`created_at` · `expires_at` (the "Access valid until") · `used_at` · `is_used bool` ·
`woo_order_id text` · `product/color/size` · `variation_id int` (the **private** variation).
- Consumed (marked `is_used=true`, `woo_order_id` set) only on a **paid** order, by the WP payment hook — not on verify. `✅`

### 3.3 `web_purchases` — **derived Woo mirror; handle with care** `⚠️/✅`
`id uuid` · `order_id bigint` (Woo order id) · `product_id bigint` · `variation_id bigint` ·
`product_name text` · `email text` · `phone text` (**raw, not normalized**) ·
`order_status text NOT NULL` · `order_date timestamptz` · `synced_at timestamptz`.
- **724 rows but only 641 distinct `order_id`** → one row **per line item**; a multi-item
  order appears multiple times (75 orders have >1 line). Count *distinct orders*, not rows. `✅`
- Only backfilled from order id ≥ ~13275 → **not full history**. `✅ CONFIRMED` (backfill snippet gate)
- Statuses are stale: today `processing` 615 · `completed` 109 (many "processing" are really
  completed/cancelled in Woo — see §8.1). `✅`
- Populated by WP snippets: live sync (per order going forward) + a one-time backfill. `⚠️ INFERRED` from snippet names/comments.

### 3.4 `jastip_orders` — **derived Woo mirror, watched products only** `✅`
`id uuid` · `order_no text` (Woo id, UNIQUE — 3,938 distinct) · `recipient_name` ·
`phone` + `phone_normalized` · `address_raw` + `address_normalized` · `subdistrict/city/province/postal_code` ·
`products jsonb` (aggregated line items) · `email` + `email_normalized` · `order_status` ·
`order_date` · `uploaded_at` · `batch_id`.
- One row per order (line items inside `products`). Only orders containing an **active watched
  product** are ingested — so this is *not* general purchase history. `✅`
- Has both raw and normalized phone/email → **the cleanest existing source of normalized identity + address**. `✅`
- `order_status` also stale: today `completed` 3,849 · `refunded` 44 · **`processing` 42** · `cancelled` 3. See §8.1. `✅`

### 3.5 `jastip_flags` / `jastip_suspects` — reseller signals `✅`
- `jastip_flags`: `order_id`→jastip_orders, `rule_triggered`, `confidence` (`high`/`medium`/`low`),
  `detail`, `matched_suspect_id`, `matched_order_id`, `address_similarity_score`, `reviewed`, `review_action`.
- `jastip_suspects` (currently 0 confirmed): `phone`+`phone_normalized`, `email`+`email_normalized`,
  `address_raw`+`address_normalized`, `known_names text[]`, `known_products jsonb`, `status`
  (`suspected`/`confirmed`/…), `confidence`, `group_id`, `total_appearances`.
- **This is the "is this identity a reseller?" source of truth** the POS should consult before
  granting loyalty tier/points. `✅`

### 3.6 `pos_customers` / `pos_sales` / `pos_points_log` — **existing POS scaffold** `✅`
- `pos_customers`: `id` · `nama text NOT NULL` · `phone text NOT NULL` · `email` · `birthday` ·
  `tier text` (default `Atelier`) · `total_spend numeric` · `points_balance int` · `notes` · timestamps.
- `pos_sales`: `customer_id` · `kasir` · `channel` (default `Store`) · `subtotal`/`discount`/`total` ·
  `points_redeemed`/`points_earned` · `payment_method`/`payment_ref` · `status`.
- `pos_points_log`: `customer_id` · `sale_id` · `delta` · `type` · `balance_after`.
- `app_settings.pos_tier_config` (tiers incl. `Atelier` min_spend 0, multiplier 1.0…) and
  `pos_points_config` (`earn_per_rupiah` 0.0001, `redeem_rupiah_per_point` 100) already define
  loyalty math. `✅`
- **Decision `❓ FOUNDER`:** does the new central customer DB extend this `pos_*` scaffold, or
  replace it? It exists but is nearly empty (1 customer, 2 sales).

---

## 4. Normalization (reuse these exactly)

### 4.1 Phone — `jd_normalize_phone(text) → text` (IMMUTABLE) `✅ CONFIRMED (read source)`
Strips all non-digits, then:
- `0xxxxxxxxx` → `62xxxxxxxxx`
- `62xxxxxxxxx` → unchanged
- `8xxxxxxxxx` → `628xxxxxxxxx`
- empty/no digits → `NULL`

Result is an Indonesian MSISDN without `+` (e.g. `6285…`). **Use this for all phone matching.**

### 4.2 Email — `jd_normalize_email(text) → text` (IMMUTABLE) `✅ CONFIRMED (read source)`
- lowercases + trims;
- strips a `+tag` from the local part;
- for `gmail.com`/`googlemail.com`, additionally removes dots in the local part and forces
  `@gmail.com` (so `John.Doe+x@googlemail.com` → `johndoe@gmail.com`).

There is also `jd_normalize_address(text)` for fuzzy address matching (used by jastip via `pg_trgm`).

**Storage reality (important):**
- `launch_waitlist`: phone stored normalized (`62…`), email stored lowercased — **at write time**. `✅`
- `jastip_orders`/`jastip_suspects`: keep **both** raw and `_normalized` columns. `✅`
- `web_purchases`: **raw** phone/email — you must normalize **at read time** to match. `✅`
- `pos_customers`: `phone`/`email` present but normalization **not verified** — treat as needing
  normalization on read. `⚠️ INFERRED`

---

## 5. Current identity-matching behavior (what exists today)

Two different mechanisms exist; the new system should understand both.

### 5.1 Waitlist ↔ jastip check (at registration) `✅ CONFIRMED (behavior)`
`launch_submit_waitlist` (SECURITY DEFINER RPC), on submit:
1. normalizes phone, rate-limits by IP (≤3/hr), cleans stale expired rows,
2. rejects duplicate email / duplicate phone,
3. **flags** the registrant (`is_flagged=true`, `flag_reason`) if their **phone OR email** matches
   `jastip_suspects` (confirmed/suspected) **or** `jastip_orders`⋈`jastip_flags` (high/medium).
   Flag is silent (never returned to the client).

### 5.2 Jastip identity clustering (in the Jastip Dashboard) `✅ CONFIRMED (behavior)`
Flags are grouped into one "person" via **union-find over shared normalized phone/email/address
+ explicit flag links** — so one reseller = one card regardless of how many orders/aliases.
Confirming a cluster calls `jastip_confirm_suspect`, which **merges** into an existing suspect by
exact phone OR exact email OR fuzzy address (`similarity ≥ 0.85`) rather than duplicating.

### 5.3 Purchase lookup (in the Waitlist Dashboard) `✅ CONFIRMED (behavior)`
`launch_waitlist_view` LATERAL-joins `web_purchases` on **`lower(email)` OR normalized phone** to
show "already purchased". This is the closest existing example of cross-linking a registrant to a
Woo purchase — but it inherits `web_purchases`'s stale-status and partial-history limits.

> **There is no single existing "customer" entity that unifies all of the above.**
> Unifying waitlist + purchases + jastip into one profile is exactly the new system's job.

---

## 6. Relationships: registration → invitation → order → jastip

```
launch_waitlist (registrant)
   │ 1
   │        admin invites
   ▼ N
launch_tokens (invite link, carries PRIVATE variation_id)
   │        customer verifies (last-5 phone) → reaches private product → pays
   ▼
WooCommerce order  ── mirrored ──▶ web_purchases (line items, id≥13275)
   │                              └▶ launch_tokens.woo_order_id + is_used=true
   │                              └▶ launch_waitlist.status='purchased'
   │
   └── if contains a WATCHED product ──▶ jastip_orders ──rules──▶ jastip_flags
                                                             └▶ jastip_suspects (on confirm)
                                                                     │
                          (future) waitlist signup by same phone/email ──▶ is_flagged
```

Join keys between domains:
- **waitlist ↔ token:** `launch_tokens.waitlist_id = launch_waitlist.id`. `✅`
- **token ↔ order:** `launch_tokens.woo_order_id = <Woo order id>`. `✅`
- **order ↔ web_purchases:** `web_purchases.order_id = <Woo order id>`. `✅`
- **order ↔ jastip:** `jastip_orders.order_no = <Woo order id as text>`. `✅`
- **registrant ↔ purchase (fuzzy):** normalized phone OR normalized email. `✅`
- **public ↔ private product:** `launch_variations` maps `product_id`/`variation_id` ↔
  `private_product_id`/`private_variation_id`. Tokens carry the **private** id; orders may
  reference either — reconcile via `launch_variations`. `✅`

---

## 7. What should feed a future central customer profile (recommendation)

**Good candidates to feed the central profile (identity + history):**
- **Identity:** normalized phone (primary key candidate), normalized email(s) (a person can have
  several), name(s) as *display aliases* only.
- **Purchase history & spend:** from **WooCommerce (authoritative)** — order id, date, status,
  line items, order total. Use `web_purchases`/`jastip_orders` only as convenience mirrors, and
  only after status is reconciled (§8.1).
- **Engagement:** waitlist registrations (`launch_waitlist`: registered_at, product interest,
  invited?, purchased?) — a strong "prospect/loyal" signal.
- **Risk flag:** whether the identity is in `jastip_suspects` (confirmed reseller) → should be
  **excluded from or capped in** loyalty tiers/points. Accidental duplicate accounts (same person,
  multiple emails/phones, **not** in jastip) should be **merged**, not excluded. `❓ FOUNDER` (exact
  policy for capping resellers vs. merging duplicates).

**Should remain domain-specific (do NOT fold into the central profile):**
- Invitation mechanics: `launch_tokens`, `expires_at`, verify/rate-limit state, `launch_variations`,
  `launch_allocations`, `launch_shadow_log`.
- Jastip internals: `jastip_flags`, rule details, address similarity scores, `jastip_batches`,
  `jastip_address_links`, watched keywords. (Expose only a boolean/enum "reseller risk" outward.)
- ERP/manufacturing (`prod_*`, `bb_*`, `dist_*`, …) — unrelated.

**Identity resolution approach (matches existing design):** cluster by normalized phone, bridge via
shared normalized email, merge accidental multi-accounts; treat a cluster as a reseller only if it
intersects jastip signals. (See `docs/HANDOFF-jastip-status-fix.md` and the founder discussion notes
for the "merge vs. exclude" distinction.)

---

## 8. Current unresolved bugs & data-quality issues (must read)

### 8.1 Stale order statuses (`processing` that aren't) — **affects spend/tier math** `✅ CONFIRMED`
Supabase mirrors do **not** reliably follow later WooCommerce status changes:
- `jastip_orders`: **42** rows still `processing` (down from ~395; partially cleaned) that are
  actually completed/cancelled/refunded in Woo.
- `web_purchases`: **615** rows `processing` vs only 109 `completed` — a large fraction are stale.
- **Impact for POS/CRM:** counting spend or purchase frequency from these tables *as-is* will be
  wrong. Reconcile against WooCommerce (authoritative) before computing tiers.
- Fix approach + root cause: `docs/HANDOFF-jastip-status-fix.md` (the ingest RPC updates status only
  when re-fed; a proper one-time status resync is still pending). `❓ FOUNDER`/dev: decide whether to
  fix the mirror or read spend straight from WooCommerce.

### 8.2 `web_purchases` grain & coverage `✅`
Per-line-item rows (724 rows / 641 orders) and history only from id ≥ ~13275. Any "number of
purchases" metric must `COUNT(DISTINCT order_id)` and acknowledge missing older history.

### 8.3 Un-normalized phone in `web_purchases` `✅`
Only 7/724 phones are in `62…` form; matching requires `jd_normalize_phone(phone)` at read time.

### 8.4 Two RPC overloads still exist `✅`
`launch_submit_waitlist` and `jastip_ingest_order` each have a legacy + current overload; callers
must pass the full/new argument shape (incl. email) to hit current logic. Technical debt.

### 8.5 Private-gate status — **now believed ENFORCING** `⚠️ UPDATED`
The master doc (2026‑07‑06) left "is the private gate enforcing or shadow-logging?" open. Later work
indicates the production gate **does enforce** (redirects invalid tokens) after the C2 token-RLS
lockdown. Treat as enforcing, but a fresh read of the production gate snippet is the final confirmation. `❓ FOUNDER`/dev to re-confirm.

---

## 9. Sensitive data & security considerations `✅ CONFIRMED (RLS read live)`

This is customer PII (emails, phones, addresses, purchase history). Current exposure:

- **`jastip_orders`, `jastip_flags`, `jastip_watched_products` → policy `anon_all` (`USING true /
  WITH CHECK true`).** The **anon key can read, write, AND delete** all jastip data (PII +
  addresses). This is audit finding **C3, still open**.
- **`web_purchases` → SELECT for role `public`** (`USING true`). Anyone with the anon key can read
  **all customer emails/phones/order history**. PII exposure.
- **The anon key is embedded** in the WP front-end snippets and both dashboards; a **`service_role`
  key** (full RLS bypass) is embedded in the WordPress payment-hook snippet export. See §10.
- `launch_tokens`, `launch_waitlist`: properly locked down (anon cannot read tokens; anon may only
  set `status='purchased'` / `is_used=true`). This is the post-C2 good state. `✅`
- `pos_customers`, `pos_sales`, `app_settings`: `authenticated`-only. `✅`

**Implication for the new system:** do **not** build the central customer DB on the anon key, and do
**not** widen access. Any new customer store should be `authenticated`/service-role gated, and the
open jastip/web_purchases policies should be closed (see `docs/JEDDA_OPS_SECURITY_RECOMMENDATION.md`,
`docs/AUDIT_REPORT_FINAL.md`). `❓ FOUNDER`: prioritize closing C3 before loading more PII in.

---

## 10. Secrets touching customer data (never commit)

- **`service_role` key** — embedded in `migration/jedda-1-system.json` and
  `migration/jedda-ALL-21.json` (the payment-hook snippet uses it to write tokens after RLS
  lockdown). Full DB read/write/delete. `✅ CONFIRMED`
- **anon key** — embedded in every `migration/*.json` export and both dashboards. Public by design,
  but powerful given the open jastip/web_purchases policies. `✅`
- **Resend API key** — server-side only, inside the Supabase Edge Function `send-launch-email`; not
  in the repo. `⚠️ INFERRED` (per master doc).

`.gitignore` excludes the raw `migration/*.json`; sanitized copies (placeholder keys) are
tracked under `migration/sanitized/`. See README §Secrets.

---

## 11. Confirmed vs. inferred — quick index

| Claim | Status |
|---|---|
| Table list, columns, row counts, RLS policies | `✅ CONFIRMED` (live query 2026‑07‑10) |
| `jd_normalize_phone` / `jd_normalize_email` logic | `✅ CONFIRMED` (read function source) |
| `launch_waitlist` deduped & normalized | `✅ CONFIRMED` (aggregate query) |
| `web_purchases` per-line-item, raw phone, id≥13275 | `✅ CONFIRMED` (query) / `⚠️` (13275 gate from snippet) |
| Stale `processing` counts | `✅ CONFIRMED` (query) |
| Who populates `web_purchases`/`jastip_orders` (WP snippets) | `⚠️ INFERRED` (snippet names/comments) |
| Payment-hook internals & "session cross-contamination" fix | `⚠️ INFERRED` |
| Private gate now enforcing | `⚠️ UPDATED / to re-confirm` |
| `service_role` key embedded in 2 migration JSONs | `✅ CONFIRMED` (decoded JWT role, key not printed) |

## 12. Decisions requiring founder confirmation (`❓ FOUNDER`)

1. Extend the existing `pos_*` scaffold, or build a fresh central customer store?
2. Read spend/purchase history straight from **WooCommerce** (authoritative) or from the reconciled
   Supabase mirrors?
3. Loyalty policy for resellers: exclude entirely, or cap tier? And the exact rule for **merging
   accidental duplicate accounts** vs **excluding intentional jastip**.
4. Priority of closing the open RLS (C3 jastip + `web_purchases` public read) **before** loading more
   PII into a new customer DB.
5. Whether older purchase history (pre‑13275, not in `web_purchases`) must be backfilled for fair
   tiering.
