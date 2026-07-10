# JEDDA Waitlist & Anti-Jastip System — Engineering & Product Documentation

**Document status:** Complete as of 2026-07-06.
**Audience:** Independent auditor, future engineers, maintainers.
**Assumed prior knowledge:** None. This document is self-contained.

---

> ## ⚠️ ERRATA / STATUS UPDATE — 2026-07-10
> This document is a **point-in-time record (2026-07-06, staging era)**. It is preserved
> as-is for its architecture/rationale value, but the following has **changed or been
> resolved** since. For current truth, prefer `../HANDOFF.md` and
> `../CUSTOMER_DATA_INTEGRATION.md`.
>
> 1. **Production migration happened (2026-07-08).** WPCode snippet IDs cited below are
>    **staging** IDs; production uses different IDs (14009–14029) — see `../HANDOFF.md` §3
>    and `../migration/ID-MAPPING-staging-to-prod.md`.
> 2. **Private-product gate (§17.4, §28 Q1): now believed ENFORCING**, not shadow-only.
>    Production gate redirects invalid tokens after the C2 lockdown. Re-confirm by reading
>    the live gate snippet.
> 3. **C2 invite-bypass closed.** `launch_tokens` anon `SELECT` is now `USING(false)`; token
>    reads/writes go through `SECURITY DEFINER` RPCs (`launch_token_status`,
>    `launch_consume_token`, …). §14.6/§17's "anon may SELECT a token" is **outdated**.
> 4. **`jd_normalize_email` now exists** (gmail dot/`+tag` canonicalization) alongside
>    `jd_normalize_phone`/`jd_normalize_address` — see `../CUSTOMER_DATA_INTEGRATION.md` §4.
> 5. **`web_purchases` populated** by WP snippets (live sync + one-time backfill, id ≥ ~13275);
>    it is **per-line-item** (724 rows / 641 orders) — §22 "sync source Unknown" is resolved.
> 6. **Open data bug:** stale `processing` statuses in `jastip_orders` (42) and `web_purchases`
>    (~615) — affects any spend/tier math. See `HANDOFF-jastip-status-fix.md`.
> 7. **Still open:** C1 anon-key exposure and C3 world-open `jastip_*` + `web_purchases`
>    RLS (§17.1, §22, §23) remain unresolved.

---

> **A note on evidence.** Wherever possible this document cites the actual file,
> line, database object, or snippet that implements a behaviour. Some server-side
> PHP snippets live inside the WordPress WPCode plugin (not in a git repo) and
> their full bodies could not be machine-extracted during authoring because the
> browser automation tool's content-exfiltration filter blocks output containing
> token/query-string patterns. For those, the behaviour is documented from the
> function names, code comments, and the database tables they read/write, and is
> explicitly flagged. Anything that genuinely could not be determined is marked
> **“Unknown.”**

---

## 1. Executive Summary

JEDDA is an Indonesian fashion brand. This system governs a **controlled,
invitation-only product launch** (the SS26 jacket launch — the *Rhea Flare
Jacket* and *Phi Jacket*) layered on top of an existing WooCommerce store, plus a
**res eller/“jastip” detection system** that protects that launch (and the store
generally) from bulk resellers.

There are two intertwined but distinct subsystems:

1. **The Waitlist System** — a public sign-up form where customers register
   interest in a launch product. Admins later invite selected registrants in
   controlled batches via personal, expiring, single-use access links. Invited
   customers verify identity (last 5 digits of their phone), reach a *private*
   WooCommerce product variation, and check out. This throttles demand,
   prevents bots/scalpers from buying instantly, and creates scarcity/exclusivity.

2. **The Anti-Jastip System** — “jastip” (Indonesian: *jasa titip*, “entrust-buy
   service”) means resellers who buy limited items on behalf of others to resell.
   Every real WooCommerce order is scanned live; orders matching reseller
   patterns (same phone/email/address buying watched products repeatedly, or bulk
   buying one watched product) are flagged. A confirmed-reseller database is
   built. When a flagged person later signs up for a waitlist, they are
   automatically marked for review so admins can decline to invite them.

The two systems meet at exactly one point: **when someone submits the waitlist
form, the server checks them against the jastip database and sets
`is_flagged=true`** if they match.

Both subsystems are backed by a single **Supabase** (PostgreSQL) project
(`misrdmmvukdpranxnqjq`). The customer-facing UI and all server-side enforcement
run inside **WordPress + WooCommerce** (host: Hostinger) via **WPCode** snippets.
Two separate static **admin dashboards** (plain HTML/CSS/JS, deployed on Vercel)
provide the operator interfaces.

---

## 2. Product Vision

Launch scarce, high-demand products in a way that feels **exclusive, fair, and
calm** rather than a chaotic first-come-first-served scramble — while
systematically keeping resellers out so genuine customers get the product.

The experience should read as a quiet luxury invitation: *“Thank you for your
patience. Your reserved access is now available.”* — not a countdown-timer flash
sale.

---

## 3. Business Objectives

- **Control launch demand.** Release inventory in admin-curated batches instead
  of all at once, avoiding stock/checkout stampedes and letting operations keep up.
- **Exclude resellers (jastip).** Prevent bulk resellers from consuming
  limited-edition stock, which erodes brand value and denies real customers.
- **Protect brand perception.** Every touchpoint (form, email, access page) must
  feel premium and intentional.
- **Give operators leverage & auditability.** Admins can see who registered,
  who’s a reseller risk, who already bought, and can invite/segment precisely.
- **Zero bot instant-buys.** A registrant cannot buy immediately; they must be
  individually invited and verify identity.

## 4. Customer Objectives

- Register interest quickly (email + phone, ~15 seconds).
- Be told clearly they’re on the list without over-promising.
- Receive a personal, trustworthy invitation when it’s their turn.
- Complete purchase through a link that feels secure and “reserved for me.”
- Not be publicly shamed or told they’re “flagged” (flagging is invisible to the
  customer by design).

## 5. Non-Goals

- **Not** a general e-commerce platform — it rides on existing WooCommerce.
- **Not** a payment system — checkout/payment is native WooCommerce + Midtrans.
- **Not** real-time inventory management — allocation is an advisory planning aid,
  not a hard stock lock (see §22, Known Limitations).
- **Not** a public reseller blocklist — jastip flags are internal only; a flagged
  person is *reviewed*, never auto-rejected or notified.
- **Not** a CRM/marketing-automation tool — no campaigns, segmentation beyond
  launch invites, or lifecycle emails.
- **Not** a mobile app — everything is web.

---

## 6. System Overview

