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

## Authors

- **William Cross** &mdash; Original Author. Established the initial donation application and the foundational work that this platform continues.
- **John O'Grady** (`jwogrady`, `john@status26.com`) &mdash; Maintainer, operating through **Status26 Inc**. Responsible for the current secure rebuild and ongoing maintenance.

## Ownership

All right, title, and interest in this software are assigned to the **NDASA Foundation** (<https://ndasafoundation.org/>). Use, modification, and distribution are governed by the terms of the [LICENSE](LICENSE).

## Acknowledgment

This platform exists because of William Cross's foundational contribution. The present codebase is a secure rebuild that preserves the purpose and intent of his original work. It is maintained in his honor. See [TRIBUTE.md](TRIBUTE.md).
