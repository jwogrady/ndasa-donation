# Deployment

Server-specific install kit for the NDASA Donation Platform on Nexcess managed WordPress hosting (cPanel/LiteSpeed + nginx, PHP-FPM 8.3). These files are not part of the application proper &mdash; they exist only to deploy it alongside an existing WordPress install.

## Target layout

```
~/                                            (user home)
├── backups/ndasa-donation/                   (install.sh snapshots, chmod 700)
│   ├── .ndasa-donation.bak-YYYYMMDD-HHMMSS/  (hidden-app snapshots)
│   └── donation.bak-YYYYMMDD-HHMMSS/         (public-shim snapshots)
└── public_html/                              (chroot html root; WordPress lives here)
    ├── wp-config.php, wp-admin/, ...         (WordPress, untouched)
    ├── .ndasa-donation/                      (hidden; Require all denied)
    │   ├── src/ config/ templates/ public/ vendor/
    │   ├── storage/donations.sqlite
    │   ├── .env                              (chmod 600)
    │   └── .htaccess                         (deny-all)
    └── donation/                             (the public surface)
        ├── .htaccess                         (rewrites + cache-bypass)
        ├── index.php                         (shim)
        ├── webhook.php                       (shim)
        └── assets/                           (symlink -> ../.ndasa-donation/public/assets)
```

Backups live outside the webroot so copies of `.env`, the SQLite DB, and app source are never served by the webserver, never scanned by WordPress plugins, and never miscounted against a public-asset quota. `install.sh` uses `mv` across directories on the same filesystem, which remains atomic and instant. Override the target with `BACKUP_ROOT=/some/other/path ./install.sh` if your host puts `public_html/` on a different mount. Prune old snapshots with `deploy/prune-backups.sh` (dry-run by default; see `--help`).

Why a hidden `.ndasa-donation/` directory instead of a path above the webroot: Nexcess runs PHP-FPM inside a chroot at `/chroot/home/<user>/<site-id>/html/`. Files in `~/` outside that path are not visible to the PHP process, so the "code above webroot" pattern does not work here. Instead we put the code inside the chroot but outside the URL-reachable donation path, with a `Require all denied` in its `.htaccess`.

## Prerequisites

- Root or user SSH access to the Nexcess host.
- `php` 8.2+ and `composer` on `PATH` (confirmed present on Nexcess managed WP).
- A working WordPress install under `public_html/`.
- Stripe account with rotated keys (the previous `sk_live_…` must have been rolled &mdash; it was exposed in the legacy code).
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
2. On a re-run, rescues the live `.env` and the entire `storage/` tree (SQLite DB, WAL/SHM journals, logs) to a private tempdir. Also stages a `storage.safety-copy/` inside the pending backup dir as a belt-and-braces second copy.
3. Moves any existing `public_html/.ndasa-donation/` and `public_html/donation/` to `~/backups/ndasa-donation/.ndasa-donation.bak-YYYYMMDD-HHMMSS/` and `~/backups/ndasa-donation/donation.bak-YYYYMMDD-HHMMSS/` (overridable via `BACKUP_ROOT`, chmod 700).
4. Copies the app into `public_html/.ndasa-donation/` and runs `composer install --no-dev`.
5. Restores the rescued `.env` and `storage/` contents into the new install. Once the restore is confirmed (non-empty `donations.sqlite` present), removes the staged safety copy from the backup dir so one canonical DB lives on disk.
6. Seeds `.env` from [deploy/.env.template](.env.template) on a fresh install (skipped on upgrade — the rescued `.env` is authoritative).
7. Writes the public shims into `public_html/donation/`.
8. Runs a config-load dry run as a final sanity check.

It pauses for `y/N` confirmation before writing anything into `public_html/`, and every step prints what it is about to do.

## Post-install

1. **Fill in `.env`.** Open `public_html/.ndasa-donation/.env` and replace every `REPLACE_ME`:
    - `STRIPE_LIVE_SECRET_KEY` &mdash; the *new* rolled key (never the old one)
    - `STRIPE_LIVE_WEBHOOK_SECRET` &mdash; see step 3 below

2. **Test config loads:**
    ```sh
    php -d display_errors=1 ~/public_html/.ndasa-donation/config/app.php
    ```
    Expect no output; any error here is a missing env var or a typo.

