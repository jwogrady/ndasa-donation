# Changelog

All notable changes to the NDASA Donation Platform are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- **Dedication field** on the donor form ("in memory of / in honor of"). Optional, 200-char cap, stored in Stripe session + PaymentIntent metadata and persisted to a new `donations.dedication` column. Surfaced in the staff-notification email and the admin donation detail view; intentionally not printed on the donor receipt.
- **CSV export** of donations at `/admin/export`. Optional `from` / `to` date filters (YYYY-MM-DD, interpreted in `APP_TIMEZONE`); streams `text/csv` with ISO-8601 timestamps. Accessible from a new form in the Recent Donations panel.
- **Donation detail page** at `/admin/donations/{order_id}`. Shows order metadata, donor info, status, dedication, and (for mode-aware deep linking) a link into the Stripe dashboard at the correct live/test URL. Recent Donations rows now link here.
- **Admin audit log.** New `admin_audit` SQLite table records config saves and Stripe live/test toggles with actor, action, detail, and timestamp. Renders as an "Admin Activity" panel on the dashboard (20 most recent). Config-save entries record which keys changed but never log values — secrets stay out of the log.
- **Redesigned donor header** with foundation logo, wordmark, and a rotating slogan strip (50 curated slogans in `src/Support/Slogans.php`). Random starting slogan per page view; three animation modes (fade / drift / reveal) that never repeat back-to-back. Respects `prefers-reduced-motion` and pauses while the tab is hidden.
- **Thank-you "gratitude moment"** on the success page: animated gradient heart (purple-to-orange) shown only when the payment is confirmed `paid`.
- **Site footer** with tagline, copyright year, 501(c)(3) acknowledgement, and a Stripe attribution link.
- **Admin-controlled Stripe live/test mode toggle.** A new `app_config` SQLite table persists the active mode; the admin dashboard exposes a switch that flips modes on the next request without an `.env` edit or PHP-FPM reload. Requires paired `STRIPE_LIVE_*` / `STRIPE_TEST_*` secrets in `.env`; legacy `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` remain valid as live-mode fallbacks.
- **Donor-facing test-mode banner** — sticky amber bar with a pulsing TEST chip, rendered only while the toggle is in test mode. Respects `prefers-reduced-motion`.
- **Read-only prod diagnostic script** at `deploy/diagnose.sh` for fast on-host triage.
- **Env-sync check** at `bin/check-env-sync.php` that verifies `.env.example` and `deploy/.env.template` declare the same key set.

### Changed

- Checkout session creation switched from `automatic_payment_methods` to an explicit `payment_method_types: ['card']` list. The NDASA Stripe account rejects the automatic form with `parameter_unknown`.
- Stripe API version pinned to `2026-03-25.dahlia`.
- Admin config editor expanded to cover all safe keys (Stripe, APP_URL, mail, SMTP components, donation bounds, trusted proxies) with per-field validation.

### Fixed

- Rate limiter rewritten to avoid `UPSERT` and `RETURNING`. Nexcess managed WordPress ships SQLite 3.7.17 (2013), which predates both; every `/checkout` POST was returning a generic error page. Replaced with a select-then-insert-or-update wrapped in a transaction.
- Deploy installer: removed a bogus publishable-key check and fixed quoting in the `awk`/`tr` pipeline; active `.env` is now preserved across re-installs.

---

## 1.0.0 &mdash; 2026-04-21

Initial public release of the secure-rebuild donation platform. Replaces the legacy donation application wholesale with a webhook-authoritative design that keeps card data off the server and treats Stripe as the single source of truth. Adds a Basic-Auth-protected admin panel with a metrics dashboard, a safe `.env` config editor, and a grouped System Health view.

### Added