```
                         ┌────────────────────────────────────────────┐
                         │             SUPABASE (Postgres)             │
                         │  Project: misrdmmvukdpranxnqjq              │
                         │                                            │
   Customer (browser)    │  launch_waitlist   jastip_orders          │
        │                │  launch_tokens     jastip_suspects        │
        ▼                │  launch_variations jastip_flags           │
 ┌───────────────┐  RPC  │  launch_allocations jastip_watched_products│
 │ WordPress /   │◀─────▶│  web_purchases     (+ RPC functions, RLS,  │
 │ WooCommerce   │       │                     views, 1 edge function)│
 │ (WPCode       │       └────────────────────────────────────────────┘
 │  snippets)    │              ▲                        ▲
 └───────────────┘              │ REST (authenticated)   │ REST (authenticated)
        │                       │                        │
        │ live order hook       │                        │
        ▼                ┌──────────────┐        ┌──────────────────┐
   jastip_ingest_order   │  Waitlist    │        │  Jastip          │
                         │  Dashboard   │        │  Dashboard       │
                         │ (Vercel)     │        │ (Vercel)         │
                         └──────────────┘        └──────────────────┘
                          dashboard-waitslist.    dashboard-jastip.
                          vercel.app              vercel.app
```

**Three tiers:**

1. **WordPress + WooCommerce** (Hostinger) — customer-facing form, invitation
   access/verify page, private-product gate, payment hook, and the live jastip
   order scanner. All implemented as WPCode snippets.
2. **Supabase** — the single source of truth. Tables, `SECURITY DEFINER` RPC
   functions (the real business logic), Row-Level Security, one view, one Edge
   Function (transactional email relay).
3. **Two static admin dashboards** — single-file HTML apps on Vercel, sharing one
   GitHub repo, talking directly to Supabase REST/RPC.

---

## 7. High-Level Architecture

### 7.1 Data flow: customer registration
```
Browser form (WPCode 13948 on /early-access/)
   └─POST rpc/launch_submit_waitlist(email, phone, product, color, size, ip)
        ├─ normalize phone (08→62)
        ├─ rate-limit by IP (≤3/hour)
        ├─ clean up stale expired rows for same email/phone
        ├─ reject duplicate email / duplicate phone
        ├─ CHECK JASTIP: phone OR email match in jastip_suspects
        │  (confirmed/suspected) OR in jastip_orders+jastip_flags (high/medium)
        │      → is_flagged = true, flag_reason set
        └─ INSERT launch_waitlist (status='pending')
```

### 7.2 Data flow: admin invitation → purchase
```
Waitlist Dashboard (admin, Supabase Auth session)
   └─ select registrants → generate launch_tokens (random 12-char, expiry)
        │   token carries private_variation_id (NOT the public one)
        └─ send email via Edge Function send-launch-email (Resend)
Customer clicks /jacket-access/?token=XXX  (WPCode 13948)
   └─ rpc/launch_get_phone_hint(token) → shows masked phone (first 4 + ****)
   └─ user types last 5 digits
   └─ rpc/launch_verify_phone(token, last5)
        ├─ rate-limit ≤5 failed/15min per token
        ├─ reject if used / expired / mismatch
        └─ success → returns product/color/size/variation_id → reveal private product
Customer checks out (native WooCommerce + Midtrans)
   └─ Payment Hook (WPCode 14010) on order paid:
        ├─ mark launch_tokens.is_used = true
        └─ mark launch_waitlist.status = 'purchased'
```

### 7.3 Data flow: live jastip detection
```
Any WooCommerce order reaches processing/completed/refunded
   └─ WPCode 14034 hook fires → POST rpc/jastip_ingest_order(...email...products...)
        ├─ insert jastip_orders (idempotent on order_no)
        ├─ Rule 1: match confirmed suspects (phone/email/address≥0.85) → high
        ├─ Rule 2: bulk same watched product (qty≥2 in one order) → high
        ├─ Rule 4: cross-order same watched product + same phone/email/addr
        │          → high (exact phone/email) / high (addr≥0.95) / medium (0.85–0.94)
        └─ INSERT jastip_flags
Jastip Dashboard (admin) reviews clustered flags → Confirm (writes jastip_suspects)
```

---

## 8. Complete Customer Journey

### 8.1 Registration (the “early access” page)
1. Customer lands on `/early-access/` (WordPress page rendering WPCode snippet
   **13948**). Optional intro popup (WPCode **13978**) may appear.
2. Fills email + phone (and, for the launch, an implicit or selected
   product/color/size). Form styling: serif headline, minimal fields, single dark
   CTA button (see §19 Visual Language).
3. On submit, browser calls `rpc/launch_submit_waitlist`. Client sees only a
   generic success or a specific error (duplicate email/phone, rate limited).
   **The customer is never told whether they were jastip-flagged** — flagging is
   invisible (server comment in `launch_submit_waitlist`: *“tidak reveal apakah
   flagged atau tidak ke frontend”*).
4. Success state renders in-place (WPCode 13948 lines ~222–341): a calm
   confirmation. Registrant now exists in `launch_waitlist` with `status='pending'`.

### 8.2 Waiting
The customer waits. There is no self-service status page. Nothing is promised
about timing.

### 8.3 Invitation
When an admin invites them (see §9), the customer receives an email
(subject: *“Your access is now open.”*) built by `buildLaunchEmailHtml`
(dashboard `index.html:894`). It addresses them by a first-name guess derived
from their email local-part, names the product/color/size, and contains one
button: **COMPLETE YOUR ORDER** → `https://<domain>/jacket-access/?token=<token>`.
The email states the link is personal, single-use, time-limited, and must not be
shared.

### 8.4 Access & identity verification
1. `/jacket-access/?token=XXX` (WPCode 13948 “verify page”, lines ~354–421).
2. Page calls `rpc/launch_get_phone_hint(token)` → shows a masked phone
   (`left(phone,4) || '****'`) so the user recognizes it’s their own invite.
3. User enters the **last 5 digits** of their phone.
4. `rpc/launch_verify_phone(token, last5)`:
   - Rejects if token not found, already used, expired, or digits mismatch.
   - Rate-limits to 5 failed attempts per token per 15 minutes.
   - On success returns `product, color, size, variation_id` (the **private**
     variation) → the page reveals/links the private product for checkout.
   - **Verification does NOT consume the token** (comment in 13948:
     *“Token sekarang TIDAK dikonsumsi di sini”*).

### 8.5 Purchase
Customer completes checkout through native WooCommerce (payment via Midtrans,
per the sandbox simulator tab observed). On successful payment, the **Payment
Hook (WPCode 14010)** fires: it marks the token `is_used=true` and the waitlist
row `status='purchased'`. This is the point of token consumption.

