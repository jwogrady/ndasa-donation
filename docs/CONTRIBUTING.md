# Contributing

This document is for developers working on the NDASA donation platform. It covers the repository layout, local development, testing, coding conventions, and release discipline.

If you are a donor, see [USER.md](USER.md). If you are responsible for running the system, see [ADMIN.md](ADMIN.md).

## Repository layout

```
.
├── public/                 (document root; nothing else should be web-reachable)
│   ├── index.php           (front controller for GET /, POST /checkout, GET /success, admin routes)
│   ├── webhook.php         (Stripe webhook entry point)
│   ├── .htaccess           (Apache hardening + rewrite rules)
│   └── assets/css/         (stylesheet)
├── config/
│   └── app.php             (env load, security headers, session, Stripe SDK init)
├── src/
│   ├── Admin/              (Auth, EnvFile, HealthCheck, Metrics, Version)
│   ├── Http/               (Csrf, RateLimiter, ClientIp)
│   ├── Mail/               (ReceiptMailer)
│   ├── Payment/            (AmountValidator, FeeCalculator, DonationService)
│   ├── Support/            (Database, Html)
│   └── Webhook/            (WebhookController, EventStore)
├── templates/
│   ├── admin/              (layout, dashboard, config)
│   └── …                   (form, success, error, layout for the donation flow)
├── tests/                  (PHPUnit)
├── deploy/                 (Nexcess-specific install kit; not part of the app proper)
├── storage/                (runtime: SQLite DB + logs; gitignored)
├── composer.json
├── phpunit.xml
└── .env.example
```

The `public/` tree is the only directory exposed by URL in any sensible deployment. Every other directory must live outside the web root or be protected by an `.htaccess` / `Require all denied` (see `deploy/` for the Nexcess pattern).

## Key entry points and services

These are the files most often touched by changes:

- [public/index.php](../public/index.php) &mdash; tiny front controller. Routes `GET /`, `POST /checkout`, `GET /success`, and the three admin routes. Subpath-aware: strips the path prefix of `APP_URL` before matching. All `/admin*` paths pass through `AdminAuth::require()` immediately before dispatch.
- [public/webhook.php](../public/webhook.php) &mdash; verifies the Stripe signature, constructs the event, and hands it to the webhook controller. Returns non-2xx only on handler failure (so Stripe retries).
- [config/app.php](../config/app.php) &mdash; loads `.env`, validates required env vars, configures the Stripe SDK (with a pinned API version), emits security headers for browser responses, starts a hardened session. Webhook handler opts out of session handling via `NDASA_SKIP_SESSION`.
- [src/Payment/DonationService.php](../src/Payment/DonationService.php) &mdash; wraps `Stripe\Checkout\Session::create`. Uses `automatic_payment_methods`; the deterministic idempotency key (`sess_<order_id>`) prevents double-submission from creating two Stripe sessions.
- [src/Webhook/WebhookController.php](../src/Webhook/WebhookController.php) &mdash; dispatches Stripe events (`checkout.session.completed`, `.async_payment_succeeded`, `.async_payment_failed`, `charge.refunded`, `payment_intent.payment_failed`). Returns a boolean; the entry point decides HTTP status. Sync and async success paths converge on `recordPaidSession()`.
- [src/Webhook/EventStore.php](../src/Webhook/EventStore.php) &mdash; the idempotency log and donation ledger. `markProcessed()` uses `INSERT OR IGNORE` + `rowCount()` so duplicate deliveries are detected atomically.
- [src/Mail/ReceiptMailer.php](../src/Mail/ReceiptMailer.php) &mdash; staff notifications. Builds a Symfony Mailer `Dsn` from discrete `SMTP_*` env components (or accepts a pre-formed `SMTP_DSN`). Donor receipts are sent by Stripe, not by this class.
- [src/Support/Database.php](../src/Support/Database.php) &mdash; lazy-singleton PDO handle on SQLite. Self-migrating (tables, indexes). WAL, foreign keys, 5 s busy timeout; prepared statements only.
- [src/Admin/Auth.php](../src/Admin/Auth.php) &mdash; HTTP Basic Auth gate with a hardened `HTTP_AUTHORIZATION` fallback parser. Constant-time credential comparison.
- [src/Admin/EnvFile.php](../src/Admin/EnvFile.php) &mdash; safe `.env` reader/updater. Preserves comments and unknown keys; writes to `.env.tmp` + `rename()` for atomicity; rejects CR/LF injection.
- [src/Admin/Metrics.php](../src/Admin/Metrics.php) &mdash; read-only aggregate queries for the dashboard. All counts/sums filtered to `status = 'paid'`; per-instance memoisation.
- [src/Admin/HealthCheck.php](../src/Admin/HealthCheck.php) &mdash; grouped system health (Database, Environment, Configuration). Every probe is try/catch-wrapped; nothing throws.
- [src/Admin/Version.php](../src/Admin/Version.php) &mdash; resolves the admin-footer version string: `APP_VERSION` env → short git hash → fallback constant.

## Local development

### First-time setup

```sh
git clone git@github.com:jwogrady/ndasa-donation.git
cd ndasa-donation
composer install
cp .env.example .env
```

Fill in the following in `.env`:

- `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` with Stripe **test-mode** values.
- `SMTP_HOST` / `SMTP_PORT` / `SMTP_USERNAME` / `SMTP_PASSWORD`, or `SMTP_DSN`. For local-only work, `SMTP_DSN=null://null` will swallow mail without sending it.
- `DB_PATH` to an absolute path outside the repo tree, e.g. `/tmp/ndasa.sqlite`.
- `APP_URL=http://127.0.0.1:8000` for the built-in server.
- `APP_ENV=development` (anything other than `production`) to disable HTTPS enforcement.

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

The `stripe listen` output includes a temporary `whsec_...` secret &mdash; paste it into `STRIPE_WEBHOOK_SECRET` in `.env` for the duration of the dev session.

Trigger events with:

```sh
stripe trigger checkout.session.completed
```

## Testing

```sh
composer test
```

Current coverage is deliberately narrow: the two primitives most exposed to untrusted input are covered.

- **`tests/Payment/AmountValidatorTest.php`** &mdash; eight cases covering the amount-parsing attack surface: simple integers, decimals, negatives, below-min, above-max, scientific notation, alphabetic input, excess decimals.
- **`tests/Http/ClientIpTest.php`** &mdash; six cases covering the trusted-proxy chain-walker: no trusted proxies, untrusted direct peer, trusted proxy with XFF, chained trusted hops, exact-IP match, malformed XFF.

Tests use PHPUnit 11 and run against PHP 8.2+.

### What is not covered

There are no end-to-end tests for the webhook pipeline, the Checkout session creation, the mail path, or the templates. Changes to those areas should include manual verification steps in the PR description (see Release expectations below).

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
- New environment variables must be added to both `.env.example` and the "Required" / "Optional" tables in [ADMIN.md](ADMIN.md).

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
- Symfony Mailer DSN reference: <https://symfony.com/doc/current/mailer.html#transport-setup>
- PHPUnit 11 manual: <https://docs.phpunit.de/en/11.0/>
