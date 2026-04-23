# Changelog

All notable changes to the NDASA Donation Platform are documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- **Admin console — Transactions / Subscriptions / Donors.** Three new paginated index pages in the admin nav, each with a detail drill-down. `/admin/transactions` supports email substring search, status filter, date range, and a 25/50/100/500 page-size dropdown. `/admin/subscriptions` lists one row per `stripe_subscription_id` with derived status; `/admin/subscriptions/{sub_id}` additionally calls `Stripe\Subscription::retrieve()` for authoritative live status (current period, cancel flags). `/admin/donors` lists one row per lowercased email with lifetime totals; `/admin/donors/{sha256(email)}` shows donor identity, opt-in state, every donation, any subscriptions, and lazily-fetched Stripe hosted-receipt URLs per donation. Donor URLs use a SHA-256 hash so email identifiers never land in access logs or browser history.
- **Dashboard pulse.** Four operational tiles above the recent-donations table: **Last Webhook** heartbeat with age-bucketed traffic-light borders (`<1h` / `<1d` / `>1d` / never); **Active Recurring** commitment ($/month, yearly normalized /12); **Last 30 Days** inline-SVG sparkline with zero-day backfill; **Refund Rate (30d)** with ok/warn/bad color bands. Repeat-donors panel rendered below when any donor has more than one paid gift.
- **Live/test dashboard filter.** Every donation row now carries a `livemode` flag sourced from the verified Stripe event (1 live, 0 test). Metrics, the recent table, the CSV export, the donation detail page, and all three new index pages filter by the admin's currently active mode, so flipping the toggle swaps the view without mingling test runs with real revenue. CSV filename includes `-live-` or `-test-` to prevent accounting mishaps after download.
- **Stripe API importer** at `bin/stripe-import.php`. Back-fills `donations` from Stripe (Checkout Sessions, Invoices, Charges) using `EventStore::recordDonation` — same path as the webhook so schemas can't drift. Required `--mode=live|test`; optional `--from`/`--to` (YYYY-MM-DD); `--dry-run`; `--yes` to skip the confirmation; `--verbose`. Idempotent via `INSERT OR IGNORE`. Foreign sessions (no `client_reference_id`) and non-subscription invoices are counted separately from inserts and skips so the report distinguishes "we've already got this" from "not ours."
- **Backup pruner** at `deploy/prune-backups.sh`. Dry-run by default; `--execute` required to delete. Retains per series (hidden-app + public-shim tracked independently) so `--keep 3` keeps 3 of each, not 3 interleaved. Supports `--older-than DAYS`. A belt-and-braces regex + parent-dir guard runs immediately before every `rm -rf` so a bug in the matching logic can't wipe neighbouring directories. Supports a one-time legacy sweep via `BACKUP_ROOT=$HOME/public_html` for snapshots created by older installers.
- **Recurring donations.** Frequency toggle on the donor form (One-time / Monthly / Yearly, default One-time). Monthly and yearly selections create a Stripe Subscription instead of a one-time Payment session. Fee-cover gross-up, when elected, is baked into the subscription price at signup and stays fixed for the subscription lifetime. CTA and total preview update live based on the chosen frequency.
- **Stripe Customer Portal integration.** Success page mints a Portal session for recurring donors with a "Manage or cancel this donation" link. Degrades gracefully to a contact-us message if the Portal isn't enabled in the Stripe dashboard.
- **Webhook handlers for subscriptions**: `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`. First-invoice dedupe against `checkout.session.completed` via `order_id` lookup; subsequent recurring charges use `inv_<invoice_id>` as a synthetic, deterministic order_id so retries stay idempotent.
- **Dedication field** on the donor form ("in memory / in honor of"), 200-char cap, stored in Stripe metadata and a new `donations.dedication` column. Surfaced in the staff-notification email and admin detail view; not printed on the donor receipt.
- **Newsletter opt-in checkbox** below the email field, pre-checked by default. Propagates through validator → Stripe metadata → webhook → a new `donations.email_optin` column. Visible on the admin detail view and CSV export. Consent capture only — no send path yet.
- **Clickable impact cards** on the donor form. Each `<button>` card selects the matching preset, scrolls the form into view, and updates the total preview. Default ($100) tier is visually highlighted.
- **Mobile layout reorder**: below 560px, the impact cards render right after the hero pills and before the amount picker; desktop order is the same by DOM order. Screen-reader flow is preserved.
- **Success-page "amplify" block**: share buttons (X, Facebook, LinkedIn, email) with prefilled copy. Employer-matching section now shows a generic "check with your HR department" note — no external lookup link.
- **CSV export of donations** at `/admin/export` with optional `from` / `to` date filters (YYYY-MM-DD, interpreted in `APP_TIMEZONE`). Streams `text/csv` with ISO-8601 timestamps; includes interval, subscription id, dedication, and newsletter opt-in columns.
- **Donation detail page** at `/admin/donations/{order_id}` with mode-aware deep links into the Stripe dashboard for the PaymentIntent and Subscription.
- **Admin audit log**. Append-only `admin_audit` table records config saves and Stripe mode toggles with actor, action, detail, and timestamp. Config saves log only the *changed* key names — never values, so secrets stay out of the log. Renders as an "Admin Activity" panel on the dashboard.
- **Runtime Stripe live/test mode toggle.** Persisted in a new `app_config` SQLite table; flips on the next request with no `.env` edit or PHP-FPM reload. Donor-facing amber TEST banner while in test mode.
- **Redesigned donor header** with foundation logo, wordmark, and a rotating slogan strip (50 curated slogans). Three animation modes that never repeat back-to-back. Respects `prefers-reduced-motion`, pauses while the tab is hidden.
- **Thank-you "gratitude moment"** on the success page: animated gradient heart shown only on confirmed paid.
- **Site footer** with tagline, copyright year, 501(c)(3) acknowledgement, Stripe attribution.
- **Diagnostic script** at `deploy/diagnose.sh` for read-only on-host triage.
- **Env-sync check** at `bin/check-env-sync.php` that verifies `.env.example` and `deploy/.env.template` declare the same key set. Wired into CI.
- **CI workflow** at `.github/workflows/ci.yml`: `php -l` on the tree, env-sync check, PHPUnit.

