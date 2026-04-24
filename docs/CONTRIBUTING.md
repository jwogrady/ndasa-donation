# Contributing

This document is for developers working on the NDASA donation platform. It covers the repository layout, local development, testing, coding conventions, and release discipline.

If you are a donor, see [USER.md](USER.md). If you are responsible for running the system, see [ADMIN.md](ADMIN.md).

## Repository layout

```
.
├── public/                 (document root; nothing else should be web-reachable)
│   ├── index.php           (front controller: donor routes + admin routes)
│   ├── webhook.php         (Stripe webhook entry point)
│   ├── .htaccess           (Apache hardening + rewrite rules)
│   └── assets/             (css, fonts, img)
├── config/
│   └── app.php             (env load, security headers, session, Stripe SDK init)
├── src/
│   ├── Admin/              (AppConfig, AuditLog, Auth, Diagnostics, HealthCheck, Metrics, Version)
│   ├── Http/               (Csrf, RateLimiter, ClientIp)
│   ├── Payment/            (AmountValidator, FeeCalculator, DonationService)
│   ├── Support/            (Database, Html, Slogans)
│   └── Webhook/            (WebhookController, EventStore)
├── templates/
│   ├── admin/              (layout, dashboard, diagnostics, donation, donor(s), subscription(s), transactions, _pager)
│   └── …                   (form, success, error, layout for the donation flow)
├── bin/                    (CLI: check-env-sync.php, stripe-import.php)
├── tests/                  (PHPUnit)
├── deploy/                 (Nexcess-specific install kit + prune-backups.sh; not part of the app proper)
├── storage/                (runtime: SQLite DB + logs; gitignored)
├── composer.json
├── composer.lock
├── phpunit.xml
└── .env.example
```

The `public/` tree is the only directory exposed by URL in any sensible deployment. Every other directory must live outside the web root or be protected by an `.htaccess` / `Require all denied` (see `deploy/` for the Nexcess pattern).

## Key entry points and services

These are the files most often touched by changes:

- [public/index.php](../public/index.php) &mdash; front controller. Donor routes (`GET /`, `POST /checkout`, `GET /success`) plus admin routes (`GET /admin`, `/admin/diagnostics`, `POST /admin/stripe-mode`, `GET /admin/export`, `/admin/transactions`, `/admin/subscriptions{/<sub_id>}`, `/admin/donors{/<sha256>}`, `/admin/donations/{order_id}`). Subpath-aware: strips the path prefix of `APP_URL` before matching. All `/admin*` paths pass through `AdminAuth::require()` immediately before dispatch.
- [public/webhook.php](../public/webhook.php) &mdash; verifies the Stripe signature against both live and test secrets (first-match-wins), constructs the event, and hands it to the webhook controller. Returns non-2xx only on handler failure so Stripe retries.
- [config/app.php](../config/app.php) &mdash; loads `.env`, validates required env vars, resolves Stripe credentials for the currently active mode via `AppConfig::resolveStripeCredentials()`, configures the Stripe SDK (with a pinned API version), emits security headers, starts a hardened session. Webhook handler opts out of session handling via `NDASA_SKIP_SESSION`.
- [src/Payment/DonationService.php](../src/Payment/DonationService.php) &mdash; wraps `Stripe\Checkout\Session::create` for one-time and subscription flows. Uses explicit `payment_method_types: ['card']` (the NDASA account rejects `automatic_payment_methods`). A deterministic idempotency key (`sess_<order_id>`) prevents double-submission from creating two Stripe sessions. Also exposes `createPortalSession()` for the success-page self-serve link.
- [src/Webhook/WebhookController.php](../src/Webhook/WebhookController.php) &mdash; dispatches all eight Stripe events: `checkout.session.completed`, `.async_payment_succeeded`, `.async_payment_failed`, `charge.refunded`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`. Sync and async success paths converge on `recordPaidSession()`; subscription invoices go through `onInvoicePaid()` which dedupes the first invoice against its signup session via `metadata.order_id`.
- [src/Webhook/EventStore.php](../src/Webhook/EventStore.php) &mdash; the idempotency log and donation ledger. Two-phase: `isProcessed()` check → handler run → `markProcessed()` (the livemode flag from the verified event is stored here too). `recordDonation()` is the single canonical insert path used by both the webhook and `bin/stripe-import.php`. `markSubscriptionCancelled()` stamps every row of a cancelled subscription with `subscription_status='cancelled'` so `Metrics::activeRecurringCommitment()` stops counting it, without rewriting historical `paid` statuses.
- [src/Support/Database.php](../src/Support/Database.php) &mdash; lazy-singleton PDO handle on SQLite. Self-migrating (tables, indexes). Every schema extension is idempotent and 3.7.17-safe (pragma probe + `ALTER TABLE ADD COLUMN`). WAL, foreign keys, 5 s busy timeout; prepared statements only.
- [src/Admin/AppConfig.php](../src/Admin/AppConfig.php) &mdash; runtime toggles backed by the `app_config` SQLite table. Canonical source for `stripeMode()` and `resolveStripeCredentials()`.
- [src/Admin/AuditLog.php](../src/Admin/AuditLog.php) &mdash; append-only log of privileged admin actions. Mode toggles log the previous→next transition; secret values are never written.
- [src/Admin/Auth.php](../src/Admin/Auth.php) &mdash; HTTP Basic Auth gate with a hardened `HTTP_AUTHORIZATION` fallback parser. Constant-time credential comparison.
- [src/Admin/Diagnostics.php](../src/Admin/Diagnostics.php) &mdash; read-only "geek view" collector. Gathers App / PHP / Database / Filesystem / Logs / Env / Stripe keys / Stripe API / Webhook heartbeat tiles in a single call for `/admin/diagnostics`. Hits the Stripe API live each page load via per-instance `StripeClient` (so both live and test keys can be probed in one request); every tile catches its own exceptions so a broken Stripe call never blanks the page.
- [src/Admin/Metrics.php](../src/Admin/Metrics.php) &mdash; read-only aggregate queries for every admin page. Constructor takes `(PDO, bool $isLive)`; every donation query binds the livemode flag. `lastWebhookAt(?bool $livemode)` answers live/test heartbeats separately. Per-instance memoisation on the scalar aggregates.
- [src/Admin/HealthCheck.php](../src/Admin/HealthCheck.php) &mdash; grouped system health (Database, Environment, Configuration). Every probe is try/catch-wrapped; nothing throws. Consumed by Diagnostics.
- [src/Admin/Version.php](../src/Admin/Version.php) &mdash; resolves the admin-footer version string: `APP_VERSION` env → short git hash → fallback constant.
- [bin/stripe-import.php](../bin/stripe-import.php) &mdash; CLI back-fill tool. Reads Stripe Sessions, Invoices, Charges directly; writes via `EventStore::recordDonation()` so schema can't drift from the webhook path. Idempotent, mode-scoped, date-windowable, dry-runnable.

## Local development

### First-time setup

```sh
git clone git@github.com:jwogrady/ndasa-donation.git
cd ndasa-donation
composer install
cp .env.example .env
```

Fill in the following in `.env`:

- `STRIPE_TEST_SECRET_KEY` and `STRIPE_TEST_WEBHOOK_SECRET` with Stripe **test-mode** values. The admin mode toggle defaults to `live`; for local development, flip it to `test` from `/admin/diagnostics` (the live keys can stay empty, or you can set them to your test values as a fallback).
- `ADMIN_USER` and `ADMIN_PASS` for the admin panel's HTTP Basic Auth.
- `DB_PATH` to an absolute path, e.g. `/home/you/projects/ndasa-donation/storage/donations.sqlite`.
- `APP_URL=http://127.0.0.1:8000` for the built-in server.
- `APP_ENV=development` (anything other than `production`) to disable HTTPS enforcement.

The app sends no email — donor receipts and internal alerts both come from Stripe, so there is no SMTP or mail configuration to provide.

### Running the app