### 8.6 Edge journeys
- **Expired invite:** token past `expires_at` → verify fails “expired.” The
  waitlist row becomes actionable again via `effective_status='expired'` (see
  §16 view logic) so the admin can re-invite.
- **Re-registration attempt:** duplicate email/phone is rejected at submit, *but*
  stale expired/abandoned rows for the same person are cleaned up first (see
  `launch_submit_waitlist` v2 step 5) so a genuinely lapsed person can re-register.
- **Reseller signup:** flagged silently; they can still register and even be
  invited — the flag is an admin decision aid, not a hard block.

---

## 9. Complete Admin Workflow

### 9.1 Waitlist Dashboard (`dashboard-waitslist.vercel.app`)
1. **Login** — real Supabase Auth (email + password), not a hardcoded password
   (`jdLogin`, `index.html:1120`). Session held in `sessionStorage`; auto-restore
   if unexpired (`restoreSession`, `:1165`). All writes are signed as the
   `authenticated` role (`authHeaders`, `:1211`) because RLS requires it.
2. **Environment toggle** — Staging (`beta2.jeddawear.com`) vs Production
   (`jeddawear.com`) governs the domain used in access links (`ENV_DOMAINS`,
   `:864`). Purpose: test end-to-end on staging without emailing real production
   links.
3. **Review waitlist** — paged table (`loadWaitlist`, `:1310`) from
   `launch_waitlist_view`. Summary cards: total, Eligible (clean & pending),
   “Perlu Ditinjau” (flagged OR already-purchased), Undangan Terkirim (ever
   invited), Sudah Beli. A single action-oriented badge per row; jastip rows get a
   red left-accent, already-purchased rows an orange accent (**one badge, never
   stacked**, jastip wins — `renderRows` branch `:1385`).
4. **Cross-check jastip (reference)** — `loadJastipSuspects` (`:942`) lists
   confirmed suspects for manual reference; `whitelistJastip` (`:980`) marks a
   suspect reviewed/whitelisted (writes only `jastip_suspects`, never
   `launch_waitlist`).
5. **Look up past purchases** — `lookupPurchases` (`:994`) queries `web_purchases`
   by email or phone (manual on-demand tool).
6. **Invite (single or batch)** — select rows → `generateSelected` (`:1499`)
   builds a batch of tokens carrying the correct **`private_variation_id`** →
   `confirmBatch` (`:1744`) inserts `launch_tokens` (deleting any prior token for
   that waitlist_id first, since there’s no unique constraint) and flips rows to
   `status='invited'` → `sendEmails` (`:1794`) relays each email via the Edge
   Function with a 200 ms gap.
7. **Manage tokens** — `loadTokens` (`:1546`): active/used/expired counts,
   per-token “Generate token baru” (`tokenAction 'new'`, `:1625`) which deletes the
   old token, re-fetches the correct private variation, inserts a fresh token,
   resets the waitlist to `invited`, and re-sends. Used tokens are **locked in
   production** to avoid re-selling a consumed slot.
8. **Allocations (planning)** — `loadAllocations` (`:1847`) shows
   `launch_allocation_summary`; `saveAllocation` / `deleteAllocation` manage
   `launch_allocations`. Advisory only.
9. **Export** — `exportCSV` (`:1959`) dumps unused tokens.

### 9.2 Jastip Dashboard (`dashboard-jastip.vercel.app`)
Static single-file app `dashboard/jastip/index.html`. Anon-key access (no login —
see §17 Security). Three tabs:

1. **Flags** (`loadFlags`, `renderFlags`) — the review queue. Unreviewed flags for
   the three current rules are pulled, then **clustered by identity** (union-find
   over shared normalized phone/email/address AND flag links — see §14.4) so one
   real person = one card, regardless of how many orders/pairwise matches exist.
   Each card shows the person’s orders, a **summarized** reason set (e.g. “6 order
   dikirim ke alamat yang sama persis, tapi pakai HP berbeda-beda”), and
   Confirm/Clear acting on the whole cluster at once.
2. **Database Jastip** (`loadSuspects`, `renderSuspects`) — the confirmed/suspected
   reseller list, address-led cards, searchable by phone/email/address/name.
   Confirm from a flag calls `rpc/jastip_confirm_suspect`, which **merges** into an
   existing suspect (by phone/email/fuzzy-address) instead of duplicating.
3. **Watched Products** (`loadWatched`) — the keyword list (product *names*) that
   scopes bulk/cross-order rules. Toggle active, add new.

---

## 10. Component Inventory

| # | Component | Layer | Implemented in | Purpose |
|---|-----------|-------|----------------|---------|
| C1 | Waitlist sign-up form + verify/access page | WP front-end | WPCode **13948** (HTML/CSS/JS) | Register interest; verify invite; reach private product |
| C2 | Intro popup | WP front-end | WPCode **13978** | Early-access intro/marketing popup (details Unknown — not fully read) |
| C3 | Private product gate | WP server | WPCode **13980** “ENFORCE MODE” | Guard private product URLs; **currently shadow-logging** to `launch_shadow_log` (see §17.4) |
| C4 | Payment hook | WP server | WPCode **14010** “v2” | On paid order: consume token (`is_used`), mark waitlist `purchased`; “fixed session cross-contamination” |
| C5 | Cart drawer visual fixes | WP front-end | WPCode **14018** | Waitlist item image + notice styling in cart drawer (cosmetic) |
| C6 | Live jastip detector | WP server | WPCode **14034** | On order processing/completed/refunded → `jastip_ingest_order` |
| C7 | Jastip historical backfill | WP server (run-once) | WPCode **14036** | Admin-triggered bulk scan of past orders into pipeline |
| C8 | Jastip email backfill | WP server (run-once) | WPCode **14037** (now inactive) | One-shot: populate email on the 105 orders currently under review |
| C9 | Waitlist submit RPC | Supabase | `launch_submit_waitlist` (2 overloads) | Register + rate-limit + dedupe + jastip check |
| C10 | Phone hint RPC | Supabase | `launch_get_phone_hint` | Masked phone for invite recognition |
| C11 | Phone verify RPC | Supabase | `launch_verify_phone` | Verify last-5 digits, gate access |
| C12 | Jastip ingest RPC | Supabase | `jastip_ingest_order` (2 overloads) | The rule engine |
| C13 | Jastip confirm RPC | Supabase | `jastip_confirm_suspect` | Merge-or-insert a confirmed suspect |
| C14 | Normalizers | Supabase | `jd_normalize_phone`, `jd_normalize_address` | Canonicalize phone/address |
| C15 | Waitlist view | Supabase | `launch_waitlist_view` | Adds `already_purchased`, `effective_status` |
| C16 | Email relay | Supabase | Edge Function `send-launch-email` | Server-side Resend call (avoids browser CORS) |
| C17 | Waitlist Dashboard | Admin | `dashboard/waitlist/index.html` | Invite/token/email/allocation operator UI |
| C18 | Jastip Dashboard | Admin | `dashboard/jastip/index.html` | Flag review + suspect DB operator UI |

