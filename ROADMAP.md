# Roadmap

Forward-looking plan for the NDASA Donation Platform. This file reflects what the codebase actually implements today and what is likely to come next. Items under "Next" and "Later" are candidates, not commitments — each needs its own planning, review, and release cycle.

## Current State

- One-time donation flow at `/` with preset tiers ($25/$50/$100/$250/$500) plus free-form "Other".
- Stripe Checkout (hosted) via an explicit `payment_method_types: ['card']` session; Stripe API pinned to `2026-03-25.dahlia`.
- Live fee gross-up on the client, with the server-side `FeeCalculator` as the source of truth.
- Success page that reads back the Checkout Session and renders `paid` / `unpaid` (async) / unknown states truthfully; gratitude animation on confirmed `paid`.
- Signature-verified webhook at `/webhook` handling `checkout.session.completed`, `checkout.session.async_payment_{succeeded,failed}`, `charge.refunded`, and `payment_intent.payment_failed`. Idempotent via the `stripe_events` table.
- Staff-notification email per successful donation via Symfony Mailer; donor receipt sent by Stripe.
- Admin panel at `/admin` behind HTTP Basic Auth, with dashboard metrics, recent-donations table (linked to detail view), Admin Activity audit log, System Health sections (Database / Environment / Configuration), and a CSRF-protected config editor.
- Donation detail page at `/admin/donations/{order_id}` with a mode-aware deep link into the Stripe dashboard.
- CSV export at `/admin/export` with optional date-range filter.
- Append-only admin audit log (`admin_audit` table) for config saves and Stripe mode toggles; config entries record key names only, never values.
- Optional donor dedication field ("in memory of / in honor of") stored in Stripe metadata and the `donations.dedication` column; surfaced on the staff-notification email and the detail view.
- Runtime Stripe **live/test mode toggle** persisted in the `app_config` table; flips on the next request with no `.env` edit or PHP-FPM reload. Donor-facing test-mode banner.
- Page-view tracking throttled to one hit per 30 s per session; conversion rate derived from paid donations over views.
- Rotating donor-header slogans (50 entries) with three animation modes, `prefers-reduced-motion` support, and page-visibility pause.
- SQLite schema auto-migrates on connect (`donations`, `stripe_events`, `page_views`, `rate_limit`, `app_config`), with the two indexes the dashboard queries depend on. Compatible with SQLite 3.7.17 (no `UPSERT`, `RETURNING`, or window functions used).
- Rate limiter on `/checkout` (5 req / 60 s per client IP) using a transaction-wrapped select-then-insert-or-update.
- Trusted-proxy XFF resolution (CIDR-aware).
- Strict CSP with per-request nonce, HSTS, production HTTPS redirect, `X-Frame-Options: DENY`, and PCI-DSS SAQ-A scope.
- Subpath-aware routing (deployments at `/donation` work without code changes).
- Version resolver: `APP_VERSION` env &rarr; short git hash &rarr; hardcoded fallback.
- Nexcess managed-WordPress deploy kit (`install.sh`, Apache shims, PHP shims, WordPress mu-plugin bridging `.env` into WP Mail SMTP) and a read-only `diagnose.sh`.
- `bin/check-env-sync.php` verifies `.env.example` and `deploy/.env.template` declare the same keys.
- PHPUnit tests for `AmountValidator` and `ClientIp`.

## In Progress

- Copy placeholders in the donor templates (impact tiers, allocation percentages, EIN, planned-giving contact, mailing address, newsletter signup) flagged with `{{PLACEHOLDER: ...}}` — awaiting foundation-supplied content.

## Next: User-Facing Features

1. **Recurring (monthly) donations.** Add a "give monthly" toggle that creates a Stripe subscription instead of a one-time payment session; webhook pipeline already has the idempotency and event-store bones for subscription events.
2. **Additional payment methods.** Expand `payment_method_types` beyond card (Link, Apple Pay, Google Pay, ACH) once the Stripe account's dashboard-side payment-method configuration is completed — the current restriction is account-side, not code-side.
3. **Fill in real impact and allocation copy.** Replace the `{{PLACEHOLDER}}` blocks with audited figures, EIN, and live contact points.

## Next: Dashboard / Admin / Internal Features

1. **Trend charts.** Daily/weekly donation totals and page-views over the last 30/90 days, rendered from the existing `donations` and `page_views` tables (both already indexed on `created_at`).
2. **Webhook replay / inspector.** Surface recent `stripe_events` rows with type, timestamp, and a "re-run" button that re-dispatches an event through `WebhookController` (useful when a handler bug is fixed after delivery).

## Later / Optional

- Campaign attribution: optional `?c=<slug>` query parameter captured in Stripe metadata and grouped in the dashboard.
- Multi-currency Checkout sessions (today the code hardcodes `usd`).
- Fraud insights via Stripe Radar rules surfaced in the admin UI.
- Customer Portal link in the staff notification so donors can manage future recurring gifts.
- Configurable preset tiers and minimum/maximum amounts from the admin UI (today they're `.env`-only).
- Multi-tenant / per-campaign page support.
