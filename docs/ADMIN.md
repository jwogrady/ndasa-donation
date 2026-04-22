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
| `STRIPE_SECRET_KEY` | Stripe API secret (`sk_live_...` in production, `sk_test_...` for testing). |
| `STRIPE_WEBHOOK_SECRET` | The `whsec_...` signing secret for the webhook endpoint. See Webhook setup below. |
| `DB_PATH` | Absolute path to the SQLite file. Must be outside the web root and writable by the PHP-FPM user. |
| `MAIL_FROM` | Address that staff notifications are sent from. |
| `MAIL_BCC_INTERNAL` | Address that receives a notification for each completed donation. |

### SMTP &mdash; required, one of these two forms

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

Component form is preferred because the password does not need URL-encoding.

### Optional

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `production` | Set to anything other than `production` to disable HTTPS enforcement in local/test environments. |
| `APP_TIMEZONE` | `UTC` | PHP `date_default_timezone_set()` value. |
| `SESSION_NAME` | `ndasa_sess` | PHP session cookie name. |
| `DONATION_MIN_CENTS` | `1000` | Minimum accepted donation in cents. |
| `DONATION_MAX_CENTS` | `1000000` | Maximum accepted donation in cents. |
| `TRUSTED_PROXIES` | empty | Comma-separated CIDRs / IPs of reverse proxies whose `X-Forwarded-For` header may be trusted. Leave empty if the app is directly connected. **Never** use a wildcard. |
| `MAIL_FROM_NAME` | `NDASA Foundation` | Display name on outgoing staff notifications. |

The bootstrap aborts with a 500 at startup if any **Required** variable is missing or empty. For SMTP, at least one of `SMTP_HOST` or `SMTP_DSN` must be set.

### File permissions

- `.env`: `chmod 600`, owned by the PHP-FPM user.
- `storage/`: `chmod 700`, owned by the PHP-FPM user. Contains the SQLite database and application log.

## Stripe configuration

### API keys

Use **test-mode** keys (`sk_test_...`) for staging and development. Use **live-mode** keys (`sk_live_...`) only in production. Never commit either.

### Webhook endpoint

In the Stripe dashboard &rarr; **Developers** &rarr; **Webhooks** &rarr; **Add endpoint**:

- **URL:** `<APP_URL>/webhook.php` (for example, `https://ndasafoundation.org/donation/webhook.php`)
- **API version:** match the pin in [config/app.php](../config/app.php). The current pin is `2026-03-25.dahlia`. Keep them aligned; reviewing this pin before upgrading is a deliberate step.
- **Events:**
  - `checkout.session.completed`
  - `checkout.session.async_payment_succeeded`
  - `checkout.session.async_payment_failed`
  - `charge.refunded`
  - `payment_intent.payment_failed`

Copy the generated `whsec_...` secret into `STRIPE_WEBHOOK_SECRET` in `.env`.

### Payment methods

The Checkout session requests `automatic_payment_methods: enabled`. Which methods actually appear to donors is controlled entirely by the Stripe dashboard (**Settings** &rarr; **Payment methods**). Enable or disable Card, Link, Apple Pay, Google Pay, and ACH Direct Debit there. For Apple Pay you must also verify the donation domain in the dashboard.

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

The Stripe webhook is the system of record for every donation. The browser-facing session contains a non-sensitive "pending order" hint used only to improve the donor's confirmation page experience; it is not used for reconciliation, reporting, or any financial purpose.

For each Stripe event, the application:

1. Verifies the `Stripe-Signature` header with a 300-second tolerance. Replays outside the window are rejected with a 400.
2. Inserts the event ID into `stripe_events` using `INSERT OR IGNORE` and checks the row count. Duplicate deliveries are acknowledged without re-processing.
3. For `checkout.session.completed` with `payment_status = paid`, records a row in the donations ledger and sends the staff notification. For sessions that arrive unpaid (e.g. ACH), `checkout.session.async_payment_succeeded` does the same later.
4. For `charge.refunded`, updates the corresponding donation's status and sets `refunded_at`.
5. For `checkout.session.async_payment_failed` or `payment_intent.payment_failed`, logs and takes no further action.

## Rate limiting

The `/checkout` POST handler applies a **5-requests-per-minute per-IP** limit, backed by a single SQLite atomic UPSERT. An exceeded limit returns a `429` with a friendly error page. The limit is designed to blunt card-testing attacks and should not affect legitimate donors.

The "per-IP" identifier is resolved through `ClientIp`, which trusts `X-Forwarded-For` only if the direct peer address is listed in `TRUSTED_PROXIES`. Set `TRUSTED_PROXIES` to the CIDR or IP of your CDN or load balancer. If the app is directly connected, leave it empty.

## Storage and reconciliation

The application uses a single SQLite database at `DB_PATH`. On first connect the schema is created automatically with three tables:

- **`donations`** &mdash; one row per completed donation. Keyed by `order_id` (PK) and `payment_intent_id` (UNIQUE). Fields: amount in cents, currency, donor email and name, status, created timestamp, optional refunded timestamp.
- **`stripe_events`** &mdash; idempotency log keyed by Stripe event ID. Fields: event type, received timestamp.
- **`rate_limit`** &mdash; fixed-window counters keyed by `"checkout:<ip>"`.

For reconciliation, compare the local `donations` table against Stripe's own dashboard or a Stripe report. Any live Stripe charge without a corresponding local row is a processing miss and should be investigated (usually a webhook delivery failure).

SQLite is configured with WAL journalling, enforced foreign keys, and a 5-second busy timeout. PDO uses real prepared statements (no string-concat SQL anywhere).

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

1. Pull or rsync the new code into place (or, for Nexcess, re-run `./deploy/install.sh`, which backs up the current install and replaces it).
2. Run `composer install --no-dev --optimize-autoloader`.
3. If the Stripe API version pin has changed, update the endpoint in the Stripe dashboard to match before cutting over.
4. Reload PHP-FPM to pick up any opcache changes.
5. Smoke-test with a test-mode donation before declaring the deploy complete.

`.env`, the SQLite database, and the log directory are never overwritten by deploys.

## Rollback

If a deploy goes wrong:

1. Restore the previous code tree (the Nexcess install script keeps a `.bak-YYYYMMDD-HHMMSS` backup; a direct deploy is a `git checkout` of the previous tag).
2. Run `composer install --no-dev --optimize-autoloader` against the restored tree.
3. Reload PHP-FPM.
4. Inspect `storage/logs/app.log` for any entries that correspond to the failed deploy window.

No database migration is required for rollbacks; the schema is append-only across releases.

## Known limitations

- The rate limiter is SQLite-backed and adequate for a single-host deployment. Multi-host or CDN-fronted deployments need a shared backend.
- Staff notification failures are logged and swallowed; they do not trigger Stripe retries. An SMTP outage during a donation surge would silently drop notifications for the outage window.
- There is no built-in admin UI. All reconciliation, refunds, and donor lookups are performed through the Stripe dashboard and direct SQLite access.
- The application is single-tenant. It is scoped to the NDASA Foundation; multi-tenant support is not in the current design.
- Recurring donations (subscriptions) are out of scope for the current release.