---

## 11. File Inventory & Folder Structure

```
(repo root)                               (git repo; remote: github.com/dibuatpekat-eng/dashboard-waitslist)
├── dashboard/
│   ├── waitlist/index.html               C17 — Waitlist Dashboard (single file)
│   └── jastip/index.html                 C18 — Jastip Dashboard (single file)
├── docs/WAITLIST_SYSTEM_DOCUMENTATION.md  this document
├── docs/ (other reference docs), README.md, HANDOFF.md, AGENTS.md, CUSTOMER_DATA_INTEGRATION.md
├── migration/ (snippet packages; sanitized/ tracked, raw *.json git-ignored)
└── .git/, .gitignore, .claude/
   NOTE (2026-07-10): dashboards moved from repo root → dashboard/{waitlist,jastip}/;
   see the ERRATA banner at the top of this document.

Not in the repo (live only inside WordPress/WPCode on Hostinger):
  WPCode snippet 13948  C1   Jedda Launch — Form & Token          (Active, HTML)
  WPCode snippet 13978  C2   Jedda — Early Access Waitlist Intro Popup
  WPCode snippet 13980  C3   Jedda — Private Product Gate (ENFORCE MODE)  (PHP)
  WPCode snippet 14010  C4   Jedda — Payment Hook v2               (PHP)
  WPCode snippet 14018  C5   Jedda — Cart Drawer Visual Fixes
  WPCode snippet 14034  C6   Jedda — Jastip Live Detector          (Active, PHP)
  WPCode snippet 14036  C7   Jedda — Jastip Backfill Utility        (PHP, run-once)
  WPCode snippet 14037  C8   Jedda — Jastip Email Backfill          (Inactive, PHP, spent)

  -- Added 2026-07-06: found via full WPCode listing (admin.php?page=wpcode),
  -- missed by the original inventory pass which only looked at snippets
  -- surfaced through in-context links, not the plugin's own "All Snippets" list.
  WPCode snippet 14024  C19  Jedda - Web Purchases Live Sync       (Active, PHP)
  WPCode snippet 14023  C20  Jedda - Web Purchases Backfill        (Active, PHP, run-once)
  WPCode snippet 14000  C21  Jedda — Full Cart Drawer on Add to Cart      (Active, JS)
  WPCode snippet 14001  C22  Jedda — Mobile Cart Menu Icon (depends on C21) (Active, JS)
  WPCode snippet 14003  C23  Jedda — Fix Theme JS Errors           (Active, PHP)
  WPCode snippet 13996  C24  Jedda — Show Purchase-Limit Notice Immediately (Active, JS)
  WPCode snippet 13994  C25  Jedda — Fix Stuck Add to Cart/Buy Now Hover   (Active, CSS)
  WPCode snippet 13982  C26  Jedda — Hide Page Title on Jacket/Early Access (Active, PHP)
  WPCode snippet 13981  C27  Jedda — Hide Reset Variations on Locked Product (Active, PHP)

Not in the repo (Supabase): all tables, RPCs, view, RLS policies, Edge Function.

**Full WPCode inventory check (2026-07-06):** the site has 47 snippets total
across 3 admin-list pages. Pages 2–3 (30 snippets) are all pre-existing store
snippets unrelated to the waitlist/jastip system (product badges, cart CSS,
older Agif-authored hooks, etc.) — confirmed by reading the full list, not
inferred. C19–C27 above are the complete remaining set of Jedda-prefixed
snippets that C1–C8 missed (C17/C18 are already used for the two dashboards,
see start of this list).
```

**Deployment mapping (both dashboards share one repo, deployed as two Vercel
projects with different Root Directory):**
- `dashboard-waitslist.vercel.app` → `dashboard/waitlist/index.html` (repoint Vercel Root Directory)
- `dashboard-jastip.vercel.app` → `dashboard/jastip/index.html` (repoint Vercel Root Directory)

---

## 12. WordPress Architecture

- **Host:** Hostinger. **Stack:** WordPress + WooCommerce + WPCode Lite.
- **All custom logic is WPCode snippets** (no theme/plugin files edited).
  Snippet types: HTML (front-end), PHP Snippet (server hooks). Snippets are
  toggled Active/Inactive and auto-inserted.
- **Customer pages:**
  - `/early-access/` renders C1 (form + verify UI in one HTML snippet; the UI
    branch shown depends on presence of a `?token=` param).
  - `/jacket-access/?token=…` is the invited-access entry (same snippet C1
    handles the verify/access states).
- **HPOS note:** the store uses WooCommerce High-Performance Order Storage; the
  admin orders screen is `admin.php?page=wc-orders`. Relevant to the backfill
  utility (C7) which pages orders via `wc_get_orders()`.
- **Known operational quirk:** the WPCode editor’s “Update/Save” is unreliable and
  frequently needs 2–4 retries with a fresh tab before an edit persists (observed
  repeatedly). Always re-open the snippet in a new tab and verify the saved
  content before trusting a save.

---

## 13. WooCommerce Integration

Three integration points, all via WPCode PHP snippets hooking WooCommerce:

1. **Order → jastip scanner (C6 / 14034).** Hooks the order-status transitions
   `woocommerce_order_status_processing`, `_completed`, `_refunded` (priority 20).
   For each order it reads billing phone, **billing email** (added this session),
   shipping address parts, and line items, then POSTs to
   `rpc/jastip_ingest_order`. It only sends orders whose line items match an
   active watched-product keyword (a 5-minute transient cache of keywords avoids
   re-querying Supabase per order).
2. **Order paid → token/waitlist consume (C4 / 14010).** On successful payment it
   marks the used launch token and flips the waitlist row to `purchased`. The “v2”
   in its name refers to a fix for session cross-contamination (a prior version
   could attribute a token to the wrong session/order). *(Exact internals not
   machine-readable; behaviour inferred from name + C1’s comment that consumption
   happens “oleh PHP hook (Payment Hook).”)*
3. **Private product access gate (C3 / 13980).** Intended to block visitors who
   reach a *private* launch product URL without a valid token. **As currently
   deployed it appears to run in shadow mode** — its functions are
   `jd_private_gate_shadow()` and `jd_shadow_log()`, and it writes decisions
   (`product_id, token, decision, reason`) to `launch_shadow_log` rather than
   hard-blocking. See §17.4 and §22.

