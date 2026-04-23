# Administrator Guide

This document is for the webmaster or operator responsible for running the NDASA donation platform. It covers installation, configuration, Stripe setup, mail, security, and operations.

If you are a donor, see [USER.md](USER.md). If you are working on the codebase, see [CONTRIBUTING.md](CONTRIBUTING.md).

## Prerequisites

- **PHP 8.2 or newer** with these extensions: `pdo_sqlite`, `openssl`, `mbstring`, `curl`.
- **Composer 2.x** for dependency management.
- **A Stripe account**, with both test-mode and live-mode API keys.
- **An SMTP account** for staff notification email (the application uses Symfony Mailer and accepts any DSN-compatible transport).
- **A web server** serving PHP through PHP-FPM (nginx or Apache 2.4 both work). The document root must be pointed at `public/` &mdash; the rest of the repository must not be web-reachable.

## Deployment

There are two supported deployment shapes:

### 1. Its own virtual host or subdomain

If you control the web server configuration, point the document root at `public/`. Example nginx block:

```nginx
server {
    listen 443 ssl http2;
    server_name ndasafoundation.org;
    root /var/www/ndasa-donation/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    # Explicit webhook route — no rewrite, no session.
    location = /webhook.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/webhook.php;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }

    # Belt and suspenders.
    location ~ /\.(env|git) { deny all; return 404; }
}
```

For Apache, an `.htaccess` in `public/` handles rewriting and denies dotfiles and sensitive extensions. Set the virtual host document root to `public/` and ensure `AllowOverride All`.

### 2. Subpath under an existing WordPress install (Nexcess)

If the donation app has to live alongside a WordPress site on managed hosting, use the deployment kit at [deploy/](../deploy/). It installs the app into a hidden `public_html/.ndasa-donation/` directory (denied by `.htaccess`), puts two shim files at `public_html/donation/`, and installs a WordPress must-use plugin at `wp-content/mu-plugins/ndasa-shared-env.php` that shares the same `.env` for SMTP credentials.

The kit is tested specifically against Nexcess managed WordPress hosting (PHP-FPM 8.3 inside a chroot). See [deploy/README.md](../deploy/README.md) for the step-by-step install process, including pre-install cleanup of any existing legacy donation directory.

## Environment variables

The application reads configuration exclusively from environment variables, loaded from a `.env` file in the repository root (or, for the Nexcess deployment, from `public_html/.ndasa-donation/.env`). Start from [.env.example](../.env.example).

### Required

| Variable | Purpose |
|---|---|
| `APP_URL` | Public origin of the donation app, including any subpath (e.g. `https://ndasafoundation.org/donation`). Used to build Stripe return URLs; the router strips this prefix from incoming paths automatically. |
| `DB_PATH` | Absolute path to the SQLite file. Must be outside the web root and writable by the PHP-FPM user. |
| `MAIL_FROM` | Address that staff notifications are sent from. |
| `MAIL_BCC_INTERNAL` | Address that receives a notification for each completed donation. |

Plus at least one Stripe key/webhook pair for the currently active mode (see **Stripe configuration** below).

### SMTP &mdash; optional; falls back to local sendmail if absent

If neither `SMTP_DSN` nor `SMTP_HOST` is set, the mailer uses Symfony's `sendmail://default` transport (the same path PHP's native `mail()` uses), which routes through the local MTA. On managed hosts with a working local MTA (Nexcess, most cPanel) that is deliverable without further setup. Set SMTP env vars only when you want to route through a specific relay.

Either set the discrete components:

| Variable | Example |
|---|---|
| `SMTP_HOST` | `secure.emailsrvr.com` |
| `SMTP_PORT` | `587` |
| `SMTP_ENCRYPTION` | `tls` (STARTTLS on 587) or `ssl` (implicit TLS on 465) |
| `SMTP_USERNAME` | `admin@ndasafoundation.org` |
| `SMTP_PASSWORD` | (plaintext; any characters are safe) |

Or a pre-formed DSN that overrides the components:

| `SMTP_DSN` | `smtp://user:URL_ENCODED_PASS@host:587` or Symfony Mailer provider DSN (`sendgrid+api://KEY@default`, `ses+api://…`) |

Component form is preferred over `SMTP_DSN` because the password does not need URL-encoding.

