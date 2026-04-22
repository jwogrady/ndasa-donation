<div align="center">

<img src="public/assets/img/Foundation-Logo.png" alt="NDASA Foundation" width="120" />

# NDASA Donation Platform

### Secure, webhook-first Stripe donations for the NDASA Foundation

One-time, monthly, and yearly giving · admin dashboard · runtime live/test toggle · PCI-DSS SAQ-A

<!-- Identity -->

[![NDASA Foundation](https://img.shields.io/badge/NDASA-Foundation-623b99?style=for-the-badge&labelColor=4e2e7a)](https://ndasafoundation.org/)
[![Donate](https://img.shields.io/badge/Donate-secure%20checkout-fa5c1e?style=for-the-badge&labelColor=623b99)](https://ndasafoundation.org/donation/)
[![501(c)(3)](https://img.shields.io/badge/501(c)(3)-Tax--Deductible-0a7d3b?style=for-the-badge&labelColor=1f3527)](https://ndasafoundation.org/)

<!-- Tech -->

[![PHP](https://img.shields.io/badge/PHP-8.2+-777bb4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3.7.17+-003b57?style=for-the-badge&logo=sqlite&logoColor=white)](https://sqlite.org/)
[![Stripe](https://img.shields.io/badge/Stripe-API%202026--03--25-635bff?style=for-the-badge&logo=stripe&logoColor=white)](https://stripe.com/docs/api)
[![Symfony Mailer](https://img.shields.io/badge/Symfony-Mailer-000000?style=for-the-badge&logo=symfony&logoColor=white)](https://symfony.com/components/Mailer)

<!-- Quality / posture -->

[![CI](https://img.shields.io/badge/CI-GitHub%20Actions-2088ff?style=for-the-badge&logo=githubactions&logoColor=white)](.github/workflows/ci.yml)
[![PHPUnit](https://img.shields.io/badge/tests-PHPUnit-366488?style=for-the-badge&logo=phpunit&logoColor=white)](phpunit.xml)
[![PCI](https://img.shields.io/badge/PCI--DSS-SAQ--A-0a7d3b?style=for-the-badge)](https://www.pcisecuritystandards.org/)
[![CSP](https://img.shields.io/badge/CSP-nonce%20per%20request-1f3527?style=for-the-badge)](config/app.php)
[![Status](https://img.shields.io/badge/status-production%20ready-brightgreen?style=for-the-badge)](CHANGELOG.md)

<!-- Project -->

[![License](https://img.shields.io/badge/license-proprietary-b42318?style=for-the-badge)](LICENSE)
[![Maintained](https://img.shields.io/badge/maintained-Status26%20Inc-623b99?style=for-the-badge)](https://status26.com/)
[![Changelog](https://img.shields.io/badge/changelog-up%20to%20date-fa5c1e?style=for-the-badge)](CHANGELOG.md)

</div>

---

## Overview

Accepts **one-time, monthly, and yearly** donations on a public web page. Card data is entered on `checkout.stripe.com` — this application never sees or stores a PAN. Every donation row in the local SQLite ledger is written only after Stripe's signature-verified webhook confirms payment; the browser-facing success page is purely advisory.

Donors get a Stripe-sent receipt and (for recurring) a Stripe Customer Portal link to self-serve cancel or update their card. Staff get an internal notification per paid donation. The admin panel exposes a metrics dashboard, per-donation detail views with deep links into Stripe, grouped system-health checks, a safe `.env` config editor, a runtime live/test Stripe mode toggle, a dated CSV export, and an append-only audit log.

```
                                Donor journey
                                ─────────────
   /  (form)  ─►  /checkout  ─►  checkout.stripe.com  ─►  /success
                      │                                        │
                      │                                        ▼
                      └──────── Stripe webhook ──►  /webhook  ─►  SQLite ledger
                                                         │
                                                         └─►  Staff email
```

## Feature highlights

<table>
<tr>
<td width="50%" valign="top">

### 🎁  Donor experience
- One-time / **monthly / yearly** frequency (Stripe subscriptions)
- Preset tiers **$25 · $50 · $100 · $250 · $500** + free-form "Other"
- Clickable impact cards with real hover + selection feedback
- Live fee-cover gross-up (donor covers 2.9% + 30¢, foundation nets 100%)
- Optional in-memory / in-honor-of dedication field
- Newsletter opt-in (pre-checked, stored per donation)
- Mobile-first layout · form above the fold
- Rotating 50-slogan header strip · respects `prefers-reduced-motion`

</td>
<td width="50%" valign="top">

### 🧭  Admin panel
- Metrics dashboard (donations, donors, page views, conversion rate)
- Recent Donations with per-row detail and Stripe deep links
- CSV export with date-range filter
- Append-only audit log (config saves, mode toggles)
- Grouped system health (DB / env / config)
- Atomic `.env` config editor (CSRF + CR/LF rejected)
- **Live/Test Stripe mode toggle** · flips on next request, no reload

</td>
</tr>
<tr>
<td width="50%" valign="top">

### 💳  Payments
- Stripe Checkout (hosted) · API pinned to `2026-03-25.dahlia`
- Signature verified against **both** live and test secrets
- Two-phase webhook idempotency (handler runs before mark-processed)
- Eight webhook events handled — including `invoice.paid`,
  `invoice.payment_failed`, and `customer.subscription.deleted` for
  recurring
- Customer Portal for self-serve subscription management

</td>
<td width="50%" valign="top">

### 🔒  Security posture
- **PCI-DSS SAQ-A** — card data never touches the server
- Strict **CSP** with per-request script/style nonce
- HSTS · `X-Frame-Options: DENY` · Permissions-Policy
- Prod HTTPS redirect · session ID regen on CSRF rotate
- CSRF on every state-changing POST
- Trusted-proxy XFF (CIDR-aware)
- Rate limit on `/checkout` (5 req / 60 s per IP)
- Prepared statements only

</td>
</tr>
</table>

## Quick start

```sh
git clone git@github.com:jwogrady/ndasa-donation.git
cd ndasa-donation
composer install
cp .env.example .env
# edit .env — Stripe test keys, SMTP, DB_PATH, ADMIN_USER/ADMIN_PASS
php -S 127.0.0.1:8000 -t public
```

| What | Where |
|---|---|
| Donor form | <http://127.0.0.1:8000/> |
| Admin panel | <http://127.0.0.1:8000/admin> |
| Webhook endpoint | <http://127.0.0.1:8000/webhook> |

Forward Stripe events to the local endpoint with `stripe listen --forward-to localhost:8000/webhook`.

## Configuration

Required env vars — bootstrap aborts on 500 if any are missing:

| Key | Purpose |
|---|---|
| `APP_URL` | Public origin, including any subpath |
| `DB_PATH` | Absolute path to SQLite file (writable by PHP user) |
| `MAIL_FROM` | Sender address on staff notifications |
| `MAIL_BCC_INTERNAL` | Recipient of staff donation notifications |
| `SMTP_HOST` or `SMTP_DSN` | Outbound mail |
| `ADMIN_USER` / `ADMIN_PASS` | HTTP Basic Auth for `/admin*` |
| Stripe credentials | See below |

**Stripe credentials** — at least the active mode's pair:

- Live: `STRIPE_LIVE_SECRET_KEY` + `STRIPE_LIVE_WEBHOOK_SECRET` (or legacy `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET`)
- Test: `STRIPE_TEST_SECRET_KEY` + `STRIPE_TEST_WEBHOOK_SECRET`

Populate both for runtime mode toggling. See [`.env.example`](.env.example) for the full key list. [`bin/check-env-sync.php`](bin/check-env-sync.php) enforces that `.env.example` and `deploy/.env.template` declare the same keys — wired into CI.

## Stripe setup

1. **Webhook endpoint** → `https://<your-host>/<base-path>/webhook`. Enable exactly these events:

   | Event | Required |
   |---|---|
   | `checkout.session.completed` | ✅ |
   | `checkout.session.async_payment_succeeded` | ✅ |
   | `checkout.session.async_payment_failed` | ✅ |
   | `charge.refunded` | ✅ |
   | `payment_intent.payment_failed` | ✅ |
   | `invoice.paid` | ✅ recurring |
   | `invoice.payment_failed` | ✅ recurring |
   | `customer.subscription.deleted` | ✅ recurring |

2. **Customer Portal** → Settings → Billing → Customer Portal → **Enable**. Configure cancellation and payment-method update so recurring donors can self-serve. Without this, recurring still works but the success page falls back to a contact-us message.

3. **Payment methods** — this account rejects `automatic_payment_methods`. Enable **card** in the dashboard (optionally Link / Apple Pay / Google Pay / ACH) and the code will pick them up once enabled.

## Deployment

Update in place:

```sh
cd /path/to/ndasa-donation
git pull
composer install --no-dev --optimize-autoloader
# reload PHP-FPM so opcache picks up changes
```

For a fresh install on Nexcess managed WordPress, use the kit in [`deploy/`](deploy/) — `install.sh`, Apache `.htaccess` shims, PHP front-controller shims, and a WordPress mu-plugin (`ndasa-shared-env.php`) that bridges `.env` into WP Mail SMTP.

Writable-path requirements:

- `.env` — only if the admin config editor will save changes
- `DB_PATH` — must be writable by the PHP-FPM user
- `storage/logs/` — must be writable

## Admin

- URL: `/admin`
- Auth: HTTP Basic Auth (`ADMIN_USER` / `ADMIN_PASS`)
- Config editor at `/admin/config` — atomic writes to `.env`. Some values read at bootstrap (Stripe keys, timezone, CSP) need a PHP-FPM reload; donation bounds, SMTP, and the Stripe mode toggle don't.
- CSV export: `/admin/export?from=YYYY-MM-DD&to=YYYY-MM-DD` (both optional)
- Per-donation detail: `/admin/donations/{order_id}` — deep links into Stripe dashboard for PaymentIntent and Subscription

## Documentation

| File | Audience |
|---|---|
| [`docs/USER.md`](docs/USER.md) | Donors |
| [`docs/ADMIN.md`](docs/ADMIN.md) | Webmasters / operators |
| [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) | Developers |
| [`CHANGELOG.md`](CHANGELOG.md) | Release history |
| [`ROADMAP.md`](ROADMAP.md) | What's next |
| [`LICENSE`](LICENSE) | Proprietary — NDASA Foundation |
| [`TRIBUTE.md`](TRIBUTE.md) | Project recognition |

## Authors

**William Cross** — Original Author. Established the initial donation application and the foundational work this platform continues.

**John O'Grady** · [`@jwogrady`](https://github.com/jwogrady) · [john@status26.com](mailto:john@status26.com) — Maintainer, operating through **Status26 Inc**. Responsible for the current secure rebuild and ongoing maintenance.

## Ownership

All right, title, and interest in this software are assigned to the **[NDASA Foundation](https://ndasafoundation.org/)**. Use, modification, and distribution are governed by the terms of the [LICENSE](LICENSE).

<div align="center">

---

**Built with gravitas by [Status26 Inc](https://status26.com/) · Maintained in honor of William Cross**

</div>