Additionally, **`web_purchases`** mirrors real WooCommerce orders (order_id,
product, email, phone, status, date) so the Waitlist Dashboard can flag
already-purchased registrants. **Resolved 2026-07-06** (previously marked
Unknown — the sync snippets exist but were missed by the original inventory
pass, see §11):
4. **Live sync (C19 / 14024).** Hooks `woocommerce_order_status_processing`
   and `_completed`. On each transition it reads billing email/phone + line
   items from the order and upserts into `web_purchases`
   (`Prefer: resolution=merge-duplicates`). Fires once per order, going
   forward only — not a historical scan.
5. **One-time backfill (C20 / 14023).** Hooked on `admin_init`, gated by
   `current_user_can('manage_options')` and a `?jd_run_backfill=1` query
   param — so it only runs when a logged-in admin visits any wp-admin page
   with that param (safe from unauthenticated triggering). Pages through
   `wc_get_orders()` for orders with id ≥ 13275 in paid statuses and upserts
   them the same way as C19. Re-running is safe (upsert, not insert).

---

## 14. Supabase Architecture

Single project `misrdmmvukdpranxnqjq`. Extensions used include `pg_trgm`
(fuzzy address similarity). Two logical domains share the DB: `launch_*`
(waitlist) and `jastip_*` (reseller detection), plus `web_purchases`.

### 14.1 Waitlist tables

| Table | Purpose | Key columns |
|-------|---------|-------------|
| `launch_waitlist` | The registrants | `email` (NOT NULL), `phone` (NOT NULL, normalized 62…), `status` (`pending`/`invited`/`purchased`), `is_flagged`, `flag_reason`, `product/color/size/variation_id`, `ip_address`, `registered_at` |
| `launch_tokens` | Personal access links | `token` (12-char), `waitlist_id`, `email`, `phone`, `product/color/size/variation_id` (**private** variation), `expires_at`, `is_used`, `used_at`, `woo_order_id` |
| `launch_variations` | Product/color/size → Woo variation map | public `variation_id`, `private_variation_id`, `private_product_id`, `product_url`, `private_product_url`, `active` |
| `launch_allocations` | Planned stock per variation/batch | `batch_label`, `product/color/size`, `allocated` |
| `launch_allocation_summary` | View: allocated vs invited/purchased/remaining | (derived) |
| `launch_rate_limits` | Anti-abuse counters | `ip_address`, `token`, `attempt_at` |
| `launch_shadow_log` | Private-gate observe-mode log | `product_id`, `token`, `decision`, `reason` |

### 14.2 Jastip tables

| Table | Purpose | Key columns |
|-------|---------|-------------|
| `jastip_orders` | Every scanned order relevant to watched products | `order_no` (UNIQUE), `recipient_name`, `phone/phone_normalized`, **`email/email_normalized`**, `address_raw/address_normalized`, `products` (jsonb), `batch_id` |
| `jastip_flags` | Rule hits awaiting review | `order_id`, `rule_triggered`, `confidence` (`high`/`medium`/`low`), `detail`, `matched_suspect_id`, `matched_order_id`, `address_similarity_score`, `reviewed`, `review_action` |
| `jastip_suspects` | Confirmed/suspected resellers | `phone/phone_normalized`, **`email/email_normalized`**, `address_raw/address_normalized`, `known_names` (text[]), `known_products` (jsonb), `status` (`confirmed`/`suspected`/`watching`/`cleared`), `confidence`, `total_appearances`, `notes` |
| `jastip_watched_products` | Product-name keywords to watch | `keyword`, `active`, `notes` |
| `jastip_batches` | Backfill batch bookkeeping | `batch_name`, `total_orders`, `total_flags` |
| `jastip_address_links` | Manually-linked equivalent addresses | `address_a_normalized`, `address_b_normalized` (present; not exercised by current dashboard code) |

### 14.3 RPC functions (the real business logic; all `SECURITY DEFINER`)

- **`launch_submit_waitlist`** — two overloads:
  - `(email, phone, ip)` — generic waitlist (no product).
  - `(email, phone, product, color, size, ip)` — launch-specific; validates
    product∈{rhea,phi}, color∈{breen,auburn}, size∈{sm,lxl}, resolves
    `variation_id`, and additionally cleans up stale expired rows before dedupe.
  - Both: normalize phone, rate-limit ≤3/hr/IP, reject dup email/phone, **jastip
    check by phone OR email** against `jastip_suspects` (confirmed/suspected) and
    `jastip_orders`⋈`jastip_flags` (high/medium) → set `is_flagged`+`flag_reason`,
    then insert `status='pending'`. Never reveals flag status to the client.
- **`launch_get_phone_hint(token)`** — returns `left(phone,4)||'****'`.
- **`launch_verify_phone(token, last5)`** — validates 5-digit numeric input,
  rate-limits ≤5 failed/15min/token, rejects not-found/used/expired, compares
  `right(phone,5)`; on success returns product/color/size/variation_id; on
  mismatch records a rate-limit row. Does not consume the token.
- **`jastip_ingest_order(...)`** — the rule engine (see §14.5). Two overloads
  exist; the 10-arg one (with `p_email`) is current; the 9-arg one is legacy
  (**technical debt**, §23).
- **`jastip_confirm_suspect(phone, address, name, notes, status, confidence, email)`**
  — finds an existing suspect by exact phone OR exact email OR fuzzy address
  (`similarity ≥ 0.85`); if found, updates & appends name, bumps
  `total_appearances`, merges notes; else inserts. This is what prevents duplicate
  suspect rows.
- **`jd_normalize_phone(p)`** — strips non-digits, maps `0…`→`62…`, `8…`→`62 8…`,
  passes through `62…`. `IMMUTABLE`.
- **`jd_normalize_address(addr)`** — abbreviation-expansion/canonicalization (ported
  from a JS normalizer) enabling fuzzy matching.

### 14.4 View: `launch_waitlist_view`
Wraps `launch_waitlist` and adds:
- `already_purchased` / `purchased_order_id` / `_product_name` / `_order_date` via
  a LATERAL join to `web_purchases` matching **`lower(email)` OR normalized phone**.
- `effective_status`: if a row is `invited` but its latest token is expired and
  unused → `expired` (so lapsed invites resurface as actionable); else the raw
  status. Consumed by the Waitlist Dashboard for all stats and filters.

### 14.5 The jastip rule engine (`jastip_ingest_order`)
On each order:
1. Insert into `jastip_orders` idempotently (`ON CONFLICT (order_no) DO NOTHING`).
   If already processed, returns early (`already_processed: true`).
