# Changelog

All notable changes to the NDASA Donation Platform are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry is traceable to one or more commits in the git history.

## [1.0.0] &mdash; 2026-04-21

Initial public release of the secure-rebuild donation platform. The release replaces a legacy donation application wholesale with a webhook-authoritative, PCI-SAQ-A-scoped design. All changes in this release were authored between `dc841cb` and `211e621` on the `master` branch (prior to the documentation and deployment branches).

### Added

- **Project scaffolding** &mdash; initial `composer.json` requiring PHP 8.2+, the Stripe SDK, `vlucas/phpdotenv`, and `symfony/mailer`; `phpunit.xml`; `.env.example` with placeholder-only values; `.gitignore` excluding `.env`, `vendor/`, logs, and the SQLite database; initial `README.md`; a writable `storage/` directory. _(`8757e2b`)_
- **Env-driven bootstrap** (`config/app.php`) &mdash; loads `.env`, fails closed on missing required secrets, configures error logging, initialises the Stripe SDK, emits security headers (HSTS, CSP with per-request script nonce, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`), enforces HTTPS in production, and starts a hardened session. Webhook endpoints opt out of session handling via `NDASA_SKIP_SESSION`. _(`643a831`)_
- **HTTP primitives** (`src/Http/`) &mdash; `Csrf` issues per-session tokens, rotates on validate, compares in constant time; `RateLimiter` applies a fixed-window limit via a single atomic SQLite UPSERT; `ClientIp` resolves the real client address from `X-Forwarded-For`, trusting only CIDRs or IPs listed in `TRUSTED_PROXIES` and rejecting wildcard trust. _(`d546386`)_
- **Data layer** (`src/Support/`) &mdash; lazy-singleton PDO handle on SQLite with WAL, foreign keys, and a 5-second busy timeout; auto-migrations create the `stripe_events`, `donations`, and `rate_limit` tables on first connect; `Html::h()` helper for contextual output escaping. _(`7409a29`)_
- **Payment domain** (`src/Payment/`) &mdash; `AmountValidator` converts a whitelisted dollar string to integer cents and enforces configurable minimum and maximum bounds; `FeeCalculator` grosses up the charge to Stripe's US standard rate when the donor opts to cover fees; `DonationService` wraps `Stripe\Checkout\Session::create` with a deterministic idempotency key derived from the order ID. _(`448320d`)_
- **Webhook pipeline** (`src/Webhook/`) &mdash; `EventStore` provides an idempotency log keyed on Stripe event ID and a donation ledger keyed on order ID, both using `INSERT OR IGNORE`; `WebhookController` dispatches `checkout.session.completed`, `charge.refunded`, and `payment_intent.payment_failed`, returning a boolean so the entry point can trigger Stripe's retry behaviour on handler failure. _(`33535e4`)_
- **Mail** (`src/Mail/ReceiptMailer.php`) &mdash; Symfony Mailer wrapper that sends staff notifications on every completed donation via a DSN-configured transport. Donor receipts are sent by Stripe directly via `receipt_email`. CR/LF rejection on header-bound values provides defence in depth against header injection. _(`586b94c`)_
- **Public entry points and Apache hardening** (`public/`) &mdash; `index.php` front controller for `GET /`, `POST /checkout`, and `GET /success`, applying rate-limit and CSRF checks before any state change; `webhook.php` verifies the `Stripe-Signature` header with a 300-second tolerance and dispatches the event; `.htaccess` denies dotfiles and sensitive extensions and rewrites unknown paths to `index.php`. _(`2a913b7`)_
- **User interface** (`templates/`, `public/assets/css/styles.css`) &mdash; donation form with preset amounts and free-form entry; live "card will be charged" preview whose gross-up matches `FeeCalculator` on the server; three-state success page (`paid` / `unpaid` / `unknown`) that speaks honestly about async payment methods; status-code-aware error page with per-situation recovery guidance; shared layout with a skip-to-content link. All output is escaped through `Html::h()`. The form submits correctly without JavaScript, falling back to a whitelisted preset when the free-form amount is empty. _(`98cbc5c`)_
- **Tests** (`tests/`) &mdash; PHPUnit coverage for `AmountValidator` (eight cases covering simple integers, decimals, negatives, below-min, above-max, scientific notation, alphabetic input, and excess decimals) and `ClientIp` (six cases covering trusted-proxy chain walking, exact-IP match, wildcard rejection, and malformed `X-Forwarded-For`). _(`388000f`)_

### Changed

- **Stripe SDK upgraded from `^16.0` to `^20.0`** and the Stripe API version explicitly pinned to `2026-03-25.dahlia` in `config/app.php` so future SDK upgrades cannot silently shift webhook payload shapes. The webhook entry point's error-log message was clarified to reflect that in v20+, `UnexpectedValueException` can indicate either a malformed payload or a V2 event notification misrouted to the V1 endpoint; control flow is unchanged (both still return 400). Reviewing the pinned API version before changing it is a deliberate step. _(`211e621`)_

### Removed

- **Legacy donation application.** The pre-rebuild tree was imported as a single historical-reference commit (`c86e559`) and then removed wholesale prior to the rebuild (`2b0b702`). Removed components included an `index.php` that collected raw PAN and CVV, a hardcoded live Stripe key, Stripe.js v2 (no SCA/3DS2 support), the deprecated Charges API path, a `phpinfo.txt` that leaked the server environment to the public web, `mail()` usage with unsanitized headers, client-trusted amount calculation in `ajax/calculate.php`, and the absence of CSRF, webhook handling, and input validation. This was a breaking change: the `/donation/index.php` endpoint is gone.

### Security

- PCI-DSS scope reduced to **SAQ-A** by moving all card entry to Stripe Checkout; no card data enters the application.
- Strong Content Security Policy with a per-request script nonce replaces any reliance on `'unsafe-inline'` scripts.
- Webhook signature verification with a 300-second tolerance window.
- Idempotent event handling ensures duplicate Stripe deliveries cannot double-record a donation.
- Prepared statements only; no string concatenation against SQL.

---

## Development-history notes

The `master` branch preserves three commits (`dc841cb`, `659b51b`, `c86e559`) and a breaking-change commit (`2b0b702`) that together record the import and removal of the legacy codebase. They are intentionally retained so that the security rationale for the rebuild is auditable from the history itself. The present `1.0.0` release is not derived from those trees; it is a ground-up rewrite.

Changes made after `211e621` (payment-method expansion, async webhook handlers, subpath-aware routing, SMTP component configuration, deployment kit for Nexcess managed WordPress hosting, the audience-separated documentation set, and the `LICENSE` / `TRIBUTE.md` additions) are currently unreleased and sit on the working tree only; they will be rolled into the next release entry once tagged.

[1.0.0]: https://github.com/jwogrady/ndasa-donation/releases/tag/v1.0.0
