# Roadmap

Forward-looking plan for the NDASA Donation Platform. This file reflects what the codebase actually implements today and what comes next. Items under "Next" and "Later" are candidates, not commitments — each needs its own planning, review, and release cycle.

## Current State

**Donor flow**
- One-time / monthly / yearly frequency toggle (default one-time)
- Preset amounts ($25/$50/$100/$250/$500) plus free-form "Other"
- Clickable impact cards that select an amount and scroll the frequency block into view
- Live fee-cover gross-up; default opt-in
- Optional dedication field (stored in Stripe metadata + local column)
- Newsletter opt-in checkbox (pre-checked; stored per-donation)
- Mobile-first layout: impact cards render immediately after the hero, above the amount picker, on small viewports; desktop uses the same DOM order
- Rotating donor-header slogans with three animation modes; respects `prefers-reduced-motion`
- Subpath-aware routing

**Payments**
- Stripe Checkout (hosted) in payment mode for one-time, subscription mode for monthly/yearly
- API pinned to `2026-03-25.dahlia`, `payment_method_types: ['card']`
- Fee-cover gross-up baked into subscription price at signup
- Webhook signature verified against BOTH live and test secrets so mode flips don't strand retries
- Two-phase idempotency: handler-runs-before-mark; downstream writes use `INSERT OR IGNORE`
- Webhook events handled: `checkout.session.completed`, `checkout.session.async_payment_{succeeded,failed}`, `charge.refunded`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`
- First-invoice dedupe vs the signup `checkout.session.completed`; recurring charges keyed by `inv_<invoice_id>`
- Stripe Customer Portal link on the success page for recurring-donor self-serve cancel / payment-method update
- Every donation tagged with `livemode` (1 live / 0 test) from the verified event; dashboard, CSV export, and detail views filter by the admin's active mode

**Success page**
- Truthful `paid` / `unpaid` (async) / `unknown` states
- Animated gratitude heart on confirmed paid
- Subscription-aware "Manage or cancel" section
- Share buttons (X, Facebook, LinkedIn, email) with prefilled copy
- Generic "check with your HR department" employer-matching note (no external lookup link)

**Admin panel** (HTTP Basic Auth)
- **Fundraiser dashboard** at `/admin` — reporting only. Four scalar stats plus a **pulse** row: Last Webhook heartbeat split by mode (live + test), Active Recurring commitment ($/mo, yearly normalized, cancelled subs excluded), 30-day donations sparkline, 30-day refund rate.
- **Repeat-donors panel** rendered below the pulse when any donor has more than one paid gift.
- **Recent Donations** (10 rows) with per-row detail link.
- **Transactions index** at `/admin/transactions` with email search, status filter, date range, 25/50/100/500 page-size dropdown; links to per-donation detail.
- **Subscriptions index + detail** at `/admin/subscriptions{/<sub_id>}`. Detail page hits Stripe for authoritative live status (current period, cancel flags) and lists every invoice row tagged with that subscription.
- **Donors index + detail** at `/admin/donors{/<sha256(email)>}`. Index is one row per lowercased email, ordered by lifetime giving. Detail shows identity, opt-in state, every donation, any subscriptions, and per-donation Stripe hosted-receipt URLs. Donor URLs are SHA-256 hashes so emails never land in access logs.
- Donation detail page at `/admin/donations/{order_id}` with mode-aware deep links into Stripe (PaymentIntent + Subscription).
- CSV export at `/admin/export` with optional date range; filename embeds `-live-` or `-test-` slug; includes interval, subscription id, dedication, newsletter opt-in.
- **Diagnostics** at `/admin/diagnostics` — read-only tile grid for App / Stripe API (account + balance + webhook endpoint, live + test) / Stripe keys (presence + format, no values) / Webhook heartbeat / PHP / Database / Filesystem / Logs / Env vars. Hosts the Stripe mode toggle and the append-only admin audit log.
- Runtime Stripe live/test mode toggle persisted in `app_config`; flips on the next request with no reload.
- Version resolver: `APP_VERSION` → short git hash → hardcoded fallback.
- `.env` is SSH-only — no web-editable config surface.

**Security**
- PCI-DSS SAQ-A (card entry on `checkout.stripe.com`)
- Strict CSP with per-request nonce; HSTS; `X-Frame-Options: DENY`
- Session ID regenerated on CSRF rotation
- Hardened Basic Auth parser for FastCGI / LiteSpeed
- Trusted-proxy XFF resolution (CIDR-aware)
- IP-keyed rate limit on `/checkout` (5 req / 60 s)
- Content-Type sniff on `/webhook` short-circuits non-JSON probes
- Log-line injection defence: CR/LF stripped from untrusted interpolations
- Amount validator rejects scientific notation, locale separators, non-numeric input
- Webhook content never persists PANs; prepared statements only

**Infrastructure**
- SQLite database, WAL journal; schema auto-migrates on connection; 3.7.17-safe (no `UPSERT`, `RETURNING`, window functions, `RENAME COLUMN`, or `DROP COLUMN`)
- Indexes: `idx_donations_created_at`, `idx_donations_status`, `idx_donations_subscription`, `idx_donations_livemode_created_at`, `idx_donations_livemode_status`, `idx_page_views_created_at`, `idx_admin_audit_created_at`
- Nexcess managed-WordPress deploy kit (`install.sh`, Apache shims, PHP shims)
- `install.sh` preserves `.env` and `storage/` across reinstalls; backups written to `~/backups/ndasa-donation/` (not the webroot); staged safety copy survives mid-install failure
- `deploy/prune-backups.sh` with dry-run default, per-series retention, `--older-than` filter, and hard regex/parent-dir guard on `rm`
- No application-side mail: donor receipts and internal alerts both come from Stripe
- `bin/stripe-import.php` back-fills the local ledger from the Stripe API (idempotent, mode-scoped, date-windowable, dry-runnable)
- Read-only `deploy/diagnose.sh` for on-host triage
- `bin/check-env-sync.php` verifies `.env.example` and `deploy/.env.template` declare the same keys
- CI (`.github/workflows/ci.yml`): `php -l`, env-sync, PHPUnit
- ~130 PHPUnit tests covering the webhook dispatch path, the event store, the Metrics query surface (including livemode-split heartbeat and cancelled-subscription exclusion), the rate limiter, CSRF, admin auth, audit log, fee calculation, HTML escaping, and value coercion

## In Progress

- **Content placeholders** in donor-visible templates (`{{PLACEHOLDER}}` blocks): EIN, real mailing address for check donations, planned-giving contact, newsletter URL. A coordinated content pass against the live foundation site is still outstanding for these specific fields.

## Next: User-Facing Features (Top 5)

1. **Additional payment methods**: enable Link / Apple Pay / Google Pay / ACH on the Stripe account and expand `payment_method_types`. Mobile conversion lift is the primary target.
2. **Real-time social proof** on the donor page: "Join N donors who gave $X this year." Code already has `totalDonationCents()` and `donorCount()`; only the rendering is missing.
3. **Tax-deduction preview**: compute inline from the selected amount ("Your $100 donation may reduce your federal tax liability by up to $X").
4. **Recurring dedications propagate.** Today the signup row carries the dedication; subsequent `invoice.paid` rows do not. Replicate onto every recurring row so the admin-side subscription detail shows the dedication on every charge.
5. **Post-donation thank-you via Stripe**: configure a richer Stripe Customer Emails template with NDASA branding and an impact story, rather than the default receipt.

## Next: Dashboard / Admin / Internal Features (Top 5)

1. **Webhook replay / inspector**: surface recent `stripe_events` rows on `/admin/diagnostics` with a re-dispatch button that calls the existing `WebhookController`.
2. **Admin-side subscription cancel**: button on the subscription detail view that calls `Stripe\Subscription::cancel`, CSRF-protected the same way the mode toggle is.
3. **Dashboard metrics-failure banner**: when the metrics try/catch hits, show an inline warning above the stat tiles instead of silent zeros.
4. **Nightly backup-pruner cron entry** documented in `deploy/README.md` as a copy-paste snippet (`--keep 5 --older-than 14 --execute`).
5. **Donor search on the donors index** (server-side email substring match, same pattern as `/admin/transactions`).

## Later / Optional

- Campaign attribution via `?c=<slug>` captured in Stripe metadata and grouped in the dashboard
- Multi-currency Checkout sessions (today hardcoded `usd`)
- Stripe Radar fraud-insights surfaced in the admin UI
- Configurable preset tiers and min/max amounts from the admin UI (today `.env`-only, SSH edit required)
- Donor portal on NDASA's side ("your giving history") keyed by email cookie, no login
- Per-email rate limit in addition to the per-IP one
- Log rotation config for `storage/logs/` or syslog integration
- CSV export streamed via generator for very large result sets
- Multi-tenant / per-campaign donation pages
- Hour-of-day / day-of-week donations heatmap
- Event-type histogram (7-day `stripe_events` `GROUP BY type`) on the dashboard