2. Compute the set of active watched keywords present in this order’s products.
3. **Rule 1 — DB match** (not gated by watched product): if phone OR email OR
   address(≥0.85) matches a non-cleared `jastip_suspects` row → `high`.
4. **Rule 2 — bulk same product**: for any single watched keyword, qty ≥ 2 in this
   one order → `high`. (Buying two *different* watched products, 1 each, does **not**
   trigger — an explicit product decision.)
5. **Rule 4 — cross-order**: versus every other order that shares ≥1 *identical*
   watched keyword: if same phone/email → `high`; else if address similarity
   ≥0.95 → `high`, 0.85–0.94 → `medium`. **Confidence reflects match strength, not
   order age** (a fix made this session — previously age-based, which was wrong).
6. Insert resulting `jastip_flags`.

(There is no “Rule 3” in the current set; historically `rule_3_multi_watched` and
`rule_2_batch_dupe` existed and were retired as flawed — the dashboard hides those
legacy flags.)

### 14.6 Row-Level Security (RLS)
All `launch_*` and `jastip_*` tables and `web_purchases` have RLS **enabled**.
Policy shape:
- **`jastip_*` tables:** a single `anon_all` policy — `ALL` for role `anon`,
  `USING true / WITH CHECK true`. **The jastip data is fully readable/writable with
  the anon key.** (This is why the Jastip Dashboard needs no login — see §17.)
- **`launch_waitlist`:** anon may `INSERT` (form) and may `SELECT`; anon may
  `UPDATE` **only** to set `status='purchased'` (`WITH CHECK status='purchased'`,
  used by the payment path); full update is `authenticated`-only.
- **`launch_tokens`:** anon may `SELECT` (to read a token by value) and may
  `UPDATE` **only** to set `is_used=true`; insert/delete/general-update are
  `authenticated`-only.
- **`launch_variations`:** `SELECT` for anon + authenticated.
- **`launch_rate_limits` / `launch_shadow_log`:** anon `INSERT` (+ anon `SELECT` on
  rate limits) so the front-end/gate can record attempts.
- **`launch_allocations`:** `authenticated`-only (admin planning).
- **`web_purchases`:** `SELECT` for `public`.

The intent: the public front-end (anon key) can *register*, *read a token*, *mark a
purchase*, and *log attempts*, but cannot read or tamper with the invite pipeline;
only the admin (authenticated session) can invite, mint tokens, or edit registrants.

---

## 15. Email Flow

- **Trigger:** admin invites (batch `sendEmails` `:1794`, or single/re-send in
  `tokenAction` `:1722`).
- **Transport:** browser → Supabase Edge Function `send-launch-email` → Resend.
  Routed through the Edge Function specifically because Resend’s API rejects
  direct browser calls (CORS) and to keep the Resend key server-side (comment
  `:1816`; note `:827`: *“RESEND_KEY tidak disimpan di browser lagi”*).
- **Content:** `buildLaunchEmailHtml` (`:894`) — inline-styled table email, serif
  brand mark, first-name guess from email local-part, product/color/size, a single
  black CTA to `/jacket-access/?token=…`, and sharing/expiry warnings.
- **Batch pacing:** 200 ms between sends (`:1831`). Success/fail tallied per send.
- **Subject:** *“Your access is now open.”*

## 16. Invitation & Token Flow (State)

**`launch_tokens.is_used` + `launch_waitlist.status` + `launch_tokens.expires_at`**
jointly define invite state.

```
 waitlist.status:  pending ──invite──▶ invited ──purchase──▶ purchased
                      ▲                   │
                      │                   └─(token expires, unused)─▶ effective_status = expired
                      │                                                        │
                      └──────────────── re-invite / re-register ◀─────────────┘

 token lifecycle:  (none) ──generate──▶ active ──verify(ok)──▶ (still active, NOT consumed)
                                          │                          │
                                          │                          └─purchase──▶ is_used=true
                                          └─expires_at passes──▶ expired
```

- A token is **minted** by the admin carrying the **private** `variation_id`.
- Verification (`launch_verify_phone`) does **not** consume it.
- **Consumption** (`is_used=true`, `used_at`, `woo_order_id`) happens only on a
  paid order via the Payment Hook (C4).
- “Generate token baru” deletes the old token, re-resolves the private variation,
  inserts fresh, resets the row to `invited`, re-emails. Used tokens are locked in
  production.

---

## 17. Security Model

### 17.1 Two distinct trust levels
- **Waitlist Dashboard** — **real Supabase Auth** (email+password → JWT). Writes to
  the invite pipeline require the `authenticated` role, enforced by RLS. This is
  the strong boundary: only a logged-in admin can invite, mint tokens, edit
  registrants, or manage allocations.
- **Jastip Dashboard** — **no login; uses the anon key**, because all `jastip_*`
  tables have a permissive `anon_all` RLS policy. Anyone with the dashboard URL (or
  the anon key) can read and modify jastip data. This is a deliberate simplicity
  trade-off but is a real exposure (§22, §23).

### 17.2 Anti-abuse
- **IP rate limit** at registration: ≤3 submissions/IP/hour (`launch_rate_limits`).
- **Token verify rate limit:** ≤5 failed attempts/token/15 min.
- **Identity check:** last-5-digits-of-phone gate on invited access.
- **Duplicate prevention:** unique-ish handling of email & phone at submit.

### 17.3 Secrets
- The **anon key** is embedded in both dashboards and the front-end snippet (by
  design — it’s a public key constrained by RLS).
- The **Resend key** is server-side only (Edge Function), never in the browser.
- Admin credentials are real Supabase Auth users (not shared/hardcoded).

### 17.4 The private-product gate is (apparently) not enforcing
The snippet is titled **“ENFORCE MODE”** but its live code defines
`jd_private_gate_shadow()` writing to `launch_shadow_log` — i.e. it **observes and
logs** access decisions rather than blocking. If accurate, a *direct* URL to a
private product/variation may not be hard-blocked server-side; access control
currently leans on private URLs being unguessable + the token/verify funnel + this
shadow log for monitoring. **The exact enforce-vs-observe status could not be fully
verified** (browser content filter blocked reading the full body). **This must be
confirmed by the auditor** — it is the single most important open security
question. See §22/§23.

### 17.5 Privacy
- Jastip flag status is **never** revealed to the customer.
- Phone hint only exposes first-4 + last-5-via-challenge, not the full number.

---

## 18. Validation & Business Rules

**Validation (server-enforced in RPCs):**
- Phone normalized to `62…`; must yield digits.
- Launch product/color/size restricted to the allowed enums (rhea/phi,
  breen/auburn, sm/lxl); variation must exist & be active.
- Verify input: exactly 5 numeric digits.
- Duplicate email and duplicate phone rejected at registration.