### Admin panel &mdash; required, always

| Variable | Purpose |
|---|---|
| `ADMIN_USER` | HTTP Basic Auth username for every `/admin*` route. |
| `ADMIN_PASS` | HTTP Basic Auth password. If either is empty, `/admin*` returns 500 rather than exposing the panel unauthenticated. |

### Optional

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `production` | Set to anything other than `production` to disable HTTPS enforcement in local/test environments. |
| `APP_TIMEZONE` | `UTC` | PHP `date_default_timezone_set()` value. |
| `APP_VERSION` | `1.0.0` | Explicit version string shown in the admin footer. Ships as the current release tag; override per-environment or clear to fall back to the short git hash and then to the hardcoded constant. |
| `SESSION_NAME` | `ndasa_sess` | PHP session cookie name. |
| `DONATION_MIN_CENTS` | `1000` | Minimum accepted donation in cents. |
| `DONATION_MAX_CENTS` | `1000000` | Maximum accepted donation in cents. |
| `TRUSTED_PROXIES` | empty | Comma-separated CIDRs / IPs of reverse proxies whose `X-Forwarded-For` header may be trusted. Leave empty if the app is directly connected. **Never** use a wildcard. |
| `MAIL_FROM_NAME` | `NDASA Foundation` | Display name on outgoing staff notifications. |

The bootstrap aborts with a 500 at startup if any **Required** variable is missing or empty, or if the credentials for the currently active Stripe mode are absent. SMTP has no required form — the mailer defaults to local `sendmail://default` when nothing is configured. Missing `ADMIN_USER` or `ADMIN_PASS` does not abort startup but does take the admin panel offline.

### File permissions

- `.env`: `chmod 600`, owned by the PHP-FPM user.
- `storage/`: `chmod 700`, owned by the PHP-FPM user. Contains the SQLite database and application log.

## Stripe configuration

### API keys

The app stores **two pairs** of Stripe credentials side by side:

- `STRIPE_LIVE_SECRET_KEY` + `STRIPE_LIVE_WEBHOOK_SECRET` (from the dashboard's **Live mode** toggle)
- `STRIPE_TEST_SECRET_KEY` + `STRIPE_TEST_WEBHOOK_SECRET` (from **Test mode**)

Which pair is active at any moment is controlled by the **Stripe Mode** panel on the admin dashboard. Live mode is the default; test mode must be explicitly selected.

For compatibility with older installs, `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` (without the `_LIVE_` / `_TEST_` prefix) are still honored as a fallback for live mode — so a pre-upgrade `.env` keeps working. Newly provisioned `.env` files should use the explicit `_LIVE_` / `_TEST_` names.

### Switching modes

From `/admin`, click **Switch to TEST** or **Switch to LIVE** in the Stripe Mode panel. The flip is instant: the next `/checkout` request uses the selected pair. The button is disabled when the target mode's credentials are missing from `.env`, so the toggle cannot break an already-working mode.

When test mode is active, a diagonal amber caution banner appears above the donor form ("Test mode active — payments are simulated"). That banner is the donor-visible signal that no real card will be charged.

Every mode change writes an audit line to `storage/logs/app.log` in the form `stripe_mode live -> test by admin '<username>'`.

### Webhook endpoint

In the Stripe dashboard &rarr; **Developers** &rarr; **Webhooks** &rarr; **Add endpoint** (one endpoint per mode — live and test each need their own):

- **URL:** `<APP_URL>/webhook.php` (for example, `https://ndasafoundation.org/donation/webhook.php`)
- **API version:** match the pin in [config/app.php](../config/app.php). The current pin is `2026-03-25.dahlia`. Keep them aligned; reviewing this pin before upgrading is a deliberate step.
- **Events** (all eight are required — subscription events are needed even if you are not recurring-enabled yet, because the handler is a no-op when they do not apply):
  - `checkout.session.completed`
  - `checkout.session.async_payment_succeeded`
  - `checkout.session.async_payment_failed`
  - `charge.refunded`
  - `payment_intent.payment_failed`
  - `invoice.paid`
  - `invoice.payment_failed`
  - `customer.subscription.deleted`

Copy the generated `whsec_...` secret into `STRIPE_LIVE_WEBHOOK_SECRET` (or `STRIPE_TEST_WEBHOOK_SECRET` for the test-mode endpoint) in `.env`.

### Payment methods

The Checkout session sends `payment_method_types: ['card']` explicitly. The NDASA Foundation Stripe account does not have the dashboard-side payment-method configuration that `automatic_payment_methods` requires, so requests using the automatic mode are rejected with `parameter_unknown`. Expand the list in [src/Payment/DonationService.php](../src/Payment/DonationService.php) to add methods (e.g. Link, ACH Direct Debit) as needed; each addition requires a Stripe dashboard config check first.

### Testing

Before going live, do at least one end-to-end test with test-mode keys:

1. Make a test-mode donation with the Stripe test card `4242 4242 4242 4242`.
2. Verify the success page renders, the donation appears in the Stripe dashboard, and a row is inserted into the donations ledger.
3. Verify the staff notification email arrives at `MAIL_BCC_INTERNAL`.
4. Verify the donor-facing Stripe receipt email arrives.

For asynchronous methods (ACH), use Stripe's test bank details and allow up to a few minutes for `async_payment_succeeded` to fire.

## Mail

Donor receipts are sent by **Stripe** directly, using the `receipt_email` on the PaymentIntent. This application does not send donor-facing mail.

Staff notifications are sent by this application on every successful donation, using Symfony Mailer. The transport is configured via the SMTP variables above. Internal notification failures are caught and logged; they do **not** cause Stripe to retry the webhook, because the donation has already been recorded.

The staff email contains the order ID, donation amount and currency, donor name, and donor email. It does **not** contain payment-card information (the application never has access to it).

## Source of truth

The Stripe webhook is the system of record for every donation. The browser-facing success page looks up its status via `Stripe\Checkout\Session::retrieve()` purely for the donor's confirmation message; reconciliation, reporting, and staff notification all run from verified webhook events. **The admin dashboard reads only from the local SQLite ledger; it never queries the Stripe API for metrics.**

For each Stripe event, the application:

1. Verifies the `Stripe-Signature` header with a 300-second tolerance. The signature is checked against **both** configured secrets (live and test) in order, so retries that cross a mode toggle still validate. Replays outside the 300 s tolerance are rejected with a 400.
2. Checks `stripe_events` for the event ID. If already recorded, returns 200 immediately (idempotent ack). If not, runs the handler first and only then inserts into `stripe_events` — a transient handler failure returns 500 so Stripe retries instead of silently deduping a failed event.
3. For `checkout.session.completed` with `payment_status = paid` (and no `subscription`), records a row in the donations ledger tagged with `livemode` from the event, and sends the staff notification. For sessions that arrive unpaid (ACH etc.), `checkout.session.async_payment_succeeded` does the same later.
4. For `charge.refunded`, flips the matching donation's status to `refunded` and sets `refunded_at`.
5. For `invoice.paid` (subscription charge), writes a row keyed by `inv_<invoice_id>`. The first invoice of a new subscription is deduped against the signup `checkout.session.completed` via the subscription's `metadata.order_id`.
6. For `invoice.payment_failed`, logs the decline; Stripe's own dunning drives retries.
7. For `customer.subscription.deleted`, marks any still-pending rows tied to that subscription as cancelled (paid rows are untouched).
8. For `checkout.session.async_payment_failed` or `payment_intent.payment_failed`, logs and takes no further action.

## Rate limiting

The `/checkout` POST handler applies a **5-requests-per-minute per-IP** limit, backed by a short SQLite transaction (select-then-insert-or-update; Nexcess managed WP ships SQLite 3.7.17, which predates UPSERT). An exceeded limit returns a `429` with a friendly error page. The limit is designed to blunt card-testing attacks and should not affect legitimate donors.

The "per-IP" identifier is resolved through `ClientIp`, which trusts `X-Forwarded-For` only if the direct peer address is listed in `TRUSTED_PROXIES`. Set `TRUSTED_PROXIES` to the CIDR or IP of your CDN or load balancer. If the app is directly connected, leave it empty.

## Storage and reconciliation

The application uses a single SQLite database at `DB_PATH`. On first connect the schema is created automatically; subsequent upgrades extend it via idempotent `ALTER TABLE ADD COLUMN` probes (SQLite 3.7.17-safe, no `RENAME COLUMN` or `DROP COLUMN`).

Tables:

- **`donations`** &mdash; one row per completed donation (or recurring invoice). Keyed by `order_id` (PK) and `payment_intent_id` (UNIQUE). Fields: `amount_cents`, `currency`, `email`, `contact_name`, `status` (`paid` / `refunded` / `cancelled`), `created_at`, optional `refunded_at`, `dedication`, `email_optin` (1/0/null), `interval` (`month`/`year`/null), `stripe_subscription_id`, `stripe_customer_id`, `livemode` (1 live / 0 test).
- **`stripe_events`** &mdash; idempotency + heartbeat log keyed by Stripe event ID. Fields: `type`, `received_at`. The admin dashboard uses `MAX(received_at)` as the "Last Webhook" heartbeat tile.
- **`rate_limit`** &mdash; fixed-window counters keyed by `"checkout:<ip>"`.
- **`page_views`** &mdash; one row per GET to the donation page (throttled, see Metrics below). Fields: `id`, `created_at`.
- **`admin_audit`** &mdash; append-only log of privileged admin actions. Fields: `actor`, `action`, `detail`, `created_at`. Config saves log changed-key names only; secret values are never written here.
- **`app_config`** &mdash; runtime flags that need to flip without a PHP-FPM reload. Today holds the live/test Stripe mode toggle (`stripe_mode`).

Indexes: `idx_donations_created_at`, `idx_donations_status`, `idx_donations_subscription`, `idx_donations_livemode_created_at`, `idx_donations_livemode_status`, `idx_page_views_created_at`, `idx_admin_audit_created_at`.

For reconciliation, compare the local `donations` table (filtered to `livemode = 1` for real-money only) against Stripe's own dashboard or a Stripe report. Any live Stripe charge without a corresponding local row is a processing miss; `bin/stripe-import.php` can backfill it (see **Backfill from Stripe** below).

SQLite is configured with WAL journalling, enforced foreign keys, and a 5-second busy timeout. PDO uses real prepared statements (no string-concat SQL anywhere).

### Backfill from Stripe

`bin/stripe-import.php` reads the Stripe API directly (sessions, invoices, charges) and writes matching rows through the same `EventStore::recordDonation` path the webhook uses, so schemas cannot drift between the two. Typical recovery after a webhook outage:

```sh
cd /path/to/ndasa-donation
php bin/stripe-import.php --mode=live --from=YYYY-MM-DD --dry-run --verbose   # preview
php bin/stripe-import.php --mode=live --from=YYYY-MM-DD --yes                 # commit
```

The script is idempotent — re-running against the same window is a no-op. `--mode=live|test` is required (no default). `--from` and `--to` accept YYYY-MM-DD in `APP_TIMEZONE`. Foreign Stripe objects (WPForms sessions, non-subscription one-off invoices) are counted separately from inserts and skips. Refunds are applied only to charges whose `payment_intent_id` matches a local row.

## Admin panel

The admin panel lives at `/admin`. All `/admin*` routes are gated by a single HTTP Basic Auth check that reads `ADMIN_USER` and `ADMIN_PASS` from the environment. The gate supports both the standard `$_SERVER['PHP_AUTH_USER']` pair and the `HTTP_AUTHORIZATION` fallback (for FastCGI / LiteSpeed setups that strip `PHP_AUTH_*`). Credentials are compared in constant time.

Top-level pages (all filter by the admin's active Stripe mode, except page-view counts which are traffic-level and unfiltered):

- **`/admin`** — Dashboard. Four scalar stats (Total Donations, Total Donors, Page Views, Conversion Rate), a pulse row with Last Webhook heartbeat / Active Recurring commitment / 30-day sparkline / 30-day refund rate, a repeat-donors table when any donor has more than one paid gift, a 10-row Recent Donations table, the Admin Activity log, and a grouped System Health panel.
- **`/admin/transactions`** — Paginated transactions index. Filters: email substring, status (paid/refunded/cancelled/any), date range (from/to). Page-size dropdown: 25 / 50 / 100 / 500. Rows link to the per-donation detail page.
- **`/admin/subscriptions`** — Paginated subscriptions index, one row per `stripe_subscription_id` with derived status from the most recent invoice.
- **`/admin/subscriptions/{sub_id}`** — Subscription detail. Calls `Stripe\Subscription::retrieve()` for authoritative live status (current period, `cancel_at`, `cancel_at_period_end`), lists every invoice row linked to that subscription.
- **`/admin/donors`** — Paginated donors index, one row per unique lowercased email with lifetime giving, first-gift date, last-gift date.
- **`/admin/donors/{sha256(email)}`** — Donor detail. Identity header (name + mailto + opt-in state + lifetime total), any subscriptions with links to their detail page, every donation with a Stripe hosted-receipt URL fetched lazily from the Charge. Donor URLs use SHA-256 so email identifiers do not land in access logs or browser history.
- **`/admin/donations/{order_id}`** — Donation detail with mode-aware Stripe dashboard deep links for the PaymentIntent and Subscription.
- **`/admin/export`** — CSV export with optional `from` / `to` (YYYY-MM-DD). Filename embeds `-live-` or `-test-` so a test-mode export can never be mistaken for live accounting after download. Streams `text/csv`.
- **`/admin/config`** — Atomic `.env` config editor covering the operationally-relevant env vars (Stripe keys and webhook secrets for both modes, `APP_URL`, `MAIL_*`, `SMTP_*`, `DONATION_MIN_CENTS` / `MAX_CENTS`, `APP_TIMEZONE`, `SESSION_NAME`). CSRF-protected. Values are validated per-field, written to `.env.tmp`, then `rename()`d onto `.env` so a mid-write crash cannot leave a truncated file that bricks the fail-closed bootstrap.

**Restart caveat.** The admin form updates `.env` on disk, but the running PHP-FPM workers have already loaded the old values into `$_ENV` at request-start and will continue to use them for bootstrap-driven settings. A PHP-FPM reload is required for new Stripe keys, timezone, or CSP-affecting values to take effect. The live/test mode toggle is an exception — it lives in the `app_config` SQLite table and flips on the very next request, no reload needed.

## Metrics

All dashboard numbers are sourced from the local SQLite ledger. Except where noted, every metric filters by `livemode` against the admin's currently active Stripe mode, so flipping the toggle swaps the view without mingling test runs with real revenue.

Scalar stats:

- **Page views** &mdash; count of rows in `page_views`. Each GET to `/` inserts one row, **throttled to at most one record per 30 seconds per session**. Not filtered by Stripe mode — it is a traffic metric independent of payment mode.
- **Total Donations** &mdash; sum of `amount_cents` for rows with `status = 'paid'` in the current mode. Refunded, pending, and failed rows are excluded.
- **Total Donors** &mdash; count of distinct lowercase `email` values among paid donations in the current mode.
- **Conversion Rate** &mdash; `paid-donation-count / page-views * 100`, rounded to one decimal place. Zero page views yields 0%.

Pulse tiles:

- **Last Webhook** &mdash; `MAX(received_at)` from `stripe_events`, unfiltered (webhook ingest is pipeline health, not a mode signal). Age-bucketed: green <1 h, amber <1 d, red >1 d, grey never.
- **Active Recurring** &mdash; sum of the most-recent invoice amount per active subscription, normalized to a monthly number (yearly plans divide by 12). Active = most-recent row's status is `paid`.
- **Last 30 Days** &mdash; inline-SVG polyline of daily donation totals for the last 30 calendar days in `APP_TIMEZONE`, with zero-day backfill so the line is continuous.
- **Refund Rate (30d)** &mdash; `COUNT(refunded_at in window) / COUNT(created_at in window) * 100`. Ok < 2%, warn 2–5%, bad ≥ 5%.

Tables:

- **Repeat Donors** (rendered only when at least one donor has >1 paid gift) &mdash; top 10 by lifetime giving. Links to the donor detail page.
- **Recent Donations** &mdash; the ten most recent rows from `donations`, all statuses included. The status column is where operators see refund activity. Each row links to the per-donation detail page.

Each aggregate is memoised per request, so the full dashboard render produces at most one query per distinct aggregate.

## System Health

The dashboard renders three grouped panels:

- **Database** &mdash; connection, presence of the `donations` / `page_views` / `stripe_events` tables, and presence of both expected indexes.
- **Environment** &mdash; `.env` is writable, the SQLite file at `DB_PATH` is writable (or its parent directory is writable for first-run creation), and `storage/logs/` is writable.
- **Configuration** &mdash; each of `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `APP_URL` is non-empty.

Every probe is wrapped in a try/catch; a broken check reports FAIL with a human-readable detail string rather than crashing the page.

## Known limitations

- **Page-view counts are approximate.** The 30-second session throttle eliminates refresh inflation but does not filter bots that reject cookies (each request looks like a fresh session), nor bots that spoof unique sessions for every request. Treat the page-view figure as directional.
- **No bot filtering** beyond the session throttle. There is no user-agent allowlist, no CAPTCHA, no third-party bot-detection service integrated. Stripe Radar handles the actual fraud side.
- **No Stripe analytics sync.** The dashboard reads the local DB only. Metrics in the admin panel and in the Stripe dashboard can drift if a webhook delivery was dropped and never retried successfully. `bin/stripe-import.php` (see **Backfill from Stripe** above) is the repair tool; the Last Webhook heartbeat on the dashboard is the early-warning signal.
- **Admin config changes are not live until PHP-FPM reloads** for bootstrap-driven settings (Stripe keys, timezone, CSP). The live/test Stripe mode toggle is the exception — it flips on the next request with no reload.
- **SQLite-backed rate limiter** is adequate for a single-host deployment. Multi-host or CDN-fronted deployments need a shared backend.
- **Staff notification failures** are logged and swallowed; an SMTP outage during a donation surge silently drops notifications for the outage window (donations themselves are still recorded).
- **Single-tenant.** Scoped to the NDASA Foundation; multi-tenant support is not in the current design.
- **Donor identity is the email address.** Case-insensitive equality is the de-dupe rule. A donor who gives from two different email addresses appears as two separate donors.

Direct shell access to the ledger, when needed:

```sh
sqlite3 $DB_PATH 'SELECT order_id, amount_cents, email, status, datetime(created_at,"unixepoch") FROM donations ORDER BY created_at DESC LIMIT 20;'
```

## Security model

- **No card data ever enters the application.** Card entry happens on `checkout.stripe.com`. The app handles only the Stripe session ID and webhook events.
- **Fail-closed configuration.** Missing required secrets abort startup with a generic 500.
- **CSRF.** Every state-changing POST goes through a CSRF check; tokens are rotated on successful use.
- **Webhook signature verification** with a 300 s tolerance.
- **Idempotent event handling** via the `stripe_events` table.
- **Prepared statements only.**
- **Security headers** &mdash; HSTS (two years, preload), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `X-Frame-Options: DENY`, and a Content Security Policy with a per-request script nonce. Script tags that need to run inline must carry the `nonce` attribute from `NDASA_CSP_NONCE`.
- **HTTPS enforced** in production via 301 redirect on plain-HTTP GETs, honoring `HTTP_X_FORWARDED_PROTO` behind proxies.
- **Hardened sessions** &mdash; secure, HttpOnly, `SameSite=Lax`, strict mode, cookies only.
- **Mail-header-injection defence** &mdash; CRLF characters rejected in any value destined for an email header.

## Operations

### Logs

PHP errors log to `storage/logs/app.log` (path configurable in `config/app.php`). The application intentionally does **not** display errors to users; treat any 500 response as an event worth inspecting in the log.

Structured entries worth watching:

- `Webhook: invalid/malformed payload` &mdash; something is hitting `/webhook.php` that is not a valid Stripe payload. Could be a Stripe API-version mismatch, a misconfigured endpoint, or a probe.
- `Webhook: signature failed` &mdash; either a bad secret or a genuine replay attempt. Rotate the signing secret if unexplained.
- `Incomplete paid session` &mdash; Stripe posted a paid session but one of the expected fields was empty. Rare; inspect the matching event in the Stripe dashboard.
- `Internal notification failed for order …` &mdash; the donation is recorded but the staff email did not send. Check the SMTP transport health.
- `Webhook: async payment failed for session …` &mdash; an ACH or similar pull failed. The donor gets no email from us; Stripe may retry.

### Stripe webhook retries

When the application returns a non-2xx to a webhook delivery, Stripe automatically retries on an exponential schedule for up to three days. An event that successfully reaches us once is recorded in `stripe_events`; any later duplicate delivery (replay by Stripe, a CLI forward re-run, etc.) is detected and ignored.

### Rolling the Stripe API version

The version is pinned in [config/app.php](../config/app.php) as `\Stripe\Stripe::setApiVersion('2026-03-25.dahlia')`. Before changing it:

1. Review the Stripe changelog for every version between the current pin and the target.
2. Update the webhook endpoint in the Stripe dashboard to the new version.
3. Deploy the code change that updates the pin.

The two must match. If they diverge, the webhook may receive payload shapes the code does not handle; the signature will still verify, but fields may be missing.

### Rolling the Stripe secret key

To rotate `STRIPE_SECRET_KEY` without downtime, use the Stripe dashboard's **"Roll key"** feature, which keeps the old key active for a grace period while the new one is installed. Update `.env`, reload PHP-FPM, then expire the old key in the dashboard.

### Rolling the webhook signing secret

Adding a new endpoint with the new secret, waiting one Stripe retry window, and then deleting the old endpoint is the zero-downtime path. Alternatively, the dashboard supports "roll signing secret" on a single endpoint; the old secret accepts events for 24 hours after the roll.

## Updating

To deploy a new version of the application:

1. Pull or rsync the new code into place (or, for Nexcess, re-run `./deploy/install.sh` from an updated repo checkout; it performs a timestamped backup, rescues `.env` and `storage/`, and restores them into the fresh install).
2. Run `composer install --no-dev --optimize-autoloader`.
3. If the Stripe API version pin has changed, update the endpoint in the Stripe dashboard to match before cutting over.
4. Reload PHP-FPM to pick up any opcache changes.
5. Smoke-test with a test-mode donation before declaring the deploy complete.

`.env`, the SQLite database, WAL/SHM journals, and `storage/logs/` are rescued before the old install is renamed and restored into the fresh install. A staged `storage.safety-copy/` inside the backup dir also survives mid-install failure; it is removed only after the restore confirms a non-empty `donations.sqlite` in the new install.

## Rollback

If a deploy goes wrong:

1. Identify the newest snapshot in `~/backups/ndasa-donation/` (or your `BACKUP_ROOT`), named `.ndasa-donation.bak-YYYYMMDD-HHMMSS` and `donation.bak-YYYYMMDD-HHMMSS`.
2. Move them back into place:
   ```sh
   mv ~/public_html/.ndasa-donation                  ~/backups/ndasa-donation/.ndasa-donation.bad
   mv ~/backups/ndasa-donation/.ndasa-donation.bak-<TAG>  ~/public_html/.ndasa-donation
   mv ~/public_html/donation                         ~/backups/ndasa-donation/donation.bad
   mv ~/backups/ndasa-donation/donation.bak-<TAG>         ~/public_html/donation
   ```
3. Run `composer install --no-dev --optimize-autoloader` against the restored tree if composer.lock differs.
4. Reload PHP-FPM.
5. Inspect `storage/logs/app.log` for any entries that correspond to the failed deploy window.

If the failed install aborted mid-rescue and printed a `storage.safety-copy` path, copy its contents into `~/public_html/.ndasa-donation/storage/` to restore runtime data.

No database migration is required for rollbacks; the schema is append-only across releases.

## Backup hygiene

Every `install.sh` run leaves a timestamped backup pair in `~/backups/ndasa-donation/` (overridable via `BACKUP_ROOT`). Prune old ones with `deploy/prune-backups.sh`, run from your **repo checkout** (not the deployed app — `install.sh` deliberately excludes `deploy/` from what it copies into `public_html/`):

```sh
cd ~/apps/ndasa-donation     # your repo checkout, wherever you cloned it
./deploy/prune-backups.sh                                   # dry-run, keep 3 per series
./deploy/prune-backups.sh --keep 5 --older-than 14 --execute
```

The script retains each series independently (`.ndasa-donation.bak-*` and `donation.bak-*` counted separately), refuses to touch anything whose name doesn't match the strict `YYYYMMDD-HHMMSS` timestamp pattern, and double-checks the parent directory immediately before every `rm -rf`. See `./deploy/prune-backups.sh --help` for the full option list.

For a one-time sweep of legacy snapshots that older installer versions placed directly under `public_html/`, point `BACKUP_ROOT` at that directory:

```sh
cd ~/apps/ndasa-donation
BACKUP_ROOT=$HOME/public_html ./deploy/prune-backups.sh --keep 0 --execute
```

