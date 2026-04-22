# Deployment

Server-specific install kit for the NDASA Donation Platform on Nexcess managed WordPress hosting (cPanel/LiteSpeed + nginx, PHP-FPM 8.3). These files are not part of the application proper &mdash; they exist only to deploy it alongside an existing WordPress install.

## Target layout

```
public_html/                                  (chroot html root; WordPress lives here)
├── wp-config.php, wp-admin/, ...             (WordPress, untouched)
├── wp-content/mu-plugins/
│   └── ndasa-shared-env.php                  (bridges .env into WP Mail SMTP)
├── .ndasa-donation/                          (hidden; Require all denied)
│   ├── src/ config/ templates/ public/ vendor/
│   ├── storage/donations.sqlite
│   ├── .env                                  (chmod 600)
│   └── .htaccess                             (deny-all)
└── donation/                                 (the public surface)
    ├── .htaccess                             (rewrites + cache-bypass)
    ├── index.php                             (shim)
    ├── webhook.php                           (shim)
    └── assets/                               (symlink -> ../.ndasa-donation/public/assets)
```

Why a hidden `.ndasa-donation/` directory instead of a path above the webroot: Nexcess runs PHP-FPM inside a chroot at `/chroot/home/<user>/<site-id>/html/`. Files in `~/` outside that path are not visible to the PHP process, so the "code above webroot" pattern does not work here. Instead we put the code inside the chroot but outside the URL-reachable donation path, with a `Require all denied` in its `.htaccess`.

## Prerequisites

- Root or user SSH access to the Nexcess host.
- `php` 8.2+ and `composer` on `PATH` (confirmed present on Nexcess managed WP).
- A working WordPress install under `public_html/`.
- Stripe account with rotated keys (the previous `sk_live_…` must have been rolled &mdash; it was exposed in the legacy code).
- SMTP credentials for `admin@ndasafoundation.org` at `secure.emailsrvr.com`.
- The legacy `public_html/donation/` has already been moved aside (see pre-install).

## Pre-install (do once, manually)

```sh
# 1. Kill any publicly-served info leak from the legacy app.
rm -f ~/public_html/donation/phpinfo.txt

# 2. Grep for hardcoded Stripe keys in the legacy tree so you know what to rotate.
grep -rEn 'sk_live_|pk_live_|whsec_' ~/public_html/donation/ 2>/dev/null

# 3. Take the legacy donation app offline.
mv ~/public_html/donation "$HOME/donation.legacy.$(date +%Y%m%d)"

# 4. Confirm /donation/ is now a 404 from the public internet.
curl -sI https://ndasafoundation.org/donation/ | head -1
# Expect: HTTP/1.1 404 Not Found  (may take a minute for SpeedyCache to expire)
```

If step 2 shows any `sk_live_…` or `whsec_…`, rotate those credentials in the Stripe dashboard before proceeding.

## Install

```sh
# Clone the repo somewhere outside public_html/.
cd ~
git clone git@github.com:jwogrady/ndasa-donation.git
cd ndasa-donation

# Run the installer. It is interactive and idempotent.
./deploy/install.sh
```

The script:

1. Validates PHP version and extensions.
2. Backs up any existing `public_html/.ndasa-donation/` or `public_html/donation/` with a timestamped `.bak-YYYYMMDD-HHMMSS` suffix.
3. Copies the app into `public_html/.ndasa-donation/` and runs `composer install --no-dev`.
4. Seeds `.env` from [deploy/.env.template](.env.template) with the correct absolute `DB_PATH` for the detected chroot.
5. Writes the public shims into `public_html/donation/`.
6. Installs the mu-plugin at `public_html/wp-content/mu-plugins/ndasa-shared-env.php`.
7. Runs a config-load dry run as a final sanity check.

It pauses for `y/N` confirmation before writing anything into `public_html/`, and every step prints what it is about to do.

## Post-install

1. **Fill in `.env`.** Open `public_html/.ndasa-donation/.env` and replace every `REPLACE_ME`:
    - `STRIPE_SECRET_KEY` &mdash; the *new* rolled key (never the old one)
    - `STRIPE_WEBHOOK_SECRET` &mdash; see step 3 below
    - `SMTP_PASSWORD` &mdash; the Rackspace Email password for `admin@ndasafoundation.org`