**Business rules:**
- One registration per email and per phone (after stale-row cleanup).
- Invites are admin-curated; nobody is auto-invited.
- A token is single-use and time-limited; consumed only on purchase.
- Tokens carry the **private** variation id (mismatch would block a legitimate
  buyer at the gate).
- Jastip flag = review signal, never an auto-reject and never customer-visible.
- Jastip Rule 2 needs qty≥2 of the **same** watched product; two different watched
  products don’t trigger.
- Jastip cross-order rule requires the **same specific** watched product across
  orders plus a matching identity signal (phone/email/address).
- Confidence encodes **evidence strength** (exact phone/email or ≥95% address =
  high; 85–94% address = medium), not recency.
- Suspect confirmation **merges** by identity; it must not create duplicates.

---

## 19. Visual Language, UI & UX Philosophy, Copywriting

**Visual language (customer-facing, WPCode 13948 + email):**
- Quiet-luxury editorial: serif display (Georgia/Cormorant-style) for headlines,
  clean sans for body; generous whitespace; a single near-black CTA; muted greys;
  uppercase letter-spaced “JEDDA” eyebrow.
- Minimal fields, one clear action, calm success state.

**Admin dashboards (design principles):**
- Built from scratch for readability (explicit instruction: do **not** port the
  old tool’s look). Jost font, paper/ink palette, card-and-table hybrid,
  colour-coded confidence/status badges (high=red, medium=orange, low=yellow;
  ok=green; bought=orange accent; jastip=red accent).
- **One badge per row, never stacked** — the most severe state wins (jastip over
  already-purchased). Rationale: an operator should get a single unambiguous “what
  do I do with this row” signal.
- Jastip Flags favour **identity clustering + summarized reasons** over raw pairwise
  match lists, because an O(n²) list of “order A matches order B” is noise; the
  operator’s real question is “is this one person, and why.”
- Per-order detail (name/phone/email/address) is shown **only when it differs**
  within a cluster, so mixed-identity resellers are legible without clutter.

**UX philosophy:**
- Customer: never punished or shamed; never told they’re flagged; never rushed.
- Admin: destructive/irreversible actions gated (used-token lock in production;
  confirm dialogs); staging vs production explicitly separated to prevent
  accidentally emailing real customers during tests.

**Copywriting principles:**
- Customer copy is warm, restrained, gratitude-first (“Thank you for your
  patience”), emphasizes exclusivity and personal responsibility (don’t share the
  link) without threat.
- Admin copy is plain Indonesian, task-oriented, and explains *why* in code
  comments for the next maintainer.

---

## 20. State Diagrams

**Waitlist row (`launch_waitlist.status` + `effective_status`):**
```
 pending ──(admin invite)──▶ invited ──(paid order, hook C4)──▶ purchased
    ▲                           │
    │                           └─(latest token expired & unused)──▶ effective_status=expired
    └───────────(re-register after stale cleanup, or admin re-invite)──────────┘
```

**Jastip flag → suspect:**
```
 order ingested ─▶ rule hit ─▶ jastip_flags(reviewed=false)
                                   │
        ┌──────────────────────────┼───────────────────────────┐
        ▼(Clear)                    ▼(Confirm)                   ▼(no action)
 reviewed=true,              jastip_confirm_suspect →      stays in queue
 review_action=cleared       merge/insert jastip_suspects
                             (future orders from this
                              identity auto-flag via Rule 1
                              and auto-flag at waitlist signup)
```

**Token:**
```
 active ──verify ok──▶ active(unconsumed) ──purchase──▶ used
   └──expires_at passes──▶ expired ──(admin “generate baru”)──▶ new active
```

---

## 21. Sequence Diagrams

**Registration + jastip check**
```
Browser         WordPress(13948)      Supabase RPC                DB
  │  submit ───────▶│                    │                        │
  │                 │ POST launch_submit_waitlist ───────────────▶│
  │                 │                    │ normalize/ratelimit    │
  │                 │                    │ dedupe email/phone     │
  │                 │                    │ SELECT jastip_suspects (phone|email)
  │                 │                    │ SELECT jastip_orders⋈flags (phone|email)
  │                 │                    │ INSERT launch_waitlist(is_flagged?) ─▶│
  │  generic ok ◀───│◀── {success} ──────│                        │
```

**Invite → access → purchase**
```
Admin DB            Supabase            Edge Fn        Customer         WP hooks
  │ generate tokens ─▶ INSERT launch_tokens            │                 │
  │ send ───────────────────────────▶ Resend ─email──▶│                 │
  │                                                     │ /jacket-access │
  │                                    get_phone_hint ◀─│                 │
  │                                    verify_phone   ◀─│ last5           │
  │                                    (ok: variation) ─▶│ reveal product │
  │                                                     │ checkout ──────▶│ Payment Hook 14010
  │                                                     │                 │ token.is_used=true
  │                                                     │                 │ waitlist=purchased
```

---

## 22. Edge Cases & Known Limitations

- **Private-gate enforcement uncertain (critical).** Titled ENFORCE, appears to
  shadow-log only. If not enforcing, private product URLs rely on
  unguessability + the verify funnel, not a hard server block.
- **Jastip data is world-writable via anon key.** `anon_all` on all `jastip_*`
  tables + a no-login dashboard means the reseller database has no access control
  beyond URL/key obscurity.
- **Allocations are advisory.** Nothing prevents inviting/selling beyond
  `allocated`; the summary is informational. No hard stock lock between token
  minting and checkout — two invited customers could race for the last unit.
- **`web_purchases` sync source is Unknown.** If it lags or stops, “already
  purchased” detection and the view’s LATERAL join silently degrade.
- **Two RPC overloads each** for `launch_submit_waitlist` and `jastip_ingest_order`.
  The legacy `jastip_ingest_order` 9-arg (no email) still exists; PostgREST picks
  by argument shape, so callers must pass the email arg to hit the new logic.
- **First-name in emails is a guess** from the email local-part (e.g.
  `z.rianda@…` → “Z”), which can produce awkward greetings.
- **Identity clustering is heuristic.** Union-find over exact phone/email + fuzzy
  address (≥0.85) can, in principle, over-merge (shared household/dropship address)
  or under-merge (typo below threshold, no shared signal). `jastip_address_links`
  exists to hand-link addresses but isn’t wired into the current dashboard.
- **WPCode save flakiness** can silently drop a server-logic change; every snippet
  edit must be re-verified in a fresh tab.
- **Waitlist Dashboard token table caps at 200** and CSV export at 500 unused
  tokens; large launches would need pagination.
- **Backfill email** only covered the ~105 orders currently under review; older
  jastip_orders remain email-less (acceptable per product decision).

