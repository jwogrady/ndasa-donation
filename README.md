# NDASA Donation

Secure Stripe Checkout donation flow. PCI-DSS **SAQ-A** &mdash; no card data ever touches this server.

## Requirements

- PHP 8.2+ with `pdo_sqlite`, `openssl`, `mbstring`, `curl`
- Composer 2.x
- A Stripe account (live + test modes)
- SMTP credentials or an API mail provider (SendGrid / SES / etc.)

## Install

```sh
composer install --no-dev --optimize-autoloader
cp .env.example .env
# edit .env with real secrets — chmod 600, owned by the web user
```

**Commit `composer.lock`.** Reproducible installs and `composer audit` both depend on it.

## Web server

Point DocumentRoot at `public/`. The app will not work correctly if it is pointed at the repo root &mdash; `src/`, `config/`, `.env`, and the SQLite DB must not be web-reachable.

### nginx

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

    # Defence in depth — these should already be outside the root.
    location ~ /\.(env|git) { deny all; return 404; }
}
```

### Apache

An `.htaccess` in `public/` denies dotfiles and sensitive extensions.

## Behind a CDN / proxy

Set `TRUSTED_PROXIES` in `.env` to the CIDRs of your edge (e.g. Cloudflare, ALB). Leave empty if direct-connected &mdash; never use a wildcard.

## Webhook

In Stripe dashboard &rarr; Developers &rarr; Webhooks, add an endpoint:

- URL: `https://<host>/webhook.php`
- Events: `checkout.session.completed`, `charge.refunded`, `payment_intent.payment_failed`

Copy the resulting `whsec_…` into `STRIPE_WEBHOOK_SECRET`.

## Routes

| Method | Path          | Purpose                           |
|--------|---------------|-----------------------------------|
| GET    | `/`           | Donation form                     |
| POST   | `/checkout`   | Validate, create Checkout Session |
| GET    | `/success`    | Post-redirect thanks page         |
| POST   | `/webhook.php`| Stripe event receiver             |

## Local development

```sh
php -S 127.0.0.1:8000 -t public
stripe listen --forward-to http://127.0.0.1:8000/webhook.php
```

Use Stripe **test mode** keys in `.env`. Enable Stripe Radar in the dashboard for card-testing protection.

## Tests

```sh
composer test
composer audit
```
