# JEDDA — Waitlist & Anti-Jastip System

Invitation-only product-launch **waitlist** + reseller ("jastip") **detection** for
[jeddawear.com](https://jeddawear.com), built on WordPress/WooCommerce + Supabase,
with two static admin dashboards.

This repository is the **system + documentation**, prepared as an **integration
reference** for building JEDDA Atelier's central customer database and POS. It does
**not** contain the POS/central-customer-DB design — see `CUSTOMER_DATA_INTEGRATION.md`
for how to integrate with what already exists.

---

## Start here

| If you want to… | Read |
|---|---|
| Understand the system end-to-end | `docs/WAITLIST_SYSTEM_DOCUMENTATION.md` (deep reference) |
| Get oriented for handoff (status, access, snippet IDs, gotchas) | **`HANDOFF.md`** |
| Build the central customer DB / POS | **`CUSTOMER_DATA_INTEGRATION.md`** |
| Work in this repo as an AI agent | **`AGENTS.md`** |
| Migrate staging → production | `docs/PRODUCTION_MIGRATION.md` + `migration/ID-MAPPING-staging-to-prod.md` |
| Review security posture | `docs/AUDIT_REPORT_FINAL.md`, `docs/JEDDA_OPS_SECURITY_RECOMMENDATION.md` |
| Fix the jastip status backfill | `docs/HANDOFF-jastip-status-fix.md` |

---

## Architecture (one paragraph)

Customer-facing UI and all server enforcement run as **WPCode snippets** inside
WordPress/WooCommerce (Hostinger). The single source of truth is a **Supabase**
Postgres project (`misrdmmvukdpranxnqjq`, `jedda-atelier`) with `SECURITY DEFINER`
RPCs holding the business logic, Row-Level Security, and one Edge Function
(`send-launch-email` → Resend). Two **static single-file dashboards** (on Vercel,
source under `dashboard/` in this repo) provide the waitlist and jastip operator UIs.
Payments are Midtrans via native WooCommerce checkout. Staging (`beta2.jeddawear.com`)
and production (`jeddawear.com`) **share the same Supabase project**.

```
Customer ─▶ WordPress/WooCommerce (WPCode snippets) ─RPC▶ Supabase (tables, RPCs, RLS, Edge Fn)
                                                              ▲                    ▲
                                                   Waitlist Dashboard      Jastip Dashboard  (Vercel)
```

---

## Repository layout

```
.
├── README.md                        ← you are here (entry point + index)
├── HANDOFF.md                       ← handoff guide (status, access, gotchas)
├── AGENTS.md                        ← conventions & guardrails for AI/dev agents
├── CUSTOMER_DATA_INTEGRATION.md     ← customer-data map for the future central DB/POS
├── .gitignore
├── docs/                            ← reference documentation
│   ├── WAITLIST_SYSTEM_DOCUMENTATION.md   (deep system reference; has 2026-07-10 errata banner)
│   ├── AUDIT_REPORT_FINAL.md              (security audit)
│   ├── JEDDA_OPS_SECURITY_RECOMMENDATION.md
│   ├── PRODUCTION_MIGRATION.md            (staging → prod)
│   └── HANDOFF-jastip-status-fix.md       (open task: jastip status resync)
├── dashboard/                       ← both admin dashboards (part of THIS repo)
│   ├── waitlist/index.html          (Waitlist Dashboard)
│   └── jastip/index.html            (Jastip Dashboard)
└── migration/                       ← WordPress snippet packages & new snippets
    ├── NEW-snippet-stock-button-state.php     (tracked; no secrets)
    ├── NEW-snippet-cart-lock.js               (tracked; no secrets)
    ├── NEW-snippet-buynow-checkout.php        (tracked; no secrets)
    ├── NEW-snippet-waitlist-badge.css         (tracked; no secrets)
    ├── ID-MAPPING-staging-to-prod.md          (tracked)
    ├── sanitized/                             (tracked — keys → placeholders)
    │   ├── jedda-1-system.json
    │   ├── jedda-3-backfills-RUN-ONCE-THEN-DELETE.json
    │   └── jedda-ALL-21.json
    └── jedda-*.json                 ⚠️ SECRETS (anon + service_role) — GIT-IGNORED (raw)
```

> This repository **is** the existing `dibuatpekat-eng/dashboard-waitslist` GitHub
> repo (history + remote preserved); the dashboards moved from the repo root into
> `dashboard/`. Vercel "Root Directory" for each project must be repointed to
> `dashboard/waitlist` and `dashboard/jastip` respectively.

---

## Secrets & what must stay out of Git

**Never commit these** (already handled by `.gitignore`):

| Secret | Where it appears | Risk |
|---|---|---|
| **Supabase `service_role` key** | `migration/jedda-1-system.json`, `migration/jedda-ALL-21.json` | 🔴 Full RLS bypass — read/write/delete the entire `jedda-atelier` DB |
| **Supabase anon key** | raw `migration/*.json`, `dashboard/*/index.html` | Powerful given open jastip/`web_purchases` RLS |
| **Resend API key** | Supabase Edge Function only (not in repo) | Email-send abuse |
| Local settings | `.claude/settings.local.json` | Machine-specific |

**Tracking policy (enforced by `.gitignore`):**
- ✅ **Track:** all `.md` docs, `dashboard/**`, `migration/NEW-snippet-*.{php,js,css}`,
  `migration/ID-MAPPING-staging-to-prod.md`, **`migration/sanitized/*.json`** (placeholder
  keys), `.gitignore`.
- 🚫 **Do not track:** raw `migration/*.json` (embed real keys), `.DS_Store`,
  `.claude/settings.local.json`.
- 🔁 **Snippet packages are preserved safely** as sanitized copies under
  `migration/sanitized/` — real keys replaced with `<SUPABASE_ANON_KEY>` /
  `<SUPABASE_SERVICE_ROLE_KEY>`. The raw exports stay local-only.
- Before any push: re-scan for `eyJ…`-shaped JWTs and rotate the keys if they ever
  landed in history.

> Nothing has been committed or pushed yet — awaiting your approval. `origin` still
> points at `github.com/dibuatpekat-eng/dashboard-waitslist`.

---

## Environments

| | Production | Staging |
|---|---|---|
| Site | jeddawear.com | beta2.jeddawear.com |
| WPCode snippet IDs | 14009–14029 (see HANDOFF §3) | 13xxx (see ID-MAPPING) |
| Supabase | **shared** `misrdmmvukdpranxnqjq` | **shared** (same project) |

---

## Known open items (see HANDOFF.md §4)

- 🔴 Security: anon-key exposure (C1) + world-open jastip/`web_purchases` RLS (C3).
- ⏳ Jastip/`web_purchases` stale `processing` statuses (spend/tier accuracy).
- ⏳ Go-live product-ID remap + `WAITLIST_LIVE` flag.
