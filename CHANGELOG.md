# Changelog

All notable changes to the NDASA Donation Platform are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 &mdash; 2026-04-21

Initial public release of the secure-rebuild donation platform. Replaces the legacy donation application wholesale with a webhook-authoritative design that keeps card data off the server and treats Stripe as the single source of truth. Adds a Basic-Auth-protected admin panel with a metrics dashboard, a safe `.env` config editor, and a grouped System Health view.

### Added

- Admin panel with config editor (atomic `.env` write, CSRF-protected, PHP-FPM-reload caveat documented).
- Dashboard with real metrics &mdash; total donations (amount and count), total donors, page views, and conversion rate.
- System Health checks grouped into Database, Environment, and Configuration; every probe is try/catch-wrapped and never crashes the page.
- Page-view tracking with a 30-second per-session throttle so refreshes and cookie-holding bots cannot inflate the count.
- Version display system with preference order `APP_VERSION` env &rarr; short git hash &rarr; hardcoded fallback constant.
- Stripe Checkout integration with `automatic_payment_methods` so Card, Link, Apple Pay, Google Pay, and ACH surface based on the foundation's Stripe dashboard settings.
- Webhook pipeline handling `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `charge.refunded`, and `payment_intent.payment_failed`; signatures verified with a 300-second tolerance; duplicate deliveries idempotent via the `stripe_events` table.
- Staff notification email sent via Symfony Mailer on every successful donation; donor receipts sent directly by Stripe.
- Nexcess managed-WordPress deployment kit (install script, `.htaccess` shims, WordPress mu-plugin that shares `.env` for SMTP credentials).
- Audience-separated documentation set: `README.md`, `docs/USER.md`, `docs/ADMIN.md`, `docs/CONTRIBUTING.md`, plus `ROADMAP.md`, `LICENSE`, `TRIBUTE.md`.

### Changed

- Donation metrics now reflect paid-only transactions (`status = 'paid'`); refunded and failed rows are excluded so the dashboard shows actual revenue rather than attempts.
- Documentation restructured by audience (donor, operator, developer) so no document serves more than one reader at a time.
- SMTP configuration accepts either a pre-formed `SMTP_DSN` or discrete `SMTP_HOST` / `SMTP_PORT` / `SMTP_USERNAME` / `SMTP_PASSWORD` / `SMTP_ENCRYPTION` components; components are safer because special characters in the password do not require URL-encoding.
- Front controller is now subpath-aware: the router strips the path prefix derived from `APP_URL` so deployments at `https://host/donation` route correctly.

### Fixed

- Payment correctness via webhook-first processing: the browser-facing success page is advisory only; every donation record is driven by a verified webhook event.
- Error handling and input validation hardened &mdash; CSRF tokens are rotated on successful use, amount parsing rejects scientific notation / locale separators / non-numeric input, and mail-header values reject CR/LF injection.
- Removed a dead write to `$_SESSION['pending_order']` in the checkout handler; the value was never read and its absence has no observable effect.

### Security

- CSRF protection on `/admin/config` POST using the same per-session token utility the donation form uses.
- Hardened Basic Auth header parsing: strict `Basic <base64>` regex, case-insensitive scheme match, trim of surrounding whitespace, and a try/catch around the fallback parser so malformed headers fail closed instead of raising.
- PCI-DSS scope reduced to **SAQ-A** by moving all card entry to Stripe Checkout.
- Strict Content Security Policy with a per-request script nonce.
- Prepared statements only; no string-concatenation SQL anywhere in the codebase.
- Idempotent webhook event handling prevents duplicate Stripe deliveries from double-recording a donation.

---

## Development-history notes

The `master` branch preserves a legacy-import commit (`c86e559`) and a breaking-change removal commit (`2b0b702`) that together record the import and removal of the pre-rebuild codebase. They are intentionally retained so that the security rationale for the rebuild is auditable from the history itself. The 1.0.0 release is not derived from those trees; it is a ground-up rewrite.

[1.0.0]: https://github.com/jwogrady/ndasa-donation/releases/tag/v1.0.0
