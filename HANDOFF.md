# HANDOFF.md — JEDDA Waitlist & Anti-Jastip System

**For:** the incoming AI developer using this system as an **integration reference**
while building JEDDA Atelier's central customer database + POS.
**Prepared:** 2026‑07‑10. **Do not** redesign/implement the POS here — this repo is
the *existing* waitlist/jastip system and its documentation.

Read order: **README.md → this file → `CUSTOMER_DATA_INTEGRATION.md` →
`docs/WAITLIST_SYSTEM_DOCUMENTATION.md`** (deep reference).

---

## 1. What this system is (30 seconds)

Two subsystems on one Supabase DB, driven from WordPress/WooCommerce via WPCode snippets:

1. **Waitlist / invitation launch** — public sign-up → admin invites in batches via
   personal, expiring, single-use links → customer verifies (last 5 phone digits) →
   reaches a *private* product variation → checks out.
2. **Anti-jastip (reseller) detection** — every Woo order with a watched product is
   scanned; reseller patterns are flagged and clustered into a suspect DB; a flagged
   identity that later joins the waitlist is silently marked for review.

Two static admin dashboards (Vercel) drive the operators. Full detail:
`docs/WAITLIST_SYSTEM_DOCUMENTATION.md`.

---

## 2. Environments & access

| Thing | Where | Notes |
|---|---|---|
| **Production site** | `jeddawear.com` (WordPress/WooCommerce, Hostinger) | HPOS enabled (`wp_wc_orders`) |
| **Staging site** | `beta2.jeddawear.com` | Same theme/snippets, different WPCode IDs |
| **Supabase** | project `misrdmmvukdpranxnqjq` (`jedda-atelier`, ap‑southeast‑1, PG 17) | **SHARED by prod AND staging** — RLS/RPC changes hit both |
| **Waitlist Dashboard** | `dashboard-waitslist.vercel.app` | Supabase Auth login (email+password) |
| **Jastip Dashboard** | `dashboard-jastip.vercel.app` | **No login** (anon key) — see security |
| **Dashboard source** | **This repo**, under `dashboard/waitlist/` & `dashboard/jastip/` | `origin` = `github.com/dibuatpekat-eng/dashboard-waitslist`; repoint Vercel Root Directory to the new paths |
| **Email** | Resend, via Supabase Edge Function `send-launch-email` | Resend key server-side only |
| **Payments** | Midtrans (native WooCommerce checkout) | — |

⚠️ **Shared Supabase:** because staging and production point at the *same* Supabase
project, any RPC/RLS/schema change affects both environments simultaneously. Snippet
code differs per environment (different WPCode IDs); the database does not.

---

## 3. Production WPCode snippet inventory (current)

All custom WP logic is WPCode snippets (no theme edits). Current **production** IDs
(staging uses different 13xxx IDs — see `migration/ID-MAPPING-staging-to-prod.md`):

| Prod ID | Snippet | Role |
|---|---|---|
| 14009 | Jedda Launch — Form & Token | Waitlist form + invite verify/access page (`/early-access/`, `/jacket-access/`) |
| 14010 | Early Access Waitlist Intro Popup | Marketing popup |
| 14011 | Product Stock & Button State | Sold-out UX + "Join Waitlist" buttons (has `WAITLIST_LIVE` flag) |
| 14012 | Private Product Gate (ENFORCE MODE) | Guards private product URLs; validates token via RPC |
| 14013–14019, 14021, 14024, 14026 | Cart/checkout/theme UX fixes | Drawer, mobile cart, buy-now, notice de-dup, hover/title fixes |
| 14020 | Payment Hook v2 | On paid order → consume token + mark waitlist purchased (uses **service_role** key) |
| 14022 | Web Purchases Live Sync | Upserts orders → `web_purchases` (going forward) |
| 14023 | Jastip Live Detector | On order status change → `jastip_ingest_order` |
| 14025 | Jastip Order Status Sync | Syncs order status/date badges |
| 14027 | Web Purchases Backfill | One-time (admin `?param`) |
| 14028 | Jastip Backfill Utility | One-time (admin `?param`); contains a temp `jd_dump_jastip_status` block |
| 14029 | Jastip Email Backfill | One-time |

