# NDASA Donation Platform

A webhook-authoritative online donation application for the **NDASA Foundation**, built on Stripe Checkout. All card data is entered on Stripe's hosted page; this application never sees or stores a payment card number.

## What it does

- Accepts one-time donations from any web browser.
- Offers preset amounts ($25, $50, $100, $250, $500) and free-form custom amounts, within configurable bounds.
- Optionally grosses up the charge so the foundation receives the full intended amount after Stripe's fees.
- Uses Stripe's hosted Checkout for payment entry &mdash; Card, Link, Apple Pay, Google Pay, and ACH surface automatically based on the Stripe account configuration.
- Treats the Stripe webhook as the system of record; browser redirects are used only for the donor's own confirmation message.
- Sends the donor a receipt (via Stripe) and notifies staff of each completed gift (via the application's own mail transport).

## Who this is for

This repository contains three audience-specific documents. Start with the one that matches your role.

- [**docs/USER.md**](docs/USER.md) &mdash; for donors and staff who explain the donation flow to donors. Describes what happens on the page, what the three success states mean, and what to expect for receipts.
- [**docs/ADMIN.md**](docs/ADMIN.md) &mdash; for webmasters and operators. Installation on Nexcess managed WordPress hosting, environment variables, Stripe webhook registration, SMTP configuration, rate-limit and security settings, reconciliation and monitoring notes.
- [**docs/CONTRIBUTING.md**](docs/CONTRIBUTING.md) &mdash; for developers working on the codebase. Repository structure, local development, testing, coding conventions, and release discipline.

Supporting documents:

- [**CHANGELOG.md**](CHANGELOG.md) &mdash; release history, grounded in the actual git commit record.
- [**LICENSE**](LICENSE) &mdash; proprietary licence; all rights assigned to the NDASA Foundation.
- [**TRIBUTE.md**](TRIBUTE.md) &mdash; recognition of the project's original author.

## Getting started in one minute

If you are simply evaluating the project:

```sh
git clone git@github.com:jwogrady/ndasa-donation.git
cd ndasa-donation
composer install
cp .env.example .env
php -S 127.0.0.1:8000 -t public    # then open http://127.0.0.1:8000/
```

This will give you the donation form. To actually create a Checkout session you will need Stripe test-mode keys in `.env` &mdash; see [docs/ADMIN.md](docs/ADMIN.md) for full configuration, or [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for the local-development workflow.

## Admin

- URL: `/admin`
- Protected by HTTP Basic Auth.
- Credentials are set via `ADMIN_USER` and `ADMIN_PASS` in `.env`.
- A minimal config editor at `/admin/config` can update the Stripe keys, `APP_URL`, and `MAIL_BCC_INTERNAL`. A PHP-FPM reload may be required for changes to take full effect.
- Config POSTs are CSRF-protected using the same per-session token mechanism the donation form uses.

### Dashboard Metrics

- **Page views** are counted per GET request to `/` (the donation page).
- **Donations** are sourced from webhook-verified records in the local SQLite ledger; the Stripe API is not queried.
- **Conversion rate** = donations &divide; page views, expressed as a percentage rounded to one decimal place. Zero page views yields 0%.
- **Recent donations** shows the ten most recent rows, newest first.

### Performance & Metrics Notes

- **Page views are throttled** to one record per 30 seconds per session, so a refresh-happy donor or bot with a cookie jar cannot inflate the count.
- **Metrics use database aggregates** (`COUNT`, `SUM`, `COUNT(DISTINCT …)`); results are memoised per request so the dashboard runs each query at most once per page load.
- **Indexes on `donations(created_at)` and `page_views(created_at)`** are created automatically on first connect and are required for dashboard queries to remain fast as volume grows.
- **System Health warnings** appear on `/admin` when any required env var is missing, any expected table or index is absent, or the database is unreachable.

## Authors

- **William Cross** &mdash; Original Author. Established the initial donation application and the foundational work that this platform continues.
- **John O'Grady** (`jwogrady`, `john@status26.com`) &mdash; Maintainer, operating through **Status26 Inc**. Responsible for the current secure rebuild and ongoing maintenance.

## Ownership

All right, title, and interest in this software are assigned to the **NDASA Foundation** (<https://ndasafoundation.org/>). Use, modification, and distribution are governed by the terms of the [LICENSE](LICENSE).

## Acknowledgment

This platform exists because of William Cross's foundational contribution. The present codebase is a secure rebuild that preserves the purpose and intent of his original work. It is maintained in his honor. See [TRIBUTE.md](TRIBUTE.md).