```sh
php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000/>. The donation form should render.

### Forwarding Stripe webhooks locally

Install the Stripe CLI, then in a separate terminal:

```sh
stripe login
stripe listen --forward-to http://127.0.0.1:8000/webhook.php
```

The `stripe listen` output includes a temporary `whsec_...` secret &mdash; paste it into `STRIPE_TEST_WEBHOOK_SECRET` (or `STRIPE_WEBHOOK_SECRET` if you are using the legacy fallback) in `.env` for the duration of the dev session.

Trigger events with:

```sh
stripe trigger checkout.session.completed
```

## Testing

```sh
composer test
```

PHPUnit 11, PHP 8.2+. Every test runs against an in-memory SQLite DB with the same schema as production (applied via `tests/Support/DatabaseTestCase.php`), so the full suite is hermetic: no network, no Stripe account, no filesystem side-effects. Current suite is ~130 tests running in under 100 ms.

Coverage by module:

- **`tests/Payment/AmountValidatorTest.php`** &mdash; amount-parsing attack surface.
- **`tests/Payment/FeeCalculatorTest.php`** &mdash; gross-up math at boundaries.
- **`tests/Http/ClientIpTest.php`** &mdash; trusted-proxy chain walker.
- **`tests/Http/CsrfTest.php`** &mdash; token mint, validate, rotate.
- **`tests/Http/RateLimiterTest.php`** &mdash; fixed-window limiter with key isolation and rollover.
- **`tests/Support/HtmlTest.php`** &mdash; HTML escape on every dangerous character class plus UTF-8 passthrough.
- **`tests/Webhook/EventStoreTest.php`** &mdash; idempotency, shape coercion, refund, subscription cancel (including `subscription_status` stamping and redelivery idempotence).
- **`tests/Webhook/WebhookControllerTest.php`** &mdash; dispatch across all eight handled event types: one-time, async ACH, refund, recurring, subscription delete, unknown event, handler-error rollback.
- **`tests/Admin/AppConfigTest.php`** &mdash; runtime flags and Stripe credential resolution (live/test/legacy fallback).
- **`tests/Admin/AuditLogTest.php`** &mdash; append-only write, truncation, swallow-on-failure.
- **`tests/Admin/AuthTest.php`** &mdash; Basic-Auth header parser quirks (PHP_AUTH_*, FastCGI, LiteSpeed, malformed base64, colon-in-password).
- **`tests/Admin/MetricsTest.php`** &mdash; all query shapes including livemode filtering, pagination, email/status/date filters, recurring aggregation (cancelled-subscription exclusion across live+test), donor-hash lookup, refund-rate window math.

### Shared harness

- **`tests/Support/DatabaseTestCase.php`** &mdash; base class that stands up a fresh in-memory SQLite DB with the production schema on every test. Extend it for anything that needs the ledger.
- **`tests/Support/Fixtures.php`** &mdash; constructors for real `Stripe\Event` objects from array payloads that mirror the delivered webhook shapes.

### What is still not covered

The HTTP-request surface of `public/index.php` (front-controller routing, form rendering, admin template rendering) is exercised manually; there are no Selenium / Playwright tests. Changes to that layer should include manual verification in a PR description. `DonationService::create()`, `bin/stripe-import.php`, and `Admin\Diagnostics::stripeApiTilesForMode()` also hit the Stripe API directly and are exercised end-to-end in Stripe test mode rather than by unit tests.

Dependencies to audit:

```sh
composer audit
```

## Coding conventions

The codebase is opinionated but small. Follow the existing style rather than introducing new patterns.

- **Strict types everywhere.** Every PHP file declares `declare(strict_types=1);` immediately after the optional PHPDoc header.
- **`final` classes** unless there is a genuine inheritance reason. No abstract base classes currently exist.
- **Constructor property promotion** is the default for service classes.
- **No magic statics.** Primitives like `Csrf` use static methods because they delegate to `$_SESSION`, which is process-global anyway; everything else is injected.
- **PSR-4 autoloading** under the `NDASA\` namespace, rooted at `src/`. Tests autoload under `NDASA\Tests\`, rooted at `tests/`.
- **Output escaping** happens at the call site via `NDASA\Support\Html::h()`. Templates never receive pre-escaped HTML.
- **SQL uses prepared statements only.** `PDO::ATTR_EMULATE_PREPARES` is `false`. String-concatenation SQL is a code-review block.
- **Error handling is fail-closed.** Missing configuration aborts startup. Unexpected exceptions inside handlers are caught, logged, and converted to a generic 500 for the user &mdash; never shown verbatim.
- **Minimal comments.** Add a comment when the _why_ is non-obvious (a hidden constraint, a workaround, a subtle invariant). Do not narrate what the code does.

## When to add new code

This is a small application with a tight scope. Resist expansion:

- New features that do not directly serve the donation flow should be discussed before implementation.
- New dependencies must earn their place; consider the audit surface.
- New tables or columns in SQLite should use `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE ADD COLUMN` idempotent migrations added to `Database::migrate()`.
- New Stripe event types should be added to the `match` in `WebhookController::dispatch()` and to the webhook endpoint's event list in the Stripe dashboard. The two must stay synchronised.
- New environment variables must be added to **all three** of `.env.example`, `deploy/.env.template`, and the "Required" / "Optional" tables in [ADMIN.md](ADMIN.md). `bin/check-env-sync.php` enforces parity between the first two in CI; ADMIN.md is reviewer-checked.
- New schema changes must go through `Database::migrate()` as additive `CREATE TABLE IF NOT EXISTS` / pragma-probed `ALTER TABLE ADD COLUMN` statements. `RENAME COLUMN` and `DROP COLUMN` are unsupported on the SQLite 3.7.17 prod floor — reshape via a table rebuild if you need them.

## Release expectations

The project follows [Semantic Versioning](https://semver.org/) and [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) for [CHANGELOG.md](../CHANGELOG.md).

Before proposing a release:

1. All tests pass (`composer test`) and `composer audit` reports no known vulnerabilities.
2. The Stripe API version pin in `config/app.php` matches the webhook endpoint configuration in the Stripe dashboard.
3. Every env-var change is reflected in `.env.example` and in [ADMIN.md](ADMIN.md).
4. The [CHANGELOG.md](../CHANGELOG.md) has an entry for the release under `## [X.Y.Z] — YYYY-MM-DD`, grouped into **Added** / **Changed** / **Removed** / **Security** as appropriate. Every entry must be traceable to a commit in the range.