- **Donation form** at `/` with $25/$50/$100/$250/$500 preset tiers and a free-form "Other" amount; HTML5 number input bounded by `DONATION_MIN_CENTS` / `DONATION_MAX_CENTS`.
- **"Cover the processing fee"** opt-in with live client-side preview. Server-side `FeeCalculator` grosses up the charge (2.9% + 30¢) so the foundation nets the donor's intended amount; the JS reads the same constants so the on-screen math never drifts.
- **Stripe Checkout integration** via `DonationService`. Creates a hosted session with `mode=payment`, a Stripe-side `idempotency_key`, 30-minute TTL, and `submit_type=donate`. Success and cancel URLs are derived from `APP_URL`.
- **Success page** that reads the Checkout Session back from Stripe and renders one of three truthful states — `paid`, `unpaid` (async methods still clearing), or unknown.
- **Webhook pipeline** at `/webhook` handling `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `charge.refunded`, and `payment_intent.payment_failed`. Signatures verified with a 300-second tolerance; duplicate deliveries are idempotent via the `stripe_events` table.
- **Staff notification email** sent through Symfony Mailer on every successful donation; donor receipts are sent by Stripe via `receipt_email`.
- **Admin panel** at `/admin` (HTTP Basic Auth via `ADMIN_USER` / `ADMIN_PASS`). Hardened header parsing supports both `PHP_AUTH_*` and a fallback parse of `HTTP_AUTHORIZATION` for FastCGI/LiteSpeed hosts (including Nexcess).
- **Admin dashboard metrics** — total donations (amount and count), distinct donor count, page views, and conversion rate — derived from the local SQLite ledger only; no live Stripe API calls.
- **Recent donations table** (10 rows) with date, donor, amount, and status.
- **System Health checks** grouped into Database (connection, table existence, index presence), Environment (writability of `.env`, DB file, `storage/logs/`), and Configuration (presence of required env vars). Every probe is try/catch-wrapped and cannot crash the page.
- **Admin config editor** at `/admin/config` — atomic write via temp-file + `rename()`, preserves comments and unknown keys, rejects newline injection, CSRF-protected.
- **Page-view tracking** with a 30-second per-session throttle so refreshes and cookie-holding bots cannot inflate the count; a DB failure never blocks the donation form.
- **Version resolver** with preference order `APP_VERSION` env &rarr; short git hash (parsed from `.git/HEAD`, loose refs, and packed-refs) &rarr; hardcoded `1.0.0` fallback. No shell-outs.
- **Trusted-proxy client-IP resolution** honouring `X-Forwarded-For` only when the immediate hop is in `TRUSTED_PROXIES` (CIDRs or IPs). Never trusts XFF blindly.
- **Auto-migrating SQLite schema** on first connection: `donations`, `stripe_events`, `page_views`, `rate_limit`, `app_config`, plus `idx_donations_created_at` / `idx_page_views_created_at`. WAL journal, foreign keys on, 5 s busy timeout.
- **Nexcess managed-WordPress deployment kit** in `deploy/`: `install.sh`, Apache `.htaccess` shims, PHP shims for `index.php` / `webhook.php` under the public `donation/` path, and a WordPress mu-plugin (`ndasa-shared-env.php`) that bridges `.env` into WP Mail SMTP.
- **Audience-separated documentation**: `README.md`, `docs/USER.md`, `docs/ADMIN.md`, `docs/CONTRIBUTING.md`, plus `ROADMAP.md`, `LICENSE`, `TRIBUTE.md`.
- **PHPUnit tests** for `AmountValidator` and `ClientIp`.

### Changed

- Donation metrics reflect paid-only transactions (`status = 'paid'`); refunded and failed rows are excluded so the dashboard shows actual revenue rather than attempts.
- SMTP configuration accepts either a pre-formed `SMTP_DSN` or discrete `SMTP_HOST` / `SMTP_PORT` / `SMTP_USERNAME` / `SMTP_PASSWORD` / `SMTP_ENCRYPTION` components; components are safer because special characters in the password do not need URL-encoding.
- Front controller is subpath-aware: the router strips the prefix derived from `APP_URL` so deployments at `https://host/donation` route correctly.
- CSRF tokens rotate on fresh form renders (not on every `validate()`), so honest retries and back-button resubmits keep working while cross-request replay remains blocked.

### Security

- PCI-DSS scope reduced to **SAQ-A** by keeping all card entry on `checkout.stripe.com`.
- Strict Content Security Policy with a per-request script/style nonce; `form-action` allow-lists `self` and `https://checkout.stripe.com`; `frame-ancestors 'none'`; HSTS, `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy` set on every non-webhook response.
- Production HTTPS redirect for GET requests when the edge reports plain HTTP.
- CSRF protection on all POST routes (`/checkout`, `/admin/config`, `/admin/stripe-mode`) using the same per-session token utility.
- Hardened Basic Auth header parsing: strict `Basic <base64>` regex, case-insensitive scheme match, whitespace trim, and a try/catch around the fallback parser so malformed headers fail closed instead of raising.
- Amount validator rejects scientific notation, locale separators, and non-numeric input; donor-name and email values reject CR/LF injection before reaching Stripe metadata or mail headers.
- Prepared statements only; no string-concatenation SQL anywhere in the codebase.
- Idempotent webhook handling via `stripe_events` prevents duplicate deliveries from double-recording a donation.

---

## Development-history notes

The `master` branch preserves a legacy-import commit (`c86e559`) and a breaking-change removal commit (`2b0b702`) that together record the import and removal of the pre-rebuild codebase. They are intentionally retained so the security rationale for the rebuild is auditable from the history itself. The 1.0.0 release is not derived from those trees; it is a ground-up rewrite.

[1.0.0]: https://github.com/jwogrady/ndasa-donation/releases/tag/v1.0.0
