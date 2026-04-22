# Roadmap

Forward-looking plan for the NDASA Donation Platform. This file reflects what the codebase actually implements today and what comes next. Items under "Next" and "Later" are candidates, not commitments — each needs its own planning, review, and release cycle.

## Current State

**Donor flow**
- One-time / monthly / yearly frequency toggle (default one-time)
- Preset amounts ($25/$50/$100/$250/$500) plus free-form "Other"
- Clickable impact cards that select an amount and scroll the form into view
- Live fee-cover gross-up; default opt-in
- Optional dedication field (stored in Stripe metadata + local column)
- Newsletter opt-in checkbox (pre-checked; stored per-donation)
- Mobile-first layout: form above impact/allocation on small viewports
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

**Success page**
- Truthful `paid` / `unpaid` (async) / `unknown` states
- Animated gratitude heart on confirmed paid
- Subscription-aware "Manage or cancel" section
- Share buttons (X, Facebook, LinkedIn, email) with prefilled copy
- Double-the-Donation employer-matching lookup link

**Admin panel** (HTTP Basic Auth)
- Dashboard metrics (total donations, donor count, page views, conversion rate) from a single consolidated SQL aggregation
- Recent Donations table with Frequency column, rows link to per-donation detail
- Donation detail page at `/admin/donations/{order_id}` with mode-aware deep links into Stripe (PaymentIntent + Subscription)
- CSV export at `/admin/export` with optional date range; includes interval, subscription id, dedication, newsletter opt-in
- Append-only Admin Activity audit log (config saves diff changed keys only; never values)
- System Health groups: Database, Environment, Configuration; every probe try/catch-wrapped
- `.env` config editor with per-field validation, atomic write, CSRF
- Runtime Stripe live/test mode toggle persisted in `app_config`
- Version resolver: `APP_VERSION` → short git hash → hardcoded fallback

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
- SQLite database, WAL journal; schema auto-migrates on connection; 3.7.17-safe (no `UPSERT`, `RETURNING`, or window functions)
- Indexes: `idx_donations_created_at`, `idx_donations_status`, `idx_donations_subscription`, `idx_page_views_created_at`, `idx_admin_audit_created_at`
- Nexcess managed-WordPress deploy kit (`install.sh`, Apache shims, PHP shims, WP mu-plugin bridging `.env` into WP Mail SMTP)
- Read-only `deploy/diagnose.sh` for on-host triage
- `bin/check-env-sync.php` verifies `.env.example` and `deploy/.env.template` declare the same keys
- CI (`.github/workflows/ci.yml`): `php -l`, env-sync, PHPUnit
- PHPUnit tests for `AmountValidator` and `ClientIp`

## In Progress

- **Content placeholders** in donor-visible templates (`{{PLACEHOLDER}}` blocks): allocation percentages, EIN, mailing address, planned-giving contact, newsletter URL, and the $25/$100/$500 impact copy — impact tiers have been rewritten to match `ndasafoundation.org/about/causes/`, but the hero headline and allocation bars still reference the legacy positioning ("drug-free communities") and need a coordinated content pass against the real foundation programs.

## Next: User-Facing Features (Top 5)

1. **Post-donation thank-you email from NDASA** (separate from Stripe's receipt). 48-hour delay, one real impact story, share prompt, unsubscribe link. The biggest retention lever currently unbuilt.
2. **Additional payment methods**: enable Link / Apple Pay / Google Pay / ACH on the Stripe account and expand `payment_method_types`. Mobile conversion lift is the primary target.
3. **Real-time social proof** on the donor page: "Join N donors who gave $X this year." Code already has `totalDonationCents()` and `donorCount()`; only the rendering is missing.
4. **Tax-deduction preview**: compute inline from the selected amount ("Your $100 donation may reduce your federal tax liability by up to $X").
5. **Recurring dedications propagate.** Today the signup row carries the dedication; subsequent `invoice.paid` rows do not. Replicate onto every recurring row and the staff notification.

## Next: Dashboard / Admin / Internal Features (Top 5)

1. **Active subscriber view**: filter recent donations by interval, show count and MRR derived from the existing `donations.interval` + `stripe_subscription_id` columns.
2. **Trend charts**: daily/weekly donations and page-views over 30/90 days, rendered from `donations(created_at)` and `page_views(created_at)` — both already indexed.
3. **Webhook replay / inspector**: surface recent `stripe_events` rows with a re-dispatch button for the existing `WebhookController`.
4. **Admin-side subscription cancel**: button on the donation detail view that calls `Stripe\Subscription::cancel`, authenticated by the same CSRF token the config editor uses.
5. **Dashboard metrics-failure banner**: when the metrics try/catch hits, show an inline warning above the stat tiles instead of silent zeros.

## Later / Optional

- Campaign attribution via `?c=<slug>` captured in Stripe metadata and grouped in the dashboard
- Multi-currency Checkout sessions (today hardcoded `usd`)
- Stripe Radar fraud-insights surfaced in the admin UI
- Configurable preset tiers and min/max amounts from the admin UI (today `.env`-only)
- Donor portal on NDASA's side ("your giving history") keyed by email cookie, no login
- Per-email rate limit in addition to the per-IP one
- Log rotation config for `storage/logs/` or syslog integration
- CSV export streamed via generator for very large result sets
- Multi-tenant / per-campaign donation pages