## Branching and release workflow

The project uses a simple, linear flow. It is intentionally minimal &mdash; the repository is small and has a single maintainer, so heavier branching models are not warranted.

- **`master`** is the integration branch. It always reflects shippable code. Do not commit directly to `master`; land work there only via a reviewed pull request.
- **Feature / fix branches** &mdash; all new work starts on a short-lived branch named after the concern: `feat/<short-name>`, `fix/<short-name>`, `chore/<short-name>`, `docs/<short-name>`. Open a pull request back to `master` when the work is ready.
- **Release branches** &mdash; each release gets its own `release/vX.Y.Z` branch, cut from `master` at the intended release point. Final release-tightening (CHANGELOG finalisation, version bumps, last-pass regression testing) happens on the release branch. When the release branch is ready, it is merged into `master` and tagged as `vX.Y.Z` from the resulting commit.
- **Hotfixes** &mdash; urgent production fixes branch from the release tag as `hotfix/vX.Y.(Z+1)`, land via PR, are merged back to `master`, and are tagged.

The test commands and release checklist above apply on both feature branches (before opening the PR) and on release branches (before tagging).

Conventions that keep the flow honest:

- One concern per branch, one concern per PR.
- Rebase onto the latest `master` before requesting review; prefer a clean, linear history over merge commits on feature branches.
- Delete the branch after merge. Branch names are cheap; stale ones are noise.
- Tags are authoritative. A release that is not tagged did not happen.

## Submitting changes

### Issues

Open an issue at <https://github.com/jwogrady/ndasa-donation/issues> with:

- What you observed (for a bug: exact error, steps, approximate time; for a request: the donor- or admin-facing outcome you want).
- What you expected.
- Which audience the change serves: donor, administrator, maintainer, or a combination.

Do not include Stripe API keys, webhook signing secrets, donor emails, or any production `.env` values in an issue.

### Pull requests

- **One concern per PR.** Bug fix, feature, or refactor &mdash; pick one.
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/)**: `feat(payment): …`, `fix(webhook): …`, `chore(deps): …`, `docs: …`, `test: …`.
- **Include a testing note.** Describe how you verified the change (test command, manual Stripe test-mode steps, screenshots for UI work).
- **Update documentation that your change affects.** User-facing change: touch [USER.md](USER.md). New env var: touch [ADMIN.md](ADMIN.md). New internal pattern: touch this file.
- **Do not commit `.env`, `storage/`, or `vendor/`.** They are gitignored; re-check before pushing.
- **Do not relax existing security controls** (CSRF, rate limit, CSP, prepared statements, signature verification) without an accompanying issue that explains the rationale.

### Commit discipline on security-sensitive files

Changes to these files require an extra reviewer, because a mistake here is a payment-security bug:

- `config/app.php`
- `public/index.php`
- `public/webhook.php`
- `src/Http/Csrf.php`
- `src/Http/RateLimiter.php`
- `src/Http/ClientIp.php`
- `src/Payment/DonationService.php`
- `src/Webhook/WebhookController.php`
- `src/Webhook/EventStore.php`

## Further reading

- Stripe API reference: <https://stripe.com/docs/api>
- Stripe Checkout guide: <https://stripe.com/docs/payments/checkout>
- Stripe webhook guide: <https://stripe.com/docs/webhooks>
- PHPUnit 11 manual: <https://docs.phpunit.de/en/11.0/>