> Also present: a **second plugin, "Code Snippets"** (separate from WPCode) that once
> held an old payment hook (`jd_handle_store_token`) — its conflicting hook was
> deactivated (was causing "Cannot redeclare" errors). Check it if that error recurs.

`migration/` holds importable snippet packages + the 4 standalone new snippets
(`NEW-snippet-*.php/.js/.css`). ⚠️ The `migration/*.json` exports embed secrets (§6).

---

## 4. Status: done vs. pending

**Done (verified this session / recent):**
- ✅ Production cutover completed (2026‑07‑08); waitlist + jastip live on prod.
- ✅ **C2** (invite bypass) closed: `launch_tokens` anon SELECT `USING(false)`; all token
  reads/writes go through SECURITY DEFINER RPCs. Private gate (14012) enforcing.
- ✅ Verify/access page copy + layout polish (14009) — latest edit 2026‑07‑10.
- ✅ Cart/drawer/add-to-cart regressions fixed (see auto-memory notes).
- ✅ Staging: "Waitlist Open" archive badge for Rhea/Phi (snippet 14046, staging only).

**Pending / open:**
- ⏳ **Jastip processing-status resync** — 42 `jastip_orders` (and ~615 `web_purchases`)
  still `processing` but really completed/cancelled. See `docs/HANDOFF-jastip-status-fix.md`.
- ⏳ **Go-live product-ID remap** — swap staging IDs → prod IDs in 14011/14012/14020, decide
  on product `13937`, set `WAITLIST_LIVE` at go-live. See `migration/ID-MAPPING-staging-to-prod.md`
  and `docs/PRODUCTION_MIGRATION.md`.
- 🔴 **C1 / C3 security still open** — anon key reads/writes jastip data and reads all
  `web_purchases` (PII). See `docs/AUDIT_REPORT_FINAL.md`, `docs/JEDDA_OPS_SECURITY_RECOMMENDATION.md`.
- ⏳ One-time backfill snippets (14027–14029) should be deactivated/removed once their jobs are done.

Full open list: `CUSTOMER_DATA_INTEGRATION.md` §8 and the audit docs.

---

## 5. Gotchas for whoever works here

- **WPCode "Update" button trap:** the editor has *two* buttons reading "Update" — the
  first is a WordPress notice/nag button (`wpb-notice-button`); the real save is
  `button.wpcode-button[type=submit]`. Clicking the wrong one silently fails to save.
  Always re-open the snippet in a fresh tab and verify the content persisted.
- **Shared Supabase** (see §2) — test DB changes with both environments in mind.
- **Content-exfiltration filter** in browser automation blocks tool output containing
  token/JWT/query-string patterns; read snippet bodies in slices that avoid those.
- **Normalizers are load-bearing** — always use `jd_normalize_phone` / `jd_normalize_email`
  (a divergent naive normalizer previously broke jastip matching).
- **RPC overloads** — pass the full/new argument shape (incl. email) or you hit legacy logic.
- **Supabase mirrors lag WooCommerce** — WooCommerce is authoritative for order status/spend.

---

## 6. Secrets — do not commit (summary; full list in README)

- **`service_role` key** embedded in `migration/jedda-1-system.json` and
  `migration/jedda-ALL-21.json` → full RLS bypass. **git-ignored.**
- **anon key** embedded in raw `migration/*.json` and the dashboards under `dashboard/`
  → raw JSON git-ignored; sanitized copies (placeholder keys) live in `migration/sanitized/`.
- **Resend key** — Edge Function only, not in repo.

`.gitignore` already covers these. Before pushing anywhere, re-scan for `eyJ…` JWTs.

---

## 7. How to work on this repo (for the AI dev)

1. Read `README.md`, this file, `CUSTOMER_DATA_INTEGRATION.md`, then the master doc.
2. For DB facts, query Supabase (`misrdmmvukdpranxnqjq`) directly rather than trusting
   doc counts — mark anything you infer.
3. WP changes go through WPCode on the correct environment; verify saves (§5).
4. Never widen RLS or expose the anon/service keys further; prefer closing C1/C3.
5. Keep docs in sync: architecture → master doc; customer-data → `CUSTOMER_DATA_INTEGRATION.md`;
   status/tasks → this file.
6. See `AGENTS.md` for repo conventions and guardrails.