## 23. Technical Debt

1. **Confirm/lock down jastip RLS** or move the Jastip Dashboard behind Supabase
   Auth like the waitlist one. Current `anon_all` is the biggest security gap.
2. **Resolve the ENFORCE-vs-shadow gate.** Either finish enforcement in 13980 or
   rename it to reflect reality; document the intended access-control guarantee.
3. **Drop the legacy `jastip_ingest_order(9-arg)`** overload to avoid ambiguity.
4. **Single-file dashboards** (1993 + 821 lines of inline HTML/CSS/JS) — no build,
   no modules, no tests. Fine for now; will resist safe change as they grow.
5. **Hardcoded label maps** (rhea/phi, breen/auburn, sm/lxl) duplicated across the
   dashboard and RPCs; adding a product means editing multiple places.
6. **Locate & document the `web_purchases` sync** so it isn’t a silent dependency.
7. **WPCode reliability** — consider moving critical PHP hooks into a small
   must-use plugin for reliable deploys and version control.

## 24. Future Improvements

- Admin auth + audit log for the Jastip Dashboard.
- Hard inventory reservation at token-mint time to prevent overselling.
- A customer self-service “you’re on the list” status page.
- Configurable products/variations from `launch_variations` instead of hardcoded
  enums.
- Address-graph review UI leveraging `jastip_address_links`.
- Better first-name handling (collect a name field, or omit the greeting).
- Automated tests around the RPCs (they hold all real logic and are the highest-
  value place to test).

---

## 25. Design Decisions & Rationale

| Decision | Rationale |
|----------|-----------|
| Invitation-only, admin-batched launch | Throttle demand, defeat instant bot-buys, manufacture calm exclusivity |
| Business logic in `SECURITY DEFINER` RPCs, not the client | Single trusted enforcement point; anon clients can’t bypass rules via direct table writes (RLS is restrictive) |
| Flag jastip **silently** at signup | Don’t tip off resellers; keep it an operator decision, avoid false-accusation UX |
| Confidence = match strength, not recency | An identical phone/email is strong evidence regardless of when the prior order happened |
| Cluster flags by identity (union-find over phone/email/address) | One reseller = one card; O(n²) pairwise lists are unreadable noise |
| Merge-on-confirm (`jastip_confirm_suspect`) | Prevent duplicate suspect rows when the same person is confirmed twice |
| Rule 2 requires **same** product qty≥2, not two different watched products | Buying two different limited items once is normal shopping, not jastip |
| Tokens carry **private** variation id | The product gate matches on private variation; using the public id would block legitimate buyers |
| Email via Edge Function, not browser→Resend | Resend blocks browser CORS; keeps the Resend key off the client |
| Real Supabase Auth for the waitlist admin (vs hardcoded password) | Proper rev'able credentials; RLS `authenticated` role enforcement |
| Staging/Production env toggle in the dashboard | Prevent emailing real customers real links during testing |
| Lock used tokens in production | A consumed slot must not be silently reset and re-sold |
| Two separate dashboards sharing one repo | Clear separation of operator concerns; independent Vercel deploys; low overhead |
| Verify by last-5-phone-digits (not a password) | Frictionless identity proof the customer already knows, resistant to link-sharing |
| Don’t consume token on verify, only on purchase | Let a customer re-open their link and complete checkout without “burning” access prematurely |

---

## 26. Assumptions

- WooCommerce order billing captures a usable phone and email for jastip matching.
- Private product/variation URLs are non-enumerable enough to matter while the
  gate is in shadow mode.
- Resend and the Edge Function are correctly configured with a verified sender.
- The single Supabase project is the sole source of truth for both domains.
- Admins operate from trusted machines (the Jastip Dashboard’s open access assumes
  the URL/key isn’t shared publicly).

## 27. Dependencies & External Services

- **WordPress + WooCommerce** (Hostinger) — customer UI + commerce + server hooks.
- **WPCode Lite** — snippet host for all custom WP logic.
- **Supabase** (`misrdmmvukdpranxnqjq`) — Postgres, RPC, RLS, Auth, one Edge
  Function; `pg_trgm` extension.
- **Resend** — transactional email (via the Edge Function).
- **Vercel** — hosts both static dashboards (two projects, one repo).
- **Midtrans** — payment (native WooCommerce checkout; sandbox simulator observed).
- **GitHub** (`dibuatpekat-eng/dashboard-waitslist`) — source for the dashboards.

---

## 28. Open Questions For The Auditor (things marked Unknown)

1. **Is WPCode 13980 actually enforcing** private-product access, or only
   shadow-logging to `launch_shadow_log`? Re-checked 2026-07-06: confirmed the
   live functions are `jd_private_gate_shadow()` / `jd_shadow_log()`, and the
   first 20 lines (all that could be read — see §17.4) only build a
   `$private_ids` list and POST decisions to `launch_shadow_log`; no
   `wp_die`/`wp_redirect`/`wp_safe_redirect` call was visible in that portion.
   **Still not 100% confirmed past line 20 — a browser content filter blocks
   reading the rest of this specific snippet's body (reproduced twice, 2026-07
   and again 2026-07-06). The auditor should open snippet 13980 directly in
   WPCode and read past line 20 manually.**
2. ~~What populates `web_purchases`?~~ **Resolved 2026-07-06** — see §13,
   snippets C19 (14024, live sync) and C20 (14023, one-time backfill).
3. **Exact internals of the Payment Hook (14010)** and what “session
   cross-contamination” bug it fixed.
4. **Intro popup (13978) and Cart Drawer (14018)** exact behaviour — treated as
   cosmetic/marketing; not fully read.
5. **Any scheduled jobs / crons** (e.g. token/rate-limit cleanup) beyond the
   `launch_submit_waitlist` inline stale-row cleanup — none found, but not
   exhaustively ruled out. Confirmed no `pg_cron` extension is installed on
   the Supabase project (checked 2026-07-06), so any such job would have to
   live in WordPress (wp-cron) — not yet checked.
6. **C19–C27 (§11) were only discovered 2026-07-06** via the full WPCode
   "All Snippets" list (`admin.php?page=wpcode`) — the original inventory
   pass (C1–C8) only saw snippets reachable through in-context links and
   missed these. **The completed shopping-journey audits (tasks #1/#2) ran
   before this discovery and did not account for C21–C27 (cart drawer,
   purchase-limit notice, hover-fix, hide-title, anti-flicker reset
   variations)** — those audits may be incomplete with respect to this UI
   layer. Worth a targeted re-check, especially C24 (purchase-limit notice
   polling logic) since it touches the same "blocked add to cart" path the
   anti-jastip system cares about.

*End of document.*
