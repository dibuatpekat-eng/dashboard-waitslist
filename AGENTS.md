# AGENTS.md

Conventions and guardrails for AI agents (and humans) working in this repository.
This is a **live production system** for a real brand — bias toward caution.

## What this repo is / is not

- **Is:** the existing JEDDA Waitlist + Anti-Jastip system and its documentation,
  serving as an **integration reference**.
- **Is not:** the central customer database or POS. **Do not design or implement the
  POS/central customer DB in this repo** unless explicitly asked. Capture integration
  facts in `CUSTOMER_DATA_INTEGRATION.md` instead.

## Golden rules

1. **Secrets never enter Git or chat output.**
   - `migration/*.json` embed the Supabase **anon** key *and* a **service_role** key
     (full RLS bypass). They are git-ignored. Do not print keys; when you must
     reference one, describe it (role/where), never paste it.
   - Before any commit/push, scan for `eyJ…`-shaped JWTs. See README → Secrets.
2. **Never widen access.** Do not loosen RLS, expose the anon/service keys further, or
   move privileged logic to the client. Prefer *closing* the open policies (C1/C3).
3. **This is production.** For anything hard to reverse or outward-facing (prod snippet
   edits, mass DB writes, emails to real customers, schema/RLS changes), confirm intent
   first. Mass/bulk writes to production data should be reviewable before running.
4. **WooCommerce is authoritative** for orders/spend/status. Supabase `web_purchases`
   and `jastip_orders` are partial, sometimes-stale mirrors — reconcile, don't trust.
5. **Use the canonical normalizers** `jd_normalize_phone` / `jd_normalize_email` for any
   identity matching. Do not hand-roll phone/email normalization.
6. **Shared Supabase:** staging and production share project `misrdmmvukdpranxnqjq`.
   RPC/RLS/schema changes hit both environments. WPCode snippet code is per-environment.

## Working with the pieces

- **Supabase:** query it directly (project `misrdmmvukdpranxnqjq`) for ground truth;
  don't trust doc row counts blindly. Business logic lives in `SECURITY DEFINER` RPCs,
  not the client.
- **WordPress/WPCode:** all custom WP logic is WPCode snippets (no theme edits). The
  editor has **two "Update" buttons** — the real save is
  `button.wpcode-button[type=submit]`, not the notice/nag button. Re-open in a fresh tab
  and verify the saved content; WPCode can silently drop a save.
- **Dashboards:** live in **this** repo under `dashboard/waitlist/index.html` and
  `dashboard/jastip/index.html` (single-file HTML apps). `origin` remains
  `dibuatpekat-eng/dashboard-waitslist`; Vercel deploys from those subpaths. They embed
  the anon key — never add the service_role key to a dashboard.
- **Browser automation caveat:** a content-exfiltration filter blocks tool output
  containing token/JWT/query-string patterns — read snippet bodies in slices that avoid
  those regions.

## Evidence discipline

- Mark every non-trivial claim as **confirmed** (verified against live DB/code, with how)
  or **inferred** (from names/comments/behavior). Keep the `✅ CONFIRMED / ⚠️ INFERRED /
  ❓ FOUNDER` convention used in `CUSTOMER_DATA_INTEGRATION.md`.
- Flag business/product choices as **`❓ FOUNDER`** rather than deciding them.

## Documentation conventions

- **Architecture / how it works** → `docs/WAITLIST_SYSTEM_DOCUMENTATION.md`.
- **Customer-data integration facts** → `CUSTOMER_DATA_INTEGRATION.md`.
- **Status, access, open tasks, gotchas** → `HANDOFF.md`.
- **Security** → `docs/AUDIT_REPORT_FINAL.md`, `docs/JEDDA_OPS_SECURITY_RECOMMENDATION.md`.
- The master doc is a **point-in-time** record (2026‑07‑06) with an errata banner; add new
  material as errata or in the newer docs rather than silently rewriting its body.
- Convert relative dates to absolute (YYYY‑MM‑DD). Prefer tables and explicit column names.

## Style

- Match the surrounding code/prose. Indonesian is fine for operator-facing copy; keep
  developer docs in English unless a file is already Indonesian.
- Keep snippet identifiers precise (prod vs staging IDs differ — see
  `migration/ID-MAPPING-staging-to-prod.md` and `HANDOFF.md` §3).

## Definition of done for changes here

- Secrets clean, docs updated to match reality, claims marked confirmed/inferred,
  production changes verified (fresh-tab re-read for WPCode; re-query for DB), and any
  founder decision explicitly surfaced rather than assumed.