### Changed

- **Deploy backups live outside the webroot.** `install.sh` now writes snapshots to `~/backups/ndasa-donation/` (overridable via `BACKUP_ROOT`) with `chmod 700`, instead of next to WordPress. Keeps copies of `.env`, the SQLite DB, and app source out of any path that could be served or scanned by WordPress plugins. `mv` across same-filesystem paths remains atomic.
- **`install.sh` preserves runtime data across reinstalls.** Both `.env` and `storage/` (SQLite DB, WAL/SHM journals, logs) are rescued before the old install is renamed and restored into the fresh install. A staged `storage.safety-copy/` inside the backup dir survives mid-install failures; it is removed only after the restore confirms a non-empty `donations.sqlite` in the new install. On abort, the cleanup trap tells the operator the safety-copy path so recovery is a directory copy.
- **`ReceiptMailer` falls back to local `sendmail://default`** when neither `SMTP_DSN` nor `SMTP_HOST` is configured. Resolves an earlier failure mode where the webhook handler 500'd during `new ReceiptMailer()` on hosts without SMTP creds, stranding legitimate events.
- **Webhook signature verification** accepts both the live and test signing secrets, first-secret-wins. Flipping the admin mode toggle no longer strands in-flight retries signed with the previous mode's secret.
- **Webhook idempotency is two-phase.** The handler runs first; the `stripe_events` row is inserted only after success. A transient handler failure now lets Stripe retry successfully instead of silently deduping a failed event. Downstream writes remain idempotent via `INSERT OR IGNORE` on `donations.order_id`.
- **`Csrf::rotate()` regenerates the PHP session ID** (`session_regenerate_id(true)`) in addition to minting a fresh token, closing a session-fixation window.
- **Fee-cover default is "Yes"** on a fresh page load. Sticky re-renders still honor the donor's submitted value.
- **Impact-tier copy rewritten** to match the foundation's actual programs (Clothes for Orphans / Schools for Poor / Water Systems) sourced from ndasafoundation.org/about/causes/.
- **Admin dashboard metrics** collapsed from three separate donation aggregations into one SQL query (count, distinct donors, sum).
- **`HealthCheck::all()`** now returns `{groups, missing_indexes}` so the dashboard doesn't probe the index list twice.
- **Stripe credential resolution consolidated** into `AppConfig::resolveStripeCredentials(mode, env)`. Replaces three divergent copies across bootstrap, dashboard, and mode-toggle handler.
- **Admin health and required-keys checks** no longer probe the bootstrap-synthesized `$_ENV['STRIPE_SECRET_KEY']` / `STRIPE_WEBHOOK_SECRET`; they now check the source `STRIPE_LIVE_*` / `STRIPE_TEST_*` pairs for the active mode.
- **Content-Type sniff** on `/webhook`: non-`application/json` requests are rejected with 415 before hitting signature verification.
- **Checkout session** uses explicit `payment_method_types: ['card']` instead of `automatic_payment_methods` (Stripe rejects the automatic form on this account).

### Fixed