3. **Register the Stripe webhook.** In Stripe dashboard &rarr; Developers &rarr; Webhooks &rarr; Add endpoint (add one for live and one for test):
    - URL: `https://ndasafoundation.org/donation/webhook.php`
    - API version: `2026-03-25.dahlia` (matches the pin in [config/app.php](../config/app.php))
    - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `charge.refunded`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`
    - Copy the `whsec_…` signing secret into `STRIPE_LIVE_WEBHOOK_SECRET` (from the live endpoint) or `STRIPE_TEST_WEBHOOK_SECRET` (from the test endpoint) in `.env`.

4. **End-to-end test in Stripe test mode.** Flip the admin mode toggle to test, make a \$1 donation using a Stripe test card, and verify:
    - Donation form renders at `https://ndasafoundation.org/donation/`.
    - Payment flow redirects to Stripe Checkout, then back to `/donation/success`.
    - Webhook delivers: check `storage/logs/app.log` for any errors, and `storage/donations.sqlite` for a new row.
    - Stripe sends the donor receipt (configured in Stripe Dashboard &rarr; Settings &rarr; Customer emails).
    - Flip the mode toggle back to live when done.

## Rollback

Restore from the newest snapshot in `~/backups/ndasa-donation/` (or your `BACKUP_ROOT`):

```sh
# Identify the newest snapshot pair
ls -lt ~/backups/ndasa-donation/ | head

# Move the broken install out of the way and restore the backup pair
TAG=YYYYMMDD-HHMMSS  # fill in the newest one from the ls above
mv ~/public_html/.ndasa-donation                     ~/backups/ndasa-donation/.ndasa-donation.bad
mv ~/backups/ndasa-donation/.ndasa-donation.bak-$TAG ~/public_html/.ndasa-donation
mv ~/public_html/donation                            ~/backups/ndasa-donation/donation.bad
mv ~/backups/ndasa-donation/donation.bak-$TAG        ~/public_html/donation
```

If `install.sh` aborted mid-deploy and printed a `storage.safety-copy` path, copy its contents back into `~/public_html/.ndasa-donation/storage/` to restore runtime data:

```sh
cp -a ~/backups/ndasa-donation/.ndasa-donation.bak-$TAG/storage.safety-copy/. \
      ~/public_html/.ndasa-donation/storage/
```

To revert to the pre-NDASA-rebuild legacy app:

```sh
mv ~/public_html/donation        ~/donation.new.bak-$(date +%Y%m%d)
mv ~/public_html/.ndasa-donation ~/ndasa-donation.new.bak-$(date +%Y%m%d)
mv ~/donation.legacy.YYYYMMDD    ~/public_html/donation
```

Prune old snapshots you no longer need with `deploy/prune-backups.sh` (dry-run by default; see `--help`).

## Updating

To deploy a new version of the app, re-run `./deploy/install.sh` from an updated repo checkout. The script automatically rescues `.env` and the entire `storage/` tree (SQLite DB, WAL journals, logs) before renaming the old install, and restores them into the fresh install after `composer install` completes. A staged `storage.safety-copy/` inside the backup dir additionally survives mid-install failure. No manual `.env` or DB file copy is required — the script handles both.

If the restore somehow leaves `storage/` empty (e.g. silent rsync permission issue), the safety copy is preserved in the backup dir and its path is printed to stderr so the operator can recover it by hand.

## Operational notes

- **Caching:** SpeedyCache runs at the site root. Our [public_html/donation/.htaccess](apache/donation.htaccess) sets `Cache-Control: no-store` and `X-SpeedyCache-Bypass: 1` so dynamic donation pages are never cached. If you ever put a CDN in front, ensure the same headers are honored.
- **PHP handler:** The site-root `.htaccess` still references `ea-php74`; this is stale. Nexcess's PHP-FPM pool is 8.3, which is what actually runs. Don't edit the cPanel-managed block.
- **nginx in front:** Nexcess runs nginx as a reverse proxy; `REMOTE_ADDR` at the PHP layer is the real client IP already, so `TRUSTED_PROXIES` in `.env` stays empty unless you add your own CDN.
- **Chroot paths:** the PHP-FPM chroot is `/chroot/home/<user>/<site-id>/html/`. `ABSPATH` inside WordPress resolves to a path inside this chroot. `$HOME` from SSH maps to the parent. The installer assumes you are running it from an SSH shell whose `$HOME/public_html` is the same directory the PHP-FPM pool sees as `/html`.
- **SQLite permissions:** `storage/donations.sqlite` is created by the PHP-FPM user on first request. The directory is `chmod 700`; if you need to inspect the DB from the shell, you can `sqlite3 $HOME/public_html/.ndasa-donation/storage/donations.sqlite`.