2. **Test config loads:**
    ```sh
    php -d display_errors=1 ~/public_html/.ndasa-donation/config/app.php
    ```
    Expect no output; any error here is a missing env var or a typo.

3. **Register the Stripe webhook.** In Stripe dashboard &rarr; Developers &rarr; Webhooks &rarr; Add endpoint:
    - URL: `https://ndasafoundation.org/donation/webhook.php`
    - API version: `2026-03-25.dahlia` (matches the pin in [config/app.php](../config/app.php))
    - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `charge.refunded`, `payment_intent.payment_failed`
    - Copy the `whsec_…` signing secret back into `STRIPE_WEBHOOK_SECRET` in `.env`.

4. **Confirm WP Mail SMTP reads from the mu-plugin.** In WP admin &rarr; WP Mail SMTP &rarr; Settings, the plugin should show a notice that SMTP values are "defined in code." Send a test email. If it works, clear the password field in the UI (the `WPMS_SMTP_PASS` constant from the mu-plugin takes over).

5. **End-to-end test in Stripe test mode.** Temporarily swap `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` to test-mode values, make a \$1 donation, and verify:
    - Donation form renders at `https://ndasafoundation.org/donation/`.
    - Payment flow redirects to Stripe Checkout, then back to `/donation/success`.
    - Webhook delivers: check `storage/logs/app.log` for any errors, and `storage/donations.sqlite` for a new row.
    - Staff notification email arrives at `MAIL_BCC_INTERNAL`.
    - Restore live keys before you finish.

## Rollback

```sh
# Take the new install down.
mv ~/public_html/donation       ~/donation.new.bak-$(date +%Y%m%d)
mv ~/public_html/.ndasa-donation ~/ndasa-donation.new.bak-$(date +%Y%m%d)

# Restore the legacy app from its pre-install backup (if you want).
mv ~/donation.legacy.YYYYMMDD ~/public_html/donation

# Remove the mu-plugin so WP Mail SMTP stops reading from the (now missing) .env.
rm ~/public_html/wp-content/mu-plugins/ndasa-shared-env.php

# Manually re-enter the SMTP password in WP Mail SMTP settings since the
# constants are gone.
```

The backups created by `install.sh` on re-runs use the `bak-YYYYMMDD-HHMMSS` suffix; keep the most recent one for at least a release cycle.

## Updating

To deploy a new version of the app, re-run `./deploy/install.sh` from an updated repo checkout. It will back up the current install and replace it. Your `.env`, `storage/donations.sqlite`, and `storage/logs/` are preserved (they live only in the backup copy; the new install is seeded fresh except for those files, which the installer carries forward &mdash; see script).

Note: the current script does *not* carry forward `.env` automatically on reinstall &mdash; the back-up-and-replace is full. If you re-run, copy `.env` from the `.bak-*` directory into the new `.ndasa-donation/`. A future iteration of the installer will handle this automatically.

## Operational notes

- **Caching:** SpeedyCache runs at the site root. Our [public_html/donation/.htaccess](apache/donation.htaccess) sets `Cache-Control: no-store` and `X-SpeedyCache-Bypass: 1` so dynamic donation pages are never cached. If you ever put a CDN in front, ensure the same headers are honored.
- **PHP handler:** The site-root `.htaccess` still references `ea-php74`; this is stale. Nexcess's PHP-FPM pool is 8.3, which is what actually runs. Don't edit the cPanel-managed block.
- **nginx in front:** Nexcess runs nginx as a reverse proxy; `REMOTE_ADDR` at the PHP layer is the real client IP already, so `TRUSTED_PROXIES` in `.env` stays empty unless you add your own CDN.
- **Chroot paths:** the PHP-FPM chroot is `/chroot/home/<user>/<site-id>/html/`. `ABSPATH` inside WordPress resolves to a path inside this chroot. `$HOME` from SSH maps to the parent. The installer assumes you are running it from an SSH shell whose `$HOME/public_html` is the same directory the PHP-FPM pool sees as `/html`.
- **SQLite permissions:** `storage/donations.sqlite` is created by the PHP-FPM user on first request. The directory is `chmod 700`; if you need to inspect the DB from the shell, you can `sqlite3 $HOME/public_html/.ndasa-donation/storage/donations.sqlite`.