- Rate limiter uses select-then-insert-or-update inside a transaction instead of `UPSERT` / `RETURNING`. Nexcess managed WP ships SQLite 3.7.17, which predates both.
- Deploy installer: dropped a bogus publishable-key check; fixed `awk`/`tr` quoting.

### Schema

- `donations.dedication` (TEXT, nullable)
- `donations.email_optin` (INTEGER, nullable: `1` opted in / `0` opted out / NULL pre-feature)
- `donations.interval` (TEXT, nullable: `'month'` / `'year'` / NULL one-time)
- `donations.stripe_subscription_id` (TEXT, nullable)
- `donations.stripe_customer_id` (TEXT, nullable)
- `donations.livemode` (INTEGER, NOT NULL, DEFAULT 1)
- `admin_audit` table (id, actor, action, detail, created_at) + `idx_admin_audit_created_at`
- `app_config` table (key, value, updated_at)
- `idx_donations_status`, `idx_donations_subscription`, `idx_donations_livemode_created_at`, `idx_donations_livemode_status`

All migrations are idempotent and SQLite 3.7.17-safe (probe `pragma_table_info` before `ALTER TABLE ADD COLUMN`; no `RENAME COLUMN` or `DROP COLUMN`).

---

## 1.0.0 — 2026-04-21

Initial public release of the secure-rebuild donation platform. Replaces the legacy application wholesale with a webhook-authoritative design that keeps card data off the server and treats Stripe as the single source of truth.

### Added

- Donation form at `/` with preset tiers + free-form "Other"
- "Cover the processing fee" opt-in with live client-side preview and server-side `FeeCalculator` (2.9% + 30¢) as the source of truth
- Stripe Checkout integration via `DonationService` (`mode=payment`, per-order idempotency key, 30-minute TTL, `submit_type=donate`)
- Success page that reads back the Checkout Session from Stripe and renders `paid` / `unpaid` (async) / unknown states truthfully
- Webhook pipeline handling `checkout.session.completed`, `checkout.session.async_payment_{succeeded,failed}`, `charge.refunded`, `payment_intent.payment_failed`; 300-second signature tolerance; idempotency via `stripe_events`
- Staff notification email via Symfony Mailer on every paid donation; donor receipts sent by Stripe via `receipt_email`
- Admin panel at `/admin` behind HTTP Basic Auth with hardened header parsing (supports FastCGI / LiteSpeed)
- Dashboard metrics: total donations, donor count, page views, conversion rate — derived from local SQLite, no live Stripe API calls
- Recent donations table (10 rows)
- System Health checks grouped into Database / Environment / Configuration; every probe try/catch-wrapped
- Admin config editor: atomic `.env` write (temp-file + `rename()`), preserves comments and unknown keys, rejects CR/LF injection, CSRF-protected
- Page-view tracking with 30-second per-session throttle
- Version resolver: `APP_VERSION` → short git hash → hardcoded fallback; no shell-outs
- Trusted-proxy XFF resolution (CIDR-aware)
- Auto-migrating SQLite schema on connection; WAL journal, foreign keys on, 5 s busy timeout
- Nexcess managed-WordPress deploy kit: `install.sh`, `.htaccess` shims, PHP shims, `ndasa-shared-env.php` mu-plugin
- PHPUnit tests for `AmountValidator` and `ClientIp`

### Changed

- Dashboard metrics reflect paid-only rows (`status = 'paid'`); refunded and failed attempts excluded
- SMTP accepts either `SMTP_DSN` or discrete `SMTP_HOST` / `SMTP_PORT` / `SMTP_USERNAME` / `SMTP_PASSWORD` / `SMTP_ENCRYPTION` components
- Subpath-aware front controller: strips the path prefix derived from `APP_URL`
- CSRF tokens rotate on fresh renders, not on every `validate()`, so honest retries keep working

### Security

- PCI-DSS scope reduced to SAQ-A
- Strict CSP with per-request nonce; HSTS; `X-Frame-Options: DENY`; `Referrer-Policy`; `Permissions-Policy`
- Production HTTPS redirect on GET
- CSRF on all state-changing POSTs
- Amount validator rejects scientific notation / locale separators / non-numeric input
- Donor-name, email, and mail-header values reject CR/LF injection
- Prepared statements only

---

## Development-history notes

The `master` branch preserves a legacy-import commit (`c86e559`) and a breaking-change removal commit (`2b0b702`) that together record the import and removal of the pre-rebuild codebase. They are intentionally retained so the security rationale for the rebuild is auditable from git history. The 1.0.0 release is a ground-up rewrite, not derived from those trees.

[1.0.0]: https://github.com/jwogrady/ndasa-donation/releases/tag/v1.0.0
