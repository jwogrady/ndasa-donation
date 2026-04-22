<div align="center">

# NDASA Donation Platform

**Secure, webhook-first Stripe donation system with admin dashboard and health monitoring**

![Version](https://img.shields.io/badge/version-1.0.0-blue?style=for-the-badge)
![PHP](https://img.shields.io/badge/php-8.2+-777bb4?logo=php&style=for-the-badge)
![Status](https://img.shields.io/badge/status-production--ready-brightgreen?style=for-the-badge)
![Stripe](https://img.shields.io/badge/payments-stripe-635bff?logo=stripe&style=for-the-badge)
![License](https://img.shields.io/badge/license-proprietary-red?style=for-the-badge)

</div>

---

## 🚀 Features

- Secure Stripe Checkout (hosted)
- Webhook-first payment verification
- Admin dashboard with metrics + health checks
- Page view tracking + conversion rate
- Production-safe configuration system

## 🔒 Trust & Safety

- No card data touches your server
- Stripe-hosted checkout
- Webhook-verified payments (source of truth)
- CSRF + rate limiting protection

## ❗ What This Is Not

- Not a CRM
- Not a campaign management system
- Not a Stripe replacement

---

## What this does

Accepts one-time donations on a public web page, creates a Stripe Checkout session, and records the donation locally only after Stripe confirms payment via a signature-verified webhook. All card data is entered on `checkout.stripe.com`; this application never sees or stores a payment-card number. The donor gets a Stripe-generated receipt; staff get an internal notification.

The Stripe webhook is the single source of truth for donation state. The admin dashboard reads only from the local SQLite ledger that the webhook populates &mdash; it does not query the Stripe API.

## Quick start

```sh
git clone git@github.com:jwogrady/ndasa-donation.git
cd ndasa-donation
composer install
cp .env.example .env
# edit .env — Stripe test-mode keys, SMTP credentials, DB_PATH, ADMIN_USER/ADMIN_PASS
php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000/> for the donation form or <http://127.0.0.1:8000/admin> for the admin dashboard. Full configuration reference is in [docs/ADMIN.md](docs/ADMIN.md).

## Admin

- URL: `/admin`
- Protected by HTTP Basic Auth (`ADMIN_USER` / `ADMIN_PASS` in `.env`).
- The config editor at `/admin/config` saves changes atomically to `.env`. A PHP-FPM reload may be required for new values to take effect.
- Optional `APP_VERSION` in `.env` is shown in the admin footer; if unset the app falls back to the short git hash, then a hardcoded constant.

## Deployment

Standard deploy on a server that already has the app checked out:

```sh
cd /path/to/ndasa-donation
git pull
composer install --no-dev --optimize-autoloader
# reload PHP-FPM so opcache picks up changes
```

For the first deploy on managed WordPress hosting (Nexcess), use the kit at [deploy/](deploy/) &mdash; it installs the app above the webroot and shares SMTP credentials with WordPress through an mu-plugin.

Writable-path requirements:

- **`.env`** &mdash; writable only if the admin config editor will save changes.
- **SQLite database at `DB_PATH`** &mdash; must be writable by the PHP-FPM user.
- **`storage/logs/`** &mdash; must be writable.

Missing any required env var (`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `APP_URL`) will cause the bootstrap to abort with a 500; the admin dashboard surfaces the same condition as a "Configuration incomplete &mdash; donations may fail" banner.

Full deployment details, webhook registration, and operational procedures are in [docs/ADMIN.md](docs/ADMIN.md).

## Documentation

- [**docs/USER.md**](docs/USER.md) &mdash; for donors. What the donation page shows, how amounts are chosen, what the success / cancel / error pages mean, and what to expect for receipts.
- [**docs/ADMIN.md**](docs/ADMIN.md) &mdash; for webmasters and operators. Setup, deployment, Stripe configuration, admin panel, metrics definitions, System Health checks, operational notes, and known limitations.
- [**docs/CONTRIBUTING.md**](docs/CONTRIBUTING.md) &mdash; for developers. Repository layout, local development, testing, coding conventions, branching, and release workflow.
- [**CHANGELOG.md**](CHANGELOG.md) &mdash; release history.
- [**ROADMAP.md**](ROADMAP.md) &mdash; forward-looking feature plan.
- [**LICENSE**](LICENSE) &mdash; proprietary licence; all rights assigned to the NDASA Foundation.
- [**TRIBUTE.md**](TRIBUTE.md) &mdash; recognition of the project's original author.

## Authors

- **William Cross** &mdash; Original Author. Established the initial donation application and the foundational work that this platform continues.
- **John O'Grady** (`jwogrady`, `john@status26.com`) &mdash; Maintainer, operating through **Status26 Inc**. Responsible for the current secure rebuild and ongoing maintenance.

## Ownership

All right, title, and interest in this software are assigned to the **NDASA Foundation** (<https://ndasafoundation.org/>). Use, modification, and distribution are governed by the terms of the [LICENSE](LICENSE). See [TRIBUTE.md](TRIBUTE.md) for project recognition.
