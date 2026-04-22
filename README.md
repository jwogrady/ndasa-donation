<div align="center">

# NDASA Donation Platform

**Secure, webhook-first Stripe donation app with one-time and recurring giving, admin dashboard, and runtime live/test mode toggle.**

![PHP](https://img.shields.io/badge/php-8.2+-777bb4?logo=php&style=for-the-badge)
![Status](https://img.shields.io/badge/status-production--ready-brightgreen?style=for-the-badge)
![Stripe](https://img.shields.io/badge/payments-stripe-635bff?logo=stripe&style=for-the-badge)
![License](https://img.shields.io/badge/license-proprietary-red?style=for-the-badge)

</div>

---

## What it does

Accepts one-time, monthly, and yearly donations on a public web page. Card data is entered on `checkout.stripe.com`; this app never sees or stores a PAN. Every donation row in the local SQLite ledger is written only after Stripe's signature-verified webhook confirms payment — the browser-facing success page is advisory.

Donors get a Stripe-sent receipt. Staff get an internal notification per paid donation. Recurring donors can self-serve cancel / update payment method through the Stripe Customer Portal.

The admin panel exposes a metrics dashboard, recent donations with detail views, grouped system-health checks, a safe `.env` config editor, a runtime Stripe live/test mode toggle, a dated CSV export, and an append-only audit log.

## Features

**Donor flow**
- One-time / monthly / yearly frequency toggle (default one-time)
- Preset amounts ($25 / $50 / $100 / $250 / $500) + free-form "Other"
- Clickable impact cards that select an amount and scroll the form into view
- Live fee-cover gross-up so the foundation nets the intended amount; default opt-in
- Optional dedication field ("in memory / in honor of")
- Newsletter opt-in (pre-checked, stored per-donation)
- Subpath-aware routing (deploy at `/donation` or at root)
- Mobile-first layout: form visible on the first scroll
- Rotating header slogans (50 entries) with three animation modes; honours `prefers-reduced-motion`

**Payments**
- Stripe Checkout (hosted). `payment_method_types: ['card']`; API pinned to `2026-03-25.dahlia`
- Subscription mode for recurring donations; fee-cover baked into the subscription price at signup
- Webhook handles: `checkout.session.completed`, `checkout.session.async_payment_{succeeded,failed}`, `charge.refunded`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`
- Signature verification against BOTH live and test secrets, so mode toggles never strand an in-flight retry
- Two-phase idempotency: the handler runs first; only then is the event marked processed — a transient handler failure lets Stripe retry successfully
- Stripe Customer Portal link on the success page for recurring-donor self-service

**Success page**
- Truthful `paid` / `unpaid` (async) / `unknown` states
- Animated gratitude heart on confirmed paid
- Share buttons (X, Facebook, LinkedIn, email) with prefilled copy
- Double-the-Donation employer-matching lookup link
- Subscription-aware "Manage or cancel" link when applicable

**Admin panel** (HTTP Basic Auth)
- Metrics: total donations (amount + count), donors, page views, conversion rate
- Recent Donations table with Frequency column, linking to per-donation detail pages
- Donation detail page: full fields, mode-aware deep links to Stripe dashboard (PaymentIntent + Subscription), dedication, newsletter opt-in state
- CSV export with optional date range (YYYY-MM-DD, interpreted in `APP_TIMEZONE`)
- Admin Activity audit log (config saves diff the changed keys; secret values never logged)
- System Health: grouped Database / Environment / Configuration checks, every probe try/catch-wrapped
- `.env` config editor: atomic write, CR/LF injection rejected, CSRF-protected
- Stripe live/test mode toggle persisted in `app_config`; flips on next request with no FPM reload

**Security**
- PCI-DSS scope reduced to SAQ-A (card entry on `checkout.stripe.com`)
- Strict CSP with per-request script/style nonce; HSTS, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`
- Production HTTPS redirect on GET
- CSRF on every state-changing POST; session ID regenerated on rotation
- Amount validator rejects scientific notation / locale separators; donor fields reject CR/LF injection
- Hardened HTTP Basic Auth parser (supports FastCGI/LiteSpeed hosts)
- Trusted-proxy IP resolution (CIDR-aware, never trusts XFF blindly)
- IP-keyed rate limit on `/checkout` (5 req / 60 s)
- Prepared statements only

## How it works

```
Donor → GET /  → form (frequency + amount + details + dedication + opt-in)
    POST /checkout → validate + CSRF + rate limit → Stripe Checkout session
        → Stripe hosted checkout (card entry)
            → GET /success?sid=… (advisory)
            ↓
            Stripe webhook POST /webhook (signed, verified)
                → WebhookController
                    → EventStore (idempotency + donations row)
                    → ReceiptMailer (staff notification; Stripe sends the donor receipt)
```

The local SQLite database (`donations`, `stripe_events`, `page_views`, `rate_limit`, `app_config`, `admin_audit`) is populated by webhooks. The Stripe dashboard is the authoritative record; the local ledger is the reporting surface.

## Quick start (local)

```sh
git clone git@github.com:jwogrady/ndasa-donation.git
cd ndasa-donation
composer install
cp .env.example .env
# edit .env — Stripe test-mode keys, SMTP credentials, DB_PATH, ADMIN_USER/ADMIN_PASS
php -S 127.0.0.1:8000 -t public
```

- Donor form: <http://127.0.0.1:8000/>
- Admin: <http://127.0.0.1:8000/admin>
- Webhook endpoint (for `stripe listen --forward-to`): <http://127.0.0.1:8000/webhook>

## Configuration

Required env vars (bootstrap aborts if missing):

| Key | Purpose |
|---|---|
| `APP_URL` | Public origin, including any subpath |
| `DB_PATH` | Absolute path to the SQLite file (writable by the PHP user) |
| `MAIL_FROM` | Sender address on staff notifications |
| `MAIL_BCC_INTERNAL` | Recipient for staff notifications |
| `SMTP_HOST` or `SMTP_DSN` | Outbound mail |
| `ADMIN_USER` / `ADMIN_PASS` | HTTP Basic Auth for `/admin*` |
| Stripe credentials | For the active mode (see below) |

Stripe credentials — at least the active mode's pair must be set:

- **Live mode**: `STRIPE_LIVE_SECRET_KEY` + `STRIPE_LIVE_WEBHOOK_SECRET` (or legacy `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET`)
- **Test mode**: `STRIPE_TEST_SECRET_KEY` + `STRIPE_TEST_WEBHOOK_SECRET`

Populate both pairs if you want runtime mode toggling. See `.env.example` for the complete key list; `bin/check-env-sync.php` enforces that `.env.example` and `deploy/.env.template` declare the same keys (wired into CI).

## Stripe setup

1. **Webhook endpoint** — point Stripe at `https://<your-host>/<base-path>/webhook`. Enable events:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.async_payment_failed`
   - `charge.refunded`
   - `payment_intent.payment_failed`
   - `invoice.paid` *(required for recurring)*
   - `invoice.payment_failed` *(required for recurring)*
   - `customer.subscription.deleted` *(required for recurring)*
2. **Customer Portal** — Settings → Billing → Customer Portal → enable. Configure cancellation + payment-method update for recurring donor self-service. Without this, recurring donations still work but donors can't self-cancel; the success page falls back to a contact-us message.
3. **Payment methods** — this account rejects `automatic_payment_methods`. Enable `card` in the dashboard (and optionally Link / Apple Pay / Google Pay / ACH) and they'll surface.

## Deployment

Update in place:

```sh
cd /path/to/ndasa-donation
git pull
composer install --no-dev --optimize-autoloader
# reload PHP-FPM so opcache picks up changes
```

For a fresh install on Nexcess managed WordPress, use the kit in [deploy/](deploy/) — `install.sh`, Apache `.htaccess` shims, PHP shims, and a WordPress mu-plugin (`ndasa-shared-env.php`) that bridges `.env` into WP Mail SMTP.

Writable-path requirements:

- `.env` — only if the admin config editor will save changes
- `DB_PATH` — must be writable by the PHP-FPM user
- `storage/logs/` — must be writable

## Admin

- URL: `/admin`
- Auth: HTTP Basic Auth (`ADMIN_USER` / `ADMIN_PASS`)
- Config editor at `/admin/config` saves atomically to `.env`. PHP-FPM reload may be required for values read at bootstrap (Stripe keys, timezone, CSP — not donation bounds, SMTP, or Stripe mode)
- Stripe mode toggle persists in the DB and takes effect on the next request, no reload
- CSV export at `/admin/export?from=YYYY-MM-DD&to=YYYY-MM-DD` (both optional)
- Donation detail at `/admin/donations/{order_id}`

## Documentation

- [**docs/USER.md**](docs/USER.md) — for donors
- [**docs/ADMIN.md**](docs/ADMIN.md) — for webmasters and operators
- [**docs/CONTRIBUTING.md**](docs/CONTRIBUTING.md) — for developers
- [**CHANGELOG.md**](CHANGELOG.md)
- [**ROADMAP.md**](ROADMAP.md)
- [**LICENSE**](LICENSE) — proprietary
- [**TRIBUTE.md**](TRIBUTE.md)

## Authors

- **William Cross** — Original Author. Established the initial donation application and the foundational work this platform continues.
- **John O'Grady** (`jwogrady`, `john@status26.com`) — Maintainer, operating through **Status26 Inc**. Responsible for the current secure rebuild and ongoing maintenance.

## Ownership

All right, title, and interest in this software are assigned to the **NDASA Foundation** (<https://ndasafoundation.org/>). Use, modification, and distribution are governed by the terms of the [LICENSE](LICENSE).
